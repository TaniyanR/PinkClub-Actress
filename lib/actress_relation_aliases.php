<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * 商品API側と女優API側でDMM IDがずれていても、同一の通常女優名なら
 * videoa作品の関係を数値DMM IDへ補完する。
 * しろうと(videoc / name:合成ID)には適用しない。
 */
function pca_backfill_normal_actress_alias_relations(): array
{
    $pdo = db();
    $before = 0;
    $after = 0;

    try {
        $before = (int)$pdo->query(
            "SELECT COUNT(*) FROM item_actresses ia
             INNER JOIN items i ON i.id=ia.item_id
             WHERE i.floor_code='videoa'"
        )->fetchColumn();

        $sql = "INSERT IGNORE INTO item_actresses (item_id,dmm_id,actress_name)
                SELECT DISTINCT ia.item_id, a.dmm_id, a.name
                FROM item_actresses ia
                INNER JOIN items i ON i.id=ia.item_id
                INNER JOIN actresses a
                  ON LOWER(REPLACE(REPLACE(REPLACE(TRIM(a.name),' ',''),'　',''),CHAR(9),''))
                   = LOWER(REPLACE(REPLACE(REPLACE(TRIM(ia.actress_name),' ',''),'　',''),CHAR(9),''))
                WHERE i.floor_code='videoa'
                  AND TRIM(COALESCE(ia.actress_name,''))<>''
                  AND a.dmm_id REGEXP '^[0-9]+$'";
        $pdo->exec($sql);

        $after = (int)$pdo->query(
            "SELECT COUNT(*) FROM item_actresses ia
             INNER JOIN items i ON i.id=ia.item_id
             WHERE i.floor_code='videoa'"
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('normal actress alias relation backfill failed: ' . $e->getMessage());
    }

    return [
        'before' => $before,
        'after' => $after,
        'added' => max(0, $after - $before),
    ];
}
