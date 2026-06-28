-- =============================================================================
-- Migration: Platform Owner — Tenant Billing & Usage (manual tracking)
-- Date:      2026-06-04
-- ADR:       docs/adr/0001-database-per-tenant-isolation.md (master DB layer)
-- Scope:     master DB `zrismpsz_reya_platform` — 3 tables:
--              tenant_subscriptions, tenant_payments, tenant_usage_snapshots
--
-- Purpose:   เก็บข้อมูล subscription + การชำระเงิน (เจ้าของบันทึกเอง — ไม่ต่อ
--            payment gateway) และ cache ตัวเลขการใช้งานต่อร้าน สำหรับหน้า
--            เจ้าของระบบ `admin/customers.php`.
--
-- Re-run safe: ทุก CREATE ใช้ IF NOT EXISTS; backfill ใช้ INSERT IGNORE.
-- Charset:   utf8mb4_unicode_ci (Thai)
-- Engine:    InnoDB (FK)
-- Timezone:  Asia/Bangkok (+07:00) — บังคับโดย connection layer
-- Target:    MariaDB 10.3+ / PHP 8.0+
--
-- Notes:
--   * สถานะการจ่าย (จ่ายแล้ว/ค้าง/เกินกำหนด) ไม่เก็บเป็นคอลัมน์ — คำนวณตอน read
--     จาก next_due_date เทียบ CURDATE() (ดู includes/platform-billing-helpers.php)
--   * suspend/terminate ยังเป็น manual — ไม่มี cron ตัดร้านอัตโนมัติ
-- =============================================================================
USE `zrismpsz_reya_platform`;

-- -----------------------------------------------------------------------------
-- 1) tenant_subscriptions — เงื่อนไข subscription ปัจจุบัน (1 แถว / 1 tenant)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_subscriptions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL COMMENT 'FK tenants.id (1:1)',
  `start_date` DATE NOT NULL COMMENT 'วันเริ่มใช้งาน (default = DATE(tenants.created_at))',
  `billing_cycle` ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly'
    COMMENT 'รอบบิล',
  `next_due_date` DATE NOT NULL COMMENT 'วันครบกำหนดชำระรอบถัดไป',
  `last_paid_date` DATE NULL DEFAULT NULL COMMENT 'cache จากการจ่ายล่าสุด (tenant_payments)',
  `amount_thb` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'ยอดที่ตกลงต่อรอบ (default = ราคา plan, แก้ได้เผื่อส่วนลด/ดีลพิเศษ)',
  `note` VARCHAR(255) DEFAULT NULL COMMENT 'หมายเหตุของเจ้าของระบบ',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tsub_tenant` (`tenant_id`),
  KEY `idx_tsub_next_due` (`next_due_date`),
  CONSTRAINT `fk_tsub_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='เงื่อนไข subscription ต่อ tenant (บันทึกเอง)';

-- -----------------------------------------------------------------------------
-- 2) tenant_payments — ประวัติการจ่าย (append log, immutable by convention)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_payments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL COMMENT 'FK tenants.id',
  `paid_date` DATE NOT NULL COMMENT 'วันที่จ่าย',
  `amount_thb` DECIMAL(10,2) NOT NULL COMMENT 'ยอดที่จ่าย (บาท)',
  `method` ENUM('bank_transfer','promptpay','credit_card','cash','other') NOT NULL DEFAULT 'bank_transfer'
    COMMENT 'วิธีชำระ',
  `reference` VARCHAR(100) DEFAULT NULL COMMENT 'เลขอ้างอิง/หมายเหตุสลิป',
  `period_start` DATE NULL DEFAULT NULL COMMENT 'รอบที่จ่ายครอบคลุม (เริ่ม)',
  `period_end` DATE NULL DEFAULT NULL COMMENT 'รอบที่จ่ายครอบคลุม (สิ้นสุด)',
  `note` VARCHAR(255) DEFAULT NULL,
  `recorded_by` INT NULL DEFAULT NULL COMMENT 'FK platform_users.id (คนที่บันทึก)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tpay_tenant_date` (`tenant_id`, `paid_date`),
  CONSTRAINT `fk_tpay_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_tpay_recorded_by` FOREIGN KEY (`recorded_by`)
    REFERENCES `platform_users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ประวัติการชำระเงินต่อ tenant';

-- -----------------------------------------------------------------------------
-- 3) tenant_usage_snapshots — cache ตัวเลขการใช้งาน (1 แถว / 1 tenant, upsert)
--    เติมโดย admin/customers.php (ปุ่มรีเฟรช) และ cron/refresh_tenant_usage.php
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_usage_snapshots` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL COMMENT 'FK tenants.id (1:1, latest snapshot)',
  `num_users` INT NULL DEFAULT NULL COMMENT 'จำนวน users ใน tenant DB (NULL = นับไม่ได้)',
  `num_orders` INT NULL DEFAULT NULL COMMENT 'จำนวน orders',
  `num_messages` INT NULL DEFAULT NULL COMMENT 'จำนวน messages',
  `computed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'อัปเดตล่าสุดเมื่อ',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tusage_tenant` (`tenant_id`),
  CONSTRAINT `fk_tusage_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='cache ตัวเลขการใช้งานต่อ tenant (สำหรับลิสต์หน้า customers)';

-- =============================================================================
-- Backfill — สร้าง subscription ให้ tenant ที่มีอยู่แล้วทุกราย (ยกเว้น terminated)
--   start_date    = DATE(tenants.created_at)
--   amount_thb    = ราคา plan ปัจจุบัน (0 ถ้าไม่มี)
--   next_due_date = วันนี้ + 1 เดือน  ← ค่าเริ่มต้น เจ้าของควร review/ปรับหลัง migrate
-- idempotent ผ่าน UNIQUE(tenant_id) + INSERT IGNORE
-- =============================================================================
INSERT IGNORE INTO `tenant_subscriptions`
  (`tenant_id`, `start_date`, `billing_cycle`, `next_due_date`, `amount_thb`)
SELECT
  t.`id`,
  DATE(t.`created_at`),
  'monthly',
  DATE_ADD(CURDATE(), INTERVAL 1 MONTH),
  COALESCE(p.`price_monthly_thb`, 0.00)
FROM `tenants` t
LEFT JOIN `plans` p ON p.`id` = t.`plan_id`
WHERE t.`status` <> 'terminated';

-- =============================================================================
-- End of migration_2026-06-04_platform_billing.sql
-- =============================================================================
