<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/dmm_normalizer.php';

function pca_enrich_missing_actress_images(int $limit = 100): array
{
    $limit = max(1, min(100, $limit));
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id,dmm_id,name FROM actresses WHERE TRIM(COALESCE(name,''))<>'' AND dmm_id REGEXP '^[0-9]+$' AND COALESCE(image_large,'')='' AND COALESCE(image_small,'')='' AND COALESCE(image_url,'')='' ORDER BY updated_at DESC,id DESC LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $client = dmm_client_for_type('actresses');
    $processed = 0;
    $updated = 0;
    foreach ($rows as $row) {
        $dmmId = trim((string)($row['dmm_id'] ?? ''));
        if ($dmmId === '') continue;
        $processed++;
        try {
            $response = $client->searchActresses(['actress_id'=>$dmmId,'hits'=>10,'offset'=>1]);
            $apiRows = DmmNormalizer::toList($response['result']['actress'] ?? []);
            $best = null;
            foreach ($apiRows as $apiRow) {
                if (is_array($apiRow) && trim((string)($apiRow['id'] ?? '')) === $dmmId) {
                    $best = $apiRow;
                    break;
                }
            }
            if (!is_array($best) && isset($apiRows[0]) && is_array($apiRows[0])) $best = $apiRows[0];
            if (!is_array($best)) continue;
            $large = trim((string)($best['imageURL']['large'] ?? $best['image_large'] ?? ''));
            $small = trim((string)($best['imageURL']['small'] ?? $best['image_small'] ?? ''));
            $image = $large !== '' ? $large : $small;
            if ($image === '') continue;
            $update = $pdo->prepare('UPDATE actresses SET image_url=:image,image_small=:small,image_large=:large,updated_at=NOW() WHERE id=:id');
            $update->execute([':image'=>$image,':small'=>$small,':large'=>$large,':id'=>(int)$row['id']]);
            if ($update->rowCount() > 0) $updated++;
        } catch (Throwable $e) {
            error_log('actress image enrichment failed for ' . $dmmId . ': ' . $e->getMessage());
        }
    }
    return ['processed'=>$processed,'updated'=>$updated,'message'=>'女優画像を'.$processed.'人確認し、'.$updated.'人分を補完しました。'];
}

/**
 * 既存items.raw_jsonを正としてitem_actressesを100件ずつ修復する。
 * 過去の誤った一括紐付けを消し、APIレスポンスに実在する出演者だけを再登録する。
 */
