<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';

/**
 * Actress-focused public catalogue helpers.
 *
 * FANZA ItemList identifies the amateur video floor with floor_code "videoc".
 * We also keep a name fallback so old rows remain classifiable after API changes.
 */
function pca_amateur_item_sql(string $alias = 'items'): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';

    return '('
        . $prefix . "floor_code = 'videoc'"
        . ' OR ' . $prefix . "floor_name LIKE '%素人%'"
        . ' OR ' . $prefix . "floor_name LIKE '%しろうと%'"
        . ' OR ' . $prefix . "floor_name LIKE '%シロウト%'"
        . ')';
}

function pca_valid_actress_sql(string $alias = 'actresses'): string
{
    return $alias . ".name <> ''"
        . ' AND ' . $alias . ".dmm_id REGEXP '^[0-9]+$'";
}

function pca_actress_has_image_sql(string $alias = 'actresses'): string
{
    return '(COALESCE(' . $alias . ".image_large, '') <> ''"
        . ' OR COALESCE(' . $alias . ".image_small, '') <> ''"
        . ' OR COALESCE(' . $alias . ".image_url, '') <> '')";
}

function pca_audience_exists_sql(bool $amateur, string $actressAlias = 'actresses'): string
{
    $amateurSql = pca_amateur_item_sql('i');
    $audienceSql = $amateur ? $amateurSql : 'NOT ' . $amateurSql;

    return 'EXISTS ('
        . 'SELECT 1 FROM item_actresses ia '
        . 'INNER JOIN items i ON i.id = ia.item_id '
        . 'WHERE ia.dmm_id = ' . $actressAlias . '.dmm_id '
        . 'AND ' . items_product_source_where('i') . ' '
        . 'AND ' . $audienceSql
        . ')';
}

/** @return array<int,array<string,mixed>> */
function pca_fetch_actresses(bool $amateur, int $limit = 10000, int $offset = 0, bool $withImagesOnly = false): array
{
    $limit = max(1, min(10000, $limit));
    $offset = max(0, $offset);

    $where = [
        pca_valid_actress_sql('a'),
        pca_audience_exists_sql($amateur, 'a'),
    ];
    if ($withImagesOnly) {
        $where[] = pca_actress_has_image_sql('a');
    }

    try {
        $sql = 'SELECT a.* FROM actresses a WHERE ' . implode(' AND ', $where)
            . ' ORDER BY a.name ASC, a.id ASC LIMIT :limit OFFSET :offset';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('actress catalogue fetch failed: ' . $e->getMessage());
        return [];
    }
}

