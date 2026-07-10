-- Migration: Flex Studio — per-shop Flex brand tokens + slot-bound template overrides
-- การย้ายฐานข้อมูล: ตั้งค่าธีม Flex ต่อร้าน + ผูกเทมเพลต override กับ slot
-- Date: 2026-07-10
--
-- Adds:
--   1. flex_brand_settings  — per line_account_id brand tokens (colors/logo/sender/footer)
--   2. flex_templates.slot_key + is_active — bind a stored template to a known Flex slot
--
-- Safe to re-run. Tenant-scoped by line_account_id. Timezone Asia/Bangkok (+07:00).

-- ---------------------------------------------------------------------------
-- 1) Brand tokens per shop / โทเคนธีมต่อร้าน
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `flex_brand_settings` (
  `line_account_id`   INT(11)      NOT NULL COMMENT 'FK line_accounts.id — one row per shop',
  `primary_color`     VARCHAR(9)   DEFAULT NULL COMMENT 'สีหลักแบรนด์ แทนที่ #06C755',
  `primary_dark`      VARCHAR(9)   DEFAULT NULL COMMENT 'สีเข้ม แทนที่ #006400 (ป้ายยา)',
  `accent_color`      VARCHAR(9)   DEFAULT NULL COMMENT 'สีรอง (ปุ่ม/ไฮไลต์)',
  `logo_url`          VARCHAR(500) DEFAULT NULL COMMENT 'โลโก้ร้านสำหรับ Flex',
  `sender_name`       VARCHAR(255) DEFAULT NULL COMMENT 'ชื่อผู้ส่งที่แสดงบน LINE',
  `sender_icon_url`   VARCHAR(500) DEFAULT NULL COMMENT 'ไอคอนผู้ส่ง',
  `shop_display_name` VARCHAR(255) DEFAULT NULL COMMENT 'ชื่อร้านที่โชว์ใน Flex (ทับ shop_settings)',
  `footer_text`       VARCHAR(500) DEFAULT NULL COMMENT 'ข้อความท้าย Flex',
  `corner_style`      VARCHAR(20)  DEFAULT NULL COMMENT 'none|sm|md|lg — ความโค้งมุม',
  `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-shop Flex brand tokens / โทเคนธีม Flex ต่อร้าน';

-- ---------------------------------------------------------------------------
-- 2) Bind stored templates to Flex slots / ผูกเทมเพลตกับ slot
--    Columns may already exist on re-run; guard with information_schema.
-- ---------------------------------------------------------------------------
SET @has_slot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flex_templates' AND COLUMN_NAME = 'slot_key'
);
SET @sql := IF(@has_slot = 0,
  'ALTER TABLE `flex_templates` ADD COLUMN `slot_key` VARCHAR(64) DEFAULT NULL COMMENT ''known Flex slot this template overrides''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_active := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flex_templates' AND COLUMN_NAME = 'is_active'
);
SET @sql := IF(@has_active = 0,
  'ALTER TABLE `flex_templates` ADD COLUMN `is_active` TINYINT(1) DEFAULT 0 COMMENT ''1 = this override is live for its slot''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'flex_templates' AND INDEX_NAME = 'idx_flex_slot'
);
SET @sql := IF(@has_idx = 0,
  'ALTER TABLE `flex_templates` ADD KEY `idx_flex_slot` (`line_account_id`, `slot_key`, `is_active`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
