-- ============================================================================
-- Migration: เพิ่ม columns ที่ขาดใน odoo_bdos และ odoo_webhook_dlq
-- Created: 2026-03-18  Updated: 2026-03-18
--
-- ปัญหาที่พบจากการรัน migration จริง:
--   1. odoo_bdos ขาด payment_state, amount_net_to_pay, due_date
--   2. odoo_webhook_dlq ขาด status, webhook_log_id, last_retry_at, resolved_at
--      (INSERT ใน OdooWebhookHandler.php ไม่ได้ set columns เหล่านี้ → ต้องมี DEFAULT)
--
-- รันก่อน migration_missing_indexes.sql เสมอ
-- MariaDB 10.0.2+: รองรับ ADD COLUMN IF NOT EXISTS
-- ============================================================================

-- ── 1. เพิ่ม columns ที่ขาดใน odoo_bdos ─────────────────────────────────

ALTER TABLE odoo_bdos
    ADD COLUMN IF NOT EXISTS `payment_state`    VARCHAR(64)    DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `amount_net_to_pay` DECIMAL(14,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `due_date`         DATE           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `payment_method`   VARCHAR(100)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `payment_reference` VARCHAR(255)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `qr_data`          TEXT           DEFAULT NULL;

-- ── 2. เพิ่ม columns ที่ขาดใน odoo_webhook_dlq ───────────────────────────
-- dashboard API ใช้ status, webhook_log_id, last_retry_at, resolved_at
-- แต่ OdooWebhookHandler.php INSERT ไม่ได้ include columns เหล่านี้

ALTER TABLE odoo_webhook_dlq
    ADD COLUMN IF NOT EXISTS `status`         VARCHAR(32)  NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS `webhook_log_id` INT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `last_retry_at`  DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `resolved_at`    DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP;

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
