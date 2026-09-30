<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/crawler_guard.php';

pcf_crawler_guard_check();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=300');
header('X-Robots-Tag: noindex, nofollow', true);

function actress_profile_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"success":false}';
    exit;
}

function actress_profile_display_values(array $profile): array
{
    $display = [];
    foreach (['ruby', 'prefectures', 'hobby', 'bust', 'cup', 'waist', 'hip', 'height', 'blood_type'] as $key) {
        $value = trim((string)($profile[$key] ?? ''));
        $display[$key] = $value !== '' ? $value : '未登録';
    }
    $birthday = trim((string)($profile['birthday'] ?? ''));
    $display['birthday'] = $birthday !== '' ? format_date($birthday) : '未登録';
    return $display;
}

function actress_profile_image_url(array $profile): string
{
    foreach (['image_large', 'image_small', 'image_url'] as $key) {
        $value = trim((string)($profile[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($id) || $id <= 0) {
    actress_profile_json_response(['success' => false], 400);
}

try {
    $row = fetch_actress($id);
} catch (Throwable) {
    $row = null;
}
if (!is_array($row)) {
    actress_profile_json_response(['success' => false], 404);
}

$dmmId = trim((string)($row['dmm_id'] ?? ''));
$name = trim((string)($row['name'] ?? ''));
if ($name === '' || preg_match('/^[0-9]+$/', $dmmId) !== 1) {
    actress_profile_json_response(['success' => false], 404);
}

actress_profile_json_response([
    'success' => true,
    'display' => actress_profile_display_values($row),
    'image_url' => actress_profile_image_url($row),
]);
