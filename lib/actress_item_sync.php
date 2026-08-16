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

/**
 * 保存済み女優のうち、作品がまだ1件も紐付いていない人を最優先する。
 * 未取得女優が残っている間はカーソルを使わず、必ず未取得の先頭を選ぶ。
 * 全員に作品確認済みとなった後だけカーソル巡回に戻す。
 */
function pca_sync_items_for_next_saved_actress(int $batch = 10): array
{
    $batch = max(1, min(100, $batch));
    $pdo = db();

    $unsyncedStmt = $pdo->query(
        "SELECT a.id,a.dmm_id,a.name
         FROM actresses a
         WHERE TRIM(COALESCE(a.name,''))<>''
           AND a.dmm_id REGEXP '^[0-9]+$'
           AND NOT EXISTS (
             SELECT 1 FROM item_actresses ia
             WHERE ia.dmm_id=a.dmm_id OR ia.actress_name=a.name
           )
         ORDER BY a.id ASC
         LIMIT 1"
    );
    $actress = $unsyncedStmt ? $unsyncedStmt->fetch(PDO::FETCH_ASSOC) : false;

    if (!$actress) {
        $rows = $pdo->query(
            "SELECT a.id,a.dmm_id,a.name
             FROM actresses a
             WHERE TRIM(COALESCE(a.name,''))<>'' AND a.dmm_id REGEXP '^[0-9]+$'
             ORDER BY a.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) throw new RuntimeException('保存済み女優がありません。');
        $cursor = max(0, (int)site_setting_get('actress_item_sync_cursor', '0'));
        if ($cursor >= count($rows)) $cursor = 0;
        $actress = $rows[$cursor];
        site_setting_set_many(['actress_item_sync_cursor'=>(string)(($cursor + 1) % count($rows))]);
    }

    $actressId = (int)($actress['id'] ?? 0);
    $actressDmmId = trim((string)($actress['dmm_id'] ?? ''));
    $actressName = trim((string)($actress['name'] ?? ''));
    if ($actressId <= 0 || $actressDmmId === '' || $actressName === '') throw new RuntimeException('保存済み女優データが不正です。');

    $offsetKey = 'actress_item_sync_offset.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $actressDmmId . '.videoa');
    $offset = max(1, (int)site_setting_get($offsetKey, '1'));
    $beforeCount = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $syncStartedAt = date('Y-m-d H:i:s');
    $result = dmm_sync_service('items')->syncItemsBatch('FANZA','digital','videoa',$batch,$offset,[
        'sort'=>'rank','article'=>'actress','article_id'=>$actressDmmId,
    ]);

    try {
        $link = $pdo->prepare("INSERT IGNORE INTO item_actresses (item_id,dmm_id,actress_name)
            SELECT id,:dmm_id,:name FROM items WHERE floor_code='videoa' AND updated_at>=:started_at");
        $link->execute([':dmm_id'=>$actressDmmId,':name'=>$actressName,':started_at'=>$syncStartedAt]);
    } catch (Throwable $e) {
        error_log('explicit actress item relation failed: ' . $e->getMessage());
    }

    $count = (int)($result['api_count'] ?? $result['synced_count'] ?? 0);
    site_setting_set_many([$offsetKey=>(string)max(1,(int)($result['next_offset'] ?? 1))]);
    $afterCount = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    return [
        'actress_id'=>$actressId,'actress_dmm_id'=>$actressDmmId,'actress_name'=>$actressName,
        'synced_count'=>$count,'new_count'=>max(0,$afterCount-$beforeCount),'total_items'=>$afterCount,
        'message'=>$actressName.' の作品を取得しました（'.$count.'件）。',
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

/**
 * 女優APIに存在しないしろうと女性を発見するため、videocフロアを直接100作品ずつ巡回する。
 * DmmNormalizer側で、videocにactress情報が無い場合はPinkClub-Shirotoと同じく作品タイトルを
 * 女性名として補完するため、保存後のitem_actressesから人物マスタへ登録できる。
 */
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
