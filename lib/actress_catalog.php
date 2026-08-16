<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';

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
    return "TRIM(COALESCE({$alias}.name, '')) <> ''";
}

function pca_actress_has_image_sql(string $alias = 'actresses'): string
{
    return '(COALESCE(' . $alias . ".image_large, '') <> ''"
        . ' OR COALESCE(' . $alias . ".image_small, '') <> ''"
        . ' OR COALESCE(' . $alias . ".image_url, '') <> '')";
}

/**
 * 女優一覧は保存済み actresses テーブルを正とする。
 * 通常一覧は作品同期の有無に依存させない。
 * しろうと女性だけ、商品フロア情報から判定する。
 *
 * @return array<int,array<string,mixed>>
 */
function pca_fetch_actresses(bool $amateur, int $limit = 10000, int $offset = 0, bool $withImagesOnly = false): array
{
    $limit = max(1, min(10000, $limit));
    $offset = max(0, $offset);
    $imageWhere = $withImagesOnly ? ' AND ' . pca_actress_has_image_sql('a') : '';

    try {
        if (!$amateur) {
            // LIMIT/OFFSET は整数に正規化済みなので直接埋め込み、環境差によるPDOのLIMITバインド失敗を避ける。
            $sql = "SELECT a.* FROM actresses a
                    WHERE TRIM(COALESCE(a.name, '')) <> ''{$imageWhere}
                    ORDER BY a.name ASC, a.id ASC
                    LIMIT {$limit} OFFSET {$offset}";
            $stmt = db()->query($sql);
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }

        $amateurSql = pca_amateur_item_sql('i');
        $sql = "SELECT DISTINCT a.*
                FROM actresses a
                INNER JOIN item_actresses ia
                  ON (ia.dmm_id = a.dmm_id OR (TRIM(ia.actress_name) <> '' AND ia.actress_name = a.name))
                INNER JOIN items i ON i.id = ia.item_id
                WHERE TRIM(COALESCE(a.name, '')) <> ''
                  AND {$amateurSql}{$imageWhere}
                ORDER BY a.name ASC, a.id ASC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = db()->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('actress catalogue fetch failed: ' . $e->getMessage());
        if (!$amateur) {
            try {
                $sql = "SELECT * FROM actresses
                        WHERE TRIM(COALESCE(name, '')) <> ''
                        ORDER BY name ASC, id ASC
                        LIMIT {$limit} OFFSET {$offset}";
                $stmt = db()->query($sql);
                return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (Throwable $fallbackError) {
                error_log('actress catalogue fallback failed: ' . $fallbackError->getMessage());
            }
        }
        return [];
    }
}

function pca_count_actresses(bool $amateur, bool $withImagesOnly = false): int
{
    return count(pca_fetch_actresses($amateur, 10000, 0, $withImagesOnly));
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

/** @return array{rows:array<int,array<string,mixed>>,page:int,pages:int,total:int} */
function pca_home_page(int $page, int $perPage = 120): array
{
    $page = max(1, $page);
    $perPage = max(1, min(120, $perPage));
    $rows = pca_fetch_actresses(false, 10000, 0, false);
    $seed = (int)sprintf('%u', crc32(gmdate('Y-m-d') . ':pinkclub-actress'));
    $rows = pca_seeded_shuffle($rows, $seed);
    $total = count($rows);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    return [
        'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
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
        'あ'=>'あ','い'=>'あ','う'=>'あ','え'=>'あ','お'=>'あ',
        'か'=>'か','き'=>'か','く'=>'か','け'=>'か','こ'=>'か','が'=>'か','ぎ'=>'か','ぐ'=>'か','げ'=>'か','ご'=>'か',
        'さ'=>'さ','し'=>'さ','す'=>'さ','せ'=>'さ','そ'=>'さ','ざ'=>'さ','じ'=>'さ','ず'=>'さ','ぜ'=>'さ','ぞ'=>'さ',
        'た'=>'た','ち'=>'た','つ'=>'た','て'=>'た','と'=>'た','だ'=>'た','ぢ'=>'た','づ'=>'た','で'=>'た','ど'=>'た',
        'な'=>'な','に'=>'な','ぬ'=>'な','ね'=>'な','の'=>'な',
        'は'=>'は','ひ'=>'は','ふ'=>'は','へ'=>'は','ほ'=>'は','ば'=>'は','び'=>'は','ぶ'=>'は','べ'=>'は','ぼ'=>'は','ぱ'=>'は','ぴ'=>'は','ぷ'=>'は','ぺ'=>'は','ぽ'=>'は',
        'ま'=>'ま','み'=>'ま','む'=>'ま','め'=>'ま','も'=>'ま',
        'や'=>'や','ゆ'=>'や','よ'=>'や',
        'ら'=>'ら','り'=>'ら','る'=>'ら','れ'=>'ら','ろ'=>'ら',
        'わ'=>'わ','を'=>'わ','ん'=>'わ',
    ];
    if (isset($map[$first])) {
        return $map[$first];
    }
    $latin = strtoupper(mb_substr($source, 0, 1, 'UTF-8'));
    return preg_match('/^[A-Z]$/', $latin) ? 'A-Z' : '#';
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
        $groups[pca_name_bucket((string)($row['name'] ?? ''), (string)($row['ruby'] ?? ''))][] = $row;
    }
    return $groups;
}
