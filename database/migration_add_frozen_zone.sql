-- Migration: Add 'frozen' zone type and make zone_type flexible
-- Date: 2026-01-09
-- Description: เปลี่ยน zone_type จาก ENUM เป็น VARCHAR เพื่อรองรับประเภทโซนแบบ dynamic

-- 1. Alter warehouse_locations.zone_type to VARCHAR
ALTER TABLE `warehouse_locations` 
MODIFY COLUMN `zone_type` VARCHAR(50) DEFAULT 'general';

-- 2. Alter business_items.storage_zone_type to VARCHAR
ALTER TABLE `business_items` 
MODIFY COLUMN `storage_zone_type` VARCHAR(50) DEFAULT 'general';

-- 3. Insert frozen zone type into zone_types table (if exists)
INSERT INTO `zone_types` (`code`, `label`, `color`, `icon`, `description`, `sort_order`, `is_active`, `created_at`, `line_account_id`) 
VALUES ('frozen', 'ห้องแช่แข็ง', 'indigo', 'fa-temperature-low', 'สำหรับสินค้าที่ต้องเก็บในอุณหภูมิต่ำกว่า 0°C', 3, 1, NOW(), 1)
ON DUPLICATE KEY UPDATE label = VALUES(label), is_active = 1;
