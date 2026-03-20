<?php
/**
 * Users Management - รายชื่อผู้ใช้/ลูกค้า
 * แสดงทุก follow = 1 customer
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Users';

require_once 'includes/header.php';

// Add glassmorphism styles
?>
<link rel="stylesheet" href="assets/css/design-tokens.css">
<link rel="stylesheet" href="assets/css/glassmorphism.css">
<link rel="stylesheet" href="assets/css/components.css">
<style>
.users-page {
    background: radial-gradient(at 40% 20%, rgba(99, 102, 241, 0.08) 0px, transparent 50%),
                radial-gradient(at 80% 0%, rgba(139, 92, 246, 0.05) 0px, transparent 50%),
                linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    min-height: 100vh;
    padding: 24px;
}
.users-card {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(20px) saturate(180%);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    padding: 24px;
    margin-bottom: 20px;
}
.users-title {
    color: white;
    font-size: 24px;
    font-weight: 700;
}
.users-subtitle {
    color: rgba(255, 255, 255, 0.5);
    font-size: 14px;
}
.tab-btn-glass {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 12px;
    font-weight: 500;
    transition: all 0.2s ease;
    text-decoration: none;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.tab-btn-glass.active {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border-color: transparent;
}
.tab-btn-glass:not(.active) {
    background: rgba(255, 255, 255, 0.05);
    color: rgba(255, 255, 255, 0.7);
}
.tab-btn-glass:not(.active):hover {
    background: rgba(255, 255, 255, 0.1);
}
.stat-card-glass {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    padding: 20px;
}
.stat-card-glass .stat-label {
    color: rgba(255, 255, 255, 0.5);
    font-size: 13px;
    margin-bottom: 8px;
}
.stat-card-glass .stat-value {
    color: white;
    font-size: 24px;
    font-weight: 700;
}
.stat-card-glass .stat-sub {
    color: rgba(255, 255, 255, 0.4);
    font-size: 12px;
    margin-top: 4px;
}
.table-glass {
    width: 100%;
    border-collapse: collapse;
}
.table-glass thead {
    background: rgba(255, 255, 255, 0.05);
}
.table-glass th {
    padding: 16px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: rgba(255, 255, 255, 0.4);
}
.table-glass td {
    padding: 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    color: rgba(255, 255, 255, 0.8);
}
.table-glass tr:hover {
    background: rgba(255, 255, 255, 0.03);
}
.btn-glass-primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    padding: 10px 20px;
    border-radius: 12px;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
}
.btn-glass-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
}
.input-glass {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 12px 16px;
    color: white;
    width: 100%;
    transition: all 0.2s ease;
}
.input-glass:focus {
    outline: none;
    border-color: #6366f1;
    background: rgba(255, 255, 255, 0.08);
}
.input-glass::placeholder {
    color: rgba(255, 255, 255, 0.3);
}
.select-glass {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 12px 16px;
    color: white;
    cursor: pointer;
}
.pagination-glass a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    transition: all 0.2s ease;
}
.pagination-glass a:hover {
    background: rgba(255, 255, 255, 0.1);
}
.pagination-glass span.current {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 12px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border-radius: 10px;
    color: white;
}
</style>
<?php

// Get filter parameters
$tagFilter = isset($_GET['tag']) ? (int) $_GET['tag'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$activeTab = $_GET['tab'] ?? 'line';
if (!in_array($activeTab, ['line', 'odoo'], true)) {
    $activeTab = 'line';
}

$odooSearch = isset($_GET['odoo_search']) ? trim($_GET['odoo_search']) : '';
$odooPage = isset($_GET['odoo_page']) ? max(1, (int) $_GET['odoo_page']) : 1;
$odooPerPage = 20;
$odooOffset = ($odooPage - 1) * $odooPerPage;
$odooUnlinkedPage = isset($_GET['odoo_unlinked_page']) ? max(1, (int) $_GET['odoo_unlinked_page']) : 1;
$odooUnlinkedPerPage = 20;
$odooUnlinkedOffset = ($odooUnlinkedPage - 1) * $odooUnlinkedPerPage;

// Advanced filters
$tierFilter = isset($_GET['tier']) ? trim($_GET['tier']) : '';
$pointsFilter = isset($_GET['points']) ? trim($_GET['points']) : '';
$activityFilter = isset($_GET['activity']) ? trim($_GET['activity']) : '';
$purchaseFilter = isset($_GET['purchase']) ? trim($_GET['purchase']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Get tag info if filtering
$currentTag = null;
// Check if user_tags tables exist
$hasTagTables = false;
try {
    $checkStmt = $db->query("SHOW TABLES LIKE 'user_tags'");
    $hasTagTables = $checkStmt->fetch() !== false;
} catch (Exception $e) {
    $hasTagTables = false;
}

if ($tagFilter && $hasTagTables) {
    try {
        $stmt = $db->prepare("SELECT * FROM user_tags WHERE id = ?");
        $stmt->execute([$tagFilter]);
        $currentTag = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $tagFilter = null; // Reset filter if table doesn't exist
    }
}

// Build query
$whereConditions = ["1=1"];
$params = [];

if ($tagFilter && $hasTagTables) {
    $whereConditions[] = "EXISTS (SELECT 1 FROM user_tag_assignments uta WHERE uta.user_id = u.id AND uta.tag_id = ?)";
    $params[] = $tagFilter;
}

if ($search) {
    $whereConditions[] = "(u.display_name LIKE ? OR u.line_user_id LIKE ? OR u.real_name LIKE ? OR u.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

// Tier filter
if ($tierFilter) {
    $whereConditions[] = "u.id IN (SELECT user_id FROM loyalty_points WHERE tier = ?)";
    $params[] = $tierFilter;
}

// Points filter
if ($pointsFilter) {
    switch ($pointsFilter) {
        case '0-100':
            $whereConditions[] = "COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 0 AND 100";
            break;
        case '100-500':
            $whereConditions[] = "COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 100 AND 500";
            break;
        case '500-1000':
            $whereConditions[] = "COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) BETWEEN 500 AND 1000";
            break;
        case '1000+':
            $whereConditions[] = "COALESCE((SELECT points FROM loyalty_points WHERE user_id = u.id LIMIT 1), 0) > 1000";
            break;
    }
}

// Activity filter
if ($activityFilter) {
    switch ($activityFilter) {
        case 'today':
            $whereConditions[] = "DATE(u.updated_at) = CURDATE()";
            break;
        case '7days':
            $whereConditions[] = "u.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case '30days':
            $whereConditions[] = "u.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'inactive':
            $whereConditions[] = "u.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
    }
}

// Purchase filter
if ($purchaseFilter) {
    switch ($purchaseFilter) {
        case 'purchased':
            $whereConditions[] = "EXISTS (SELECT 1 FROM orders WHERE user_id = u.id AND status != 'cancelled')";
            break;
        case 'never':
            $whereConditions[] = "NOT EXISTS (SELECT 1 FROM orders WHERE user_id = u.id)";
            break;
        case '1000+':
            $whereConditions[] = "(SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') >= 1000";
            break;
        case '5000+':
            $whereConditions[] = "(SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = u.id AND status = 'completed') >= 5000";
            break;
    }
}

// Status filter
if ($statusFilter) {
    switch ($statusFilter) {
        case 'active':
            $whereConditions[] = "u.is_blocked = 0";
            break;
        case 'blocked':
            $whereConditions[] = "u.is_blocked = 1";
            break;
    }
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
try {
    $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereClause}";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalUsers = $stmt->fetchColumn();
} catch (Exception $e) {
    $totalUsers = 0;
}
$totalPages = ceil($totalUsers / $perPage);

// Get users
$hasExtraCols = false;
$hasLineAccountId = false;
try {
    // Check if extra columns exist
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM users LIKE 'real_name'");
        $hasExtraCols = $checkStmt->fetch() !== false;
    } catch (Exception $e) {
        $hasExtraCols = false;
    }

    // Check if line_account_id column exists
    try {
        $checkStmt = $db->query("SHOW COLUMNS FROM users LIKE 'line_account_id'");
        $hasLineAccountId = $checkStmt->fetch() !== false;
    } catch (Exception $e) {
        $hasLineAccountId = false;
    }

    // Build SELECT clause based on available columns
    $selectCols = "u.id, u.line_user_id, u.display_name, u.picture_url, u.status_message, u.is_blocked, u.created_at, u.updated_at";
    if ($hasLineAccountId) {
        $selectCols .= ", u.line_account_id";
    }
    if ($hasExtraCols) {
        $selectCols .= ", u.real_name, u.phone, u.email, u.birthday";
    }

    // Check if user_tags table exists
    $hasUserTags = false;
    try {
        $checkStmt = $db->query("SHOW TABLES LIKE 'user_tags'");
        $hasUserTags = $checkStmt->fetch() !== false;
    } catch (Exception $e) {
        $hasUserTags = false;
    }

    // Build tags subquery only if table exists
    $tagsSubquery = "NULL as tags";
    if ($hasUserTags) {
        $tagsSubquery = "(SELECT GROUP_CONCAT(t.name SEPARATOR ', ') FROM user_tags t 
             JOIN user_tag_assignments uta ON t.id = uta.tag_id 
             WHERE uta.user_id = u.id) as tags";
    }

    $sql = "SELECT {$selectCols},
            {$tagsSubquery},
            (SELECT COUNT(*) FROM messages m WHERE m.user_id = u.id) as message_count,
            (SELECT MAX(created_at) FROM messages m WHERE m.user_id = u.id) as last_message_at
            FROM users u 
            WHERE {$whereClause}
            ORDER BY u.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add default values for missing columns
    foreach ($users as &$user) {
        if (!$hasLineAccountId) {
            $user['line_account_id'] = null;
        }
        if (!$hasExtraCols) {
            $user['real_name'] = null;
            $user['phone'] = null;
            $user['email'] = null;
            $user['birthday'] = null;
        }
    }
    unset($user);
} catch (Exception $e) {
    // Fallback query - use only basic columns
    $sql = "SELECT u.id, u.line_user_id, u.display_name, u.picture_url, u.status_message, u.is_blocked, u.created_at, u.updated_at 
            FROM users u WHERE {$whereClause} ORDER BY u.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$user) {
        $user['tags'] = null;
        $user['message_count'] = 0;
        $user['last_message_at'] = null;
        $user['real_name'] = null;
        $user['phone'] = null;
        $user['email'] = null;
        $user['birthday'] = null;
        $user['line_account_id'] = null;
    }
    unset($user);
}

// Get all tags (only if table exists)
$allTags = [];
if ($hasTagTables) {
    try {
        $currentBotId = $currentBotId ?? null;
        $stmt = $db->prepare("SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
        $stmt->execute([$currentBotId]);
        $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }
}

// Odoo linked customers data (for Odoo tab)
$hasOdooLinkTable = false;
$hasOdooCustomerProjection = false;
$hasOdooOrderProjection = false;
$hasOdooWebhookLog = false;
$odooOrderProjectionHasRows = false;
$odooCustomers = [];
$odooTotal = 0;
$odooTotalPages = 0;
$odooUnlinkedCustomers = [];
$odooUnlinkedTotal = 0;
$odooUnlinkedPages = 0;
$odooSummary = [
    'linked_total' => 0,
    'orders_30d' => 0,
    'spend_30d' => 0,
    'total_due' => 0,
    'overdue_amount' => 0,
    'credit_limit' => 0,
    'credit_used' => 0,
    'credit_remaining' => 0,
    'latest_order_at' => null
];

try {
    $checkStmt = $db->query("SHOW TABLES LIKE 'odoo_line_users'");
    $hasOdooLinkTable = $checkStmt->fetch() !== false;
} catch (Exception $e) {
    $hasOdooLinkTable = false;
}

try {
    $checkStmt = $db->query("SHOW TABLES LIKE 'odoo_customer_projection'");
    $hasOdooCustomerProjection = $checkStmt->fetch() !== false;
} catch (Exception $e) {
    $hasOdooCustomerProjection = false;
}

try {
    $checkStmt = $db->query("SHOW TABLES LIKE 'odoo_order_projection'");
    $hasOdooOrderProjection = $checkStmt->fetch() !== false;
} catch (Exception $e) {
    $hasOdooOrderProjection = false;
}

if ($hasOdooOrderProjection) {
    try {
        $countStmt = $db->query("SELECT COUNT(*) FROM odoo_order_projection");
        $odooOrderProjectionHasRows = ((int) $countStmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        $odooOrderProjectionHasRows = false;
    }
}

try {
    $checkStmt = $db->query("SHOW TABLES LIKE 'odoo_webhooks_log'");
    $hasOdooWebhookLog = $checkStmt->fetch() !== false;
} catch (Exception $e) {
    $hasOdooWebhookLog = false;
}

if ($hasOdooLinkTable) {
    $odooWhere = [];
    $odooParams = [];

    if (!empty($currentBotId)) {
        $odooWhere[] = '(olu.line_account_id = ? OR olu.line_account_id IS NULL)';
        $odooParams[] = $currentBotId;
    }

    if ($odooSearch !== '') {
        $odooWhere[] = "(olu.odoo_partner_name LIKE ? OR olu.odoo_customer_code LIKE ? OR olu.line_user_id LIKE ? OR u.display_name LIKE ? OR u.phone LIKE ? OR u.real_name LIKE ? OR CAST(olu.odoo_partner_id AS CHAR) LIKE ?)";
        $searchParam = "%{$odooSearch}%";
        $odooParams[] = $searchParam;
        $odooParams[] = $searchParam;
        $odooParams[] = $searchParam;
        $odooParams[] = $searchParam;
        $odooParams[] = $searchParam;
        $odooParams[] = $searchParam;
        $odooParams[] = $searchParam;
    }

    $odooWhereClause = count($odooWhere) > 0 ? 'WHERE ' . implode(' AND ', $odooWhere) : '';

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM odoo_line_users olu LEFT JOIN users u ON u.line_user_id = olu.line_user_id {$odooWhereClause}");
        $stmt->execute($odooParams);
        $odooTotal = (int) $stmt->fetchColumn();
        $odooTotalPages = (int) ceil($odooTotal / max(1, $odooPerPage));
    } catch (Exception $e) {
        $odooTotal = 0;
        $odooTotalPages = 0;
    }

    $selectCols = "olu.*, u.id as user_id, u.display_name, u.real_name, u.phone, u.email, u.picture_url, u.is_blocked, u.line_user_id as user_line_user_id";
    if ($hasLineAccountId) {
        $selectCols .= ", u.line_account_id as user_line_account_id";
    }
    if ($hasOdooCustomerProjection) {
        $selectCols .= ", cp.credit_limit, cp.credit_used, cp.credit_remaining, cp.total_due, cp.overdue_amount, cp.latest_order_name, cp.latest_order_at, cp.orders_count_30d, cp.orders_count_90d, cp.spend_30d, cp.spend_90d";
    }

    $sql = "SELECT {$selectCols}
            FROM odoo_line_users olu
            LEFT JOIN users u ON u.line_user_id = olu.line_user_id";
    if ($hasOdooCustomerProjection) {
        $sql .= " LEFT JOIN odoo_customer_projection cp ON cp.line_user_id = olu.line_user_id";
    }
    $sql .= " {$odooWhereClause}
            ORDER BY olu.linked_at DESC
            LIMIT {$odooPerPage} OFFSET {$odooOffset}";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($odooParams);
        $odooCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $odooCustomers = [];
    }

    $odooSummary['linked_total'] = $odooTotal;

    if ($hasOdooCustomerProjection) {
        try {
            $summaryStmt = $db->query("SELECT
                    COUNT(*) as customers,
                    SUM(COALESCE(orders_count_30d, 0)) as orders_30d,
                    SUM(COALESCE(spend_30d, 0)) as spend_30d,
                    SUM(COALESCE(total_due, 0)) as total_due,
                    SUM(COALESCE(overdue_amount, 0)) as overdue_amount,
                    SUM(COALESCE(credit_limit, 0)) as credit_limit,
                    SUM(COALESCE(credit_used, 0)) as credit_used,
                    SUM(COALESCE(credit_remaining, 0)) as credit_remaining,
                    MAX(latest_order_at) as latest_order_at
                FROM odoo_customer_projection");
            $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $odooSummary['linked_total'] = (int) ($summaryRow['customers'] ?? $odooSummary['linked_total']);
            $odooSummary['orders_30d'] = (int) ($summaryRow['orders_30d'] ?? 0);
            $odooSummary['spend_30d'] = (float) ($summaryRow['spend_30d'] ?? 0);
            $odooSummary['total_due'] = (float) ($summaryRow['total_due'] ?? 0);
            $odooSummary['overdue_amount'] = (float) ($summaryRow['overdue_amount'] ?? 0);
            $odooSummary['credit_limit'] = (float) ($summaryRow['credit_limit'] ?? 0);
            $odooSummary['credit_used'] = (float) ($summaryRow['credit_used'] ?? 0);
            $odooSummary['credit_remaining'] = (float) ($summaryRow['credit_remaining'] ?? 0);
            $odooSummary['latest_order_at'] = $summaryRow['latest_order_at'] ?? null;
        } catch (Exception $e) {
        }
    }
}

if ($hasOdooOrderProjection || $hasOdooWebhookLog) {
    $unlinkedParams = [];
    $unlinkedWhere = [];
    $orderSource = ($hasOdooOrderProjection && $odooOrderProjectionHasRows) ? 'projection' : 'webhook';

    if ($orderSource === 'projection') {
        $unlinkedWhere[] = 'op.odoo_partner_id IS NOT NULL';
    } else {
        $unlinkedWhere[] = "JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.id')) IS NOT NULL";
    }
    $unlinkedWhere[] = 'olu.odoo_partner_id IS NULL';

    if ($odooSearch !== '') {
        if ($orderSource === 'projection') {
            $unlinkedWhere[] = "(op.customer_name LIKE ? OR op.customer_ref LIKE ? OR CAST(op.odoo_partner_id AS CHAR) LIKE ?)";
        } else {
            $unlinkedWhere[] = "(JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.name')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.ref')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.id')) LIKE ?)";
        }
        $searchParam = "%{$odooSearch}%";
        $unlinkedParams[] = $searchParam;
        $unlinkedParams[] = $searchParam;
        $unlinkedParams[] = $searchParam;
    }

    $unlinkedWhereClause = 'WHERE ' . implode(' AND ', $unlinkedWhere);

    if ($orderSource === 'projection') {
        try {
            $countSql = "SELECT COUNT(*) FROM (
                    SELECT op.odoo_partner_id
                    FROM odoo_order_projection op
                    LEFT JOIN odoo_line_users olu ON olu.odoo_partner_id = op.odoo_partner_id
                    {$unlinkedWhereClause}
                    GROUP BY op.odoo_partner_id
                ) t";
            $stmt = $db->prepare($countSql);
            $stmt->execute($unlinkedParams);
            $odooUnlinkedTotal = (int) $stmt->fetchColumn();
            $odooUnlinkedPages = (int) ceil($odooUnlinkedTotal / max(1, $odooUnlinkedPerPage));
        } catch (Exception $e) {
            $odooUnlinkedTotal = 0;
            $odooUnlinkedPages = 0;
        }

        $unlinkedSql = "SELECT
                op.odoo_partner_id,
                MAX(op.customer_name) as customer_name,
                MAX(op.customer_ref) as customer_ref,
                MAX(op.last_webhook_at) as latest_order_at,
                COUNT(*) as orders_total,
                SUM(COALESCE(op.amount_total, 0)) as spend_total
            FROM odoo_order_projection op
            LEFT JOIN odoo_line_users olu ON olu.odoo_partner_id = op.odoo_partner_id
            {$unlinkedWhereClause}
            GROUP BY op.odoo_partner_id
            ORDER BY latest_order_at DESC
            LIMIT {$odooUnlinkedPerPage} OFFSET {$odooUnlinkedOffset}";

        try {
            $stmt = $db->prepare($unlinkedSql);
            $stmt->execute($unlinkedParams);
            $odooUnlinkedCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $odooUnlinkedCustomers = [];
        }
    } else {
        try {
            $countSql = "SELECT COUNT(*) FROM (
                    SELECT JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.id')) AS partner_id
                    FROM odoo_webhooks_log op
                    LEFT JOIN odoo_line_users olu ON olu.odoo_partner_id = JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.id'))
                    {$unlinkedWhereClause}
                    GROUP BY JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.id'))
                ) t";
            $stmt = $db->prepare($countSql);
            $stmt->execute($unlinkedParams);
            $odooUnlinkedTotal = (int) $stmt->fetchColumn();
            $odooUnlinkedPages = (int) ceil($odooUnlinkedTotal / max(1, $odooUnlinkedPerPage));
        } catch (Exception $e) {
            $odooUnlinkedTotal = 0;
            $odooUnlinkedPages = 0;
        }

        $unlinkedSql = "SELECT
                JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.id')) AS odoo_partner_id,
                MAX(JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.name'))) as customer_name,
                MAX(JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.ref'))) as customer_ref,
                MAX(op.processed_at) as latest_order_at,
                COUNT(*) as orders_total,
                SUM(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.amount_total')), '0') AS DECIMAL(14,2))) as spend_total
            FROM odoo_webhooks_log op
            LEFT JOIN odoo_line_users olu ON olu.odoo_partner_id = JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.id'))
            {$unlinkedWhereClause}
            GROUP BY JSON_UNQUOTE(JSON_EXTRACT(op.payload, '$.customer.id'))
            ORDER BY latest_order_at DESC
            LIMIT {$odooUnlinkedPerPage} OFFSET {$odooUnlinkedOffset}";

        try {
            $stmt = $db->prepare($unlinkedSql);
            $stmt->execute($unlinkedParams);
            $odooUnlinkedCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $odooUnlinkedCustomers = [];
        }
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_tag') {
        $userId = (int) $_POST['user_id'];
        $tagId = (int) $_POST['tag_id'];
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'manual')");
            $stmt->execute([$userId, $tagId]);
        } catch (Exception $e) {
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'remove_tag') {
        $userId = (int) $_POST['user_id'];
        $tagId = (int) $_POST['tag_id'];
        try {
            $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
            $stmt->execute([$userId, $tagId]);
        } catch (Exception $e) {
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$lineTabUrl = '?' . http_build_query(array_merge($_GET, ['tab' => 'line', 'page' => 1]));
$odooTabUrl = '?' . http_build_query(array_merge($_GET, ['tab' => 'odoo', 'odoo_page' => 1]));
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h2 class="users-title">👥 Customers</h2>
        <?php if ($activeTab === 'odoo'): ?>
            <p class="users-subtitle">ลูกค้า Odoo ที่เชื่อมแล้ว <?php echo number_format($odooSummary['linked_total'] ?? 0); ?> ราย</p>
        <?php else: ?>
            <p class="users-subtitle">ทั้งหมด <?php echo number_format($totalUsers); ?> คน</p>
        <?php endif; ?>
    </div>
    <div class="flex items-center gap-2">
        <a href="odoo-dashboard.php" class="btn-glass-primary">
            <i class="fas fa-chart-line mr-1"></i>Odoo Dashboard
        </a>
    </div>
</div>

<div class="flex flex-wrap gap-2 mb-6">
    <a href="<?= $lineTabUrl ?>" class="tab-btn-glass <?= $activeTab === 'line' ? 'active' : '' ?>">
        <i class="fab fa-line mr-1"></i>LINE Users
    </a>
    <a href="<?= $odooTabUrl ?>" class="tab-btn-glass <?= $activeTab === 'odoo' ? 'active' : '' ?>">
        <i class="fas fa-link mr-1"></i>Odoo Customers
    </a>
</div>

<?php if ($activeTab === 'odoo'): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        <div class="stat-card-glass">
            <p class="stat-label">ออเดอร์ 30 วัน</p>
            <p class="stat-value text-indigo-400"><?php echo number_format((int) ($odooSummary['orders_30d'] ?? 0)); ?></p>
            <p class="stat-sub">ยอดขาย ฿<?php echo number_format((float) ($odooSummary['spend_30d'] ?? 0), 2); ?></p>
        </div>
        <div class="stat-card-glass">
            <p class="stat-label">ยอดค้างชำระ</p>
            <p class="stat-value text-rose-400">฿<?php echo number_format((float) ($odooSummary['total_due'] ?? 0), 2); ?></p>
            <p class="stat-sub">เกินกำหนด ฿<?php echo number_format((float) ($odooSummary['overdue_amount'] ?? 0), 2); ?></p>
        </div>
        <div class="stat-card-glass">
            <p class="stat-label">วงเงินเครดิต</p>
            <p class="stat-value text-blue-400">฿<?php echo number_format((float) ($odooSummary['credit_limit'] ?? 0), 2); ?></p>
            <p class="stat-sub">ใช้ไป ฿<?php echo number_format((float) ($odooSummary['credit_used'] ?? 0), 2); ?></p>
        </div>
        <div class="stat-card-glass">
            <p class="stat-label">เครดิตคงเหลือ</p>
            <p class="stat-value text-emerald-400">฿<?php echo number_format((float) ($odooSummary['credit_remaining'] ?? 0), 2); ?></p>
            <p class="stat-sub">
                อัปเดตล่าสุด <?php echo !empty($odooSummary['latest_order_at']) ? date('d/m/Y H:i', strtotime($odooSummary['latest_order_at'])) : '-'; ?>
            </p>
        </div>
    </div>

    <div class="users-card">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="tab" value="odoo">
            <div class="flex-1 min-w-[220px]">
                <label class="block text-sm font-medium mb-1">ค้นหา Odoo</label>
                <input type="text" name="odoo_search" value="<?= htmlspecialchars($odooSearch) ?>"
                    placeholder="ชื่อ, รหัสลูกค้า, Partner ID, LINE ID..."
                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500">
            </div>
            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                <i class="fas fa-search mr-1"></i>ค้นหา
            </button>
            <a href="<?= $odooTabUrl ?>" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ล้างตัวกรอง</a>
        </form>
        <div class="mt-3 text-xs text-gray-500">
            ลูกค้าที่เชื่อมแล้ว <?= number_format((int) $odooTotal) ?> ราย · ยังไม่เชื่อม LINE <?= number_format((int) $odooUnlinkedTotal) ?> ราย
            · ทั้งหมด <?= number_format((int) ($odooTotal + $odooUnlinkedTotal)) ?> ราย
        </div>
    </div>

    <div class="users-card overflow-hidden" style="padding: 0;">
        <table class="table-glass">
            <thead>
                <tr>
                    <th>ลูกค้า Odoo</th>
                    <th>การติดต่อ</th>
                    <th>ออเดอร์</th>
                    <th>เครดิต/หนี้</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($odooCustomers as $customer): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-800">
                                        <?= htmlspecialchars($customer['odoo_partner_name'] ?? $customer['customer_name'] ?? 'ไม่ระบุ') ?>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <?= htmlspecialchars($customer['odoo_customer_code'] ?? '-') ?> · #<?= htmlspecialchars($customer['odoo_partner_id'] ?? '-') ?>
                                    </p>
                                    <div class="mt-1">
                                        <span class="inline-block px-2 py-0.5 rounded-full text-[11px] bg-indigo-50 text-indigo-600">
                                            <?= htmlspecialchars($customer['linked_via'] ?? 'linked') ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <div class="space-y-1">
                                <div>
                                    <span class="text-gray-500">LINE:</span>
                                    <span class="font-mono text-xs text-gray-700"><?= htmlspecialchars($customer['line_user_id'] ?? '-') ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500">โทร:</span>
                                    <span class="text-gray-700"><?= htmlspecialchars($customer['phone'] ?? $customer['odoo_phone'] ?? '-') ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500">อีเมล:</span>
                                    <span class="text-gray-700"><?= htmlspecialchars($customer['email'] ?? $customer['odoo_email'] ?? '-') ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <div class="space-y-1">
                                <div>
                                    <span class="text-gray-500">ล่าสุด:</span>
                                    <span class="text-gray-700"><?= htmlspecialchars($customer['latest_order_name'] ?? '-') ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500">ออเดอร์ 30 วัน:</span>
                                    <span class="text-gray-700"><?= number_format((int) ($customer['orders_count_30d'] ?? 0)) ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500">ยอด 30 วัน:</span>
                                    <span class="text-emerald-600 font-medium">฿<?= number_format((float) ($customer['spend_30d'] ?? 0), 2) ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <div class="space-y-1">
                                <div>
                                    <span class="text-gray-500">เครดิต:</span>
                                    <span class="text-gray-700">฿<?= number_format((float) ($customer['credit_limit'] ?? 0), 2) ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500">ใช้ไป:</span>
                                    <span class="text-gray-700">฿<?= number_format((float) ($customer['credit_used'] ?? 0), 2) ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500">ค้างชำระ:</span>
                                    <span class="text-red-600 font-medium">฿<?= number_format((float) ($customer['total_due'] ?? 0), 2) ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex flex-col items-center gap-2">
                                <button type="button"
                                    onclick="openOdooDetailModal('<?= htmlspecialchars($customer['line_user_id'] ?? '', ENT_QUOTES) ?>', '<?= (int) ($customer['user_id'] ?? 0) ?>', '<?= htmlspecialchars($customer['user_line_account_id'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($customer['odoo_partner_id'] ?? '', ENT_QUOTES) ?>')"
                                    class="inline-flex items-center gap-2 px-3 py-2 bg-indigo-50 text-indigo-700 rounded-lg text-sm hover:bg-indigo-100">
                                    <i class="fas fa-eye"></i> รายละเอียด Odoo
                                </button>
                                <?php if (!empty($customer['user_id'])): ?>
                                    <a href="user-detail.php?id=<?= (int) $customer['user_id'] ?>"
                                        class="inline-flex items-center gap-2 px-3 py-2 bg-green-50 text-green-700 rounded-lg text-sm hover:bg-green-100">
                                        <i class="fas fa-user"></i> ดูโปรไฟล์
                                    </a>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">ยังไม่มีโปรไฟล์ LINE</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($odooCustomers)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-link text-4xl text-gray-300 mb-3 block"></i>
                            <p>ยังไม่มีลูกค้า Odoo ที่เชื่อมต่อ</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($odooTotalPages > 1): ?>
        <div class="pagination-glass">
            <?php for ($i = 1; $i <= $odooTotalPages; $i++): ?>
                <?php if ($i == $odooPage): ?>
                    <span class="current"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['tab' => 'odoo', 'odoo_page' => $i])); ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <div class="mt-8 users-card overflow-hidden" style="padding: 0;">
        <div class="p-4 border-b flex items-center justify-between" style="border-color: rgba(255,255,255,0.1);">
            <div>
                <h3 class="font-semibold text-white">ลูกค้า Odoo (ยังไม่เชื่อม LINE)</h3>
                <p class="text-xs" style="color: rgba(255,255,255,0.5);">ดึงจากออเดอร์ Odoo ที่มีรหัสลูกค้า</p>
            </div>
            <span class="text-xs text-gray-400">ทั้งหมด <?= number_format((int) $odooUnlinkedTotal) ?> ราย</span>
        </div>
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ลูกค้า Odoo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">สรุปออเดอร์</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ล่าสุด</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($odooUnlinkedCustomers as $customer): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <p class="font-medium text-gray-800"><?= htmlspecialchars($customer['customer_name'] ?? 'ไม่ระบุ') ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($customer['customer_ref'] ?? '-') ?> · #<?= htmlspecialchars($customer['odoo_partner_id'] ?? '-') ?></p>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <div>
                                <span class="text-gray-500">ออเดอร์:</span>
                                <span class="text-gray-700"><?= number_format((int) ($customer['orders_total'] ?? 0)) ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500">ยอดรวม:</span>
                                <span class="text-emerald-600 font-medium">฿<?= number_format((float) ($customer['spend_total'] ?? 0), 2) ?></span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <?= !empty($customer['latest_order_at']) ? date('d/m/Y H:i', strtotime($customer['latest_order_at'])) : '-' ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex flex-col items-center gap-2">
                                <button type="button"
                                    onclick="openOdooDetailModal('', '', '', '<?= htmlspecialchars($customer['odoo_partner_id'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($customer['customer_name'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($customer['customer_ref'] ?? '', ENT_QUOTES) ?>', '<?= (int) ($customer['orders_total'] ?? 0) ?>', '<?= (float) ($customer['spend_total'] ?? 0) ?>', '<?= htmlspecialchars($customer['latest_order_at'] ?? '', ENT_QUOTES) ?>')"
                                    class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 bg-indigo-50 text-indigo-700 rounded-lg text-sm hover:bg-indigo-100">
                                    <i class="fas fa-eye"></i> ดูข้อมูล Odoo
                                </button>
                                <button type="button"
                                    onclick="openOdooLinkModal({
                                        lineUserId: '',
                                        customerCode: '<?= htmlspecialchars($customer['customer_ref'] ?? '', ENT_QUOTES) ?>',
                                        phone: '',
                                        partnerId: '<?= htmlspecialchars($customer['odoo_partner_id'] ?? '', ENT_QUOTES) ?>',
                                        customerName: '<?= htmlspecialchars($customer['customer_name'] ?? '', ENT_QUOTES) ?>',
                                        lineAccountId: '<?= htmlspecialchars($currentBotId ?? '', ENT_QUOTES) ?>'
                                    })"
                                    class="w-full inline-flex items-center justify-center gap-2 px-3 py-2 bg-orange-50 text-orange-700 rounded-lg text-sm hover:bg-orange-100">
                                    <i class="fas fa-link"></i> เชื่อมลูกค้า
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($odooUnlinkedCustomers)): ?>
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-gray-400 text-sm">ยังไม่พบลูกค้า Odoo ที่ยังไม่เชื่อม LINE</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($odooUnlinkedPages > 1): ?>
        <div class="mt-4 flex justify-center gap-2">
            <?php for ($i = 1; $i <= $odooUnlinkedPages; $i++): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['tab' => 'odoo', 'odoo_unlinked_page' => $i])); ?>"
                    class="px-3 py-1 rounded <?php echo $i == $odooUnlinkedPage ? 'bg-indigo-600 text-white' : 'bg-white hover:bg-gray-100'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <!-- Odoo Link Modal -->
    <div id="odooLinkModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 overflow-hidden">
            <div class="p-5 border-b flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-semibold">🔗 เชื่อมลูกค้า Odoo กับ LINE</h3>
                    <p class="text-sm text-gray-500" id="odooLinkSubtitle">ระบุข้อมูลลูกค้า Odoo</p>
                </div>
                <button onclick="closeOdooLinkModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6 space-y-5" id="odooLinkContent">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium text-gray-600">รหัสลูกค้า Odoo (Customer Code)</label>
                        <input type="text" id="odooLinkCustomerCode" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500" placeholder="เช่น PC4788">
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-600">เบอร์โทรลูกค้า (9-10 หลัก)</label>
                        <input type="tel" id="odooLinkPhone" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500" placeholder="0XXXXXXXX">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium text-gray-600">LINE User ID</label>
                        <input type="text" id="odooLinkLineUserId" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500" placeholder="Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                        <p class="text-xs text-gray-400 mt-1">คัดลอกจากแท็บ LINE Users (รูปแบบ U + 32 ตัวอักษร)</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-gray-600">LINE Account</label>
                        <input type="text" id="odooLinkLineAccount" class="w-full px-4 py-2 border rounded-lg bg-gray-50 text-gray-500" readonly>
                    </div>
                </div>
                <div id="odooLinkAlert" class="hidden p-3 rounded-lg text-sm"></div>
                <div class="flex items-center justify-between">
                    <div class="text-xs text-gray-500">จำเป็นต้องกรอก Customer Code + เบอร์โทร เพื่อยืนยันตัวตน</div>
                    <button id="odooLinkSubmit" class="px-5 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 flex items-center gap-2">
                        <span>เชื่อมลูกค้า</span>
                    </button>
                </div>
            </div>
            <div class="border-t bg-gray-50 p-5 hidden" id="odooLinkResult">
                <h4 class="text-sm font-semibold text-gray-700 mb-3">ผลการเชื่อมล่าสุด</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="p-3 bg-white rounded-lg border">
                        <p class="text-xs text-gray-500">ลูกค้า</p>
                        <p class="text-lg font-semibold" id="odooLinkResultName">-</p>
                        <p class="text-xs text-gray-400" id="odooLinkResultCode">-</p>
                    </div>
                    <div class="p-3 bg-white rounded-lg border">
                        <p class="text-xs text-gray-500">Partner ID</p>
                        <p class="text-lg font-semibold" id="odooLinkResultPartner">-</p>
                        <p class="text-xs text-gray-400" id="odooLinkResultPhone">-</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="odooDetailModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl mx-4 overflow-hidden">
            <div class="p-5 border-b flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-semibold">📌 รายละเอียดลูกค้า Odoo</h3>
                    <p class="text-sm text-gray-500" id="odooDetailSubtitle">กำลังโหลด...</p>
                </div>
                <button onclick="closeOdooDetailModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-6 max-h-[75vh] overflow-y-auto" id="odooDetailContent">
                <div class="text-gray-400 text-center py-10">กำลังโหลดข้อมูล Odoo...</div>
            </div>
        </div>
    </div>

    <script>
        let currentOdooLinkPayload = null;

        function openOdooLinkModal(payload = {}) {
            currentOdooLinkPayload = payload;
            document.getElementById('odooLinkModal').classList.remove('hidden');
            document.getElementById('odooLinkModal').classList.add('flex');
            document.getElementById('odooLinkCustomerCode').value = payload.customerCode || '';
            document.getElementById('odooLinkPhone').value = payload.phone || '';
            document.getElementById('odooLinkLineUserId').value = payload.lineUserId || '';
            document.getElementById('odooLinkLineAccount').value = payload.lineAccountId || '-';
            document.getElementById('odooLinkAlert').classList.add('hidden');
            document.getElementById('odooLinkResult').classList.add('hidden');
            document.getElementById('odooLinkSubtitle').textContent = payload.customerName ? `ลูกค้า: ${payload.customerName}` : 'ระบุข้อมูลลูกค้า Odoo';

            const submitBtn = document.getElementById('odooLinkSubmit');
            submitBtn.onclick = () => submitOdooLink();
        }

        function closeOdooLinkModal() {
            document.getElementById('odooLinkModal').classList.add('hidden');
            document.getElementById('odooLinkModal').classList.remove('flex');
        }

        function showOdooLinkAlert(message, type = 'error') {
            const alertBox = document.getElementById('odooLinkAlert');
            alertBox.textContent = message;
            alertBox.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'bg-green-50', 'text-green-700');
            if (type === 'success') {
                alertBox.classList.add('bg-green-50', 'text-green-700');
            } else {
                alertBox.classList.add('bg-red-50', 'text-red-700');
            }
        }

        async function submitOdooLink() {
            const customerCode = document.getElementById('odooLinkCustomerCode').value.trim();
            const phone = document.getElementById('odooLinkPhone').value.trim();
            const lineUserId = document.getElementById('odooLinkLineUserId').value.trim();
            const lineAccountId = currentOdooLinkPayload?.lineAccountId || null;

            if (!customerCode || !phone) {
                showOdooLinkAlert('กรอก Customer Code และเบอร์โทรให้ครบก่อนเชื่อม');
                return;
            }

            const lineIdPattern = /^U[a-fA-F0-9]{32}$/;
            if (!lineIdPattern.test(lineUserId)) {
                showOdooLinkAlert('LINE User ID ไม่ถูกต้อง (ต้องขึ้นต้นด้วย U และตามด้วยตัวอักษร/ตัวเลข 32 ตัว)');
                return;
            }

            const cleanedPhone = phone.replace(/[^0-9]/g, '');
            if ((cleanedPhone.length !== 10 && cleanedPhone.length !== 9) || cleanedPhone[0] !== '0') {
                showOdooLinkAlert('รูปแบบเบอร์โทรไม่ถูกต้อง (ต้องเป็น 9-10 หลัก และขึ้นต้นด้วย 0)');
                return;
            }

            try {
                const resp = await fetch('api/odoo-user-link.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'link',
                        line_user_id: lineUserId,
                        account_id: lineAccountId,
                        customer_code: customerCode,
                        phone: cleanedPhone
                    })
                });
                const result = await resp.json();
                if (!resp.ok || !result.success) {
                    showOdooLinkAlert(result.error || 'เชื่อมต่อล้มเหลว');
                    return;
                }

                document.getElementById('odooLinkResult').classList.remove('hidden');
                document.getElementById('odooLinkResultName').textContent = result.data?.partner_name || '-';
                document.getElementById('odooLinkResultCode').textContent = result.data?.customer_code || customerCode;
                document.getElementById('odooLinkResultPartner').textContent = result.data?.partner_id || '-';
                document.getElementById('odooLinkResultPhone').textContent = result.data?.phone ? `โทร ${result.data.phone}` : '-';

                showOdooLinkAlert(result.data?.message || 'เชื่อมลูกค้าสำเร็จ', 'success');

                setTimeout(() => {
                    window.location.reload();
                }, 1200);
            } catch (error) {
                showOdooLinkAlert(error.message || 'เกิดข้อผิดพลาดในการเชื่อมลูกค้า');
            }
        }

        const _WH_API = 'api/odoo-webhooks-dashboard.php';
        async function whApiCall(data){try{const r=await fetch(_WH_API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});return await r.json();}catch(e){return{success:false,error:e.message};}}

        async function openOdooDetailModal(lineUserId, userId, lineAccountId, partnerId, fallbackName, fallbackRef, ordersTotal, spendTotal, latestOrderAt) {
            const modal = document.getElementById('odooDetailModal');
            const content = document.getElementById('odooDetailContent');
            const subtitle = document.getElementById('odooDetailSubtitle');

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            content.innerHTML = '<div class="text-gray-400 text-center py-10"><i class="fas fa-spinner fa-spin mr-2"></i>กำลังโหลดข้อมูล Odoo...</div>';
            subtitle.textContent = 'กำลังโหลด...';

            const pidParam = partnerId && partnerId !== '-' ? partnerId : '';
            const refParam = fallbackRef || '';

            const stateColor = {sale:'#16a34a',done:'#1d4ed8',cancel:'#64748b',draft:'#854d0e',to_delivery:'#7c3aed',packed:'#0891b2',confirmed:'#0369a1'};
            const stMap = {
                posted:'<span style="background:#dcfce7;color:#16a34a;padding:2px 6px;border-radius:50px;font-size:0.75rem;">ยืนยัน</span>',
                paid:'<span style="background:#dbeafe;color:#1d4ed8;padding:2px 6px;border-radius:50px;font-size:0.75rem;">ชำระแล้ว</span>',
                open:'<span style="background:#fef9c3;color:#854d0e;padding:2px 6px;border-radius:50px;font-size:0.75rem;">ค้างชำระ</span>',
                overdue:'<span style="background:#fee2e2;color:#dc2626;padding:2px 6px;border-radius:50px;font-size:0.75rem;">เกินกำหนด</span>',
                cancel:'<span style="background:#f1f5f9;color:#64748b;padding:2px 6px;border-radius:50px;font-size:0.75rem;">ยกเลิก</span>',
                draft:'<span style="background:#fef9c3;color:#854d0e;padding:2px 6px;border-radius:50px;font-size:0.75rem;">ร่าง</span>'
            };

            try {
                const [ordRes, invRes] = await Promise.all([
                    whApiCall({action:'odoo_orders', limit:50, offset:0, partner_id:pidParam, customer_ref:refParam}),
                    whApiCall({action:'odoo_invoices', limit:50, offset:0, partner_id:pidParam, customer_ref:refParam})
                ]);

                subtitle.textContent = fallbackName || '-';

                let html = '<div style="display:flex;gap:0.5rem;margin-bottom:1rem;border-bottom:2px solid #e5e7eb;">';
                html += '<button id="usrTabBtnOrders" onclick="usrCustSwitchTab(\'orders\')" style="padding:0.4rem 1rem;border:none;border-bottom:2px solid #6366f1;background:none;font-weight:600;cursor:pointer;color:#6366f1;font-size:0.875rem;"><i class="fas fa-shopping-bag mr-1"></i>ออเดอร์ (' + (ordRes&&ordRes.success ? Number(ordRes.data.total||0) : 0) + ')</button>';
                html += '<button id="usrTabBtnInvoices" onclick="usrCustSwitchTab(\'invoices\')" style="padding:0.4rem 1rem;border:none;border-bottom:2px solid transparent;background:none;cursor:pointer;color:#6b7280;font-size:0.875rem;"><i class="fas fa-file-invoice mr-1"></i>ใบแจ้งหนี้ (' + (invRes&&invRes.success ? Number(invRes.data.total||0) : 0) + ')</button>';
                html += '</div>';

                // Orders tab
                html += '<div id="usrTabOrders">';
                if (!ordRes || !ordRes.success) {
                    html += '<p style="color:#6b7280;font-size:0.875rem;">' + ((ordRes&&ordRes.error)||'ไม่สามารถโหลดออเดอร์ได้') + '</p>';
                } else {
                    const orders = ordRes.data.orders || [];
                    if (!orders.length) {
                        html += '<p style="color:#9ca3af;text-align:center;padding:2rem;font-size:0.875rem;">ไม่พบออเดอร์</p>';
                    } else {
                        html += '<p style="font-size:0.8rem;color:#6b7280;margin-bottom:0.5rem;">ทั้งหมด ' + Number(ordRes.data.total||0).toLocaleString() + ' รายการ</p>';
                        html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;"><thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;"><th style="padding:0.5rem;text-align:left;">เลขที่ออเดอร์</th><th style="padding:0.5rem;text-align:left;">สถานะ</th><th style="padding:0.5rem;text-align:right;">ยอดรวม</th><th style="padding:0.5rem;text-align:left;">อัปเดตล่าสุด</th></tr></thead><tbody>';
                        orders.forEach(o => {
                            const d = o.last_updated_at ? new Date(o.last_updated_at) : null;
                            const dt = d && !isNaN(d) ? d.toLocaleDateString('th-TH', {day:'2-digit',month:'short',year:'2-digit'}) : '-';
                            const sc = stateColor[String(o.state||'').toLowerCase()] || '#64748b';
                            const sb = '<span style="background:'+sc+'22;color:'+sc+';padding:2px 8px;border-radius:50px;font-size:0.75rem;">' + (o.state_display||o.state||'-') + '</span>';
                            const amt = o.amount_total != null && o.amount_total > 0 ? '฿'+Number(o.amount_total).toLocaleString() : '-';
                            html += '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:0.5rem;font-weight:500;">' + (o.order_name||'-') + '</td><td style="padding:0.5rem;">' + sb + '</td><td style="padding:0.5rem;text-align:right;">' + amt + '</td><td style="padding:0.5rem;color:#6b7280;">' + dt + '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                    }
                }
                html += '</div>';

                // Invoices tab
                html += '<div id="usrTabInvoices" style="display:none;">';
                if (!invRes || !invRes.success) {
                    html += '<p style="color:#6b7280;font-size:0.875rem;">' + ((invRes&&invRes.error)||'ไม่สามารถโหลดใบแจ้งหนี้ได้') + '</p>';
                } else {
                    const invoices = invRes.data.invoices || [];
                    if (!invoices.length) {
                        html += '<p style="color:#9ca3af;text-align:center;padding:2rem;font-size:0.875rem;">ไม่พบใบแจ้งหนี้</p>';
                    } else {
                        html += '<p style="font-size:0.8rem;color:#6b7280;margin-bottom:0.5rem;">ทั้งหมด ' + Number(invRes.data.total||0).toLocaleString() + ' รายการ</p>';
                        html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.85rem;"><thead><tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;"><th style="padding:0.5rem;text-align:left;">เลขที่</th><th style="padding:0.5rem;text-align:left;">วันที่</th><th style="padding:0.5rem;text-align:left;">สถานะ</th><th style="padding:0.5rem;text-align:right;">ยอดรวม</th><th style="padding:0.5rem;text-align:right;">ค้างชำระ</th></tr></thead><tbody>';
                        invoices.forEach(inv => {
                            const rawDate = inv.invoice_date || inv.due_date || inv.processed_at || null;
                            const d = rawDate ? new Date(rawDate) : null;
                            const dt = d && !isNaN(d) ? d.toLocaleDateString('th-TH') : '-';
                            const stateVal = String(inv.invoice_state||inv.state||'').toLowerCase();
                            const sb = stMap[stateVal] || '<span style="background:#f1f5f9;padding:2px 6px;border-radius:50px;font-size:0.75rem;">'+(inv.invoice_state||inv.state||'-')+'</span>';
                            const amt = inv.amount_total != null ? '฿'+Number(inv.amount_total).toLocaleString() : '-';
                            const isPaid = stateVal === 'paid';
                            const resAmt = isPaid ? 0 : parseFloat(inv.amount_residual||0);
                            const res = isPaid ? '<span style="color:#9ca3af;">฿0</span>' : ('฿'+Number(inv.amount_residual||0).toLocaleString());
                            const resColor = (!isPaid && resAmt > 0) ? '#dc2626' : 'inherit';
                            html += '<tr style="border-bottom:1px solid #f3f4f6;"><td style="padding:0.5rem;font-weight:500;">'+(inv.invoice_number||inv.name||'-')+'</td><td style="padding:0.5rem;color:#6b7280;">'+dt+'</td><td style="padding:0.5rem;">'+sb+'</td><td style="padding:0.5rem;text-align:right;">'+amt+'</td><td style="padding:0.5rem;text-align:right;color:'+resColor+';">'+res+'</td></tr>';
                        });
                        html += '</tbody></table></div>';
                    }
                }
                html += '</div>';

                content.innerHTML = html;
            } catch (err) {
                subtitle.textContent = fallbackName || 'ไม่สามารถโหลดข้อมูลได้';
                content.innerHTML = '<div class="text-red-500 text-center py-6">เกิดข้อผิดพลาด: ' + err.message + '</div>';
            }
        }

        function usrCustSwitchTab(tab) {
            document.getElementById('usrTabOrders').style.display = tab === 'orders' ? '' : 'none';
            document.getElementById('usrTabInvoices').style.display = tab === 'invoices' ? '' : 'none';
            const bo = document.getElementById('usrTabBtnOrders');
            const bi = document.getElementById('usrTabBtnInvoices');
            if (bo) { bo.style.borderBottomColor = tab==='orders' ? '#6366f1' : 'transparent'; bo.style.color = tab==='orders' ? '#6366f1' : '#6b7280'; bo.style.fontWeight = tab==='orders' ? '600' : '400'; }
            if (bi) { bi.style.borderBottomColor = tab==='invoices' ? '#6366f1' : 'transparent'; bi.style.color = tab==='invoices' ? '#6366f1' : '#6b7280'; bi.style.fontWeight = tab==='invoices' ? '600' : '400'; }
        }

        function closeOdooDetailModal() {
            const modal = document.getElementById('odooDetailModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>
<?php else: ?>
    <div class="users-card">
        <form method="GET" id="filterForm">
            <input type="hidden" name="tab" value="line">
            <!-- Basic Search Row -->
            <div class="flex flex-wrap gap-4 items-end mb-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium mb-1" style="color: rgba(255,255,255,0.6);">ค้นหา</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="ชื่อ, เบอร์โทร, LINE ID..."
                        class="input-glass">
                </div>
                <button type="submit" class="btn-glass-primary">
                    <i class="fas fa-search mr-1"></i>ค้นหา
                </button>
                <button type="button" onclick="toggleAdvancedFilters()"
                    class="chip-glass">
                    <i class="fas fa-filter mr-1"></i>ตัวกรอง
                    <?php
                    $activeFilters = array_filter([$tierFilter, $pointsFilter, $activityFilter, $purchaseFilter, $statusFilter, $tagFilter]);
                    if (count($activeFilters) > 0): ?>
                        <span
                            class="ml-1 px-2 py-0.5 bg-indigo-500 text-white text-xs rounded-full"><?= count($activeFilters) ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Advanced Filters (collapsible) -->
            <div id="advancedFilters" class="<?= count($activeFilters) > 0 ? '' : 'hidden' ?> pt-4 border-t">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <!-- Tier Filter -->
                    <div>
                        <label class="block text-sm font-medium mb-1">ระดับสมาชิก</label>
                        <select name="tier" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">ทั้งหมด</option>
                            <option value="bronze" <?= $tierFilter === 'bronze' ? 'selected' : '' ?>>🥉 Bronze</option>
                            <option value="silver" <?= $tierFilter === 'silver' ? 'selected' : '' ?>>🥈 Silver</option>
                            <option value="gold" <?= $tierFilter === 'gold' ? 'selected' : '' ?>>🥇 Gold</option>
                            <option value="platinum" <?= $tierFilter === 'platinum' ? 'selected' : '' ?>>💎 Platinum</option>
                        </select>
                    </div>

                    <!-- Points Filter -->
                    <div>
                        <label class="block text-sm font-medium mb-1">แต้มสะสม</label>
                        <select name="points" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">ทั้งหมด</option>
                            <option value="0-100" <?= $pointsFilter === '0-100' ? 'selected' : '' ?>>0-100 แต้ม</option>
                            <option value="100-500" <?= $pointsFilter === '100-500' ? 'selected' : '' ?>>100-500 แต้ม</option>
                            <option value="500-1000" <?= $pointsFilter === '500-1000' ? 'selected' : '' ?>>500-1,000 แต้ม
                            </option>
                            <option value="1000+" <?= $pointsFilter === '1000+' ? 'selected' : '' ?>>1,000+ แต้ม</option>
                        </select>
                    </div>

                    <!-- Activity Filter -->
                    <div>
                        <label class="block text-sm font-medium mb-1">กิจกรรมล่าสุด</label>
                        <select name="activity"
                            class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">ทั้งหมด</option>
                            <option value="today" <?= $activityFilter === 'today' ? 'selected' : '' ?>>วันนี้</option>
                            <option value="7days" <?= $activityFilter === '7days' ? 'selected' : '' ?>>7 วันที่ผ่านมา</option>
                            <option value="30days" <?= $activityFilter === '30days' ? 'selected' : '' ?>>30 วันที่ผ่านมา
                            </option>
                            <option value="inactive" <?= $activityFilter === 'inactive' ? 'selected' : '' ?>>ไม่มีกิจกรรม (>30
                                วัน)</option>
                        </select>
                    </div>

                    <!-- Purchase Filter -->
                    <div>
                        <label class="block text-sm font-medium mb-1">ประวัติซื้อ</label>
                        <select name="purchase"
                            class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">ทั้งหมด</option>
                            <option value="purchased" <?= $purchaseFilter === 'purchased' ? 'selected' : '' ?>>เคยซื้อแล้ว
                            </option>
                            <option value="never" <?= $purchaseFilter === 'never' ? 'selected' : '' ?>>ยังไม่เคยซื้อ</option>
                            <option value="1000+" <?= $purchaseFilter === '1000+' ? 'selected' : '' ?>>ซื้อ ≥ ฿1,000</option>
                            <option value="5000+" <?= $purchaseFilter === '5000+' ? 'selected' : '' ?>>ซื้อ ≥ ฿5,000</option>
                        </select>
                    </div>

                    <!-- Tag Filter -->
                    <div>
                        <label class="block text-sm font-medium mb-1">Tags</label>
                        <select name="tag" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($allTags as $tag): ?>
                                <option value="<?= $tag['id'] ?>" <?= $tagFilter == $tag['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tag['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div>
                        <label class="block text-sm font-medium mb-1">สถานะ</label>
                        <select name="status" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                            <option value="">ทั้งหมด</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>✅ Active</option>
                            <option value="blocked" <?= $statusFilter === 'blocked' ? 'selected' : '' ?>>🚫 Blocked</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-2 mt-4">
                    <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                        <i class="fas fa-filter mr-1"></i>กรองข้อมูล
                    </button>
                    <a href="users.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                        <i class="fas fa-times mr-1"></i>ล้างตัวกรอง
                    </a>
                </div>
            </div>
        </form>
    </div>

    <script>
        function toggleAdvancedFilters() {
            const filters = document.getElementById('advancedFilters');
            filters.classList.toggle('hidden');
        }
    </script>

    <!-- Bulk Actions Bar (hidden until selection) -->
    <div id="bulkActionsBar" class="hidden bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="font-medium text-blue-700">
                    <i class="fas fa-check-square mr-1"></i>
                    เลือกแล้ว <span id="selectedCount">0</span> คน
                </span>
            </div>
            <div class="flex-1 flex flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <select id="bulkTagSelect" class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">-- เลือก Tag --</option>
                        <?php foreach ($allTags as $tag): ?>
                            <option value="<?= $tag['id'] ?>"><?= htmlspecialchars($tag['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="bulkAssignTag()"
                        class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                        <i class="fas fa-plus mr-1"></i>เพิ่ม Tag
                    </button>
                    <button type="button" onclick="bulkRemoveTag()"
                        class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 text-sm">
                        <i class="fas fa-minus mr-1"></i>ลบ Tag
                    </button>
                </div>
            </div>
            <button type="button" onclick="clearSelection()" class="chip-glass">
                <i class="fas fa-times mr-1"></i>ยกเลิก
            </button>
        </div>
    </div>

    <div class="users-card overflow-hidden" style="padding: 0;">
        <table class="table-glass">
            <thead>
                <tr>
                    <th class="text-center">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()"
                            class="w-4 h-4 rounded border-gray-300 focus:ring-green-500">
                    </th>
                    <th>ผู้ใช้</th>
                    <th>Tags</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">ข้อความ</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($users as $user): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-4 text-center">
                            <input type="checkbox" class="user-checkbox w-4 h-4 rounded border-gray-300 focus:ring-green-500"
                                data-user-id="<?= $user['id'] ?>" onchange="updateSelection()">
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center">
                                <img src="<?php echo $user['picture_url'] ?: 'https://via.placeholder.com/40'; ?>"
                                    class="w-10 h-10 rounded-full object-cover mr-3">
                                <div>
                                    <p class="font-medium"><?php echo htmlspecialchars($user['display_name'] ?: 'Unknown'); ?>
                                    </p>
                                    <p class="text-xs text-gray-500"><?php echo substr($user['line_user_id'], 0, 15); ?>...</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($user['tags']): ?>
                                <?php foreach (explode(', ', $user['tags']) as $tagName): ?>
                                    <span
                                        class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-xs"><?php echo htmlspecialchars($tagName); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-gray-400 text-xs">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="font-medium"><?php echo number_format($user['message_count'] ?? 0); ?></span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php if ($user['is_blocked']): ?>
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs">Blocked</span>
                            <?php else: ?>
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex justify-center gap-2">
                                <a href="user-detail.php?id=<?php echo $user['id']; ?>"
                                    class="text-green-500 hover:text-green-700" title="ดูรายละเอียด">
                                    <i class="fas fa-user"></i>
                                </a>
                                <a href="messages.php?user=<?php echo $user['id']; ?>" class="text-blue-500 hover:text-blue-700"
                                    title="ดูแชท">
                                    <i class="fas fa-comments"></i>
                                </a>
                                <button
                                    onclick="openTagModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['display_name'] ?? '', ENT_QUOTES); ?>')"
                                    class="text-purple-500 hover:text-purple-700" title="จัดการ Tags">
                                    <i class="fas fa-tags"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center" style="color: rgba(255,255,255,0.4);">
                            <i class="fas fa-users text-4xl mb-3 block" style="color: rgba(255,255,255,0.2);"></i>
                            <p>ไม่พบผู้ใช้</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination-glass">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="current"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($activeTab === 'line'): ?>
    <!-- Tag Modal -->
    <div id="tagModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center">
        <div class="modal-glass w-full max-w-md mx-4">
            <div class="p-6 border-b flex justify-between items-center" style="border-color: rgba(255,255,255,0.1);">
                <div>
                    <h3 class="text-xl font-semibold text-white">🏷️ จัดการ Tags</h3>
                    <p class="text-sm" style="color: rgba(255,255,255,0.5);" id="tagModalUserName"></p>
                </div>
                <button onclick="closeTagModal()" class="modal-close-glass">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2" style="color: rgba(255,255,255,0.6);">Tags ปัจจุบัน</label>
                    <div id="currentTags" class="flex flex-wrap gap-2 min-h-[32px] p-2 rounded-lg" style="background: rgba(255,255,255,0.05);">
                        <span class="text-sm" style="color: rgba(255,255,255,0.4);">กำลังโหลด...</span>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2" style="color: rgba(255,255,255,0.6);">เพิ่ม Tag</label>
                    <div class="flex gap-2">
                        <select id="tagSelect" class="input-glass flex-1">
                            <?php foreach ($allTags as $tag): ?>
                                <option value="<?php echo $tag['id']; ?>"
                                    data-color="<?php echo htmlspecialchars($tag['color'] ?? '#3B82F6'); ?>">
                                    <?php echo htmlspecialchars($tag['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="assignTag()"
                            class="btn-glass-success btn-glass-sm">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="mt-4 pt-4 border-t" style="border-color: rgba(255,255,255,0.1);">
                    <button type="button" onclick="closeTagModal()"
                        class="w-full btn-glass-secondary btn-glass-sm">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        var currentUserId = null;

        function openTagModal(userId, userName) {
            currentUserId = userId;
            document.getElementById('tagModalUserName').textContent = userName;
            document.getElementById('tagModal').classList.remove('hidden');
            document.getElementById('tagModal').classList.add('flex');
            loadUserTags(userId);
        }

        function closeTagModal() {
            document.getElementById('tagModal').classList.add('hidden');
            document.getElementById('tagModal').classList.remove('flex');
            currentUserId = null;
        }

        async function loadUserTags(userId) {
            const container = document.getElementById('currentTags');
            container.innerHTML = '<span class="text-gray-400 text-sm">กำลังโหลด...</span>';

            try {
                const response = await fetch('api/ajax_handler.php?action=get_user_tags&user_id=' + userId);
                const result = await response.json();

                if (result.success) {
                    if (result.tags.length === 0) {
                        container.innerHTML = '<span class="text-gray-400 text-sm">ยังไม่มี Tags</span>';
                    } else {
                        container.innerHTML = result.tags.map(tag =>
                            '<span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-sm" style="background-color: ' + (tag.color || '#3B82F6') + '20; color: ' + (tag.color || '#3B82F6') + '">' +
                            '<span class="w-2 h-2 rounded-full" style="background-color: ' + (tag.color || '#3B82F6') + '"></span>' +
                            tag.name +
                            '<button onclick="removeTag(' + tag.id + ')" class="ml-1 hover:opacity-70">×</button>' +
                            '</span>'
                        ).join('');
                    }
                } else {
                    container.innerHTML = '<span class="text-red-500 text-sm">' + (result.error || 'เกิดข้อผิดพลาด') + '</span>';
                }
            } catch (e) {
                container.innerHTML = '<span class="text-red-500 text-sm">เกิดข้อผิดพลาด: ' + e.message + '</span>';
            }
        }

        async function assignTag() {
            if (!currentUserId) return;

            const tagId = document.getElementById('tagSelect').value;

            try {
                const formData = new FormData();
                formData.append('action', 'assign_tag');
                formData.append('user_id', currentUserId);
                formData.append('tag_id', tagId);

                const response = await fetch('api/ajax_handler.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    loadUserTags(currentUserId);
                } else {
                    alert(result.error || 'เกิดข้อผิดพลาด');
                }
            } catch (e) {
                alert('เกิดข้อผิดพลาด: ' + e.message);
            }
        }

        async function removeTag(tagId) {
            if (!currentUserId) return;

            try {
                const formData = new FormData();
                formData.append('action', 'remove_tag');
                formData.append('user_id', currentUserId);
                formData.append('tag_id', tagId);

                const response = await fetch('api/ajax_handler.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    loadUserTags(currentUserId);
                } else {
                    alert(result.error || 'เกิดข้อผิดพลาด');
                }
            } catch (e) {
                alert('เกิดข้อผิดพลาด: ' + e.message);
            }
        }

        // ==================== Bulk Actions ====================
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateSelection();
        }

        function updateSelection() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCount').textContent = count;

            const bulkBar = document.getElementById('bulkActionsBar');
            if (count > 0) {
                bulkBar.classList.remove('hidden');
            } else {
                bulkBar.classList.add('hidden');
            }

            // Update select all checkbox
            const allCheckboxes = document.querySelectorAll('.user-checkbox');
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = allCheckboxes.length > 0 && allCheckboxes.length === checkboxes.length;
        }

        function clearSelection() {
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }

        function getSelectedUserIds() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.dataset.userId);
        }

        async function bulkAssignTag() {
            const tagId = document.getElementById('bulkTagSelect').value;
            if (!tagId) {
                alert('กรุณาเลือก Tag');
                return;
            }

            const userIds = getSelectedUserIds();
            if (userIds.length === 0) {
                alert('กรุณาเลือกผู้ใช้');
                return;
            }

            if (!confirm(`ต้องการเพิ่ม Tag ให้ ${userIds.length} คน ใช่หรือไม่?`)) return;

            try {
                const response = await fetch('api/ajax_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'bulk_assign_tag',
                        user_ids: userIds,
                        tag_id: tagId
                    })
                });

                const result = await response.json();
                if (result.success) {
                    alert(`เพิ่ม Tag สำเร็จ ${result.count || userIds.length} คน`);
                    location.reload();
                } else {
                    alert(result.error || 'เกิดข้อผิดพลาด');
                }
            } catch (e) {
                alert('เกิดข้อผิดพลาด: ' + e.message);
            }
        }

        async function bulkRemoveTag() {
            const tagId = document.getElementById('bulkTagSelect').value;
            if (!tagId) {
                alert('กรุณาเลือก Tag');
                return;
            }

            const userIds = getSelectedUserIds();
            if (userIds.length === 0) {
                alert('กรุณาเลือกผู้ใช้');
                return;
            }

            if (!confirm(`ต้องการลบ Tag จาก ${userIds.length} คน ใช่หรือไม่?`)) return;

            try {
                const response = await fetch('api/ajax_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'bulk_remove_tag',
                        user_ids: userIds,
                        tag_id: tagId
                    })
                });

                const result = await response.json();
                if (result.success) {
                    alert(`ลบ Tag สำเร็จ ${result.count || userIds.length} คน`);
                    location.reload();
                } else {
                    alert(result.error || 'เกิดข้อผิดพลาด');
                }
            } catch (e) {
                alert('เกิดข้อผิดพลาด: ' + e.message);
            }
        }
    </script>
<?php endif; ?>

</div> <!-- Close users-page -->

<?php require_once 'includes/footer.php'; ?>