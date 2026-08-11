<?php
declare(strict_types=1);

require_once __DIR__ . '/public/_bootstrap.php';
require_once __DIR__ . '/lib/repository.php';
require_once __DIR__ . '/lib/home_rotation_cache.php';

function redirect_canonical_home_url(): void
{
    $basePath = (string)(parse_url(BASE_URL, PHP_URL_PATH) ?: '');
    $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
    $homePath = $basePath . '/';
    $requestPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($requestPath === '') {
        $requestPath = '/';
    }

    $homePaths = [
        $homePath,
        $basePath . '/index.php',
        $basePath . '/index.com',
        $basePath . '/public/',
        $basePath . '/public/index.php',
    ];

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if (in_array($requestPath, $homePaths, true) && (!$isHttps || $requestPath !== $homePath)) {
        $canonicalUrl = rtrim(BASE_URL, '/') . '/';
        if (str_starts_with($canonicalUrl, 'http://')) {
            $canonicalUrl = 'https://' . substr($canonicalUrl, 7);
        }
        header('Location: ' . $canonicalUrl, true, 301);
        exit;
    }
}

redirect_canonical_home_url();

function seeded_shuffle(array $rows, int $seed): array
{
    $count = count($rows);
    if ($count <= 1) {
        return $rows;
    }

    mt_srand($seed);
    for ($i = $count - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
    }
    return $rows;
}

function pick_random_items(array $rows, int $seed, int $limit = 15): array
{
    $rows = seeded_shuffle($rows, $seed);
    return array_slice($rows, 0, $limit);
}


function take_unique_items_for_home(array $items, array &$usedKeys, int $limit): array
{
    $limit = max(1, $limit);
    $result = [];

    foreach (dedupe_items_by_key($items) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $contentId = strtolower(trim((string)($item['content_id'] ?? '')));
        $productId = strtolower(trim((string)($item['product_id'] ?? '')));
        $id = trim((string)($item['id'] ?? ''));
        $key = $contentId !== '' ? 'content_id:' . $contentId : ($productId !== '' ? 'product_id:' . $productId : ($id !== '' ? 'id:' . $id : ''));

        if ($key !== '' && isset($usedKeys[$key])) {
            continue;
        }
        if ($key !== '') {
            $usedKeys[$key] = true;
        }

        $result[] = $item;
        if (count($result) >= $limit) {
            break;
        }
    }

    return $result;
}

function decode_item_raw(array $item): array
{
    $raw = [];
    if (is_string($item['raw_json'] ?? null) && $item['raw_json'] !== '') {
        $decoded = json_decode((string)$item['raw_json'], true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }
    return $raw;
}

function normalize_movie_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }

    return '';
}


function normalize_index_image_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }

    return '';
}

function actress_index_image(array $actress): string
{
    foreach (['image_small', 'image_large', 'image_url'] as $key) {
        $candidate = normalize_index_image_url((string)($actress[$key] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function parse_index_image_urls(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $trimmed = trim($value);
    if ($trimmed !== '' && $trimmed[0] === '[') {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded)));
        }
    }

    $parts = preg_split('/[\r\n,|\s]+/', $value);
    if (!is_array($parts)) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
}

function collect_movie_urls_from_value(mixed $value, array &$urls): void
{
    if (is_string($value)) {
        $candidate = normalize_movie_url($value);
        if ($candidate !== '') {
            $urls[] = $candidate;
        }
        return;
    }

    if (!is_array($value)) {
        return;
    }

    foreach ($value as $child) {
        collect_movie_urls_from_value($child, $urls);
    }
}

function pick_sample_movie_urls_from_raw(array $raw): array
{
    $urls = [];
    foreach (['sampleMovieURL', 'sample_movie_url', 'sampleMovieUrl'] as $movieKeyName) {
        $rawMovie = $raw[$movieKeyName] ?? null;

        if (is_string($rawMovie)) {
            $candidate = normalize_movie_url($rawMovie);
            if ($candidate !== '') {
                $urls[] = $candidate;
            }
        }

        if (is_array($rawMovie)) {
            foreach (['size_720_480', 'size_644_414', 'size_560_360', 'size_476_306'] as $movieKey) {
                $candidate = normalize_movie_url((string)($rawMovie[$movieKey] ?? ''));
                if ($candidate !== '') {
                    $urls[] = $candidate;
                }
            }

            collect_movie_urls_from_value($rawMovie, $urls);
        }
    }

    return array_values(array_unique(array_filter(array_map(static fn($u) => trim((string)$u), $urls))));
}

