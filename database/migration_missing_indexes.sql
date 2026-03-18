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
-- JOIN กับ odoo_bdo_context และ filter ด้วย payment_state / state
ALTER TABLE odoo_bdo_orders
    ADD INDEX IF NOT EXISTS idx_bdo_orders_bdo_id  (bdo_id),
    ADD INDEX IF NOT EXISTS idx_bdo_orders_partner (partner_id, due_date),
    ADD INDEX IF NOT EXISTS idx_bdo_orders_payment (payment_state, state);

-- ── odoo_webhook_dlq ──────────────────────────────────────────────────────
-- retry queue: ดึง rows ที่ next_retry_at ถึงกำหนดแล้ว
ALTER TABLE odoo_webhook_dlq
    ADD INDEX IF NOT EXISTS idx_dlq_status_next (status, next_retry_at),
    ADD INDEX IF NOT EXISTS idx_dlq_created     (created_at);

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
