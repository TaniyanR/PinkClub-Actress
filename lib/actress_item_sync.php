<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/dmm_normalizer.php';

/**
 * 画像未取得の保存済み女優を少数ずつ個別APIで補完する。
 * 大量リクエストを避けるため1回最大10人まで。
 */
function pca_enrich_missing_actress_images(int $limit = 5): array
{
    $limit = max(1, min(10, $limit));
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT id, dmm_id, name FROM actresses
         WHERE TRIM(COALESCE(name, '')) <> ''
           AND dmm_id REGEXP '^[0-9]+$'
           AND COALESCE(image_large, '') = ''
           AND COALESCE(image_small, '') = ''
           AND COALESCE(image_url, '') = ''
         ORDER BY updated_at DESC, id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($rows === []) {
        return ['processed' => 0, 'updated' => 0, 'message' => '画像未取得の女優はいません。'];
    }

    $client = dmm_client_for_type('actresses');
    $processed = 0;
    $updated = 0;

    foreach ($rows as $row) {
        $dmmId = trim((string)($row['dmm_id'] ?? ''));
        if ($dmmId === '') {
            continue;
        }
        $processed++;
        try {
            $response = $client->searchActresses(['actress_id' => $dmmId, 'hits' => 10, 'offset' => 1]);
            $apiRows = DmmNormalizer::toList($response['result']['actress'] ?? []);
            $best = null;
            foreach ($apiRows as $apiRow) {
                if (!is_array($apiRow)) {
                    continue;
                }
                if (trim((string)($apiRow['id'] ?? '')) === $dmmId) {
                    $best = $apiRow;
                    break;
                }
            }
            if (!is_array($best) && isset($apiRows[0]) && is_array($apiRows[0])) {
                $best = $apiRows[0];
            }
            if (!is_array($best)) {
                continue;
            }

            $large = trim((string)($best['imageURL']['large'] ?? $best['image_large'] ?? ''));
            $small = trim((string)($best['imageURL']['small'] ?? $best['image_small'] ?? ''));
            $image = $large !== '' ? $large : $small;
            if ($image === '') {
                continue;
            }

            $update = $pdo->prepare(
                'UPDATE actresses SET image_url = :image, image_small = :small, image_large = :large, updated_at = NOW() WHERE id = :id'
            );
            $update->execute([
                ':image' => $image,
                ':small' => $small,
                ':large' => $large,
                ':id' => (int)$row['id'],
            ]);
            $updated += $update->rowCount() > 0 ? 1 : 0;
        } catch (Throwable $e) {
            error_log('actress image enrichment failed for ' . $dmmId . ': ' . $e->getMessage());
        }
    }

    return [
        'processed' => $processed,
        'updated' => $updated,
        'message' => '女優画像を' . $processed . '人確認し、' . $updated . '人分を補完しました。',
    ];
}

/**
 * 保存済み女優を順番に選び、その女優の出演作品だけを商品APIから取得する。
 * API検索条件として女優IDを指定しているため、取得直後にその女優との関連も明示保存する。
 *
 * @return array{actress_id:int,actress_dmm_id:string,actress_name:string,synced_count:int,new_count:int,total_items:int,message:string}
 */
