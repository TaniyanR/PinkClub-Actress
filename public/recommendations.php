<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/lib/repository.php';
require_once __DIR__ . '/partials/public_ui.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

function recommendation_ids(string $key, int $limit = 10): array
{
    $raw = trim((string)($_GET[$key] ?? ''));
    if ($raw === '') {
        return [];
    }

    $ids = [];
    foreach (explode(',', $raw) as $value) {
        $id = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false && !in_array((int)$id, $ids, true)) {
            $ids[] = (int)$id;
        }
        if (count($ids) >= $limit) {
            break;
        }
    }
    return $ids;
}

function recommendation_placeholders(array $values, string $prefix, array &$params): string
{
    $placeholders = [];
    foreach (array_values($values) as $index => $value) {
        $key = ':' . $prefix . $index;
        $placeholders[] = $key;
        $params[$key] = (int)$value;
    }
    return implode(',', $placeholders);
}

function recommendation_add_scores(array &$scores, array $rows, int $weight, string $reason): void
{
    foreach ($rows as $row) {
        $itemId = (int)($row['item_id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        if (!isset($scores[$itemId])) {
            $scores[$itemId] = ['score' => 0, 'reasons' => []];
        }
        $scores[$itemId]['score'] += $weight * max(1, (int)($row['matches'] ?? 1));
        $scores[$itemId]['reasons'][$reason] = true;
    }
}

function recommendation_relation_rows(string $table, string $column, array $ids): array
{
    if ($ids === []) {
        return [];
    }

    $allowed = [
        'item_actresses' => 'actress_id',
        'item_genres' => 'genre_id',
        'item_makers' => 'maker_id',
        'item_series' => 'series_id',
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        return [];
    }

    $params = [];
    $in = recommendation_placeholders($ids, 'relation_', $params);
    try {
        $stmt = db()->prepare(
            'SELECT item_id, COUNT(*) AS matches FROM ' . $table .
            ' WHERE ' . $column . ' IN (' . $in . ') AND item_id IS NOT NULL' .
            ' GROUP BY item_id LIMIT 300'
        );
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

$actresses = recommendation_ids('actresses');
$genres = recommendation_ids('genres');
$makers = recommendation_ids('makers');
$series = recommendation_ids('series');
$viewed = recommendation_ids('viewed', 20);

if ($actresses === [] && $genres === [] && $makers === [] && $series === []) {
    echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$scores = [];
recommendation_add_scores($scores, recommendation_relation_rows('item_actresses', 'actress_id', $actresses), 8, 'よく見ている女優に近い作品');
recommendation_add_scores($scores, recommendation_relation_rows('item_genres', 'genre_id', $genres), 5, 'よく見ているジャンルに近い作品');
recommendation_add_scores($scores, recommendation_relation_rows('item_series', 'series_id', $series), 4, 'よく見ているシリーズに近い作品');
recommendation_add_scores($scores, recommendation_relation_rows('item_makers', 'maker_id', $makers), 3, 'よく見ているメーカーに近い作品');

foreach ($viewed as $viewedId) {
    unset($scores[$viewedId]);
}

if ($scores === []) {
    echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$candidateIds = array_keys($scores);
usort($candidateIds, static function (int $a, int $b) use ($scores): int {
    $scoreCompare = ((int)($scores[$b]['score'] ?? 0)) <=> ((int)($scores[$a]['score'] ?? 0));
    return $scoreCompare !== 0 ? $scoreCompare : ($b <=> $a);
});
$candidateIds = array_slice($candidateIds, 0, 80);

$params = [];
$in = recommendation_placeholders($candidateIds, 'item_', $params);

try {
    $sql = 'SELECT * FROM items WHERE id IN (' . $in . ') AND ' . items_product_source_where('items');
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
} catch (Throwable) {
    $rows = [];
}

usort($rows, static function (array $a, array $b) use ($scores): int {
    $aId = (int)($a['id'] ?? 0);
    $bId = (int)($b['id'] ?? 0);
    $scoreCompare = ((int)($scores[$bId]['score'] ?? 0)) <=> ((int)($scores[$aId]['score'] ?? 0));
    if ($scoreCompare !== 0) {
        return $scoreCompare;
    }
    $dateCompare = strcmp((string)($b['release_date'] ?? ''), (string)($a['release_date'] ?? ''));
    return $dateCompare !== 0 ? $dateCompare : ($bId <=> $aId);
});

$items = [];
foreach (array_slice($rows, 0, 10) as $row) {
    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $reasonKeys = array_keys((array)($scores[$id]['reasons'] ?? []));
    $items[] = [
        'id' => $id,
        'title' => pcf_item_title($row),
        'image' => pcf_item_image($row),
        'url' => public_url('item.php?id=' . $id),
        'reason' => (string)($reasonKeys[0] ?? '閲覧傾向に近い作品'),
    ];
}

echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
