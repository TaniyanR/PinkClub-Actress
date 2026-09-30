<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/actress_item_sync.php';
require_once __DIR__ . '/actress_product_coverage.php';
require_once __DIR__ . '/actress_product_direct.php';

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

    // まずvideoaを100作品単位で1回取得し、女優情報APIに登録済みの女優が出演する作品だけ保存する。
    // その後、作品がまだ無い登録女優だけ最大10人を女優ID指定で補完する。
    $floorItems = pca_sync_normal_floor_batch(100);

    // 女優ID指定検索の結果は、商品API側の出演者IDが異なっていても対象女優へ必ず紐付けて保存する。
    $normal = pca_direct_sync_product_batch(10, 10);

    // 女優APIで登録されていない人物だけに紐付く作品は不要なので、段階的に整理する。
    $prunedItems = pca_prune_unregistered_actress_items(500);

    // 以前の pca_repair_item_actress_relations_batch() は既存関係を一度全削除して
    // raw_jsonだけから再構築していたため、女優ID指定検索で補完した正しい関係まで消していた。
    // 商品同期そのものが関係を保存する現在は定期修復を実行しない。

    $totalItems = 0;
    try {
        $totalItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    } catch (Throwable) {
    }

    $newActresses = max(0, $afterActresses - $beforeActresses);
    $message = '女優 ' . $processedActresses . '件取得（新規 ' . $newActresses . '人） / '
        . '画像 ' . (int)($images['processed'] ?? 0) . '人確認・' . (int)($images['updated'] ?? 0) . '人補完 / '
        . '登録女優の通常作品 ' . (int)($floorItems['api_count'] ?? 0) . '件確認・新規 ' . (int)($floorItems['new_count'] ?? 0) . '件 / '
        . '商品未紐付け女優 ' . (int)($normal['processed_actresses'] ?? 0) . '人を補完'
        . '（API ' . (int)($normal['api_count'] ?? 0) . '件 / 保存 ' . (int)($normal['saved_items'] ?? 0) . '件 / 新規 ' . (int)($normal['new_items'] ?? 0) . '件 / 同名既存関係 ' . (int)($normal['copied_relations'] ?? 0) . '件補完 / 商品カード対象 '
        . (int)($normal['coverage_before'] ?? 0) . '人→' . (int)($normal['coverage_after'] ?? 0) . '人） / '
        . '登録女優に紐付かない既存作品 ' . $prunedItems . '件整理';

    site_setting_set_many([
        'pca_sync_last_run_at' => date('Y-m-d H:i:s'),
        'pca_sync_last_message' => $message,
    ]);

    return [
        'actresses' => $processedActresses,
        'images_processed' => (int)($images['processed'] ?? 0),
        'images_updated' => (int)($images['updated'] ?? 0),
        'normal_items' => (int)($floorItems['api_count'] ?? 0) + (int)($normal['api_count'] ?? 0),
        'normal_actresses_processed' => (int)($normal['processed_actresses'] ?? 0),
        'synced_items' => (int)($floorItems['api_count'] ?? 0) + (int)($normal['api_count'] ?? 0),
        'new_items' => (int)($floorItems['new_count'] ?? 0) + (int)($normal['new_items'] ?? 0),
        'total_items' => $totalItems,
        'linked_actresses' => (int)($normal['coverage_after'] ?? 0),
        'pruned_items' => $prunedItems,
        'copied_relations' => (int)($normal['copied_relations'] ?? 0),
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
