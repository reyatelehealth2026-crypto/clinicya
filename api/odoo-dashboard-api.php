<?php
/**
 * Odoo Webhooks Dashboard API
 * 
 * Provides data for the webhook monitoring dashboard.
 * 
 * Actions:
 * - list: Get recent webhook logs with filters
 * - stats: Get summary statistics
 * - detail: Get single webhook detail by ID
 * - order_timeline: Get all events for a specific order
 * - customer_list: Get paginated customer list (projection + webhook fallback)
 * - invoice_list: Get invoice events per customer from webhook log
 * - notification_log: Get notification audit log stats and records
 * 
 * @version 1.1.0
 * @created 2026-02-14
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/odoo-dashboard-functions.php';
require_once __DIR__ . '/../classes/BdoSlipContract.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    // Accept both GET and POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_GET;
    }

    $action = trim((string) ($input['action'] ?? ''));
    if ($action === '') {
        // Keep bare endpoint calls fast so health/reachability checks don't time out.
        $action = 'health';
    }

    $cacheTtls = [
        'stats' => 60,
        'order_grouped_today' => 60,
        'customer_list' => 60,
        'invoice_list' => 60,
        'order_list' => 60,
        'notification_log' => 60,
        'salesperson_list' => 300,
        'overview_today' => 45,
        'customer_full_detail' => 45,
        'overview_combined' => 45,
        'odoo_orders' => 30,
        'odoo_invoices' => 30,
        'odoo_slips' => 30,
        'odoo_bdo_list_api' => 30,
        'customer_detail' => 45,
        'customer_360' => 30,
        'pending_bdo_orders' => 60,
        'slip_center_bdo_overview' => 30,
        'slip_center_customer_detail' => 20,
        'activity_log_list' => 30,
        'webhook_stats_mini' => 30,
        'dlq_stats' => 30,
    ];
    $cacheKey = null;
    $cacheTtl = null;
    if (isset($cacheTtls[$action])) {
        $cacheTtl = (int) $cacheTtls[$action];
        $cacheKey = dashboardApiBuildCacheKey($action, $input);
        $cachedResult = dashboardApiCacheGet($cacheKey, $cacheTtl);
        if ($cachedResult !== null) {
            echo json_encode([
                'success' => true,
                'data' => $cachedResult,
                'meta' => ['cache' => 'hit']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    try {
        switch ($action) {
            case 'health':
                $result = [
                    'status' => 'ok',
                    'service' => 'odoo-webhooks-dashboard',
                    'timestamp' => date('c')
                ];
                break;
            case 'stats':
                $result = getStats($db);
                break;
            case 'list':
                $result = getWebhookList($db, $input);
                break;
            case 'detail':
                $result = getWebhookDetail($db, $input);
                break;
            case 'notification_log':
                $result = getNotificationLog($db, $input);
                break;
            case 'customer_list':
                $result = getCustomerList($db, $input);
                break;
            case 'order_grouped_today':
                $result = getOrderGroupedToday($db, $input);
                break;
            case 'salesperson_list':
                $result = getSalespersonList($db);
                break;
            case 'invoice_list':
                $result = getInvoiceList($db, $input);
                break;
            case 'order_list':
                $result = getOrderList($db, $input);
                break;
            case 'customer_detail':
                $result = getCustomerDetail($db, $input);
                break;
            case 'daily_summary_preview':
                $result = getDailySummaryPreview($db);
                break;
            case 'send_daily_summary':
                $result = sendDailySummary($db, $input['user_ids'] ?? []);
                break;
            case 'order_timeline':
                $result = getOrderTimeline($db, $input);
                break;
            case 'odoo_orders':
                $result = getOdooOrders($db, $input);
                break;
            case 'odoo_invoices':
                $result = getOdooInvoices($db, $input);
                break;
            case 'odoo_slips':
                $result = getOdooSlips($db, $input);
                break;
            case 'customer_360':
                $result = getCustomer360($db, $input);
                break;
            case 'overview_today':
                $result = getOverviewToday($db);
                break;
            case 'pending_bdo_orders':
                $result = getPendingBdoOrdersApi($db, $input);
                break;
            case 'slip_center_customer_detail':
                $result = getSlipCenterCustomerDetail($db, $input);
                break;
            case 'slip_center_bdo_overview':
                $result = getSlipCenterBdoOverview($db, $input);
                break;
            case 'slip_center_bdo_global':
                $result = getSlipCenterBdoGlobal($db, $input);
                break;
            case 'activity_log_list':
                $result = activityLogList($db, $input);
                break;
            case 'order_status_override':
                $result = orderStatusOverride($db, $input);
                break;
            case 'order_note_add':
                $result = orderNoteAdd($db, $input);
                break;
            case 'order_notes_list':
                $result = orderNotesList($db, $input);
                break;
            case 'customer_lookup':
                $result = getCustomerLookup($db, $input);
                break;
            case 'invoice_lookup':
                $result = getInvoiceLookup($db, $input);
                break;
            case 'webhook_stats_mini':
                $result = getWebhookStatsMini($db, $input);
                break;
            case 'dlq_list':
                $result = getDlqList($db, $input);
                break;
            case 'dlq_retry':
                $result = retryDlqItem($db, $input);
                break;
            case 'dlq_stats':
                $result = getDlqStats($db);
                break;
            case 'odoo_bdo_list_api':
                $result = getBdoListLive($db, $input);
                break;
            case 'bdo_detail':
            case 'bdo_detail_live':
            case 'odoo_bdo_detail_api':
                $result = getBdoDetailLive($db, $input);
                break;
            case 'slip_match_bdo':
            case 'odoo_slip_match_api':
                $result = slipMatchBdo($db, $input);
                break;
            case 'slip_unmatch':
            case 'odoo_slip_unmatch_api':
                $result = slipUnmatch($db, $input);
                break;
            case 'customer_full_detail':
                $result = getCustomerFullDetail($db, $input);
                break;
            case 'send_bdo_payment_notification':
                $result = sendBdoPaymentNotification($db, $input);
                break;
            case 'overview_combined':
                $result = getOverviewCombined($db, $input);
                break;
            case 'statement_pdf':
            case 'odoo_bdo_statement_pdf':
                streamStatementPdf($db, $input);
                exit; // PDF streams directly, no JSON wrapper
            default:
                throw new Exception('Unknown action: ' . $action);
        }
    } catch (Exception $actionException) {
        if ($cacheKey !== null) {
            $staleResult = dashboardApiCacheGetStale($cacheKey, defined('ODOO_DASHBOARD_STALE_TTL') ? (int) ODOO_DASHBOARD_STALE_TTL : 300);
            if ($staleResult !== null) {
                echo json_encode([
                    'success' => true,
                    'data' => $staleResult,
                    'meta' => [
                        'cache' => 'stale',
                        'warning' => $actionException->getMessage()
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        throw $actionException;
    }

    if ($cacheKey !== null && dashboardApiShouldCache($action, $input, $result)) {
        dashboardApiCacheSet($cacheKey, $result);
    }

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * Get summary statistics
 */
function getStats($db)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';
    $hasLatency = hasWebhookColumn($db, 'process_latency_ms');
    $hasRetryCount = hasWebhookColumn($db, 'retry_count');

    // All-time counts: full-table scan but no per-row DATE() overhead.
    // "today" metrics are fetched separately with a range predicate (see below).
    $latencySelect = $hasLatency
        ? "ROUND(AVG(CASE WHEN process_latency_ms IS NOT NULL THEN process_latency_ms END), 2) as avg_latency_ms,"
        : "NULL as avg_latency_ms,";
    $retriedSelect = $hasRetryCount
        ? "SUM(IF(COALESCE(retry_count, 0) > 0, 1, 0)) as retried_total,"
        : "0 as retried_total,";

    $agg = $db->query("
        SELECT
            COUNT(*) as total,
            SUM(IF(status = 'success', 1, 0)) as success,
            SUM(IF(status = 'failed', 1, 0)) as failed,
            SUM(IF(status = 'received', 1, 0)) as received,
            SUM(IF(status = 'processing', 1, 0)) as processing,
            SUM(IF(status = 'retry', 1, 0)) as retry_cnt,
            SUM(IF(status = 'dead_letter', 1, 0)) as dead_letter,
            SUM(IF(status = 'duplicate', 1, 0)) as duplicate,
            {$latencySelect}
            {$retriedSelect}
            MAX({$processedAtExpr}) as last_webhook
        FROM odoo_webhooks_log
    ")->fetch(PDO::FETCH_ASSOC);

    // Today metrics: scoped range scan — allows MySQL to use index on the timestamp column.
    $today        = 0;
    $uniqueOrders = 0;
    $notified     = 0;
    if ($processedAtColumn) {
        $todayRow = $db->query("
            SELECT
                COUNT(*) as today,
                COUNT(DISTINCT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), CAST(order_id AS CHAR))) as unique_orders_today,
                SUM(IF(line_user_id IS NOT NULL, 1, 0)) as notified_today
            FROM odoo_webhooks_log
            WHERE {$processedAtColumn} >= CURDATE()
              AND {$processedAtColumn} < CURDATE() + INTERVAL 1 DAY
        ")->fetch(PDO::FETCH_ASSOC);
        $today        = (int) ($todayRow['today'] ?? 0);
        $uniqueOrders = (int) ($todayRow['unique_orders_today'] ?? 0);
        $notified     = (int) ($todayRow['notified_today'] ?? 0);
    }

    $total      = (int) ($agg['total'] ?? 0);
    $success    = (int) ($agg['success'] ?? 0);
    $failed     = (int) ($agg['failed'] ?? 0);
    $received   = (int) ($agg['received'] ?? 0);
    $processing = (int) ($agg['processing'] ?? 0);
    $retry      = (int) ($agg['retry_cnt'] ?? 0);
    $deadLetter = (int) ($agg['dead_letter'] ?? 0);
    $duplicate  = (int) ($agg['duplicate'] ?? 0);
    $avgLatencyMs  = $agg['avg_latency_ms'] !== null ? (float) $agg['avg_latency_ms'] : null;
    $retriedTotal  = (int) ($agg['retried_total'] ?? 0);
    $lastWebhook   = $agg['last_webhook'] ?? null;

    $dlqTotal = tableExists($db, 'odoo_webhook_dlq')
        ? $db->query("SELECT COUNT(*) FROM odoo_webhook_dlq")->fetchColumn()
        : 0;

    // Scope failed-events to last 90 days to avoid full historical scan
    $failedWindow = $processedAtColumn
        ? "AND {$processedAtColumn} >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)"
        : "";
    $topFailedEvents = $db->query("
        SELECT event_type, COUNT(*) as count
        FROM odoo_webhooks_log
        WHERE status IN ('failed', 'retry', 'dead_letter') {$failedWindow}
        GROUP BY event_type
        ORDER BY count DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Events by type (today) — range predicate, index-friendly
    $todayWhere = $processedAtColumn
        ? "WHERE {$processedAtColumn} >= CURDATE() AND {$processedAtColumn} < CURDATE() + INTERVAL 1 DAY"
        : "WHERE 1=0";
    $stmt = $db->query("
        SELECT event_type, COUNT(*) as count
        FROM odoo_webhooks_log
        {$todayWhere}
        GROUP BY event_type
        ORDER BY count DESC
    ");
    $eventsByType = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Hourly distribution (last 24h)
    $hourly = $db->query("
        SELECT HOUR({$processedAtExpr}) as hour, COUNT(*) as count
        FROM odoo_webhooks_log
        WHERE {$processedAtExpr} >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY HOUR({$processedAtExpr})
        ORDER BY hour
    ")->fetchAll(PDO::FETCH_ASSOC);

    return [
        'total' => (int) $total,
        'today' => (int) $today,
        'success' => (int) $success,
        'failed' => (int) $failed,
        'received' => (int) $received,
        'processing' => (int) $processing,
        'retry' => (int) $retry,
        'dead_letter' => (int) $deadLetter,
        'duplicate' => (int) $duplicate,
        'avg_latency_ms' => $avgLatencyMs !== null ? (float) $avgLatencyMs : null,
        'retried_total' => (int) $retriedTotal,
        'dlq_total' => (int) $dlqTotal,
        'unique_orders_today' => (int) $uniqueOrders,
        'notified_today' => (int) $notified,
        'last_webhook' => $lastWebhook,
        'events_by_type' => $eventsByType,
        'top_failed_events' => $topFailedEvents,
        'hourly_distribution' => $hourly
    ];
}

/**
 * Get webhook list with filters
 */
function getWebhookList($db, $input)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';
    $orderByExpr = $processedAtColumn ?: '`id`';
    $hasRetryCount = hasWebhookColumn($db, 'retry_count');
    $hasLatency = hasWebhookColumn($db, 'process_latency_ms');
    $hasErrorCode = hasWebhookColumn($db, 'last_error_code');

    $limit = min((int) ($input['limit'] ?? 50), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $eventType = $input['event_type'] ?? null;
    $status = $input['status'] ?? null;
    $search = $input['search'] ?? null;
    $dateFrom = $input['date_from'] ?? null;
    $dateTo = $input['date_to'] ?? null;
    $noDateScope = !empty($input['no_date_scope']);

    // Default to today if no date filters and no explicit opt-out
    $dateScoped = false;
    if (!$dateFrom && !$dateTo && !$noDateScope && $processedAtColumn) {
        $dateFrom = date('Y-m-d');
        $dateScoped = true;
    }

    $where = [];
    $params = [];

    if ($eventType) {
        $where[] = "event_type = ?";
        $params[] = $eventType;
    }

    if ($status) {
        $where[] = "status = ?";
        $params[] = $status;
    }

    if ($search) {
        $where[] = "(delivery_id LIKE ? OR payload LIKE ? OR CAST(order_id AS CHAR) LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    if ($dateFrom && $processedAtColumn) {
        $where[] = "{$processedAtColumn} >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo && $processedAtColumn) {
        $where[] = "{$processedAtColumn} <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Get total count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_webhooks_log {$whereClause}");
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();

    // Get records
    $sql = "
        SELECT id, delivery_id, event_type, status, error_message, 
               line_user_id, order_id, {$processedAtExpr} as processed_at,
               " . ($hasErrorCode ? "last_error_code" : "NULL") . " as last_error_code,
               " . ($hasRetryCount ? "retry_count" : "0") . " as retry_count,
               " . ($hasLatency ? "process_latency_ms" : "NULL") . " as process_latency_ms,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) as order_name,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')) as customer_name,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')) as customer_ref,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')) as customer_id,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')) as new_state,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')) as new_state_display,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.old_state_display')) as old_state_display,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')) as amount_total,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')) as customer_line_user_id
        FROM odoo_webhooks_log 
        {$whereClause}
        ORDER BY {$orderByExpr} DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get available event types for filter
    $eventTypes = $db->query("
        SELECT DISTINCT event_type
        FROM odoo_webhooks_log
        WHERE event_type IS NOT NULL AND event_type <> ''
        ORDER BY event_type
    ")->fetchAll(PDO::FETCH_COLUMN);

    return [
        'webhooks' => $webhooks,
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'event_types' => $eventTypes,
        'date_scoped' => $dateScoped
    ];
}

/**
 * Get single webhook detail
 */
function getWebhookDetail($db, $input)
{
    $id = $input['id'] ?? null;
    if (!$id) throw new Exception('Missing webhook ID');

    $stmt = $db->prepare("SELECT * FROM odoo_webhooks_log WHERE id = ?");
    $stmt->execute([$id]);
    $webhook = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$webhook) throw new Exception('Webhook not found');

    // Decode payload
    $webhook['payload_decoded'] = json_decode($webhook['payload'], true);

    return $webhook;
}

/**
 * Get all events for a specific order (timeline)
 */
function getOrderTimeline($db, $input)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';
    $orderByExpr = $processedAtColumn ?: '`id`';

    $orderId = $input['order_id'] ?? null;
    $orderName = $input['order_name'] ?? null;

    if (!$orderId && !$orderName) throw new Exception('Missing order_id or order_name');

    $where = [];
    $params = [];

    if ($orderId) {
        $where[] = "order_id = ?";
        $params[] = $orderId;
    }

    if ($orderName) {
        $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) = ?";
        $params[] = $orderName;
    }

    $whereClause = implode(' OR ', $where);

    $stmt = $db->prepare("
        SELECT id, delivery_id, event_type, status, error_message,
               line_user_id, order_id, {$processedAtExpr} as processed_at,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) as order_name,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')) as new_state_display,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.old_state_display')) as old_state_display,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')) as customer_name,
               JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')) as amount_total
        FROM odoo_webhooks_log
        WHERE {$whereClause}
        ORDER BY {$orderByExpr} ASC
    ");
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'order_id' => $orderId,
        'order_name' => $orderName ?? ($events[0]['order_name'] ?? null),
        'events' => $events,
        'total_events' => count($events)
    ];
}

/**
 * Lookup customers from webhook payloads.
 */
function getCustomerLookup($db, $input)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';

    $limit = min((int) ($input['limit'] ?? 20), 100);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $search = trim((string) ($input['search'] ?? ''));
    $customerId = trim((string) ($input['customer_id'] ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));

    $customerIdExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
    $customerRefExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
    $customerNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '')";
    $orderKeyExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')), ''), CAST(order_id AS CHAR))";
    $customerKeyExpr = "COALESCE({$customerIdExpr}, {$customerRefExpr}, {$customerNameExpr})";

    $where = ["status = 'success'", "{$customerKeyExpr} IS NOT NULL", "{$orderKeyExpr} IS NOT NULL"];
    $params = [];

    if ($customerId !== '') {
        $where[] = "{$customerIdExpr} = ?";
        $params[] = $customerId;
    }

    if ($customerRef !== '') {
        $where[] = "{$customerRefExpr} = ?";
        $params[] = $customerRef;
    }

    if ($search !== '') {
        $where[] = "({$customerIdExpr} LIKE ? OR {$customerRefExpr} LIKE ? OR {$customerNameExpr} LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM (
            SELECT {$customerKeyExpr} as customer_key
            FROM odoo_webhooks_log
            {$whereClause}
            GROUP BY customer_key
        ) t";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // Two-step approach: first get customer list, then compute accurate spend per customer
    // Step 1: Get paginated customer list (without spend)
    $sql = "SELECT
            {$customerKeyExpr} as customer_key,
            {$customerIdExpr} as customer_id,
            MAX({$customerNameExpr}) as customer_name,
            MAX({$customerRefExpr}) as customer_ref,
            COUNT(DISTINCT {$orderKeyExpr}) as orders_total,
            0 as spend_total,
            MAX({$processedAtExpr}) as latest_event_at
        FROM odoo_webhooks_log
        {$whereClause}
        GROUP BY customer_key
        ORDER BY latest_event_at DESC
        LIMIT {$limit} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Step 2: Compute accurate spend per customer (MAX amount per unique order, then SUM)
    $amtExpr = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))";
    $custKeys = array_filter(array_column($customers, 'customer_key'));
    if (!empty($custKeys)) {
        $placeholders = implode(',', array_fill(0, count($custKeys), '?'));
        $spendSql = "SELECT ckey, SUM(max_amt) as spend_total FROM (
            SELECT {$customerKeyExpr} as ckey, {$orderKeyExpr} as okey, MAX({$amtExpr}) as max_amt
            FROM odoo_webhooks_log
            WHERE status = 'success' AND {$customerKeyExpr} IN ({$placeholders})
            GROUP BY ckey, okey
        ) per_order GROUP BY ckey";
        $spendStmt = $db->prepare($spendSql);
        $spendStmt->execute($custKeys);
        $spendMap = [];
        while ($row = $spendStmt->fetch(PDO::FETCH_ASSOC)) {
            $spendMap[$row['ckey']] = (float) $row['spend_total'];
        }
        foreach ($customers as &$cu) {
            $cu['spend_total'] = $spendMap[$cu['customer_key']] ?? 0;
        }
        unset($cu);
    }

    return [
        'customers' => $customers,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Lookup invoice events from webhook payloads.
 */
function getInvoiceLookup($db, $input)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';
    $orderByExpr = $processedAtColumn ?: '`id`';

    $invoiceNumber = trim((string) ($input['invoice_number'] ?? $input['invoice'] ?? $input['search'] ?? ''));
    if ($invoiceNumber === '') {
        throw new Exception('Missing invoice_number');
    }

    $limit = min((int) ($input['limit'] ?? 50), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);

    $invoiceExprA = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_number')), '')";
    $invoiceExprB = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.name')), '')";
    $invoiceExprC = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.number')), '')";
    $invoiceKeyExpr = "COALESCE({$invoiceExprA}, {$invoiceExprB}, {$invoiceExprC})";

    $where = ["payload IS NOT NULL", "({$invoiceKeyExpr} = ? OR payload LIKE ?)"];
    $params = [$invoiceNumber, "%{$invoiceNumber}%"];
    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_webhooks_log {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT id, delivery_id, event_type, status, {$processedAtExpr} as processed_at,
            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) as order_name,
            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')) as customer_name,
            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')) as amount_total,
            {$invoiceKeyExpr} as invoice_number
        FROM odoo_webhooks_log
        {$whereClause}
        ORDER BY {$orderByExpr} DESC
        LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'events' => $events,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Get paginated customer list from odoo_customer_projection with webhook fallback.
 */
function getCustomerList($db, $input)
{
    $limit = min((int) ($input['limit'] ?? 30), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $search = trim((string) ($input['search'] ?? ''));
    $invoiceFilter = trim((string) ($input['invoice_filter'] ?? ''));
    $sortBy = trim((string) ($input['sort_by'] ?? ''));
    $salespersonId = trim((string) ($input['salesperson_id'] ?? ''));
    $fastMode = !empty($input['fast']) && $search === '' && $invoiceFilter === '' && $sortBy === '' && $salespersonId === '';

    // Salesperson JSON expressions (used in webhook fallback)
    $spIdExpr   = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.id')), '')";
    $spNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.name')), '')";

    // Try odoo_customer_projection first
    // NOTE: projection table has no salesperson column → fall through to webhook when salesperson filter active
    if ($salespersonId === '' && tableExists($db, 'odoo_customer_projection')) {
        try {
            $where = [];
            $params = [];

            if ($search !== '') {
                $where[] = "(partner_name LIKE ? OR partner_code LIKE ? OR customer_name LIKE ?)";
                $s = "%{$search}%";
                $params[] = $s;
                $params[] = $s;
                $params[] = $s;
            }

            if ($invoiceFilter === 'unpaid') {
                $where[] = "COALESCE(total_due, 0) > 0";
            } elseif ($invoiceFilter === 'overdue') {
                $where[] = "COALESCE(overdue_amount, 0) > 0";
            }

            $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_customer_projection {$whereClause}");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // Sort logic
            $sortMap = [
                'spend_desc'  => 'ORDER BY COALESCE(spend_30d,0) DESC, latest_order_at DESC',
                'spend_asc'   => 'ORDER BY COALESCE(spend_30d,0) ASC, latest_order_at DESC',
                'orders_desc' => 'ORDER BY COALESCE(orders_count_total,0) DESC, latest_order_at DESC',
                'orders_asc'  => 'ORDER BY COALESCE(orders_count_total,0) ASC, latest_order_at DESC',
                'due_desc'    => 'ORDER BY COALESCE(total_due,0) DESC, COALESCE(overdue_amount,0) DESC',
                'name_asc'    => 'ORDER BY COALESCE(partner_name, customer_name, \'\') ASC',
            ];
            if ($sortBy !== '' && isset($sortMap[$sortBy])) {
                $orderBy = $sortMap[$sortBy];
            } elseif ($invoiceFilter === 'unpaid' || $invoiceFilter === 'overdue') {
                $orderBy = 'ORDER BY COALESCE(overdue_amount,0) DESC, COALESCE(total_due,0) DESC';
            } else {
                $orderBy = 'ORDER BY latest_order_at DESC';
            }

            $stmt = $db->prepare("
                SELECT
                    COALESCE(partner_name, customer_name, '') as customer_name,
                    COALESCE(partner_code, customer_ref, '') as customer_ref,
                    COALESCE(odoo_partner_id, customer_id) as customer_id,
                    COALESCE(odoo_partner_id, customer_id) as partner_id,
                    COALESCE(orders_count_30d, 0) as orders_30d,
                    COALESCE(orders_count_total, 0) as orders_total,
                    COALESCE(spend_30d, 0) as spend_30d,
                    COALESCE(total_due, 0) as total_due,
                    COALESCE(overdue_amount, 0) as overdue_amount,
                    COALESCE(credit_limit, 0) as credit_limit,
                    COALESCE(credit_used, 0) as credit_used,
                    COALESCE(credit_remaining, 0) as credit_remaining,
                    latest_order_at,
                    line_user_id
                FROM odoo_customer_projection
                {$whereClause}
                {$orderBy}
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($total > 0 || $search !== '' || $invoiceFilter !== '') {
                return ['customers' => $customers, 'total' => $total, 'source' => 'projection', 'limit' => $limit, 'offset' => $offset];
            }
        } catch (Exception $e) {
            // fall through to webhook fallback
        }
    }

    // Webhook fallback
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';
    $recentWindowWhere = webhookRecentWindowWhere(
        $db,
        $processedAtColumn,
        $fastMode ? 120 : (($invoiceFilter === 'unpaid' || $invoiceFilter === 'overdue') ? 365 : 180),
        $fastMode ? 50000 : (($invoiceFilter === 'unpaid' || $invoiceFilter === 'overdue') ? 120000 : 80000)
    );

    $customerIdExpr    = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
    $customerPidExpr   = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), '')";
    $customerRefExpr   = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
    $customerNameExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '')";
    $customerKeyExpr   = "COALESCE({$customerIdExpr}, {$customerPidExpr}, {$customerRefExpr}, {$customerNameExpr})";
    $partnerIdExpr     = "COALESCE({$customerPidExpr}, {$customerIdExpr})";

    $where = ["status = 'success'", "{$customerKeyExpr} IS NOT NULL"];
    $params = [];

    if ($search === '' && $recentWindowWhere !== '') {
        $where[] = $recentWindowWhere;
    }

    if ($search !== '') {
        $where[] = "({$customerRefExpr} LIKE ? OR {$customerNameExpr} LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
    }

    if ($salespersonId !== '') {
        $where[] = "{$spIdExpr} = ?";
        $params[] = $salespersonId;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $total = 0;
    if (!$fastMode) {
        $countSql = "SELECT COUNT(*) FROM (
            SELECT {$customerKeyExpr} as customer_key
            FROM odoo_webhooks_log {$whereClause}
            GROUP BY customer_key
        ) t";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
    }

    $orderKeyExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), CAST(order_id AS CHAR))";
    $amtExpr = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))";

    $stmt = $db->prepare("
        SELECT
            {$customerKeyExpr} as customer_key,
            {$customerIdExpr} as customer_id,
            MAX({$partnerIdExpr}) as partner_id,
            MAX({$customerNameExpr}) as customer_name,
            MAX({$customerRefExpr}) as customer_ref,
            COUNT(DISTINCT {$orderKeyExpr}) as orders_total,
            0 as spend_30d,
            MAX(line_user_id) as line_user_id,
            MAX({$processedAtExpr}) as latest_order_at,
            MAX({$spIdExpr}) as salesperson_id,
            MAX({$spNameExpr}) as salesperson_name,
            0 as total_due,
            0 as overdue_amount,
            0 as credit_limit,
            0 as credit_used,
            0 as credit_remaining
        FROM odoo_webhooks_log
        {$whereClause}
        GROUP BY customer_key
        ORDER BY " . webhookCustomerSortExpr($sortBy) . "
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$fastMode) {
        // Compute accurate spend: MAX amount per unique order, then SUM (avoids counting same order across multiple events)
        $custKeys = array_filter(array_column($customers, 'customer_key'));
        if (!empty($custKeys)) {
            $ph = implode(',', array_fill(0, count($custKeys), '?'));
            $spendStmt = $db->prepare("
                SELECT ckey, SUM(max_amt) as spend FROM (
                    SELECT {$customerKeyExpr} as ckey, {$orderKeyExpr} as okey, MAX({$amtExpr}) as max_amt
                    FROM odoo_webhooks_log
                    WHERE status = 'success' AND {$customerKeyExpr} IN ({$ph})" . ($search === '' && $recentWindowWhere !== '' ? " AND {$recentWindowWhere}" : "") . "
                    GROUP BY ckey, okey
                ) per_order GROUP BY ckey
            ");
            $spendStmt->execute($custKeys);
            $spendMap = [];
            while ($r = $spendStmt->fetch(PDO::FETCH_ASSOC)) {
                $spendMap[$r['ckey']] = (float) $r['spend'];
            }
            foreach ($customers as &$cu) {
                $cu['spend_30d'] = $spendMap[$cu['customer_key']] ?? 0;
            }
            unset($cu);
        }
    }

    if ($fastMode) {
        $total = count($customers);
    }

    return ['customers' => $customers, 'total' => $total, 'source' => 'webhook', 'limit' => $limit, 'offset' => $offset];
}

