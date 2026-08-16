<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';

$id = max(0, (int)get('id', 0));
$contentId = trim((string)get('content_id', ''));
if ($contentId === '') {
    $contentId = trim((string)get('cid', ''));
}

$item = null;
try {
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM items WHERE id = :id AND ' . items_product_source_where() . ' LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $item = is_array($row) ? $row : null;
    } elseif ($contentId !== '') {
        $item = fetch_item_by_content_id($contentId);
    }
} catch (Throwable $e) {
    error_log('purchase redirect lookup failed: ' . $e->getMessage());
    $item = null;
}

if (!is_array($item)) {
    require __DIR__ . '/404.php';
    exit;
}

$purchaseUrl = trim((string)($item['affiliate_url'] ?? ''));
if ($purchaseUrl === '') {
    $purchaseUrl = trim((string)($item['url'] ?? ''));
}

$scheme = strtolower((string)parse_url($purchaseUrl, PHP_URL_SCHEME));
if ($purchaseUrl === '' || !in_array($scheme, ['http', 'https'], true)) {
    require __DIR__ . '/404.php';
    exit;
}

// PinkClub-Actress has no product detail page. Product cards go straight to the
// FANZA purchase page (affiliate_url takes priority over the plain product URL).
header('Cache-Control: no-store, private');
header('Location: ' . $purchaseUrl, true, 302);
exit;