function pca_repair_item_actress_relations_batch(int $limit = 100): array
{
    $limit = max(1, min(100, $limit));
    $pdo = db();
    $cursor = max(0, (int)site_setting_get('pca_relation_repair_cursor', '0'));
    $stmt = $pdo->prepare('SELECT id,raw_json FROM items WHERE id > :cursor ORDER BY id ASC LIMIT :limit');
    $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $cursor = 0;
        $stmt = $pdo->prepare('SELECT id,raw_json FROM items ORDER BY id ASC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $processed = 0;
    $relations = 0;
    $lastId = $cursor;
    foreach ($rows as $itemRow) {
        $itemId = (int)($itemRow['id'] ?? 0);
        $raw = json_decode((string)($itemRow['raw_json'] ?? ''), true);
        if ($itemId <= 0 || !is_array($raw)) continue;
        $normalized = DmmNormalizer::normalizeItemsResponse(['result'=>['items'=>[$raw]]]);
        $item = $normalized[0] ?? null;
        if (!is_array($item)) continue;
        $performers = is_array($item['actresses'] ?? null) ? $item['actresses'] : [];

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM item_actresses WHERE item_id=?')->execute([$itemId]);
            foreach ($performers as $performer) {
                if (!is_array($performer)) continue;
                $name = trim((string)($performer['name'] ?? ''));
                if ($name === '') continue;
                $dmmId = trim((string)($performer['id'] ?? ''));
                if ($dmmId === '') $dmmId = 'name:' . sha1(mb_strtolower($name, 'UTF-8'));
                $pdo->prepare('INSERT IGNORE INTO item_actresses(item_id,dmm_id,actress_name) VALUES(?,?,?)')->execute([$itemId,$dmmId,$name]);
                $pdo->prepare('INSERT INTO actresses(dmm_id,name,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()')->execute([$dmmId,$name]);
                $relations++;
            }
            $pdo->commit();
            $processed++;
            $lastId = max($lastId, $itemId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('item actress relation repair failed for item '.$itemId.': '.$e->getMessage());
        }
    }
    site_setting_set_many(['pca_relation_repair_cursor'=>(string)$lastId]);
    return ['processed'=>$processed,'relations'=>$relations,'cursor'=>$lastId];
}

/**
 * 通常作品は女優を1人ずつ検索せず、videoaを100作品単位で取得する。
 * 商品APIが返すiteminfo.actressを正としてitem_actressesを構築するため、
 * 保存済み女優と一致する作品は個別ページへ自動的に表示できる。
 */
function pca_sync_normal_floor_batch(int $batch = 100): array
{
    $batch = max(1, min(100, $batch));
    $pdo = db();
    $offset = max(1, (int)site_setting_get('pca_normal_floor_offset', '1'));
    $beforeItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();

    $result = dmm_sync_service('items')->syncItemsBatch(
        'FANZA',
        'digital',
        'videoa',
        $batch,
        $offset,
        ['sort'=>'date']
    );

    $apiCount = (int)($result['api_count'] ?? $result['synced_count'] ?? 0);
    $nextOffset = max(1, (int)($result['next_offset'] ?? 1));
    if ($apiCount < $batch) $nextOffset = 1;
    site_setting_set_many(['pca_normal_floor_offset'=>(string)$nextOffset]);

    $afterItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $linkedActresses = 0;
    try {
        $linkedActresses = (int)$pdo->query(
            "SELECT COUNT(DISTINCT ia.dmm_id)
             FROM item_actresses ia
             INNER JOIN items i ON i.id=ia.item_id
             WHERE i.floor_code='videoa' AND ia.dmm_id REGEXP '^[0-9]+$'"
        )->fetchColumn();
    } catch (Throwable) {
    }

    return [
        'api_count'=>$apiCount,
        'new_count'=>max(0,$afterItems-$beforeItems),
        'linked_actresses'=>$linkedActresses,
        'total_items'=>$afterItems,
        'next_offset'=>$nextOffset,
    ];
}

/**
 * 後方互換用。旧「女優100人を個別検索」は使わず、通常フロア100作品取得へ集約する。
 */
function pca_sync_items_for_saved_actresses(int $actressCount = 100, int $batchPerActress = 10): array
{
    $result = pca_sync_normal_floor_batch(100);
    return [
        'processed_actresses'=>0,
        'synced_count'=>(int)($result['api_count'] ?? 0),
        'new_count'=>(int)($result['new_count'] ?? 0),
        'total_items'=>(int)($result['total_items'] ?? 0),
        'message'=>'通常作品を100件単位で取得しました。',
    ];
}

function pca_sync_amateur_floor_batch(int $batch = 100): array
{
    $batch = max(1, min(100, $batch));
    $pdo = db();
    $offset = max(1, (int)site_setting_get('pca_amateur_floor_offset', '1'));
    $beforeItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $result = dmm_sync_service('items')->syncItemsBatch('FANZA','digital','videoc',$batch,$offset,['sort'=>'rank']);
    $apiCount = (int)($result['api_count'] ?? $result['synced_count'] ?? 0);
    $nextOffset = max(1, (int)($result['next_offset'] ?? 1));
    if ($apiCount < $batch) $nextOffset = 1;
    site_setting_set_many(['pca_amateur_floor_offset'=>(string)$nextOffset]);

    try {
        $pdo->exec(
            "INSERT INTO actresses (dmm_id,name,created_at,updated_at)
             SELECT DISTINCT
               CASE WHEN TRIM(COALESCE(ia.dmm_id,''))<>'' THEN TRIM(ia.dmm_id)
                    ELSE CONCAT('name:',SHA1(LOWER(TRIM(ia.actress_name)))) END,
               TRIM(ia.actress_name),NOW(),NOW()
             FROM item_actresses ia INNER JOIN items i ON i.id=ia.item_id
             WHERE TRIM(COALESCE(ia.actress_name,''))<>''
               AND (i.floor_code='videoc' OR i.floor_name LIKE '%素人%' OR i.floor_name LIKE '%しろうと%' OR i.floor_name LIKE '%シロウト%')
             ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()"
        );
    } catch (Throwable $e) {
        error_log('amateur performer backfill failed: ' . $e->getMessage());
    }

    $afterItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $amateurCount = 0;
    try {
        $amateurCount = (int)$pdo->query("SELECT COUNT(DISTINCT ia.actress_name) FROM item_actresses ia INNER JOIN items i ON i.id=ia.item_id WHERE TRIM(COALESCE(ia.actress_name,''))<>'' AND (i.floor_code='videoc' OR i.floor_name LIKE '%素人%' OR i.floor_name LIKE '%しろうと%' OR i.floor_name LIKE '%シロウト%')")->fetchColumn();
    } catch (Throwable) {
    }
    return ['api_count'=>$apiCount,'new_count'=>max(0,$afterItems-$beforeItems),'amateur_count'=>$amateurCount,'total_items'=>$afterItems];
}
