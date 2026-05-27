-- ============================================================================
-- Migration: 2026-05-24 — Products & Inventory consolidation
-- ----------------------------------------------------------------------------
-- ขยายระบบสินค้าเพื่อรองรับหน้า /products.php (ข้อมูลสินค้า) แบบรวมศูนย์
-- Inspired by Smile Pharmacy /web/dataproduct, adapted for REYA multi-tenant.
--
-- This migration is IDEMPOTENT — safe to re-run.
-- Uses INFORMATION_SCHEMA-guarded ALTER pattern for column additions.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Extend business_items (ตารางสินค้าหลัก) with NEW columns
--    Pattern: add column only if it does not already exist.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS __reya_add_col;
DELIMITER //
CREATE PROCEDURE __reya_add_col(
    IN p_table   VARCHAR(64),
    IN p_column  VARCHAR(64),
    IN p_ddl     TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- business_items extensions
CALL __reya_add_col('business_items', 'dispensing_fee',     '`dispensing_fee` DECIMAL(10,2) DEFAULT 0 COMMENT "ค่าหยิบยา per unit / dispensing fee"');
CALL __reya_add_col('business_items', 'reorder_point',      '`reorder_point` INT DEFAULT 0 COMMENT "จุดสั่งซื้อ (ROP)"');
CALL __reya_add_col('business_items', 'storage_location_id','`storage_location_id` INT NULL COMMENT "FK storage_locations.id"');
CALL __reya_add_col('business_items', 'drug_group_id',      '`drug_group_id` INT NULL COMMENT "FK drug_groups.id"');
CALL __reya_add_col('business_items', 'generic_name_id',    '`generic_name_id` INT NULL COMMENT "FK generic_names.id"');
CALL __reya_add_col('business_items', 'unit_id',            '`unit_id` INT NULL COMMENT "FK product_units.id (sell unit)"');
CALL __reya_add_col('business_items', 'label_template_id',  '`label_template_id` INT NULL COMMENT "FK drug_label_templates.id"');
CALL __reya_add_col('business_items', 'usage_method',       '`usage_method` VARCHAR(100) NULL COMMENT "วิธีการใช้ (oral, topical, injection ...)"');
CALL __reya_add_col('business_items', 'label_language',     '`label_language` VARCHAR(5) DEFAULT "th" COMMENT "TH / EN"');
CALL __reya_add_col('business_items', 'default_usage_text', '`default_usage_text` TEXT NULL COMMENT "วิธีใช้เริ่มต้นสำหรับฉลาก"');
CALL __reya_add_col('business_items', 'default_warning_text','`default_warning_text` TEXT NULL COMMENT "คำเตือนเริ่มต้นสำหรับฉลาก"');

-- Helpful composite index for product listing (search + tenant scope)
SET @ix := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'business_items'
       AND INDEX_NAME   = 'idx_bi_tenant_active'
);
SET @sql := IF(@ix = 0,
    'ALTER TABLE `business_items` ADD INDEX `idx_bi_tenant_active` (`line_account_id`, `is_active`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. NEW tables (multi-tenant scoped)
-- ---------------------------------------------------------------------------

-- 2.1 Drug Groups — กลุ่มยา
CREATE TABLE IF NOT EXISTS `drug_groups` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `code`            VARCHAR(50)  NULL COMMENT 'รหัสกลุ่ม / group code',
    `name_th`         VARCHAR(255) NOT NULL COMMENT 'ชื่อภาษาไทย',
    `name_en`         VARCHAR(255) NULL COMMENT 'ชื่อภาษาอังกฤษ',
    `description`     TEXT NULL,
    `is_active`       TINYINT(1) DEFAULT 1,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_dg_tenant`     (`line_account_id`),
    INDEX `idx_dg_tenant_code`(`line_account_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='กลุ่มยา / drug groups (e.g. NSAIDs, antibiotics)';

-- 2.2 Generic Names — ชื่อทางการ
CREATE TABLE IF NOT EXISTS `generic_names` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id`     INT NOT NULL,
    `generic_name`        VARCHAR(255) NOT NULL COMMENT 'ชื่อทางการ / generic name',
    `atc_code`            VARCHAR(20)  NULL  COMMENT 'WHO ATC classification',
    `default_dosage_form` VARCHAR(100) NULL  COMMENT 'รูปแบบยาเริ่มต้น',
    `default_unit`        VARCHAR(50)  NULL  COMMENT 'หน่วยเริ่มต้น',
    `default_warnings`    TEXT         NULL  COMMENT 'คำเตือนเริ่มต้น',
    `description`         TEXT NULL,
    `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_gn_tenant`      (`line_account_id`),
    INDEX `idx_gn_tenant_name` (`line_account_id`, `generic_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ชื่อทางการของยา / generic drug names';

-- 2.3 Product Units — หน่วยสินค้า (with self-ref conversion)
CREATE TABLE IF NOT EXISTS `product_units` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id`  INT NOT NULL,
    `code`             VARCHAR(50)  NULL COMMENT 'รหัสหน่วย / unit code',
    `name`             VARCHAR(100) NOT NULL COMMENT 'ชื่อหน่วย (เม็ด, แผง, ขวด ...)',
    `name_en`          VARCHAR(100) NULL,
    `sub_unit_id`      INT NULL COMMENT 'self-ref → smaller unit',
    `conversion_ratio` DECIMAL(10,4) DEFAULT 1.0000 COMMENT 'this unit = ratio × sub_unit',
    `is_base_unit`     TINYINT(1) DEFAULT 0 COMMENT '1 = smallest/base unit',
    `is_active`        TINYINT(1) DEFAULT 1,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_pu_tenant`     (`line_account_id`),
    INDEX `idx_pu_tenant_code`(`line_account_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='หน่วยสินค้า (กล่อง / แผง / เม็ด) + conversion';

-- 2.4 Storage Locations — พื้นที่เก็บ
CREATE TABLE IF NOT EXISTS `storage_locations` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id`   INT NOT NULL,
    `code`              VARCHAR(50)  NULL  COMMENT 'รหัสพื้นที่ (A1, B2 ...)',
    `name`              VARCHAR(255) NOT NULL COMMENT 'ชื่อพื้นที่เก็บ',
    `temperature_range` VARCHAR(50)  NULL  COMMENT 'เช่น 2-8°C',
    `humidity_range`    VARCHAR(50)  NULL  COMMENT 'เช่น <60%',
    `notes`             TEXT NULL,
    `is_active`         TINYINT(1) DEFAULT 1,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_sl_tenant`     (`line_account_id`),
    INDEX `idx_sl_tenant_code`(`line_account_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='พื้นที่เก็บยา / storage locations';

-- 2.5 Drug Label Templates — ฉลากยา
CREATE TABLE IF NOT EXISTS `drug_label_templates` (
    `id`                       INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id`          INT NOT NULL,
    `name`                     VARCHAR(255) NOT NULL COMMENT 'ชื่อเทมเพลต',
    `template_text`            TEXT NOT NULL COMMENT 'placeholders: {shop_name}, {patient_name}, {drug_name}, {dose}, {usage}, {date}, {pharmacist}',
    `language`                 VARCHAR(5) DEFAULT 'th',
    `applies_to_generic_id`    INT NULL COMMENT 'FK generic_names.id (auto-apply)',
    `applies_to_usage_pattern` VARCHAR(100) NULL COMMENT 'usage_method match for bulk apply',
    `default_for_drug_group_id`INT NULL COMMENT 'FK drug_groups.id (default for whole group)',
    `is_active`                TINYINT(1) DEFAULT 1,
    `created_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_dlt_tenant`        (`line_account_id`),
    INDEX `idx_dlt_generic`       (`applies_to_generic_id`),
    INDEX `idx_dlt_drug_group`    (`default_for_drug_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='เทมเพลตฉลากยา / drug label templates';

-- 2.6 Drug Interactions — ตารางยาตีกัน
-- The legacy `drug_interactions` table (from migration_vibe_selling_v2.sql)
-- exists WITHOUT line_account_id. We extend it in place to keep the
-- consumer-facing /api/drug-interactions.php (LIFF check) working unchanged,
-- and add multi-tenant + spec-required columns.
CREATE TABLE IF NOT EXISTS `drug_interactions` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `drug1_name`      VARCHAR(255) NOT NULL COMMENT 'drug A brand name',
    `drug1_generic`   VARCHAR(255) NULL,
    `drug2_name`      VARCHAR(255) NOT NULL COMMENT 'drug B brand name',
    `drug2_generic`   VARCHAR(255) NULL,
    `severity`        ENUM('mild','moderate','severe','contraindicated') NOT NULL DEFAULT 'moderate',
    `description`     TEXT NULL,
    `recommendation`  TEXT NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_drug1` (`drug1_name`),
    INDEX `idx_drug2` (`drug2_name`),
    INDEX `idx_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL __reya_add_col('drug_interactions', 'line_account_id',  '`line_account_id` INT NULL COMMENT "FK line_accounts.id (NULL = global)" AFTER `id`');
CALL __reya_add_col('drug_interactions', 'mechanism',        '`mechanism` TEXT NULL COMMENT "กลไก / mechanism of interaction"');
CALL __reya_add_col('drug_interactions', 'interaction_text', '`interaction_text` TEXT NULL COMMENT "ข้อความผลที่เกิด (alias / extended description)"');

SET @ix := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'drug_interactions'
       AND INDEX_NAME   = 'idx_di_tenant'
);
SET @sql := IF(@ix = 0,
    'ALTER TABLE `drug_interactions` ADD INDEX `idx_di_tenant` (`line_account_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2.7 Stock Movements (extended) — ประวัติการตัด stock
-- Existing `stock_movements` table uses: quantity, stock_before, stock_after,
-- reference_type, reference_id, created_by, created_at, notes.
-- We add lot/expiry tracking + ensure required columns exist for the new UI.
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id`  INT DEFAULT NULL,
    `product_id`       INT NOT NULL COMMENT 'FK business_items.id',
    `movement_type`    VARCHAR(50) NOT NULL COMMENT 'sale|purchase_in|adjustment|expiry_writeoff|transfer_out|transfer_in|count_correction|return|goods_receive|disposal',
    `quantity`         INT NOT NULL COMMENT 'positive = in, negative = out',
    `stock_before`     INT NOT NULL DEFAULT 0,
    `stock_after`      INT NOT NULL DEFAULT 0,
    `unit_cost`        DECIMAL(10,2) NULL,
    `value_change`     DECIMAL(12,2) NULL,
    `reference_type`   VARCHAR(50) NULL,
    `reference_id`     INT NULL,
    `reference_number` VARCHAR(50) NULL,
    `notes`            TEXT NULL,
    `created_by`       INT NULL,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_product`        (`product_id`),
    INDEX `idx_movement_type`  (`movement_type`),
    INDEX `idx_reference`      (`reference_type`, `reference_id`),
    INDEX `idx_created_at`     (`created_at`),
    INDEX `idx_line_account`   (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ประวัติการเคลื่อนไหวสต๊อก / stock movements log';

CALL __reya_add_col('stock_movements', 'lot_no',      '`lot_no` VARCHAR(50) NULL COMMENT "Lot number"');
CALL __reya_add_col('stock_movements', 'expiry_date', '`expiry_date` DATE NULL COMMENT "Expiry date for this movement"');

-- 2.8 Stock Count Sessions — งานนับสินค้า (workflow state)
CREATE TABLE IF NOT EXISTS `stock_count_sessions` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NOT NULL,
    `code`            VARCHAR(50)  NULL COMMENT 'รหัสรอบนับ',
    `name`            VARCHAR(255) NULL COMMENT 'ชื่อรอบนับ (เช่น สิ้นเดือน 05/2569)',
    `status`          ENUM('draft','counting','submitted','adjusted','cancelled') DEFAULT 'draft',
    `scope`           ENUM('all','category','location','custom') DEFAULT 'all',
    `scope_ref_id`    INT NULL,
    `note`            TEXT NULL,
    `started_by`      INT NULL,
    `started_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `submitted_by`    INT NULL,
    `submitted_at`    TIMESTAMP NULL,
    INDEX `idx_scs_tenant` (`line_account_id`),
    INDEX `idx_scs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='งานนับสินค้า (stock count sessions)';

CREATE TABLE IF NOT EXISTS `stock_count_items` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `session_id`     INT NOT NULL,
    `product_id`     INT NOT NULL,
    `expected_qty`   INT NOT NULL DEFAULT 0,
    `counted_qty`    INT NULL,
    `delta`          INT NULL COMMENT 'counted - expected',
    `note`           TEXT NULL,
    `counted_at`     TIMESTAMP NULL,
    INDEX `idx_sci_session` (`session_id`),
    INDEX `idx_sci_product` (`product_id`),
    UNIQUE KEY `uniq_sci_session_product` (`session_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='รายการสินค้าที่นับในแต่ละรอบ';

-- ---------------------------------------------------------------------------
-- 3. item_categories — ensure display_order + is_active columns exist
-- ---------------------------------------------------------------------------
CALL __reya_add_col('item_categories', 'display_order', '`display_order` INT DEFAULT 0 COMMENT "ลำดับการแสดง"');
CALL __reya_add_col('item_categories', 'is_active',     '`is_active` TINYINT(1) DEFAULT 1');

-- ---------------------------------------------------------------------------
-- cleanup
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS __reya_add_col;

-- Done.
