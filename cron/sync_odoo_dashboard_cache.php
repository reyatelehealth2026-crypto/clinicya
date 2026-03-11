<?php
/**
 * Odoo Dashboard Cache Sync Job
 * Populates local cache tables from webhook log data
 * 
 * Run this via cron every 5 minutes:
 *   */5 * * * * php /path/to/cron/sync_odoo_dashboard_cache.php
 * 
 * Or run manually:
 *   php sync_odoo_dashboard_cache.php [full|incremental|orders|customers|invoices|slips|stats]
 * 
 * @version 1.0.0
 * @created 2026-03-11
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// CLI mode detection
$isCli = php_sapi_name() === 'cli';
$jobType = $isCli ? ($argv[1] ?? 'incremental') : 'full';
$lineAccountId = $isCli ? ($argv[2] ?? null) : ($_GET['bot_id'] ?? null);

// Logging function
function logMessage($msg, $level = 'INFO') {
    global $isCli;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] [{$level}] {$msg}";
    
    if ($isCli) {
        echo $line . PHP_EOL;
    }
    
    // Also log to file
    $logFile = __DIR__ . '/../logs/odoo_sync_' . date('Y-m-d') . '.log';
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Error handler
set_error_handler(function($severity, $message, $file, $line) {
    logMessage("{$message} in {$file}:{$line}", 'ERROR');
    return true;
});

