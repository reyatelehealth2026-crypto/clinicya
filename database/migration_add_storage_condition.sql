-- =============================================
-- Migration: Add storage_condition to business_items
-- Version: 1.0
-- Description: เพิ่ม column storage_condition ที่ขาดหายไป
-- =============================================

SET NAMES utf8mb4;

-- Add storage_condition column if not exists
DROP PROCEDURE IF EXISTS AddStorageConditionColumn;

DELIMITER //
CREATE PROCEDURE AddStorageConditionColumn()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'business_items' 
        AND COLUMN_NAME = 'storage_condition'
    ) THEN
        ALTER TABLE `business_items` ADD COLUMN `storage_condition` VARCHAR(255) DEFAULT NULL COMMENT 'สภาพการจัดเก็บ/ตำแหน่งจัดเก็บ';
    END IF;
END //
DELIMITER ;

CALL AddStorageConditionColumn();
DROP PROCEDURE IF EXISTS AddStorageConditionColumn;

-- Success message
SELECT 'Migration completed: storage_condition column added to business_items' AS result;
