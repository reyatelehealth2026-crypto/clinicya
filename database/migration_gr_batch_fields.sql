-- =============================================
-- Migration: Add batch fields to goods_receive_items
-- Version: 1.0
-- Description: เพิ่ม columns สำหรับ batch tracking ใน GR items
-- Requirements: 1.2, 4.2
-- =============================================

SET NAMES utf8mb4;

-- =============================================
-- ADD BATCH FIELDS TO goods_receive_items TABLE
-- For tracking batch/lot information during goods receive
-- =============================================

-- Add batch_number column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'goods_receive_items' 
     AND COLUMN_NAME = 'batch_number') = 0,
    "ALTER TABLE `goods_receive_items` ADD COLUMN `batch_number` VARCHAR(50) NULL COMMENT 'Batch number from supplier'",
    "SELECT 'Column batch_number already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add lot_number column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'goods_receive_items' 
     AND COLUMN_NAME = 'lot_number') = 0,
    "ALTER TABLE `goods_receive_items` ADD COLUMN `lot_number` VARCHAR(50) NULL COMMENT 'Lot number from supplier'",
    "SELECT 'Column lot_number already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add expiry_date column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'goods_receive_items' 
     AND COLUMN_NAME = 'expiry_date') = 0,
    "ALTER TABLE `goods_receive_items` ADD COLUMN `expiry_date` DATE NULL COMMENT 'Product expiry date'",
    "SELECT 'Column expiry_date already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add manufacture_date column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'goods_receive_items' 
     AND COLUMN_NAME = 'manufacture_date') = 0,
    "ALTER TABLE `goods_receive_items` ADD COLUMN `manufacture_date` DATE NULL COMMENT 'Product manufacture date'",
    "SELECT 'Column manufacture_date already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for batch_number for faster lookups
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'goods_receive_items' 
     AND INDEX_NAME = 'idx_gri_batch_number') = 0,
    "ALTER TABLE `goods_receive_items` ADD INDEX `idx_gri_batch_number` (`batch_number`)",
    "SELECT 'Index idx_gri_batch_number already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for expiry_date for expiry tracking queries
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'goods_receive_items' 
     AND INDEX_NAME = 'idx_gri_expiry_date') = 0,
    "ALTER TABLE `goods_receive_items` ADD INDEX `idx_gri_expiry_date` (`expiry_date`)",
    "SELECT 'Index idx_gri_expiry_date already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Success message
SELECT 'Migration completed: batch fields added to goods_receive_items' AS result;
