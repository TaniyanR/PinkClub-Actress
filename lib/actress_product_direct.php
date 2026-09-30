<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/actress_product_coverage.php';

/**
 * 女優APIで登録済みの女優だけを対象に、出演作品を少しずつ保存する。
 *
 * 1回の対象女優数と1女優あたりの取得件数を分けることで、
 * 共有サーバーでも外部API呼び出し回数と処理時間を抑える。
 */
function pca_direct_sync_product_batch(int $actressLimit = 5, int $hitsPerActress = 20): array
{
    $actressLimit = max(1, min(20, $actressLimit));
    $hitsPerActress = max(1, min(100, $hitsPerActress));
    pca_product_coverage_ensure_state_table();

    $coverageBefore = pca_product_coverage_count_linked_actresses();
    $targets = pca_product_coverage_targets($actressLimit);

    $processed = 0;
    $apiCount = 0;
    $savedItems = 0;
    $newItems = 0;
    $updatedItems = 0;
    $completed = 0;
    $errors = 0;

    $service = dmm_sync_service('items');

    foreach ($targets as $target) {
        $actressId = (int)($target['id'] ?? 0);
        $dmmId = trim((string)($target['dmm_id'] ?? ''));
        $name = trim((string)($target['name'] ?? ''));
        $offset = max(1, min(50000, (int)($target['next_offset'] ?? 1)));

        if ($actressId <= 0 || preg_match('/^[0-9]+$/', $dmmId) !== 1 || $name === '') {
            continue;
        }

        $processed++;
        try {
            $result = $service->syncItemsForRegisteredActress(
                $actressId,
                $dmmId,
                $name,
                $hitsPerActress,
                $offset
            );

            $targetApiCount = (int)($result['api_count'] ?? 0);
            $targetNextOffset = max(1, (int)($result['next_offset'] ?? 1));
            $targetComplete = (bool)($result['reached_end'] ?? false);

            $apiCount += $targetApiCount;
            $savedItems += (int)($result['saved_count'] ?? 0);
            $newItems += (int)($result['new_count'] ?? 0);
            $updatedItems += (int)($result['updated_count'] ?? 0);
            if ($targetComplete) {
                $completed++;
            }

            pca_product_coverage_save_state(
                $actressId,
                $dmmId,
                $targetNextOffset,
                $targetComplete,
                pca_product_coverage_count_for_dmm_id($dmmId),
                $targetApiCount,
                ''
            );
        } catch (Throwable $e) {
            $errors++;
            try {
                pca_product_coverage_save_state(
                    $actressId,
                    $dmmId,
                    $offset,
                    false,
                    pca_product_coverage_count_for_dmm_id($dmmId),
                    0,
                    $e->getMessage()
                );
            } catch (Throwable) {
            }
            error_log('registered actress product sync failed: ' . $e->getMessage());
        }
    }

    $coverageAfter = pca_product_coverage_count_linked_actresses();

    return [
        'processed_actresses' => $processed,
        'api_count' => $apiCount,
        'saved_items' => $savedItems,
        'new_items' => $newItems,
        'updated_items' => $updatedItems,
        'completed_actresses' => $completed,
        'errors' => $errors,
        'coverage_before' => $coverageBefore,
        'coverage_after' => $coverageAfter,
    ];
}
