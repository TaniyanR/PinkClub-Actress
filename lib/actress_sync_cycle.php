<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/actress_item_sync.php';
require_once __DIR__ . '/actress_product_coverage.php';
require_once __DIR__ . '/actress_product_direct.php';

/**
 * 公開女優一覧は同名人物を1人へまとめて表示している一方、DBには同じ名前で
 * DMM女優IDが複数残ることがある。
 *
 * 商品取得先のIDと、一覧キャッシュが保持しているIDが別だと、商品自体は保存済みでも
 * 個別ページが0件になる。そのため外部API通信の後に、同名の数値DMM IDへ商品関係を
 * DB内だけで伝播させる。LIMITで一度に大量処理しすぎないよう複数回に分ける。
 */
function pca_repair_product_aliases_for_public_pages(): int
{
    $total = 0;
    for ($pass = 0; $pass < 5; $pass++) {
        $added = pca_product_coverage_repair_name_aliases(10000);
        $total += $added;
        if ($added < 10000) {
            break;
        }
    }
    return $total;
}

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

    // 個別ActressSearchを100回連続で行うと共有サーバーのWeb実行時間を超えるため、
    // 画像補完は10人ずつ進める。女優情報100件の取得自体は従来どおり1回で行う。
    $images = pca_enrich_missing_actress_images(10);

    // 既存作品の壊れた出演者関係を100件ずつraw_jsonから修復する（外部API通信なし）。
    $repair = pca_repair_item_actress_relations_batch(100);

    // 通常商品は10女優×最大10作品で最大100作品。
    // 外部商品APIは最大10回に抑え、各取得結果をその女優IDへ明示的に紐付ける。
    $normal = pca_direct_sync_product_batch(10, 10);

    // 重要: 女優API由来のIDと商品API由来のIDが同名人物で異なる既存DBをここで吸収する。
    // 公開一覧キャッシュが古い別IDを指していても、そのIDから商品カードを引ける状態にする。
    $aliasRelations = pca_repair_product_aliases_for_public_pages();
    $coverageAfterAliases = pca_product_coverage_count_linked_actresses();
    $normal['coverage_after'] = $coverageAfterAliases;

    // しろうと女性は女優APIに存在しないため、videocフロアを100作品単位で取得する。
    $amateur = pca_sync_amateur_floor_batch(100);

    $totalItems = 0;
    try {
        $totalItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    } catch (Throwable) {
    }

    $newActresses = max(0, $afterActresses - $beforeActresses);
    $message = '女優 '.$processedActresses.'件取得（新規 '.$newActresses.'人） / '
        . '画像 '.(int)($images['processed'] ?? 0).'人確認・'.(int)($images['updated'] ?? 0).'人補完 / '
        . '通常商品 '.(int)($normal['processed_actresses'] ?? 0).'女優を直接確認'
        . '（API '.(int)($normal['api_count'] ?? 0).'作品 / 保存 '.(int)($normal['saved_items'] ?? 0).'作品 / 新規 '.(int)($normal['new_items'] ?? 0).'作品 / 商品あり '.(int)($normal['with_products'] ?? 0).'女優 / 商品カード対象 '
        . (int)($normal['coverage_before'] ?? 0).'人→'.(int)($normal['coverage_after'] ?? 0).'人） / '
        . '同名別IDの商品関係 '.(int)$aliasRelations.'件補完 / '
        . 'しろうと作品 '.(int)($amateur['api_count'] ?? 0).'件取得（登録しろうと女性 '.(int)($amateur['amateur_count'] ?? 0).'人） / '
        . '既存作品の出演者関係 '.(int)($repair['processed'] ?? 0).'件修復';

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
        'alias_relations_repaired'=>(int)$aliasRelations,
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
