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
                if (is_array($apiRow) && trim((string)($apiRow['id'] ?? '')) === $dmmId) { $best = $apiRow; break; }
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

function pca_ensure_actress_item_sync_state_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS pca_actress_item_sync_state (
        actress_dmm_id VARCHAR(64) PRIMARY KEY,
        last_checked_at DATETIME NOT NULL,
        last_api_count INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pca_actress_item_checked (last_checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
 * 保存済み女優を公平に巡回する。
 * 「作品が0件の女優」を未取得扱いで永久ループしないよう、専用stateテーブルで確認済みを管理する。
 */
function pca_sync_items_for_next_saved_actress(int $batch = 10): array
{
    $batch = max(1, min(100, $batch));
    $pdo = db();
    pca_ensure_actress_item_sync_state_table();

    $stmt = $pdo->query(
        "SELECT a.id,a.dmm_id,a.name
         FROM actresses a
         LEFT JOIN pca_actress_item_sync_state s ON s.actress_dmm_id=a.dmm_id
         WHERE TRIM(COALESCE(a.name,''))<>'' AND a.dmm_id REGEXP '^[0-9]+$'
         ORDER BY (s.last_checked_at IS NULL) DESC, s.last_checked_at ASC, a.id ASC
         LIMIT 1"
    );
    $actress = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$actress) throw new RuntimeException('保存済み女優がありません。');

    $actressId = (int)($actress['id'] ?? 0);
    $actressDmmId = trim((string)($actress['dmm_id'] ?? ''));
    $actressName = trim((string)($actress['name'] ?? ''));
    if ($actressId <= 0 || $actressDmmId === '' || $actressName === '') throw new RuntimeException('保存済み女優データが不正です。');

    $offsetKey = 'actress_item_sync_offset.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $actressDmmId . '.videoa');
    $offset = max(1, (int)site_setting_get($offsetKey, '1'));
    $beforeCount = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $result = dmm_sync_service('items')->syncItemsBatch('FANZA','digital','videoa',$batch,$offset,[
        'sort'=>'rank','article'=>'actress','article_id'=>$actressDmmId,
    ]);

    // relationはDmmSyncService::rebuildItemRelations()がAPIレスポンスから正確に作る。
    // ここでupdated_at基準の一括紐付けは絶対に行わない。
    $count = (int)($result['api_count'] ?? $result['synced_count'] ?? 0);
    $pdo->prepare("INSERT INTO pca_actress_item_sync_state(actress_dmm_id,last_checked_at,last_api_count,updated_at)
        VALUES(:id,NOW(),:cnt,NOW()) ON DUPLICATE KEY UPDATE last_checked_at=NOW(),last_api_count=VALUES(last_api_count),updated_at=NOW()")
        ->execute([':id'=>$actressDmmId,':cnt'=>$count]);

    site_setting_set_many([$offsetKey=>(string)max(1,(int)($result['next_offset'] ?? 1))]);
    $afterCount = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    return [
        'actress_id'=>$actressId,'actress_dmm_id'=>$actressDmmId,'actress_name'=>$actressName,
        'synced_count'=>$count,'new_count'=>max(0,$afterCount-$beforeCount),'total_items'=>$afterCount,
        'message'=>$actressName.' の作品を取得しました（API '.$count.'件）。',
    ];
}

function pca_sync_items_for_saved_actresses(int $actressCount = 100, int $batchPerActress = 10): array
{
    $actressCount = max(1, min(100, $actressCount));
    $batchPerActress = max(1, min(100, $batchPerActress));
    $processed=0; $synced=0; $new=0; $totalItems=(int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
    for ($i=0; $i<$actressCount; $i++) {
        $result = pca_sync_items_for_next_saved_actress($batchPerActress);
        $processed++;
        $synced += (int)($result['synced_count'] ?? 0);
        $new += (int)($result['new_count'] ?? 0);
        $totalItems = (int)($result['total_items'] ?? $totalItems);
    }
    return ['processed_actresses'=>$processed,'synced_count'=>$synced,'new_count'=>$new,'total_items'=>$totalItems,'message'=>$processed.'人分の作品を取得しました。'];
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
    } catch (Throwable) {}
    return ['api_count'=>$apiCount,'new_count'=>max(0,$afterItems-$beforeItems),'amateur_count'=>$amateurCount,'total_items'=>$afterItems];
}
