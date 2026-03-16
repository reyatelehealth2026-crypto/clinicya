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
        'stats' => 20,
        'order_grouped_today' => 20,
        'customer_list' => 20,
        'notification_log' => 20,
        'salesperson_list' => 180,
    ];
    $cacheKey = null;
    if (isset($cacheTtls[$action])) {
        $cacheKey = dashboardApiBuildCacheKey($action, $input);
        $cachedResult = dashboardApiCacheGet($cacheKey, (int) $cacheTtls[$action]);
        if ($cachedResult !== null) {
            echo json_encode(['success' => true, 'data' => $cachedResult], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

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
        case 'slip_match_bdo':
            $result = slipMatchBdoProxy($db, $input);
            break;
        case 'slip_unmatch':
            $result = slipUnmatchProxy($db, $input);
            break;
        case 'bdo_detail':
            $result = bdoDetailProxy($db, $input);
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
        case 'pending_bdo_orders':
            $result = getPendingBdoOrdersApi($db, $input);
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
        case 'odoo_bdo_list_api':
            $result = getOdooBdos($db, $input);
            break;
        default:
            throw new Exception('Unknown action: ' . $action);
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

    // Consolidated single-pass aggregation (replaces ~12 separate COUNT queries)
    $latencySelect = $hasLatency
        ? "ROUND(AVG(CASE WHEN process_latency_ms IS NOT NULL THEN process_latency_ms END), 2) as avg_latency_ms,"
        : "NULL as avg_latency_ms,";
    $retriedSelect = $hasRetryCount
        ? "SUM(IF(COALESCE(retry_count, 0) > 0, 1, 0)) as retried_total,"
        : "0 as retried_total,";

    $agg = $db->query("
        SELECT
            COUNT(*) as total,
            SUM(IF(DATE({$processedAtExpr}) = CURDATE(), 1, 0)) as today,
            SUM(IF(status = 'success', 1, 0)) as success,
            SUM(IF(status = 'failed', 1, 0)) as failed,
            SUM(IF(status = 'received', 1, 0)) as received,
            SUM(IF(status = 'processing', 1, 0)) as processing,
            SUM(IF(status = 'retry', 1, 0)) as retry_cnt,
            SUM(IF(status = 'dead_letter', 1, 0)) as dead_letter,
            SUM(IF(status = 'duplicate', 1, 0)) as duplicate,
            {$latencySelect}
            {$retriedSelect}
            COUNT(DISTINCT CASE WHEN DATE({$processedAtExpr}) = CURDATE() THEN COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), CAST(order_id AS CHAR)) END) as unique_orders_today,
            SUM(IF(DATE({$processedAtExpr}) = CURDATE() AND line_user_id IS NOT NULL, 1, 0)) as notified_today,
            MAX({$processedAtExpr}) as last_webhook
        FROM odoo_webhooks_log
    ")->fetch(PDO::FETCH_ASSOC);

    $total      = (int) ($agg['total'] ?? 0);
    $today      = (int) ($agg['today'] ?? 0);
    $success    = (int) ($agg['success'] ?? 0);
    $failed     = (int) ($agg['failed'] ?? 0);
    $received   = (int) ($agg['received'] ?? 0);
    $processing = (int) ($agg['processing'] ?? 0);
    $retry      = (int) ($agg['retry_cnt'] ?? 0);
    $deadLetter = (int) ($agg['dead_letter'] ?? 0);
    $duplicate  = (int) ($agg['duplicate'] ?? 0);
    $avgLatencyMs  = $agg['avg_latency_ms'] !== null ? (float) $agg['avg_latency_ms'] : null;
    $retriedTotal  = (int) ($agg['retried_total'] ?? 0);
    $uniqueOrders  = (int) ($agg['unique_orders_today'] ?? 0);
    $notified      = (int) ($agg['notified_today'] ?? 0);
    $lastWebhook   = $agg['last_webhook'] ?? null;

    $dlqTotal = tableExists($db, 'odoo_webhook_dlq')
        ? $db->query("SELECT COUNT(*) FROM odoo_webhook_dlq")->fetchColumn()
        : 0;

    $topFailedEvents = $db->query(" 
        SELECT event_type, COUNT(*) as count 
        FROM odoo_webhooks_log 
        WHERE status IN ('failed', 'retry', 'dead_letter')
        GROUP BY event_type 
        ORDER BY count DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Events by type (today)
    $stmt = $db->query("
        SELECT event_type, COUNT(*) as count 
        FROM odoo_webhooks_log 
        WHERE DATE({$processedAtExpr}) = CURDATE()
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
                return (empty($o['amount_total']) || $o['amount_total'] == 0 || empty($o['state_display']) || !$o['is_paid']) && $o['order_name'];
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
                        if (isset($invMap[$o['order_name']])) {
                            $im = $invMap[$o['order_name']];
                            if ((empty($o['amount_total']) || $o['amount_total'] == 0) && $im['amount_total'] !== null) {
                                $o['amount_total'] = (float) $im['amount_total'];
                            }
                            if (!$o['date_order'] && $im['invoice_date']) {
                                $o['date_order'] = $im['invoice_date'];
                            }
                            if ((!$o['state_display'] || $o['state_display'] === '' || $o['state_display'] === 'null') && $im['invoice_state']) {
                                $isPaidFromInv = (bool) $im['is_paid'];
                                $o['state_display'] = $isPaidFromInv ? 'ชำระแล้ว' : $im['invoice_state'];
                            }
                            if ((bool) ($im['is_paid'] ?? false)) {
                                $o['is_paid'] = true;
                                $o['payment_status'] = 'paid';
                                if (!$o['state_display'] || $o['state_display'] === '' || $o['state_display'] === 'null') {
                                    $o['state_display'] = 'ชำระแล้ว';
                                }
                            }
                        }
                    }
                    unset($o);
                } catch (Exception $e) { /* ignore */ }
            }

            // Third backfill: check webhook log for invoice.paid events to detect paid orders
            // This catches orders where invoice.paid webhook arrived but sync table wasn't updated
            $unpaidOrders = array_filter($orders, function($o) {
                return !$o['is_paid'] && $o['order_name'];
            });
            if (!empty($unpaidOrders)) {
                try {
                    $names3 = array_map(function($o) { return $o['order_name']; }, $unpaidOrders);
                    $ph3 = implode(',', array_fill(0, count($names3), '?'));
                    $orderNameExpr3 = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.order_name')),''),NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.order_ref')),''))";
                    $amtExpr3 = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.amount_total')),''),'0') AS DECIMAL(14,2))";
                    $paidStmt = $db->prepare("
                        SELECT {$orderNameExpr3} AS order_name,
                               MAX({$amtExpr3}) AS amount_total,
                               MAX(processed_at) AS paid_at
                        FROM odoo_webhooks_log
                        WHERE event_type IN ('invoice.paid', 'payment.confirmed', 'payment.received')
                          AND status = 'success'
                          AND {$orderNameExpr3} IN ({$ph3})
                        GROUP BY {$orderNameExpr3}
                    ");
                    $paidStmt->execute($names3);
                    $paidMap = [];
                    foreach ($paidStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        if ($row['order_name']) $paidMap[$row['order_name']] = $row;
                    }
                    foreach ($orders as &$o) {
                        if (isset($paidMap[$o['order_name']])) {
                            $pm = $paidMap[$o['order_name']];
                            $o['is_paid'] = true;
                            $o['payment_status'] = 'paid';
                            if (!$o['state_display'] || $o['state_display'] === '' || $o['state_display'] === 'null') {
                                $o['state_display'] = 'ชำระแล้ว';
                            }
                            if ((empty($o['amount_total']) || $o['amount_total'] == 0) && (float) $pm['amount_total'] > 0) {
                                $o['amount_total'] = (float) $pm['amount_total'];
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

            // Second invoice backfill: check webhook log for invoice.paid events
            // This catches invoices where invoice.paid arrived but sync table wasn't updated
            $unpaidInvs = array_filter($invoices, function($inv) {
                return !$inv['is_paid'] && $inv['invoice_number'];
            });
            if (!empty($unpaidInvs)) {
                try {
                    $invNums2 = array_map(function($inv) { return $inv['invoice_number']; }, $unpaidInvs);
                    $ph2 = implode(',', array_fill(0, count($invNums2), '?'));
                    $invNumExpr2 = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.invoice_number')),'')";
                    $paidInvStmt = $db->prepare("
                        SELECT {$invNumExpr2} AS invoice_number,
                               MAX(processed_at) AS paid_at
                        FROM odoo_webhooks_log
                        WHERE event_type IN ('invoice.paid', 'payment.confirmed', 'payment.received')
                          AND status = 'success'
                          AND {$invNumExpr2} IN ({$ph2})
                        GROUP BY {$invNumExpr2}
                    ");
                    $paidInvStmt->execute($invNums2);
                    $paidInvMap = [];
                    foreach ($paidInvStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        if ($row['invoice_number']) $paidInvMap[$row['invoice_number']] = $row;
                    }
                    foreach ($invoices as &$inv) {
                        if (isset($paidInvMap[$inv['invoice_number']])) {
                            $inv['is_paid'] = true;
                            $inv['invoice_state'] = 'paid';
                            $inv['payment_state'] = 'paid';
                            $inv['amount_residual'] = 0.0;
                            $inv['is_overdue'] = false;
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
            invoice_id,
            order_id,
            uploaded_at,
            match_reason
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
        AND DATE(sent_at) = CURDATE()
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

    // Stats
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
        $stats['today_sent'] = (int) $db->query("SELECT COUNT(*) FROM odoo_notification_log WHERE status = 'sent' AND DATE(sent_at) = CURDATE()")->fetchColumn();
        $stats['today_failed'] = (int) $db->query("SELECT COUNT(*) FROM odoo_notification_log WHERE status = 'failed' AND DATE(sent_at) = CURDATE()")->fetchColumn();
        $stats['today_total'] = (int) $db->query("SELECT COUNT(*) FROM odoo_notification_log WHERE DATE(sent_at) = CURDATE()")->fetchColumn();
        $stats['unique_users'] = (int) $db->query("SELECT COUNT(DISTINCT line_user_id) FROM odoo_notification_log WHERE status = 'sent'")->fetchColumn();
        $stats['unique_users_today'] = (int) $db->query("SELECT COUNT(DISTINCT line_user_id) FROM odoo_notification_log WHERE status = 'sent' AND DATE(sent_at) = CURDATE()")->fetchColumn();

        $eventRows = $db->query("
            SELECT event_type, COUNT(*) as count
            FROM odoo_notification_log
            WHERE status = 'sent' AND DATE(sent_at) = CURDATE()
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
    $whJoin = $hasWhLog
        ? "LEFT JOIN (SELECT delivery_id, MAX(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name'))) as order_name, MAX(order_id) as order_id FROM odoo_webhooks_log GROUP BY delivery_id) wh ON wh.delivery_id = n.delivery_id"
        : "";
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
        $where[] = "DATE({$processedAtColumn}) = ?";
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

function dashboardApiCacheGet($key, $ttl)
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

    if ((time() - (int) $payload['t']) > $ttl) {
        @unlink($path);
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

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $totalStmt = $db->prepare("SELECT COUNT(*) FROM odoo_bdos {$whereClause}");
        $totalStmt->execute($params);
        $total = (int) $totalStmt->fetchColumn();

        if ($total > 0 || $whereClause !== '') {
            $sql = "
                SELECT
                    id, bdo_id, bdo_name,
                    order_id, order_name,
                    partner_id, customer_ref, line_user_id,
                    salesperson_id, salesperson_name,
                    state, amount_total, currency,
                    bdo_date, expected_delivery,
                    latest_event, synced_at, updated_at
                FROM odoo_bdos
                {$whereClause}
                ORDER BY updated_at DESC
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

/**
 * Customer 360 — aggregated customer view using OdooCustomerDashboardService.
 * Returns profile, credit, orders, invoices, timeline, webhook_summary in a single call.
 */
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
            ) ctx ON bo.bdo_id = ctx.bdo_id"
            : "";

        $stmt = $db->prepare("
            SELECT bo.*, b.state as bdo_state, b.bdo_date, b.qr_data{$contextSelect}
            FROM odoo_bdo_orders bo
            LEFT JOIN odoo_bdos b ON bo.bdo_id = b.bdo_id
            {$contextJoin}
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
 * Unmatch a slip from a BDO. Resets local DB statuses.
 * Called from Next.js inbox via proxy.
 */
function unmatchSlipAction($db, $input)
{
    $slipId = (int) ($input['slip_id'] ?? 0);
    $bdoId  = (int) ($input['bdo_id'] ?? 0);
    $reason = trim((string) ($input['reason'] ?? 'Unmatch from inbox'));

    if (!$slipId) {
        return ['success' => false, 'error' => 'Missing slip_id'];
    }

    try {
        // Reset slip status
        $db->prepare("
            UPDATE odoo_slip_uploads
            SET status = 'pending', match_reason = ?, matched_at = NULL,
                odoo_slip_id = NULL, order_id = NULL, invoice_id = NULL,
                match_confidence = NULL, bdo_name = NULL, slip_inbox_id = NULL
            WHERE id = ?
        ")->execute([$reason, $slipId]);

        // Reset odoo_bdo_orders if bdo_id provided
        if ($bdoId > 0) {
            try {
                $tblChk = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
                if ($tblChk->rowCount() > 0) {
                    $db->prepare("
                        UPDATE odoo_bdo_orders
                        SET payment_status = 'pending', slip_upload_id = NULL, updated_at = NOW()
                        WHERE bdo_id = ? AND slip_upload_id = ?
                    ")->execute([$bdoId, $slipId]);
                }
            } catch (Exception $e2) {
                error_log("[unmatchSlipAction] bdo_orders reset failed: " . $e2->getMessage());
            }
        }

        return ['success' => true, 'message' => 'Unmatch completed'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Proxy: POST /reya/slip/match-bdo to Odoo
 * Matches a slip with one or more BDOs via Odoo's staging/production endpoint.
 * Falls back to local DB update if Odoo is unreachable.
 */
/**
 * Proxy: POST /reya/slip/match-bdo to Odoo
 *
 * IMPORTANT: Local DB is updated ONLY after Odoo confirms success.
 * There is NO local-only fallback — if Odoo rejects, we return an error.
 * This prevents state divergence between Re-Ya and Odoo.
 */
function slipMatchBdoProxy($db, $input)
{
    $slipInboxId = (int) ($input['slip_inbox_id'] ?? 0);
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $matches     = $input['matches'] ?? [];
    $note        = trim((string) ($input['note'] ?? ''));

    // ── Legacy fallback: slip_id + bdo_id (no slip_inbox_id) ──────────────
    // When slip_inbox_id is absent the slip has not been synced to Odoo yet.
    // Update local DB only; Odoo will pick up the match on next sync.
    $legacySlipId = (int) ($input['slip_id'] ?? 0);
    $legacyBdoId  = (int) ($input['bdo_id']  ?? 0);

    if (!$slipInboxId && $legacySlipId > 0 && $legacyBdoId > 0) {
        return _legacyLocalMatch($db, $legacySlipId, $legacyBdoId, $note);
    }

    if (!$slipInboxId || empty($matches)) {
        return ['success' => false, 'error' => 'Missing slip_inbox_id or matches'];
    }

    // Validate matches array
    foreach ($matches as $i => $m) {
        if (empty($m['bdo_id']) || !isset($m['amount']) || (float)$m['amount'] <= 0) {
            return ['success' => false, 'error' => "matches[{$i}] ต้องมี bdo_id และ amount > 0"];
        }
    }

    $odooResult = _callOdooApi('/reya/slip/match-bdo', [
        'line_user_id'  => $lineUserId,
        'slip_inbox_id' => $slipInboxId,
        'matches'       => $matches,
        'note'          => $note,
    ]);

    $odooError = _extractOdooError($odooResult);

    if ($odooError !== null) {
        // Odoo rejected — do NOT update local DB
        error_log("[slipMatchBdoProxy] Odoo rejected: slip_inbox_id={$slipInboxId}, error={$odooError}");
        return ['success' => false, 'error' => $odooError, 'error_type' => 'odoo_rejected'];
    }

    // Odoo confirmed — now update local cache
    _updateLocalSlipMatch($db, $slipInboxId, $matches, $note);

    return array_merge(
        ['success' => true, 'source' => 'odoo'],
        $odooResult['result']['data'] ?? []
    );
}

/**
 * Legacy local-only match: update odoo_slip_uploads directly when slip_inbox_id is not yet available.
 */
function _legacyLocalMatch($db, int $slipId, int $bdoId, string $note): array
{
    try {
        // Fetch BDO info for denormalized fields
        $bdoRow = null;
        try {
            $stmt = $db->prepare("SELECT bdo_name, partner_id FROM odoo_bdos WHERE bdo_id = ? LIMIT 1");
            $stmt->execute([$bdoId]);
            $bdoRow = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { /* table may not exist yet */ }

        $db->prepare("
            UPDATE odoo_slip_uploads
            SET status = 'matched',
                bdo_id = ?,
                bdo_name = ?,
                match_confidence = 'manual',
                match_reason = ?,
                matched_at = NOW()
            WHERE id = ?
        ")->execute([
            $bdoId,
            $bdoRow['bdo_name'] ?? null,
            $note ?: 'Manual match (legacy)',
            $slipId,
        ]);

        return ['success' => true, 'source' => 'local', 'message' => 'Matched locally (slip_inbox_id not yet available)'];
    } catch (Exception $e) {
        error_log("[_legacyLocalMatch] failed: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Proxy: POST /reya/slip/unmatch to Odoo
 *
 * IMPORTANT: Local DB is updated ONLY after Odoo confirms success.
 * If Odoo rejects (e.g. slip is posted), we return an error and do NOT reset local state.
 */
function slipUnmatchProxy($db, $input)
{
    $slipInboxId = (int) ($input['slip_inbox_id'] ?? 0);
    $lineUserId  = trim((string) ($input['line_user_id'] ?? ''));
    $reason      = trim((string) ($input['reason'] ?? ''));

    if (!$slipInboxId) {
        return ['success' => false, 'error' => 'Missing slip_inbox_id'];
    }

    $odooResult = _callOdooApi('/reya/slip/unmatch', [
        'line_user_id'  => $lineUserId,
        'slip_inbox_id' => $slipInboxId,
        'reason'        => $reason,
    ]);

    $odooError = _extractOdooError($odooResult);

    if ($odooError !== null) {
        error_log("[slipUnmatchProxy] Odoo rejected: slip_inbox_id={$slipInboxId}, error={$odooError}");
        return ['success' => false, 'error' => $odooError, 'error_type' => 'odoo_rejected'];
    }

    // Odoo confirmed — reset local cache
    try {
        $db->prepare("
            UPDATE odoo_slip_uploads
            SET status = 'new', bdo_id = NULL, match_reason = NULL,
                match_confidence = NULL, matched_at = NULL
            WHERE slip_inbox_id = ? OR odoo_slip_id = ? OR id = ?
        ")->execute([$slipInboxId, $slipInboxId, $slipInboxId]);
    } catch (Exception $e) {
        error_log("[slipUnmatchProxy] local reset failed: " . $e->getMessage());
    }

    return array_merge(
        ['success' => true, 'source' => 'odoo'],
        $odooResult['result']['data'] ?? []
    );
}

/**
 * Proxy: POST /reya/bdo/detail to Odoo
 * Falls back to local DB data if Odoo is unreachable.
 */
function bdoDetailProxy($db, $input)
{
    $bdoId      = (int) ($input['bdo_id'] ?? 0);
    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));

    if (!$bdoId) {
        return ['error' => 'Missing bdo_id'];
    }

    $odooResult = _callOdooApi('/reya/bdo/detail', [
        'line_user_id' => $lineUserId,
        'bdo_id' => $bdoId,
    ]);

    if ($odooResult && isset($odooResult['result']['success']) && $odooResult['result']['success']) {
        return $odooResult['result']['data'];
    }

    // Fallback: return local data
    try {
        $stmt = $db->prepare("SELECT * FROM odoo_bdos WHERE bdo_id = ? LIMIT 1");
        $stmt->execute([$bdoId]);
        $bdo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($bdo) {
            return ['bdo' => $bdo, 'source' => 'local', 'odoo_error' => $odooResult['error'] ?? 'unreachable'];
        }
    } catch (Exception $e) { /* ignore */ }

    return ['error' => 'BDO not found: ' . ($odooResult['error'] ?? 'local fallback also failed')];
}

/**
 * Internal: Extract error code from Odoo JSON-RPC response.
 * Returns null if the response indicates success, or an error string otherwise.
 */
function _extractOdooError(?array $odooResponse): ?string
{
    if ($odooResponse === null) {
        return 'NETWORK_ERROR';
    }
    if (isset($odooResponse['error'])) {
        return $odooResponse['error']['message'] ?? 'JSONRPC_ERROR';
    }
    $result = $odooResponse['result'] ?? null;
    if ($result === null) {
        return 'EMPTY_RESULT';
    }
    if (!empty($result['success'])) {
        return null;
    }
    return $result['error']['code'] ?? $result['error']['message'] ?? 'UNKNOWN_ERROR';
}

/**
 * Internal: Call Odoo JSON-RPC API
 */
function _callOdooApi($endpoint, $params)
{
    $odooUrl = defined('ODOO_API_BASE') ? ODOO_API_BASE : 'https://erp.cnyrxapp.com';
    $apiKey  = defined('ODOO_API_KEY') ? ODOO_API_KEY : '';

    if (!$apiKey) {
        return ['error' => 'ODOO_API_KEY not configured'];
    }

    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'call',
        'params' => $params,
    ]);

    $ch = curl_init($odooUrl . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Api-Key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => 'cURL error: ' . $error];
    }

    $decoded = json_decode($response, true);
    if (!$decoded) {
        return ['error' => 'Invalid JSON response (HTTP ' . $httpCode . ')'];
    }

    return $decoded;
}

/**
 * Internal: Update local slip record after matching
 */
function _updateLocalSlipMatch($db, $slipInboxId, $matches, $note)
{
    try {
        $bdoIds = array_map(function($m) { return (int) ($m['bdo_id'] ?? 0); }, $matches);
        $bdoId = $bdoIds[0] ?? null;

        $db->prepare("
            UPDATE odoo_slip_uploads
            SET status = 'matched', bdo_id = ?, match_reason = ?, matched_at = NOW()
            WHERE id = ? OR odoo_slip_id = ?
        ")->execute([$bdoId, $note ?: 'Matched via Dashboard', $slipInboxId, $slipInboxId]);

        // Update bdo_orders payment status if table exists
        if ($bdoId) {
            try {
                $tblChk = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
                if ($tblChk->rowCount() > 0) {
                    $db->prepare("
                        UPDATE odoo_bdo_orders
                        SET payment_status = 'matched', slip_upload_id = ?, updated_at = NOW()
                        WHERE bdo_id = ?
                    ")->execute([$slipInboxId, $bdoId]);
                }
            } catch (Exception $e2) { /* ignore */ }
        }

        return true;
    } catch (Exception $e) {
        error_log("[slipMatchBdoProxy] local update failed: " . $e->getMessage());
        return false;
    }
}
