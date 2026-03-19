-- ============================================================================
-- Query Analysis: EXPLAIN ก่อนและหลัง Index
-- ใช้แสดงว่า index จะช่วยจริงหรือไม่
-- ============================================================================

-- ตรวจสอบว่า MySQL รองรับ EXPLAIN หรือไม่
-- Run: mysql -u $DB_USER -p$DB_PASS $DB_NAME < scripts/analyze-query-plans.sql

-- ============================================================================
-- 1. odoo_webhooks_log - Critical (ใช้ 40 ครั้งใน dashboard)
-- ============================================================================

-- Query 1.1: Count today's webhooks (line 1959 ใน odoo-dashboard-api.php)
EXPLAIN
SELECT COUNT(*) 
FROM odoo_webhooks_log 
WHERE processed_at >= CURDATE() 
  AND processed_at < CURDATE() + INTERVAL 1 DAY;
-- Expected: type=range, key=idx_webhooks_processed_status
-- Without index: type=ALL (full table scan)

-- Query 1.2: Get webhooks by line_user_id + time
EXPLAIN
SELECT * FROM odoo_webhooks_log 
WHERE line_user_id = 'test123' 
ORDER BY processed_at DESC 
LIMIT 50;
-- Expected: type=ref, key=idx_webhooks_line_user_processed

-- Query 1.3: Get webhooks by order_id
EXPLAIN
SELECT * FROM odoo_webhooks_log 
WHERE order_id = 12345 
ORDER BY processed_at DESC;
-- Expected: type=ref, key=idx_webhooks_order_id

-- ============================================================================
-- 2. odoo_notification_log - Critical (11 references)
-- ============================================================================

-- Query 2.1: Today's notifications (แก้จาก DATE(sent_at) เป็น range)
EXPLAIN
SELECT COUNT(*) 
FROM odoo_notification_log 
WHERE sent_at >= CURDATE() 
  AND sent_at < CURDATE() + INTERVAL 1 DAY;
-- Expected: type=range, key=idx_notif_sent_at
-- Before fix (with DATE()): type=ALL (function prevents index usage)

-- Query 2.2: Notifications by status + time
EXPLAIN
SELECT * FROM odoo_notification_log 
WHERE status = 'sent' 
  AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY sent_at DESC;
-- Expected: type=range, key=idx_notif_status_sent

-- Query 2.3: Notifications by line_user
EXPLAIN
SELECT * FROM odoo_notification_log 
WHERE line_user_id = 'U1234567890' 
ORDER BY sent_at DESC 
LIMIT 20;
-- Expected: type=ref, key=idx_notif_line_user_sent

-- ============================================================================
-- 3. odoo_bdo_context - High (8 references)
-- ============================================================================

-- Query 3.1: Get latest context per BDO (common GROUP BY query)
EXPLAIN
SELECT bdo_id, MAX(id) as max_id 
FROM odoo_bdo_context 
GROUP BY bdo_id 
LIMIT 10;
-- Expected: type=index, key=idx_bdo_ctx_bdo_id (covering index)
-- Without index: Using temporary + Using filesort (slow)

-- Query 3.2: Get specific BDO context
EXPLAIN
SELECT * FROM odoo_bdo_context 
WHERE bdo_id = 12345 
ORDER BY id DESC 
LIMIT 1;
-- Expected: type=ref, key=idx_bdo_ctx_bdo_id

-- ============================================================================
-- 4. odoo_slip_uploads - High (8 references)
-- ============================================================================

-- Query 4.1: Pending slips
EXPLAIN
SELECT * FROM odoo_slip_uploads 
WHERE status IN ('new','pending') 
ORDER BY uploaded_at DESC 
LIMIT 50;
-- Expected: type=range, key=idx_slips_status_uploaded

-- Query 4.2: User's recent slips
EXPLAIN
SELECT * FROM odoo_slip_uploads 
WHERE line_user_id = 'U1234567890' 
ORDER BY uploaded_at DESC 
LIMIT 20;
-- Expected: type=ref, key=idx_slips_line_user

