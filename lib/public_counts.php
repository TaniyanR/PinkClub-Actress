<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/actress_directory_cache.php';

/**
 * 公開ページに実際に表示できる商品数・女優数を返す。
 *
 * @return array{posts:?int,actresses:?int}
 */
function pcf_public_counts(): array
{
    static $counts = null;
    if (is_array($counts)) {
        return $counts;
    }

    $counts = ['posts' => null, 'actresses' => null];

    try {
        if (db_table_exists('items')) {
            $where = items_product_source_where('items');
            $stmt = db()->query('SELECT COUNT(*) FROM items WHERE ' . $where);
            $counts['posts'] = $stmt ? (int)$stmt->fetchColumn() : null;
        }
    } catch (Throwable $e) {
        $counts['posts'] = null;
    }

    try {
        $manifest = pcf_actress_directory_cache_read_manifest();
        if (!is_array($manifest) || ($manifest['groups'] ?? []) === []) {
            $manifest = pcf_actress_directory_cache_rebuild(true);
        }

        $actressCount = pcf_actress_directory_cache_count($manifest);
        if ($actressCount === null) {
            $manifest = pcf_actress_directory_cache_rebuild(true);
            $actressCount = pcf_actress_directory_cache_count($manifest);
        }
        $counts['actresses'] = $actressCount;
    } catch (Throwable $e) {
        $counts['actresses'] = null;
    }

    return $counts;
}
