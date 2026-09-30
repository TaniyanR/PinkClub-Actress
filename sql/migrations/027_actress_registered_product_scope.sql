SET NAMES utf8mb4;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME = 'hobby'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actresses ADD COLUMN hobby VARCHAR(255) NULL AFTER prefectures', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME = 'bust'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actresses ADD COLUMN bust VARCHAR(32) NULL AFTER hobby', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME = 'cup'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actresses ADD COLUMN cup VARCHAR(32) NULL AFTER bust', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME = 'waist'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actresses ADD COLUMN waist VARCHAR(32) NULL AFTER cup', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME = 'hip'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actresses ADD COLUMN hip VARCHAR(32) NULL AFTER waist', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME = 'height'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actresses ADD COLUMN height VARCHAR(32) NULL AFTER hip', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME = 'blood_type'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actresses ADD COLUMN blood_type VARCHAR(32) NULL AFTER height', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS actress_product_sync_state (
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
  CONSTRAINT fk_actress_product_sync_actress FOREIGN KEY (actress_id) REFERENCES actresses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actress_product_sync_state' AND COLUMN_NAME = 'next_offset'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actress_product_sync_state ADD COLUMN next_offset INT NOT NULL DEFAULT 1 AFTER dmm_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actress_product_sync_state' AND COLUMN_NAME = 'is_complete'
);
SET @sql := IF(@col_exists = 0, 'ALTER TABLE actress_product_sync_state ADD COLUMN is_complete TINYINT(1) NOT NULL DEFAULT 0 AFTER next_offset', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actress_product_sync_state'
    AND INDEX_NAME = 'idx_actress_product_sync_complete_checked'
);
SET @sql := IF(
  @idx_exists = 0,
  'CREATE INDEX idx_actress_product_sync_complete_checked ON actress_product_sync_state(is_complete, checked_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
