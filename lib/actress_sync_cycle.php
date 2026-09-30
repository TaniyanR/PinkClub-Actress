<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/actress_item_sync.php';
require_once __DIR__ . '/actress_product_coverage.php';
require_once __DIR__ . '/actress_product_direct.php';

/**
 * PinkClub-Actress の正規同期サイクル。
 *
 * 1. 女優APIから女優マスタを100件取得
 * 2. 画像不足女優を少量補完
 * 3. 登録済み女優5人について各20作品ずつ取得
 *
 * 商品APIだけを根拠に女優マスタを増やさない。
 * 女優APIに登録されていない女性だけの作品は保存対象にしない。
 */
function pca_run_sync_cycle(): array
{
    $pdo = db();
    $offset = max(1, (int)site_setting_get('pca_actress_sync_offset', '1'));
    $actressBatch = 100;

    $beforeActresses = (int)$pdo->query("SELECT COUNT(*) FROM actresses WHERE dmm_id REGEXP '^[0-9]+$'")->fetchColumn();
    $processedActresses = dmm_sync_service('actresses')->syncMaster('actress', null, $offset, $actressBatch);
    $afterActresses = (int)$pdo->query("SELECT COUNT(*) FROM actresses WHERE dmm_id REGEXP '^[0-9]+$'")->fetchColumn();

    $nextOffset = $processedActresses < $actressBatch ? 1 : $offset + $actressBatch;
    if ($nextOffset > 50000) {
        $nextOffset = 1;
    }
    site_setting_set_many(['pca_actress_sync_offset' => (string)$nextOffset]);

    // ActressSearchの一覧応答で画像が欠けた女優だけを少量ずつ補完する。
    $images = pca_enrich_missing_actress_images(10);

    // 外部APIは最大5回、保存候補は最大100作品。
    $products = pca_direct_sync_product_batch(5, 20);

    $totalItems = 0;
    try {
        $totalItems = (int)$pdo->query(
            "SELECT COUNT(DISTINCT i.id)
             FROM items i
             WHERE i.item_source='fanza_product'
               AND EXISTS (
                   SELECT 1
                   FROM item_actresses ia
                   INNER JOIN actresses a ON a.dmm_id=ia.dmm_id
                   WHERE ia.item_id=i.id
                     AND a.dmm_id REGEXP '^[0-9]+$'
               )"
        )->fetchColumn();
    } catch (Throwable) {
    }

    $newActresses = max(0, $afterActresses - $beforeActresses);
    $message = '女優 ' . $processedActresses . '件取得（新規 ' . $newActresses . '人） / '
        . '画像 ' . (int)($images['processed'] ?? 0) . '人確認・' . (int)($images['updated'] ?? 0) . '人補完 / '
        . '登録済み女優 ' . (int)($products['processed_actresses'] ?? 0) . '人の作品確認'
        . '（API ' . (int)($products['api_count'] ?? 0) . '件 / 保存 ' . (int)($products['saved_items'] ?? 0)
        . '件 / 新規 ' . (int)($products['new_items'] ?? 0) . '件 / 更新 ' . (int)($products['updated_items'] ?? 0)
        . '件 / 商品カード対象 ' . (int)($products['coverage_before'] ?? 0) . '人→'
        . (int)($products['coverage_after'] ?? 0) . '人）';

    site_setting_set_many([
        'pca_sync_last_run_at' => date('Y-m-d H:i:s'),
        'pca_sync_last_message' => $message,
    ]);

    return [
        'actresses' => $processedActresses,
        'images_processed' => (int)($images['processed'] ?? 0),
        'images_updated' => (int)($images['updated'] ?? 0),
        'normal_items' => (int)($products['api_count'] ?? 0),
        'normal_actresses_processed' => (int)($products['processed_actresses'] ?? 0),
        'synced_items' => (int)($products['saved_items'] ?? 0),
        'new_items' => (int)($products['new_items'] ?? 0),
        'total_items' => $totalItems,
        'linked_actresses' => (int)($products['coverage_after'] ?? 0),
        'message' => $message,
    ];
}

function pca_maybe_run_sync_cycle(): array
{
    if (!settings_bool('item_sync_enabled', false)) {
        return ['status' => 'disabled', 'message' => '自動取得は停止中です。'];
    }

    $intervalMinutes = max(1, settings_int('item_sync_interval_minutes', 60));
    $lastRun = trim(site_setting_get('pca_sync_last_run_at', ''));
    if ($lastRun !== '') {
        $lastTimestamp = strtotime($lastRun);
        if ($lastTimestamp !== false && $lastTimestamp > time() - ($intervalMinutes * 60)) {
            return ['status' => 'idle', 'message' => '次回実行時刻前のためスキップしました。'];
        }
    }

    return array_merge(['status' => 'ran'], pca_run_sync_cycle());
}
