<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';

header('Content-Type: application/xml; charset=UTF-8');

function sitemap_e(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function sitemap_url(string $loc, string $changefreq, string $priority, string $lastmod = ''): void
{
    echo "  <url>\n";
    echo '    <loc>' . sitemap_e($loc) . "</loc>\n";
    if ($lastmod !== '') {
        echo '    <lastmod>' . sitemap_e(substr($lastmod, 0, 10)) . "</lastmod>\n";
    }
    echo '    <changefreq>' . sitemap_e($changefreq) . "</changefreq>\n";
    echo '    <priority>' . sitemap_e($priority) . "</priority>\n";
    echo "  </url>\n";
}

function sitemap_actress_where(): string
{
    return "TRIM(COALESCE(entity.name, '')) <> ''"
        . " AND entity.dmm_id REGEXP '^[0-9]+$'"
        . " AND LOWER(entity.name) NOT LIKE '%http://%'"
        . " AND LOWER(entity.name) NOT LIKE '%https://%'"
        . " AND LOWER(entity.name) NOT LIKE '%www.%'"
        . " AND entity.name NOT LIKE '%/%'"
        . ' AND EXISTS ('
        . 'SELECT 1 FROM item_actresses relation_row '
        . 'INNER JOIN items related_item ON related_item.id = relation_row.item_id '
        . 'WHERE relation_row.dmm_id = entity.dmm_id '
        . 'AND ' . items_product_source_where('related_item')
        . ')';
}

function sitemap_actress_count(): int
{
    try {
        return (int)db()->query(
            'SELECT COUNT(*) FROM actresses entity WHERE ' . sitemap_actress_where()
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('actress sitemap count failed: ' . $e->getMessage());
        return 0;
    }
}

function sitemap_emit_actresses(int $start, int &$remaining): int
{
    $count = sitemap_actress_count();
    if ($remaining <= 0 || $start >= $count) {
        return $count;
    }

    $limit = min($remaining, $count - $start);
    try {
        $stmt = db()->prepare(
            'SELECT entity.id FROM actresses entity WHERE ' . sitemap_actress_where()
            . ' ORDER BY entity.id ASC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            sitemap_url(public_url('actress.php') . '?id=' . rawurlencode((string)$id), 'weekly', '0.8');
            $remaining--;
        }
    } catch (Throwable $e) {
        error_log('actress sitemap rows failed: ' . $e->getMessage());
    }

    return $count;
}

$perSitemap = 10000;
$staticUrls = [
    [rtrim(BASE_URL, '/') . '/', 'daily', '1.0'],
    [public_url('actresses.php'), 'daily', '0.9'],
    [public_url('amateur_actresses.php'), 'daily', '0.9'],
];
$actressCount = sitemap_actress_count();
$totalUrls = count($staticUrls) + $actressCount;

if ((isset($_GET['index']) && (string)$_GET['index'] === '1') || ($totalUrls > $perSitemap && !isset($_GET['part']))) {
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    $pages = max(1, (int)ceil($totalUrls / $perSitemap));
    for ($i = 1; $i <= $pages; $i++) {
        echo "  <sitemap>\n";
        echo '    <loc>' . sitemap_e(public_url('sitemap.php') . '?part=' . $i) . "</loc>\n";
        echo "  </sitemap>\n";
    }
    echo "</sitemapindex>\n";
    return;
}

$part = max(1, (int)($_GET['part'] ?? 1));
$start = ($part - 1) * $perSitemap;
$remaining = $perSitemap;

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

foreach ($staticUrls as $index => $url) {
    if ($index < $start) {
        continue;
    }
    if ($remaining <= 0) {
        break;
    }
    sitemap_url((string)$url[0], (string)$url[1], (string)$url[2]);
    $remaining--;
}
$start = max(0, $start - count($staticUrls));

if ($remaining > 0) {
    sitemap_emit_actresses($start, $remaining);
}

echo "</urlset>\n";
