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
    site_setting_set_many(['pca_actress_sync_offset'=>(string)$nextOffset]);

    $images = pca_enrich_missing_actress_images(100);

    // 既存商品100件の出演者関係をraw_jsonから先に修復し、その後で同名ID差を補完する。
    $repair = pca_repair_item_actress_relations_batch(100);

    // PinkClub-Actressの主役は保存済み女優。
    // 1サイクル100人を直接検索し、まず各女優に最低1作品を行き渡らせる。
    // これにより100人確認でもAPIの無駄な再ページングを避け、商品カードの対象人数を最速で増やす。
    $normal = pca_sync_saved_actress_product_coverage(100, 1);

    // しろうと女性は女優APIに存在しないため、従来どおりvideocフロアを100作品ずつ巡回する。
    $amateur = pca_sync_amateur_floor_batch(100);

    $totalItems = 0;
    try {
        $totalItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    } catch (Throwable) {
    }

    $newActresses = max(0, $afterActresses - $beforeActresses);
    $message = '女優 '.$processedActresses.'件取得（新規 '.$newActresses.'人） / '
        . '画像 '.(int)($images['processed'] ?? 0).'人確認・'.(int)($images['updated'] ?? 0).'人補完 / '
        . '保存済み女優 '.(int)($normal['processed_actresses'] ?? 0).'人分の商品確認'
        . '（API '.(int)($normal['api_count'] ?? 0).'件 / 新規 '.(int)($normal['new_items'] ?? 0).'件 / 商品カード対象 '
        . (int)($normal['coverage_before'] ?? 0).'人→'.(int)($normal['coverage_after'] ?? 0).'人） / '
        . 'しろうと作品 '.(int)($amateur['api_count'] ?? 0).'件取得（登録しろうと女性 '.(int)($amateur['amateur_count'] ?? 0).'人） / '
        . '既存作品の出演者関係 '.(int)($repair['processed'] ?? 0).'件修復 / '
        . '同名ID差の商品関係 '.(int)($normal['aliases_added'] ?? 0).'件補完';

    site_setting_set_many([
        'pca_sync_last_run_at'=>date('Y-m-d H:i:s'),
        'pca_sync_last_message'=>$message,
    ]);

    return [
        'actresses'=>$processedActresses,
        'images_processed'=>(int)($images['processed'] ?? 0),
        'images_updated'=>(int)($images['updated'] ?? 0),
        'normal_items'=>(int)($normal['api_count'] ?? 0),
        'normal_actresses_processed'=>(int)($normal['processed_actresses'] ?? 0),
        'synced_items'=>(int)($normal['api_count'] ?? 0) + (int)($amateur['api_count'] ?? 0),
        'new_items'=>(int)($normal['new_items'] ?? 0) + (int)($amateur['new_count'] ?? 0),
        'total_items'=>$totalItems,
        'linked_actresses'=>(int)($normal['coverage_after'] ?? 0),
        'amateur_count'=>(int)($amateur['amateur_count'] ?? 0),
        'relations_repaired'=>(int)($repair['processed'] ?? 0),
        'aliases_added'=>(int)($normal['aliases_added'] ?? 0),
        'message'=>$message,
    ];
}

function pca_maybe_run_sync_cycle(): array
{
    if (!settings_bool('item_sync_enabled', false)) {
        return ['status'=>'disabled','message'=>'自動取得は停止中です。'];
    }
    $intervalMinutes = max(1, settings_int('item_sync_interval_minutes', 60));
    $lastRun = trim(site_setting_get('pca_sync_last_run_at', ''));
    if ($lastRun !== '') {
        $lastTimestamp = strtotime($lastRun);
        if ($lastTimestamp !== false && $lastTimestamp > time() - ($intervalMinutes * 60)) {
            return ['status'=>'idle','message'=>'次回実行時刻前のためスキップしました。'];
        }
    }
    return array_merge(['status'=>'ran'], pca_run_sync_cycle());
}