/**
 * Get invoice events for a specific customer from webhook log.
 * Groups by invoice_number so each invoice appears only once,
 * keeping the highest-priority state: paid > overdue > posted > open > draft.
 */
function getInvoiceList($db, $input)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';

    $limit = min((int) ($input['limit'] ?? 50), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $customerKey = trim((string) ($input['customer_key'] ?? ''));
    $customerId  = trim((string) ($input['customer_id']  ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));
    $search      = trim((string) ($input['search']       ?? ''));

    $customerIdExpr   = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
    $customerPidExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), '')";
    $customerRefExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
    $customerNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '')";
    $invoiceExpr = "COALESCE(
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_number')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.name')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.number')), '')
    )";

    $where  = ["event_type LIKE 'invoice.%'"];
    $params = [];

    if ($customerId !== '') {
        $where[]  = "({$customerIdExpr} = ? OR {$customerPidExpr} = ?)";
        $params[] = $customerId;
        $params[] = $customerId;
    } elseif ($customerRef !== '') {
        $where[]  = "{$customerRefExpr} = ?";
        $params[] = $customerRef;
    } elseif ($customerKey !== '') {
        $where[]  = "(COALESCE({$customerIdExpr}, {$customerPidExpr}, {$customerRefExpr}, {$customerNameExpr}) = ?)";
        $params[] = $customerKey;
    }

    if ($search !== '') {
        $where[]  = "({$invoiceExpr} LIKE ? OR {$customerNameExpr} LIKE ?)";
        $s        = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // State priority: higher = wins when deduplicating per invoice_number
    $statePriority = [
        'invoice.paid'    => 4,
        'invoice.overdue' => 3,
        'invoice.posted'  => 2,
        'invoice.created' => 1,
    ];

    // Fetch all matching rows (no pagination yet — deduplicate in PHP first)
    $stmt = $db->prepare("
        SELECT
            id,
            event_type,
            status,
            {$processedAtExpr} AS processed_at,
            {$invoiceExpr} AS invoice_number,
            CAST(COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_residual')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''),
                '0'
            ) AS DECIMAL(14,2)) AS amount_residual,
            CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2)) AS amount_total,
            {$customerIdExpr} AS customer_id,
            {$customerRefExpr} AS customer_ref,
            {$customerNameExpr} AS customer_name,
            CASE event_type
                WHEN 'invoice.paid'    THEN 'paid'
                WHEN 'invoice.overdue' THEN 'overdue'
                WHEN 'invoice.posted'  THEN 'posted'
                WHEN 'invoice.created' THEN 'open'
                ELSE COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_state')), ''), event_type)
            END AS invoice_state,
            COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.due_date')), ''),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_date')), '')
            ) AS due_date,
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_date')), '') AS invoice_date,
            line_user_id
        FROM odoo_webhooks_log
        {$whereClause}
        ORDER BY {$processedAtExpr} DESC
    ");
    $stmt->execute($params);
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Deduplicate: keep one row per invoice_number with the highest-priority state.
    // Ties broken by latest processed_at (rows already ordered DESC).
    $best = []; // invoice_number → row
    foreach ($rawRows as $row) {
        $num = $row['invoice_number'];
        if ($num === null || $num === '') continue;
        if (!isset($best[$num])) {
            $best[$num] = $row;
        } else {
            $curPri = $statePriority[$best[$num]['event_type']] ?? 0;
            $newPri = $statePriority[$row['event_type']] ?? 0;
            if ($newPri > $curPri) {
                $best[$num] = $row;
            }
        }
    }

    // Sort deduplicated invoices newest first by processed_at
    $deduped = array_values($best);
    usort($deduped, function ($a, $b) {
        return strcmp((string)($b['processed_at'] ?? ''), (string)($a['processed_at'] ?? ''));
    });

    $total = count($deduped);

    // Apply pagination
    $invoices = array_slice($deduped, $offset, $limit);

    // Ensure paid invoices always show ฿0 residual
    foreach ($invoices as &$inv) {
        if (($inv['invoice_state'] ?? '') === 'paid') {
            $inv['amount_residual'] = '0.00';
        }
    }
    unset($inv);

    return ['invoices' => $invoices, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

/**
 * Get order events for a specific customer from webhook log.
 */
function getOrderList($db, $input)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';
    $orderByExpr = $processedAtColumn ?: '`id`';

    $limit = min((int) ($input['limit'] ?? 50), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $customerId  = trim((string) ($input['customer_id']  ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));

    $customerIdExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
    $customerPidExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), '')";
    $customerRefExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
    $customerNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '')";
    $orderNameExpr   = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.name')), ''))";
    $stateExpr       = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')), '')";
    $stateDisplayExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')), '')";
    $amountExpr      = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))";

    $where = [
        "(JSON_EXTRACT(payload, '$.order_id') IS NOT NULL OR JSON_EXTRACT(payload, '$.order_name') IS NOT NULL)"
    ];
    $params = [];

    if ($partnerId !== '') {
        $where[] = "(COALESCE({$customerPidExpr}, {$customerIdExpr}) = ?)";
        $params[] = $partnerId;
    } elseif ($customerId !== '') {
        $where[] = "(COALESCE({$customerPidExpr}, {$customerIdExpr}) = ?)";
        $params[] = $customerId;
    } elseif ($customerRef !== '') {
        $where[] = "{$customerRefExpr} = ?";
        $params[] = $customerRef;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // De-duplicate: one row per order_name
    // Use MAX(id) to join back for the latest event's state (not alphabetical MAX)
    $sql = "
        SELECT
            grp.max_id as id,
            grp.order_name,
            grp.order_id,
            COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(lr.payload, '$.new_state_display')), ''),
                lr.event_type
            ) as state_display,
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(lr.payload, '$.new_state')), '') as state,
            grp.amount_total,
            grp.customer_name,
            grp.customer_ref,
            grp.last_updated_at
        FROM (
            SELECT {$orderNameExpr} as order_name,
                   MAX({$amountExpr}) as amount_total,
                   MAX({$customerNameExpr}) as customer_name,
                   MAX({$customerRefExpr}) as customer_ref,
                   MAX({$processedAtExpr}) as last_updated_at,
                   MAX(id) as max_id,
                   MAX(CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')), ''), '0') AS UNSIGNED)) as order_id
            FROM odoo_webhooks_log
            {$whereClause}
            GROUP BY {$orderNameExpr}
        ) grp
        LEFT JOIN odoo_webhooks_log lr ON lr.id = grp.max_id
        ORDER BY grp.last_updated_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $countSql = "SELECT COUNT(*) FROM (SELECT {$orderNameExpr} as grp_key FROM odoo_webhooks_log {$whereClause} GROUP BY {$orderNameExpr}) t";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['orders' => $orders, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

/**
 * Get orders for a customer from odoo_orders sync table (full columns).
 * Falls back to webhook log if table unavailable.
 */
function getOdooOrders($db, $input)
{
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));
    $state       = trim((string) ($input['state']        ?? ''));
    $limit       = min((int) ($input['limit']  ?? 50), 500);
    $offset      = max((int) ($input['offset'] ?? 0), 0);

    // Resolve line_user_id from partner_id if not provided
    if ($lineUserId === '' && $partnerId !== '' && $partnerId !== '-') {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([(int) $partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $lineUserId = $row['line_user_id'];
        } catch (Exception $e) { /* ignore */ }
    }

    // Try dedicated sync table first
    try {
        $where = [];
        $params = [];

        if ($partnerId !== '' && $partnerId !== '-') {
            $where[] = 'partner_id = ?';
            $params[] = (int) $partnerId;
        } elseif ($lineUserId !== '') {
            $where[] = 'line_user_id = ?';
            $params[] = $lineUserId;
        } elseif ($customerRef !== '') {
            $where[] = 'customer_ref = ?';
            $params[] = $customerRef;
        }

        if ($state !== '') {
            $where[] = 'state = ?';
            $params[] = $state;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $totalStmt = $db->prepare("SELECT COUNT(*) FROM odoo_orders {$whereClause}");
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        if ($total > 0 || $whereClause !== '') {
            $sql = "
                SELECT
                    id, order_id, order_name, partner_id, customer_ref, line_user_id,
                    salesperson_id, salesperson_name,
                    state, state_display, payment_status, delivery_status,
                    amount_total, amount_tax, amount_untaxed, currency,
                    date_order, expected_delivery, payment_date,
                    items_count, is_paid, is_delivered,
                    latest_event, synced_at, updated_at
                FROM odoo_orders
                {$whereClause}
                ORDER BY updated_at DESC
                LIMIT ? OFFSET ?
            ";
            $params[] = $limit;
            $params[] = $offset;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Cast numerics
            foreach ($orders as &$o) {
                $o['id']            = (int) $o['id'];
                $o['order_id']      = (int) $o['order_id'];
                $o['partner_id']    = $o['partner_id']    !== null ? (int) $o['partner_id']    : null;
                $o['salesperson_id']= $o['salesperson_id']!== null ? (int) $o['salesperson_id']: null;
                $o['amount_total']  = $o['amount_total']  !== null ? (float) $o['amount_total']  : null;
                $o['amount_tax']    = $o['amount_tax']    !== null ? (float) $o['amount_tax']    : null;
                $o['amount_untaxed']= $o['amount_untaxed']!== null ? (float) $o['amount_untaxed']: null;
                $o['items_count']   = (int) $o['items_count'];
                $o['is_paid']       = (bool) $o['is_paid'];
                $o['is_delivered']  = (bool) $o['is_delivered'];
            }
            unset($o);

            // Backfill missing amount_total / date_order / state_display from webhook log
            // Check for NULL, 0, or empty — not just strict NULL
            $nullOrders = array_filter($orders, function($o) {
                return (empty($o['amount_total']) || $o['amount_total'] == 0 || $o['state_display'] === null || $o['state_display'] === '' || $o['date_order'] === null) && $o['order_name'];
            });
            if (!empty($nullOrders)) {
                try {
                    $names = array_map(function($o) { return $o['order_name']; }, $nullOrders);
                    $placeholders = implode(',', array_fill(0, count($names), '?'));
                    $amtExpr  = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.amount_total')),''),'0') AS DECIMAL(14,2))";
                    $stateExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.new_state_display')),'')";
                    $orderNameExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.order_name')),''),NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name')),''))";
                    $wbStmt = $db->prepare("
                        SELECT {$orderNameExpr} AS order_name,
                               MAX({$amtExpr})   AS amount_total,
                               MAX(date_order)   AS date_order_col,
                               MAX(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.date_order'))) AS date_order_json,
                               MAX({$stateExpr}) AS state_display
                        FROM odoo_webhooks_log
                        WHERE {$orderNameExpr} IN ({$placeholders})
                        GROUP BY {$orderNameExpr}
                    ");
                    $wbStmt->execute($names);
                    $wbMap = [];
                    foreach ($wbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $wbMap[$row['order_name']] = $row;
                    }
                    foreach ($orders as &$o) {
                        if (isset($wbMap[$o['order_name']])) {
                            $wb = $wbMap[$o['order_name']];
                            if (empty($o['amount_total']) || $o['amount_total'] == 0) {
                                $wbAmt = (float) $wb['amount_total'];
                                if ($wbAmt > 0) $o['amount_total'] = $wbAmt;
                            }
                            if (!$o['date_order']) {
                                $o['date_order'] = $wb['date_order_col'] ?: $wb['date_order_json'] ?: null;
                            }
                            if (!$o['state_display'] || $o['state_display'] === '' || $o['state_display'] === 'null') {
                                $o['state_display'] = $wb['state_display'] ?: null;
                            }
                        }
                    }
                    unset($o);
                } catch (Exception $e) { /* ignore backfill errors */ }
            }

            // Second backfill: fill remaining missing amount/date from odoo_invoices table
            // (handles orders that only have invoice.* webhook events, no order.* events)
            $stillNull = array_filter($orders, function($o) {
                return (empty($o['amount_total']) || $o['amount_total'] == 0) && $o['order_name'];
            });
            if (!empty($stillNull)) {
                try {
                    $names2 = array_map(function($o) { return $o['order_name']; }, $stillNull);
                    $ph2 = implode(',', array_fill(0, count($names2), '?'));
                    $invStmt = $db->prepare("
                        SELECT order_name,
                               MAX(amount_total) AS amount_total,
                               MAX(invoice_date) AS invoice_date,
                               MAX(invoice_state) AS invoice_state,
                               MAX(is_paid) AS is_paid
                        FROM odoo_invoices
                        WHERE order_name IN ({$ph2})
                        GROUP BY order_name
                    ");
                    $invStmt->execute($names2);
                    $invMap = [];
                    foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $invMap[$row['order_name']] = $row;
                    }
                    foreach ($orders as &$o) {
                        if ($o['amount_total'] === null && isset($invMap[$o['order_name']])) {
                            $im = $invMap[$o['order_name']];
                            $o['amount_total'] = $im['amount_total'] !== null ? (float) $im['amount_total'] : null;
                            if (!$o['date_order'] && $im['invoice_date']) {
                                $o['date_order'] = $im['invoice_date'];
                            }
                            if (!$o['state_display'] && $im['invoice_state']) {
                                $isPaidFromInv = (bool) $im['is_paid'];
                                $o['state_display'] = $isPaidFromInv ? 'ชำระแล้ว' : $im['invoice_state'];
                            }
                            if ((bool) ($im['is_paid'] ?? false)) {
                                $o['is_paid'] = true;
                                $o['payment_status'] = 'paid';
                            }
                        }
                    }
                    unset($o);
                } catch (Exception $e) { /* ignore */ }
            }

            // Quality check: if ALL orders still have amount=0 and no state after backfill,
            // the sync table data is useless — merge with webhook log data instead
            $hasAnyRealData = false;
            foreach ($orders as $chk) {
                if ((!empty($chk['amount_total']) && $chk['amount_total'] > 0) || !empty($chk['state_display']) || !empty($chk['state'])) {
                    $hasAnyRealData = true;
                    break;
                }
            }

            if (!$hasAnyRealData && !empty($orders)) {
                // Sync table has order names but no real data — use webhook log as primary
                // and merge back any sync-only fields (is_paid, delivery_status, etc.)
                $syncByName = [];
                foreach ($orders as $so) {
                    if ($so['order_name']) $syncByName[$so['order_name']] = $so;
                }

                $wbInput = $input;
                $wbInput['limit'] = $limit;
                $wbInput['offset'] = $offset;
                $wbFallback = getOrderList($db, $wbInput);
                $wbOrders = $wbFallback['orders'] ?? [];

                // Merge sync metadata onto webhook orders
                foreach ($wbOrders as &$wo) {
                    $oName = $wo['order_name'] ?? '';
                    if ($oName && isset($syncByName[$oName])) {
                        $sync = $syncByName[$oName];
                        if (!empty($sync['is_paid']))        $wo['is_paid'] = true;
                        if (!empty($sync['is_delivered']))   $wo['is_delivered'] = true;
                        if (!empty($sync['payment_status'])) $wo['payment_status'] = $sync['payment_status'];
                        if (!empty($sync['delivery_status']))$wo['delivery_status'] = $sync['delivery_status'];
                        if (!empty($sync['salesperson_name']))$wo['salesperson_name'] = $sync['salesperson_name'];
                    }
                }
                unset($wo);

                return ['orders' => $wbOrders, 'total' => $wbFallback['total'] ?? count($wbOrders), 'source' => 'webhook_merged', 'limit' => $limit, 'offset' => $offset];
            }

            return ['orders' => $orders, 'total' => $total, 'source' => 'sync_table', 'limit' => $limit, 'offset' => $offset];
        }
    } catch (Exception $e) {
        // fall through to webhook log
    }

    // Fallback to webhook log
    $fallback = getOrderList($db, $input);
    $fallback['source'] = 'webhook_log';
    return $fallback;
}

/**
 * Get invoices for a customer from odoo_invoices sync table (full columns).
 * Includes all fields needed for slip matching.
 */
