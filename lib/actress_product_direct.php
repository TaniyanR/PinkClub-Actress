<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/dmm_normalizer.php';
require_once __DIR__ . '/actress_product_coverage.php';

/**
 * 女優を指定した商品取得を1回のItemListリクエストだけで完結させる。
 * syncItemsBatch() は「新規商品数」を満たすまで再リクエストする用途のため、
 * 既存商品が多い女優では同じHTTPリクエスト内でAPI通信が増えやすい。
 * PinkClub-Actressの商品カード補完では、1女優につき1回だけ取得して保存する。
 *
 * @return array{api_count:int,new_count:int,saved_count:int,item_count:int}
 */
function pca_direct_sync_actress_products(int $actressId, string $dmmId, string $actressName, int $hits = 10): array
{
    $actressId = max(0, $actressId);
    $dmmId = trim($dmmId);
    $actressName = trim($actressName);
    $hits = max(1, min(20, $hits));

    if ($actressId <= 0 || $dmmId === '' || $actressName === '' || preg_match('/^[0-9]+$/', $dmmId) !== 1) {
        return ['api_count'=>0,'new_count'=>0,'saved_count'=>0,'item_count'=>0];
    }

    $client = dmm_client_for_type('items');
    $response = $client->fetchItems('FANZA', 'digital', 'videoa', [
        'hits' => $hits,
        'offset' => 1,
        'sort' => 'date',
        'article' => 'actress',
        'article_id' => $dmmId,
    ]);
    $items = DmmNormalizer::normalizeItemsResponse($response);
    $apiCount = count($items);
    if ($items === []) {
        pca_product_coverage_save_state($actressId, $dmmId, 0, 0, '');
        return ['api_count'=>0,'new_count'=>0,'saved_count'=>0,'item_count'=>0];
    }

    $pdo = db();
    $newCount = 0;
    $savedCount = 0;

    $sql = 'INSERT INTO items(content_id,product_id,item_source,title,service_code,service_name,floor_code,floor_name,category_name,volume,review_count,review_average,url,affiliate_url,image_list,image_small,image_large,sample_movie_url_476,sample_movie_url_560,sample_movie_url_644,sample_movie_url_720,sample_movie_pc_flag,sample_movie_sp_flag,price_min_text,list_price_text,release_date,raw_json,updated_at)
            VALUES(:content_id,:product_id,:item_source,:title,:service_code,:service_name,:floor_code,:floor_name,:category_name,:volume,:review_count,:review_average,:url,:affiliate_url,:image_list,:image_small,:image_large,:u476,:u560,:u644,:u720,:pc,:sp,:price_min,:list_price,:release_date,:raw_json,NOW())
            ON DUPLICATE KEY UPDATE item_source=VALUES(item_source),title=VALUES(title),service_code=VALUES(service_code),service_name=VALUES(service_name),floor_code=VALUES(floor_code),floor_name=VALUES(floor_name),category_name=VALUES(category_name),volume=VALUES(volume),review_count=VALUES(review_count),review_average=VALUES(review_average),url=VALUES(url),affiliate_url=VALUES(affiliate_url),image_list=VALUES(image_list),image_small=VALUES(image_small),image_large=VALUES(image_large),sample_movie_url_476=VALUES(sample_movie_url_476),sample_movie_url_560=VALUES(sample_movie_url_560),sample_movie_url_644=VALUES(sample_movie_url_644),sample_movie_url_720=VALUES(sample_movie_url_720),sample_movie_pc_flag=VALUES(sample_movie_pc_flag),sample_movie_sp_flag=VALUES(sample_movie_sp_flag),price_min_text=VALUES(price_min_text),list_price_text=VALUES(list_price_text),release_date=VALUES(release_date),raw_json=VALUES(raw_json),updated_at=NOW()';
    $upsert = $pdo->prepare($sql);
    $findId = $pdo->prepare('SELECT id FROM items WHERE content_id=:content_id LIMIT 1');
    $exists = $pdo->prepare('SELECT 1 FROM items WHERE content_id=:content_id LIMIT 1');
    $deleteRelations = $pdo->prepare('DELETE FROM item_actresses WHERE item_id=:item_id');
    $insertRelation = $pdo->prepare('INSERT IGNORE INTO item_actresses(item_id,dmm_id,actress_name) VALUES(:item_id,:dmm_id,:name)');

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            $contentId = trim((string)($item['content_id'] ?? ''));
            if ($contentId === '') continue;

            $exists->execute([':content_id'=>$contentId]);
            $wasExisting = $exists->fetchColumn() !== false;

            $upsert->execute([
                ':content_id'=>$contentId,
                ':product_id'=>$item['product_id'] ?? null,
                ':item_source'=>'fanza_product',
                ':title'=>(string)($item['title'] ?? ''),
                ':service_code'=>$item['service_code'] ?? null,
                ':service_name'=>$item['service_name'] ?? null,
                ':floor_code'=>$item['floor_code'] ?? 'videoa',
                ':floor_name'=>$item['floor_name'] ?? null,
                ':category_name'=>$item['category_name'] ?? null,
                ':volume'=>$item['volume'] ?? null,
                ':review_count'=>$item['review_count'] ?? null,
                ':review_average'=>$item['review_average'] ?? null,
                ':url'=>$item['url'] ?? null,
                ':affiliate_url'=>$item['affiliate_url'] ?? null,
                ':image_list'=>$item['image_list'] ?? null,
                ':image_small'=>$item['image_small'] ?? null,
                ':image_large'=>$item['image_large'] ?? null,
                ':u476'=>$item['sample_movie_url_476'] ?? null,
                ':u560'=>$item['sample_movie_url_560'] ?? null,
                ':u644'=>$item['sample_movie_url_644'] ?? null,
                ':u720'=>$item['sample_movie_url_720'] ?? null,
                ':pc'=>(int)($item['sample_movie_pc_flag'] ?? 0),
                ':sp'=>(int)($item['sample_movie_sp_flag'] ?? 0),
                ':price_min'=>$item['price_min_text'] ?? null,
                ':list_price'=>$item['list_price_text'] ?? null,
                ':release_date'=>$item['release_date'] ?? null,
                ':raw_json'=>json_encode($item['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $findId->execute([':content_id'=>$contentId]);
            $itemId = (int)($findId->fetchColumn() ?: 0);
            if ($itemId <= 0) continue;

            // ItemListをこの女優IDで絞って取得しているため、対象女優との関係を必ず保存する。
            // APIのiteminfo.actressが欠ける作品でも商品カードを失わない。
            $deleteRelations->execute([':item_id'=>$itemId]);
            $performers = is_array($item['actresses'] ?? null) ? $item['actresses'] : [];
            foreach ($performers as $performer) {
                if (!is_array($performer)) continue;
                $name = trim((string)($performer['name'] ?? ''));
                if ($name === '') continue;
                $performerId = trim((string)($performer['id'] ?? ''));
                if ($performerId === '') $performerId = 'name:' . sha1(mb_strtolower($name, 'UTF-8'));
                $insertRelation->execute([':item_id'=>$itemId,':dmm_id'=>$performerId,':name'=>$name]);
            }
            $insertRelation->execute([':item_id'=>$itemId,':dmm_id'=>$dmmId,':name'=>$actressName]);

            $savedCount++;
            if (!$wasExisting) $newCount++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $itemCount = pca_product_coverage_count_for_dmm_id($dmmId);
    pca_product_coverage_save_state($actressId, $dmmId, $itemCount, $apiCount, '');

    return [
        'api_count'=>$apiCount,
        'new_count'=>$newCount,
        'saved_count'=>$savedCount,
        'item_count'=>$itemCount,
    ];
}

/**
 * 1サイクルでは10女優×最大10作品 = 最大100作品を補完する。
 * 外部APIは最大10回なので、共有サーバーのWeb実行時間を超えにくい。
 */
function pca_direct_sync_product_batch(int $actressLimit = 10, int $hitsPerActress = 10): array
{
    $actressLimit = max(1, min(10, $actressLimit));
    $hitsPerActress = max(1, min(10, $hitsPerActress));
    pca_product_coverage_ensure_state_table();

    $coverageBefore = pca_product_coverage_count_linked_actresses();
    $targets = pca_product_coverage_targets($actressLimit);
    $processed = 0;
    $apiCount = 0;
    $newItems = 0;
    $savedItems = 0;
    $withProducts = 0;
    $errors = 0;

    foreach ($targets as $target) {
        $actressId = (int)($target['id'] ?? 0);
        $dmmId = trim((string)($target['dmm_id'] ?? ''));
        $name = trim((string)($target['name'] ?? ''));
        if ($actressId <= 0 || $dmmId === '' || $name === '') continue;
        $processed++;
        try {
            $result = pca_direct_sync_actress_products($actressId, $dmmId, $name, $hitsPerActress);
            $apiCount += (int)($result['api_count'] ?? 0);
            $newItems += (int)($result['new_count'] ?? 0);
            $savedItems += (int)($result['saved_count'] ?? 0);
            if ((int)($result['item_count'] ?? 0) > 0) $withProducts++;
        } catch (Throwable $e) {
            $errors++;
            try { pca_product_coverage_save_state($actressId, $dmmId, 0, 0, $e->getMessage()); } catch (Throwable) {}
            error_log('direct actress product sync failed for '.$dmmId.' ('.$name.'): '.$e->getMessage());
        }
    }

    $coverageAfter = pca_product_coverage_count_linked_actresses();
    return [
        'processed_actresses'=>$processed,
        'api_count'=>$apiCount,
        'new_items'=>$newItems,
        'saved_items'=>$savedItems,
        'with_products'=>$withProducts,
        'errors'=>$errors,
        'coverage_before'=>$coverageBefore,
        'coverage_after'=>$coverageAfter,
    ];
}
