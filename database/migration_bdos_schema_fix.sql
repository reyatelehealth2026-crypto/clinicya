-- ============================================================================
-- Migration: เพิ่ม columns ที่ขาดใน odoo_bdos และแก้ไข odoo_webhooks_log
-- Created: 2026-03-18
--
-- ปัญหา:
--   1. odoo_bdos มีข้อมูล 1,668 rows แต่ขาด payment_state, amount_net_to_pay, due_date
--      ทำให้ dashboard query และ index migration ล้มเหลว
--   2. migration_odoo_api_performance.sql พยายาม index payment_state ที่ไม่มีอยู่
--
-- MariaDB support: ADD COLUMN IF NOT EXISTS (10.0.2+)
-- MySQL 8.0.31+: ไม่รองรับ IF NOT EXISTS สำหรับ ADD COLUMN → ใช้ stored procedure แทน
-- ============================================================================

-- ── 1. เพิ่ม columns ที่ขาดใน odoo_bdos ─────────────────────────────────

ALTER TABLE odoo_bdos
    ADD COLUMN IF NOT EXISTS `payment_state` VARCHAR(64) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `amount_net_to_pay` DECIMAL(14,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `due_date` DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `payment_reference` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `qr_data` TEXT DEFAULT NULL;

-- ── 2. เพิ่ม index หลังเพิ่ม column ─────────────────────────────────────

-- index นี้ถูกต้องแล้ว (จาก migration_odoo_api_performance.sql) แต่ทำไม่ได้เพราะ column ยังไม่มี
ALTER TABLE odoo_bdos
    ADD INDEX IF NOT EXISTS idx_bdos_payment_state (`payment_state`, `state`, `due_date`),
    ADD INDEX IF NOT EXISTS idx_bdos_due_date (`due_date`),
    ADD INDEX IF NOT EXISTS idx_bdos_amount_net (`amount_net_to_pay`);

-- ── 3. backfill ค่า default สำหรับ rows เดิมที่ไม่มี payment_state ─────
-- rows เดิมที่ state = 'done' หรือ 'cancel' ตั้ง payment_state เป็นค่า reasonable
UPDATE odoo_bdos
    SET payment_state = CASE
        WHEN state = 'cancel' THEN 'cancelled'
        WHEN state IN ('done', 'validated') THEN 'paid'
        ELSE 'not_paid'
    END
    WHERE payment_state IS NULL;