-- Query 4.3: Find slip by order
EXPLAIN
SELECT * FROM odoo_slip_uploads 
WHERE order_id = 12345;
-- Expected: type=ref, key=idx_slips_order_id

-- ============================================================================
-- 5. odoo_bdo_orders - Medium (3 references)
-- ============================================================================

-- Query 5.1: Orders in BDO
EXPLAIN
SELECT * FROM odoo_bdo_orders 
WHERE bdo_id = 12345 
ORDER BY order_id;
-- Expected: type=ref, key=idx_bdo_orders_bdo_id

-- Query 5.2: Orders by partner
EXPLAIN
SELECT * FROM odoo_bdo_orders 
WHERE partner_id = 5678 
ORDER BY order_id;
-- Expected: type=ref, key=idx_bdo_orders_partner

-- ============================================================================
-- 6. Cache Tables - Should be very fast
-- ============================================================================

-- Query 6.1: Today's orders from cache
EXPLAIN
SELECT * FROM odoo_orders_summary 
WHERE date_order = CURDATE() 
  AND state != 'cancel'
ORDER BY last_event_at DESC;
-- Expected: type=ref, key=idx_orders_sum_date_state

-- Query 6.2: Customer lookup by name
EXPLAIN
SELECT * FROM odoo_customers_cache 
WHERE customer_name LIKE '%test%' 
LIMIT 20;
-- Expected: type=range, key=idx_cust_cache_name (if using prefix)
-- Note: Wildcard at start (%test) cannot use index efficiently

-- Query 6.3: Customer by phone
EXPLAIN
SELECT * FROM odoo_customers_cache 
WHERE phone = '0812345678';
-- Expected: type=ref, key=idx_cust_cache_phone

-- ============================================================================
-- 7. Verification: Check all new indexes exist
-- ============================================================================

SELECT 
    'Index Verification' as check_type,
    table_name,
    index_name,
    CASE 
        WHEN index_name LIKE 'idx_webhooks%' THEN 'Migration 1'
        WHEN index_name LIKE 'idx_notif%' THEN 'Migration 2'
        WHEN index_name LIKE 'idx_bdo%' THEN 'Migration 2'
        WHEN index_name LIKE 'idx_slips%' THEN 'Migration 2'
        WHEN index_name LIKE 'idx_cust%' THEN 'Migration 2'
        WHEN index_name LIKE 'idx_orders_sum%' THEN 'Migration 2'
        ELSE 'Other'
    END as source
FROM information_schema.statistics 
WHERE table_schema = DATABASE()
    AND table_name LIKE 'odoo_%'
    AND index_name LIKE 'idx_%'
ORDER BY source, table_name, index_name;

-- ============================================================================
-- 8. Performance Summary Query
-- ============================================================================

SELECT 
    'Performance Summary' as report,
    table_name,
    COUNT(DISTINCT index_name) as index_count,
    CASE 
        WHEN table_name = 'odoo_webhooks_log' THEN '40 refs in dashboard'
        WHEN table_name = 'odoo_notification_log' THEN '11 refs'
        WHEN table_name = 'odoo_line_users' THEN '13 refs'
        WHEN table_name IN ('odoo_slip_uploads','odoo_bdos','odoo_bdo_context') THEN '8 refs each'
        ELSE 'Medium usage'
    END as importance
FROM information_schema.statistics 
WHERE table_schema = DATABASE()
    AND table_name IN (
        'odoo_webhooks_log',
        'odoo_notification_log',
        'odoo_line_users',
        'odoo_slip_uploads',
        'odoo_bdos',
        'odoo_bdo_context',
        'odoo_webhook_dlq',
        'odoo_orders',
        'odoo_invoices',
        'odoo_bdo_orders',
        'odoo_orders_summary',
        'odoo_customers_cache'
    )
    AND index_name != 'PRIMARY'
GROUP BY table_name
ORDER BY 
    FIELD(table_name, 
        'odoo_webhooks_log',
        'odoo_notification_log', 
        'odoo_line_users',
        'odoo_slip_uploads',
        'odoo_bdos',
        'odoo_bdo_context'
    );
