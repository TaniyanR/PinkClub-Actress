<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/partials/public_ui.php';
require_once __DIR__ . '/../lib/repository.php';

function search_item_has_product_source(array $item): bool
{
    if (array_key_exists('item_source', $item)) {
        return (string)($item['item_source'] ?? '') === 'fanza_product';
    }

    foreach (['affiliate_url', 'service_code', 'floor_code', 'sample_movie_url_476', 'sample_movie_url_560', 'sample_movie_url_644', 'sample_movie_url_720'] as $key) {
        if (trim((string)($item[$key] ?? '')) !== '') {
            return true;
        }
    }

    $rawJson = (string)($item['raw_json'] ?? '');
    if ($rawJson !== '') {
        $raw = json_decode($rawJson, true);
        if (is_array($raw)) {
            foreach (['affiliateURL', 'service_code', 'floor_code', 'sampleMovieURL'] as $key) {
                if (isset($raw[$key])) {
                    return true;
                }
            }
        }
    }

    return false;
}

function search_item_raw(array $item): array
{
    $rawJson = (string)($item['raw_json'] ?? '');
    if ($rawJson === '') {
        return [];
    }

    $decoded = json_decode($rawJson, true);
    return is_array($decoded) ? $decoded : [];
}

function search_item_affiliate_url(array $item): string
{
    $affiliateUrl = trim((string)($item['affiliate_url'] ?? ''));
    if ($affiliateUrl !== '') {
        return $affiliateUrl;
    }

    $raw = search_item_raw($item);
    return trim((string)($raw['affiliateURL'] ?? ''));
}

