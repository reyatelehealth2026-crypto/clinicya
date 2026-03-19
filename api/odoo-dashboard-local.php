<?php
/**
 * Odoo Dashboard Local API
 * Fast, local-only data access using denormalized cache tables
 * No external API calls - reads from local tables only
 * 
 * Actions:
 * - overview_kpi: Dashboard KPI stats (orders, revenue, customers)
 * - orders_list: Paginated orders from local cache
 * - customers_list: Paginated customers from local cache
 * - customer_detail: Single customer with orders/invoices
 * - invoices_list: Invoice list from local cache
 * - slips_list: Payment slips from local cache
 * - order_timeline: Order events history
 * - refresh_cache: Trigger cache refresh (admin only)
 * 
 * @version 1.0.0
 * @created 2026-03-11
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' 
        ? (json_decode(file_get_contents('php://input'), true) ?? [])
        : $_GET;
    
    $action = trim((string) ($input['action'] ?? ''));
    if ($action === '') {
        $action = 'health';
    }
    
    // Line account filtering
    $currentBotId = $_SESSION['current_bot_id'] ?? ($input['bot_id'] ?? null);
    
    switch ($action) {
        case 'health':
            $localTables = checkLocalTables($db);
            $hasData = false;
            foreach ($localTables as $tableInfo) {
                if (!empty($tableInfo['exists']) && !empty($tableInfo['count'])) {
                    $hasData = true;
                    break;
                }
            }
            $result = [
                'status' => 'ok',
                'service' => 'odoo-dashboard-local',
                'local_tables' => $localTables,
                'local_enabled' => $hasData,
                'has_data' => $hasData,
                'timestamp' => date('c')
            ];
            break;
            
        case 'overview_kpi':
            $result = getOverviewKpi($db, $currentBotId);
            break;
            
        case 'orders_list':
            $result = getOrdersList($db, $input, $currentBotId);
            break;
            
        case 'orders_today':
            $result = getOrdersToday($db, $currentBotId);
            break;
            
        case 'customers_list':
            $result = getCustomersList($db, $input, $currentBotId);
            break;
            
        case 'customer_detail':
            $result = getCustomerDetail($db, $input, $currentBotId);
            break;
            
        case 'invoices_list':
            $result = getInvoicesList($db, $input, $currentBotId);
            break;
            
        case 'invoices_overdue':
            $result = getInvoicesOverdue($db, $currentBotId);
            break;
            
        case 'slips_list':
            $result = getSlipsList($db, $input, $currentBotId);
            break;
            
        case 'slips_pending':
            $result = getSlipsPending($db, $currentBotId);
            break;
            
        case 'order_timeline':
            $result = getOrderTimelineLocal($db, $input);
            break;
            
        case 'search_global':
            $result = globalSearch($db, $input, $currentBotId);
            break;
            
        case 'cache_status':
            $result = getCacheStatus($db);
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ============================================
// LOCAL TABLE CHECK
// ============================================
function checkLocalTables($db) {
    $tables = [
        'odoo_orders_summary',
        'odoo_customers_cache',
        'odoo_invoices_cache',
        'odoo_slips_cache',
        'odoo_order_events'
    ];
    $result = [];
    foreach ($tables as $table) {
        $exists = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount() > 0;
        $count = 0;
        if ($exists) {
            $count = (int) $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        $result[$table] = ['exists' => $exists, 'count' => $count];
    }
    return $result;
}

// ============================================
// OVERVIEW KPI - Fast aggregation from local tables
// ============================================
function getOverviewKpi($db, $lineAccountId = null) {
    $kpi = [
        'orders' => ['today' => 0, 'yesterday' => 0, 'month' => 0, 'total' => 0],
        'revenue' => ['today' => 0, 'yesterday' => 0, 'month' => 0],
        'customers' => ['total' => 0, 'new_today' => 0, 'new_month' => 0, 'active_30d' => 0],
        'invoices' => ['open' => 0, 'overdue' => 0, 'paid_today' => 0, 'total_due' => 0],
        'slips' => ['pending' => 0, 'today' => 0, 'matched_today' => 0],
        'updated_at' => null
    ];
    
    // Orders KPI
    if (tableExists($db, 'odoo_orders_summary')) {
        $where = $lineAccountId ? "WHERE line_account_id = {$lineAccountId}" : "";
        
        $sql = "SELECT 
            SUM(CASE WHEN date_order = CURDATE() THEN 1 ELSE 0 END) as orders_today,
            SUM(CASE WHEN date_order = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as orders_yesterday,
            SUM(CASE WHEN YEAR(date_order) = YEAR(CURDATE()) AND MONTH(date_order) = MONTH(CURDATE()) THEN 1 ELSE 0 END) as orders_month,
            COUNT(*) as orders_total,
            SUM(CASE WHEN date_order = CURDATE() THEN amount_total ELSE 0 END) as revenue_today,
            SUM(CASE WHEN date_order = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN amount_total ELSE 0 END) as revenue_yesterday,
            SUM(CASE WHEN YEAR(date_order) = YEAR(CURDATE()) AND MONTH(date_order) = MONTH(CURDATE()) THEN amount_total ELSE 0 END) as revenue_month,
            MAX(updated_at) as last_updated
        FROM odoo_orders_summary {$where}";
        
        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $kpi['orders']['today'] = (int) ($row['orders_today'] ?? 0);
            $kpi['orders']['yesterday'] = (int) ($row['orders_yesterday'] ?? 0);
            $kpi['orders']['month'] = (int) ($row['orders_month'] ?? 0);
            $kpi['orders']['total'] = (int) ($row['orders_total'] ?? 0);
            $kpi['revenue']['today'] = (float) ($row['revenue_today'] ?? 0);
            $kpi['revenue']['yesterday'] = (float) ($row['revenue_yesterday'] ?? 0);
            $kpi['revenue']['month'] = (float) ($row['revenue_month'] ?? 0);
            $kpi['updated_at'] = $row['last_updated'];
        }
    }
    
    // Customers KPI
    if (tableExists($db, 'odoo_customers_cache')) {
        $where = $lineAccountId ? "WHERE line_account_id = {$lineAccountId}" : "";
        
        $sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as new_today,
            SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) as new_month,
            SUM(CASE WHEN latest_order_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as active_30d
        FROM odoo_customers_cache {$where}";
        
        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $kpi['customers']['total'] = (int) ($row['total'] ?? 0);
            $kpi['customers']['new_today'] = (int) ($row['new_today'] ?? 0);
            $kpi['customers']['new_month'] = (int) ($row['new_month'] ?? 0);
            $kpi['customers']['active_30d'] = (int) ($row['active_30d'] ?? 0);
        }
    }
    
    // Invoices KPI
    if (tableExists($db, 'odoo_invoices_cache')) {
        $where = $lineAccountId ? "WHERE line_account_id = {$lineAccountId}" : "";
        
        $sql = "SELECT 
            SUM(CASE WHEN state IN ('open', 'posted') AND is_overdue = 0 THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN is_overdue = 1 THEN 1 ELSE 0 END) as overdue_count,
            SUM(CASE WHEN state = 'paid' AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) as paid_today,
            SUM(CASE WHEN state IN ('open', 'posted', 'overdue') THEN amount_residual ELSE 0 END) as total_due
        FROM odoo_invoices_cache {$where}";
        
        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $kpi['invoices']['open'] = (int) ($row['open_count'] ?? 0);
            $kpi['invoices']['overdue'] = (int) ($row['overdue_count'] ?? 0);
            $kpi['invoices']['paid_today'] = (int) ($row['paid_today'] ?? 0);
            $kpi['invoices']['total_due'] = (float) ($row['total_due'] ?? 0);
        }
    }
    
    // Slips KPI
    if (tableExists($db, 'odoo_slips_cache')) {
        $where = $lineAccountId ? "WHERE line_account_id = {$lineAccountId}" : "";
        
        $sql = "SELECT 
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN DATE(payment_date) = CURDATE() THEN 1 ELSE 0 END) as today_count,
            SUM(CASE WHEN status = 'matched' AND DATE(matched_at) = CURDATE() THEN 1 ELSE 0 END) as matched_today
        FROM odoo_slips_cache {$where}";
        
        $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $kpi['slips']['pending'] = (int) ($row['pending'] ?? 0);
            $kpi['slips']['today'] = (int) ($row['today_count'] ?? 0);
            $kpi['slips']['matched_today'] = (int) ($row['matched_today'] ?? 0);
        }
    }
    
    return $kpi;
}

// ============================================
// ORDERS LIST - From local cache only
// ============================================
function getOrdersList($db, $input, $lineAccountId = null) {
    if (!tableExists($db, 'odoo_orders_summary')) {
        return ['orders' => [], 'total' => 0, 'source' => 'none'];
    }
    
    $limit = min((int) ($input['limit'] ?? 30), 100);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $search = trim((string) ($input['search'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $dateFrom = trim((string) ($input['date_from'] ?? ''));
    $dateTo = trim((string) ($input['date_to'] ?? ''));
    $customerId = trim((string) ($input['customer_id'] ?? ''));
    $sortBy = trim((string) ($input['sort_by'] ?? 'date_desc'));
    
    $where = [];
    $params = [];
    
    if ($lineAccountId) {
        $where[] = "line_account_id = ?";
        $params[] = $lineAccountId;
    }
    
    if ($search !== '') {
        $where[] = "(order_key LIKE ? OR customer_name LIKE ? OR customer_ref LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }
    
    if ($state !== '') {
        $where[] = "state = ?";
        $params[] = $state;
    }
    
    if ($dateFrom !== '') {
        $where[] = "date_order >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo !== '') {
        $where[] = "date_order <= ?";
        $params[] = $dateTo;
    }
    
    if ($customerId !== '') {
        $where[] = "(customer_id = ? OR partner_id = ?)";
        $params[] = $customerId;
        $params[] = $customerId;
    }
    
    $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count
    $countSql = "SELECT COUNT(*) FROM odoo_orders_summary {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    
    // Sort mapping
    $sortMap = [
        'date_desc' => 'date_order DESC, last_event_at DESC',
        'date_asc' => 'date_order ASC, last_event_at ASC',
        'amount_desc' => 'amount_total DESC',
        'amount_asc' => 'amount_total ASC',
        'customer_asc' => 'customer_name ASC'
    ];
    $orderBy = $sortMap[$sortBy] ?? $sortMap['date_desc'];
    
    // Data
    $sql = "SELECT 
        order_key, order_id, odoo_order_id,
        customer_id, customer_name, customer_ref, partner_id,
        salesperson_name,
        amount_total, amount_tax, currency,
        state, state_display, delivery_type,
        invoice_status, payment_status,
        line_user_id,
        first_event_at, last_event_at, date_order, expected_delivery_date,
        created_at, updated_at
    FROM odoo_orders_summary
    {$whereClause}
    ORDER BY {$orderBy}
    LIMIT {$limit} OFFSET {$offset}";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'orders' => $orders,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'source' => 'local_cache'
    ];
}

// ============================================
// ORDERS TODAY - Quick view for dashboard
// ============================================
function getOrdersToday($db, $lineAccountId = null) {
    if (!tableExists($db, 'odoo_orders_summary')) {
        return ['orders' => [], 'total' => 0, 'count' => 0, 'total_amount' => 0];
    }
    
    $where = "WHERE date_order = CURDATE()";
    $params = [];
    
    if ($lineAccountId) {
        $where .= " AND line_account_id = ?";
        $params[] = $lineAccountId;
    }
    
    // Summary stats
    $statsSql = "SELECT 
        COUNT(*) as count,
        COALESCE(SUM(amount_total), 0) as total_amount,
        COUNT(DISTINCT customer_id) as unique_customers
    FROM odoo_orders_summary {$where}";
    
    $stats = $db->prepare($statsSql);
    $stats->execute($params);
    $summary = $stats->fetch(PDO::FETCH_ASSOC);
    
    // Recent orders (top 10)
    $sql = "SELECT 
        order_key, customer_name, amount_total, state_display, line_user_id,
        last_event_at
    FROM odoo_orders_summary
    {$where}
    ORDER BY last_event_at DESC
    LIMIT 10";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'count' => (int) ($summary['count'] ?? 0),
        'total_amount' => (float) ($summary['total_amount'] ?? 0),
        'unique_customers' => (int) ($summary['unique_customers'] ?? 0),
        'orders' => $orders
    ];
}

// ============================================
// CUSTOMERS LIST - From local cache
// ============================================
function getCustomersList($db, $input, $lineAccountId = null) {
    if (!tableExists($db, 'odoo_customers_cache')) {
        return ['customers' => [], 'total' => 0, 'source' => 'none'];
    }
    
    $limit = min((int) ($input['limit'] ?? 30), 100);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $search = trim((string) ($input['search'] ?? ''));
    $invoiceFilter = trim((string) ($input['invoice_filter'] ?? ''));
    $sortBy = trim((string) ($input['sort_by'] ?? 'latest_order'));
    $salespersonId = trim((string) ($input['salesperson_id'] ?? ''));
    
    $where = [];
    $params = [];
    
    if ($lineAccountId) {
        $where[] = "line_account_id = ?";
        $params[] = $lineAccountId;
    }
    
    if ($search !== '') {
        $where[] = "(customer_name LIKE ? OR customer_ref LIKE ? OR phone LIKE ? OR email LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }
    
    if ($invoiceFilter === 'unpaid') {
        $where[] = "total_due > 0";
    } elseif ($invoiceFilter === 'overdue') {
        $where[] = "overdue_amount > 0";
    }
    
    if ($salespersonId !== '') {
        $where[] = "salesperson_id = ?";
        $params[] = $salespersonId;
    }
    
    $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count
    $countSql = "SELECT COUNT(*) FROM odoo_customers_cache {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    
    // Sort mapping
    $sortMap = [
        'latest_order' => 'latest_order_at DESC',
        'spend_desc' => 'spend_30d DESC, latest_order_at DESC',
        'spend_asc' => 'spend_30d ASC',
        'orders_desc' => 'orders_count_total DESC',
        'name_asc' => 'customer_name ASC',
        'due_desc' => 'overdue_amount DESC, total_due DESC',
        'credit_desc' => 'credit_remaining DESC'
    ];
    $orderBy = $sortMap[$sortBy] ?? $sortMap['latest_order'];
    
    // Data
    $bdoJoin = tableExists($db, 'odoo_bdos')
        ? "LEFT JOIN (SELECT partner_id, COUNT(*) AS waiting_bdo_count FROM odoo_bdos WHERE state='waiting' GROUP BY partner_id) bdo_cnt ON bdo_cnt.partner_id = COALESCE(c.partner_id, c.customer_id)"
        : '';

    $sql = "SELECT 
        c.customer_id, c.partner_id, c.customer_name, c.customer_ref,
        c.salesperson_id,
        c.phone, c.email, c.city, c.state,
        c.line_user_id, c.line_display_name,
        c.salesperson_name,
        c.credit_limit, c.total_due, c.overdue_amount, c.trust_level,
        c.orders_count_total, c.orders_count_30d, c.spend_total, c.spend_30d,
        c.first_order_at, c.latest_order_at, c.last_invoice_at, c.last_payment_at,
        c.synced_at,
        COALESCE(bdo_cnt.waiting_bdo_count, 0) as waiting_bdo_count
    FROM odoo_customers_cache c
    {$bdoJoin}
    {$whereClause}
    ORDER BY {$orderBy}
    LIMIT {$limit} OFFSET {$offset}";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'customers' => $customers,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'source' => 'local_cache'
    ];
}

// ============================================
// CUSTOMER DETAIL - Full customer 360 view
// ============================================
function getCustomerDetail($db, $input, $lineAccountId = null) {
    $customerId = trim((string) ($input['customer_id'] ?? ''));
    $partnerId = trim((string) ($input['partner_id'] ?? ''));
    $customerRef = trim((string) ($input['customer_ref'] ?? ''));
    
    if ($customerId === '' && $partnerId === '' && $customerRef === '') {
        throw new Exception('Missing customer_id, partner_id or customer_ref');
    }
    
    $result = [
        'profile' => null,
        'orders' => [],
        'invoices' => [],
        'activity' => [],
        'stats' => [
            'orders_count' => 0,
            'total_spend' => 0,
            'avg_order_value' => 0,
            'invoices_open' => 0,
            'invoices_overdue' => 0
        ]
    ];
    
    // Profile
    $where = [];
    $params = [];
    if ($customerId !== '') {
        $where[] = "customer_id = ?";
        $params[] = $customerId;
    }
    if ($partnerId !== '') {
        $where[] = "partner_id = ?";
        $params[] = $partnerId;
    }
    if ($customerRef !== '') {
        $where[] = "customer_ref = ?";
        $params[] = $customerRef;
    }
    $whereClause = 'WHERE ' . implode(' OR ', $where);
    if ($lineAccountId) {
        $whereClause .= " AND line_account_id = ?";
        $params[] = $lineAccountId;
    }
    
    if (tableExists($db, 'odoo_customers_cache')) {
        $sql = "SELECT * FROM odoo_customers_cache {$whereClause} LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result['profile'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['profile']) {
            $custKey = $result['profile']['customer_id'] ?? $result['profile']['partner_id'];
            
            // Orders
            if (tableExists($db, 'odoo_orders_summary')) {
                $orderSql = "SELECT 
                    order_key, amount_total, state_display, date_order, delivery_type
                FROM odoo_orders_summary 
                WHERE (customer_id = ? OR partner_id = ?)
                " . ($lineAccountId ? " AND line_account_id = ?" : "") . "
                ORDER BY date_order DESC
                LIMIT 20";
                $orderParams = [$custKey, $custKey];
                if ($lineAccountId) $orderParams[] = $lineAccountId;
                
                $orderStmt = $db->prepare($orderSql);
                $orderStmt->execute($orderParams);
                $result['orders'] = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Order stats
                $statsSql = "SELECT 
                    COUNT(*) as count,
                    COALESCE(SUM(amount_total), 0) as total
                FROM odoo_orders_summary 
                WHERE (customer_id = ? OR partner_id = ?)
                " . ($lineAccountId ? " AND line_account_id = ?" : "");
                $statsStmt = $db->prepare($statsSql);
                $statsStmt->execute($orderParams);
                $orderStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
                
                $result['stats']['orders_count'] = (int) ($orderStats['count'] ?? 0);
                $result['stats']['total_spend'] = (float) ($orderStats['total'] ?? 0);
                if ($result['stats']['orders_count'] > 0) {
                    $result['stats']['avg_order_value'] = round($result['stats']['total_spend'] / $result['stats']['orders_count'], 2);
                }
            }
            
            // Invoices
            if (tableExists($db, 'odoo_invoices_cache')) {
                $invSql = "SELECT 
                    invoice_number, amount_total, amount_residual, state, 
                    invoice_date, due_date, is_overdue, days_overdue
                FROM odoo_invoices_cache 
                WHERE (customer_id = ? OR partner_id = ?)
                " . ($lineAccountId ? " AND line_account_id = ?" : "") . "
                ORDER BY invoice_date DESC
                LIMIT 20";
                $invParams = [$custKey, $custKey];
                if ($lineAccountId) $invParams[] = $lineAccountId;
                
                $invStmt = $db->prepare($invSql);
                $invStmt->execute($invParams);
                $result['invoices'] = $invStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Invoice stats
                $invStatsSql = "SELECT 
                    SUM(CASE WHEN state IN ('open', 'posted') AND is_overdue = 0 THEN 1 ELSE 0 END) as open_count,
                    SUM(CASE WHEN is_overdue = 1 THEN 1 ELSE 0 END) as overdue_count
                FROM odoo_invoices_cache 
                WHERE (customer_id = ? OR partner_id = ?)
                " . ($lineAccountId ? " AND line_account_id = ?" : "");
                $invStatsStmt = $db->prepare($invStatsSql);
                $invStatsStmt->execute($invParams);
                $invStats = $invStatsStmt->fetch(PDO::FETCH_ASSOC);
                
                $result['stats']['invoices_open'] = (int) ($invStats['open_count'] ?? 0);
                $result['stats']['invoices_overdue'] = (int) ($invStats['overdue_count'] ?? 0);
            }
            
            // Activity/Events
            if (tableExists($db, 'odoo_order_events')) {
                $actSql = "SELECT 
                    oe.*
                FROM odoo_order_events oe
                INNER JOIN odoo_orders_summary oos ON oe.order_key = oos.order_key
                WHERE (oos.customer_id = ? OR oos.partner_id = ?)
                " . ($lineAccountId ? " AND oos.line_account_id = ?" : "") . "
                ORDER BY oe.processed_at DESC
                LIMIT 30";
                $actParams = [$custKey, $custKey];
                if ($lineAccountId) $actParams[] = $lineAccountId;
                
                $actStmt = $db->prepare($actSql);
                $actStmt->execute($actParams);
                $result['activity'] = $actStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
    
    return $result;
}

// ============================================
// INVOICES LIST - From local cache
// ============================================
function getInvoicesList($db, $input, $lineAccountId = null) {
    if (!tableExists($db, 'odoo_invoices_cache')) {
        return ['invoices' => [], 'total' => 0, 'source' => 'none'];
    }
    
    $limit = min((int) ($input['limit'] ?? 30), 100);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $search = trim((string) ($input['search'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $customerId = trim((string) ($input['customer_id'] ?? ''));
    $isOverdue = isset($input['is_overdue']) ? (int) $input['is_overdue'] : null;
    
    $where = [];
    $params = [];
    
    if ($lineAccountId) {
        $where[] = "line_account_id = ?";
        $params[] = $lineAccountId;
    }
    
    if ($search !== '') {
        $where[] = "(invoice_number LIKE ? OR customer_name LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
    }
    
    if ($state !== '') {
        $where[] = "state = ?";
        $params[] = $state;
    }
    
    if ($customerId !== '') {
        $where[] = "(customer_id = ? OR partner_id = ?)";
        $params[] = $customerId;
        $params[] = $customerId;
    }
    
    if ($isOverdue !== null) {
        $where[] = "is_overdue = ?";
        $params[] = $isOverdue;
    }
    
    $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count
    $countSql = "SELECT COUNT(*) FROM odoo_invoices_cache {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    
    // Data
    $sql = "SELECT 
        invoice_number, invoice_id, order_key,
        customer_name, customer_id, partner_id,
        amount_total, amount_residual, amount_paid,
        state, invoice_date, due_date,
        is_overdue, days_overdue,
        payment_state, line_user_id,
        synced_at
    FROM odoo_invoices_cache
    {$whereClause}
    ORDER BY is_overdue DESC, due_date ASC
    LIMIT {$limit} OFFSET {$offset}";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'invoices' => $invoices,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'source' => 'local_cache'
    ];
}

// ============================================
// INVOICES OVERDUE - Quick list
// ============================================
function getInvoicesOverdue($db, $lineAccountId = null) {
    if (!tableExists($db, 'odoo_invoices_cache')) {
        return ['invoices' => [], 'total' => 0, 'total_amount' => 0];
    }
    
    $where = "WHERE is_overdue = 1";
    $params = [];
    
    if ($lineAccountId) {
        $where .= " AND line_account_id = ?";
        $params[] = $lineAccountId;
    }
    
    // Stats
    $statsSql = "SELECT 
        COUNT(*) as count,
        COALESCE(SUM(amount_residual), 0) as total_amount
    FROM odoo_invoices_cache {$where}";
    $stats = $db->prepare($statsSql);
    $stats->execute($params);
    $summary = $stats->fetch(PDO::FETCH_ASSOC);
    
    // Top overdue
    $sql = "SELECT 
        invoice_number, customer_name, amount_residual, days_overdue, due_date
    FROM odoo_invoices_cache
    {$where}
    ORDER BY days_overdue DESC, amount_residual DESC
    LIMIT 20";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'count' => (int) ($summary['count'] ?? 0),
        'total_amount' => (float) ($summary['total_amount'] ?? 0),
        'invoices' => $invoices
    ];
}

// ============================================
// SLIPS LIST - From local cache
// ============================================
function getSlipsList($db, $input, $lineAccountId = null) {
    if (!tableExists($db, 'odoo_slips_cache')) {
        return ['slips' => [], 'total' => 0, 'source' => 'none'];
    }
    
    $limit = min((int) ($input['limit'] ?? 30), 100);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $search = trim((string) ($input['search'] ?? ''));
    $status = trim((string) ($input['status'] ?? ''));
    $date = trim((string) ($input['date'] ?? ''));
    
    $where = [];
    $params = [];
    
    if ($lineAccountId) {
        $where[] = "line_account_id = ?";
        $params[] = $lineAccountId;
    }
    
    if ($search !== '') {
        $where[] = "(slip_id LIKE ? OR customer_name LIKE ? OR order_key LIKE ?)";
        $s = "%{$search}%";
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }
    
    if ($status !== '') {
        $where[] = "status = ?";
        $params[] = $status;
    }

    if ($date !== '') {
        $where[] = "(payment_date = ? OR DATE(created_at) = ?)";
        $params[] = $date;
        $params[] = $date;
    }
    
    $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Count
    $countSql = "SELECT COUNT(*) FROM odoo_slips_cache {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    
    // Data
    $sql = "SELECT 
        slip_id,
        slip_id AS id,
        order_key,
        order_id,
        invoice_id,
        bdo_id,
        odoo_slip_id,
        slip_inbox_id,
        customer_name,
        amount,
        matched_amount,
        payment_date,
        payment_date AS transfer_date,
        payment_method,
        status,
        confidence,
        match_reason,
        matched_at,
        matched_by,
        uploaded_by,
        image_path,
        image_url,
        created_at AS uploaded_at,
        line_user_id,
        line_account_id
    FROM odoo_slips_cache
    {$whereClause}
    ORDER BY payment_date DESC, created_at DESC
    LIMIT {$limit} OFFSET {$offset}";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : '[REDACTED]', '/');
    foreach ($slips as &$slip) {
        $slip['id'] = (int) ($slip['id'] ?? 0);
        $slip['slip_id'] = (int) ($slip['slip_id'] ?? 0);
        $slip['slip_inbox_id'] = $slip['slip_inbox_id'] !== null ? (int) $slip['slip_inbox_id'] : null;
        $slip['odoo_slip_id'] = $slip['odoo_slip_id'] !== null ? (int) $slip['odoo_slip_id'] : null;
        $slip['bdo_id'] = $slip['bdo_id'] !== null ? (int) $slip['bdo_id'] : null;
        $slip['amount'] = $slip['amount'] !== null ? (float) $slip['amount'] : null;
        $slip['matched_amount'] = $slip['matched_amount'] !== null ? (float) $slip['matched_amount'] : null;
        if (!empty($slip['image_path'])) {
            $slip['image_full_url'] = $baseUrl . '/' . ltrim($slip['image_path'], '/');
        } else {
            $slip['image_full_url'] = $slip['image_url'] ?: null;
        }
    }
    unset($slip);
    
    return [
        'slips' => $slips,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'source' => 'local_cache'
    ];
}

// ============================================
// SLIPS PENDING - Quick list
// ============================================
function getSlipsPending($db, $lineAccountId = null) {
    if (!tableExists($db, 'odoo_slips_cache')) {
        return ['slips' => [], 'count' => 0, 'total_amount' => 0];
    }
    
    $where = "WHERE status = 'pending'";
    $params = [];
    
    if ($lineAccountId) {
        $where .= " AND line_account_id = ?";
        $params[] = $lineAccountId;
    }
    
    // Stats
    $statsSql = "SELECT 
        COUNT(*) as count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM odoo_slips_cache {$where}";
    $stats = $db->prepare($statsSql);
    $stats->execute($params);
    $summary = $stats->fetch(PDO::FETCH_ASSOC);
    
    // Top pending
    $sql = "SELECT 
        slip_id, customer_name, amount, payment_date, order_key, bdo_id, image_path, image_url
    FROM odoo_slips_cache
    {$where}
    ORDER BY payment_date DESC
    LIMIT 20";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : '[REDACTED]', '/');
    foreach ($slips as &$slip) {
        if (!empty($slip['image_path'])) {
            $slip['image_full_url'] = $baseUrl . '/' . ltrim($slip['image_path'], '/');
        } else {
            $slip['image_full_url'] = $slip['image_url'] ?: null;
        }
    }
    unset($slip);
    
    return [
        'count' => (int) ($summary['count'] ?? 0),
        'total_amount' => (float) ($summary['total_amount'] ?? 0),
        'slips' => $slips
    ];
}

// ============================================
// ORDER TIMELINE - From local events table
// ============================================
function getOrderTimelineLocal($db, $input) {
    $orderKey = trim((string) ($input['order_key'] ?? $input['order_name'] ?? ''));
    $orderId = trim((string) ($input['order_id'] ?? ''));
    
    if ($orderKey === '' && $orderId === '') {
        throw new Exception('Missing order_key or order_id');
    }
    
    // First find order_key if only order_id provided
    if ($orderKey === '' && $orderId !== '' && tableExists($db, 'odoo_orders_summary')) {
        $findSql = "SELECT order_key FROM odoo_orders_summary WHERE order_key = ? OR odoo_order_id = ? LIMIT 1";
        $findStmt = $db->prepare($findSql);
        $findStmt->execute([$orderId, $orderId]);
        $found = $findStmt->fetchColumn();
        if ($found) {
            $orderKey = $found;
        }
    }
    
    $result = [
        'order_key' => $orderKey,
        'events' => [],
        'order_info' => null
    ];
    
    // Order info
    if (tableExists($db, 'odoo_orders_summary')) {
        $infoSql = "SELECT * FROM odoo_orders_summary WHERE order_key = ? LIMIT 1";
        $infoStmt = $db->prepare($infoSql);
        $infoStmt->execute([$orderKey]);
        $result['order_info'] = $infoStmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Events from local table
    if (tableExists($db, 'odoo_order_events')) {
        $sql = "SELECT * FROM odoo_order_events WHERE order_key = ? ORDER BY processed_at ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$orderKey]);
        $result['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Fallback to webhook log if no events in local table
    if (empty($result['events'])) {
        $processedAtColumn = resolveWebhookTimeColumn($db);
        $processedAtExpr = $processedAtColumn ?: '`id`';
        
        $sql = "SELECT 
            id, event_type, status, {$processedAtExpr} as processed_at,
            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')) as new_state_display,
            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.old_state_display')) as old_state_display,
            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')) as amount_total
        FROM odoo_webhooks_log
        WHERE order_id = ? OR JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) = ?
        ORDER BY {$processedAtExpr} ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$orderId, $orderKey]);
        $result['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['source'] = 'webhook_fallback';
    } else {
        $result['source'] = 'local_cache';
    }

    if (!empty($result['order_info']['order_key']) && empty($result['order_name'])) {
        $result['order_name'] = $result['order_info']['order_key'];
    } elseif ($orderKey !== '') {
        $result['order_name'] = $orderKey;
    }
    
    return $result;
}

// ============================================
// GLOBAL SEARCH - Across all local tables
// ============================================
function globalSearch($db, $input, $lineAccountId = null) {
    $query = trim((string) ($input['q'] ?? $input['query'] ?? ''));
    if ($query === '' || strlen($query) < 2) {
        return ['results' => [], 'total' => 0];
    }
    
    $results = [];
    $limit = min((int) ($input['limit'] ?? 10), 20);
    $s = "%{$query}%";
    $params = [$s, $s];
    
    // Search orders
    if (tableExists($db, 'odoo_orders_summary')) {
        $orderSql = "SELECT 
            'order' as type,
            order_key as title,
            CONCAT(customer_name, ' - ฿', FORMAT(amount_total, 2)) as subtitle,
            order_key as id,
            state_display as status,
            date_order as date
        FROM odoo_orders_summary
        WHERE (order_key LIKE ? OR customer_name LIKE ?)";
        
        if ($lineAccountId) {
            $orderSql .= " AND line_account_id = ?";
            $params[] = $lineAccountId;
        }
        $orderSql .= " ORDER BY date_order DESC LIMIT {$limit}";
        
        $stmt = $db->prepare($orderSql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results = array_merge($results, $orders);
    }
    
    // Search customers
    if (tableExists($db, 'odoo_customers_cache')) {
        $custParams = [$s, $s, $s];
        $custSql = "SELECT 
            'customer' as type,
            customer_name as title,
            CONCAT(IFNULL(customer_ref, '-'), ' | ', IFNULL(phone, 'no phone')) as subtitle,
            customer_id as id,
            CONCAT('Orders: ', orders_count_total, ' | Spend: ฿', FORMAT(spend_total, 0)) as status,
            latest_order_at as date
        FROM odoo_customers_cache
        WHERE (customer_name LIKE ? OR customer_ref LIKE ? OR phone LIKE ?)";
        
        if ($lineAccountId) {
            $custSql .= " AND line_account_id = ?";
            $custParams[] = $lineAccountId;
        }
        $custSql .= " ORDER BY latest_order_at DESC LIMIT {$limit}";
        
        $stmt = $db->prepare($custSql);
        $stmt->execute($custParams);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results = array_merge($results, $customers);
    }
    
    // Sort by date
    usort($results, function($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });
    
    return [
        'query' => $query,
        'results' => array_slice($results, 0, $limit),
        'total' => count($results)
    ];
}

// ============================================
// CACHE STATUS - For monitoring
// ============================================
function getCacheStatus($db) {
    $status = [];
    
    $tables = [
        'odoo_orders_summary' => 'Orders',
        'odoo_customers_cache' => 'Customers',
        'odoo_invoices_cache' => 'Invoices',
        'odoo_slips_cache' => 'Slips',
        'odoo_order_events' => 'Events'
    ];
    
    foreach ($tables as $table => $label) {
        if (tableExists($db, $table)) {
            $row = $db->query("SELECT 
                COUNT(*) as count,
                MAX(updated_at) as last_update,
                MIN(created_at) as first_record
            FROM {$table}")->fetch(PDO::FETCH_ASSOC);
            
            $status[$table] = [
                'label' => $label,
                'exists' => true,
                'record_count' => (int) ($row['count'] ?? 0),
                'last_update' => $row['last_update'],
                'first_record' => $row['first_record']
            ];
        } else {
            $status[$table] = ['label' => $label, 'exists' => false];
        }
    }
    
    // Sync log
    if (tableExists($db, 'odoo_sync_log')) {
        $latest = $db->query("SELECT * FROM odoo_sync_log ORDER BY started_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $status['recent_syncs'] = $latest;
    }
    
    return $status;
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function tableExists($db, $table) {
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function resolveWebhookTimeColumn($db) {
    try {
        $cols = $db->query("SHOW COLUMNS FROM odoo_webhooks_log LIKE 'processed_at'")->fetchAll();
        if (!empty($cols)) return 'processed_at';
        $cols = $db->query("SHOW COLUMNS FROM odoo_webhooks_log LIKE 'created_at'")->fetchAll();
        if (!empty($cols)) return 'created_at';
    } catch (Exception $e) {
        // ignore
    }
    return null;
}
