-- ─────────────────────────────────────────────────────────────────────────────
-- master_products — shared product catalog on the platform DB.
--
-- Lives in `zrismpsz_reya_platform`. All tenants can browse it and import
-- selected SKUs into their own `business_items`. This is read-only for
-- tenants; only super_admin / dev populates it.
--
-- Mirrors the column shape of the master_สินค้า.xlsx working template that
-- pharmacists upload, so the same import pipeline can populate it from CSV.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `master_products` (
    `id`                 INT(11)       NOT NULL AUTO_INCREMENT,
    `sku`                VARCHAR(100)  NOT NULL COMMENT 'รหัสสินค้า — unique within source',
    `name`               VARCHAR(500)  NOT NULL COMMENT 'ชื่อสินค้า (ไทย)',
    `name_en`            VARCHAR(500)  DEFAULT NULL COMMENT 'ชื่อสินค้า (อังกฤษ)',
    `manufacturer`       VARCHAR(255)  DEFAULT NULL COMMENT 'ผู้ผลิต',
    `variant`            VARCHAR(255)  DEFAULT NULL COMMENT 'ตัวแปร — เช่น กล่องส้ม, รุ่น A',
    `generic_name`       VARCHAR(500)  DEFAULT NULL COMMENT 'ตัวยาสำคัญ (Generic)',
    `unit`               VARCHAR(50)   DEFAULT NULL COMMENT 'หน่วย',
    `pack_size`          VARCHAR(100)  DEFAULT NULL COMMENT 'ขนาดบรรจุ',
    `usage_instructions` LONGTEXT      DEFAULT NULL COMMENT 'วิธีใช้',
    `description`        LONGTEXT      DEFAULT NULL COMMENT 'สรรพคุณ / คุณสมบัติ',
    `image_url`          VARCHAR(500)  DEFAULT NULL COMMENT 'รูปภาพ (URL)',
    `source`             VARCHAR(50)   NOT NULL DEFAULT 'cny' COMMENT 'ที่มา: cny | manual | partner',
    `is_active`          TINYINT(1)    NOT NULL DEFAULT 1 COMMENT 'visible ใน picker',
    `created_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sku_source` (`sku`, `source`),
    KEY `idx_name`        (`name`(191)),
    KEY `idx_generic`     (`generic_name`(191)),
    KEY `idx_manufacturer`(`manufacturer`),
    KEY `idx_active`      (`is_active`),
    FULLTEXT KEY `ft_search` (`name`, `name_en`, `generic_name`, `manufacturer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Platform-wide shared product master — pickable by every tenant';
