<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/dmm_normalizer.php';
require_once __DIR__ . '/actress_product_coverage.php';
require_once __DIR__ . '/indexnow.php';

/**
 * 商品API側の出演者IDと女優API側のDMM女優IDが異なる既存データを、
 * 今回処理する女優だけ同名で補完する。
 * 全女優・全作品の一括走査は行わない。
 */
function pca_direct_copy_existing_products_by_same_name(string $dmmId, string $actressName, int $limit = 200): int
{
    $dmmId = trim($dmmId);
    $actressName = trim($actressName);
    $limit = max(1, min(500, $limit));
    if ($dmmId === '' || $actressName === '') {
        return 0;
    }

    try {
        $sql = "INSERT IGNORE INTO item_actresses(item_id,dmm_id,actress_name)
                SELECT DISTINCT ia.item_id,:dmm_id,:actress_name
                FROM item_actresses ia
                INNER JOIN items i ON i.id=ia.item_id
                WHERE i.floor_code='videoa'
                  AND LOWER(REPLACE(REPLACE(TRIM(ia.actress_name),' ',''),'　',''))
                      = LOWER(REPLACE(REPLACE(TRIM(:match_name),' ',''),'　',''))
                ORDER BY ia.item_id DESC
                LIMIT {$limit}";
        $stmt = db()->prepare($sql);
        $stmt->execute([
            ':dmm_id' => $dmmId,
            ':actress_name' => $actressName,
            ':match_name' => $actressName,
        ]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('targeted same-name product relation copy failed for ' . $dmmId . ' (' . $actressName . '): ' . $e->getMessage());
        return 0;
    }
}

/**
 * 女優ID指定の商品APIを1回だけ呼び、返却された作品を保存する。
 *
 * @return array{api_count:int,new_count:int,saved_count:int,item_count:int,copied_count:int}
 */
function pca_direct_sync_actress_products(int $actressId, string $dmmId, string $actressName, int $hits = 10): array
{
    $actressId = max(0, $actressId);
    $dmmId = trim($dmmId);
    $actressName = trim($actressName);
    $hits = max(1, min(20, $hits));

    if ($actressId <= 0 || $dmmId === '' || $actressName === '' || preg_match('/^[0-9]+$/', $dmmId) !== 1) {
        return ['api_count' => 0, 'new_count' => 0, 'saved_count' => 0, 'item_count' => 0, 'copied_count' => 0];
    }

    // 対象女優は女優情報APIで登録済みであることを必須にする。
    $registeredTarget = db()->prepare("SELECT 1 FROM actresses WHERE id=:id AND dmm_id=:dmm_id AND dmm_id REGEXP '^[0-9]+$' LIMIT 1");
    $registeredTarget->execute([':id' => $actressId, ':dmm_id' => $dmmId]);
    if (!$registeredTarget->fetchColumn()) {
        return ['api_count' => 0, 'new_count' => 0, 'saved_count' => 0, 'item_count' => 0, 'copied_count' => 0];
    }

    // 既存DBに同名の別ID商品がある場合は、対象女優へだけ関係を補完する。
    $copied = pca_direct_copy_existing_products_by_same_name($dmmId, $actressName, 200);
    $existingItemCount = pca_product_coverage_count_for_dmm_id($dmmId);

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
        pca_product_coverage_save_state($actressId, $dmmId, $existingItemCount, 0, '');
        return [
            'api_count' => 0,
            'new_count' => 0,
            'saved_count' => 0,
            'item_count' => $existingItemCount,
            'copied_count' => $copied,
        ];
    }

    $pdo = db();
    $newCount = 0;
    $savedCount = 0;
    $changedItemIds = [];

    $upsert = $pdo->prepare(
        'INSERT INTO items(content_id,product_id,item_source,title,service_code,service_name,floor_code,floor_name,category_name,volume,review_count,review_average,url,affiliate_url,image_list,image_small,image_large,sample_movie_url_476,sample_movie_url_560,sample_movie_url_644,sample_movie_url_720,sample_movie_pc_flag,sample_movie_sp_flag,price_min_text,list_price_text,release_date,raw_json,updated_at)
         VALUES(:content_id,:product_id,:item_source,:title,:service_code,:service_name,:floor_code,:floor_name,:category_name,:volume,:review_count,:review_average,:url,:affiliate_url,:image_list,:image_small,:image_large,:u476,:u560,:u644,:u720,:pc,:sp,:price_min,:list_price,:release_date,:raw_json,NOW())
         ON DUPLICATE KEY UPDATE
           product_id=VALUES(product_id),item_source=VALUES(item_source),title=VALUES(title),service_code=VALUES(service_code),service_name=VALUES(service_name),floor_code=VALUES(floor_code),floor_name=VALUES(floor_name),category_name=VALUES(category_name),volume=VALUES(volume),review_count=VALUES(review_count),review_average=VALUES(review_average),url=VALUES(url),affiliate_url=VALUES(affiliate_url),image_list=VALUES(image_list),image_small=VALUES(image_small),image_large=VALUES(image_large),sample_movie_url_476=VALUES(sample_movie_url_476),sample_movie_url_560=VALUES(sample_movie_url_560),sample_movie_url_644=VALUES(sample_movie_url_644),sample_movie_url_720=VALUES(sample_movie_url_720),sample_movie_pc_flag=VALUES(sample_movie_pc_flag),sample_movie_sp_flag=VALUES(sample_movie_sp_flag),price_min_text=VALUES(price_min_text),list_price_text=VALUES(list_price_text),release_date=VALUES(release_date),raw_json=VALUES(raw_json),updated_at=NOW()'
    );
    $findItem = $pdo->prepare('SELECT id FROM items WHERE content_id=:content_id LIMIT 1');
    $insertRelation = $pdo->prepare('INSERT IGNORE INTO item_actresses(item_id,dmm_id,actress_name) VALUES(:item_id,:dmm_id,:name)');
    $registeredActress = $pdo->prepare("SELECT 1 FROM actresses WHERE dmm_id=:dmm_id AND dmm_id REGEXP '^[0-9]+$' LIMIT 1");

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $contentId = trim((string)($item['content_id'] ?? ''));
            if ($contentId === '') {
                continue;
            }

            $findItem->execute([':content_id' => $contentId]);
            $wasExisting = (int)($findItem->fetchColumn() ?: 0) > 0;

            $upsert->execute([
                ':content_id' => $contentId,
                ':product_id' => $item['product_id'] ?? null,
                ':item_source' => 'fanza_product',
                ':title' => (string)($item['title'] ?? ''),
                ':service_code' => $item['service_code'] ?? null,
                ':service_name' => $item['service_name'] ?? null,
                ':floor_code' => $item['floor_code'] ?? 'videoa',
                ':floor_name' => $item['floor_name'] ?? null,
                ':category_name' => $item['category_name'] ?? null,
                ':volume' => $item['volume'] ?? null,
                ':review_count' => $item['review_count'] ?? null,
                ':review_average' => $item['review_average'] ?? null,
                ':url' => $item['url'] ?? null,
                ':affiliate_url' => $item['affiliate_url'] ?? null,
                ':image_list' => $item['image_list'] ?? null,
                ':image_small' => $item['image_small'] ?? null,
                ':image_large' => $item['image_large'] ?? null,
                ':u476' => $item['sample_movie_url_476'] ?? null,
                ':u560' => $item['sample_movie_url_560'] ?? null,
                ':u644' => $item['sample_movie_url_644'] ?? null,
                ':u720' => $item['sample_movie_url_720'] ?? null,
                ':pc' => (int)($item['sample_movie_pc_flag'] ?? 0),
                ':sp' => (int)($item['sample_movie_sp_flag'] ?? 0),
                ':price_min' => $item['price_min_text'] ?? null,
                ':list_price' => $item['list_price_text'] ?? null,
                ':release_date' => $item['release_date'] ?? null,
                ':raw_json' => json_encode($item['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $findItem->execute([':content_id' => $contentId]);
            $itemId = (int)($findItem->fetchColumn() ?: 0);
            if ($itemId <= 0) {
                continue;
            }

            // 商品APIの出演者のうち、女優情報APIですでに登録済みの人物だけ関係を保存する。
            foreach ((array)($item['actresses'] ?? []) as $performer) {
                if (!is_array($performer)) {
                    continue;
                }
                $performerName = trim((string)($performer['name'] ?? ''));
                $performerId = trim((string)($performer['id'] ?? ''));
                if ($performerName === '' || preg_match('/^[0-9]+$/', $performerId) !== 1) {
                    continue;
                }
                $registeredActress->execute([':dmm_id' => $performerId]);
                if ($registeredActress->fetchColumn()) {
                    $insertRelation->execute([
                        ':item_id' => $itemId,
                        ':dmm_id' => $performerId,
                        ':name' => $performerName,
                    ]);
                }
            }

            // 女優ID指定検索そのものを根拠に、検索対象の登録済み女優との関係を必ず保存する。
            $insertRelation->execute([
                ':item_id' => $itemId,
                ':dmm_id' => $dmmId,
                ':name' => $actressName,
            ]);

            $changedItemIds[$itemId] = true;
            $savedCount++;
            if (!$wasExisting) {
                $newCount++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    foreach (array_keys($changedItemIds) as $changedItemId) {
        pcf_indexnow_item_changed((int)$changedItemId);
    }

    $itemCount = pca_product_coverage_count_for_dmm_id($dmmId);
    pca_product_coverage_save_state($actressId, $dmmId, $itemCount, $apiCount, '');

    return [
        'api_count' => $apiCount,
        'new_count' => $newCount,
        'saved_count' => $savedCount,
        'item_count' => $itemCount,
        'copied_count' => $copied,
    ];
}

/**
 * 1サイクル最大10女優、1女優あたり最大10作品。
 * 外部API通信も最大10回で止める。
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
    $copiedRelations = 0;
    $errors = 0;

    foreach ($targets as $target) {
        $actressId = (int)($target['id'] ?? 0);
        $dmmId = trim((string)($target['dmm_id'] ?? ''));
        $name = trim((string)($target['name'] ?? ''));
        if ($actressId <= 0 || preg_match('/^[0-9]+$/', $dmmId) !== 1 || $name === '') {
            continue;
        }

        $processed++;
        try {
            $result = pca_direct_sync_actress_products($actressId, $dmmId, $name, $hitsPerActress);
            $apiCount += (int)($result['api_count'] ?? 0);
            $newItems += (int)($result['new_count'] ?? 0);
            $savedItems += (int)($result['saved_count'] ?? 0);
            $copiedRelations += (int)($result['copied_count'] ?? 0);
            if ((int)($result['item_count'] ?? 0) > 0) {
                $withProducts++;
            }
        } catch (Throwable $e) {
            $errors++;
            try {
                pca_product_coverage_save_state($actressId, $dmmId, 0, 0, $e->getMessage());
            } catch (Throwable) {
            }
            error_log('direct actress product sync failed for ' . $dmmId . ' (' . $name . '): ' . $e->getMessage());
        }
    }

    $coverageAfter = pca_product_coverage_count_linked_actresses();
    return [
        'processed_actresses' => $processed,
        'api_count' => $apiCount,
        'new_items' => $newItems,
        'saved_items' => $savedItems,
        'with_products' => $withProducts,
        'copied_relations' => $copiedRelations,
        'errors' => $errors,
        'coverage_before' => $coverageBefore,
        'coverage_after' => $coverageAfter,
    ];
}

/**
 * 女優情報APIで登録済みの通常女優に紐付かない既存作品を段階的に削除する。
 */
function pca_prune_unregistered_actress_items(int $limit = 500): int
{
    $limit = max(1, min(2000, $limit));
    $pdo = db();

    try {
        $stmt = $pdo->query(
            "SELECT i.id
             FROM items i
             WHERE NOT EXISTS (
                 SELECT 1
                 FROM item_actresses ia
                 INNER JOIN actresses a ON a.dmm_id = ia.dmm_id
                 WHERE ia.item_id = i.id
                   AND a.dmm_id REGEXP '^[0-9]+$'
             )
             ORDER BY i.id ASC
             LIMIT {$limit}"
        );
        $ids = array_values(array_filter(array_map('intval', $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [])));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $pdo->prepare("DELETE FROM items WHERE id IN ({$placeholders})");
        $delete->execute($ids);
        return $delete->rowCount();
    } catch (Throwable $e) {
        error_log('unregistered actress item prune failed: ' . $e->getMessage());
        return 0;
    }
}
