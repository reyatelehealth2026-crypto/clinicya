-- =============================================
-- Migration: Add value_change column to stock_movements
-- Version: 1.0
-- Description: เพิ่ม column value_change สำหรับ tracking มูลค่าการเคลื่อนไหว stock
-- Requirements: 6.3
-- =============================================

SET NAMES utf8mb4;

-- =============================================
-- CREATE stock_movements TABLE IF NOT EXISTS
-- This ensures the table exists before adding columns
-- =============================================
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `product_id` INT NOT NULL,
    `movement_type` VARCHAR(50) NOT NULL COMMENT 'goods_receive, disposal, adjustment_in, adjustment_out, sale',
    `quantity` INT NOT NULL COMMENT 'Positive for in, negative for out',
    `stock_before` INT NOT NULL DEFAULT 0,
    `stock_after` INT NOT NULL DEFAULT 0,
    `reference_type` VARCHAR(50) NULL COMMENT 'goods_receive, batch_disposal, adjustment, order',
    `reference_id` INT NULL,
    `reference_number` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_product` (`product_id`),
    INDEX `idx_movement_type` (`movement_type`),
    INDEX `idx_reference` (`reference_type`, `reference_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ADD value_change COLUMN TO stock_movements TABLE
-- For tracking cost impact of each movement
-- Requirements: 6.3
-- =============================================

-- Add value_change column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'stock_movements' 
     AND COLUMN_NAME = 'value_change') = 0,
    "ALTER TABLE `stock_movements` ADD COLUMN `value_change` DECIMAL(12,2) NULL COMMENT 'Cost impact: quantity × unit_cost'",
    "SELECT 'Column value_change already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unit_cost column if not exists (for reference)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'stock_movements' 
     AND COLUMN_NAME = 'unit_cost') = 0,
    "ALTER TABLE `stock_movements` ADD COLUMN `unit_cost` DECIMAL(10,2) NULL COMMENT 'Unit cost at time of movement'",
    "SELECT 'Column unit_cost already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add stock_before column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'stock_movements' 
     AND COLUMN_NAME = 'stock_before') = 0,
    "ALTER TABLE `stock_movements` ADD COLUMN `stock_before` INT NOT NULL DEFAULT 0 COMMENT 'Stock quantity before movement'",
    "SELECT 'Column stock_before already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add stock_after column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'stock_movements' 
     AND COLUMN_NAME = 'stock_after') = 0,
    "ALTER TABLE `stock_movements` ADD COLUMN `stock_after` INT NOT NULL DEFAULT 0 COMMENT 'Stock quantity after movement'",
    "SELECT 'Column stock_after already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for value_change queries
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'stock_movements' 
     AND INDEX_NAME = 'idx_value_change') = 0,
    "ALTER TABLE `stock_movements` ADD INDEX `idx_value_change` (`value_change`)",
    "SELECT 'Index idx_value_change already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Success message
SELECT 'Migration completed: value_change column added to stock_movements' AS result;