function search_item_matches_partner_rss(array $item): bool
{
    $title = trim(pcf_item_title($item));
    $url = trim((string)($item['url'] ?? ''));
    $affiliateUrl = search_item_affiliate_url($item);
    $imageSmall = trim((string)($item['image_small'] ?? ''));
    $imageLarge = trim((string)($item['image_large'] ?? ''));

    if ($title === '' && $url === '' && $affiliateUrl === '' && $imageSmall === '' && $imageLarge === '') {
        return false;
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM rss_items ri INNER JOIN rss_sources rs ON rs.id = ri.source_id WHERE rs.source_type = "partner_link" AND (ri.title = :title OR ri.url = :url OR ri.url = :affiliate_url OR ri.image_url = :image_small OR ri.image_url = :image_large) LIMIT 1');
        $stmt->execute([':title' => $title, ':url' => $url, ':affiliate_url' => $affiliateUrl, ':image_small' => $imageSmall, ':image_large' => $imageLarge]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (Throwable) {
    }

    try {
        $stmt = db()->prepare('SELECT 1 FROM rss_items WHERE title = :title OR url = :url OR url = :affiliate_url OR image_url = :image_small OR image_url = :image_large LIMIT 1');
        $stmt->execute([':title' => $title, ':url' => $url, ':affiliate_url' => $affiliateUrl, ':image_small' => $imageSmall, ':image_large' => $imageLarge]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function search_normalize_query(string $value): string
{
    $value = str_replace('　', ' ', trim($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return trim($value);
}

function search_query_terms(string $query): array
{
    $query = search_normalize_query($query);
    if ($query === '') {
        return [];
    }

    $parts = preg_split('/\s+/u', $query) ?: [];
    $terms = [];
    foreach ($parts as $part) {
        $term = trim((string)$part);
        if ($term === '') {
            continue;
        }
        $terms[$term] = true;
    }

    return array_slice(array_keys($terms), 0, 8);
}

function search_compact_text(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    return preg_replace("/[\s　「」『』【】（）()［］\[\]｛｝{}・,，、。.!！?？:：;；ー－―‐\"'“”‘’]+/u", '', $value) ?? '';
}

function search_text_contains(string $haystack, string $needle): bool
{
    $haystack = (string)$haystack;
    $needle = trim($needle);
    if ($needle === '') {
        return false;
    }

    if (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false) {
        return true;
    }

    $compactHaystack = search_compact_text($haystack);
    $compactNeedle = search_compact_text($needle);
    return $compactNeedle !== '' && mb_strpos($compactHaystack, $compactNeedle, 0, 'UTF-8') !== false;
}

function search_item_matches_query(array $item, string $query): bool
{
    $terms = search_query_terms($query);
    if ($terms === []) {
        return false;
    }

    $title = pcf_item_title($item);
    $rawJson = (string)($item['raw_json'] ?? '');
    $contentId = trim((string)($item['content_id'] ?? ''));
    $productId = trim((string)($item['product_id'] ?? ''));

    foreach ($terms as $term) {
        if ($contentId === $term || $productId === $term) {
            return true;
        }
        if (search_text_contains($title, $term) || search_text_contains($rawJson, $term)) {
            return true;
        }
    }

    return false;
}

function search_item_is_displayable(array $item): bool
{
    if (search_item_matches_partner_rss($item)) {
        return false;
    }

    if (!search_item_has_product_source($item)) {
        return false;
    }

    if (pcf_item_title($item) === 'タイトル未設定') {
        return false;
    }

    if (trim(pcf_item_image($item)) === '') {
        return false;
    }

    return true;
}

function search_fetch_items(string $query, int $limit, int $offset): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $terms = search_query_terms($query);
    if ($terms === []) {
        return [];
    }

    $params = [];
    $termWhere = [];
    foreach ($terms as $index => $term) {
        $titleParam = ':q_title_' . $index;
        $rawParam = ':q_raw_json_' . $index;
        $contentParam = ':q_content_id_' . $index;
        $productParam = ':q_product_id_' . $index;
        $like = '%' . addcslashes($term, '\%_') . '%';
        $params[$titleParam] = $like;
        $params[$rawParam] = $like;
        $params[$contentParam] = $term;
        $params[$productParam] = $term;
        $termWhere[] = "(title LIKE {$titleParam} ESCAPE '\\\\' OR raw_json LIKE {$rawParam} ESCAPE '\\\\' OR content_id = {$contentParam} OR product_id = {$productParam})";
    }
    $whereSql = '(' . implode(' OR ', $termWhere) . ')';
    $sourceWhere = function_exists('items_product_source_where') ? items_product_source_where() : '';
    if ($sourceWhere !== '') {
        $whereSql .= ' AND ' . $sourceWhere;
    }
    $orderSqlCandidates = [
        'view_count DESC, release_date DESC, id DESC',
        'view_count DESC, date_published DESC, id DESC',
        'view_count DESC, id DESC',
        'release_date DESC, id DESC',
        'date_published DESC, id DESC',
        'updated_at DESC, id DESC',
        'id DESC',
    ];

    foreach ($orderSqlCandidates as $orderSql) {
        try {
            $chunkSize = max($limit + 1, 25);
            $cursor = 0;
            $targetCount = $offset + $limit + 1;
            $maxLoops = 30;
            $collected = [];

            for ($i = 0; $i < $maxLoops; $i++) {
                $stmt = db()->prepare('SELECT * FROM items WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT :l OFFSET :o');
                foreach ($params as $paramName => $paramValue) {
                    $stmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
                }
                $stmt->bindValue(':l', $chunkSize, PDO::PARAM_INT);
                $stmt->bindValue(':o', $cursor, PDO::PARAM_INT);
                $stmt->execute();
                $chunk = $stmt->fetchAll() ?: [];
                if ($chunk === []) {
                    break;
                }

                $rawFetched = count($chunk);
                $chunk = array_values(array_filter($chunk, static fn(array $row): bool => search_item_matches_query($row, $query) && search_item_is_displayable($row)));
                $collected = dedupe_items_by_key(array_merge($collected, $chunk));
                if (count($collected) >= $targetCount) {
                    break;
                }

                $cursor += $rawFetched;
                if ($rawFetched < $chunkSize) {
                    break;
                }
            }

            return array_slice($collected, $offset, $limit + 1);
        } catch (Throwable) {
        }
    }

    return [];
}

$searchQuery = safe_str($_GET['q'] ?? '', 200);
$page = normalize_int((int)($_GET['page'] ?? 1), 1, 100000);
$limit = (int)(app_config()['pagination']['per_page'] ?? 24);
$offset = ($page - 1) * $limit;
$searchRows = search_fetch_items($searchQuery, $limit, $offset);
[$searchItems, $searchHasNext] = paginate_items($searchRows, $limit);

$title = '検索結果';
$pageDescription = $searchQuery !== '' ? mb_strimwidth('「' . $searchQuery . '」の商品検索結果です。', 0, 150, '…', 'UTF-8') : 'キーワードを入力して商品を検索できます。';
$robotsMeta = 'noindex,follow';
$canonicalQuery = [];
if ($searchQuery !== '') {
    $canonicalQuery['q'] = $searchQuery;
}
if ($page > 1) {
    $canonicalQuery['page'] = $page;
}
$canonicalUrl = public_url('search.php') . ($canonicalQuery !== [] ? '?' . http_build_query($canonicalQuery) : '');
if ($page > 1) {
    $relPrev = public_url('search.php') . '?' . http_build_query(['q' => $searchQuery, 'page' => $page - 1]);
}
if ($searchHasNext) {
    $relNext = public_url('search.php') . '?' . http_build_query(['q' => $searchQuery, 'page' => $page + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_hero('検索結果', $searchQuery !== '' ? '「' . $searchQuery . '」の商品検索結果です。' : 'キーワードを入力して商品を検索できます。'); ?>

<?php if ($searchQuery === ''): ?>
  <?php pcf_render_empty('検索キーワードを入力してください。'); ?>
<?php elseif ($searchItems !== []): ?>
  <section class="pcf-related-grid">
    <?php foreach ($searchItems as $item): ?>
      <?php pcf_render_item_card(is_array($item) ? $item : []); ?>
    <?php endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if ($page > 1): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('search.php') . '?' . http_build_query(['q' => $searchQuery, 'page' => $page - 1])) ?>">前へ</a>
    <?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$page) ?></span>
    <?php if ($searchHasNext): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('search.php') . '?' . http_build_query(['q' => $searchQuery, 'page' => $page + 1])) ?>">次へ</a>
    <?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('検索条件に一致する商品がありません。'); ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
