<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

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

/** @return array<int,array<string,mixed>> */
function pca_product_coverage_targets(int $limit): array
{
    $limit = max(1, min(10, $limit));
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
    if ($dmmId === '') return 0;

    try {
        $stmt = db()->prepare(
            "SELECT COUNT(DISTINCT i.id)
             FROM item_actresses ia
             INNER JOIN items i ON i.id=ia.item_id
             WHERE ia.dmm_id=:dmm_id
               AND i.floor_code='videoa'"
        );
        $stmt->execute([':dmm_id' => $dmmId]);
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
        ':actress_id' => $actressId,
        ':dmm_id' => $dmmId,
        ':item_count' => $itemCount,
        ':api_count' => $apiCount,
        ':last_error' => $error !== '' ? mb_substr($error, 0, 500) : null,
    ]);
}

/**
 * 1サイクル最大10女優を直接確認する。
 * 1女優あたり最大10作品なので、商品としては最大100作品を補完する。
 * 大量の同名ID補完や全件走査は行わない。
 */
function pca_sync_saved_actress_product_coverage(int $actressLimit = 10, int $itemsPerActress = 10): array
{
    $actressLimit = max(1, min(10, $actressLimit));
    $itemsPerActress = max(1, min(10, $itemsPerActress));
    pca_product_coverage_ensure_state_table();

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
        if ($actressId <= 0 || $dmmId === '' || $name === '') continue;

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
                    'sort' => 'date',
                    'article' => 'actress',
                    'article_id' => $dmmId,
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
        if ($itemCount > 0) $withProducts++;

        try {
            pca_product_coverage_save_state($actressId, $dmmId, $itemCount, $targetApiCount, $error);
        } catch (Throwable $e) {
            error_log('actress product sync state save failed: ' . $e->getMessage());
        }
    }

    $coverageAfter = pca_product_coverage_count_linked_actresses();

    return [
        'processed_actresses' => $processed,
        'api_count' => $apiCount,
        'new_items' => $newItems,
        'with_products' => $withProducts,
        'errors' => $errors,
        'coverage_before' => $coverageBefore,
        'coverage_after' => $coverageAfter,
        'message' => '通常女優' . $processed . '人の商品を確認し、API ' . $apiCount . '件・新規商品' . $newItems . '件を保存しました。',
    ];
}