function pca_sync_items_for_next_saved_actress(int $batch = 10): array
{
    $batch = max(1, min(100, $batch));
    $pdo = db();

    $rows = $pdo->query(
        "SELECT id, dmm_id, name FROM actresses
         WHERE TRIM(COALESCE(name, '')) <> '' AND dmm_id REGEXP '^[0-9]+$'
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($rows === []) {
        throw new RuntimeException('先に女優情報を取得してください。保存済み女優がありません。');
    }

    $cursor = max(0, (int)site_setting_get('actress_item_sync_cursor', '0'));
    if ($cursor >= count($rows)) {
        $cursor = 0;
    }
    $actress = $rows[$cursor];
    $nextCursor = ($cursor + 1) % count($rows);

    $actressId = (int)($actress['id'] ?? 0);
    $actressDmmId = trim((string)($actress['dmm_id'] ?? ''));
    $actressName = trim((string)($actress['name'] ?? ''));
    if ($actressId <= 0 || $actressDmmId === '' || $actressName === '') {
        throw new RuntimeException('保存済み女優データが不正です。');
    }

    $settings = settings_get();
    $targets = $settings['catalog_targets'] ?? [];
    if (!is_array($targets) || $targets === []) {
        $targets = [
            ['site' => 'FANZA', 'service' => 'digital', 'floor' => 'videoa', 'label' => '女優作品'],
            ['site' => 'FANZA', 'service' => 'digital', 'floor' => 'videoc', 'label' => 'しろうと作品'],
        ];
    }

    $beforeCount = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $synced = 0;
    $messages = [];
    $service = dmm_sync_service('items');

    foreach ($targets as $target) {
        if (!is_array($target)) {
            continue;
        }
        $targetKey = settings_catalog_target_key($target);
        $floor = (string)($target['floor'] ?? 'videoa');
        $offsetKey = 'actress_item_sync_offset.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $actressDmmId . '.' . $targetKey);
        $offset = max(1, (int)site_setting_get($offsetKey, '1'));
        $syncStartedAt = date('Y-m-d H:i:s');

        $result = $service->syncItemsBatch(
            (string)($target['site'] ?? 'FANZA'),
            (string)($target['service'] ?? 'digital'),
            $floor,
            $batch,
            $offset,
            [
                'sort' => 'rank',
                'article' => 'actress',
                'article_id' => $actressDmmId,
            ]
        );

        // ItemListを女優IDで絞って取得しているので、今回保存・更新された商品を
        // 対象女優へ確実に紐付ける。APIレスポンス内のactress配列欠落にも耐える。
        try {
            $link = $pdo->prepare(
                "INSERT IGNORE INTO item_actresses (item_id, dmm_id, actress_name)
                 SELECT id, :dmm_id, :name FROM items
                 WHERE floor_code = :floor
                   AND updated_at >= :started_at"
            );
            $link->execute([
                ':dmm_id' => $actressDmmId,
                ':name' => $actressName,
                ':floor' => $floor,
                ':started_at' => $syncStartedAt,
            ]);
        } catch (Throwable $e) {
            error_log('explicit actress item relation failed: ' . $e->getMessage());
        }

        $count = (int)($result['api_count'] ?? $result['synced_count'] ?? 0);
        $synced += $count;
        $nextOffset = max(1, (int)($result['next_offset'] ?? 1));
        site_setting_set_many([$offsetKey => (string)$nextOffset]);

        $label = trim((string)($target['label'] ?? $targetKey)) ?: $targetKey;
        $messages[] = $label . ' ' . $count . '件';
    }

    site_setting_set_many(['actress_item_sync_cursor' => (string)$nextCursor]);

    $afterCount = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $newCount = max(0, $afterCount - $beforeCount);

    return [
        'actress_id' => $actressId,
        'actress_dmm_id' => $actressDmmId,
        'actress_name' => $actressName,
        'synced_count' => $synced,
        'new_count' => $newCount,
        'total_items' => $afterCount,
        'message' => $actressName . ' の作品を取得しました（' . implode(' / ', $messages) . '）。',
    ];
}

/** @return array{processed_actresses:int,synced_count:int,new_count:int,total_items:int,message:string} */
function pca_sync_items_for_saved_actresses(int $actressCount = 5, int $batchPerActress = 10): array
{
    $actressCount = max(1, min(10, $actressCount));
    $batchPerActress = max(1, min(50, $batchPerActress));

    $processed = 0;
    $synced = 0;
    $new = 0;
    $totalItems = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $names = [];

    for ($i = 0; $i < $actressCount; $i++) {
        $result = pca_sync_items_for_next_saved_actress($batchPerActress);
        $processed++;
        $synced += (int)($result['synced_count'] ?? 0);
        $new += (int)($result['new_count'] ?? 0);
        $totalItems = (int)($result['total_items'] ?? $totalItems);
        $name = trim((string)($result['actress_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return [
        'processed_actresses' => $processed,
        'synced_count' => $synced,
        'new_count' => $new,
        'total_items' => $totalItems,
        'message' => $processed . '人分の作品を取得しました' . ($names !== [] ? '（' . implode('、', $names) . '）' : '') . '。',
    ];
}
