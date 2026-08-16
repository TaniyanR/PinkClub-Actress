<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/actress_item_sync.php';

/**
 * PinkClub-Actress の1回分の同期処理。
 * cron と管理画面の手動実行は必ずこの関数を共用する。
 *
 * 1. 女優マスタを取得
 * 2. 画像未取得女優を少数補完
 * 3. 保存済み女優を順番に処理し、その女優の作品だけを取得
 *
 * @return array{actresses:int,images_processed:int,images_updated:int,processed_actresses:int,synced_items:int,new_items:int,total_items:int,message:string}
 */
function pca_run_sync_cycle(): array
{
    $pdo = db();
    $offset = max(1, (int)site_setting_get('pca_actress_sync_offset', '1'));
    $actressBatch = 100;

    $beforeActresses = (int)$pdo->query('SELECT COUNT(*) FROM actresses')->fetchColumn();
    $processedActresses = dmm_sync_service('actresses')->syncMaster('actress', null, $offset, $actressBatch);
    $afterActresses = (int)$pdo->query('SELECT COUNT(*) FROM actresses')->fetchColumn();

    $nextOffset = $processedActresses < $actressBatch ? 1 : $offset + $actressBatch;
    if ($nextOffset > 50000) {
        $nextOffset = 1;
    }
    site_setting_set_many(['pca_actress_sync_offset' => (string)$nextOffset]);

    $images = pca_enrich_missing_actress_images(5);
    $items = pca_sync_items_for_saved_actresses(5, 10);

    $newActresses = max(0, $afterActresses - $beforeActresses);
    $message = '女優 ' . $processedActresses . '件取得（新規 ' . $newActresses . '人） / '
        . '画像 ' . (int)($images['updated'] ?? 0) . '人補完 / '
        . (int)($items['processed_actresses'] ?? 0) . '人分の作品を取得（新規作品 '
        . (int)($items['new_count'] ?? 0) . '件）';

    site_setting_set_many([
        'pca_sync_last_run_at' => date('Y-m-d H:i:s'),
        'pca_sync_last_message' => $message,
    ]);

    return [
        'actresses' => $processedActresses,
        'images_processed' => (int)($images['processed'] ?? 0),
        'images_updated' => (int)($images['updated'] ?? 0),
        'processed_actresses' => (int)($items['processed_actresses'] ?? 0),
        'synced_items' => (int)($items['synced_count'] ?? 0),
        'new_items' => (int)($items['new_count'] ?? 0),
        'total_items' => (int)($items['total_items'] ?? 0),
        'message' => $message,
    ];
}

/**
 * cron 用。自動設定のON/OFFと間隔を尊重して、期限が来た時だけ1サイクル実行する。
 */
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

    $result = pca_run_sync_cycle();
    return array_merge(['status' => 'ran'], $result);
}
