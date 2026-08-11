<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/partials/public_ui.php';
require_once __DIR__ . '/../lib/actress_directory_cache.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$key = trim((string)get('group', ''));
if (!preg_match('/\A(?:kana:[あかさたなはまやらわ]|alpha:[A-Z])\z/u', $key)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'rows' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    echo json_encode([
        'success' => true,
        'rows' => pcf_actress_directory_cache_group($key),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('actress directory group failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'rows' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
