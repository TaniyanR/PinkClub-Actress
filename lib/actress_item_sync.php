<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * 保存済み女優を順番に選び、その女優の出演作品だけを商品APIから取得する。
 * 商品APIは女優個別ページの補助データとして扱う。
 *
 * @return array{actress_id:int,actress_dmm_id:string,actress_name:string,synced_count:int,new_count:int,total_items:int,message:string}
 */
function pca_sync_items_for_next_saved_actress(int $batch = 10): array
{
    $batch = max(1, min(100, $batch));
    $pdo = db();

    $rows = $pdo->query(
        "SELECT id, dmm_id, name FROM actresses
         WHERE TRIM(name) <> '' AND dmm_id REGEXP '^[0-9]+$'
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
        $targets = [[
            'site' => (string)($settings['site'] ?? 'FANZA'),
            'service' => (string)($settings['service'] ?? 'digital'),
            'floor' => (string)($settings['floor'] ?? 'videoa'),
            'label' => '作品',
        ]];
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
        $offsetKey = 'actress_item_sync_offset.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $actressDmmId . '.' . $targetKey);
        $offset = max(1, (int)site_setting_get($offsetKey, '1'));

        $result = $service->syncItemsBatch(
            (string)($target['site'] ?? 'FANZA'),
            (string)($target['service'] ?? 'digital'),
            (string)($target['floor'] ?? 'videoa'),
            $batch,
            $offset,
            [
                'sort' => 'rank',
                'article' => 'actress',
                'article_id' => $actressDmmId,
            ]
        );

        $count = (int)($result['synced_count'] ?? 0);
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

/**
 * 1回の操作で複数の保存済み女優を順番に処理する。
 * 既存女優が多いサイトでも、何度も1人ずつボタンを押さなくて済むようにする。
 *
 * @return array{processed_actresses:int,synced_count:int,new_count:int,total_items:int,message:string}
 */
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
