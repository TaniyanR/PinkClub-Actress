<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/actress_item_sync.php';
require_once __DIR__ . '/actress_product_coverage.php';

function pca_run_sync_cycle(): array
{
    $pdo = db();
    $offset = max(1, (int)site_setting_get('pca_actress_sync_offset', '1'));
    $actressBatch = 100;

    $beforeActresses = (int)$pdo->query('SELECT COUNT(*) FROM actresses')->fetchColumn();
    $processedActresses = dmm_sync_service('actresses')->syncMaster('actress', null, $offset, $actressBatch);
    $afterActresses = (int)$pdo->query('SELECT COUNT(*) FROM actresses')->fetchColumn();
    $nextOffset = $processedActresses < $actressBatch ? 1 : $offset + $actressBatch;
    if ($nextOffset > 50000) $nextOffset = 1;
    site_setting_set_many(['pca_actress_sync_offset' => (string)$nextOffset]);

    // 女優画像の個別API確認は外部通信が重いため10人ずつ。
    $images = pca_enrich_missing_actress_images(10);

    // 既存商品は100件ずつだけ出演者関係を修復する。
    $repair = pca_repair_item_actress_relations_batch(100);

    // 通常女優は10人×最大10作品。外部商品APIは最大10回。
    $normal = pca_sync_saved_actress_product_coverage(10, 10);

    // しろうと女性はvideocを100作品ずつ取得する。
    $amateur = pca_sync_amateur_floor_batch(100);

    $totalItems = 0;
    try {
        $totalItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    } catch (Throwable) {
    }

    $newActresses = max(0, $afterActresses - $beforeActresses);
    $message = '女優 ' . $processedActresses . '件取得（新規 ' . $newActresses . '人） / '
        . '画像 ' . (int)($images['processed'] ?? 0) . '人確認・' . (int)($images['updated'] ?? 0) . '人補完 / '
        . '通常女優 ' . (int)($normal['processed_actresses'] ?? 0) . '人の商品確認'
        . '（API ' . (int)($normal['api_count'] ?? 0) . '件 / 新規 ' . (int)($normal['new_items'] ?? 0) . '件 / 商品カード対象 '
        . (int)($normal['coverage_before'] ?? 0) . '人→' . (int)($normal['coverage_after'] ?? 0) . '人） / '
        . 'しろうと作品 ' . (int)($amateur['api_count'] ?? 0) . '件取得（登録しろうと女性 ' . (int)($amateur['amateur_count'] ?? 0) . '人） / '
        . '既存作品の出演者関係 ' . (int)($repair['processed'] ?? 0) . '件修復';

    site_setting_set_many([
        'pca_sync_last_run_at' => date('Y-m-d H:i:s'),
        'pca_sync_last_message' => $message,
    ]);

    return [
        'actresses' => $processedActresses,
        'images_processed' => (int)($images['processed'] ?? 0),
        'images_updated' => (int)($images['updated'] ?? 0),
        'normal_items' => (int)($normal['api_count'] ?? 0),
        'normal_actresses_processed' => (int)($normal['processed_actresses'] ?? 0),
        'synced_items' => (int)($normal['api_count'] ?? 0) + (int)($amateur['api_count'] ?? 0),
        'new_items' => (int)($normal['new_items'] ?? 0) + (int)($amateur['new_count'] ?? 0),
        'total_items' => $totalItems,
        'linked_actresses' => (int)($normal['coverage_after'] ?? 0),
        'amateur_count' => (int)($amateur['amateur_count'] ?? 0),
        'relations_repaired' => (int)($repair['processed'] ?? 0),
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
