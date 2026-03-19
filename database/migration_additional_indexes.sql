-- ============================================================================
-- Migration: Additional Indexes Based on Query Analysis
-- Created: 2026-03-19 (Additional optimizations)
-- 
-- ใช้หลัง migration_odoo_api_performance.sql และ migration_missing_indexes.sql
-- Run: mysql -u $DB_USER -p$DB_PASS $DB_NAME < database/migration_additional_indexes.sql
-- ============================================================================

-- ============================================================================
-- Additional indexes from EXPLAIN analysis
-- ============================================================================

-- odoo_webhooks_log: Covering index for common dashboard query
-- Query: SELECT * FROM odoo_webhooks_log WHERE status = 'processed' AND event_type = 'order.created'
-- This composite index allows index-only scans for status+event_type filters
ALTER TABLE odoo_webhooks_log
ADD INDEX IF NOT EXISTS idx_webhooks_status_event_created (
    status, 
    event_type, 
    created_at DESC
);

-- odoo_notification_log: Covering index for notification dashboard
-- Query: SELECT * FROM odoo_notification_log WHERE line_account_id = X AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ALTER TABLE odoo_notification_log
ADD INDEX IF NOT EXISTS idx_notif_account_sent (
    line_account_id,
    sent_at DESC
);

-- odoo_orders: Index for customer order history (frequently used)
-- Query: SELECT * FROM odoo_orders WHERE partner_id = X ORDER BY date_order DESC
ALTER TABLE odoo_orders
ADD INDEX IF NOT EXISTS idx_orders_partner_date (
    partner_id,
    date_order DESC
);

-- odoo_invoices: Index for overdue invoices (dashboard metric)
-- Query: SELECT COUNT(*) FROM odoo_invoices WHERE state = 'posted' AND due_date < CURDATE()
ALTER TABLE odoo_invoices
ADD INDEX IF NOT EXISTS idx_invoices_state_due (
    state,
    due_date
);

-- odoo_bdos: Index for BDO aging report
-- Query: SELECT * FROM odoo_bdos WHERE state NOT IN ('cancel', 'done') ORDER BY due_date
ALTER TABLE odoo_bdos
ADD INDEX IF NOT EXISTS idx_bdos_active_due (
    state,
    due_date
);

-- odoo_slip_uploads: Covering index for slip verification queue
-- Query: SELECT id, line_user_id, amount, image_path FROM odoo_slip_uploads WHERE status = 'pending' AND slip_verified = 0
ALTER TABLE odoo_slip_uploads
ADD INDEX IF NOT EXISTS idx_slips_pending_verify (
    status,
    slip_verified,
    uploaded_at DESC
);

-- odoo_line_users: Index for partner lookup (used in webhook matching)
-- Query: SELECT * FROM odoo_line_users WHERE line_user_id = 'Uxxx' AND line_account_id = Y
ALTER TABLE odoo_line_users
ADD INDEX IF NOT EXISTS idx_line_users_account_user (
    line_account_id,
    line_user_id
);

-- ============================================================================
-- Index cleanup: Remove redundant indexes if they exist
-- (Run only after confirming the new indexes are working)
-- ============================================================================

-- If idx_webhooks_status_event exists and is redundant, drop it:
-- DROP INDEX IF EXISTS idx_webhooks_status_event ON odoo_webhooks_log;

-- If idx_notif_status_sent is redundant after idx_notif_account_sent, drop it:
-- DROP INDEX IF EXISTS idx_notif_status_sent ON odoo_notification_log;

-- ============================================================================
-- Verify all indexes
-- ============================================================================

SELECT 
    table_name,
    index_name,
    GROUP_CONCAT(column_name ORDER BY seq_in_index) as columns,
    index_type
FROM information_schema.statistics 
WHERE table_schema = DATABASE()
    AND table_name LIKE 'odoo_%'
    AND index_name LIKE 'idx_%'
GROUP BY table_name, index_name
ORDER BY table_name, index_name;
