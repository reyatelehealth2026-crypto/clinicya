-- =============================================
-- Put Away & Location Management Migration
-- Version: 1.0
-- Description: Creates tables for warehouse locations, batch tracking, and movements
-- =============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- WAREHOUSE LOCATIONS TABLE
-- Zone-Shelf-Bin hierarchy for storage locations
-- =============================================
CREATE TABLE IF NOT EXISTS `warehouse_locations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT 1,
    `location_code` VARCHAR(20) NOT NULL,           -- A1-03-02 format
    `zone` VARCHAR(10) NOT NULL,                    -- A, B, C, RX, COLD
    `shelf` INT NOT NULL,                           -- 1-10
    `bin` INT NOT NULL,                             -- 1-20
    `zone_type` ENUM('general', 'cold_storage', 'controlled', 'hazardous') DEFAULT 'general',
    `ergonomic_level` ENUM('golden', 'upper', 'lower') DEFAULT 'golden',
    `capacity` INT DEFAULT 100,                     -- max items
    `current_qty` INT DEFAULT 0,
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uk_location_code` (`location_code`, `line_account_id`),
    INDEX `idx_zone` (`zone`),
    INDEX `idx_zone_type` (`zone_type`),
    INDEX `idx_location_code` (`location_code`),
    INDEX `idx_line_account` (`line_account_id`),
    INDEX `idx_ergonomic` (`ergonomic_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================
-- INVENTORY BATCHES TABLE
-- Batch/Lot tracking with expiry management
-- =============================================
CREATE TABLE IF NOT EXISTS `inventory_batches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT 1,
    `product_id` INT NOT NULL,
    `batch_number` VARCHAR(50) NOT NULL,
    `lot_number` VARCHAR(50) NULL,
    `supplier_id` INT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `quantity_available` INT NOT NULL DEFAULT 0,
    `cost_price` DECIMAL(10,2) NULL,
    `manufacture_date` DATE NULL,
    `expiry_date` DATE NULL,
    `received_at` DATETIME NOT NULL,
    `received_by` INT NULL,
    `location_id` INT NULL,
    `status` ENUM('active', 'quarantine', 'expired', 'disposed') DEFAULT 'active',
    `disposal_date` DATETIME NULL,
    `disposal_by` INT NULL,
    `disposal_reason` TEXT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX `idx_product` (`product_id`),
    INDEX `idx_batch_number` (`batch_number`),
    INDEX `idx_expiry` (`expiry_date`),
    INDEX `idx_status` (`status`),
    INDEX `idx_location` (`location_id`),
    INDEX `idx_line_account` (`line_account_id`),
    INDEX `idx_received_at` (`received_at`),
    CONSTRAINT `fk_batch_location` FOREIGN KEY (`location_id`) 
        REFERENCES `warehouse_locations`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================
-- LOCATION MOVEMENTS TABLE
-- Track all product movements in warehouse
-- =============================================
CREATE TABLE IF NOT EXISTS `location_movements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT 1,
    `product_id` INT NOT NULL,
    `batch_id` INT NULL,
    `from_location_id` INT NULL,
    `to_location_id` INT NULL,
    `quantity` INT NOT NULL,
    `movement_type` ENUM('put_away', 'pick', 'transfer', 'adjustment', 'disposal') NOT NULL,
    `reference_type` VARCHAR(50) NULL,              -- order, gr, adjustment
    `reference_id` INT NULL,
    `staff_id` INT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX `idx_product` (`product_id`),
    INDEX `idx_batch` (`batch_id`),
    INDEX `idx_from_location` (`from_location_id`),
    INDEX `idx_to_location` (`to_location_id`),
    INDEX `idx_created` (`created_at`),
    INDEX `idx_line_account` (`line_account_id`),
    INDEX `idx_movement_type` (`movement_type`),
    CONSTRAINT `fk_movement_from_location` FOREIGN KEY (`from_location_id`) 
        REFERENCES `warehouse_locations`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_movement_to_location` FOREIGN KEY (`to_location_id`) 
        REFERENCES `warehouse_locations`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_movement_batch` FOREIGN KEY (`batch_id`) 
        REFERENCES `inventory_batches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================
-- ADD COLUMNS TO BUSINESS_ITEMS TABLE
-- For ABC classification and location tracking
-- =============================================

-- Add movement_class column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'business_items' 
     AND COLUMN_NAME = 'movement_class') = 0,
    "ALTER TABLE `business_items` ADD COLUMN `movement_class` ENUM('A', 'B', 'C') DEFAULT 'C' COMMENT 'ABC classification'",
    "SELECT 'Column movement_class already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add storage_zone_type column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'business_items' 
     AND COLUMN_NAME = 'storage_zone_type') = 0,
    "ALTER TABLE `business_items` ADD COLUMN `storage_zone_type` ENUM('general', 'cold_storage', 'controlled', 'hazardous') DEFAULT 'general' COMMENT 'Required storage zone type'",
    "SELECT 'Column storage_zone_type already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add default_location_id column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'business_items' 
     AND COLUMN_NAME = 'default_location_id') = 0,
    "ALTER TABLE `business_items` ADD COLUMN `default_location_id` INT NULL COMMENT 'Default storage location'",
    "SELECT 'Column default_location_id already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add requires_batch_tracking column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'business_items' 
     AND COLUMN_NAME = 'requires_batch_tracking') = 0,
    "ALTER TABLE `business_items` ADD COLUMN `requires_batch_tracking` TINYINT(1) DEFAULT 0 COMMENT 'Requires batch/lot tracking'",
    "SELECT 'Column requires_batch_tracking already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add requires_expiry_tracking column if not exists
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'business_items' 
     AND COLUMN_NAME = 'requires_expiry_tracking') = 0,
    "ALTER TABLE `business_items` ADD COLUMN `requires_expiry_tracking` TINYINT(1) DEFAULT 0 COMMENT 'Requires expiry date tracking'",
    "SELECT 'Column requires_expiry_tracking already exists'"
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


SET FOREIGN_KEY_CHECKS = 1;