function query_all_safe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : $key;
            if (is_int($value)) {
                $stmt->bindValue($param, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, (string)$value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('public/index.php query failed: ' . $e->getMessage());
        return [];
    }
}


function home_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];

    if (!in_array($table, ['item_genres', 'item_series', 'item_makers'], true)) {
        return false;
    }
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column');
        $stmt->execute([':column' => $column]);
        $cache[$cacheKey] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        return $cache[$cacheKey];
    } catch (Throwable) {
        $cache[$cacheKey] = false;
        return false;
    }
}

function fetch_items_with_order_fallback(PDO $pdo, array $orderByCandidates, int $limit): array
{
    $limit = max(1, min(300, $limit));
    $sourceWhere = items_product_source_where();
    $sourceWhereSql = $sourceWhere !== '' ? ' WHERE ' . $sourceWhere : '';

    foreach ($orderByCandidates as $orderBy) {
        $rows = query_all_safe($pdo, 'SELECT * FROM items' . $sourceWhereSql . ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

function item_sample_state(array $item): array
{
    $raw = decode_item_raw($item);
    $movieUrls = [];
    foreach (['sample_movie_url_720', 'sample_movie_url_644', 'sample_movie_url_560', 'sample_movie_url_476'] as $column) {
        $candidate = trim((string)($item[$column] ?? ''));
        if ($candidate !== '') {
            $movieUrls[] = $candidate;
        }
    }

    $movieUrls = array_values(array_unique(array_merge($movieUrls, pick_sample_movie_urls_from_raw($raw))));
    $firstMovieUrl = $movieUrls[0] ?? '';

    $hasImageSample = false;
    $sampleImageUrl = $raw['sampleImageURL'] ?? null;
    if (is_array($sampleImageUrl)) {
        foreach (['sample_l', 'sample_s'] as $sampleKey) {
            $images = $sampleImageUrl[$sampleKey]['image'] ?? null;
            if (is_array($images)) {
                foreach ($images as $image) {
                    if (trim((string)$image) !== '') {
                        $hasImageSample = true;
                        break 2;
                    }
                }
            }
        }
    }

    if (!$hasImageSample) {
        foreach (parse_index_image_urls((string)($item['image_list'] ?? '')) as $image) {
            if (trim((string)$image) !== '') {
                $hasImageSample = true;
                break;
            }
        }
    }

    return ['movie_url' => $firstMovieUrl, 'movie_urls' => $movieUrls, 'has_images' => $hasImageSample];
}

function pick_full_package_image(array $item): string
{
    foreach (['image_large', 'image_list', 'image_small'] as $key) {
        if ($key === 'image_list') {
            foreach (parse_index_image_urls((string)($item['image_list'] ?? '')) as $image) {
                $candidate = trim((string)$image);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
            continue;
        }
        $candidate = trim((string)($item[$key] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function render_item_card(array $item, int $width = 180, ?array $taxonomy = null, bool $preferFullPackageImage = false, bool $lazyLoad = true): void
{
    $itemUrl = app_url('public/item.php?id=' . (int)$item['id']);
    $title = (string)($item['title'] ?? '');
    $sample = item_sample_state($item);
    $movieClass = $sample['movie_url'] !== '' ? 'sample-button sample-button--enabled' : 'sample-button sample-button--disabled';
    $imageClass = $sample['has_images'] ? 'sample-button sample-button--enabled' : 'sample-button sample-button--disabled';
    $sampleImagesUrl = public_url('sample_images.php?content_id=' . rawurlencode((string)($item['content_id'] ?? '')));
    $thumbUrl = trim((string)($item['image_small'] ?? ''));
    if ($preferFullPackageImage) {
        $fullPackageImage = pick_full_package_image($item);
        if ($fullPackageImage !== '') {
            $thumbUrl = $fullPackageImage;
        }
    }
    if ($thumbUrl === '') {
        $thumbUrl = trim((string)($item['image_large'] ?? ''));
    }
    ?>
    <article class="card rail-card rail-card--<?= (int)$width ?>" style="width:<?= (int)$width ?>px;min-width:<?= (int)$width ?>px;max-width:<?= (int)$width ?>px;">
      <?php if ($thumbUrl !== ''): ?>
        <a href="<?= e($itemUrl) ?>"><img class="thumb" src="<?= e($thumbUrl) ?>" alt="<?= e($title) ?>"<?= $lazyLoad ? ' loading="lazy"' : '' ?> decoding="async" style="width:<?= (int)$width ?>px;max-width:<?= (int)$width ?>px;"></a>
      <?php else: ?>
        <div class="rail-card__noimage" style="width:<?= (int)$width ?>px;height:<?= (int)$width ?>px;">画像なし</div>
      <?php endif; ?>
      <a class="rail-card__title" href="<?= e($itemUrl) ?>"><?= e($title) ?></a>
      <div class="sample-buttons">
        <?php $releaseDateRaw = trim((string)($item['release_date'] ?? '')); ?>
        <span style="display:block;width:100%;padding:12px 10px;text-align:center;color:#000;background:transparent;border:1px solid #000;border-radius:4px;font-size:14px;font-weight:700;box-sizing:border-box;"><?= $releaseDateRaw !== '' ? '発売日：' . e(format_date($releaseDateRaw)) : '発売日' ?></span>
        <button type="button" class="<?= e($movieClass) ?> sample-movie-trigger" <?= $sample['movie_url'] === '' ? 'disabled' : '' ?> data-movie-url="<?= e((string)$sample['movie_url']) ?>" data-movie-title="<?= e($title) ?>">サンプル動画</button>
        <button type="button" class="<?= e($imageClass) ?>" <?= !$sample['has_images'] ? 'disabled' : '' ?> onclick="<?= $sample['has_images'] ? "window.open('" . e($sampleImagesUrl) . "','_blank','noopener,noreferrer,width=760,height=540');" : 'return false;' ?>">サンプル画像</button>
      </div>
    </article>
    <?php
}

function safe_include_partial(string $filePath): void
{
    try {
        include $filePath;
    } catch (Throwable $e) {
        error_log('public/index.php include failed: ' . $filePath . ' ' . $e->getMessage());
    }
}

function safe_render_home_ad(string $positionKey): void
{
    try {
        if (!function_exists('get_ad_code') || !function_exists('render_ad')) {
            return;
        }

        if (get_ad_code($positionKey) === null) {
            return;
        }

        echo '<div class="site-ad">';
        render_ad($positionKey, 'home', 'pc');
        echo '</div>';
    } catch (Throwable $e) {
        error_log('public/index.php ad render failed: ' . $positionKey . ' ' . $e->getMessage());
    }
}

$title = 'トップ';
$itemCount = 0;

$newReleaseTop = $newReleaseBottom = $latestTop = $latestBottom = $pickupTop = $pickupBottom = [];
$fallbackItems = [];
$actresses = [];
$genreRows = [];
$seriesSection = ['id' => 0, 'name' => '', 'items' => []];
$makerSection = ['id' => 0, 'name' => '', 'items' => []];
$authorSection = ['name' => '', 'url' => '', 'items' => []];

try {
    $pdo = db();
    $homeRotationCache = pcf_home_rotation_load();
    $sourceWhere = items_product_source_where();
    $sourceWhereSql = $sourceWhere !== '' ? ' WHERE ' . $sourceWhere : '';
    $itemExistsStmt = $pdo->query('SELECT 1 FROM items' . $sourceWhereSql . ' LIMIT 1');
    $itemCount = ($itemExistsStmt && $itemExistsStmt->fetchColumn()) ? 1 : 0;

    if ($itemCount > 0) {
        $seedBase = intdiv(time(), 1800);
        $usedHomeItemKeys = [];

        $newReleaseRows = fetch_items_with_order_fallback($pdo, [
            'release_date DESC, id ASC',
        ], 40);
        $usedNewReleaseItemKeys = [];
        $newReleaseRows = take_unique_items_for_home($newReleaseRows, $usedNewReleaseItemKeys, 20);
        $newReleaseTop = array_slice($newReleaseRows, 0, 5);
        $newReleaseBottom = array_slice($newReleaseRows, 5, 15);

        $latestRows = fetch_items_with_order_fallback($pdo, [
            'release_date DESC, updated_at DESC, id DESC',
            'date_published DESC, updated_at DESC, id DESC',
            'updated_at DESC, id DESC',
            'id DESC',
        ], 40);
        $latestRows = take_unique_items_for_home($latestRows, $usedHomeItemKeys, 20);
        $latestTop = array_slice($latestRows, 0, 5);
        $latestBottom = array_slice($latestRows, 5, 15);
        $fallbackItems = array_slice($latestRows, 0, 12);

        $popularWhereSql = $sourceWhereSql !== '' ? $sourceWhereSql . ' AND view_count > 0' : ' WHERE view_count > 0';
        $popularRows = query_all_safe($pdo, 'SELECT * FROM items' . $popularWhereSql . ' ORDER BY view_count DESC, release_date DESC, id DESC LIMIT 40');
        $popularRows = take_unique_items_for_home($popularRows, $usedHomeItemKeys, 20);
        if (count($popularRows) < 20) {
            $randomRows = pcf_home_rotation_current_set($homeRotationCache, 'items');
            if ($randomRows === []) {
                $randomRows = fetch_items_with_order_fallback($pdo, ['created_at DESC,id DESC'], 40);
            }
            $popularRows = array_merge($popularRows, take_unique_items_for_home($randomRows, $usedHomeItemKeys, 20 - count($popularRows)));
        }
        $pickupTop = array_slice($popularRows, 0, 5);
        $pickupBottom = array_slice($popularRows, 5, 15);

        if (db_table_exists($pdo, 'actresses')) {
            $actresses = pcf_home_rotation_current_set($homeRotationCache, 'actresses');
            if ($actresses === []) {
                $actressCandidates = $pdo->query('SELECT id,name,image_small,image_large,image_url FROM actresses ORDER BY updated_at DESC,id DESC LIMIT 30')->fetchAll();
                $actresses = array_slice($actressCandidates ?: [], 0, 15);
            }
        }

        if (db_table_exists($pdo, 'genres') && db_table_exists($pdo, 'item_genres')) {
            $genreCandidates = pcf_home_rotation_current_set($homeRotationCache, 'genres');
            if ($genreCandidates === []) {
                $genreCandidates = query_all_safe($pdo, 'SELECT g.id,g.name,COUNT(ig.id) AS item_count FROM genres g INNER JOIN item_genres ig ON ig.genre_id = g.id GROUP BY g.id,g.name HAVING COUNT(ig.id) > 0 ORDER BY item_count DESC,g.id DESC LIMIT 3');
                if ($genreCandidates === []) {
                    $genreCandidates = query_all_safe($pdo, 'SELECT g.id,g.name,COUNT(*) AS item_count FROM genres g INNER JOIN item_genres ig ON ig.dmm_id = g.dmm_id GROUP BY g.id,g.name HAVING COUNT(*) > 0 ORDER BY item_count DESC,g.id DESC LIMIT 3');
                }
            }
            foreach (array_slice($genreCandidates, 0, 3) as $index => $genre) {
                $genreItems = [];
                foreach ([
                    'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at FROM items i INNER JOIN item_genres ig ON ig.item_id = i.id INNER JOIN genres g ON g.dmm_id = ig.dmm_id WHERE g.id = :id ORDER BY i.view_count DESC, i.release_date DESC, i.updated_at DESC, i.id DESC LIMIT 120',
                    'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at FROM items i INNER JOIN item_genres ig ON ig.item_id = i.id INNER JOIN genres g ON g.dmm_id = ig.dmm_id WHERE g.id = :id ORDER BY i.release_date DESC, i.updated_at DESC, i.id DESC LIMIT 120',
                ] as $genreSql) {
                    $genreItems = query_all_safe($pdo, $genreSql, [':id' => (int)$genre['id']]);
                    if ($genreItems !== []) {
                        break;
                    }
                }
                if ($genreItems === [] && home_column_exists($pdo, 'item_genres', 'content_id') && home_column_exists($pdo, 'item_genres', 'genre_id')) {
                    foreach ([
                        'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at FROM items i INNER JOIN item_genres ig ON ig.content_id = i.content_id WHERE ig.genre_id = :id ORDER BY i.view_count DESC, i.release_date DESC, i.updated_at DESC, i.id DESC LIMIT 120',
                        'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at FROM items i INNER JOIN item_genres ig ON ig.content_id = i.content_id WHERE ig.genre_id = :id ORDER BY i.release_date DESC, i.updated_at DESC, i.id DESC LIMIT 120',
                    ] as $genreSql) {
                        $genreItems = query_all_safe($pdo, $genreSql, [':id' => (int)$genre['id']]);
                        if ($genreItems !== []) {
                            break;
                        }
                    }
                }
                $genrePool = pick_random_items($genreItems, $seedBase + 30 + $index, 120);
                $genreItems = take_unique_items_for_home($genrePool, $usedHomeItemKeys, 15);
                if ($genreItems === []) {
                    $genreItems = array_slice(dedupe_items_by_key($genrePool), 0, 15);
                }
                if ($genreItems !== []) {
                    $genreRows[] = ['id' => (int)$genre['id'], 'name' => (string)$genre['name'], 'items' => $genreItems];
                }
            }
        }

        if (db_table_exists($pdo, 'item_series') && (db_table_exists($pdo, 'series') || db_table_exists($pdo, 'series_master'))) {
            $seriesCandidates = [];
            if (db_table_exists($pdo, 'series')) {
                $seriesCandidates = query_all_safe($pdo, 'SELECT s.id,s.name,COUNT(isr.id) AS item_count FROM series s INNER JOIN item_series isr ON isr.series_id = s.id GROUP BY s.id,s.name HAVING COUNT(isr.id) > 0 ORDER BY item_count DESC,s.id DESC LIMIT 120');
            }
            if ($seriesCandidates === [] && db_table_exists($pdo, 'series_master')) {
                $seriesCandidates = query_all_safe($pdo, 'SELECT s.id,s.name,COUNT(isr.id) AS item_count FROM series_master s INNER JOIN item_series isr ON isr.dmm_id = s.dmm_id GROUP BY s.id,s.name HAVING COUNT(isr.id) > 0 ORDER BY item_count DESC,s.id DESC LIMIT 120');
            }
            if ($seriesCandidates !== []) {
                $seriesCandidates = seeded_shuffle($seriesCandidates, $seedBase + 40);
                $picked = $seriesCandidates[0];
                $seriesItems = query_all_safe($pdo, 'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at FROM items i INNER JOIN item_series isr ON isr.item_id = i.id INNER JOIN series_master s ON s.dmm_id = isr.dmm_id WHERE s.id = :id ORDER BY i.release_date DESC, i.updated_at DESC, i.id DESC LIMIT 120', [':id' => (int)$picked['id']]);
                if ($seriesItems === [] && home_column_exists($pdo, 'item_series', 'content_id') && home_column_exists($pdo, 'item_series', 'series_id')) {
                    $seriesItems = query_all_safe($pdo, 'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at FROM items i INNER JOIN item_series isr ON isr.content_id = i.content_id WHERE isr.series_id = :id ORDER BY i.release_date DESC, i.updated_at DESC, i.id DESC LIMIT 120', [':id' => (int)$picked['id']]);
                }
                $seriesPool = pick_random_items($seriesItems, $seedBase + 41, 120);
                $seriesItems = take_unique_items_for_home($seriesPool, $usedHomeItemKeys, 15);
                if ($seriesItems === []) {
                    $seriesItems = array_slice(dedupe_items_by_key($seriesPool), 0, 15);
                }
                if ($seriesItems !== []) {
                    $seriesSection = [
                        'id' => (int)$picked['id'],
                        'name' => (string)$picked['name'],
                        'items' => $seriesItems,
                    ];
                }
            }
        }



        if (db_table_exists($pdo, 'makers') && db_table_exists($pdo, 'item_makers')) {
            $makerCandidates = query_all_safe($pdo, 'SELECT m.id,m.name,COUNT(im.id) AS item_count FROM makers m INNER JOIN item_makers im ON im.maker_id = m.id GROUP BY m.id,m.name HAVING COUNT(im.id) > 0 ORDER BY item_count DESC,m.id DESC LIMIT 120');
            if ($makerCandidates === []) {
                $makerCandidates = query_all_safe($pdo, 'SELECT m.id,m.name,COUNT(im.id) AS item_count FROM makers m INNER JOIN item_makers im ON im.dmm_id = m.dmm_id GROUP BY m.id,m.name HAVING COUNT(im.id) > 0 ORDER BY item_count DESC,m.id DESC LIMIT 120');
            }
            if ($makerCandidates !== []) {
                $makerCandidates = seeded_shuffle($makerCandidates, $seedBase + 50);
                $picked = $makerCandidates[0];
                $makerItems = query_all_safe($pdo, 'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at FROM items i INNER JOIN item_makers im ON im.item_id = i.id INNER JOIN makers m ON m.dmm_id = im.dmm_id WHERE m.id = :id ORDER BY i.release_date DESC, i.updated_at DESC, i.id DESC LIMIT 120', [':id' => (int)$picked['id']]);
                if ($makerItems === [] && home_column_exists($pdo, 'item_makers', 'content_id') && home_column_exists($pdo, 'item_makers', 'maker_id')) {
                    $makerItems = query_all_safe($pdo, 'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at FROM items i INNER JOIN item_makers im ON im.content_id = i.content_id WHERE im.maker_id = :id ORDER BY i.release_date DESC, i.updated_at DESC, i.id DESC LIMIT 120', [':id' => (int)$picked['id']]);
                }
                $makerPool = pick_random_items($makerItems, $seedBase + 51, 120);
                $makerItems = take_unique_items_for_home($makerPool, $usedHomeItemKeys, 15);
                if ($makerItems === []) {
                    $makerItems = array_slice(dedupe_items_by_key($makerPool), 0, 15);
                }
                if ($makerItems !== []) {
                    $makerSection = [
                        'id' => (int)$picked['id'],
                        'name' => (string)$picked['name'],
                        'items' => $makerItems,
                    ];
                }
            }
        }



        if (db_table_exists($pdo, 'authors') && db_table_exists($pdo, 'item_authors')) {
            $authorCandidates = $pdo->query('SELECT a.id,a.name,COUNT(ia.id) AS item_count FROM authors a INNER JOIN item_authors ia ON ia.dmm_id = a.dmm_id GROUP BY a.id,a.name HAVING COUNT(ia.id) > 0 ORDER BY item_count DESC,a.id DESC LIMIT 120')->fetchAll();
            if ($authorCandidates !== []) {
                $authorCandidates = seeded_shuffle($authorCandidates, $seedBase + 60);
                $picked = $authorCandidates[0];
                $stmt = $pdo->prepare(
                    'SELECT i.id,i.content_id,i.title,i.image_small,i.image_large,i.image_list,i.raw_json,i.affiliate_url,i.sample_movie_url_720,i.sample_movie_url_644,i.sample_movie_url_560,i.sample_movie_url_476,i.release_date,i.updated_at
                     FROM items i
                     INNER JOIN item_authors ia ON ia.item_id = i.id
                     INNER JOIN authors a ON a.dmm_id = ia.dmm_id
                     WHERE a.id = :id
                     ORDER BY i.release_date DESC, i.updated_at DESC, i.id DESC
                     LIMIT 120'
                );
                $stmt->execute([':id' => (int)$picked['id']]);
                $authorPool = pick_random_items($stmt->fetchAll() ?: [], $seedBase + 61, 120);
                $authorItems = take_unique_items_for_home($authorPool, $usedHomeItemKeys, 15);
                if ($authorItems === []) {
                    $authorItems = array_slice(dedupe_items_by_key($authorPool), 0, 15);
                }
                $authorSection = [
                    'name' => (string)$picked['name'],
                    'url' => app_url('public/author.php?id=' . (int)$picked['id']),
                    'items' => $authorItems,
                ];
            }
        }
    }
} catch (Throwable $e) {
    error_log('public/index.php load failed: ' . $e->getMessage());
}

$title = 'トップ';
$pageDescription = function_exists('setting_site_tagline') ? setting_site_tagline('') : '';
$canonicalUrl = public_url('index.php');
require __DIR__ . '/public/partials/header.php';
$hasHomeContent = $newReleaseTop !== []
    || $newReleaseBottom !== []
    || $latestTop !== []
    || $latestBottom !== []
    || $pickupTop !== []
    || $pickupBottom !== []
    || $actresses !== []
    || $genreRows !== []
    || $seriesSection['items'] !== []
    || $makerSection['items'] !== []
    || $authorSection['items'] !== [];
?>

<?php if ($itemCount === 0): ?>
  <div class="card"><p>まだ商品データが同期されていません。管理画面のAPI設定から「同期実行（DB保存）」を行ってください。</p></div>
<?php elseif (!$hasHomeContent): ?>
  <div class="card">
    <h2>表示できる本文データがまだありません</h2>
    <p>商品データは存在しますが、トップページに表示するセクションを組み立てられませんでした。下の作品一覧から確認してください。</p>
    <p><a class="button button--primary" href="<?= e(public_url('items.php')) ?>">商品一覧を見る</a></p>
  </div>
  <?php if ($fallbackItems !== []): ?>
    <section class="rail-section">
      <h2>取得できた作品</h2>
      <div class="rail-row rail-row--180"><?php foreach ($fallbackItems as $item) { render_item_card($item, 180); } ?></div>
    </section>
  <?php endif; ?>
<?php else: ?>
  <section class="rail-section only-pc home-feature-section">
    <h2>新作作品</h2>
    <div class="rail-row rail-row--210 rail-row--no-scroll rail-row--top-shift rail-row--between-gap"><?php foreach ($newReleaseTop as $item) { render_item_card($item, 210, null, false, false); } ?></div>
    <div class="rail-row rail-row--200 rail-row--wide-thumb rail-row--bottom-scroll rail-row--bottom-horizontal rail-row--home-taxonomy"><?php foreach ($newReleaseBottom as $item) { render_item_card($item, 200, null, true); } ?></div>
  </section>
  <section class="rail-section only-sp">
    <h2>新作作品</h2>
    <div class="rail-row rail-row--210 rail-row--no-scroll rail-row--top-shift"><?php foreach ($newReleaseTop as $item) { render_item_card($item, 210, null, true, false); } ?></div>
  </section>

  <section class="rail-section only-pc home-feature-section">
    <h2>新着作品</h2>
    <div class="rail-row rail-row--210 rail-row--no-scroll rail-row--top-shift rail-row--between-gap"><?php foreach ($latestTop as $item) { render_item_card($item, 210, null, false, false); } ?></div>
    <div class="rail-row rail-row--200 rail-row--wide-thumb rail-row--bottom-scroll rail-row--bottom-horizontal rail-row--home-taxonomy"><?php foreach ($latestBottom as $item) { render_item_card($item, 200, null, true); } ?></div>
  </section>
  <section class="rail-section only-sp">
    <h2>新着作品</h2>
    <div class="rail-row rail-row--210 rail-row--no-scroll rail-row--top-shift"><?php foreach ($latestTop as $item) { render_item_card($item, 210, null, true, false); } ?></div>
  </section>

  <section class="rail-section only-pc home-feature-section">
    <h2>ピックアップ</h2>
    <div class="rail-row rail-row--210 rail-row--no-scroll rail-row--top-shift rail-row--between-gap"><?php foreach ($pickupTop as $item) { render_item_card($item, 210, null, false, false); } ?></div>
    <div class="rail-row rail-row--200 rail-row--wide-thumb rail-row--bottom-scroll rail-row--bottom-horizontal rail-row--home-taxonomy"><?php foreach ($pickupBottom as $item) { render_item_card($item, 200, null, true); } ?></div>
  </section>
  <section class="rail-section only-sp">
    <h2>ピックアップ</h2>
    <div class="rail-row rail-row--210 rail-row--no-scroll rail-row--top-shift"><?php foreach ($pickupTop as $item) { render_item_card($item, 210, null, true, false); } ?></div>
  </section>

  <section class="rail-section">
    <h2>女優</h2>
    <div class="rail-row rail-row--180 rail-row--home-actresses">
      <?php foreach ($actresses as $actress): ?>
        <?php $actressImage = actress_index_image(is_array($actress) ? $actress : []); ?>
        <article class="card rail-card rail-card--180">
          <?php if ($actressImage !== ''): ?><img class="thumb" src="<?= e($actressImage) ?>" alt="<?= e((string)$actress['name']) ?>"><?php else: ?><div class="rail-card__noimage" style="width:180px;height:180px;">画像なし</div><?php endif; ?>
          <a class="rail-card__title" href="<?= e(app_url('public/actress.php?id=' . (int)$actress['id'])) ?>"><?= e((string)$actress['name']) ?></a>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="rail-section home-genre-section">
    <h2>ジャンル</h2>
    <?php foreach ($genreRows as $genre): ?>
      <h3><a href="<?= e(app_url('public/genre.php?id=' . (int)$genre['id'])) ?>"><?= e((string)$genre['name']) ?></a></h3>
      <div class="rail-row rail-row--200 rail-row--wide-thumb rail-row--bottom-scroll rail-row--bottom-horizontal rail-row--home-taxonomy">
        <?php foreach ($genre['items'] as $item) { render_item_card($item, 200, ['name' => (string)$genre['name'], 'url' => app_url('public/genre.php?id=' . (int)$genre['id'])], true); } ?>
      </div>
    <?php endforeach; ?>
  </section>
  <?php if (!empty($seriesSection['items'])): ?>
  <section class="rail-section">
    <h2>シリーズ<?= $seriesSection['name'] !== '' ? '：<a href="' . e(app_url('public/series_one.php?id=' . (int)$seriesSection['id'])) . '">' . e($seriesSection['name']) . '</a>' : '' ?></h2>
    <div class="rail-row rail-row--200 rail-row--wide-thumb rail-row--bottom-scroll rail-row--bottom-horizontal rail-row--home-taxonomy">
      <?php foreach ($seriesSection['items'] as $item) { render_item_card($item, 200, ['name' => (string)$seriesSection['name'], 'url' => app_url('public/series_one.php?id=' . (int)$seriesSection['id'])], true); } ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($makerSection['items'])): ?>
  <section class="rail-section">
    <h2>メーカー<?= $makerSection['name'] !== '' ? '：<a href="' . e(app_url('public/maker.php?id=' . (int)$makerSection['id'])) . '">' . e($makerSection['name']) . '</a>' : '' ?></h2>
    <div class="rail-row rail-row--200 rail-row--wide-thumb rail-row--bottom-scroll rail-row--bottom-horizontal rail-row--home-taxonomy">
      <?php foreach ($makerSection['items'] as $item) { render_item_card($item, 200, ['name' => (string)$makerSection['name'], 'url' => app_url('public/maker.php?id=' . (int)$makerSection['id'])], true); } ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($authorSection['items'])): ?>
  <section class="rail-section">
    <h2>作者<?= $authorSection['name'] !== '' ? '：' . e($authorSection['name']) : '' ?></h2>
    <div class="rail-row rail-row--180">
      <?php foreach ($authorSection['items'] as $item) { render_item_card($item, 180, ['name' => (string)$authorSection['name'], 'url' => (string)$authorSection['url']]); } ?>
    </div>
  </section>
  <?php endif; ?>
<?php endif; ?>


<div id="sample-movie-modal" class="sample-movie-modal" aria-hidden="true">
  <div class="sample-movie-modal__overlay" data-movie-close="1"></div>
  <div class="sample-movie-modal__dialog" role="dialog" aria-modal="true" aria-label="サンプル動画プレイヤー">
    <button type="button" class="sample-movie-modal__close" data-movie-close="1" aria-label="閉じる">×</button>
    <div id="sample-movie-title" class="sample-movie-modal__title">サンプル動画</div>
    <div class="sample-movie-modal__frame-wrap">
      <iframe id="sample-movie-frame" class="sample-movie-modal__frame" src="about:blank" allow="autoplay; fullscreen" referrerpolicy="no-referrer"></iframe>
    </div>
  </div>
</div>
<script>
(() => {
  const modal = document.getElementById('sample-movie-modal');
  const frame = document.getElementById('sample-movie-frame');
  const titleNode = document.getElementById('sample-movie-title');
  if (!modal || !frame || !titleNode) return;

  const openMovie = (url, title) => {
    if (!url) return;
    const normalizedTitle = String(title || '').trim();
    titleNode.textContent = normalizedTitle !== '' ? normalizedTitle : 'サンプル動画';
    modal.style.setProperty('--movie-modal-width', '900px');
    frame.src = url;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  };

  const closeMovie = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    frame.src = 'about:blank';
    modal.style.removeProperty('--movie-modal-width');
    titleNode.textContent = 'サンプル動画';
  };

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('.sample-movie-trigger');
    if (trigger && !trigger.disabled) {
      event.preventDefault();
      const card = trigger.closest('.rail-card');
      const fallbackTitle = card ? (card.querySelector('.rail-card__title')?.textContent || '') : '';
      openMovie(trigger.dataset.movieUrl || '', trigger.dataset.movieTitle || fallbackTitle);
      return;
    }

    if (event.target.closest('[data-movie-close="1"]')) {
      event.preventDefault();
      closeMovie();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeMovie();
    }
  });
})();
</script>
<?php require __DIR__ . '/public/partials/footer.php'; ?>
