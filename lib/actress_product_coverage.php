<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/actress_catalog.php';

/**
 * 女優APIを主データにするPinkClub-Actressでは、videoa全体を100件ずつ巡回するだけでは
 * 保存済み女優の大半に商品が行き渡るまで非常に時間がかかる。
 *
 * そのため、商品未取得の保存済み女優を優先し、女優IDを article_id に指定して
 * 1サイクル最大100人を直接確認する。取得した商品は既存 DmmSyncService に保存させるため、
 * PinkClub-FANZA と同じ items / item_actresses / 商品カード描画経路をそのまま利用できる。
 */

function pca_product_coverage_ensure_state_table(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS actress_product_sync_state (
            actress_id INT UNSIGNED NOT NULL PRIMARY KEY,
            dmm_id VARCHAR(64) NOT NULL,
            checked_at DATETIME NOT NULL,
            last_item_count INT NOT NULL DEFAULT 0,
            last_api_count INT NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_actress_product_sync_checked (checked_at),
            CONSTRAINT fk_actress_product_sync_actress
                FOREIGN KEY (actress_id) REFERENCES actresses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function pca_product_coverage_name_sql(string $expression): string
{
    return "LOWER(REPLACE(REPLACE(TRIM({$expression}), ' ', ''), '　', ''))";
}

function pca_product_coverage_count_linked_actresses(): int
{
    try {
        return (int)db()->query(
            "SELECT COUNT(*)
             FROM actresses a
             WHERE a.dmm_id REGEXP '^[0-9]+$'
               AND TRIM(COALESCE(a.name,''))<>''
               AND EXISTS (
                   SELECT 1
                   FROM item_actresses ia
                   INNER JOIN items i ON i.id=ia.item_id
                   WHERE ia.dmm_id=a.dmm_id
                     AND i.floor_code='videoa'
               )"
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('actress product coverage count failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * 商品API側と女優API側で同一人物のDMM IDが異なる既存データを補完する。
 * このサイトでは通常女優一覧でも同名人物を1人へ統合しているため、同じ正規化名の
 * 数値DMM女優IDへ videoa の商品関係を補完するのが公開側の人物統合方針と一致する。
 */
function pca_product_coverage_repair_name_aliases(int $limit = 5000): int
{
    $limit = max(1, min(20000, $limit));
    $pdo = db();

    $actressName = pca_product_coverage_name_sql('a.name');
    $relationName = pca_product_coverage_name_sql('ia.actress_name');

    try {
        $sql = "INSERT INTO item_actresses(item_id,dmm_id,actress_name)
                SELECT DISTINCT ia.item_id,a.dmm_id,a.name
                FROM item_actresses ia
                INNER JOIN items i ON i.id=ia.item_id
                INNER JOIN actresses a ON {$actressName}={$relationName}
                WHERE i.floor_code='videoa'
                  AND a.dmm_id REGEXP '^[0-9]+$'
                  AND TRIM(COALESCE(a.name,''))<>''
                  AND TRIM(COALESCE(ia.actress_name,''))<>''
                  AND NOT EXISTS (
                      SELECT 1 FROM item_actresses existing
                      WHERE existing.item_id=ia.item_id
                        AND existing.dmm_id=a.dmm_id
                  )
                LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('actress product alias repair failed: ' . $e->getMessage());
        return 0;
    }
}

/** @return array<int,array<string,mixed>> */
function pca_product_coverage_targets(int $limit): array
{
    $limit = max(1, min(100, $limit));
    pca_product_coverage_ensure_state_table();

    try {
        $stmt = db()->prepare(
            "SELECT a.id,a.dmm_id,a.name,COALESCE(s.checked_at,'') AS checked_at
             FROM actresses a
             LEFT JOIN actress_product_sync_state s ON s.actress_id=a.id
             WHERE a.dmm_id REGEXP '^[0-9]+$'
               AND TRIM(COALESCE(a.name,''))<>''
               AND NOT EXISTS (
                   SELECT 1
                   FROM item_actresses ia
                   INNER JOIN items i ON i.id=ia.item_id
                   WHERE ia.dmm_id=a.dmm_id
                     AND i.floor_code='videoa'
               )
             ORDER BY
               CASE WHEN s.checked_at IS NULL THEN 0 ELSE 1 END ASC,
               s.checked_at ASC,
               a.name ASC,
               a.id ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('actress product coverage target fetch failed: ' . $e->getMessage());
        return [];
    }
}

function pca_product_coverage_count_for_dmm_id(string $dmmId): int
{
    $dmmId = trim($dmmId);
    if ($dmmId === '') {
        return 0;
    }

    try {
        $stmt = db()->prepare(
            "SELECT COUNT(DISTINCT i.id)
             FROM item_actresses ia
             INNER JOIN items i ON i.id=ia.item_id
             WHERE ia.dmm_id=:dmm_id
               AND i.floor_code='videoa'"
        );
        $stmt->execute([':dmm_id'=>$dmmId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('actress product count failed for ' . $dmmId . ': ' . $e->getMessage());
        return 0;
    }
}

function pca_product_coverage_save_state(int $actressId, string $dmmId, int $itemCount, int $apiCount, string $error = ''): void
{
    $stmt = db()->prepare(
        "INSERT INTO actress_product_sync_state(actress_id,dmm_id,checked_at,last_item_count,last_api_count,last_error,updated_at)
         VALUES(:actress_id,:dmm_id,NOW(),:item_count,:api_count,:last_error,NOW())
         ON DUPLICATE KEY UPDATE
             dmm_id=VALUES(dmm_id),
             checked_at=VALUES(checked_at),
             last_item_count=VALUES(last_item_count),
             last_api_count=VALUES(last_api_count),
             last_error=VALUES(last_error),
             updated_at=NOW()"
    );
    $stmt->execute([
        ':actress_id'=>$actressId,
        ':dmm_id'=>$dmmId,
        ':item_count'=>$itemCount,
        ':api_count'=>$apiCount,
        ':last_error'=>$error !== '' ? mb_substr($error,0,500) : null,
    ]);
}

/**
 * 保存済み通常女優を100人ずつ直接商品APIで確認する。
 * 未確認かつ商品0件の女優を最優先するので、同じ0件女優だけで処理が止まらず、
 * サイクルを重ねるごとに全女優へ商品カードの対象範囲が広がる。
 */
function pca_sync_saved_actress_product_coverage(int $actressLimit = 100, int $itemsPerActress = 1): array
{
    $actressLimit = max(1, min(100, $actressLimit));
    $itemsPerActress = max(1, min(100, $itemsPerActress));
    pca_product_coverage_ensure_state_table();

    // まず既存商品だけで救える同名ID違いを直す。これだけで商品カードが復活する女優もいる。
    $aliasesBefore = pca_product_coverage_repair_name_aliases(5000);
    $coverageBefore = pca_product_coverage_count_linked_actresses();
    $targets = pca_product_coverage_targets($actressLimit);

    $processed = 0;
    $apiCount = 0;
    $newItems = 0;
    $withProducts = 0;
    $errors = 0;
    $service = dmm_sync_service('items');

    foreach ($targets as $target) {
        $actressId = (int)($target['id'] ?? 0);
        $dmmId = trim((string)($target['dmm_id'] ?? ''));
        $name = trim((string)($target['name'] ?? ''));
        if ($actressId <= 0 || $dmmId === '' || $name === '') {
            continue;
        }

        $processed++;
        $targetApiCount = 0;
        $error = '';
        try {
            $result = $service->syncItemsBatch(
                'FANZA',
                'digital',
                'videoa',
                $itemsPerActress,
                1,
                [
                    'sort'=>'date',
                    'article'=>'actress',
                    'article_id'=>$dmmId,
                ]
            );
            $targetApiCount = (int)($result['api_count'] ?? 0);
            $apiCount += $targetApiCount;
            $newItems += (int)($result['new_count'] ?? 0);
        } catch (Throwable $e) {
            $errors++;
            $error = $e->getMessage();
            error_log('targeted actress product sync failed for ' . $dmmId . ' (' . $name . '): ' . $error);
        }

        $itemCount = pca_product_coverage_count_for_dmm_id($dmmId);
        if ($itemCount > 0) {
            $withProducts++;
        }
        try {
            pca_product_coverage_save_state($actressId,$dmmId,$itemCount,$targetApiCount,$error);
        } catch (Throwable $e) {
            error_log('actress product sync state save failed: ' . $e->getMessage());
        }
    }

    // 100人分の取得が終わった後に1回だけ同名ID差を補完する。ループ内で全件走査しない。
    $aliasesAfter = pca_product_coverage_repair_name_aliases(5000);
    $coverageAfter = pca_product_coverage_count_linked_actresses();

    return [
        'processed_actresses'=>$processed,
        'api_count'=>$apiCount,
        'new_items'=>$newItems,
        'with_products'=>$withProducts,
        'errors'=>$errors,
        'aliases_added'=>$aliasesBefore + $aliasesAfter,
        'coverage_before'=>$coverageBefore,
        'coverage_after'=>$coverageAfter,
        'message'=>'保存済み女優'.$processed.'人分の商品を確認し、API '.$apiCount.'件・新規商品'.$newItems.'件を保存しました。商品カード対象女優 '.$coverageBefore.'人→'.$coverageAfter.'人。',
    ];
}
