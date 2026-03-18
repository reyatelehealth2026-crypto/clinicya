-- ============================================================================
-- Migration: เพิ่ม Index สำหรับตารางที่ขาดหายในแผนเดิม
-- Created: 2026-03-18  Updated: 2026-03-18 (remove DESC, fix dlq columns)
-- Tables: odoo_notification_log, odoo_bdo_context, odoo_bdo_orders,
--         odoo_webhook_dlq, odoo_line_users, odoo_slip_uploads,
--         odoo_orders_summary, odoo_customers_cache
--
-- ใช้ IF NOT EXISTS เพื่อให้รันซ้ำได้อย่างปลอดภัย (idempotent)
-- หมายเหตุ: DESC ใน index definition ไม่รองรับ MySQL < 8.0 / MariaDB < 10.8
--           → ใช้ ASC เท่านั้นเพื่อ compatibility
-- Run: mysql -u $DB_USER -p$DB_PASS $DB_NAME < database/migration_missing_indexes.sql
-- ============================================================================

-- ── odoo_notification_log ─────────────────────────────────────────────────
ALTER TABLE odoo_notification_log
    ADD INDEX IF NOT EXISTS idx_notif_sent_at        (sent_at),
    ADD INDEX IF NOT EXISTS idx_notif_status_sent    (status, sent_at),
    ADD INDEX IF NOT EXISTS idx_notif_line_user_sent (line_user_id, sent_at),
    ADD INDEX IF NOT EXISTS idx_notif_event_sent     (event_type, sent_at);

-- ── odoo_bdo_context ──────────────────────────────────────────────────────
ALTER TABLE odoo_bdo_context
    ADD INDEX IF NOT EXISTS idx_bdo_ctx_bdo_id (bdo_id, id),
    ADD INDEX IF NOT EXISTS idx_bdo_ctx_id     (id);

-- ── odoo_bdo_orders ───────────────────────────────────────────────────────
-- Actual columns (from OdooSyncService.php INSERT):
--   bdo_id, bdo_name, order_id, order_name, amount_total, payment_reference,
--   partner_id, customer_name, line_user_id, payment_method, webhook_delivery_id,
--   payment_status, created_at, updated_at
-- NOTE: due_date / payment_state ไม่มีใน table นี้ — อยู่ใน odoo_bdos แทน
ALTER TABLE odoo_bdo_orders
    ADD INDEX IF NOT EXISTS idx_bdo_orders_bdo_id         (bdo_id),
    ADD INDEX IF NOT EXISTS idx_bdo_orders_partner        (partner_id, order_id),
    ADD INDEX IF NOT EXISTS idx_bdo_orders_payment_status (payment_status),
    ADD INDEX IF NOT EXISTS idx_bdo_orders_payment_method (payment_method);

-- ── odoo_webhook_dlq ──────────────────────────────────────────────────────
-- Actual columns (INSERT in OdooWebhookHandler.php):
--   id, delivery_id, event_type, payload, error_code,
--   error_message, retry_count, failed_at
-- Extra columns added via migration_bdos_schema_fix.sql:
--   status, webhook_log_id, last_retry_at, resolved_at, created_at
-- NOTE: next_retry_at ไม่มีอยู่จริง — ชื่อจริงคือ last_retry_at
-- NOTE: รัน migration_bdos_schema_fix.sql ก่อนไฟล์นี้เสมอ
ALTER TABLE odoo_webhook_dlq
    ADD INDEX IF NOT EXISTS idx_dlq_status_failed   (status, failed_at),
    ADD INDEX IF NOT EXISTS idx_dlq_failed_at       (failed_at),
    ADD INDEX IF NOT EXISTS idx_dlq_last_retry      (last_retry_at),
    ADD INDEX IF NOT EXISTS idx_dlq_webhook_log_id  (webhook_log_id);

-- ── odoo_line_users ───────────────────────────────────────────────────────
ALTER TABLE odoo_line_users
    ADD INDEX IF NOT EXISTS idx_line_users_partner       (odoo_partner_id, line_user_id),
    ADD INDEX IF NOT EXISTS idx_line_users_customer_code (odoo_customer_code);

-- ── odoo_slip_uploads ─────────────────────────────────────────────────────
ALTER TABLE odoo_slip_uploads
    ADD INDEX IF NOT EXISTS idx_slips_status_uploaded (status, uploaded_at),
    ADD INDEX IF NOT EXISTS idx_slips_line_user       (line_user_id, uploaded_at),
    ADD INDEX IF NOT EXISTS idx_slips_matched_order   (matched_order_id);

-- ── odoo_orders_summary (cache table) ────────────────────────────────────
ALTER TABLE odoo_orders_summary
    ADD INDEX IF NOT EXISTS idx_orders_sum_date_state   (date_order, state),
    ADD INDEX IF NOT EXISTS idx_orders_sum_customer_ref (customer_ref(50)),
    ADD INDEX IF NOT EXISTS idx_orders_sum_line_user    (line_user_id, last_event_at);

-- ── odoo_customers_cache (cache table) ───────────────────────────────────
ALTER TABLE odoo_customers_cache
    ADD INDEX IF NOT EXISTS idx_cust_cache_name  (customer_name(80)),
    ADD INDEX IF NOT EXISTS idx_cust_cache_phone (phone(20));