// Start sync
$startTime = microtime(true);
$jobId = null;

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if required tables exist
    $tables = ['odoo_orders_summary', 'odoo_customers_cache', 'odoo_invoices_cache', 'odoo_slips_cache', 'odoo_order_events'];
    $missing = [];
    foreach ($tables as $table) {
        $exists = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount() > 0;
        if (!$exists) $missing[] = $table;
    }
    
    if (!empty($missing)) {
        logMessage('Missing tables: ' . implode(', ', $missing), 'ERROR');
        logMessage('Please run migration: database/migration_odoo_dashboard_cache.sql', 'ERROR');
        exit(1);
    }
    
    // Log job start
    $stmt = $db->prepare("INSERT INTO odoo_sync_log (job_type, started_at, status, triggered_by, line_account_id) VALUES (?, NOW(), 'running', ?, ?)");
    $stmt->execute([$jobType, $isCli ? 'cron' : 'manual', $lineAccountId]);
    $jobId = $db->lastInsertId();
    
    logMessage("Starting sync job #{$jobId} type={$jobType}");
    
    $stats = [
        'orders' => ['inserted' => 0, 'updated' => 0],
        'customers' => ['inserted' => 0, 'updated' => 0],
        'invoices' => ['inserted' => 0, 'updated' => 0],
        'events' => ['inserted' => 0]
    ];
    
    // ============================================
    // SYNC ORDERS
    // ============================================
    if ($jobType === 'full' || $jobType === 'orders' || $jobType === 'incremental') {
        logMessage('Syncing orders from webhook log...');
        
        // Get last sync time for incremental
        $lastSync = null;
        if ($jobType === 'incremental') {
            $lastSync = $db->query("SELECT MAX(last_event_at) FROM odoo_orders_summary" . ($lineAccountId ? " WHERE line_account_id = {$lineAccountId}" : ""))->fetchColumn();
        }
        
        // Build base subquery from webhook log
        $processedAtColumn = resolveWebhookTimeColumn($db);
        $processedAtExpr = $processedAtColumn ?: 'NOW()';
        
        $where = "status = 'success'";
        $params = [];
        
        if ($lastSync) {
            $where .= " AND {$processedAtExpr} > ?";
            $params[] = $lastSync;
        }
        if ($lineAccountId) {
            $where .= " AND line_account_id = ?";
            $params[] = $lineAccountId;
        }
        
        // Get order snapshot from webhooks
        $snapshotSql = "
            SELECT
                order_key,
                MIN({$processedAtExpr}) as first_event_at,
                MAX({$processedAtExpr}) as last_event_at,
                MAX(CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))) as amount_total,
                MAX(CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_tax')), ''), '0') AS DECIMAL(14,2))) as amount_tax,
                MAX(CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_untaxed')), ''), '0') AS DECIMAL(14,2))) as amount_untaxed,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.currency')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as currency,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as customer_id,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as partner_id,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as customer_ref,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as customer_name,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.id')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as salesperson_id,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.name')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as salesperson_name,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as state,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.delivery_type')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as delivery_type,
                SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.payment_status')), '') ORDER BY {$processedAtExpr} DESC), ',', 1) as payment_status,
                MAX(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')), '') AS UNSIGNED)) as odoo_order_id,
                MAX(line_user_id) as line_user_id,
                MAX(line_account_id) as line_account_id,
                MIN(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.created_at')), '') AS DATETIME)) as created_at_odoo,
                MIN(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.date_order')), '') AS DATE)) as date_order,
                MAX(CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.expected_delivery_date')), '') AS DATE)) as expected_delivery_date
            FROM odoo_webhooks_log
            WHERE {$where} AND order_key IS NOT NULL AND order_key != ''
            GROUP BY order_key
        ";
        
        $stmt = $db->prepare($snapshotSql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        logMessage("Found " . count($orders) . " orders to sync");
        
        // Upsert orders
        $upsert = $db->prepare("
            INSERT INTO odoo_orders_summary (
                order_key, odoo_order_id, customer_id, partner_id, customer_name, customer_ref,
                salesperson_id, salesperson_name, amount_total, amount_tax, amount_untaxed, currency,
                state, delivery_type, payment_status, line_user_id, line_account_id,
                first_event_at, last_event_at, created_at_odoo, date_order, expected_delivery_date,
                synced_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                odoo_order_id = VALUES(odoo_order_id),
                customer_id = VALUES(customer_id),
                partner_id = VALUES(partner_id),
                customer_name = VALUES(customer_name),
                customer_ref = VALUES(customer_ref),
                salesperson_id = VALUES(salesperson_id),
                salesperson_name = VALUES(salesperson_name),
                amount_total = VALUES(amount_total),
                amount_tax = VALUES(amount_tax),
                amount_untaxed = VALUES(amount_untaxed),
                currency = VALUES(currency),
                state = VALUES(state),
                delivery_type = VALUES(delivery_type),
                payment_status = VALUES(payment_status),
                line_user_id = VALUES(line_user_id),
                line_account_id = VALUES(line_account_id),
                last_event_at = VALUES(last_event_at),
                synced_at = NOW()
        ");
        
        foreach ($orders as $o) {
            $upsert->execute([
                $o['order_key'],
                $o['odoo_order_id'],
                $o['customer_id'],
                $o['partner_id'],
                $o['customer_name'],
                $o['customer_ref'],
                $o['salesperson_id'],
                $o['salesperson_name'],
                $o['amount_total'] ?: 0,
                $o['amount_tax'] ?: 0,
                $o['amount_untaxed'] ?: 0,
                $o['currency'] ?: 'THB',
                $o['state'] ?: 'draft',
                $o['delivery_type'],
                $o['payment_status'],
                $o['line_user_id'],
                $o['line_account_id'],
                $o['first_event_at'],
                $o['last_event_at'],
                $o['created_at_odoo'],
                $o['date_order'],
                $o['expected_delivery_date']
            ]);
            
            if ($upsert->rowCount() > 0) {
                if (strpos($upsert->queryString, 'INSERT') !== false) {
                    $stats['orders']['updated']++;
                } else {
                    $stats['orders']['inserted']++;
                }
            }
        }
        
        // Sync order events
        logMessage('Syncing order events...');
        
        $eventsWhere = "event_type LIKE 'sale.order.%' OR event_type LIKE 'order.%' OR event_type LIKE 'delivery.%'";
        if ($lastSync) {
            $eventsWhere .= " AND {$processedAtExpr} > '{$lastSync}'";
        }
        if ($lineAccountId) {
            $eventsWhere .= " AND line_account_id = {$lineAccountId}";
        }
        
        $eventsSql = "
            INSERT INTO odoo_order_events (
                order_key, event_type, event_category, status, old_state, new_state,
                payload_summary, webhook_log_id, processed_at
            )
            SELECT 
                order_key,
                event_type,
                CASE 
                    WHEN event_type LIKE 'sale.order.%' THEN 'order'
                    WHEN event_type LIKE 'order.%' THEN 'order'
                    WHEN event_type LIKE 'delivery.%' THEN 'delivery'
                    WHEN event_type LIKE 'invoice.%' THEN 'invoice'
                    WHEN event_type LIKE 'payment.%' THEN 'payment'
                    ELSE 'other'
                END as event_category,
                status,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.old_state')),
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')),
                JSON_OBJECT(
                    'amount_total', JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')),
                    'customer_name', JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')),
                    'state', JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state'))
                ),
                id,
                {$processedAtExpr}
            FROM odoo_webhooks_log
            WHERE {$eventsWhere}
            AND order_key IS NOT NULL
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                new_state = VALUES(new_state),
                processed_at = VALUES(processed_at)
        ";
        
        $eventsResult = $db->exec($eventsSql);
        $stats['events']['inserted'] = $eventsResult > 0 ? $eventsResult : 0;
        
        logMessage("Orders: +{$stats['orders']['inserted']} new, ~{$stats['orders']['updated']} updated");
    }
    
    // ============================================
    // SYNC CUSTOMERS
    // ============================================
    if ($jobType === 'full' || $jobType === 'customers' || $jobType === 'incremental') {
        logMessage('Syncing customers from orders...');
        
        // Aggregate customer data from orders_summary
        $custWhere = $lineAccountId ? "WHERE line_account_id = {$lineAccountId}" : "";
        
        $custSql = "
            INSERT INTO odoo_customers_cache (
                customer_id, partner_id, customer_name, customer_ref,
                salesperson_id, salesperson_name,
                orders_count_total, orders_count_30d, spend_total, spend_30d,
                first_order_at, latest_order_at, line_user_id, line_account_id,
                synced_at
            )
            SELECT 
                COALESCE(customer_id, partner_id) as cust_id,
                partner_id,
                MAX(customer_name) as name,
                MAX(customer_ref) as ref,
                MAX(salesperson_id) as sp_id,
                MAX(salesperson_name) as sp_name,
                COUNT(*) as total_orders,
                SUM(CASE WHEN date_order >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as orders_30d,
                SUM(amount_total) as total_spend,
                SUM(CASE WHEN date_order >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount_total ELSE 0 END) as spend_30d,
                MIN(first_event_at) as first_order,
                MAX(last_event_at) as latest_order,
                MAX(line_user_id) as line_uid,
                MAX(line_account_id) as line_aid
            FROM odoo_orders_summary
            {$custWhere}
            GROUP BY COALESCE(customer_id, partner_id), partner_id
            ON DUPLICATE KEY UPDATE
                customer_name = VALUES(customer_name),
                customer_ref = VALUES(customer_ref),
                salesperson_id = VALUES(salesperson_id),
                salesperson_name = VALUES(salesperson_name),
                orders_count_total = VALUES(orders_count_total),
                orders_count_30d = VALUES(orders_count_30d),
                spend_total = VALUES(spend_total),
                spend_30d = VALUES(spend_30d),
                first_order_at = VALUES(first_order_at),
                latest_order_at = VALUES(latest_order_at),
                line_user_id = VALUES(line_user_id),
                synced_at = NOW()
        ";
        
        $custResult = $db->exec($custSql);
        $stats['customers']['updated'] = $custResult > 0 ? $custResult : 0;
        
        // Count total customers
        $custCount = $db->query("SELECT COUNT(*) FROM odoo_customers_cache" . ($lineAccountId ? " WHERE line_account_id = {$lineAccountId}" : ""))->fetchColumn();
        logMessage("Customers: {$custCount} total in cache");
    }
    
    // ============================================
    // SYNC INVOICES
    // ============================================
    if ($jobType === 'full' || $jobType === 'invoices') {
        logMessage('Syncing invoices from webhook log...');
        
        $invWhere = "event_type LIKE 'invoice.%' AND status = 'success'";
        if ($lineAccountId) {
            $invWhere .= " AND line_account_id = {$lineAccountId}";
        }
        
        $invSql = "
            INSERT INTO odoo_invoices_cache (
                invoice_number, invoice_id, order_key, customer_id, partner_id, customer_name,
                amount_total, amount_residual, state, invoice_date, due_date,
                is_overdue, days_overdue, line_user_id, line_account_id, synced_at
            )
            SELECT 
                COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_number')), ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.name')), ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.number')), '')
                ) as inv_num,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.id')), '') AS UNSIGNED) as inv_id,
                order_key,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')),
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')),
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')),
                CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2)),
                CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_residual')), ''), 
                              NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2)),
                CASE event_type
                    WHEN 'invoice.paid' THEN 'paid'
                    WHEN 'invoice.overdue' THEN 'overdue'
                    WHEN 'invoice.posted' THEN 'posted'
                    ELSE 'open'
                END as state,
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_date')), '') AS DATE),
                CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.due_date')), '') AS DATE),
                CASE WHEN event_type = 'invoice.overdue' THEN 1 ELSE 0 END as is_overdue,
                CASE WHEN event_type = 'invoice.overdue' 
                    THEN DATEDIFF(CURDATE(), CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.due_date')), '') AS DATE))
                    ELSE 0 
                END as days_overdue,
                line_user_id,
                line_account_id,
                NOW()
            FROM odoo_webhooks_log
            WHERE {$invWhere}
            HAVING inv_num IS NOT NULL
            ON DUPLICATE KEY UPDATE
                amount_residual = VALUES(amount_residual),
                state = VALUES(state),
                is_overdue = VALUES(is_overdue),
                days_overdue = VALUES(days_overdue),
                synced_at = NOW()
        ";
        
        $invResult = $db->exec($invSql);
        $stats['invoices']['updated'] = $invResult > 0 ? $invResult : 0;
        
        // Recalculate overdue status for all invoices
        $db->exec("
            UPDATE odoo_invoices_cache 
            SET is_overdue = 1, days_overdue = DATEDIFF(CURDATE(), due_date)
            WHERE state IN ('open', 'posted') AND due_date < CURDATE() AND is_overdue = 0
        ");
        
        logMessage("Invoices: ~{$stats['invoices']['updated']} updated");
    }
    
    // ============================================
    // UPDATE CACHE METADATA
    // ============================================
    $db->prepare("
        INSERT INTO odoo_dashboard_cache_meta (cache_key, cache_type, last_synced_at, is_dirty)
        VALUES ('orders_summary', 'summary', NOW(), 0),
               ('customers_cache', 'list', NOW(), 0),
               ('invoices_cache', 'list', NOW(), 0)
        ON DUPLICATE KEY UPDATE last_synced_at = VALUES(last_synced_at), is_dirty = 0
    ")->execute();
    
    // ============================================
    // COMPLETE JOB
    // ============================================
    $duration = round((microtime(true) - $startTime) * 1000);
    $totalRecords = $stats['orders']['inserted'] + $stats['orders']['updated'] + 
                    $stats['customers']['inserted'] + $stats['customers']['updated'] +
                    $stats['invoices']['inserted'] + $stats['invoices']['updated'] +
                    $stats['events']['inserted'];
    
    $db->prepare("
        UPDATE odoo_sync_log 
        SET completed_at = NOW(), 
            status = 'success',
            records_processed = ?,
            records_inserted = ?,
            records_updated = ?,
            execution_duration_ms = ?
        WHERE id = ?
    ")->execute([
        $totalRecords,
        $stats['orders']['inserted'] + $stats['customers']['inserted'] + $stats['invoices']['inserted'],
        $stats['orders']['updated'] + $stats['customers']['updated'] + $stats['invoices']['updated'],
        $duration,
        $jobId
    ]);
    
    logMessage("Sync completed in {$duration}ms. Total: {$totalRecords} records");
    logMessage("Stats: " . json_encode($stats));
    
    if (!$isCli) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'job_id' => $jobId,
            'duration_ms' => $duration,
            'stats' => $stats
        ]);
    }
    
} catch (Exception $e) {
    logMessage('ERROR: ' . $e->getMessage(), 'ERROR');
    
    if ($jobId) {
        $db->prepare("
            UPDATE odoo_sync_log 
            SET completed_at = NOW(), status = 'failed', error_message = ?
            WHERE id = ?
        ")->execute([$e->getMessage(), $jobId]);
    }
    
    if (!$isCli) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    exit(1);
}

// Helper functions
function resolveWebhookTimeColumn($db) {
    try {
        $cols = $db->query("SHOW COLUMNS FROM odoo_webhooks_log LIKE 'processed_at'")->fetchAll();
        if (!empty($cols)) return 'processed_at';
        $cols = $db->query("SHOW COLUMNS FROM odoo_webhooks_log LIKE 'created_at'")->fetchAll();
        if (!empty($cols)) return 'created_at';
    } catch (Exception $e) {
        // ignore
    }
    return 'id';
}
