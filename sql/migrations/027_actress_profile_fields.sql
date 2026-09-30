SET NAMES utf8mb4;

SET @cols := 'hobby,bust,cup,waist,hip,height,blood_type';

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
