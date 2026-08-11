<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function pcf_home_rotation_cache_file(): string
{
    return dirname(__DIR__) . '/storage/cache/home-rotation.json';
}

function pcf_home_rotation_query(PDO $pdo, string $sql): array
{
    try {
        $stmt = $pdo->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable) {
        return [];
    }
}

function pcf_home_rotation_pick_sets(array $rows, int $perSet, int $seed): array
{
    $sets = [];
    for ($slot = 0; $slot < 5; $slot++) {
        $copy = $rows;
        mt_srand($seed + $slot);
        shuffle($copy);
        $sets[$slot] = array_slice($copy, 0, $perSet);
    }
    mt_srand();

    return $sets;
}

function pcf_home_rotation_window_sets(PDO $pdo, string $columns, string $table, string $where, int $perSet): array
{
    $whereSql = trim($where) !== '' ? ' WHERE ' . $where : '';
    $maxId = (int)($pdo->query('SELECT MAX(id) FROM ' . $table . $whereSql)?->fetchColumn() ?: 0);
    if ($maxId < 1) {
        return [];
    }

    $sets = [];
    for ($slot = 0; $slot < 5; $slot++) {
        $anchor = random_int(1, $maxId);
        $rows = pcf_home_rotation_query(
            $pdo,
            'SELECT ' . $columns . ' FROM ' . $table . $whereSql
            . ($whereSql === '' ? ' WHERE ' : ' AND ') . 'id>= ' . $anchor
            . ' ORDER BY id ASC LIMIT ' . $perSet
        );
        if (count($rows) < $perSet) {
            $rows = array_merge($rows, pcf_home_rotation_query(
                $pdo,
                'SELECT ' . $columns . ' FROM ' . $table . $whereSql
                . ' ORDER BY id ASC LIMIT ' . ($perSet - count($rows))
            ));
        }
        $sets[$slot] = array_slice($rows, 0, $perSet);
    }

    return $sets;
}

function pcf_home_rotation_refresh(?PDO $pdo = null): array
{
    $pdo ??= db();
    $seed = (int)floor(time() / 600);

    $actressSets = pcf_home_rotation_window_sets(
        $pdo,
        'id,name,image_small,image_large,image_url',
        'actresses',
        'COALESCE(NULLIF(image_small, ""),NULLIF(image_large, ""),NULLIF(image_url, "")) IS NOT NULL',
        15
    );
    $itemSets = pcf_home_rotation_window_sets(
        $pdo,
        'id,content_id,title,image_small,image_large,image_list,affiliate_url,
         sample_movie_url_720,sample_movie_url_644,sample_movie_url_560,sample_movie_url_476,
         release_date,created_at,updated_at',
        'items',
        'item_source="fanza_product" AND (release_date IS NULL OR release_date="" OR release_date<=CURDATE())',
        40
    );
    $genres = pcf_home_rotation_query(
        $pdo,
        'SELECT g.id,g.name,COUNT(ig.id) AS item_count
         FROM genres g INNER JOIN item_genres ig ON ig.genre_id=g.id
         GROUP BY g.id,g.name HAVING COUNT(ig.id)>0
         ORDER BY item_count DESC,g.id DESC LIMIT 120'
    );
    if ($genres === []) {
        $genres = pcf_home_rotation_query(
            $pdo,
            'SELECT g.id,g.name,COUNT(ig.id) AS item_count
             FROM genres g INNER JOIN item_genres ig ON ig.dmm_id=g.dmm_id
             GROUP BY g.id,g.name HAVING COUNT(ig.id)>0
             ORDER BY item_count DESC,g.id DESC LIMIT 120'
        );
    }

    $payload = [
        'generated_at' => date(DATE_ATOM),
        'items' => $itemSets,
        'actresses' => $actressSets,
        'genres' => pcf_home_rotation_pick_sets($genres, 3, $seed + 20),
    ];

    $directory = dirname(pcf_home_rotation_cache_file());
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return [];
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return [];
    }
    $temporary = pcf_home_rotation_cache_file() . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, pcf_home_rotation_cache_file())) {
        @unlink($temporary);
        return [];
    }

    return $payload;
}

function pcf_home_rotation_load(int $maxAgeSeconds = 900): array
{
    $file = pcf_home_rotation_cache_file();
    if (!is_file($file) || time() - (int)filemtime($file) > $maxAgeSeconds) {
        try {
            return pcf_home_rotation_refresh();
        } catch (Throwable) {
            return [];
        }
    }
    $decoded = json_decode((string)@file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function pcf_home_rotation_current_set(array $cache, string $key): array
{
    $sets = $cache[$key] ?? [];
    if (!is_array($sets) || $sets === []) {
        return [];
    }
    $slot = (int)(floor(time() / 600) % 5);
    $rows = $sets[$slot] ?? [];
    return is_array($rows) ? $rows : [];
}
