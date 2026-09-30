<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * 女優ごとの商品取得位置を保存する。
 * 女優マスタは女優APIだけを正とし、商品APIから actresses を増やさない。
 */
function pca_product_coverage_ensure_state_table(): void
{
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS actress_product_sync_state (
            actress_id INT UNSIGNED NOT NULL PRIMARY KEY,
            dmm_id VARCHAR(64) NOT NULL,
            next_offset INT NOT NULL DEFAULT 1,
            is_complete TINYINT(1) NOT NULL DEFAULT 0,
            checked_at DATETIME NULL,
            last_item_count INT NOT NULL DEFAULT 0,
            last_api_count INT NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_actress_product_sync_checked (checked_at),
            INDEX idx_actress_product_sync_complete_checked (is_complete, checked_at),
            CONSTRAINT fk_actress_product_sync_actress
                FOREIGN KEY (actress_id) REFERENCES actresses(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM actress_product_sync_state');
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $columns[(string)($row['Field'] ?? '')] = true;
    }
    if (!isset($columns['next_offset'])) {
        $pdo->exec('ALTER TABLE actress_product_sync_state ADD COLUMN next_offset INT NOT NULL DEFAULT 1 AFTER dmm_id');
    }
    if (!isset($columns['is_complete'])) {
        $pdo->exec('ALTER TABLE actress_product_sync_state ADD COLUMN is_complete TINYINT(1) NOT NULL DEFAULT 0 AFTER next_offset');
    }
}

function pca_product_coverage_count_linked_actresses(): int
{
    try {
        return (int)db()->query(
            "SELECT COUNT(*)
             FROM actresses a
             WHERE a.dmm_id REGEXP '^[0-9]+$'
               AND TRIM(COALESCE(a.name,''))<>''
               AND EXISTS (
                   SELECT 1
                   FROM item_actresses ia
                   INNER JOIN items i ON i.id=ia.item_id
                   WHERE ia.dmm_id=a.dmm_id
                     AND i.floor_code='videoa'
                     AND i.item_source='fanza_product'
               )"
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('actress product coverage count failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * 毎回「最も長く確認していない登録済み女優」から取得する。
 * 商品が既にある女優も next_offset を進め、出演作品を少しずつDBへ蓄積する。
 *
 * @return array<int,array<string,mixed>>
 */
function pca_product_coverage_targets(int $limit): array
{
    $limit = max(1, min(20, $limit));
    pca_product_coverage_ensure_state_table();

    $stmt = db()->prepare(
        "SELECT
            a.id,
            a.dmm_id,
            a.name,
            COALESCE(s.next_offset,1) AS next_offset,
            COALESCE(s.is_complete,0) AS is_complete,
            s.checked_at
         FROM actresses a
         LEFT JOIN actress_product_sync_state s ON s.actress_id=a.id
         WHERE a.dmm_id REGEXP '^[0-9]+$'
           AND TRIM(COALESCE(a.name,''))<>''
         ORDER BY
           CASE WHEN s.checked_at IS NULL THEN 0 ELSE 1 END ASC,
           s.checked_at ASC,
           a.id ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pca_product_coverage_count_for_dmm_id(string $dmmId): int
{
    $dmmId = trim($dmmId);
    if ($dmmId === '' || preg_match('/^[0-9]+$/', $dmmId) !== 1) {
        return 0;
    }

    try {
        $stmt = db()->prepare(
            "SELECT COUNT(DISTINCT i.id)
             FROM item_actresses ia
             INNER JOIN items i ON i.id=ia.item_id
             INNER JOIN actresses a ON a.dmm_id=ia.dmm_id
             WHERE ia.dmm_id=:dmm_id
               AND a.dmm_id REGEXP '^[0-9]+$'
               AND i.floor_code='videoa'
               AND i.item_source='fanza_product'"
        );
        $stmt->execute([':dmm_id' => $dmmId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('actress product count failed: ' . $e->getMessage());
        return 0;
    }
}

function pca_product_coverage_save_state(
    int $actressId,
    string $dmmId,
    int $nextOffset,
    bool $isComplete,
    int $itemCount,
    int $apiCount,
    string $error = ''
): void {
    pca_product_coverage_ensure_state_table();

    $stmt = db()->prepare(
        "INSERT INTO actress_product_sync_state(
            actress_id,dmm_id,next_offset,is_complete,checked_at,last_item_count,last_api_count,last_error,updated_at
         ) VALUES(
            :actress_id,:dmm_id,:next_offset,:is_complete,NOW(),:item_count,:api_count,:last_error,NOW()
         )
         ON DUPLICATE KEY UPDATE
            dmm_id=VALUES(dmm_id),
            next_offset=VALUES(next_offset),
            is_complete=VALUES(is_complete),
            checked_at=VALUES(checked_at),
            last_item_count=VALUES(last_item_count),
            last_api_count=VALUES(last_api_count),
            last_error=VALUES(last_error),
            updated_at=NOW()"
    );
    $stmt->execute([
        ':actress_id' => $actressId,
        ':dmm_id' => $dmmId,
        ':next_offset' => max(1, min(50000, $nextOffset)),
        ':is_complete' => $isComplete ? 1 : 0,
        ':item_count' => max(0, $itemCount),
        ':api_count' => max(0, $apiCount),
        ':last_error' => $error !== '' ? mb_substr($error, 0, 500) : null,
    ]);
}