function getOdooInvoices($db, $input)
{
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));
    $isPaid      = isset($input['is_paid']) ? (int) $input['is_paid'] : null;
    $limit       = min((int) ($input['limit']  ?? 50), 500);
    $offset      = max((int) ($input['offset'] ?? 0), 0);

    // Resolve line_user_id from partner_id if not provided
    if ($lineUserId === '' && $partnerId !== '' && $partnerId !== '-') {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([(int) $partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $lineUserId = $row['line_user_id'];
        } catch (Exception $e) { /* ignore */ }
    }

    // Try dedicated sync table first
    try {
        $where = [];
        $params = [];

        if ($partnerId !== '' && $partnerId !== '-') {
            $where[] = 'partner_id = ?';
            $params[] = (int) $partnerId;
        } elseif ($lineUserId !== '') {
            $where[] = 'line_user_id = ?';
            $params[] = $lineUserId;
        } elseif ($customerRef !== '') {
            $where[] = 'customer_ref = ?';
            $params[] = $customerRef;
        }

        if ($isPaid !== null) {
            $where[] = 'is_paid = ?';
            $params[] = $isPaid;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $totalStmt = $db->prepare("SELECT COUNT(*) FROM odoo_invoices {$whereClause}");
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        if ($total > 0 || $whereClause !== '') {
            $sql = "
                SELECT
                    id, invoice_id, invoice_number,
                    order_id, order_name,
                    partner_id, customer_ref, line_user_id,
                    salesperson_id, salesperson_name,
                    state, invoice_state, payment_state,
                    amount_total, amount_tax, amount_untaxed, amount_residual, currency,
                    invoice_date, due_date, payment_date, payment_term, payment_method,
                    is_paid, is_overdue, pdf_url,
                    latest_event, synced_at, updated_at
                FROM odoo_invoices
                {$whereClause}
                ORDER BY updated_at DESC
                LIMIT ? OFFSET ?
            ";
            $params[] = $limit;
            $params[] = $offset;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Cast types & normalize status
            foreach ($invoices as &$inv) {
                $inv['id']              = (int) $inv['id'];
                $inv['invoice_id']      = (int) $inv['invoice_id'];
                $inv['partner_id']      = $inv['partner_id']      !== null ? (int) $inv['partner_id']      : null;
                $inv['order_id']        = $inv['order_id']        !== null ? (int) $inv['order_id']        : null;
                $inv['salesperson_id']  = $inv['salesperson_id']  !== null ? (int) $inv['salesperson_id']  : null;
                $inv['amount_total']    = $inv['amount_total']    !== null ? (float) $inv['amount_total']    : null;
                $inv['amount_tax']      = $inv['amount_tax']      !== null ? (float) $inv['amount_tax']      : null;
                $inv['amount_untaxed']  = $inv['amount_untaxed']  !== null ? (float) $inv['amount_untaxed']  : null;

                // Determine paid status from multiple signals
                $isPaidInv = (bool) $inv['is_paid']
                    || strtolower((string) ($inv['payment_state'] ?? '')) === 'paid'
                    || strtolower((string) ($inv['latest_event'] ?? '')) === 'invoice.paid';

                $inv['amount_residual'] = $inv['amount_residual'] !== null
                    ? (float) $inv['amount_residual']
                    : ($isPaidInv ? 0.0 : (float) ($inv['amount_total'] ?? 0));

                // Force residual to 0 if paid
                if ($isPaidInv) {
                    $inv['amount_residual'] = 0.0;
                }

                $inv['is_paid']    = $isPaidInv;
                $inv['is_overdue'] = (bool) $inv['is_overdue'];

                // Normalize invoice_state for frontend consistency
                if ($isPaidInv) {
                    $inv['invoice_state'] = 'paid';
                    $inv['is_overdue'] = false;
                } elseif ($inv['is_overdue'] || (
                    $inv['due_date'] && strtotime($inv['due_date']) < time()
                )) {
                    $inv['invoice_state'] = $inv['invoice_state'] ?: 'overdue';
                    $inv['is_overdue'] = true;
                }
            }
            unset($inv);

            // Backfill NULL invoice_date / due_date from webhook log
            $nullInvs = array_filter($invoices, function($inv) {
                return (!$inv['invoice_date'] || !$inv['due_date']) && $inv['invoice_number'];
            });
            if (!empty($nullInvs)) {
                try {
                    $invNums = array_map(function($inv) { return $inv['invoice_number']; }, $nullInvs);
                    $placeholders = implode(',', array_fill(0, count($invNums), '?'));
                    $invNumExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.invoice_number')),'')";
                    $invDateExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.invoice_date')),'')";
                    $dueDateExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.due_date')),'')";
                    $wbStmt = $db->prepare("
                        SELECT {$invNumExpr} AS invoice_number,
                               MAX({$invDateExpr}) AS invoice_date,
                               MAX({$dueDateExpr}) AS due_date,
                               MAX(processed_at) AS processed_at
                        FROM odoo_webhooks_log
                        WHERE event_type LIKE 'invoice.%'
                          AND {$invNumExpr} IN ({$placeholders})
                        GROUP BY {$invNumExpr}
                    ");
                    $wbStmt->execute($invNums);
                    $wbMap = [];
                    foreach ($wbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $wbMap[$row['invoice_number']] = $row;
                    }
                    foreach ($invoices as &$inv) {
                        if (isset($wbMap[$inv['invoice_number']])) {
                            $wb = $wbMap[$inv['invoice_number']];
                            if (!$inv['invoice_date']) {
                                $inv['invoice_date'] = $wb['invoice_date'] ?: $wb['processed_at'] ?: null;
                            }
                            if (!$inv['due_date']) {
                                $inv['due_date'] = $wb['due_date'] ?: null;
                            }
                        }
                    }
                    unset($inv);
                } catch (Exception $e) { /* ignore */ }
            }

            // Quality check: if ALL invoices have no real amount data, fallback to webhook log
            $hasAnyInvData = false;
            foreach ($invoices as $ichk) {
                if ((!empty($ichk['amount_total']) && $ichk['amount_total'] > 0) || !empty($ichk['invoice_state']) || !empty($ichk['invoice_date'])) {
                    $hasAnyInvData = true;
                    break;
                }
            }

            if (!$hasAnyInvData && !empty($invoices)) {
                // Sync table has invoice numbers but no real data — fallback to webhook log
                if (!empty($input['partner_id']) && $input['partner_id'] !== '-' && empty($input['customer_id'])) {
                    $input['customer_id'] = $input['partner_id'];
                }
                $wbFallback = getInvoiceList($db, $input);
                $wbFallback['source'] = 'webhook_merged';
                return $wbFallback;
            }

            return ['invoices' => $invoices, 'total' => $total, 'source' => 'sync_table', 'limit' => $limit, 'offset' => $offset];
        }
    } catch (Exception $e) {
        // fall through to webhook log
    }

    // Fallback to webhook log
    if (!empty($input['partner_id']) && $input['partner_id'] !== '-' && empty($input['customer_id'])) {
        $input['customer_id'] = $input['partner_id'];
    }
    $fallback = getInvoiceList($db, $input);
    $fallback['source'] = 'webhook_log';
    return $fallback;
}

/**
 * Get slips for a customer by line_user_id, sorted newest first.
 * Used to match slips with orders/invoices in the customer detail modal.
 */
function getOdooSlips($db, $input)
{
    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));
    $partnerId  = trim((string) ($input['partner_id']  ?? ''));

    // Resolve line_user_id from partner_id if not given directly
    if ($lineUserId === '' && $partnerId !== '' && $partnerId !== '-') {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([(int) $partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $lineUserId = $row['line_user_id'];
        } catch (Exception $e) { /* ignore */ }
    }

    if ($lineUserId === '') {
        return ['slips' => [], 'total' => 0];
    }

    // Check table exists
    try {
        $check = $db->query("SELECT 1 FROM odoo_slip_uploads LIMIT 1");
    } catch (Exception $e) {
        return ['slips' => [], 'total' => 0, 'error' => 'odoo_slip_uploads table not found'];
    }

    $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : 'https://cny.re-ya.com', '/');

    $stmt = $db->prepare("
        SELECT
            id,
            line_user_id,
            amount,
            transfer_date,
            status,
            image_path,
            image_url,
            bdo_id,
            bdo_name,
            delivery_type,
            bdo_amount,
            match_confidence,
            slip_inbox_id,
            slip_inbox_name,
            invoice_id,
            order_id,
            uploaded_at,
            matched_at,
            match_reason,
            uploaded_by
        FROM odoo_slip_uploads
        WHERE line_user_id = ?
        ORDER BY uploaded_at DESC
        LIMIT 100
    ");
    $stmt->execute([$lineUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['id']     = (int) $row['id'];
        $row['amount'] = $row['amount'] !== null ? (float) $row['amount'] : null;
        $row['bdo_id'] = $row['bdo_id'] !== null ? (int) $row['bdo_id'] : null;
        $row['bdo_amount'] = $row['bdo_amount'] !== null ? (float) $row['bdo_amount'] : null;
        $row['slip_inbox_id'] = $row['slip_inbox_id'] !== null ? (int) $row['slip_inbox_id'] : null;
        if ($row['image_path']) {
            $row['image_full_url'] = $baseUrl . '/' . ltrim($row['image_path'], '/');
        } else {
            $row['image_full_url'] = $row['image_url'] ?: null;
        }
    }
    unset($row);

    return ['slips' => $rows, 'total' => count($rows)];
}

/**
 * Preview daily summary grouping by line_user_id
 */
function getDailySummaryPreview($db)
{
    $orderNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), '')";
    $orderRefExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')), '')";
    $lineUserIdExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')), '')";
    
    // 1. Get today's sent summaries to know who already received it
    $sentTodaySql = "
        SELECT line_user_id 
        FROM odoo_notification_log 
        WHERE event_type = 'daily.summary' 
        AND status = 'sent' 
        AND sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY
    ";
    $sentUsers = $db->query($sentTodaySql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
    
    // 2. Get active orders for each user updated today
    // We want to show a timeline for each order that had activity today
    $sql = "
        SELECT 
            {$lineUserIdExpr} as line_user_id,
            COALESCE({$orderNameExpr}, {$orderRefExpr}) as order_ref,
            event_type,
            status,
            processed_at as event_time
        FROM odoo_webhooks_log
        WHERE {$lineUserIdExpr} IS NOT NULL 
          AND DATE(processed_at) = CURDATE()
        ORDER BY processed_at ASC
    ";
    
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by user and then by order to build timeline
    $userOrders = [];
    foreach ($rows as $row) {
        $userId = $row['line_user_id'];
        $orderRef = $row['order_ref'];
        
        if (!$orderRef) continue;
        
        if (!isset($userOrders[$userId])) {
            $userOrders[$userId] = [];
        }
        
        if (!isset($userOrders[$userId][$orderRef])) {
            $userOrders[$userId][$orderRef] = [
                'order_ref' => $orderRef,
                'events' => [],
                'last_update' => null,
                'last_status' => null,
                'last_event' => null
            ];
        }
        
        $userOrders[$userId][$orderRef]['events'][] = [
            'event_type' => $row['event_type'],
            'status' => $row['status'],
            'time' => $row['event_time']
        ];
        $userOrders[$userId][$orderRef]['last_update'] = $row['event_time'];
        $userOrders[$userId][$orderRef]['last_status'] = $row['status'];
        $userOrders[$userId][$orderRef]['last_event'] = $row['event_type'];
    }
    
    // Map event_type to labels (CNY 13-state pipeline)
    $eventLabels = [
        'order.validated'        => 'ยืนยันออเดอร์',
        'order.picker_assigned'  => 'มอบหมาย Picker',
        'order.picking'          => 'กำลังจัดสินค้า',
        'order.picked'           => 'จัดเสร็จแล้ว',
        'order.packing'          => 'กำลังแพ็ค',
        'order.packed'           => 'แพ็คเสร็จ',
        'order.reserved'         => 'จองสินค้าแล้ว',
        'order.awaiting_payment' => 'รอชำระเงิน',
        'order.paid'             => 'ชำระเงินแล้ว',
        'order.to_delivery'      => 'เตรียมจัดส่ง',
        'order.in_delivery'      => 'กำลังจัดส่ง',
        'order.delivered'        => 'จัดส่งสำเร็จ',
        'order.cancelled'        => 'ยกเลิกออเดอร์',
        'invoice.created'        => 'ออกใบแจ้งหนี้',
        'invoice.posted'         => 'ออกใบแจ้งหนี้',
        'invoice.paid'           => 'ชำระเงินแล้ว',
        'invoice.overdue'        => 'ใบแจ้งหนี้เกินกำหนด',
        'invoice.cancelled'      => 'ยกเลิกใบแจ้งหนี้',
        'payment.confirmed'      => 'ยืนยันชำระเงิน',
        'payment.received'       => 'รับชำระเงิน',
        'sale.order.created'     => 'สร้างออเดอร์',
        'sale.order.confirmed'   => 'ยืนยันออเดอร์',
        'sale.order.done'        => 'ออเดอร์สำเร็จ',
        'sale.order.cancelled'   => 'ยกเลิกออเดอร์',
        'delivery.validated'     => 'เริ่มจัดเตรียม',
        'delivery.in_transit'    => 'กำลังจัดส่ง',
        'delivery.done'          => 'ส่งเสร็จแล้ว',
        'delivery.cancelled'     => 'ยกเลิกการส่ง',
    ];
    
    $results = [];
    foreach ($userOrders as $userId => $orders) {
        $activeOrders = [];
        
        foreach ($orders as $orderRef => $order) {
            // Process events for timeline display
            $processedEvents = [];
            foreach ($order['events'] as $event) {
                $eventType = $event['event_type'];
                $label = $eventLabels[$eventType] ?? explode('.', $eventType)[count(explode('.', $eventType))-1];
                $processedEvents[] = [
                    'type' => $eventType,
                    'label' => $label,
                    'status' => $event['status'],
                    'time' => $event['time']
                ];
            }
            
            $lastEvent = $order['last_event'];
            $activeOrders[] = [
                'order_ref' => $orderRef,
                'event_type' => $lastEvent,
                'event_label' => $eventLabels[$lastEvent] ?? explode('.', $lastEvent)[count(explode('.', $lastEvent))-1],
                'status' => $order['last_status'],
                'last_update' => $order['last_update'],
                'timeline' => $processedEvents
            ];
        }
        
        if (!empty($activeOrders)) {
            // Get user display name from users table if available
            $stmt = $db->prepare("SELECT display_name FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $displayName = $stmt->fetchColumn() ?: 'Customer';
            
            $results[] = [
                'line_user_id' => $userId,
                'display_name' => $displayName,
                'sent_today' => in_array($userId, $sentUsers),
                'orders' => array_values($activeOrders)
            ];
        }
    }
    
    // Sort so pending comes first
    usort($results, function($a, $b) {
        if ($a['sent_today'] === $b['sent_today']) return 0;
        return $a['sent_today'] ? 1 : -1;
    });
    
    return [
        'records' => $results,
        'total' => count($results)
    ];
}

/**
 * Send daily summary to selected users
 */
function sendDailySummary($db, $userIds)
{
    if (empty($userIds)) {
        return ['success_count' => 0, 'failed_count' => 0];
    }
    
    // 1. Get the latest data again just to be safe
    $previewData = getDailySummaryPreview($db);
    $records = $previewData['records'];
    
    // Map records by user_id
    $userRecords = [];
    foreach ($records as $record) {
        $userRecords[$record['line_user_id']] = $record;
    }
    
    $successCount = 0;
    $failedCount = 0;
    
    require_once __DIR__ . '/../classes/OdooFlexTemplates.php';
    require_once __DIR__ . '/../classes/OdooWebhookHandler.php';
    
    // We need a dummy handler to use its Line API methods
    $handler = new OdooWebhookHandler($db, null);
    
    foreach ($userIds as $userId) {
        if (!isset($userRecords[$userId])) continue;
        
        $record = $userRecords[$userId];
        if (empty($record['orders'])) continue;
        
        // Skip if already sent today
        if ($record['sent_today']) {
            continue;
        }
        
        // Get user's Line access token
        $accessToken = null;
        $user = $handler->findLineUserAcrossAccounts(null, $userId);
        if ($user && !empty($user['channel_access_token'])) {
            $accessToken = $user['channel_access_token'];
        }
        
        if (!$accessToken) {
            $failedCount++;
            continue;
        }
        
        // Generate Flex Message
        $flexBubble = null;
        try {
            $flexBubble = OdooFlexTemplates::dailySummary($record);
        } catch (Exception $e) {
            error_log('Daily Summary Flex Template error: ' . $e->getMessage());
            $failedCount++;
            continue;
        }
        
        // Send via Line API using reflection or public methods if available
        // In our case, we'll implement a direct curl call here to avoid changing OdooWebhookHandler's access modifiers
        $sent = false;
        $apiError = null;
        $apiStatus = null;
        $startTime = microtime(true);
        
        try {
            $body = json_encode([
                'to' => $userId,
                'messages' => [[
                    'type'    => 'flex',
                    'altText' => 'สรุปออเดอร์ประจำวันของคุณ',
                    'contents' => $flexBubble,
                ]]
            ]);

            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken,
                ],
                CURLOPT_TIMEOUT => 10,
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $apiStatus = $httpCode;
            $sent      = ($httpCode >= 200 && $httpCode < 300);
            if (!$sent) {
                $apiError = $response;
                error_log("LINE push failed (daily_summary) [{$httpCode}]: {$response}");
            }
        } catch (Exception $e) {
            $apiError = $e->getMessage();
            error_log('LINE push exception (daily_summary): ' . $e->getMessage());
        }
        
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        
        // Log to notification log
        try {
            $status = $sent ? 'sent' : 'failed';
            $deliveryId = 'daily_summary_' . date('Ymd_His') . '_' . substr(md5($userId), 0, 8);
            
            $stmt = $db->prepare("
                INSERT INTO odoo_notification_log
                (delivery_id, event_type, recipient_type, line_user_id,
                 notification_method, status, line_api_status, line_api_response,
                 error_message, latency_ms, sent_at)
                VALUES (?, 'daily.summary', 'customer', ?, 'flex', ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $deliveryId,
                $userId,
                $status,
                $apiStatus,
                $sent ? null : json_encode(['error' => $apiError]),
                $sent ? null : $apiError,
                $latencyMs,
            ]);
            
            if ($sent) {
                $successCount++;
            } else {
                $failedCount++;
            }
        } catch (Exception $e) {
            error_log('Error logging daily summary to odoo_notification_log: ' . $e->getMessage());
            // Still count as success/failed based on actual API result
            if ($sent) $successCount++; else $failedCount++;
        }
    }
    
    return [
        'success_count' => $successCount,
        'failed_count' => $failedCount
    ];
}

/**
 * Send BDO payment notification to customer via LINE Flex Message.
 *
 * Requires: bdo_id, partner_id (optional: line_user_id)
 * Reads data from local DB (odoo_bdo_context + odoo_bdos).
 * Generates QR from EMVCo payload if available.
 * Uses OdooFlexTemplates::bdoPaymentRequest() for Flex bubble.
 */
function sendBdoPaymentNotification($db, $input)
{
    $bdoId     = (int) ($input['bdo_id'] ?? 0);
    $partnerId = (int) ($input['partner_id'] ?? 0);

    if ($bdoId <= 0) {
        throw new Exception('กรุณาระบุ BDO ID');
    }

    // ── 1. Resolve LINE user ────────────────────────────────────────────
    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));
    if ($lineUserId === '' && $partnerId > 0) {
        $lineUserId = resolveLineUserIdFromPartner($db, $partnerId);
    }
    if ($lineUserId === '') {
        throw new Exception('ลูกค้ายังไม่ได้เชื่อมต่อ LINE — ไม่สามารถส่งแจ้งเตือนได้');
    }

    // ── 2. Throttle check (5 min cooldown per BDO) ──────────────────────
    try {
        $throttleStmt = $db->prepare("
            SELECT sent_at FROM odoo_notification_log 
            WHERE event_type = 'bdo.payment_request' AND delivery_id LIKE ?
            AND status = 'sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY sent_at DESC LIMIT 1
        ");
        $throttleStmt->execute(['bdo_pay_' . $bdoId . '_%']);
        if ($throttleStmt->fetch()) {
            throw new Exception('เพิ่งส่งแจ้งเตือน BDO นี้ไปไม่นาน กรุณารอ 5 นาที');
        }
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'กรุณารอ') !== false) throw $e;
        // Ignore throttle check errors
    }

    // ── 3. Load BDO data from local DB ──────────────────────────────────
    $stmt = $db->prepare("
        SELECT b.bdo_id, b.bdo_name, b.order_name, b.amount_total, b.bdo_date, b.state,
               b.delivery_type, b.customer_ref, b.partner_id,
               c.qr_payload, c.statement_pdf_path, c.financial_summary_json, c.amount AS ctx_amount
        FROM odoo_bdos b
        LEFT JOIN odoo_bdo_context c ON c.bdo_id = b.bdo_id
        WHERE b.bdo_id = ?
        ORDER BY c.updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$bdoId]);
    $bdo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bdo) {
        // Try context table alone
        $stmt = $db->prepare("SELECT * FROM odoo_bdo_context WHERE bdo_id = ? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$bdoId]);
        $bdo = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$bdo) {
        throw new Exception('ไม่พบข้อมูล BDO #' . $bdoId);
    }

    // ── 4. Resolve LINE account for access token ────────────────────────
    $lineAccountId = resolveLineAccountId($db, $lineUserId);
    if ($lineAccountId <= 0) {
        throw new Exception('ไม่สามารถระบุ LINE Account ได้');
    }

    // Get access token
    require_once __DIR__ . '/../classes/OdooWebhookHandler.php';
    $handler = new OdooWebhookHandler($db, null);
    $user = $handler->findLineUserAcrossAccounts(null, $lineUserId);
    if (!$user || empty($user['channel_access_token'])) {
        throw new Exception('ไม่พบ LINE access token สำหรับลูกค้านี้');
    }
    $accessToken = $user['channel_access_token'];

    // ── 5. Build data for Flex template ─────────────────────────────────
    $amountTotal = (float) ($bdo['amount_total'] ?? $bdo['ctx_amount'] ?? 0);

    // Parse net-to-pay from financial summary if available
    $netToPay = $amountTotal;
    if (!empty($bdo['financial_summary_json'])) {
        $fs = json_decode($bdo['financial_summary_json'], true);
        if (is_array($fs) && isset($fs['net_to_pay'])) {
            $netToPay = (float) $fs['net_to_pay'];
        } elseif (is_array($fs) && isset($fs['amount_net_to_pay'])) {
            $netToPay = (float) $fs['amount_net_to_pay'];
        }
    }

    $bdoRef   = $bdo['bdo_name'] ?? ('BDO-' . $bdoId);
    $orderRef = $bdo['order_name'] ?? '-';
    $dueDate  = $bdo['bdo_date'] ?? date('Y-m-d');

    // Statement PDF URL
    $baseUrl = defined('BASE_URL') ? BASE_URL : 'https://cny.re-ya.com';
    $invoicePdfUrl = !empty($bdo['statement_pdf_path'])
        ? $baseUrl . '/api/odoo-dashboard-api.php?action=odoo_bdo_statement_pdf&bdo_id=' . urlencode($bdoId)
        : $baseUrl;

    // Resolve LIFF ID from line_accounts table for upload slip link
    $liffUrl = '';
    try {
        $liffStmt = $db->prepare("SELECT liff_id FROM line_accounts WHERE id = ? LIMIT 1");
        $liffStmt->execute([$lineAccountId]);
        $liffRow = $liffStmt->fetch(PDO::FETCH_ASSOC);
        if ($liffRow && !empty($liffRow['liff_id'])) {
            $liffUrl = 'https://liff.line.me/' . $liffRow['liff_id'] . '?page=slip-upload&bdo_id=' . urlencode($bdoId);
        }
    } catch (Exception $e) { /* ignore */ }

    $flexData = [
        'amount_total' => $netToPay > 0 ? $netToPay : $amountTotal,
        'bdo_ref'      => $bdoRef,
        'order_ref'    => $orderRef,
        'due_date'     => $dueDate,
        'bank_account' => [
            'bank_name'      => 'ธนาคารกสิกรไทย',
            'account_number' => '027-8-40955-4',
            'account_name'   => 'บริษัท ซีเอ็นวาย จำกัด',
        ],
        'invoice' => [
            'pdf_url' => $invoicePdfUrl,
        ],
        'liff_url' => $liffUrl,
    ];

    // ── 6. Generate QR code from EMVCo payload ──────────────────────────
    $qrCodeUrl = '';
    if (!empty($bdo['qr_payload'])) {
        require_once __DIR__ . '/../classes/QRCodeGenerator.php';
        $qrGen = new QRCodeGenerator();
        $qrResult = $qrGen->generatePromptPayQR($bdo['qr_payload'], $bdoRef);
        if ($qrResult['success'] && !empty($qrResult['url'])) {
            $qrCodeUrl = $baseUrl . $qrResult['url'];
        }
    }

    // Use a placeholder QR if generation failed — still send the notification
    if (empty($qrCodeUrl)) {
        // Use a simple PromptPay placeholder image
        $qrCodeUrl = $baseUrl . '/assets/img/promptpay-placeholder.png';
    }

    // ── 7. Build Flex Message ───────────────────────────────────────────
    require_once __DIR__ . '/../classes/OdooFlexTemplates.php';
    $flexBubble = OdooFlexTemplates::bdoPaymentRequest($flexData, $qrCodeUrl);

    // ── 8. Send via LINE Push API ───────────────────────────────────────
    $altText = '💰 แจ้งชำระเงิน ' . $bdoRef . ' ยอด ฿' . number_format($flexData['amount_total'], 2);

    $body = json_encode([
        'to'       => $lineUserId,
        'messages' => [[
            'type'     => 'flex',
            'altText'  => $altText,
            'contents' => $flexBubble,
        ]]
    ], JSON_UNESCAPED_UNICODE);

    $sent = false;
    $apiError = null;
    $apiStatus = null;
    $startTime = microtime(true);

    try {
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $apiStatus = $httpCode;
        $sent      = ($httpCode >= 200 && $httpCode < 300);
        if (!$sent) {
            $apiError = $response;
            error_log("[sendBdoPaymentNotification] LINE push failed [{$httpCode}]: {$response}");
        }
    } catch (Exception $e) {
        $apiError = $e->getMessage();
        error_log('[sendBdoPaymentNotification] exception: ' . $e->getMessage());
    }

    $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

    // ── 9. Log to notification log ──────────────────────────────────────
    try {
        $deliveryId = 'bdo_pay_' . $bdoId . '_' . date('Ymd_His') . '_' . substr(md5($lineUserId), 0, 8);
        $status = $sent ? 'sent' : 'failed';

        $logStmt = $db->prepare("
            INSERT INTO odoo_notification_log
            (delivery_id, event_type, recipient_type, line_user_id,
             notification_method, status, line_api_status, line_api_response,
             error_message, latency_ms, sent_at)
            VALUES (?, 'bdo.payment_request', 'customer', ?, 'flex', ?, ?, ?, ?, ?, NOW())
        ");
        $logStmt->execute([
            $deliveryId,
            $lineUserId,
            $status,
            $apiStatus,
            $sent ? null : json_encode(['error' => $apiError]),
            $sent ? null : $apiError,
            $latencyMs,
        ]);
    } catch (Exception $e) {
        error_log('[sendBdoPaymentNotification] log error: ' . $e->getMessage());
    }

    if (!$sent) {
        throw new Exception('ส่งแจ้งเตือนไม่สำเร็จ: ' . ($apiError ?: 'Unknown error'));
    }

    return [
        'sent'       => true,
        'bdo_id'     => $bdoId,
        'bdo_ref'    => $bdoRef,
        'amount'     => $flexData['amount_total'],
        'has_qr'     => !empty($bdo['qr_payload']),
        'latency_ms' => $latencyMs,
    ];
}

/**
 * Get notification log stats and paginated records.
 */
function getNotificationLog($db, $input)
{
    $limit = min((int) ($input['limit'] ?? 30), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $filterStatus = trim((string) ($input['status'] ?? ''));
    $filterEvent = trim((string) ($input['event_type'] ?? ''));
    $dateFrom = trim((string) ($input['date_from'] ?? ''));
    $dateTo = trim((string) ($input['date_to'] ?? ''));

    if (!tableExists($db, 'odoo_notification_log')) {
        return [
            'available' => false,
            'stats' => [],
            'records' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    // Stats — single-pass aggregate replaces 5 separate COUNT queries
    $stats = [];
    try {
        $statsRows = $db->query("
            SELECT status, COUNT(*) as count
            FROM odoo_notification_log
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($statsRows as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }
        $stats['total'] = array_sum($stats);

        // One aggregation query replaces the previous 5 individual COUNT queries
        $aggRow = $db->query("
            SELECT
                SUM(IF(status = 'sent'   AND sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY, 1, 0)) as today_sent,
                SUM(IF(status = 'failed' AND sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY, 1, 0)) as today_failed,
                SUM(IF(sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY, 1, 0)) as today_total,
                COUNT(DISTINCT CASE WHEN status = 'sent' THEN line_user_id END) as unique_users,
                COUNT(DISTINCT CASE WHEN status = 'sent' AND sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY THEN line_user_id END) as unique_users_today
            FROM odoo_notification_log
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['today_sent']        = (int) ($aggRow['today_sent'] ?? 0);
        $stats['today_failed']      = (int) ($aggRow['today_failed'] ?? 0);
        $stats['today_total']       = (int) ($aggRow['today_total'] ?? 0);
        $stats['unique_users']      = (int) ($aggRow['unique_users'] ?? 0);
        $stats['unique_users_today'] = (int) ($aggRow['unique_users_today'] ?? 0);

        $eventRows = $db->query("
            SELECT event_type, COUNT(*) as count
            FROM odoo_notification_log
            WHERE status = 'sent' AND sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY
            GROUP BY event_type
            ORDER BY count DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $stats['events_today'] = $eventRows;
    } catch (Exception $e) {
        $stats['error'] = $e->getMessage();
    }

    // Records with filters
    $where = [];
    $params = [];

    if ($filterStatus !== '') {
        $where[] = 'status = ?';
        $params[] = $filterStatus;
    }

    if ($filterEvent !== '') {
        $where[] = 'event_type = ?';
        $params[] = $filterEvent;
    }

    if ($dateFrom !== '') {
        $where[] = 'sent_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $where[] = 'sent_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_notification_log {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $hasWhLog = tableExists($db, 'odoo_webhooks_log');
    // Scope the derived-table join to the last 30 days to avoid materialising
    // the entire odoo_webhooks_log table on every Notifications tab load.
    if ($hasWhLog) {
        $whTimeCol = resolveWebhookTimeColumn($db);
        $whWindow  = $whTimeCol
            ? "AND {$whTimeCol} >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
            : "";
        $whJoin = "LEFT JOIN (SELECT delivery_id, MAX(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name'))) as order_name, MAX(order_id) as order_id FROM odoo_webhooks_log WHERE delivery_id IS NOT NULL {$whWindow} GROUP BY delivery_id) wh ON wh.delivery_id = n.delivery_id";
    } else {
        $whJoin = "";
    }
    $whSelect = $hasWhLog ? ", wh.order_name, wh.order_id" : ", NULL as order_name, NULL as order_id";

    $stmt = $db->prepare("
        SELECT
            n.id,
            n.delivery_id,
            n.event_type,
            n.recipient_type,
            n.line_user_id,
            n.notification_method,
            n.status,
            n.line_api_status,
            n.error_message,
            n.skip_reason,
            n.sent_at,
            n.latency_ms,
            u.display_name as user_name,
            u.picture_url as user_avatar
            {$whSelect}
        FROM odoo_notification_log n
        LEFT JOIN users u ON u.line_user_id = n.line_user_id
        {$whJoin}
        {$whereClause}
        ORDER BY n.sent_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eventTypes = $db->query("
        SELECT DISTINCT event_type FROM odoo_notification_log
        WHERE event_type IS NOT NULL AND event_type <> ''
        ORDER BY event_type
    ")->fetchAll(PDO::FETCH_COLUMN);

    return [
        'available' => true,
        'stats' => $stats,
        'records' => $records,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'event_types' => $eventTypes
    ];
}

/**
 * Get orders grouped by order_name for today (or specified date), with progress %.
 */
function getOrderGroupedToday($db, $input)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr = $processedAtColumn ?: 'NOW()';
    $orderByExpr = $processedAtColumn ?: '`id`';

    $date = trim((string) ($input['date'] ?? ''));
    if ($date === '') {
        $date = date('Y-m-d');
    }

    $limit = min((int) ($input['limit'] ?? 50), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $search = trim((string) ($input['search'] ?? ''));

    $salespersonId = trim((string) ($input['salesperson_id'] ?? ''));

    $orderNameExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')), ''), CAST(order_id AS CHAR))";
    $customerNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '')";
    $customerRefExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
    $customerLineExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')), '')";
    $amountExpr = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))";
    $spIdExpr   = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.id')), '')";
    $spNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.name')), '')";

    $where = ["{$orderNameExpr} IS NOT NULL"];
    $params = [];

    if ($processedAtColumn) {
        // Range scan (can use index on processed_at) instead of DATE() which forces a full scan
        $where[] = "{$processedAtColumn} >= ? AND {$processedAtColumn} < ? + INTERVAL 1 DAY";
        $params[] = $date;
        $params[] = $date;
    }

    if ($search !== '') {
        $where[] = "({$orderNameExpr} LIKE ? OR {$customerNameExpr} LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
    }

    if ($salespersonId !== '') {
        $where[] = "{$spIdExpr} = ?";
        $params[] = $salespersonId;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Count distinct orders
    $countSql = "SELECT COUNT(*) FROM (SELECT {$orderNameExpr} as grp FROM odoo_webhooks_log {$whereClause} GROUP BY grp) t";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Get grouped orders (latest event per order)
    $sql = "
        SELECT
            {$orderNameExpr} as order_name,
            MAX(order_id) as order_id,
            MAX({$customerNameExpr}) as customer_name,
            MAX({$customerRefExpr}) as customer_ref,
            MAX({$customerLineExpr}) as customer_line_user_id,
            MAX(line_user_id) as line_user_id,
            MAX({$amountExpr}) as amount_total,
            MAX({$spIdExpr}) as salesperson_id,
            MAX({$spNameExpr}) as salesperson_name,
            COUNT(*) as event_count,
            MAX(event_type) as latest_event_type,
            MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')), '')) as latest_state_display,
            MAX({$processedAtExpr}) as last_updated_at,
            SUM(IF(status IN ('failed','retry','dead_letter'), 1, 0)) as error_count,
            GROUP_CONCAT(
                CONCAT(event_type, '||', COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')), ''), event_type), '||', {$processedAtExpr}, '||', status)
                ORDER BY {$orderByExpr} ASC
                SEPARATOR ';;'
            ) as events_concat
        FROM odoo_webhooks_log
        {$whereClause}
        GROUP BY {$orderNameExpr}
        ORDER BY last_updated_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Order lifecycle stage → progress % (CNY 13-state pipeline)
    $stageProgress = [
        'sale.order.created'    => 5,
        'sale.order.confirmed'  => 10,
        'order.validated'       => 10,
        'order.picker_assigned' => 20,
        'order.picking'         => 28,
        'order.picked'          => 36,
        'order.packing'         => 44,
        'order.packed'          => 52,
        'order.reserved'        => 58,
        'order.awaiting_payment'=> 65,
        'order.paid'            => 75,
        'order.to_delivery'     => 82,
        'order.in_delivery'     => 90,
        'order.delivered'       => 100,
        'delivery.validated'    => 40,
        'delivery.in_transit'   => 65,
        'delivery.done'         => 80,
        'invoice.posted'        => 85,
        'invoice.created'       => 85,
        'invoice.paid'          => 100,
        'payment.received'      => 100,
        'payment.confirmed'     => 100,
        'sale.order.done'       => 100,
        'sale.order.cancelled'  => -1,
        'delivery.cancelled'    => -1,
        'invoice.cancelled'     => -1,
        'order.cancelled'       => -1,
        'delivery.back_order'   => 50,
        'invoice.overdue'       => 88,
    ];

    $orders = [];
    foreach ($rows as $row) {
        // Parse events_concat into array
        $events = [];
        $maxProgress = 0;
        $isCancelled = false;
        if (!empty($row['events_concat'])) {
            $parts = explode(';;', $row['events_concat']);
            foreach ($parts as $part) {
                $segs = explode('||', $part);
                $evType = $segs[0] ?? '';
                $evLabel = $segs[1] ?? $evType;
                $evTime = $segs[2] ?? null;
                $evStatus = $segs[3] ?? '';
                $stageKey = inferOrderProgressStageKey($evType, $evLabel);
                $events[] = [
                    'event_type' => $evType,
                    'stage_key' => $stageKey,
                    'label' => $evLabel,
                    'time' => $evTime,
                    'status' => $evStatus,
                ];
                $p = $stageProgress[$stageKey] ?? ($stageProgress[$evType] ?? 0);
                if ($p === -1) {
                    $isCancelled = true;
                } elseif ($p > $maxProgress) {
                    $maxProgress = $p;
                }
            }
        }

        $orders[] = [
            'order_name'           => $row['order_name'],
            'order_id'             => $row['order_id'],
            'customer_name'        => $row['customer_name'],
            'customer_ref'         => $row['customer_ref'],
            'customer_line_user_id'=> $row['customer_line_user_id'] ?: $row['line_user_id'],
            'amount_total'         => $row['amount_total'] ? (float) $row['amount_total'] : null,
            'event_count'          => (int) $row['event_count'],
            'latest_event_type'    => $row['latest_event_type'],
            'latest_state_display' => $row['latest_state_display'],
            'last_updated_at'      => $row['last_updated_at'],
            'has_error'            => ((int) $row['error_count']) > 0,
            'progress'             => $isCancelled ? -1 : $maxProgress,
            'is_cancelled'         => $isCancelled,
            'events'               => $events,
        ];
    }

    return [
        'orders' => $orders,
        'total' => $total,
        'date' => $date,
        'limit' => $limit,
        'offset' => $offset,
    ];
}

/**
 * Single batch endpoint for dashboard overview: stats, orders, overdue, pending BDO, slips.
 */
/**
 * Lightweight stats for the overview section only.
 * Avoids the full-table-scan + JSON_EXTRACT-on-all-rows that getStats() does.
 *
 * Strategy:
 *  1) All-time status counts: windowed to last 90 days, no JSON_EXTRACT
 *  2) Today-only: range scan on processed_at (index-friendly), JSON_EXTRACT on today's rows only
 */
function getStatsMini($db)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $processedAtExpr   = $processedAtColumn ?: 'NOW()';

    // ── All-time windowed counts (no JSON_EXTRACT, no DATE() on every row) ──
    $window = webhookRecentWindowWhere($db, $processedAtColumn, 90, 100000);
    $alltime = $db->query("
        SELECT
            COUNT(*) as total,
            SUM(IF(status = 'success', 1, 0)) as success,
            SUM(IF(status = 'dead_letter', 1, 0)) as dead_letter,
            SUM(IF(status = 'failed', 1, 0)) as failed,
            SUM(IF(status = 'retry', 1, 0)) as retry_cnt,
            MAX({$processedAtExpr}) as last_webhook
        FROM odoo_webhooks_log
        WHERE {$window}
    ")->fetch(PDO::FETCH_ASSOC);

    // ── Today-only stats: range scan on index, JSON_EXTRACT only on today's rows ──
    if ($processedAtColumn) {
        // Range scan — can use index on processed_at
        $todayFilter = "{$processedAtColumn} >= CURDATE() AND {$processedAtColumn} < CURDATE() + INTERVAL 1 DAY";
    } else {
        $todayFilter = '1=0';
    }
    $orderNameExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')), ''), CAST(order_id AS CHAR))";
    $today = $db->query("
        SELECT
            COUNT(*) as today,
            COUNT(DISTINCT {$orderNameExpr}) as unique_orders_today,
            SUM(IF(line_user_id IS NOT NULL, 1, 0)) as notified_today
        FROM odoo_webhooks_log
        WHERE {$todayFilter}
    ")->fetch(PDO::FETCH_ASSOC);

    return [
        'total'               => (int)  ($alltime['total']        ?? 0),
        'success'             => (int)  ($alltime['success']      ?? 0),
        'dead_letter'         => (int)  ($alltime['dead_letter']  ?? 0),
        'failed'              => (int)  ($alltime['failed']       ?? 0),
        'retry'               => (int)  ($alltime['retry_cnt']    ?? 0),
        'last_webhook'        =>         $alltime['last_webhook'] ?? null,
        'today'               => (int)  ($today['today']              ?? 0),
        'unique_orders_today' => (int)  ($today['unique_orders_today'] ?? 0),
        'notified_today'      => (int)  ($today['notified_today']      ?? 0),
        // Placeholders to keep JS backward-compatible
        'received'            => 0,
        'processing'          => 0,
        'duplicate'           => 0,
        'avg_latency_ms'      => null,
        'retried_total'       => 0,
        'dlq_total'           => 0,
        'events_by_type'      => [],
        'top_failed_events'   => [],
        'hourly_distribution' => [],
    ];
}

function getOverviewToday($db)
{
    // ── 1. Stats from webhook log (lightweight) ──
    $stats = getStatsMini($db);

    // ── 2. Today's orders — use odoo_orders sync table (no JSON_EXTRACT) ──
    $orders = [];
    $ordersTotal = 0;
    $ordersDate  = date('Y-m-d');
    try {
        if (tableExists($db, 'odoo_orders')) {
            $countStmt = $db->query("SELECT COUNT(*) FROM odoo_orders WHERE DATE(date_order) = CURDATE() OR DATE(updated_at) = CURDATE()");
            $ordersTotal = (int) $countStmt->fetchColumn();

            $stmt = $db->query("
                SELECT
                    order_id, order_name, customer_ref, line_user_id,
                    salesperson_id, salesperson_name, state, state_display,
                    amount_total, date_order, updated_at,
                    COALESCE(state_display, state, '') as latest_state_display,
                    latest_event as latest_event_type,
                    NULL as customer_line_user_id,
                    COALESCE(
                        (SELECT display_name FROM users u WHERE u.line_user_id = o.line_user_id LIMIT 1),
                        customer_ref
                    ) as customer_name,
                    ROUND(
                        CASE
                            WHEN state IN ('done','sale') THEN 100
                            WHEN state = 'cancel' THEN 0
                            ELSE 50
                        END
                    ) as progress
                FROM odoo_orders o
                WHERE DATE(date_order) = CURDATE() OR DATE(updated_at) = CURDATE()
                ORDER BY updated_at DESC
                LIMIT 5
            ");
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($orders as &$o) {
                $o['order_id']    = (int) ($o['order_id'] ?? 0);
                $o['amount_total'] = (float) ($o['amount_total'] ?? 0);
                $o['progress']     = (int) ($o['progress'] ?? 50);
            }
            unset($o);
        } else {
            // Fallback: webhook log (original logic)
            $fallback = getOrderGroupedToday($db, ['limit' => 5, 'offset' => 0]);
            $orders = $fallback['orders'] ?? [];
            $ordersTotal = $fallback['total'] ?? 0;
            $ordersDate  = $fallback['date'] ?? $ordersDate;
        }
    } catch (Exception $e) {
        $orders = [];
    }

    // ── 3. Overdue customers — use odoo_customer_projection (already fast) ──
    $overdueData = getCustomerList($db, [
        'invoice_filter' => 'overdue',
        'limit' => 5,
        'offset' => 0,
        'sort_by' => 'due_desc',
    ]);

    // ── 4. Pending BDOs — use odoo_bdos sync table (no JSON_EXTRACT) ──
    $pendingBdo = ['orders' => [], 'bdos' => [], 'total' => 0];
    try {
        if (tableExists($db, 'odoo_bdos')) {
            $stmt = $db->query("
                SELECT id, bdo_id, bdo_name, partner_id, customer_ref,
                       amount_net_to_pay, payment_state, state, due_date, updated_at
                FROM odoo_bdos
                WHERE payment_state NOT IN ('paid','reversed','in_payment')
                  AND state != 'cancel'
                ORDER BY due_date ASC, updated_at DESC
                LIMIT 20
            ");
            $bdos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($bdos as &$b) {
                $b['amount_net_to_pay'] = (float) ($b['amount_net_to_pay'] ?? 0);
            }
            unset($b);
            $pendingBdo = ['orders' => $bdos, 'bdos' => $bdos, 'total' => count($bdos)];
        } else {
            // Fallback: existing function
            $pendingBdo = getOdooBdos($db, ['limit' => 20, 'offset' => 0]);
        }
    } catch (Exception $e) {
        $pendingBdo = getOdooBdos($db, ['limit' => 20, 'offset' => 0]);
    }

    // ── 5. Slips summary ──
    $slips = getOverviewSlipsSummary($db);

    return [
        'stats' => $stats,
        'orders' => $orders,
        'orders_total' => $ordersTotal,
        'orders_date' => $ordersDate,
        'overdue_customers' => $overdueData['customers'] ?? [],
        'overdue_total' => $overdueData['total'] ?? 0,
        'pending_bdo' => $pendingBdo,
        'slips_pending_total' => $slips['pending_total'],
        'slips_pending' => $slips['pending_slips'],
        'slips_matched_today_count' => $slips['matched_today_count'],
        'slips_matched_today_sum' => $slips['matched_today_sum'],
    ];
}

function getOverviewSlipsSummary($db)
{
    $out = [
        'pending_total' => 0,
        'pending_slips' => [],
        'matched_today_count' => 0,
        'matched_today_sum' => 0.0,
    ];
    if (!tableExists($db, 'odoo_slip_uploads')) {
        return $out;
    }
    try {
        $openStatuses = BdoSlipContract::getOpenSlipStatuses();
        $openPlaceholders = implode(', ', array_fill(0, count($openStatuses), '?'));
        $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_slip_uploads WHERE status IN ({$openPlaceholders})");
        $countStmt->execute($openStatuses);
        $out['pending_total'] = (int) $countStmt->fetchColumn();
        $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : 'https://cny.re-ya.com', '/');
        $stmt = $db->prepare("
            SELECT s.id, s.line_user_id, s.amount, s.transfer_date, s.image_path, s.image_url, s.uploaded_at, s.status,
                   u.display_name AS customer_name
            FROM odoo_slip_uploads s
            LEFT JOIN users u ON u.line_user_id = s.line_user_id
            WHERE s.status IN ({$openPlaceholders})
            ORDER BY s.uploaded_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['amount'] = $row['amount'] !== null ? (float) $row['amount'] : null;
            $row['image_full_url'] = !empty($row['image_path']) ? $baseUrl . '/' . ltrim($row['image_path'], '/') : ($row['image_url'] ?? null);
            unset($row['image_path'], $row['image_url']);
            $out['pending_slips'][] = $row;
        }
        $matchedStmt = $db->prepare("
            SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total
            FROM odoo_slip_uploads
            WHERE status = 'matched' AND DATE(COALESCE(matched_at, uploaded_at)) = CURDATE()
        ");
        $matchedStmt->execute();
        $matchedRow = $matchedStmt->fetch(PDO::FETCH_ASSOC);
        $out['matched_today_count'] = (int) ($matchedRow['cnt'] ?? 0);
        $out['matched_today_sum'] = (float) ($matchedRow['total'] ?? 0);
    } catch (Exception $e) {
        // leave defaults
    }
    return $out;
}

/**
 * Normalize raw webhook event/state text into a canonical lifecycle stage key.
 * This lets grouped progress work for both event_type and state-display-driven logs.
 */
function inferOrderProgressStageKey($eventType, $stateLabel)
{
    $eventType = trim((string) $eventType);
    $stateLabel = trim((string) $stateLabel);

    // Internal warehouse pipeline events (LINE/shop flow) should map to delivery stages,
    // not order completion/payment stages.
    if ($eventType !== '') {
        $orderEventMap = [
            'order.validated'        => 'order.validated',
            'order.picker_assigned'  => 'order.picker_assigned',
            'order.picking'          => 'order.picking',
            'order.picked'           => 'order.picked',
            'order.packing'          => 'order.packing',
            'order.packed'           => 'order.packed',
            'order.reserved'         => 'order.reserved',
            'order.awaiting_payment' => 'order.awaiting_payment',
            'order.paid'             => 'order.paid',
            'order.to_delivery'      => 'order.to_delivery',
            'order.in_delivery'      => 'order.in_delivery',
            'order.delivered'        => 'order.delivered',
            'order.cancelled'        => 'sale.order.cancelled',
        ];
        if (isset($orderEventMap[$eventType])) {
            return $orderEventMap[$eventType];
        }
    }

    if ($eventType !== '') {
        $known = [
            'sale.order.created', 'sale.order.confirmed', 'delivery.validated', 'delivery.in_transit',
            'delivery.done', 'invoice.posted', 'invoice.created', 'invoice.paid', 'payment.received',
            'payment.confirmed', 'sale.order.done', 'sale.order.cancelled', 'delivery.cancelled',
            'invoice.cancelled', 'delivery.back_order', 'invoice.overdue',
            'order.validated', 'order.picker_assigned', 'order.picking', 'order.picked',
            'order.packing', 'order.packed', 'order.reserved', 'order.awaiting_payment',
            'order.paid', 'order.to_delivery', 'order.in_delivery', 'order.delivered',
        ];
        if (in_array($eventType, $known, true)) {
            return $eventType;
        }
    }

    $lower = function ($text) {
        $text = (string) $text;
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }
        return strtolower($text);
    };
    $contains = function ($haystack, $needle) {
        if ($needle === '') return false;
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle) !== false;
        }
        return strpos($haystack, $needle) !== false;
    };

    $text = $lower($eventType . ' ' . $stateLabel);

    // Cancelled states
    if (
        $contains($text, 'cancel') ||
        $contains($text, 'ยกเลิก')
    ) {
        return 'sale.order.cancelled';
    }

    // Awaiting payment
    if (
        $contains($text, 'awaiting_payment') ||
        $contains($text, 'รอชำระเงิน')
    ) {
        return 'order.awaiting_payment';
    }

    // Reserved
    if (
        $contains($text, 'reserved') ||
        $contains($text, 'จองสินค้า')
    ) {
        return 'order.reserved';
    }

    // Payment/invoice completion
    if (
        $contains($text, 'payment.received') ||
        $contains($text, 'payment.confirmed') ||
        $contains($text, 'invoice.paid') ||
        $contains($text, ' paid') ||
        $contains($text, 'ชำระแล้ว')
    ) {
        return 'order.paid';
    }

    // Final completion
    if (
        $contains($text, 'sale.order.done') ||
        $contains($text, 'delivery.done') ||
        $contains($text, 'completed') ||
        $contains($text, 'done') ||
        $contains($text, 'เสร็จสิ้น')
    ) {
        return 'sale.order.done';
    }

    // To delivery / in transit / ready to ship
    if (
        $contains($text, 'to_delivery') ||
        $contains($text, 'เตรียมส่ง') ||
        $contains($text, 'พร้อมส่ง')
    ) {
        return 'order.to_delivery';
    }

    if (
        $contains($text, 'in_delivery') ||
        $contains($text, 'delivery.in_transit') ||
        $contains($text, 'ready_to_ship') ||
        $contains($text, 'ready to ship') ||
        $contains($text, 'กำลังจัดส่ง')
    ) {
        return 'order.in_delivery';
    }

    // Packing
    if (
        $contains($text, 'packing') ||
        $contains($text, 'กำลังแพ็ค') ||
        $contains($text, 'แพ็คสินค้า')
    ) {
        return 'order.packing';
    }

    // Picking / warehouse prep
    if (
        $contains($text, 'delivery.validated') ||
        $contains($text, 'picked') ||
        $contains($text, 'เตรียมสินค้า')
    ) {
        return 'order.picking';
    }

    // Confirmed order / waiting delivery confirmation
    if (
        $contains($text, 'sale.order.confirmed') ||
        $contains($text, 'confirmed') ||
        $contains($text, 'รอยืนยันจัดส่ง') ||
        $contains($text, 'ยืนยันออเดอร์') ||
        $contains($text, 'waiting_delivery_confirmation')
    ) {
        return 'sale.order.confirmed';
    }

    // Invoice posted/created (after delivery)
    if (
        $contains($text, 'invoice.posted') ||
        $contains($text, 'invoice.created') ||
        $contains($text, 'แจ้งหนี้') ||
        $contains($text, 'วางบิล')
    ) {
        return 'invoice.posted';
    }

    return $eventType;
}

/**
 * Check whether a column exists in odoo_webhooks_log.
 *
 * @param PDO $db
 * @param string $column
 * @return bool
 */
function hasWebhookColumn($db, $column)
{
    static $cache = [];

    $column = (string) $column;
    if ($column === '') {
        return false;
    }

    if (!isset($cache[$column])) {
        try {
            $stmt = $db->prepare("
                SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'odoo_webhooks_log'
                  AND COLUMN_NAME = ?
                LIMIT 1
            ");
            $stmt->execute([$column]);
            $cache[$column] = (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            $quoted = $db->quote($column);
            $stmt = $db->query("SHOW COLUMNS FROM `odoo_webhooks_log` LIKE {$quoted}");
            $cache[$column] = $stmt ? ($stmt->rowCount() > 0) : false;
        }
    }

    return $cache[$column];
}

/**
 * Resolve the best available webhook timestamp column expression.
 *
 * @param PDO $db
 * @return string|null Backticked column name or null if none found.
 */
function resolveWebhookTimeColumn($db)
{
    foreach (['processed_at', 'created_at', 'received_at', 'updated_at'] as $column) {
        if (hasWebhookColumn($db, $column)) {
            return "`{$column}`";
        }
    }

    return null;
}

function webhookRecentWindowWhere($db, $processedAtColumn, $days = 180, $maxRows = 80000)
{
    $days = max(1, (int) $days);
    $maxRows = max(1000, (int) $maxRows);

    if ($processedAtColumn) {
        return "{$processedAtColumn} >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
    }

    return "id >= GREATEST((SELECT MAX(id) - {$maxRows} FROM odoo_webhooks_log), 0)";
}

/**
 * Get ORDER BY expression for webhook fallback customer list sorting.
 */
function webhookCustomerSortExpr($sortBy)
{
    $map = [
        'spend_desc'  => 'spend_30d DESC, latest_order_at DESC',
        'spend_asc'   => 'spend_30d ASC, latest_order_at DESC',
        'orders_desc' => 'orders_total DESC, latest_order_at DESC',
        'orders_asc'  => 'orders_total ASC, latest_order_at DESC',
        'due_desc'    => 'total_due DESC, latest_order_at DESC',
        'name_asc'    => 'customer_name ASC',
    ];
    return $map[$sortBy] ?? 'latest_order_at DESC';
}

/**
 * Check table existence.
 *
 * @param PDO $db
 * @param string $table
 * @return bool
 */
function tableExists($db, $table)
{
    static $cache = [];

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    if ($table === '') {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        $quoted = $db->quote($table);
        $stmt = $db->query("SHOW TABLES LIKE {$quoted}");
        $cache[$table] = $stmt ? ($stmt->rowCount() > 0) : false;
    }

    return $cache[$table];
}

function dashboardApiShouldCache($action, $input, $result)
{
    if (!is_array($result)) {
        return false;
    }

    if (!empty($result['error'])) {
        return false;
    }

    if ($action === 'customer_list' && trim((string) ($input['search'] ?? '')) !== '') {
        return false;
    }

    return true;
}

function dashboardApiBuildCacheKey($action, $input)
{
    if (is_array($input)) {
        unset($input['_t']);
        dashboardApiNormalizeCacheInput($input);
    }

    return $action . '_' . sha1(json_encode($input, JSON_UNESCAPED_UNICODE));
}

function dashboardApiNormalizeCacheInput(&$value)
{
    if (!is_array($value)) {
        return;
    }

    ksort($value);
    foreach ($value as &$item) {
        if (is_array($item)) {
            dashboardApiNormalizeCacheInput($item);
        }
    }
    unset($item);
}

function dashboardApiCacheDir()
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cny_odoo_dashboard_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function dashboardApiCachePath($key)
{
    return dashboardApiCacheDir() . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
}

function dashboardApiCacheRead($key)
{
    $path = dashboardApiCachePath($key);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['t'])) {
        @unlink($path);
        return null;
    }

    return $payload;
}

function dashboardApiCacheGet($key, $ttl)
{
    $payload = dashboardApiCacheRead($key);
    if ($payload === null) {
        return null;
    }

    if ((time() - (int) $payload['t']) > $ttl) {
        return null;
    }

    return $payload['d'] ?? null;
}

function dashboardApiCacheGetStale($key, $maxAge)
{
    $payload = dashboardApiCacheRead($key);
    if ($payload === null) {
        return null;
    }

    if ((time() - (int) $payload['t']) > $maxAge) {
        return null;
    }

    return $payload['d'] ?? null;
}

function dashboardApiCacheSet($key, $data)
{
    $path = dashboardApiCachePath($key);
    $payload = json_encode([
        't' => time(),
        'd' => $data,
    ], JSON_UNESCAPED_UNICODE);

    if ($payload !== false) {
        @file_put_contents($path, $payload, LOCK_EX);
    }
}

// =====================================================================
// Customer Detail Page Functions
// =====================================================================

/**
 * Get full customer 360° detail: profile, credit, LINE link, points.
 * Accepts partner_id or customer_ref.
 */
function getCustomerDetail($db, $input)
{
    $partnerId   = trim((string) ($input['partner_id'] ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));

    if ($partnerId === '' && $customerRef === '') {
        throw new Exception('Missing partner_id or customer_ref');
    }

    $detail = [
        'partner_id'   => $partnerId,
        'customer_ref' => $customerRef,
        'profile'      => null,
        'credit'       => null,
        'link'         => null,
        'points'       => null,
        'warnings'     => [],
    ];

    // Resolve LINE link
    $lineUserId = null;
    $link = null;
    if ($partnerId !== '' && $partnerId !== '-') {
        try {
            $stmt = $db->prepare("SELECT * FROM odoo_line_users WHERE odoo_partner_id = ? LIMIT 1");
            $stmt->execute([(int) $partnerId]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { /* ignore */ }
    }
    if (!$link && $customerRef !== '') {
        try {
            $stmt = $db->prepare("SELECT * FROM odoo_line_users WHERE odoo_customer_code = ? LIMIT 1");
            $stmt->execute([$customerRef]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { /* ignore */ }
    }

    if ($link) {
        $lineUserId = $link['line_user_id'] ?? null;
        $detail['link'] = $link;
    }

    // Profile from Odoo API
    if ($lineUserId) {
        try {
            require_once __DIR__ . '/../classes/OdooAPIClient.php';
            $lineAccountId = $link['line_account_id'] ?? null;
            $odoo = new OdooAPIClient($db, $lineAccountId);
            $profile = $odoo->getUserProfile($lineUserId);
            if (is_array($profile)) {
                $detail['profile'] = $profile;
            }
        } catch (Exception $e) {
            $detail['warnings'][] = 'profile: ' . $e->getMessage();
        }

        // Credit
        try {
            if (isset($odoo)) {
                $credit = $odoo->getCreditStatus($lineUserId);
                if (is_array($credit)) {
                    $detail['credit'] = $credit;
                }
            }
        } catch (Exception $e) {
            $detail['warnings'][] = 'credit: ' . $e->getMessage();
        }

        // Points
        try {
            // Find local user by line_user_id
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$lineUserId]);
            $userId = $stmt->fetchColumn();
            if ($userId) {
                require_once __DIR__ . '/../classes/LoyaltyPoints.php';
                $lp = new LoyaltyPoints($db, $lineAccountId);
                $detail['points'] = $lp->getUserPoints($userId);
            }
        } catch (Exception $e) {
            $detail['warnings'][] = 'points: ' . $e->getMessage();
        }
    }

    // Fallback: profile from webhook if API didn't work
    if (!$detail['profile'] && ($partnerId !== '' || $customerRef !== '')) {
        try {
            $pidExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), '')";
            $cidExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
            $refExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
            $nameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '')";

            $where = [];
            $params = [];
            if ($partnerId !== '' && $partnerId !== '-') {
                $where[] = "({$pidExpr} = ? OR {$cidExpr} = ?)";
                $params[] = $partnerId;
                $params[] = $partnerId;
            } elseif ($customerRef !== '') {
                $where[] = "{$refExpr} = ?";
                $params[] = $customerRef;
            }

            if (!empty($where)) {
                $stmt = $db->prepare("
                    SELECT
                        {$nameExpr} as name,
                        {$refExpr} as ref,
                        MAX({$pidExpr}) as partner_id,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.phone')), '')) as phone,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.mobile')), '')) as mobile,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.email')), '')) as email,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.street')), '')) as street,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.street2')), '')) as street2,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.city')), '')) as city,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.state')), '')) as state_name,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.zip')), '')) as zip,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.country')), '')) as country_name,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.delivery_address')), '')) as delivery_address,
                        MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.name')), '')) as salesperson_name
                    FROM odoo_webhooks_log
                    WHERE " . implode(' AND ', $where) . "
                    LIMIT 1
                ");
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && ($row['name'] || $row['ref'])) {
                    $detail['profile'] = $row;
                    $detail['warnings'][] = 'profile_source: webhook';
                }
            }
        } catch (Exception $e) {
            $detail['warnings'][] = 'profile_webhook: ' . $e->getMessage();
        }
    }

    // Fallback: compute credit/totals from webhook order+invoice data
    if (!$detail['credit'] && ($partnerId !== '' || $customerRef !== '')) {
        try {
            $pidExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), '')";
            $cidExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
            $refExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
            $amtExpr  = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))";
            $resExpr  = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_residual')), ''), '0') AS DECIMAL(14,2))";
            $orderNameExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.name')), ''))";

            $wh = [];
            $wp = [];
            if ($partnerId !== '' && $partnerId !== '-') {
                $wh[] = "({$pidExpr} = ? OR {$cidExpr} = ?)";
                $wp[] = $partnerId;
                $wp[] = $partnerId;
            } elseif ($customerRef !== '') {
                $wh[] = "{$refExpr} = ?";
                $wp[] = $customerRef;
            }

            if (!empty($wh)) {
                $whereStr = implode(' AND ', $wh);

                // Total spend from orders (sum unique order amounts)
                $stmtOrders = $db->prepare("
                    SELECT
                        COALESCE(SUM(t.amt), 0) as total_spend,
                        COUNT(*) as order_count
                    FROM (
                        SELECT {$orderNameExpr} as oname, MAX({$amtExpr}) as amt
                        FROM odoo_webhooks_log
                        WHERE {$whereStr}
                          AND ({$orderNameExpr}) IS NOT NULL
                        GROUP BY oname
                    ) t
                ");
                $stmtOrders->execute($wp);
                $ordRow = $stmtOrders->fetch(PDO::FETCH_ASSOC);

                // Outstanding due from unpaid invoices
                $invExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_number')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.name')), ''))";
                $stmtDue = $db->prepare("
                    SELECT COALESCE(SUM(t.residual), 0) as total_due
                    FROM (
                        SELECT {$invExpr} as inv_num, MAX({$resExpr}) as residual
                        FROM odoo_webhooks_log
                        WHERE {$whereStr}
                          AND event_type LIKE 'invoice.%'
                          AND event_type NOT IN ('invoice.paid', 'invoice.cancelled')
                          AND ({$invExpr}) IS NOT NULL
                        GROUP BY inv_num
                    ) t
                ");
                $stmtDue->execute($wp);
                $dueRow = $stmtDue->fetch(PDO::FETCH_ASSOC);

                // Check if any paid invoices exist (exclude those from due)
                $stmtPaid = $db->prepare("
                    SELECT {$invExpr} as inv_num
                    FROM odoo_webhooks_log
                    WHERE {$whereStr}
                      AND event_type = 'invoice.paid'
                      AND ({$invExpr}) IS NOT NULL
                    GROUP BY inv_num
                ");
                $stmtPaid->execute($wp);
                $paidInvs = $stmtPaid->fetchAll(PDO::FETCH_COLUMN);

                $detail['credit'] = [
                    'total_spend'      => (float) ($ordRow['total_spend'] ?? 0),
                    'credit_used'      => (float) ($ordRow['total_spend'] ?? 0),
                    'total_due'        => (float) ($dueRow['total_due'] ?? 0),
                    'credit_remaining' => null,
                    'credit_limit'     => null,
                    'order_count'      => (int) ($ordRow['order_count'] ?? 0),
                ];
                $detail['warnings'][] = 'credit_source: webhook_computed';
            }
        } catch (Exception $e) {
            $detail['warnings'][] = 'credit_webhook: ' . $e->getMessage();
        }
    }

    // Pull LINE profile picture from users table
    if ($lineUserId) {
        try {
            $stmt = $db->prepare("SELECT display_name, picture_url FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$lineUserId]);
            $lineProfile = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($lineProfile) {
                $detail['line_profile'] = [
                    'display_name' => $lineProfile['display_name'] ?? null,
                    'picture_url'  => $lineProfile['picture_url'] ?? null,
                ];
            }
        } catch (Exception $e) {
            $detail['warnings'][] = 'line_profile: ' . $e->getMessage();
        }
    }

    return $detail;
}

