-- ============================================================================
-- Migration: เพิ่ม Index สำหรับตารางที่ขาดหายในแผนเดิม
-- Created: 2026-03-18
-- Tables: odoo_notification_log, odoo_bdo_context, odoo_bdo_orders,
--         odoo_webhook_dlq, odoo_line_users, odoo_slip_uploads,
--         odoo_orders_summary, odoo_customers_cache
--
-- ใช้ IF NOT EXISTS เพื่อให้รันซ้ำได้อย่างปลอดภัย (idempotent)
-- Run: mysql -u $DB_USER -p$DB_PASS $DB_NAME < database/migration_missing_indexes.sql
-- ============================================================================

-- ── odoo_notification_log ─────────────────────────────────────────────────
-- DATE(sent_at) queries ทำ full scan เพราะ function wrap ทำให้ index ไม่ถูกใช้
-- หลังจากแก้ query เป็น range (WHERE sent_at >= CURDATE()) index นี้จะใช้งานได้ทันที
ALTER TABLE odoo_notification_log
    ADD INDEX IF NOT EXISTS idx_notif_sent_at        (sent_at),
    ADD INDEX IF NOT EXISTS idx_notif_status_sent    (status, sent_at),
    ADD INDEX IF NOT EXISTS idx_notif_line_user_sent (line_user_id, sent_at DESC),
    ADD INDEX IF NOT EXISTS idx_notif_event_sent     (event_type, sent_at DESC);

-- ── odoo_bdo_context ──────────────────────────────────────────────────────
-- ใช้ GROUP BY bdo_id + MAX(id) บ่อยมากใน bdo_inbox-api / dashboard
ALTER TABLE odoo_bdo_context
    ADD INDEX IF NOT EXISTS idx_bdo_ctx_bdo_id (bdo_id, id DESC),
    ADD INDEX IF NOT EXISTS idx_bdo_ctx_id     (id);

-- ── odoo_bdo_orders ───────────────────────────────────────────────────────
-- Actual columns (from OdooSyncService.php INSERT):
--   bdo_id, bdo_name, order_id, order_name, amount_total, payment_reference,
--   partner_id, customer_name, line_user_id, payment_method, webhook_delivery_id,
--   payment_status, created_at, updated_at
-- NOTE: due_date และ payment_state ไม่มีใน table นี้ (อยู่ใน odoo_bdos แทน)
ALTER TABLE odoo_bdo_orders
    ADD INDEX IF NOT EXISTS idx_bdo_orders_bdo_id         (bdo_id),
    ADD INDEX IF NOT EXISTS idx_bdo_orders_partner        (partner_id, order_id),
    ADD INDEX IF NOT EXISTS idx_bdo_orders_payment_status (payment_status),
    ADD INDEX IF NOT EXISTS idx_bdo_orders_payment_method (payment_method);

-- ── odoo_webhook_dlq ──────────────────────────────────────────────────────
-- Actual columns (from OdooWebhookHandler.php INSERT + dashboard SELECT):
--   id, delivery_id, webhook_log_id, event_type, payload, error_code,
--   error_message, retry_count, failed_at, created_at, last_retry_at,
--   resolved_at, status (added via migration_bdos_schema_fix.sql)
-- NOTE: next_retry_at ไม่มีอยู่จริง — ชื่อจริงคือ last_retry_at
-- NOTE: status ต้องรัน migration_bdos_schema_fix.sql ก่อน
ALTER TABLE odoo_webhook_dlq
    ADD INDEX IF NOT EXISTS idx_dlq_status_created  (status, created_at DESC),
    ADD INDEX IF NOT EXISTS idx_dlq_created         (created_at DESC),
    ADD INDEX IF NOT EXISTS idx_dlq_last_retry      (last_retry_at),
    ADD INDEX IF NOT EXISTS idx_dlq_webhook_log_id  (webhook_log_id);

-- ── odoo_line_users ───────────────────────────────────────────────────────
-- JOIN กับ odoo_webhooks_log เพื่อหา line_user_id จาก partner_id
ALTER TABLE odoo_line_users
    ADD INDEX IF NOT EXISTS idx_line_users_partner       (odoo_partner_id, line_user_id),
    ADD INDEX IF NOT EXISTS idx_line_users_customer_code (odoo_customer_code);

-- ── odoo_slip_uploads ─────────────────────────────────────────────────────
-- upload tracking: filter ด้วย status + เรียงตาม uploaded_at
ALTER TABLE odoo_slip_uploads
    ADD INDEX IF NOT EXISTS idx_slips_status_uploaded (status, uploaded_at DESC),
    ADD INDEX IF NOT EXISTS idx_slips_line_user       (line_user_id, uploaded_at DESC),
    ADD INDEX IF NOT EXISTS idx_slips_matched_order   (matched_order_id);

-- ── odoo_orders_summary (cache table) ────────────────────────────────────
-- ใช้ใน odoo-dashboard-local.php แต่ยังขาด index ที่ดี
ALTER TABLE odoo_orders_summary
    ADD INDEX IF NOT EXISTS idx_orders_sum_date_state    (date_order, state),
    ADD INDEX IF NOT EXISTS idx_orders_sum_customer_ref  (customer_ref(50)),
    ADD INDEX IF NOT EXISTS idx_orders_sum_line_user     (line_user_id, last_event_at DESC);

-- ── odoo_customers_cache (cache table) ───────────────────────────────────
-- ค้นหาด้วยชื่อและเบอร์โทร
ALTER TABLE odoo_customers_cache
    ADD INDEX IF NOT EXISTS idx_cust_cache_name  (customer_name(80)),
    ADD INDEX IF NOT EXISTS idx_cust_cache_phone (phone(20));
