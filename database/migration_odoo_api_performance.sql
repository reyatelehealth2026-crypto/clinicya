-- ============================================================================
-- Odoo API Performance Optimization Indexes
-- 
-- Recommended indexes to improve dashboard and webhook query performance.
-- Run these on the production database after verifying table existence.
--
-- @version 2.0.0
-- @created 2026-03-16
-- ============================================================================

-- ── odoo_webhooks_log indexes ─────────────────────────────────────────────

-- Index for time-range filtering (stats, timeline, overview queries)
-- This is the most impactful index for dashboard performance.
ALTER TABLE odoo_webhooks_log 
ADD INDEX IF NOT EXISTS idx_webhooks_processed_status (`processed_at`, `status`);

-- Composite index for customer lookup + time ordering (dashboard customer queries)
ALTER TABLE odoo_webhooks_log
ADD INDEX IF NOT EXISTS idx_webhooks_line_user_processed (`line_user_id`, `processed_at` DESC);

-- Index for order-based lookups (order timeline, order detail)
ALTER TABLE odoo_webhooks_log
ADD INDEX IF NOT EXISTS idx_webhooks_order_id (`order_id`, `processed_at` DESC);

-- Index for status-based filtering (stats, failed event lists)
ALTER TABLE odoo_webhooks_log
ADD INDEX IF NOT EXISTS idx_webhooks_status_event (`status`, `event_type`);

-- Index for delivery_id deduplication (webhook receipt)
ALTER TABLE odoo_webhooks_log
ADD INDEX IF NOT EXISTS idx_webhooks_delivery_id (`delivery_id`);

-- Generated column + index for customer.id (avoids JSON_EXTRACT in WHERE)
-- Only add if MySQL 5.7.8+ or MariaDB 10.2.7+
ALTER TABLE odoo_webhooks_log
ADD COLUMN IF NOT EXISTS `payload_customer_id` VARCHAR(50) 
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id'))) VIRTUAL,
ADD INDEX IF NOT EXISTS idx_webhooks_payload_customer_id (`payload_customer_id`);

-- Generated column for order_name (avoids JSON_EXTRACT in GROUP BY)
ALTER TABLE odoo_webhooks_log
ADD COLUMN IF NOT EXISTS `payload_order_name` VARCHAR(100) 
    GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name'))) VIRTUAL,
ADD INDEX IF NOT EXISTS idx_webhooks_payload_order_name (`payload_order_name`);


-- ── odoo_api_logs indexes ────────────────────────────────────────────────

-- Index for rate limiting check (COUNT in last minute)
ALTER TABLE odoo_api_logs
ADD INDEX IF NOT EXISTS idx_api_logs_created_at (`created_at`);


-- ── odoo_orders indexes ──────────────────────────────────────────────────

-- Composite for customer + date queries (dashboard order lists)
ALTER TABLE odoo_orders
ADD INDEX IF NOT EXISTS idx_orders_partner_updated (`partner_id`, `updated_at` DESC);

ALTER TABLE odoo_orders
ADD INDEX IF NOT EXISTS idx_orders_line_user (`line_user_id`, `updated_at` DESC);

-- Date-based lookups (today's orders overview)
ALTER TABLE odoo_orders
ADD INDEX IF NOT EXISTS idx_orders_date_order (`date_order`);

ALTER TABLE odoo_orders
ADD INDEX IF NOT EXISTS idx_orders_updated_at (`updated_at`);


-- ── odoo_invoices indexes ────────────────────────────────────────────────

ALTER TABLE odoo_invoices
ADD INDEX IF NOT EXISTS idx_invoices_partner_updated (`partner_id`, `updated_at` DESC);

ALTER TABLE odoo_invoices
ADD INDEX IF NOT EXISTS idx_invoices_order_name (`order_name`);


-- ── odoo_bdos indexes ────────────────────────────────────────────────────

ALTER TABLE odoo_bdos
ADD INDEX IF NOT EXISTS idx_bdos_payment_state (`payment_state`, `state`, `due_date`);


-- ── odoo_customer_projection indexes ─────────────────────────────────────

ALTER TABLE odoo_customer_projection
ADD INDEX IF NOT EXISTS idx_custproj_latest_order (`latest_order_at` DESC);

ALTER TABLE odoo_customer_projection
ADD INDEX IF NOT EXISTS idx_custproj_overdue (`overdue_amount`, `total_due`);


-- ── odoo_order_projection indexes ────────────────────────────────────────

ALTER TABLE odoo_order_projection
ADD INDEX IF NOT EXISTS idx_orderproj_line_user (`line_user_id`, `last_webhook_at` DESC);

ALTER TABLE odoo_order_projection
ADD INDEX IF NOT EXISTS idx_orderproj_partner (`odoo_partner_id`, `last_webhook_at` DESC);
