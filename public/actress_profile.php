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

function actress_profile_api_row_score(array $apiRow): int
{
    $score = 0;
    foreach (['bust', 'cup', 'waist', 'hip', 'height', 'birthday', 'blood_type', 'hobby', 'prefectures', 'ruby'] as $key) {
        if (trim((string)($apiRow[$key] ?? '')) !== '') {
            $score++;
        }
    }
    if (trim((string)($apiRow['imageURL']['large'] ?? $apiRow['image_large'] ?? '')) !== '') {
        $score += 2;
    }
    if (trim((string)($apiRow['imageURL']['small'] ?? $apiRow['image_small'] ?? '')) !== '') {
        $score++;
    }
    return $score;
}

function actress_profile_best_api_row(array $rows, string $dmmId, string $name): ?array
{
    $best = null;
    $bestScore = -1;
    foreach ($rows as $apiRow) {
        if (!is_array($apiRow)) {
            continue;
        }
        $apiId = trim((string)($apiRow['id'] ?? ''));
        $apiName = trim((string)($apiRow['name'] ?? ''));
        if ($apiId !== $dmmId && $apiName !== $name) {
            continue;
        }
        $score = actress_profile_api_row_score($apiRow);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $apiRow;
        }
    }
    return $best;
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

function actress_profile_success(array $profile): void
{
    actress_profile_json_response([
        'success' => true,
        'display' => actress_profile_display_values($profile),
        'image_url' => actress_profile_image_url($profile),
    ]);
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
if ($name === '' || !ctype_digit($dmmId)) {
    actress_profile_json_response(['success' => false], 404);
}

$profile = [
    'dmm_id' => $dmmId,
    'name' => $name,
    'ruby' => (string)($row['ruby'] ?? ''),
    'birthday' => (string)($row['birthday'] ?? ''),
    'prefectures' => (string)($row['prefectures'] ?? ''),
    'image_url' => (string)($row['image_url'] ?? ''),
    'image_small' => (string)($row['image_small'] ?? ''),
    'image_large' => (string)($row['image_large'] ?? ''),
    'bust' => '',
    'cup' => '',
    'waist' => '',
    'hip' => '',
    'height' => '',
    'blood_type' => '',
    'hobby' => '',
];

$cacheKey = 'public.actress.profile.v1.' . $id;
$cacheTtl = 7 * 24 * 60 * 60;
try {
    $cached = json_decode((string)(setting_get($cacheKey, '') ?? ''), true);
    $cachedAt = is_array($cached) ? (int)($cached['cached_at'] ?? 0) : 0;
    $cachedProfile = is_array($cached) ? ($cached['profile'] ?? null) : null;
    if ($cachedAt >= time() - $cacheTtl && is_array($cachedProfile)) {
        foreach (array_keys($profile) as $key) {
            if (array_key_exists($key, $cachedProfile)) {
                $profile[$key] = (string)$cachedProfile[$key];
            }
        }
        actress_profile_success($profile);
    }
} catch (Throwable) {
}

try {
    $client = dmm_client_for_type('actresses');
    $response = $client->searchActresses(['actress_id' => $dmmId, 'hits' => 10, 'offset' => 1]);
    $apiRows = DmmNormalizer::toList($response['result']['actress'] ?? []);
    $bestApiRow = actress_profile_best_api_row($apiRows, $dmmId, $name);

    if (!is_array($bestApiRow)) {
        $keywordResponse = $client->searchActresses(['keyword' => $name, 'hits' => 20, 'offset' => 1]);
        $keywordRows = DmmNormalizer::toList($keywordResponse['result']['actress'] ?? []);
        $bestApiRow = actress_profile_best_api_row($keywordRows, $dmmId, $name);
    }

    if (is_array($bestApiRow)) {
        $profile['name'] = trim((string)($bestApiRow['name'] ?? '')) !== '' ? (string)$bestApiRow['name'] : $profile['name'];
        $profile['ruby'] = (string)($bestApiRow['ruby'] ?? $profile['ruby']);
        $profile['birthday'] = (string)($bestApiRow['birthday'] ?? $profile['birthday']);
        $profile['prefectures'] = (string)($bestApiRow['prefectures'] ?? $profile['prefectures']);
        $profile['image_url'] = (string)($bestApiRow['imageURL']['large'] ?? $bestApiRow['image_url'] ?? $profile['image_url']);
        $profile['image_small'] = (string)($bestApiRow['imageURL']['small'] ?? $bestApiRow['image_small'] ?? $profile['image_small']);
        $profile['image_large'] = (string)($bestApiRow['imageURL']['large'] ?? $bestApiRow['image_large'] ?? $profile['image_large']);
        foreach (['bust', 'cup', 'waist', 'hip', 'height', 'blood_type', 'hobby'] as $key) {
            $profile[$key] = trim((string)($bestApiRow[$key] ?? ''));
        }

        try {
            upsert_actress([
                'dmm_id' => $profile['dmm_id'],
                'name' => $profile['name'],
                'ruby' => $profile['ruby'],
                'birthday' => $profile['birthday'],
                'prefectures' => $profile['prefectures'],
                'image_url' => $profile['image_url'],
                'image_small' => $profile['image_small'],
                'image_large' => $profile['image_large'],
            ]);
        } catch (Throwable $e) {
            error_log('actress_profile.php upsert failed: ' . $e->getMessage());
        }
    }

    try {
        setting_set($cacheKey, json_encode([
            'cached_at' => time(),
            'profile' => $profile,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    } catch (Throwable $e) {
        error_log('actress_profile.php cache write failed: ' . $e->getMessage());
    }

    actress_profile_success($profile);
} catch (Throwable $e) {
    error_log('actress_profile.php API refresh failed: ' . $e->getMessage());
    actress_profile_json_response([
        'success' => true,
        'display' => actress_profile_display_values($profile),
        'image_url' => actress_profile_image_url($profile),
    ]);
}
