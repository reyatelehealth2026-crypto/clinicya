-- ============================================================================
-- CORRECTED INDEX MIGRATION (Based on actual schema)
-- Run after migration_comprehensive_indexes.sql to add missing indexes
-- ============================================================================

-- Fix odoo_webhooks_log - use actual column names (processed_at, received_at)
ALTER TABLE odoo_webhooks_log
ADD INDEX IF NOT EXISTS idx_webhooks_processed_at (processed_at),
ADD INDEX IF NOT EXISTS idx_webhooks_received_at (received_at),
ADD INDEX IF NOT EXISTS idx_webhooks_status_processed (status, processed_at DESC),
ADD INDEX IF NOT EXISTS idx_webhooks_status_received (status, received_at DESC),
ADD INDEX IF NOT EXISTS idx_webhooks_line_user_processed (line_user_id, processed_at DESC),
ADD INDEX IF NOT EXISTS idx_webhooks_line_user_received (line_user_id, received_at DESC),
ADD INDEX IF NOT EXISTS idx_webhooks_order_processed (order_id, processed_at DESC),
ADD INDEX IF NOT EXISTS idx_webhooks_event_processed (event_type, processed_at DESC);

-- Fix odoo_invoices - use invoice_date instead of date_invoice
ALTER TABLE odoo_invoices
ADD INDEX IF NOT EXISTS idx_invoices_partner_invoice (partner_id, invoice_date DESC),
ADD INDEX IF NOT EXISTS idx_invoices_state_due (state, due_date),
ADD INDEX IF NOT EXISTS idx_invoices_invoice_date (invoice_date, state);

-- Fix odoo_customer_projection - check available columns
-- Note: partner_id doesn't exist, use line_user_id or other key
ALTER TABLE odoo_customer_projection
ADD INDEX IF NOT EXISTS idx_custproj_latest_order (latest_order_at DESC),
ADD INDEX IF NOT EXISTS idx_custproj_line_user (line_user_id, latest_order_at DESC);

-- Fix odoo_slip_uploads - ensure all indexes exist
ALTER TABLE odoo_slip_uploads
ADD INDEX IF NOT EXISTS idx_slips_status_uploaded (status, uploaded_at DESC),
ADD INDEX IF NOT EXISTS idx_slips_status_verified (status, slip_verified, uploaded_at DESC),
ADD INDEX IF NOT EXISTS idx_slips_bdo_status (bdo_id, status, uploaded_at DESC);

-- Additional useful indexes discovered from schema
ALTER TABLE odoo_webhooks_log
ADD INDEX IF NOT EXISTS idx_webhooks_processing_latency (processing_started_at, process_latency_ms),
ADD INDEX IF NOT EXISTS idx_webhooks_attempt_status (attempt_count, status);

-- Index for webhook status monitoring
ALTER TABLE odoo_webhooks_log
ADD INDEX IF NOT EXISTS idx_webhooks_status_latency (status, process_latency_ms);