/**
 * Override order/invoice status manually. Requires reason + admin_name.
 * Logs to odoo_manual_overrides + ActivityLogger.
 */
function orderStatusOverride($db, $input)
{
    $entityType = trim((string) ($input['entity_type'] ?? ''));
    $entityRef  = trim((string) ($input['entity_ref'] ?? ''));
    $oldStatus  = trim((string) ($input['old_status'] ?? ''));
    $newStatus  = trim((string) ($input['new_status'] ?? ''));
    $reason     = trim((string) ($input['reason'] ?? ''));
    $adminName  = trim((string) ($input['admin_name'] ?? ''));
    $partnerId  = isset($input['partner_id']) ? (int) $input['partner_id'] : null;

    if (!in_array($entityType, ['order', 'invoice'], true)) {
        throw new Exception('entity_type must be order or invoice');
    }
    if ($entityRef === '') throw new Exception('Missing entity_ref');
    if ($newStatus === '') throw new Exception('Missing new_status');
    if ($reason === '') throw new Exception('Missing reason (เหตุผล)');
    if ($adminName === '') throw new Exception('Missing admin_name');

    // Ensure table exists
    $db->exec("CREATE TABLE IF NOT EXISTS odoo_manual_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type ENUM('order','invoice') NOT NULL,
        entity_ref VARCHAR(100) NOT NULL,
        partner_id INT NULL,
        old_status VARCHAR(50) NULL,
        new_status VARCHAR(50) NOT NULL,
        reason TEXT NOT NULL,
        admin_name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_entity (entity_type, entity_ref),
        INDEX idx_partner (partner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $db->prepare("INSERT INTO odoo_manual_overrides (entity_type, entity_ref, partner_id, old_status, new_status, reason, admin_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$entityType, $entityRef, $partnerId, $oldStatus, $newStatus, $reason, $adminName]);
    $overrideId = (int) $db->lastInsertId();

    // ActivityLogger
    try {
        require_once __DIR__ . '/../classes/ActivityLogger.php';
        $logger = ActivityLogger::getInstance($db);
        $logger->log(
            ActivityLogger::TYPE_ADMIN,
            ActivityLogger::ACTION_UPDATE,
            "Override {$entityType} status: {$entityRef} [{$oldStatus}] → [{$newStatus}] reason: {$reason}",
            [
                'admin_name'  => $adminName,
                'entity_type' => 'odoo_' . $entityType,
                'entity_id'   => $overrideId,
                'old_value'   => ['status' => $oldStatus, 'entity_ref' => $entityRef],
                'new_value'   => ['status' => $newStatus, 'reason' => $reason],
                'extra_data'  => ['partner_id' => $partnerId],
            ]
        );
    } catch (Exception $e) {
        error_log('ActivityLogger error in orderStatusOverride: ' . $e->getMessage());
    }

    return ['override_id' => $overrideId, 'entity_type' => $entityType, 'entity_ref' => $entityRef, 'new_status' => $newStatus];
}

/**
 * Add a note to an order or invoice.
 */
function orderNoteAdd($db, $input)
{
    $entityType = trim((string) ($input['entity_type'] ?? ''));
    $entityRef  = trim((string) ($input['entity_ref'] ?? ''));
    $note       = trim((string) ($input['note'] ?? ''));
    $adminName  = trim((string) ($input['admin_name'] ?? ''));
    $partnerId  = isset($input['partner_id']) ? (int) $input['partner_id'] : null;

    if (!in_array($entityType, ['order', 'invoice'], true)) {
        throw new Exception('entity_type must be order or invoice');
    }
    if ($entityRef === '') throw new Exception('Missing entity_ref');
    if ($note === '') throw new Exception('Missing note');
    if ($adminName === '') throw new Exception('Missing admin_name');

    // Ensure table exists
    $db->exec("CREATE TABLE IF NOT EXISTS odoo_order_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type ENUM('order','invoice') NOT NULL,
        entity_ref VARCHAR(100) NOT NULL,
        partner_id INT NULL,
        note TEXT NOT NULL,
        admin_name VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_entity (entity_type, entity_ref),
        INDEX idx_partner (partner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $db->prepare("INSERT INTO odoo_order_notes (entity_type, entity_ref, partner_id, note, admin_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$entityType, $entityRef, $partnerId, $note, $adminName]);
    $noteId = (int) $db->lastInsertId();

    // ActivityLogger
    try {
        require_once __DIR__ . '/../classes/ActivityLogger.php';
        $logger = ActivityLogger::getInstance($db);
        $logger->log(
            ActivityLogger::TYPE_ADMIN,
            ActivityLogger::ACTION_CREATE,
            "Add note to {$entityType}: {$entityRef} — {$note}",
            [
                'admin_name'  => $adminName,
                'entity_type' => 'odoo_' . $entityType . '_note',
                'entity_id'   => $noteId,
                'new_value'   => ['note' => $note, 'entity_ref' => $entityRef],
                'extra_data'  => ['partner_id' => $partnerId],
            ]
        );
    } catch (Exception $e) {
        error_log('ActivityLogger error in orderNoteAdd: ' . $e->getMessage());
    }

    return ['note_id' => $noteId, 'entity_type' => $entityType, 'entity_ref' => $entityRef];
}

/**
 * Get notes for a set of entity refs (orders or invoices).
 */
function orderNotesList($db, $input)
{
    $entityType = trim((string) ($input['entity_type'] ?? ''));
    $entityRef  = trim((string) ($input['entity_ref'] ?? ''));
    $partnerId  = trim((string) ($input['partner_id'] ?? ''));

    $notes = [];
    $overrides = [];

    // Notes
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS odoo_order_notes (
            id INT AUTO_INCREMENT PRIMARY KEY, entity_type ENUM('order','invoice') NOT NULL,
            entity_ref VARCHAR(100) NOT NULL, partner_id INT NULL, note TEXT NOT NULL,
            admin_name VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $where = ['1=1'];
        $params = [];
        if ($entityType !== '') { $where[] = 'entity_type = ?'; $params[] = $entityType; }
        if ($entityRef !== '') { $where[] = 'entity_ref = ?'; $params[] = $entityRef; }
        if ($partnerId !== '' && $partnerId !== '-') { $where[] = 'partner_id = ?'; $params[] = (int) $partnerId; }

        $stmt = $db->prepare("SELECT * FROM odoo_order_notes WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 200");
        $stmt->execute($params);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }

    // Overrides
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS odoo_manual_overrides (
            id INT AUTO_INCREMENT PRIMARY KEY, entity_type ENUM('order','invoice') NOT NULL,
            entity_ref VARCHAR(100) NOT NULL, partner_id INT NULL, old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NOT NULL, reason TEXT NOT NULL, admin_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_entity (entity_type, entity_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $where2 = ['1=1'];
        $params2 = [];
        if ($entityType !== '') { $where2[] = 'entity_type = ?'; $params2[] = $entityType; }
        if ($entityRef !== '') { $where2[] = 'entity_ref = ?'; $params2[] = $entityRef; }
        if ($partnerId !== '' && $partnerId !== '-') { $where2[] = 'partner_id = ?'; $params2[] = (int) $partnerId; }

        $stmt2 = $db->prepare("SELECT * FROM odoo_manual_overrides WHERE " . implode(' AND ', $where2) . " ORDER BY created_at DESC LIMIT 200");
        $stmt2->execute($params2);
        $overrides = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore */ }

    return ['notes' => $notes, 'overrides' => $overrides];
}

/**
 * Get activity log entries for a customer (by partner_id) or entity.
 */
function activityLogList($db, $input)
{
    $partnerId = trim((string) ($input['partner_id'] ?? ''));
    $entityRef = trim((string) ($input['entity_ref'] ?? ''));
    $limit     = min((int) ($input['limit'] ?? 50), 200);
    $offset    = max((int) ($input['offset'] ?? 0), 0);

    $items = [];

    // Merge from odoo_manual_overrides + odoo_order_notes + activity_logs
    // 1) Manual overrides
    try {
        $where = ['1=1'];
        $params = [];
        if ($partnerId !== '' && $partnerId !== '-') { $where[] = 'partner_id = ?'; $params[] = (int) $partnerId; }
        if ($entityRef !== '') { $where[] = 'entity_ref = ?'; $params[] = $entityRef; }

        $stmt = $db->prepare("SELECT id, 'override' as log_kind, entity_type, entity_ref, old_status, new_status, reason as description, admin_name, created_at FROM odoo_manual_overrides WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 100");
        $stmt->execute($params);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { /* table may not exist */ }

    // 2) Notes
    try {
        $where = ['1=1'];
        $params = [];
        if ($partnerId !== '' && $partnerId !== '-') { $where[] = 'partner_id = ?'; $params[] = (int) $partnerId; }
        if ($entityRef !== '') { $where[] = 'entity_ref = ?'; $params[] = $entityRef; }

        $stmt = $db->prepare("SELECT id, 'note' as log_kind, entity_type, entity_ref, NULL as old_status, NULL as new_status, note as description, admin_name, created_at FROM odoo_order_notes WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 100");
        $stmt->execute($params);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { /* table may not exist */ }

    // Sort merged items newest first
    usort($items, function ($a, $b) {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    $total = count($items);
    $items = array_slice($items, $offset, $limit);

    return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

/**
 * Get BDO records from odoo_bdos sync table (full columns).
 * Falls back to webhook log JSON extraction if table unavailable.
 */
function getOdooBdos($db, $input)
{
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));
    $search      = trim((string) ($input['search'] ?? ''));
    $limit       = min((int) ($input['limit']  ?? 100), 500);
    $offset      = max((int) ($input['offset'] ?? 0), 0);

    // Resolve line_user_id from partner_id if not provided
    if ($lineUserId === '' && $partnerId !== '' && $partnerId !== '-') {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([(int) $partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $lineUserId = $row['line_user_id'];
        } catch (Exception $e) { /* ignore */ }
    }

    // Try dedicated sync table first
    try {
        $where = [];
        $params = [];

        if ($partnerId !== '' && $partnerId !== '-') {
            $where[] = 'partner_id = ?';
            $params[] = (int) $partnerId;
        } elseif ($lineUserId !== '') {
            $where[] = 'line_user_id = ?';
            $params[] = $lineUserId;
        } elseif ($customerRef !== '') {
            $where[] = 'customer_ref = ?';
            $params[] = $customerRef;
        }
        if ($search !== '') {
            $where[] = '(bdo_name LIKE ? OR order_name LIKE ? OR customer_ref LIKE ? OR line_user_id LIKE ?)';
            $searchLike = '%' . $search . '%';
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $contextTableExists = false;
        try {
            $contextTableCheck = $db->query("SHOW TABLES LIKE 'odoo_bdo_context'");
            $contextTableExists = $contextTableCheck && $contextTableCheck->rowCount() > 0;
        } catch (Exception $e) {
            $contextTableExists = false;
        }

        $contextSelect = $contextTableExists
            ? ", ctx.delivery_type, ctx.statement_pdf_path"
            : ", NULL AS delivery_type, NULL AS statement_pdf_path";

        $contextJoin = $contextTableExists
            ? "
                LEFT JOIN (
                    SELECT c1.bdo_id, c1.delivery_type, c1.statement_pdf_path
                    FROM odoo_bdo_context c1
                    INNER JOIN (
                        SELECT bdo_id, MAX(id) AS max_id
                        FROM odoo_bdo_context
                        GROUP BY bdo_id
                    ) latest_ctx ON latest_ctx.max_id = c1.id
                ) ctx ON b.bdo_id = ctx.bdo_id"
            : "";

        $totalStmt = $db->prepare("SELECT COUNT(*) FROM odoo_bdos {$whereClause}");
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        if ($total > 0 || $whereClause !== '') {
            $sql = "
                SELECT
                    b.id, b.bdo_id, b.bdo_name,
                    b.order_id, b.order_name,
                    b.partner_id, b.customer_ref, b.line_user_id,
                    b.salesperson_id, b.salesperson_name,
                    b.state, b.amount_total, b.currency,
                    b.bdo_date, b.expected_delivery,
                    b.latest_event, b.synced_at, b.updated_at{$contextSelect}
                FROM odoo_bdos b
                {$contextJoin}
                {$whereClause}
                ORDER BY b.updated_at DESC
                LIMIT ? OFFSET ?
            ";
            $params[] = $limit;
            $params[] = $offset;
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $bdos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($bdos as &$b) {
                $b['id']           = (int) $b['id'];
                $b['bdo_id']       = (int) $b['bdo_id'];
                $b['partner_id']   = $b['partner_id']   !== null ? (int) $b['partner_id']   : null;
                $b['order_id']     = $b['order_id']     !== null ? (int) $b['order_id']     : null;
                $b['salesperson_id']= $b['salesperson_id'] !== null ? (int) $b['salesperson_id'] : null;
                $b['amount_total'] = $b['amount_total'] !== null ? (float) $b['amount_total'] : null;
            }
            unset($b);

            // Backfill NULL bdo_date from webhook log
            $nullBdos = array_filter($bdos, function($b) { return !$b['bdo_date'] && $b['bdo_name']; });
            if (!empty($nullBdos)) {
                try {
                    $names = array_map(function($b) { return $b['bdo_name']; }, $nullBdos);
                    $placeholders = implode(',', array_fill(0, count($names), '?'));
                    $bdoNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.bdo_name')),'')";
                    $dateExpr    = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.bdo_date')),'')";
                    $wbStmt = $db->prepare("
                        SELECT {$bdoNameExpr} AS bdo_name,
                               MAX({$dateExpr}) AS bdo_date,
                               MAX(processed_at) AS processed_at
                        FROM odoo_webhooks_log
                        WHERE event_type LIKE 'bdo.%'
                          AND {$bdoNameExpr} IN ({$placeholders})
                        GROUP BY {$bdoNameExpr}
                    ");
                    $wbStmt->execute($names);
                    $wbMap = [];
                    foreach ($wbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $wbMap[$row['bdo_name']] = $row;
                    }
                    foreach ($bdos as &$b) {
                        if (!$b['bdo_date'] && isset($wbMap[$b['bdo_name']])) {
                            $wb = $wbMap[$b['bdo_name']];
                            $b['bdo_date'] = $wb['bdo_date'] ?: $wb['processed_at'] ?: null;
                        }
                    }
                    unset($b);
                } catch (Exception $e) { /* ignore */ }
            }

            return ['bdos' => $bdos, 'total' => $total, 'source' => 'sync_table', 'limit' => $limit, 'offset' => $offset];
        }
    } catch (Exception $e) {
        // fall through to webhook log
    }

    // Fallback: query from webhook log with JSON extraction
    $pidExpr      = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
    $refExpr      = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
    $bdoIdExpr    = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_id')), '')";
    $bdoNameExpr  = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_name')), '')";
    $amountExpr   = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), '')";
    $dateExpr     = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_date')), '')";
    $stateExpr    = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')), '')";
    $orderNameExpr= "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.sale_orders[0].name'))";

    $fbWhere  = ["event_type LIKE 'bdo.%'"];
    $fbParams = [];
    if ($partnerId !== '' && $partnerId !== '-') {
        $fbWhere[] = "{$pidExpr} = ?";
        $fbParams[] = $partnerId;
    } elseif ($customerRef !== '') {
        $fbWhere[] = "{$refExpr} = ?";
        $fbParams[] = $customerRef;
    }
    if ($search !== '') {
        $fbWhere[] = "({$bdoNameExpr} LIKE ? OR {$orderNameExpr} LIKE ?)";
        $searchLike = '%' . $search . '%';
        $fbParams[] = $searchLike;
        $fbParams[] = $searchLike;
    }
    $fbWhereClause = 'WHERE ' . implode(' AND ', $fbWhere);

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM odoo_webhooks_log {$fbWhereClause}");
        $stmt->execute($fbParams);
        $total = (int) $stmt->fetchColumn();

        $fbParams2 = $fbParams;
        $stmt = $db->prepare("
            SELECT id, event_type,
                {$bdoIdExpr} as bdo_id,
                {$bdoNameExpr} as bdo_name,
                {$orderNameExpr} as order_name,
                {$amountExpr} as amount_total,
                {$dateExpr} as bdo_date,
                {$stateExpr} as state,
                processed_at
            FROM odoo_webhooks_log {$fbWhereClause}
            ORDER BY processed_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($fbParams2);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bdos = [];
        foreach ($rows as $row) {
            $bdos[] = [
                'id'           => (int) $row['id'],
                'bdo_id'       => $row['bdo_id'] ? (int) $row['bdo_id'] : null,
                'bdo_name'     => $row['bdo_name'] ?: null,
                'order_name'   => $row['order_name'] ?: null,
                'amount_total' => $row['amount_total'] ? (float) $row['amount_total'] : null,
                'bdo_date'     => $row['bdo_date'] ?: $row['processed_at'],
                'state'        => $row['state'] ?: 'confirmed',
                'event_type'   => $row['event_type'],
            ];
        }
        return ['bdos' => $bdos, 'total' => $total, 'source' => 'webhook_log', 'limit' => $limit, 'offset' => $offset];
    } catch (Exception $e) {
        return ['bdos' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Get distinct salespersons seen in odoo_webhooks_log payloads.
 * Used to populate the salesperson filter dropdown.
 */
function getSalespersonList($db)
{
    $processedAtColumn = resolveWebhookTimeColumn($db);
    $spIdExpr   = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.id')), '')";
    $spNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.name')), '')";
    $customerKeyExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), ''))";

    // Limit scan scope to keep dropdown API responsive on large logs.
    $windowWhere = $processedAtColumn
        ? "{$processedAtColumn} >= DATE_SUB(NOW(), INTERVAL 180 DAY)"
        : "id >= GREATEST((SELECT MAX(id) - 50000 FROM odoo_webhooks_log), 0)";

    try {
        $stmt = $db->query("
            SELECT
                t.sp_id AS id,
                MAX(t.sp_name) AS name,
                COUNT(DISTINCT t.customer_key) AS customer_count
            FROM (
                SELECT
                    {$spIdExpr} AS sp_id,
                    {$spNameExpr} AS sp_name,
                    {$customerKeyExpr} AS customer_key
                FROM odoo_webhooks_log
                WHERE {$windowWhere}
            ) t
            WHERE t.sp_id IS NOT NULL
              AND t.sp_name IS NOT NULL
            GROUP BY t.sp_id
            ORDER BY name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ['salespersons' => $rows];
    } catch (Exception $e) {
        return ['salespersons' => [], 'error' => $e->getMessage()];
    }
}

// ======================================================================== //
// BDO Matching Functions (Phase 2 — staging-ready Odoo endpoints)          //
// ======================================================================== //

/**
 * Get BDO list from Odoo live API (with sync-table fallback).
 *
 * Required: line_user_id
 * Optional: line_account_id, state, limit, offset
 */
function getBdoListLive($db, $input)
{
    $lineUserId    = trim((string) ($input['line_user_id'] ?? ''));
    $partnerId     = (int) ($input['partner_id'] ?? 0);
    $lineAccountId = (int) ($input['line_account_id'] ?? 0);
    $state         = trim((string) ($input['state'] ?? ''));
    $search        = trim((string) ($input['search'] ?? ''));
    $limit         = min((int) ($input['limit'] ?? 20), 50);
    $offset        = max((int) ($input['offset'] ?? 0), 0);

    if ($lineUserId === '' && $partnerId <= 0) {
        return array_merge(
            getOdooBdos($db, array_merge($input, ['search' => $search, 'limit' => $limit, 'offset' => $offset])),
            ['source' => 'sync_table_search']
        );
    }

    if ($lineUserId === '' && $partnerId > 0) {
        $lineUserId = resolveLineUserIdFromPartner($db, $partnerId);
    }

    if ($lineAccountId <= 0) {
        $lineAccountId = resolveLineAccountId($db, $lineUserId);
    }

    // Try Odoo live API first
    $odooError = null;
    if ($lineAccountId > 0) {
        try {
            require_once __DIR__ . '/../classes/OdooAPIClient.php';
            $odoo = new OdooAPIClient($db, $lineAccountId);

            $options = ['limit' => $limit, 'offset' => $offset];
            if ($state !== '') {
                $options['state'] = $state;
            }
            if ($partnerId > 0) {
                $options['partner_id'] = $partnerId;
            }

            $result = $odoo->getBdoList($lineUserId !== '' ? $lineUserId : null, $options);

            return [
                'bdos'   => $result['data']['bdos'] ?? $result['bdos'] ?? [],
                'total'  => $result['data']['total'] ?? count($result['data']['bdos'] ?? $result['bdos'] ?? []),
                'source' => 'odoo_live',
                'limit'  => $limit,
                'offset' => $offset,
            ];
        } catch (Exception $e) {
            $odooError = $e->getMessage();
            error_log('[bdo_list_live] Odoo API failed: ' . $odooError);
        }
    }

    // Fallback: sync table
    return array_merge(
        getOdooBdos($db, array_merge($input, ['line_user_id' => $lineUserId, 'partner_id' => $partnerId, 'limit' => $limit, 'offset' => $offset])),
        ['odoo_error' => $odooError, 'source' => 'sync_table_fallback']
    );
}

/**
 * Get BDO detail from Odoo live API.
 *
 * Required: line_user_id, bdo_id
 * Optional: line_account_id
 */
function getBdoDetailLive($db, $input)
{
    $lineUserId    = trim((string) ($input['line_user_id'] ?? ''));
    $bdoId         = (int) ($input['bdo_id'] ?? 0);
    $partnerId     = (int) ($input['partner_id'] ?? 0);
    $lineAccountId = (int) ($input['line_account_id'] ?? 0);

    if ($bdoId <= 0) {
        throw new Exception('Missing bdo_id');
    }

    if ($lineUserId === '' && $partnerId > 0) {
        $lineUserId = resolveLineUserIdFromPartner($db, $partnerId);
    }

    // ── Try Odoo live API first ─────────────────────────────────────────
    $odooResult = null;
    if ($lineUserId !== '') {
        if ($lineAccountId <= 0) {
            $lineAccountId = resolveLineAccountId($db, $lineUserId);
        }
        if ($lineAccountId > 0) {
            try {
                require_once __DIR__ . '/../classes/OdooAPIClient.php';
                $odoo       = new OdooAPIClient($db, $lineAccountId);
                $odooResult = $odoo->getBdoDetail($lineUserId, $bdoId);
                $odooResult = $odooResult['data'] ?? $odooResult;
                if (!empty($odooResult)) {
                    return $odooResult;
                }
            } catch (Exception $e) {
                error_log('[getBdoDetailLive] Odoo API failed, falling back to local DB: ' . $e->getMessage());
            }
        }
    }

    // ── Fallback: build detail from local DB (odoo_bdos + odoo_bdo_context) ──
    return getBdoDetailFromLocalDb($db, $bdoId, $partnerId);
}

/**
 * Build BDO detail from local database tables when Odoo live API is unavailable.
 * Uses odoo_bdos (synced table) + odoo_bdo_context (webhook-saved context).
 */
function getBdoDetailFromLocalDb($db, $bdoId, $partnerId = 0)
{
    // 1. Get basic BDO info from sync table
    $stmt = $db->prepare("
        SELECT b.*, c.qr_payload, c.statement_pdf_path, c.financial_summary_json,
               c.selected_invoices_json, c.selected_credit_notes_json, c.amount AS ctx_amount,
               c.delivery_type AS ctx_delivery_type, c.line_user_id AS ctx_line_user_id
        FROM odoo_bdos b
        LEFT JOIN odoo_bdo_context c ON c.bdo_id = b.bdo_id
        WHERE b.bdo_id = ?
        ORDER BY c.updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$bdoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Try just from context table
        $stmt = $db->prepare("SELECT * FROM odoo_bdo_context WHERE bdo_id = ? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$bdoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('BDO not found in local database');
        }
    }

    // 2. Build BDO object
    $customerRef = $row['customer_ref'] ?? '';
    $partnerName = '';
    $pId = (int) ($row['partner_id'] ?? $partnerId);
    // Try to get customer name from odoo_bdos or odoo_line_users
    if ($pId > 0) {
        try {
            $nameStmt = $db->prepare("SELECT customer_name FROM odoo_orders WHERE partner_id = ? AND customer_name IS NOT NULL AND customer_name != '' LIMIT 1");
            $nameStmt->execute([$pId]);
            $nameRow = $nameStmt->fetch(PDO::FETCH_ASSOC);
            if ($nameRow) $partnerName = $nameRow['customer_name'];
        } catch (Exception $e) { /* ignore */ }
    }
    if (empty($partnerName) && !empty($customerRef)) {
        $partnerName = $customerRef;
    }

    $bdo = [
        'name'             => $row['bdo_name'] ?? ('BDO-' . $bdoId),
        'bdo_name'         => $row['bdo_name'] ?? ('BDO-' . $bdoId),
        'bdo_id'           => (int) $bdoId,
        'state'            => $row['state'] ?? 'done',
        'doc_date'         => $row['bdo_date'] ?? $row['updated_at'] ?? null,
        'delivery_type'    => $row['delivery_type'] ?? $row['ctx_delivery_type'] ?? null,
        'amount_total'     => (float) ($row['amount_total'] ?? $row['ctx_amount'] ?? 0),
        'amount_net_to_pay' => null,
        'partner_id'       => $pId,
        'partner_name'     => $partnerName,
        'customer_ref'     => $customerRef,
    ];

    // 3. Parse financial summary from context
    $summary = null;
    $saleOrders = [];
    $outstandingInvoices = [];
    $creditNotes = [];
    $deposits = [];

    if (!empty($row['financial_summary_json'])) {
        $fs = json_decode($row['financial_summary_json'], true);
        if (is_array($fs)) {
            $summary = [
                'so_amount'          => $fs['so_amount'] ?? $fs['total_so_amount'] ?? null,
                'outstanding_amount' => $fs['outstanding_amount'] ?? $fs['total_outstanding'] ?? null,
                'credit_note_amount' => $fs['credit_note_amount'] ?? $fs['total_credit_notes'] ?? null,
                'deposit_amount'     => $fs['deposit_amount'] ?? $fs['total_deposits'] ?? null,
                'net_to_pay'         => $fs['net_to_pay'] ?? $fs['amount_net_to_pay'] ?? $bdo['amount_total'],
            ];
            $bdo['amount_net_to_pay'] = $summary['net_to_pay'];

            // Extract deposits from financial_summary if available
            if (!empty($fs['selected_deposits']) && is_array($fs['selected_deposits'])) {
                $deposits = array_map(function ($dp) {
                    return [
                        'name'   => $dp['name'] ?? $dp['number'] ?? '-',
                        'amount' => (float) ($dp['amount'] ?? $dp['amount_total'] ?? 0),
                    ];
                }, $fs['selected_deposits']);
            } elseif (!empty($fs['deposits']) && is_array($fs['deposits'])) {
                $deposits = array_map(function ($dp) {
                    return [
                        'name'   => $dp['name'] ?? $dp['number'] ?? '-',
                        'amount' => (float) ($dp['amount'] ?? $dp['amount_total'] ?? 0),
                    ];
                }, $fs['deposits']);
            }
        }
    }

    if (!empty($row['selected_invoices_json'])) {
        $invs = json_decode($row['selected_invoices_json'], true);
        if (is_array($invs)) {
            $outstandingInvoices = array_map(function ($inv) {
                return [
                    'number'       => $inv['number'] ?? $inv['name'] ?? '-',
                    'name'         => $inv['name'] ?? $inv['number'] ?? '-',
                    'residual'     => $inv['residual'] ?? $inv['amount_residual'] ?? $inv['amount_total'] ?? 0,
                    'amount_total' => $inv['amount_total'] ?? 0,
                    'date'         => $inv['date'] ?? $inv['invoice_date'] ?? null,
                    'origin'       => $inv['origin'] ?? '',
                    'selected'     => $inv['selected'] ?? true,
                ];
            }, $invs);
        }
    }

    if (!empty($row['selected_credit_notes_json'])) {
        $cns = json_decode($row['selected_credit_notes_json'], true);
        if (is_array($cns)) {
            $creditNotes = array_map(function ($cn) {
                return [
                    'number'       => $cn['number'] ?? $cn['name'] ?? '-',
                    'name'         => $cn['name'] ?? $cn['number'] ?? '-',
                    'residual'     => $cn['residual'] ?? $cn['amount_total'] ?? 0,
                    'amount_total' => $cn['amount_total'] ?? 0,
                    'selected'     => $cn['selected'] ?? true,
                ];
            }, $cns);
        }
    }

    // 4. QR payment data
    if (!empty($row['qr_payload'])) {
        $bdo['qr_payment_data'] = [
            'raw_payload' => $row['qr_payload'],
        ];
    }

    // 5. Statement PDF
    $statementPdfUrl = null;
    if (!empty($row['statement_pdf_path'])) {
        $statementPdfUrl = 'api/odoo-dashboard-api.php?action=odoo_bdo_statement_pdf&bdo_id=' . urlencode($bdoId);
    }

    // 6. Matched slips from local DB
    $slips = [];
    try {
        $slipStmt = $db->prepare("
            SELECT id, slip_inbox_id, amount, transfer_date, image_path, created_at
            FROM odoo_slip_uploads
            WHERE bdo_id = ? AND status IN ('matched','confirmed','sent')
            ORDER BY created_at DESC
        ");
        $slipStmt->execute([$bdoId]);
        $slips = $slipStmt->fetchAll(PDO::FETCH_ASSOC);
        $slips = array_map(function ($s) {
            return [
                'slip_inbox_name' => 'Slip #' . ($s['slip_inbox_id'] ?? $s['id']),
                'name'            => 'Slip #' . ($s['slip_inbox_id'] ?? $s['id']),
                'amount'          => (float) ($s['amount'] ?? 0),
                'transfer_date'   => $s['transfer_date'] ?? $s['created_at'],
                'created_at'      => $s['created_at'],
            ];
        }, $slips);
    } catch (Exception $e) {
        error_log('[getBdoDetailFromLocalDb] slips query: ' . $e->getMessage());
    }

    // 7. Get related sale orders from odoo_orders
    try {
        $soStmt = $db->prepare("
            SELECT order_id, order_name, amount_total, state, order_date
            FROM odoo_orders
            WHERE partner_id = ? AND order_name IN (
                SELECT order_name FROM odoo_bdos WHERE bdo_id = ?
            )
            ORDER BY order_date DESC LIMIT 10
        ");
        $soStmt->execute([$bdo['partner_id'] ?: $partnerId, $bdoId]);
        $soRows = $soStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($soRows as $so) {
            $saleOrders[] = [
                'name'         => $so['order_name'] ?? '-',
                'amount_total' => (float) ($so['amount_total'] ?? 0),
                'lines'        => [], // not available locally
            ];
        }
    } catch (Exception $e) {
        // Silently ignore — sale_orders is optional
    }

    // 8. Build Odoo URL
    $odooUrl = 'https://cny.cnyrxapp.com/web#id=' . $bdoId . '&model=cny.bill.invoice.before.delivery&view_type=form';

    return [
        'bdo'                  => $bdo,
        'summary'              => $summary,
        'sale_orders'          => $saleOrders,
        'outstanding_invoices' => $outstandingInvoices,
        'credit_notes'         => $creditNotes,
        'deposits'             => $deposits,
        'slips'                => $slips,
        'statement_pdf_url'    => $statementPdfUrl,
        'odoo_url'             => $odooUrl,
        'source'               => 'local_db',
    ];
}

/**
 * Match a slip to BDO(s) via Odoo + update local DB.
 *
 * Required: line_user_id, slip_inbox_id, matches [{bdo_id, amount}]
 * Optional: line_account_id, note, local_slip_id
 */
function slipMatchBdo($db, $input)
{
    $lineUserId    = trim((string) ($input['line_user_id'] ?? ''));
    $slipInboxId   = (int) ($input['slip_inbox_id'] ?? 0);
    $matches       = is_array($input['matches'] ?? null) ? $input['matches'] : [];
    $note          = trim((string) ($input['note'] ?? ''));
    $lineAccountId = (int) ($input['line_account_id'] ?? 0);
    $localSlipId   = (int) ($input['local_slip_id'] ?? ($input['slip_id'] ?? 0));
    $legacyBdoId   = (int) ($input['bdo_id'] ?? 0);
    $legacyAmount  = isset($input['amount']) ? (float) $input['amount'] : null;

    if (empty($matches) && $legacyBdoId > 0) {
        $matches = [[
            'bdo_id' => $legacyBdoId,
            'amount' => $legacyAmount,
        ]];
    }

    $localSlip = null;
    if ($localSlipId > 0) {
        $stmt = $db->prepare("
            SELECT id, line_user_id, line_account_id, slip_inbox_id, odoo_slip_id, bdo_id, amount,
                   transfer_date, image_path, invoice_id, order_id
            FROM odoo_slip_uploads
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$localSlipId]);
        $localSlip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($lineUserId === '' && $localSlip) {
        $lineUserId = trim((string) ($localSlip['line_user_id'] ?? ''));
    }
    if ($slipInboxId <= 0 && $localSlip) {
        $slipInboxId = (int) ($localSlip['slip_inbox_id'] ?? $localSlip['odoo_slip_id'] ?? 0);
    }
    if ($lineAccountId <= 0 && $localSlip) {
        $lineAccountId = (int) ($localSlip['line_account_id'] ?? 0);
    }

    if ($lineUserId === '') {
        throw new Exception('Missing line_user_id');
    }
    if (empty($matches)) {
        throw new Exception('Missing matches');
    }

    $normalizedMatches = [];
    foreach ($matches as $match) {
        $matchBdoId = (int) ($match['bdo_id'] ?? 0);
        if ($matchBdoId <= 0) {
            continue;
        }
        $matchAmount = isset($match['amount']) ? (float) $match['amount'] : null;
        if ($matchAmount === null || $matchAmount <= 0) {
            $matchAmount = resolveBdoAmount($db, $matchBdoId);
        }
        $normalizedMatches[] = [
            'bdo_id' => $matchBdoId,
            'amount' => $matchAmount,
        ];
    }

    if (empty($normalizedMatches)) {
        throw new Exception('No valid BDO matches');
    }

    if ($lineAccountId <= 0) {
        $lineAccountId = resolveLineAccountId($db, $lineUserId);
    }
    if ($lineAccountId <= 0) {
        throw new Exception('Cannot resolve line_account_id for this user');
    }

    require_once __DIR__ . '/../classes/OdooAPIClient.php';
    $odoo = new OdooAPIClient($db, $lineAccountId);

    if ($slipInboxId <= 0 && $localSlip) {
        $slipInboxId = ensureOdooSlipInboxId($db, $odoo, $localSlip, $lineUserId);
    }
    if ($slipInboxId <= 0) {
        throw new Exception('Missing slip_inbox_id');
    }

    $odooResult = $odoo->matchSlipBdo($lineUserId, $slipInboxId, $normalizedMatches, $note);

    // Update local slip record if local_slip_id provided
    if ($localSlipId > 0) {
        $matchedBdos = $odooResult['data']['matched_bdos'] ?? $odooResult['matched_bdos'] ?? [];
        $confidence  = $odooResult['data']['match_confidence'] ?? $odooResult['match_confidence'] ?? 'manual';
        $firstBdoName = !empty($matchedBdos) ? ($matchedBdos[0]['bdo_name'] ?? null) : null;
        $firstBdoId = !empty($matchedBdos) ? (int) ($matchedBdos[0]['bdo_id'] ?? ($normalizedMatches[0]['bdo_id'] ?? 0)) : (int) ($normalizedMatches[0]['bdo_id'] ?? 0);
        $slipInboxName = $odooResult['data']['slip_inbox_name'] ?? $odooResult['slip_inbox_name'] ?? null;
        $deliveryType = !empty($matchedBdos) ? ($matchedBdos[0]['delivery_type'] ?? null) : null;
        $totalMatched = $odooResult['data']['total_matched'] ?? $odooResult['total_matched'] ?? null;

        $db->prepare("
            UPDATE odoo_slip_uploads
            SET status = ?,
                match_reason = ?,
                matched_at = NOW(),
                slip_inbox_id = ?,
                slip_inbox_name = COALESCE(?, slip_inbox_name),
                match_confidence = ?,
                bdo_id = COALESCE(?, bdo_id),
                bdo_name = COALESCE(?, bdo_name),
                delivery_type = COALESCE(?, delivery_type),
                bdo_amount = COALESCE(?, bdo_amount)
            WHERE id = ?
        ")->execute([
            'BDO match via dashboard: ' . json_encode(array_column($normalizedMatches, 'bdo_id')),
            $slipInboxId,
            $slipInboxName,
            $confidence,
            $firstBdoId > 0 ? $firstBdoId : null,
            $firstBdoName,
            $deliveryType,
            $totalMatched !== null ? (float) $totalMatched : null,
            $localSlipId
        ]);
    }

    return [
        'odoo_result' => $odooResult,
        'local_updated' => $localSlipId > 0,
        'line_user_id' => $lineUserId,
        'slip_inbox_id' => $slipInboxId,
    ];
}

/**
 * Unmatch a slip via Odoo + reset local DB.
 *
 * Required: line_user_id, slip_inbox_id
 * Optional: line_account_id, reason, local_slip_id
 */
function slipUnmatch($db, $input)
{
    $lineUserId    = trim((string) ($input['line_user_id'] ?? ''));
    $slipInboxId   = (int) ($input['slip_inbox_id'] ?? 0);
    $reason        = trim((string) ($input['reason'] ?? 'Unmatched via dashboard'));
    $lineAccountId = (int) ($input['line_account_id'] ?? 0);
    $localSlipId   = (int) ($input['local_slip_id'] ?? ($input['slip_id'] ?? 0));

    $localSlip = null;
    if ($localSlipId > 0) {
        $stmt = $db->prepare("
            SELECT id, line_user_id, line_account_id, slip_inbox_id, odoo_slip_id
            FROM odoo_slip_uploads
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$localSlipId]);
        $localSlip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($lineUserId === '' && $localSlip) {
        $lineUserId = trim((string) ($localSlip['line_user_id'] ?? ''));
    }
    if ($slipInboxId <= 0 && $localSlip) {
        $slipInboxId = (int) ($localSlip['slip_inbox_id'] ?? $localSlip['odoo_slip_id'] ?? 0);
    }
    if ($lineAccountId <= 0 && $localSlip) {
        $lineAccountId = (int) ($localSlip['line_account_id'] ?? 0);
    }

    if ($lineUserId === '' || $slipInboxId <= 0) {
        throw new Exception('Missing line_user_id or slip_inbox_id');
    }

    if ($lineAccountId <= 0) {
        $lineAccountId = resolveLineAccountId($db, $lineUserId);
    }
    if ($lineAccountId <= 0) {
        throw new Exception('Cannot resolve line_account_id for this user');
    }

    require_once __DIR__ . '/../classes/OdooAPIClient.php';
    $odoo       = new OdooAPIClient($db, $lineAccountId);
    $odooResult = $odoo->unmatchSlip($lineUserId, $slipInboxId, $reason);

    // Reset local slip record
    if ($localSlipId > 0) {
        $db->prepare("
            UPDATE odoo_slip_uploads
            SET status = ?,
                match_reason = ?,
                matched_at = NULL,
                match_confidence = NULL,
                bdo_name = NULL,
                slip_inbox_id = NULL,
                slip_inbox_name = NULL,
                bdo_id = NULL,
                delivery_type = NULL,
                bdo_amount = NULL
            WHERE id = ?
        ")->execute(['Unmatched: ' . $reason, $localSlipId]);
    }

    return [
        'odoo_result' => $odooResult,
        'local_reset' => $localSlipId > 0,
    ];
}

/**
 * Stream Statement PDF from Odoo (binary proxy).
 *
 * Required: bdo_id
 * Optional: line_account_id
 */
function streamStatementPdf($db, $input)
{
    $bdoId         = (int) ($input['bdo_id'] ?? 0);
    $lineAccountId = (int) ($input['line_account_id'] ?? 0);

    if ($bdoId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing bdo_id']);
        return;
    }

    require_once __DIR__ . '/../classes/OdooAPIClient.php';
    $odoo = new OdooAPIClient($db, $lineAccountId ?: null);
    $pdf  = $odoo->getStatementPdf($bdoId);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="BDO_Statement_' . $bdoId . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
}

/**
 * Get pending BDO orders for a partner (for slip attachment UI).
 * Supports lookup by partner_id, customer_ref, or line_user_id.
 */
function getPendingBdoOrdersApi($db, $input)
{
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));
    $limit       = min((int) ($input['limit']  ?? 50), 200);

    // Resolve partner_id from customer_ref or line_user_id if not provided
    if ($partnerId === '' || $partnerId === '-') {
        if ($customerRef !== '') {
            try {
                $stmt = $db->prepare("SELECT partner_id FROM odoo_bdos WHERE customer_ref = ? AND partner_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1");
                $stmt->execute([$customerRef]);
                $row = $stmt->fetchColumn();
                if ($row) $partnerId = (string) $row;
            } catch (Exception $e) { /* ignore */ }
        }
        if (($partnerId === '' || $partnerId === '-') && $lineUserId !== '') {
            try {
                $stmt = $db->prepare("SELECT odoo_partner_id FROM odoo_line_users WHERE line_user_id = ? LIMIT 1");
                $stmt->execute([$lineUserId]);
                $row = $stmt->fetchColumn();
                if ($row) $partnerId = (string) $row;
            } catch (Exception $e) { /* ignore */ }
        }
    }

    if ($partnerId === '' || $partnerId === '-') {
        return ['bdo_orders' => [], 'total' => 0, 'error' => 'No partner_id resolved'];
    }

    try {
        // Check if table exists
        $tableCheck = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
        if ($tableCheck->rowCount() === 0) {
            return ['bdo_orders' => [], 'total' => 0, 'error' => 'odoo_bdo_orders table not found'];
        }

        $stmt = $db->prepare("
            SELECT bo.*, b.state as bdo_state, b.bdo_date, b.qr_data
            FROM odoo_bdo_orders bo
            LEFT JOIN odoo_bdos b ON bo.bdo_id = b.bdo_id
            WHERE bo.partner_id = ?
              AND bo.payment_status = 'pending'
            ORDER BY bo.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([(int) $partnerId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast numeric fields
        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['bdo_id']       = (int) $r['bdo_id'];
            $r['order_id']     = (int) $r['order_id'];
            $r['partner_id']   = $r['partner_id'] !== null ? (int) $r['partner_id'] : null;
            $r['amount_total'] = $r['amount_total'] !== null ? (float) $r['amount_total'] : null;
            $r['slip_upload_id'] = $r['slip_upload_id'] !== null ? (int) $r['slip_upload_id'] : null;
        }
        unset($r);

        return ['bdo_orders' => $rows, 'total' => count($rows)];
    } catch (Exception $e) {
        return ['bdo_orders' => [], 'total' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Fast BDO overview for Slip Center dashboard (local DB only, no Odoo live API).
 * Returns all pending/partial BDOs with customer_ref and line_user_id for grouping.
 */
function getSlipCenterBdoOverview($db, $input)
{
    $limit = min((int) ($input['limit'] ?? 200), 500);

    try {
        $tableCheck = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
        if ($tableCheck->rowCount() === 0) {
            return ['bdos' => [], 'total' => 0, 'source' => 'local'];
        }

        $contextTableExists = false;
        try {
            $cx = $db->query("SHOW TABLES LIKE 'odoo_bdo_context'");
            $contextTableExists = $cx && $cx->rowCount() > 0;
        } catch (Exception $e) {}

        $contextSelect = $contextTableExists
            ? ", ctx.delivery_type"
            : ", NULL AS delivery_type";
        $contextJoin = $contextTableExists
            ? "LEFT JOIN (
                SELECT c1.bdo_id, c1.delivery_type
                FROM odoo_bdo_context c1
                INNER JOIN (SELECT bdo_id, MAX(id) AS max_id FROM odoo_bdo_context GROUP BY bdo_id) lc ON lc.max_id = c1.id
               ) ctx ON bo.bdo_id = ctx.bdo_id"
            : "";

        $stmt = $db->prepare("
            SELECT
                bo.id, bo.bdo_id, bo.bdo_name, bo.order_id, bo.order_name,
                bo.partner_id, bo.customer_name, bo.line_user_id,
                bo.amount_total, bo.payment_status, bo.payment_method,
                bo.created_at, bo.updated_at,
                b.state AS bdo_state, b.bdo_date, b.customer_ref,
                b.salesperson_name{$contextSelect}
            FROM odoo_bdo_orders bo
            LEFT JOIN odoo_bdos b ON bo.bdo_id = b.bdo_id
            {$contextJoin}
            WHERE bo.payment_status IN ('pending', 'partial')
            ORDER BY bo.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['bdo_id']       = (int) $r['bdo_id'];
            $r['order_id']     = (int) $r['order_id'];
            $r['partner_id']   = $r['partner_id'] !== null ? (int) $r['partner_id'] : null;
            $r['amount_total'] = $r['amount_total'] !== null ? (float) $r['amount_total'] : null;
        }
        unset($r);

        // Fallback to webhook log if table is empty
        if (empty($rows)) {
            return getSlipCenterBdoGlobal($db, $input);
        }

        return ['bdos' => $rows, 'total' => count($rows), 'source' => 'local'];
    } catch (Exception $e) {
        // Fallback to webhook log on error
        return getSlipCenterBdoGlobal($db, $input);
    }
}

/**
 * Global BDO overview for Slip Center — extracts from webhook log when sync tables empty.
 * Returns all pending/partial BDOs across all customers.
 */
function getSlipCenterBdoGlobal($db, $input)
{
    $limit = min((int) ($input['limit'] ?? 200), 500);

    try {
        // Extract BDOs from webhook log
        $bdoIdExpr = "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_id')), '') AS UNSIGNED)";
        $bdoNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_name')), '')";
        $orderIdExpr = "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')), '') AS UNSIGNED)";
        $orderNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), '')";
        $partnerIdExpr = "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '') AS UNSIGNED)";
        $customerRefExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
        $lineUserIdExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')), '')";
        $amountExpr = "CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), '') AS DECIMAL(10,2))";
        $stateExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state')), '')";
        $paymentStatusExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.payment_status')), '')";
        $bdoDateExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.bdo_date')), '')";

        $stmt = $db->prepare("
            SELECT
                {$bdoIdExpr} AS bdo_id,
                {$bdoNameExpr} AS bdo_name,
                {$orderIdExpr} AS order_id,
                {$orderNameExpr} AS order_name,
                {$partnerIdExpr} AS partner_id,
                {$customerRefExpr} AS customer_ref,
                {$lineUserIdExpr} AS line_user_id,
                {$amountExpr} AS amount_total,
                {$stateExpr} AS state,
                {$paymentStatusExpr} AS payment_status,
                {$bdoDateExpr} AS bdo_date,
                MAX(processed_at) AS updated_at
            FROM odoo_webhooks_log
            WHERE event_type LIKE 'bdo.%'
              AND {$bdoIdExpr} > 0
              AND {$paymentStatusExpr} IN ('pending', 'partial')
            GROUP BY {$bdoIdExpr}
            ORDER BY MAX(processed_at) DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['bdo_id']       = (int) $r['bdo_id'];
            $r['order_id']     = $r['order_id']     ? (int) $r['order_id']     : null;
            $r['partner_id']   = $r['partner_id']   ? (int) $r['partner_id']   : null;
            $r['amount_total'] = $r['amount_total'] ? (float) $r['amount_total'] : null;
        }
        unset($r);

        return ['bdos' => $rows, 'total' => count($rows), 'source' => 'webhook_log'];
    } catch (Exception $e) {
        return ['bdos' => [], 'total' => 0, 'source' => 'webhook_log', 'error' => $e->getMessage()];
    }
}

/**
 * Combined customer detail for Slip Center: BDO orders + slips in one call.
 * Much faster than two separate API calls from Next.js.
 *
 * Input: partner_id, customer_ref, line_user_id (at least one required)
 * Optional: limit (default 100)
 */
function getSlipCenterCustomerDetail($db, $input)
{
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));
    $limit       = min((int) ($input['limit'] ?? 100), 200);

    if ($partnerId === '' && $lineUserId === '' && $customerRef === '') {
        return ['bdo_orders' => [], 'slips' => [], 'total_bdos' => 0, 'total_slips' => 0, 'error' => 'No identifier provided'];
    }

    // Step 1: Resolve line_user_id and partner_id from whatever was given
    if ($lineUserId === '' && $customerRef !== '') {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE customer_ref = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([$customerRef]);
            $row = $stmt->fetchColumn();
            if ($row) $lineUserId = $row;
        } catch (Exception $e) {}
        if ($lineUserId === '') {
            try {
                $stmt = $db->prepare("SELECT line_user_id FROM odoo_bdos WHERE customer_ref = ? AND line_user_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1");
                $stmt->execute([$customerRef]);
                $row = $stmt->fetchColumn();
                if ($row) $lineUserId = $row;
            } catch (Exception $e) {}
        }
    }

    if (($partnerId === '' || $partnerId === '-') && $customerRef !== '') {
        try {
            $stmt = $db->prepare("SELECT partner_id FROM odoo_bdos WHERE customer_ref = ? AND partner_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([$customerRef]);
            $row = $stmt->fetchColumn();
            if ($row) $partnerId = (string) $row;
        } catch (Exception $e) {}
    }
    if (($partnerId === '' || $partnerId === '-') && $lineUserId !== '') {
        try {
            $stmt = $db->prepare("SELECT odoo_partner_id FROM odoo_line_users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$lineUserId]);
            $row = $stmt->fetchColumn();
            if ($row) $partnerId = (string) $row;
        } catch (Exception $e) {}
    }

    $bdoOrders  = [];
    $allSlips   = [];
    $bdoErrors  = [];
    $slipErrors = [];

    // Step 2: Fetch BDO orders (pending)
    if ($partnerId !== '' && $partnerId !== '-') {
        try {
            $tableCheck = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
            if ($tableCheck->rowCount() > 0) {
                $contextTableExists = false;
                try {
                    $cx = $db->query("SHOW TABLES LIKE 'odoo_bdo_context'");
                    $contextTableExists = $cx && $cx->rowCount() > 0;
                } catch (Exception $e) {}

                $contextSelect = $contextTableExists
                    ? ", ctx.delivery_type, ctx.statement_pdf_path"
                    : ", NULL AS delivery_type, NULL AS statement_pdf_path";
                $contextJoin = $contextTableExists
                    ? "LEFT JOIN (
                        SELECT c1.bdo_id, c1.delivery_type, c1.statement_pdf_path
                        FROM odoo_bdo_context c1
                        INNER JOIN (SELECT bdo_id, MAX(id) AS max_id FROM odoo_bdo_context GROUP BY bdo_id) lc ON lc.max_id = c1.id
                       ) ctx ON bo.bdo_id = ctx.bdo_id"
                    : "";

                $stmt = $db->prepare("
                    SELECT bo.*, b.state as bdo_state, b.bdo_date, b.qr_data{$contextSelect}
                    FROM odoo_bdo_orders bo
                    LEFT JOIN odoo_bdos b ON bo.bdo_id = b.bdo_id
                    {$contextJoin}
                    WHERE bo.partner_id = ?
                      AND bo.payment_status IN ('pending', 'partial')
                    ORDER BY bo.created_at DESC
                    LIMIT ?
                ");
                $stmt->execute([(int) $partnerId, $limit]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) {
                    $r['id']             = (int) $r['id'];
                    $r['bdo_id']         = (int) $r['bdo_id'];
                    $r['order_id']       = (int) $r['order_id'];
                    $r['partner_id']     = $r['partner_id'] !== null ? (int) $r['partner_id'] : null;
                    $r['amount_total']   = $r['amount_total'] !== null ? (float) $r['amount_total'] : null;
                    $r['slip_upload_id'] = $r['slip_upload_id'] !== null ? (int) $r['slip_upload_id'] : null;
                }
                unset($r);
                $bdoOrders = $rows;
            }
        } catch (Exception $e) {
            $bdoErrors[] = $e->getMessage();
        }
    }

    // Step 3: Fetch slips for this customer (all statuses, recent 90 days)
    if ($lineUserId !== '') {
        try {
            // Check if users table exists before joining
            $usersTableExists = tableExists($db, 'users');
            $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : (defined('APP_URL') ? APP_URL : 'https://cny.re-ya.com'), '/');
            
            $customerJoin = $usersTableExists
                ? "LEFT JOIN users u ON u.line_user_id = s.line_user_id"
                : "";
            $customerSelect = $usersTableExists
                ? ", u.display_name AS customer_name, u.picture_url AS customer_avatar"
                : ", NULL AS customer_name, NULL AS customer_avatar";
            
            $stmt = $db->prepare("
                SELECT
                    s.id, s.line_user_id, s.line_account_id, s.odoo_slip_id, s.slip_inbox_id,
                    s.slip_inbox_name, s.bdo_id, s.bdo_name, s.invoice_id, s.order_id,
                    s.amount, s.transfer_date, s.image_path, s.image_url, s.uploaded_by,
                    s.status, s.match_confidence, s.delivery_type, s.bdo_amount,
                    s.match_reason, s.uploaded_at, s.matched_at
                    {$customerSelect}
                FROM odoo_slip_uploads s
                {$customerJoin}
                WHERE s.line_user_id = ?
                  AND s.uploaded_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                ORDER BY s.uploaded_at DESC
                LIMIT ?
            ");
            $stmt->execute([$lineUserId, $limit]);
            $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($slips as &$slip) {
                $slip['id']            = (int) $slip['id'];
                $slip['amount']        = $slip['amount'] !== null ? (float) $slip['amount'] : null;
                $slip['slip_inbox_id'] = $slip['slip_inbox_id'] !== null ? (int) $slip['slip_inbox_id'] : null;
                $slip['bdo_id']        = $slip['bdo_id'] !== null ? (int) $slip['bdo_id'] : null;
                $slip['bdo_amount']    = $slip['bdo_amount'] !== null ? (float) $slip['bdo_amount'] : null;
                if ($slip['image_path']) {
                    $slip['image_full_url'] = $baseUrl . '/' . ltrim($slip['image_path'], '/');
                } else {
                    $slip['image_full_url'] = $slip['image_url'] ?: null;
                }
            }
            unset($slip);
            $allSlips = $slips;
        } catch (Exception $e) {
            $slipErrors[] = $e->getMessage();
        }
    }

    $pendingSlips  = array_values(array_filter($allSlips, function($s) { return ($s['status'] ?? '') === 'pending'; }));
    $today         = date('Y-m-d');
    $matchedToday  = array_values(array_filter($allSlips, function($s) use ($today) {
        return ($s['status'] ?? '') === 'matched' &&
            !empty($s['matched_at']) &&
            substr($s['matched_at'], 0, 10) === $today;
    }));

    return [
        'bdo_orders'    => $bdoOrders,
        'pending_slips' => $pendingSlips,
        'all_slips'     => $allSlips,
        'matched_today' => $matchedToday,
        'resolved'      => [
            'partner_id'  => $partnerId ?: null,
            'line_user_id' => $lineUserId ?: null,
        ],
        'stats' => [
            'total_bdos'          => count($bdoOrders),
            'total_pending_slips' => count($pendingSlips),
            'total_matched_today' => count($matchedToday),
            'total_all_slips'     => count($allSlips),
        ],
        'errors' => array_merge($bdoErrors, $slipErrors),
    ];
}

function ensureOdooSlipInboxId($db, $odoo, array $localSlip, $lineUserId)
{
    $existingId = (int) ($localSlip['slip_inbox_id'] ?? $localSlip['odoo_slip_id'] ?? 0);
    if ($existingId > 0) {
        return $existingId;
    }

    $imagePath = trim((string) ($localSlip['image_path'] ?? ''));
    if ($imagePath === '') {
        throw new Exception('Local slip image is missing');
    }

    $fullPath = realpath(__DIR__ . '/../' . ltrim($imagePath, '/'));
    if ($fullPath === false || !is_file($fullPath)) {
        throw new Exception('Local slip image file not found');
    }

    $imageData = file_get_contents($fullPath);
    if ($imageData === false || strlen($imageData) < 100) {
        throw new Exception('Local slip image file is empty');
    }

    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    $mimeType = $mimeMap[$extension] ?? 'image/jpeg';
    $filename = basename($fullPath);

    $uploadOptions = [];
    if (!empty($localSlip['amount'])) {
        $uploadOptions['amount'] = (float) $localSlip['amount'];
    }
    if (!empty($localSlip['transfer_date'])) {
        $uploadOptions['transfer_date'] = $localSlip['transfer_date'];
    }
    if (!empty($localSlip['invoice_id'])) {
        $uploadOptions['invoice_id'] = (int) $localSlip['invoice_id'];
    }
    if (!empty($localSlip['order_id'])) {
        $uploadOptions['order_id'] = (int) $localSlip['order_id'];
    }

    $uploadResult = $odoo->uploadSlip($lineUserId, base64_encode($imageData), $uploadOptions);
    $slipData = $uploadResult['data']['slip'] ?? $uploadResult['slip'] ?? [];
    $slipInboxId = (int) ($slipData['slip_inbox_id'] ?? 0);
    if ($slipInboxId <= 0) {
        throw new Exception('Odoo did not return slip_inbox_id');
    }

    $db->prepare("
        UPDATE odoo_slip_uploads
        SET slip_inbox_id = ?,
            slip_inbox_name = COALESCE(?, slip_inbox_name),
            odoo_slip_id = COALESCE(?, odoo_slip_id),
            status = COALESCE(?, status),
            match_confidence = COALESCE(?, match_confidence),
            bdo_id = COALESCE(?, bdo_id),
            bdo_name = COALESCE(?, bdo_name),
            delivery_type = COALESCE(?, delivery_type),
            bdo_amount = COALESCE(?, bdo_amount)
        WHERE id = ?
    ")->execute([
        $slipInboxId,
        $slipData['slip_inbox_name'] ?? null,
        !empty($slipData['id']) ? (int) $slipData['id'] : null,
        $slipData['status'] ?? null,
        $slipData['match_confidence'] ?? null,
        !empty($slipData['bdo_id']) ? (int) $slipData['bdo_id'] : null,
        $slipData['bdo_name'] ?? null,
        $slipData['delivery_type'] ?? null,
        isset($slipData['bdo_amount']) ? (float) $slipData['bdo_amount'] : null,
        (int) $localSlip['id'],
    ]);

    return $slipInboxId;
}

/**
 * Helper: Resolve line_account_id from line_user_id.
 */
function resolveLineAccountId($db, $lineUserId)
{
    // Try odoo_line_users first
    $stmt = $db->prepare('SELECT line_account_id FROM odoo_line_users WHERE line_user_id = ? LIMIT 1');
    $stmt->execute([$lineUserId]);
    $val = $stmt->fetchColumn();
    if ($val !== false && $val !== null) {
        return (int) $val;
    }

    // Fallback to users table
    $stmt = $db->prepare('SELECT line_account_id FROM users WHERE line_user_id = ? LIMIT 1');
    $stmt->execute([$lineUserId]);
    $val = $stmt->fetchColumn();
    return ($val !== false && $val !== null) ? (int) $val : 0;
}

function resolveLineUserIdFromPartner($db, $partnerId)
{
    if ((int) $partnerId <= 0) {
        return '';
    }

    try {
        $stmt = $db->prepare('SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1');
        $stmt->execute([(int) $partnerId]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            return trim((string) $val);
        }
    } catch (Exception $e) {
        error_log('[resolveLineUserIdFromPartner] odoo_line_users: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare('SELECT line_user_id FROM odoo_bdo_context WHERE bdo_id IN (SELECT bdo_id FROM odoo_bdos WHERE partner_id = ? ORDER BY updated_at DESC LIMIT 5) AND line_user_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([(int) $partnerId]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? trim((string) $val) : '';
    } catch (Exception $e) {
        error_log('[resolveLineUserIdFromPartner] odoo_bdo_context: ' . $e->getMessage());
        return '';
    }
}

function resolveBdoAmount($db, $bdoId)
{
    if ((int) $bdoId <= 0) {
        return null;
    }

    try {
        $stmt = $db->prepare("
            SELECT COALESCE(amount_total, amount) AS amount_value
            FROM (
                SELECT amount_total, NULL AS amount FROM odoo_bdos WHERE bdo_id = ? LIMIT 1
            ) b
        ");
        $stmt->execute([(int) $bdoId]);
        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null && $value !== '') {
            return (float) $value;
        }
    } catch (Exception $e) {
        error_log('[resolveBdoAmount] odoo_bdos: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare('SELECT amount FROM odoo_bdo_context WHERE bdo_id = ? ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([(int) $bdoId]);
        $value = $stmt->fetchColumn();
        return ($value !== false && $value !== null && $value !== '') ? (float) $value : null;
    } catch (Exception $e) {
        error_log('[resolveBdoAmount] odoo_bdo_context: ' . $e->getMessage());
        return null;
    }
}

// ============================================================================
// Functions migrated from odoo-webhooks-dashboard.php (fallback)
// ============================================================================

function getCustomer360($db, $input)
{
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));

    // Resolve line_user_id from partner_id if not provided
    if ($lineUserId === '' && $partnerId !== '' && $partnerId !== '-') {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_partner_id = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([(int) $partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $lineUserId = $row['line_user_id'];
        } catch (Exception $e) { /* ignore */ }
    }

    // Resolve line_user_id from customer_ref if not provided
    if ($lineUserId === '' && $customerRef !== '') {
        try {
            $stmt = $db->prepare("SELECT line_user_id FROM odoo_line_users WHERE odoo_customer_code = ? AND line_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([$customerRef]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $lineUserId = $row['line_user_id'];
        } catch (Exception $e) { /* ignore */ }
    }

    if ($lineUserId === '') {
        return [
            'line_user_id' => null,
            'linked' => false,
            'profile' => null,
            'credit' => null,
            'orders' => ['total' => 0, 'recent' => []],
            'invoices' => ['total' => 0, 'recent' => []],
            'timeline' => [],
            'webhook_summary' => ['total' => 0, 'success' => 0, 'failed' => 0],
            'warnings' => ['No line_user_id resolved']
        ];
    }

    // Use existing OdooCustomerDashboardService
    require_once __DIR__ . '/../classes/OdooCustomerDashboardService.php';

    $lineAccountId = null;
    try {
        $laStmt = $db->query("SELECT id FROM line_accounts LIMIT 1");
        $la = $laStmt->fetch(PDO::FETCH_ASSOC);
        if ($la) $lineAccountId = (int) $la['id'];
    } catch (Exception $e) { /* ignore */ }

    $service = new OdooCustomerDashboardService($db, $lineAccountId);

    $options = [
        'orders_limit'   => (int) ($input['orders_limit']   ?? 10),
        'invoices_limit' => (int) ($input['invoices_limit'] ?? 10),
        'timeline_limit' => (int) ($input['timeline_limit'] ?? 20),
        'top_products'   => (int) ($input['top_products']   ?? 5),
    ];

    return $service->buildByLineUserId($lineUserId, $options);
}

/**
 * Lightweight webhook stats for sidebar badges in Next.js inbox.
 * Returns minimal counts for a specific customer.
 */
function getWebhookStatsMini($db, $input)
{
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));

    $where = [];
    $params = [];

    if ($lineUserId !== '') {
        $pidExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')), '')";
        $where[] = "(line_user_id = ? OR {$pidExpr} = ?)";
        $params[] = $lineUserId;
        $params[] = $lineUserId;
    } elseif ($partnerId !== '' && $partnerId !== '-') {
        $cidExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')";
        $cpidExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), '')";
        $where[] = "({$cidExpr} = ? OR {$cpidExpr} = ?)";
        $params[] = $partnerId;
        $params[] = $partnerId;
    } elseif ($customerRef !== '') {
        $refExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
        $where[] = "{$refExpr} = ?";
        $params[] = $customerRef;
    } else {
        return ['total' => 0, 'success' => 0, 'failed' => 0, 'last_event_at' => null];
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    try {
        $stmt = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(IF(status = 'success', 1, 0)) as success,
                SUM(IF(status IN ('failed', 'dead_letter'), 1, 0)) as failed,
                SUM(IF(status = 'retry', 1, 0)) as retry,
                MAX(processed_at) as last_event_at
            FROM odoo_webhooks_log
            {$whereClause}
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total'         => (int) ($row['total'] ?? 0),
            'success'       => (int) ($row['success'] ?? 0),
            'failed'        => (int) ($row['failed'] ?? 0),
            'retry'         => (int) ($row['retry'] ?? 0),
            'last_event_at' => $row['last_event_at'] ?? null,
        ];
    } catch (Exception $e) {
        return ['total' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0, 'last_event_at' => null, 'error' => $e->getMessage()];
    }
}

/**
 * List Dead Letter Queue items with pagination and filters.
 */
function getDlqList($db, $input)
{
    if (!tableExists($db, 'odoo_webhook_dlq')) {
        return ['items' => [], 'total' => 0, 'error' => 'DLQ table not found'];
    }

    $limit  = min((int) ($input['limit']  ?? 30), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $status = trim((string) ($input['status'] ?? ''));

    $where = [];
    $params = [];

    if ($status !== '') {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }

    $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_webhook_dlq d {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare("
        SELECT
            d.id,
            d.webhook_log_id,
            d.error_message,
            d.retry_count,
            d.last_retry_at,
            d.created_at,
            d.resolved_at,
            d.status,
            w.event_type,
            w.delivery_id,
            JSON_UNQUOTE(JSON_EXTRACT(w.payload, '$.order_name')) as order_name,
            JSON_UNQUOTE(JSON_EXTRACT(w.payload, '$.customer.name')) as customer_name
        FROM odoo_webhook_dlq d
        LEFT JOIN odoo_webhooks_log w ON d.webhook_log_id = w.id
        {$whereClause}
        ORDER BY d.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$item) {
        $item['id'] = (int) $item['id'];
        $item['webhook_log_id'] = $item['webhook_log_id'] !== null ? (int) $item['webhook_log_id'] : null;
        $item['retry_count'] = (int) $item['retry_count'];
    }
    unset($item);

    return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
}

/**
 * Retry a single DLQ item — reprocess with original payload.
 */
function retryDlqItem($db, $input)
{
    if (!tableExists($db, 'odoo_webhook_dlq')) {
        throw new Exception('DLQ table not found');
    }

    $dlqId = (int) ($input['dlq_id'] ?? $input['id'] ?? 0);
    if ($dlqId <= 0) {
        throw new Exception('Missing or invalid dlq_id');
    }

    // Get DLQ item
    $stmt = $db->prepare("SELECT d.*, w.payload, w.event_type, w.delivery_id FROM odoo_webhook_dlq d LEFT JOIN odoo_webhooks_log w ON d.webhook_log_id = w.id WHERE d.id = ?");
    $stmt->execute([$dlqId]);
    $dlq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dlq) {
        throw new Exception('DLQ item not found');
    }

    if ($dlq['status'] === 'resolved') {
        return ['success' => true, 'message' => 'Item already resolved', 'dlq_id' => $dlqId];
    }

    // Mark as retrying
    $db->prepare("UPDATE odoo_webhook_dlq SET status = 'retrying', last_retry_at = NOW(), retry_count = retry_count + 1 WHERE id = ?")->execute([$dlqId]);

    // Try to reprocess the webhook
    $payload = json_decode($dlq['payload'], true);
    $event = $dlq['event_type'] ?? 'unknown';
    $deliveryId = $dlq['delivery_id'] ?? ('dlq_retry_' . $dlqId);

    try {
        require_once __DIR__ . '/../classes/OdooWebhookHandler.php';
        $handler = new OdooWebhookHandler($db);

        $eventData = $payload['data'] ?? $payload;
        $notify = $payload['notify'] ?? ['customer' => true, 'salesperson' => false];
        $messageTemplate = $payload['message_template'] ?? [];

        $result = $handler->processWebhook($deliveryId, $event, $eventData, $notify, $messageTemplate);

        // Mark as resolved
        $db->prepare("UPDATE odoo_webhook_dlq SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([$dlqId]);

        // Update original webhook log status
        if ($dlq['webhook_log_id']) {
            $db->prepare("UPDATE odoo_webhooks_log SET status = 'success', error_message = NULL WHERE id = ?")->execute([$dlq['webhook_log_id']]);
        }

        return ['success' => true, 'message' => 'DLQ item reprocessed successfully', 'dlq_id' => $dlqId, 'result' => $result];
    } catch (Exception $e) {
        // Mark back as pending
        $db->prepare("UPDATE odoo_webhook_dlq SET status = 'pending', error_message = ? WHERE id = ?")->execute([$e->getMessage(), $dlqId]);
        throw new Exception('DLQ retry failed: ' . $e->getMessage());
    }
}

/**
 * Get DLQ summary statistics.
 */
function getDlqStats($db)
{
    if (!tableExists($db, 'odoo_webhook_dlq')) {
        return ['available' => false, 'total' => 0, 'pending' => 0, 'retrying' => 0, 'resolved' => 0, 'abandoned' => 0];
    }

    try {
        $rows = $db->query("
            SELECT status, COUNT(*) as count
            FROM odoo_webhook_dlq
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['available' => true, 'total' => 0, 'pending' => 0, 'retrying' => 0, 'resolved' => 0, 'abandoned' => 0];
        foreach ($rows as $row) {
            $s = $row['status'];
            $c = (int) $row['count'];
            $stats['total'] += $c;
            if (isset($stats[$s])) $stats[$s] = $c;
        }

        // Recent failures
        $stats['recent_24h'] = (int) $db->query("SELECT COUNT(*) FROM odoo_webhook_dlq WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

        // Top error messages
        $stats['top_errors'] = $db->query("
            SELECT error_message, COUNT(*) as count
            FROM odoo_webhook_dlq
            WHERE status = 'pending'
            GROUP BY error_message
            ORDER BY count DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    } catch (Exception $e) {
        return ['available' => true, 'total' => 0, 'error' => $e->getMessage()];
    }
}

// ======================================================================== //
// Batch Endpoints — reduce N API round-trips to 1                         //
// ======================================================================== //

/**
 * Batch: Customer Full Detail
 * 
 * Combines customer_detail + odoo_orders + odoo_invoices + odoo_slips +
 * odoo_bdo_list_api + activity_log_list into a single response.
 * Reduces 6 parallel API calls from the dashboard modal to 1.
 */
function getCustomerFullDetail($db, $input)
{
    $partnerId   = trim((string) ($input['partner_id']   ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));

    if ($partnerId === '' && $customerRef === '') {
        throw new Exception('Missing partner_id or customer_ref');
    }

    // Build sub-inputs
    $commonParams = [
        'partner_id'   => $partnerId,
        'customer_ref' => $customerRef,
    ];
    $orderParams   = array_merge($commonParams, ['limit' => (int) ($input['orders_limit'] ?? 100), 'offset' => 0]);
    $invoiceParams = array_merge($commonParams, ['limit' => (int) ($input['invoices_limit'] ?? 100), 'offset' => 0]);
    $slipParams    = ['partner_id' => $partnerId];
    $bdoParams     = array_merge($commonParams, ['limit' => (int) ($input['bdo_limit'] ?? 100), 'offset' => 0]);
    $activityParams = ['partner_id' => $partnerId, 'limit' => (int) ($input['activity_limit'] ?? 100)];

    $result = [
        'customer_detail' => null,
        'orders'          => null,
        'invoices'        => null,
        'slips'           => null,
        'bdos'            => null,
        'activity_log'    => null,
        'errors'          => [],
    ];

    // Execute all sub-queries (sequential but no HTTP overhead per call)
    // Skip sub-query when caller passes limit=0 to allow fast partial fetches
    try { $result['customer_detail'] = getCustomerDetail($db, $commonParams); }
    catch (Exception $e) { $result['errors'][] = 'customer_detail: ' . $e->getMessage(); }

    if ((int)($input['orders_limit'] ?? 100) > 0) {
        try { $result['orders'] = getOdooOrders($db, $orderParams); }
        catch (Exception $e) { $result['errors'][] = 'orders: ' . $e->getMessage(); }
    }

    if ((int)($input['invoices_limit'] ?? 100) > 0) {
        try { $result['invoices'] = getOdooInvoices($db, $invoiceParams); }
        catch (Exception $e) { $result['errors'][] = 'invoices: ' . $e->getMessage(); }
    }

    try { $result['slips'] = getOdooSlips($db, $slipParams); }
    catch (Exception $e) { $result['errors'][] = 'slips: ' . $e->getMessage(); }

    if ((int)($input['bdo_limit'] ?? 100) > 0) {
        try { $result['bdos'] = getOdooBdos($db, $bdoParams); }
        catch (Exception $e) { $result['errors'][] = 'bdos: ' . $e->getMessage(); }
    }

    if ((int)($input['activity_limit'] ?? 100) > 0) {
        try { $result['activity_log'] = activityLogList($db, $activityParams); }
        catch (Exception $e) { $result['errors'][] = 'activity_log: ' . $e->getMessage(); }
    }

    return $result;
}

/**
 * Batch: Overview Combined
 * 
 * Combines stats + order_grouped_today + pending_bdo_orders +
 * customer_list(overdue) into a single response.
 * Reduces the overview section from 6 API calls to 1 main call.
 */
function getOverviewCombined($db, $input)
{
    $result = [
        'stats'              => null,
        'orders_today'       => null,
        'pending_bdos'       => null,
        'overdue_customers'  => null,
        'errors'             => [],
    ];

    // Stats (the heaviest single query — consolidated aggregation)
    try { $result['stats'] = getStats($db); }
    catch (Exception $e) { $result['errors'][] = 'stats: ' . $e->getMessage(); }

    // Today's orders
    try {
        $result['orders_today'] = getOrderGroupedToday($db, [
            'limit'  => (int) ($input['orders_limit'] ?? 5),
            'offset' => 0,
        ]);
    } catch (Exception $e) { $result['errors'][] = 'orders_today: ' . $e->getMessage(); }

    // Pending BDOs
    try {
        $result['pending_bdos'] = getPendingBdoOrdersApi($db, [
            'limit'  => (int) ($input['bdo_limit'] ?? 20),
            'offset' => 0,
        ]);
    } catch (Exception $e) { $result['errors'][] = 'pending_bdos: ' . $e->getMessage(); }

    // Overdue customers
    try {
        $result['overdue_customers'] = getCustomerList($db, [
            'invoice_filter' => 'overdue',
            'limit'          => (int) ($input['overdue_limit'] ?? 5),
            'offset'         => 0,
            'sort_by'        => 'due_desc',
        ]);
    } catch (Exception $e) { $result['errors'][] = 'overdue_customers: ' . $e->getMessage(); }

    return $result;
}