function pca_count_actresses(bool $amateur, bool $withImagesOnly = false): int
{
    $where = [
        pca_valid_actress_sql('a'),
        pca_audience_exists_sql($amateur, 'a'),
    ];
    if ($withImagesOnly) {
        $where[] = pca_actress_has_image_sql('a');
    }

    try {
        $stmt = db()->query('SELECT COUNT(*) FROM actresses a WHERE ' . implode(' AND ', $where));
        return max(0, (int)$stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('actress catalogue count failed: ' . $e->getMessage());
        return 0;
    }
}

function pca_actress_image(array $row): string
{
    foreach (['image_large', 'image_small', 'image_url'] as $key) {
        $url = trim((string)($row[$key] ?? ''));
        if ($url !== '') {
            return $url;
        }
    }
    return '';
}

function pca_seeded_shuffle(array $rows, int $seed): array
{
    if (count($rows) < 2) {
        return $rows;
    }

    mt_srand($seed);
    for ($i = count($rows) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
    }
    mt_srand();

    return $rows;
}

/**
 * Build one 120-card home page by mixing regular actresses and amateur actresses.
 * The order changes daily, while remaining stable during a day for SEO/cache friendliness.
 *
 * @return array{rows:array<int,array<string,mixed>>,page:int,pages:int,total:int}
 */
function pca_home_page(int $page, int $perPage = 120): array
{
    $page = max(1, $page);
    $perPage = max(1, min(120, $perPage));

    $regular = pca_fetch_actresses(false, 10000, 0, true);
    $amateur = pca_fetch_actresses(true, 10000, 0, true);

    foreach ($regular as &$row) {
        $row['_audience'] = 'regular';
    }
    unset($row);
    foreach ($amateur as &$row) {
        $row['_audience'] = 'amateur';
    }
    unset($row);

    // Deduplicate the same actress when she appears on both floors.
    $all = [];
    foreach (array_merge($regular, $amateur) as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (!isset($all[$id])) {
            $all[$id] = $row;
            continue;
        }
        // Prefer the amateur label if the performer is available on both floors.
        if (($row['_audience'] ?? '') === 'amateur') {
            $all[$id] = $row;
        }
    }

    $rows = array_values($all);
    $seed = (int)sprintf('%u', crc32(gmdate('Y-m-d') . ':pinkclub-actress'));
    $rows = pca_seeded_shuffle($rows, $seed);

    $total = count($rows);
    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    return [
        'rows' => array_slice($rows, $offset, $perPage),
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
    ];
}

function pca_name_bucket(string $name, string $ruby = ''): string
{
    $source = trim($ruby) !== '' ? trim($ruby) : trim($name);
    if ($source === '') {
        return '#';
    }

    $first = mb_substr($source, 0, 1, 'UTF-8');
    $map = [
        'あ' => 'あ', 'い' => 'あ', 'う' => 'あ', 'え' => 'あ', 'お' => 'あ',
        'か' => 'か', 'き' => 'か', 'く' => 'か', 'け' => 'か', 'こ' => 'か', 'が' => 'か', 'ぎ' => 'か', 'ぐ' => 'か', 'げ' => 'か', 'ご' => 'か',
        'さ' => 'さ', 'し' => 'さ', 'す' => 'さ', 'せ' => 'さ', 'そ' => 'さ', 'ざ' => 'さ', 'じ' => 'さ', 'ず' => 'さ', 'ぜ' => 'さ', 'ぞ' => 'さ',
        'た' => 'た', 'ち' => 'た', 'つ' => 'た', 'て' => 'た', 'と' => 'た', 'だ' => 'た', 'ぢ' => 'た', 'づ' => 'た', 'で' => 'た', 'ど' => 'た',
        'な' => 'な', 'に' => 'な', 'ぬ' => 'な', 'ね' => 'な', 'の' => 'な',
        'は' => 'は', 'ひ' => 'は', 'ふ' => 'は', 'へ' => 'は', 'ほ' => 'は', 'ば' => 'は', 'び' => 'は', 'ぶ' => 'は', 'べ' => 'は', 'ぼ' => 'は', 'ぱ' => 'は', 'ぴ' => 'は', 'ぷ' => 'は', 'ぺ' => 'は', 'ぽ' => 'は',
        'ま' => 'ま', 'み' => 'ま', 'む' => 'ま', 'め' => 'ま', 'も' => 'ま',
        'や' => 'や', 'ゆ' => 'や', 'よ' => 'や',
        'ら' => 'ら', 'り' => 'ら', 'る' => 'ら', 'れ' => 'ら', 'ろ' => 'ら',
        'わ' => 'わ', 'を' => 'わ', 'ん' => 'わ',
    ];
    if (isset($map[$first])) {
        return $map[$first];
    }

    $latin = strtoupper(mb_substr($source, 0, 1, 'UTF-8'));
    if (preg_match('/^[A-Z]$/', $latin)) {
        return 'A-Z';
    }

    return '#';
}

/** @return array<string,array<int,array<string,mixed>>> */
function pca_group_actresses(array $rows): array
{
    $groups = [];
    foreach (['あ','か','さ','た','な','は','ま','や','ら','わ','A-Z','#'] as $key) {
        $groups[$key] = [];
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $bucket = pca_name_bucket((string)($row['name'] ?? ''), (string)($row['ruby'] ?? ''));
        $groups[$bucket][] = $row;
    }
    return $groups;
}
