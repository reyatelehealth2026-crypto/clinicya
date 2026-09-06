<?php
/**
 * Users Management - รายชื่อผู้ใช้/ลูกค้า
 * แสดงทุก follow = 1 customer
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Users';

require_once 'includes/header.php';

// Archetype A — List/CRUD partials
require_once 'includes/components/tabs.php';
require_once 'includes/components/page-header.php';
require_once 'includes/components/toolbar.php';
require_once 'includes/components/data-table.php';
require_once 'includes/components/empty-state.php';
require_once 'includes/components/pagination.php';
require_once 'includes/components/modal.php';
require_once 'includes/components/toast.php';

// Get filter parameters
$tagFilter = isset($_GET['tag']) ? (int) $_GET['tag'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$odooUiEnabled = defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true;
$activeTab = $_GET['tab'] ?? 'line';
$allowedUserTabs = $odooUiEnabled ? ['line', 'odoo'] : ['line'];
if (!in_array($activeTab, $allowedUserTabs, true)) {
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

// Tier filter — tier is derived from the balance, not stored, so match the
// points range that qualifies for it.
if ($tierFilter) {
    $tierRange = null;
    try {
        require_once __DIR__ . '/classes/TierService.php';
        $tierService = new TierService($db, $_SESSION['current_bot_id'] ?? null);
        $tierRange = $tierService->pointsRangeForTier($tierFilter);
    } catch (Exception $e) {
        error_log('users.php: tier range lookup failed - ' . $e->getMessage());
    }

    if ($tierRange === null) {
        // Unknown tier matches nobody, rather than everybody.
        $whereConditions[] = "1 = 0";
    } else {
        $whereConditions[] = "COALESCE(u.available_points, 0) >= ?";
        $params[] = $tierRange['min'];
        if ($tierRange['max'] !== null) {
            $whereConditions[] = "COALESCE(u.available_points, 0) < ?";
            $params[] = $tierRange['max'];
        }
    }
}

// Points filter
if ($pointsFilter) {
    switch ($pointsFilter) {
        case '0-100':
            $whereConditions[] = "COALESCE(u.available_points, 0) BETWEEN 0 AND 100";
            break;
        case '100-500':
            $whereConditions[] = "COALESCE(u.available_points, 0) BETWEEN 100 AND 500";
            break;
        case '500-1000':
            $whereConditions[] = "COALESCE(u.available_points, 0) BETWEEN 500 AND 1000";
            break;
        case '1000+':
            $whereConditions[] = "COALESCE(u.available_points, 0) > 1000";
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

// Active filter count (used by LINE-tab toolbar badge)
$activeFilters = array_filter([$tierFilter, $pointsFilter, $activityFilter, $purchaseFilter, $statusFilter, $tagFilter]);
?>

<?php
// Archetype A component styles — emitted once, design-tokens driven.
echo getPageHeaderStyles();
echo getToolbarStyles();
echo getDataTableStyles();
echo getEmptyStateStyles();
echo getPaginationStyles();
echo getModalStyles();
echo getToastStyles();
?>

<?php
// Page header — title, dynamic subtitle, primary action (Odoo Dashboard link).
$headerSubtitle = ($activeTab === 'odoo')
    ? ('ลูกค้า Odoo ที่เชื่อมแล้ว ' . number_format($odooSummary['linked_total'] ?? 0) . ' ราย')
    : ('ทั้งหมด ' . number_format($totalUsers) . ' คน');

$headerPrimary = null;
if ($odooUiEnabled) {
    $headerPrimary = [
        'type' => 'link',
        'href' => 'odoo-dashboard.php',
        'label' => 'Odoo Dashboard',
        'icon' => 'fas fa-chart-line',
        'variant' => 'primary',
    ];
}
echo renderPageHeader('Customers', $headerSubtitle, $headerPrimary);
?>

<?php
// Tab strip — LINE / Odoo. Reuses the design-token-driven tabs partial.
$userTabs = ['line' => ['label' => 'LINE Users', 'icon' => 'fab fa-line']];
if ($odooUiEnabled) {
    $userTabs['odoo'] = ['label' => 'Odoo Customers', 'icon' => 'fas fa-link'];
}
echo renderTabs($userTabs, $activeTab, [
    'style' => 'pills',
    'size' => 'md',
    'preserveParams' => ['search', 'tag', 'tier', 'points', 'activity', 'purchase', 'status', 'odoo_search'],
]);
?>

<?php if ($activeTab === 'odoo'): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 mt-4">
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-sm text-gray-500">ออเดอร์ 30 วัน</p>
            <p class="text-2xl font-bold text-indigo-600"><?php echo number_format((int) ($odooSummary['orders_30d'] ?? 0)); ?></p>
            <p class="text-xs text-gray-400 mt-1">ยอดขาย ฿<?php echo number_format((float) ($odooSummary['spend_30d'] ?? 0), 2); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-sm text-gray-500">ยอดค้างชำระ</p>
            <p class="text-2xl font-bold text-red-600">฿<?php echo number_format((float) ($odooSummary['total_due'] ?? 0), 2); ?></p>
            <p class="text-xs text-gray-400 mt-1">เกินกำหนด ฿<?php echo number_format((float) ($odooSummary['overdue_amount'] ?? 0), 2); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-sm text-gray-500">วงเงินเครดิต</p>
            <p class="text-2xl font-bold text-blue-600">฿<?php echo number_format((float) ($odooSummary['credit_limit'] ?? 0), 2); ?></p>
            <p class="text-xs text-gray-400 mt-1">ใช้ไป ฿<?php echo number_format((float) ($odooSummary['credit_used'] ?? 0), 2); ?></p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-sm text-gray-500">เครดิตคงเหลือ</p>
            <p class="text-2xl font-bold text-emerald-600">฿<?php echo number_format((float) ($odooSummary['credit_remaining'] ?? 0), 2); ?></p>
            <p class="text-xs text-gray-400 mt-1">
                อัปเดตล่าสุด <?php echo !empty($odooSummary['latest_order_at']) ? date('d/m/Y H:i', strtotime($odooSummary['latest_order_at'])) : '-'; ?>
            </p>
        </div>
    </div>

    <?php
    // Odoo tab toolbar — single search field + reset link + meta caption.
    echo renderToolbar([
        'hiddenFields' => ['tab' => 'odoo'],
        'search' => [
            'name' => 'odoo_search',
            'value' => $odooSearch,
            'placeholder' => 'ชื่อ, รหัสลูกค้า, Partner ID, LINE ID...',
        ],
        'resetHref' => $odooTabUrl,
        'meta' => 'ลูกค้าที่เชื่อมแล้ว <b>' . number_format((int) $odooTotal) . '</b> ราย · ยังไม่เชื่อม LINE <b>' . number_format((int) $odooUnlinkedTotal) . '</b> ราย · ทั้งหมด <b>' . number_format((int) ($odooTotal + $odooUnlinkedTotal)) . '</b> ราย',
    ]);
    ?>

    <?php
    // Odoo linked customers — data table with rich row renderers.
    $odooLinkedColumns = [
        [
            'key' => 'customer',
            'label' => 'ลูกค้า Odoo',
            'render' => function ($c) {
                $name = htmlspecialchars($c['odoo_partner_name'] ?? $c['customer_name'] ?? 'ไม่ระบุ');
                $code = htmlspecialchars($c['odoo_customer_code'] ?? '-');
                $pid = htmlspecialchars($c['odoo_partner_id'] ?? '-');
                $via = htmlspecialchars($c['linked_via'] ?? 'linked');
                return '<div style="display:flex;align-items:center;gap:12px;">'
                    . '<div style="width:40px;height:40px;border-radius:9999px;background:var(--color-primary-50);display:flex;align-items:center;justify-content:center;color:var(--color-primary-600);"><i class="fas fa-user"></i></div>'
                    . '<div>'
                    . '<p style="font-weight:500;color:var(--color-dark-800);margin:0;">' . $name . '</p>'
                    . '<p style="font-size:12px;color:var(--color-dark-500);margin:0;">' . $code . ' · #' . $pid . '</p>'
                    . '<div style="margin-top:4px;"><span style="display:inline-block;padding:2px 8px;border-radius:9999px;font-size:11px;background:var(--color-primary-50);color:var(--color-primary-600);">' . $via . '</span></div>'
                    . '</div></div>';
            },
        ],
        [
            'key' => 'contact',
            'label' => 'การติดต่อ',
            'render' => function ($c) {
                $line = htmlspecialchars($c['line_user_id'] ?? '-');
                $phone = htmlspecialchars($c['phone'] ?? $c['odoo_phone'] ?? '-');
                $email = htmlspecialchars($c['email'] ?? $c['odoo_email'] ?? '-');
                return '<div style="display:flex;flex-direction:column;gap:4px;font-size:13px;">'
                    . '<div><span style="color:var(--color-dark-500);">LINE:</span> <span style="font-family:var(--font-mono);font-size:12px;color:var(--color-dark-700);">' . $line . '</span></div>'
                    . '<div><span style="color:var(--color-dark-500);">โทร:</span> <span style="color:var(--color-dark-700);">' . $phone . '</span></div>'
                    . '<div><span style="color:var(--color-dark-500);">อีเมล:</span> <span style="color:var(--color-dark-700);">' . $email . '</span></div>'
                    . '</div>';
            },
        ],
        [
            'key' => 'orders',
            'label' => 'ออเดอร์',
            'render' => function ($c) {
                $latest = htmlspecialchars($c['latest_order_name'] ?? '-');
                $count30 = number_format((int) ($c['orders_count_30d'] ?? 0));
                $spend30 = number_format((float) ($c['spend_30d'] ?? 0), 2);
                return '<div style="display:flex;flex-direction:column;gap:4px;font-size:13px;">'
                    . '<div><span style="color:var(--color-dark-500);">ล่าสุด:</span> <span style="color:var(--color-dark-700);">' . $latest . '</span></div>'
                    . '<div><span style="color:var(--color-dark-500);">ออเดอร์ 30 วัน:</span> <span style="color:var(--color-dark-700);">' . $count30 . '</span></div>'
                    . '<div><span style="color:var(--color-dark-500);">ยอด 30 วัน:</span> <span style="color:var(--color-emerald-600);font-weight:500;">฿' . $spend30 . '</span></div>'
                    . '</div>';
            },
        ],
        [
            'key' => 'credit',
            'label' => 'เครดิต/หนี้',
            'render' => function ($c) {
                $limit = number_format((float) ($c['credit_limit'] ?? 0), 2);
                $used = number_format((float) ($c['credit_used'] ?? 0), 2);
                $due = number_format((float) ($c['total_due'] ?? 0), 2);
                return '<div style="display:flex;flex-direction:column;gap:4px;font-size:13px;">'
                    . '<div><span style="color:var(--color-dark-500);">เครดิต:</span> <span style="color:var(--color-dark-700);">฿' . $limit . '</span></div>'
                    . '<div><span style="color:var(--color-dark-500);">ใช้ไป:</span> <span style="color:var(--color-dark-700);">฿' . $used . '</span></div>'
                    . '<div><span style="color:var(--color-dark-500);">ค้างชำระ:</span> <span style="color:var(--color-rose-600);font-weight:500;">฿' . $due . '</span></div>'
                    . '</div>';
            },
        ],
        [
            'key' => 'actions',
            'label' => 'Actions',
            'align' => 'center',
            'render' => function ($c) {
                $line = htmlspecialchars($c['line_user_id'] ?? '', ENT_QUOTES);
                $uid = (int) ($c['user_id'] ?? 0);
                $accountId = htmlspecialchars($c['user_line_account_id'] ?? '', ENT_QUOTES);
                $pid = htmlspecialchars($c['odoo_partner_id'] ?? '', ENT_QUOTES);
                $html = '<div style="display:flex;flex-direction:column;align-items:center;gap:8px;">'
                    . '<button type="button" onclick="openOdooDetailModal(\'' . $line . '\', \'' . $uid . '\', \'' . $accountId . '\', \'' . $pid . '\')" class="data-table-row-action" style="width:auto;padding:6px 12px;background:var(--color-primary-50);color:var(--color-primary-700);"><i class="fas fa-eye"></i> รายละเอียด Odoo</button>';
                if ($uid) {
                    $html .= '<a href="user-detail.php?id=' . $uid . '" class="data-table-row-action" style="width:auto;padding:6px 12px;background:var(--color-emerald-50);color:var(--color-emerald-700);"><i class="fas fa-user"></i> ดูโปรไฟล์</a>';
                } else {
                    $html .= '<span style="font-size:11px;color:var(--color-slate-400);">ยังไม่มีโปรไฟล์ LINE</span>';
                }
                $html .= '</div>';
                return $html;
            },
        ],
    ];

    echo renderDataTable($odooLinkedColumns, $odooCustomers, [
        'emptyContent' => renderEmptyState('fas fa-link', 'ยังไม่มีลูกค้า Odoo ที่เชื่อมต่อ'),
    ]);
    ?>

    <?php if ($odooTotalPages > 1): ?>
        <div style="margin-top:16px;">
            <?php
            $odooBaseUrl = '?' . http_build_query(array_merge($_GET, ['tab' => 'odoo'])) . '&';
            // Strip any embedded odoo_page=... fragment so renderPagination appends a clean one.
            $odooBaseUrl = preg_replace('/odoo_page=\d+&?/', '', $odooBaseUrl);
            // renderPagination uses `page` param; remap by aliasing.
            $rendered = renderPagination($odooPage, $odooTotalPages, $odooPerPage, $odooBaseUrl, [
                'total' => $odooTotal,
                'offset' => $odooOffset,
            ]);
            echo str_replace('page=', 'odoo_page=', $rendered);
            ?>
        </div>
    <?php endif; ?>

    <div style="margin-top:32px;" class="data-table-card">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid var(--color-slate-200);">
            <div>
                <h3 style="font-weight:600;color:var(--color-dark-800);margin:0;">ลูกค้า Odoo (ยังไม่เชื่อม LINE)</h3>
                <p style="font-size:12px;color:var(--color-dark-500);margin:4px 0 0 0;">ดึงจากออเดอร์ Odoo ที่มีรหัสลูกค้า</p>
            </div>
            <span style="font-size:12px;color:var(--color-dark-500);">ทั้งหมด <?= number_format((int) $odooUnlinkedTotal) ?> ราย</span>
        </div>

        <?php
        $odooUnlinkedColumns = [
            [
                'key' => 'customer',
                'label' => 'ลูกค้า Odoo',
                'render' => function ($c) {
                    $name = htmlspecialchars($c['customer_name'] ?? 'ไม่ระบุ');
                    $ref = htmlspecialchars($c['customer_ref'] ?? '-');
                    $pid = htmlspecialchars($c['odoo_partner_id'] ?? '-');
                    return '<p style="font-weight:500;color:var(--color-dark-800);margin:0;">' . $name . '</p>'
                        . '<p style="font-size:12px;color:var(--color-dark-500);margin:2px 0 0 0;">' . $ref . ' · #' . $pid . '</p>';
                },
            ],
            [
                'key' => 'orders_summary',
                'label' => 'สรุปออเดอร์',
                'render' => function ($c) {
                    $orders = number_format((int) ($c['orders_total'] ?? 0));
                    $spend = number_format((float) ($c['spend_total'] ?? 0), 2);
                    return '<div style="font-size:13px;">'
                        . '<div><span style="color:var(--color-dark-500);">ออเดอร์:</span> <span style="color:var(--color-dark-700);">' . $orders . '</span></div>'
                        . '<div><span style="color:var(--color-dark-500);">ยอดรวม:</span> <span style="color:var(--color-emerald-600);font-weight:500;">฿' . $spend . '</span></div>'
                        . '</div>';
                },
            ],
            [
                'key' => 'latest',
                'label' => 'ล่าสุด',
                'render' => function ($c) {
                    return !empty($c['latest_order_at']) ? date('d/m/Y H:i', strtotime($c['latest_order_at'])) : '-';
                },
            ],
            [
                'key' => 'actions',
                'label' => 'Actions',
                'align' => 'center',
                'render' => function ($c) use ($currentBotId) {
                    $pid = htmlspecialchars($c['odoo_partner_id'] ?? '', ENT_QUOTES);
                    $name = htmlspecialchars($c['customer_name'] ?? '', ENT_QUOTES);
                    $ref = htmlspecialchars($c['customer_ref'] ?? '', ENT_QUOTES);
                    $orders = (int) ($c['orders_total'] ?? 0);
                    $spend = (float) ($c['spend_total'] ?? 0);
                    $latest = htmlspecialchars($c['latest_order_at'] ?? '', ENT_QUOTES);
                    $acct = htmlspecialchars($currentBotId ?? '', ENT_QUOTES);
                    return '<div style="display:flex;flex-direction:column;align-items:center;gap:8px;">'
                        . '<button type="button" onclick="openOdooDetailModal(\'\', \'\', \'\', \'' . $pid . '\', \'' . $name . '\', \'' . $ref . '\', \'' . $orders . '\', \'' . $spend . '\', \'' . $latest . '\')" class="data-table-row-action" style="width:100%;padding:6px 12px;background:var(--color-primary-50);color:var(--color-primary-700);"><i class="fas fa-eye"></i> ดูข้อมูล Odoo</button>'
                        . '<button type="button" onclick="openOdooLinkModal({lineUserId:\'\',customerCode:\'' . $ref . '\',phone:\'\',partnerId:\'' . $pid . '\',customerName:\'' . $name . '\',lineAccountId:\'' . $acct . '\'})" class="data-table-row-action" style="width:100%;padding:6px 12px;background:var(--color-amber-100);color:var(--color-amber-700);"><i class="fas fa-link"></i> เชื่อมลูกค้า</button>'
                        . '</div>';
                },
            ],
        ];
        echo renderDataTable($odooUnlinkedColumns, $odooUnlinkedCustomers, [
            'emptyContent' => renderEmptyState('fas fa-users', 'ยังไม่พบลูกค้า Odoo ที่ยังไม่เชื่อม LINE'),
        ]);
        ?>
    </div>

    <?php if ($odooUnlinkedPages > 1): ?>
        <div style="margin-top:16px;">
            <?php
            $odooUnlinkedBaseUrl = '?' . http_build_query(array_merge($_GET, ['tab' => 'odoo'])) . '&';
            $odooUnlinkedBaseUrl = preg_replace('/odoo_unlinked_page=\d+&?/', '', $odooUnlinkedBaseUrl);
            $renderedU = renderPagination($odooUnlinkedPage, $odooUnlinkedPages, $odooUnlinkedPerPage, $odooUnlinkedBaseUrl, [
                'total' => $odooUnlinkedTotal,
                'offset' => $odooUnlinkedOffset,
            ]);
            echo str_replace('page=', 'odoo_unlinked_page=', $renderedU);
            ?>
        </div>
    <?php endif; ?>

    <?php
    // Odoo Link Modal — body + footer composed inline.
    $odooLinkBody = <<<'HTML'
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
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
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
        <div id="odooLinkAlert" class="hidden p-3 rounded-lg text-sm mt-4"></div>
        <div id="odooLinkResult" class="hidden mt-4 pt-4 border-t">
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
HTML;
    $odooLinkFooter = '<div style="display:flex;align-items:center;justify-content:space-between;width:100%;">'
        . '<div style="font-size:12px;color:var(--color-dark-500);">จำเป็นต้องกรอก Customer Code + เบอร์โทร เพื่อยืนยันตัวตน</div>'
        . '<button id="odooLinkSubmit" type="button" class="page-header-action page-header-action-primary"><span>เชื่อมลูกค้า</span></button>'
        . '</div>';
    echo renderModal('odooLinkModal', 'เชื่อมลูกค้า Odoo กับ LINE', $odooLinkBody, $odooLinkFooter, ['size' => 'md']);
    ?>

    <?php
    // Odoo Detail Modal — body is filled dynamically by JS.
    $odooDetailBody = '<div id="odooDetailContent" style="min-height:300px;"><div style="color:var(--color-slate-400);text-align:center;padding:40px 0;">กำลังโหลดข้อมูล Odoo...</div></div>';
    echo renderModal('odooDetailModal', 'รายละเอียดลูกค้า Odoo', $odooDetailBody, null, ['size' => 'xl']);
    ?>

    <script>
        let currentOdooLinkPayload = null;

        function openOdooLinkModal(payload = {}) {
            currentOdooLinkPayload = payload;
            if (typeof openModalShell === 'function') openModalShell('odooLinkModal');
            document.getElementById('odooLinkCustomerCode').value = payload.customerCode || '';
            document.getElementById('odooLinkPhone').value = payload.phone || '';
            document.getElementById('odooLinkLineUserId').value = payload.lineUserId || '';
            document.getElementById('odooLinkLineAccount').value = payload.lineAccountId || '-';
            document.getElementById('odooLinkAlert').classList.add('hidden');
            document.getElementById('odooLinkResult').classList.add('hidden');

            const submitBtn = document.getElementById('odooLinkSubmit');
            submitBtn.onclick = () => submitOdooLink();
        }

        function closeOdooLinkModal() {
            if (typeof closeModalShell === 'function') closeModalShell('odooLinkModal');
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
            const content = document.getElementById('odooDetailContent');
            if (typeof openModalShell === 'function') openModalShell('odooDetailModal');
            content.innerHTML = '<div style="color:var(--color-slate-400);text-align:center;padding:40px 0;"><i class="fas fa-spinner fa-spin mr-2"></i>กำลังโหลดข้อมูล Odoo...</div>';

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
            if (typeof closeModalShell === 'function') closeModalShell('odooDetailModal');
        }
    </script>
<?php else: ?>
    <!--
        LINE-tab advanced filter form — kept as the original Tailwind form (collapsible
        with 6 selects + bulk-actions wiring). Per Wave 2 constraints this section is
        left intact because its JS interactions (toggleAdvancedFilters, advancedFilters
        collapse + filter-count badge) are entangled with the rest of the page.
    -->
    <div class="bg-white rounded-xl shadow p-4 mb-6 mt-4">
        <form method="GET" id="filterForm">
            <input type="hidden" name="tab" value="line">
            <!-- Basic Search Row -->
            <div class="flex flex-wrap gap-4 items-end mb-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium mb-1">ค้นหา</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="ชื่อ, เบอร์โทร, LINE ID..."
                        class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                </div>
                <button type="submit" class="px-6 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <i class="fas fa-search mr-1"></i>ค้นหา
                </button>
                <button type="button" onclick="toggleAdvancedFilters()"
                    class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                    <i class="fas fa-filter mr-1"></i>ตัวกรอง
                    <?php if (count($activeFilters) > 0): ?>
                        <span
                            class="ml-1 px-2 py-0.5 bg-green-500 text-white text-xs rounded-full"><?= count($activeFilters) ?></span>
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
            <button type="button" onclick="clearSelection()" class="px-3 py-2 border rounded-lg hover:bg-white text-sm">
                <i class="fas fa-times mr-1"></i>ยกเลิก
            </button>
        </div>
    </div>

    <?php
    // LINE users — data table with checkbox column + per-row Action buttons.
    $lineUserColumns = [
        [
            'key' => 'user',
            'label' => 'ผู้ใช้',
            'render' => function ($u) {
                $name = htmlspecialchars($u['display_name'] ?: 'Unknown');
                $lineId = htmlspecialchars(substr($u['line_user_id'] ?? '', 0, 15));
                $pic = $u['picture_url'] ?: 'https://via.placeholder.com/40';
                return '<div style="display:flex;align-items:center;gap:12px;">'
                    . '<img src="' . htmlspecialchars($pic) . '" style="width:40px;height:40px;border-radius:9999px;object-fit:cover;" alt="">'
                    . '<div><p style="font-weight:500;margin:0;">' . $name . '</p>'
                    . '<p style="font-size:12px;color:var(--color-dark-500);margin:0;">' . $lineId . '...</p></div>'
                    . '</div>';
            },
        ],
        [
            'key' => 'tags',
            'label' => 'Tags',
            'render' => function ($u) {
                if (empty($u['tags'])) {
                    return '<span style="color:var(--color-slate-400);font-size:12px;">-</span>';
                }
                $out = '';
                foreach (explode(', ', $u['tags']) as $tagName) {
                    $out .= '<span style="display:inline-block;padding:2px 8px;background:rgba(59,130,246,0.1);color:#2563eb;border-radius:9999px;font-size:11px;margin-right:4px;">' . htmlspecialchars($tagName) . '</span>';
                }
                return $out;
            },
        ],
        [
            'key' => 'messages',
            'label' => 'ข้อความ',
            'align' => 'center',
            'render' => function ($u) {
                return '<span style="font-weight:500;">' . number_format($u['message_count'] ?? 0) . '</span>';
            },
        ],
        [
            'key' => 'status',
            'label' => 'สถานะ',
            'align' => 'center',
            'render' => function ($u) {
                if (!empty($u['is_blocked'])) {
                    return '<span style="padding:2px 8px;background:var(--color-rose-100);color:var(--color-rose-700);border-radius:9999px;font-size:11px;">Blocked</span>';
                }
                return '<span style="padding:2px 8px;background:var(--color-emerald-100);color:var(--color-emerald-700);border-radius:9999px;font-size:11px;">Active</span>';
            },
        ],
        [
            'key' => 'actions',
            'label' => 'Actions',
            'align' => 'center',
            'render' => function ($u) {
                $uid = (int) $u['id'];
                $name = htmlspecialchars($u['display_name'] ?? '', ENT_QUOTES);
                return '<div style="display:inline-flex;gap:4px;">'
                    . '<a href="user-detail.php?id=' . $uid . '" class="data-table-row-action" title="ดูรายละเอียด"><i class="fas fa-user"></i></a>'
                    . '<a href="messages.php?user=' . $uid . '" class="data-table-row-action" title="ดูแชท"><i class="fas fa-comments"></i></a>'
                    . '<button type="button" onclick="openTagModal(' . $uid . ', \'' . $name . '\')" class="data-table-row-action" title="จัดการ Tags"><i class="fas fa-tags"></i></button>'
                    . '</div>';
            },
        ],
    ];

    echo renderDataTable($lineUserColumns, $users, [
        'selectable' => true,
        'selectAllId' => 'selectAll',
        'rowCheckboxClass' => 'user-checkbox',
        'selectOnChange' => 'updateSelection()',
        'selectAllOnChange' => 'toggleSelectAll()',
        'emptyContent' => renderEmptyState('fas fa-users', 'ไม่พบผู้ใช้'),
    ]);
    ?>

    <?php if ($totalPages > 1): ?>
        <div style="margin-top:16px;">
            <?php
            $lineBaseUrl = '?' . http_build_query(array_merge($_GET, ['tab' => 'line'])) . '&';
            $lineBaseUrl = preg_replace('/(?:^|&)page=\d+&?/', '&', $lineBaseUrl);
            $lineBaseUrl = ltrim($lineBaseUrl, '&');
            if (substr($lineBaseUrl, -1) !== '&' && substr($lineBaseUrl, -1) !== '?') {
                $lineBaseUrl .= '&';
            }
            echo renderPagination($page, $totalPages, $perPage, $lineBaseUrl, [
                'total' => (int) $totalUsers,
                'offset' => $offset,
            ]);
            ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($activeTab === 'line'): ?>
    <?php
    // Tag Modal — modal shell, body rendered inline.
    $tagModalBody = '<p id="tagModalUserName" style="color:var(--color-dark-500);font-size:14px;margin:-12px 0 16px 0;"></p>'
        . '<div style="margin-bottom:16px;">'
        . '<label style="display:block;font-size:14px;font-weight:500;margin-bottom:8px;">Tags ปัจจุบัน</label>'
        . '<div id="currentTags" style="display:flex;flex-wrap:wrap;gap:8px;min-height:32px;padding:8px;background:var(--color-slate-50);border-radius:8px;">'
        . '<span style="color:var(--color-slate-400);font-size:14px;">กำลังโหลด...</span>'
        . '</div>'
        . '</div>'
        . '<div>'
        . '<label style="display:block;font-size:14px;font-weight:500;margin-bottom:8px;">เพิ่ม Tag</label>'
        . '<div style="display:flex;gap:8px;">'
        . '<select id="tagSelect" style="flex:1;padding:8px 16px;border:1px solid var(--color-slate-200);border-radius:8px;">';
    foreach ($allTags as $tag) {
        $tagModalBody .= '<option value="' . (int) $tag['id'] . '" data-color="' . htmlspecialchars($tag['color'] ?? '#3B82F6') . '">' . htmlspecialchars($tag['name']) . '</option>';
    }
    $tagModalBody .= '</select>'
        . '<button type="button" onclick="assignTag()" class="page-header-action page-header-action-success" style="padding:8px 16px;"><i class="fas fa-plus"></i></button>'
        . '</div>'
        . '</div>';
    $tagModalFooter = '<button type="button" data-modal-close="tagModal" class="toolbar-bulk-btn toolbar-bulk-btn-neutral">ปิด</button>';
    echo renderModal('tagModal', 'จัดการ Tags', $tagModalBody, $tagModalFooter, ['size' => 'sm']);
    ?>

    <script>
        var currentUserId = null;

        function openTagModal(userId, userName) {
            currentUserId = userId;
            document.getElementById('tagModalUserName').textContent = userName;
            if (typeof openModalShell === 'function') openModalShell('tagModal');
            loadUserTags(userId);
        }

        function closeTagModal() {
            if (typeof closeModalShell === 'function') closeModalShell('tagModal');
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

<?php echo renderToastContainer(); ?>

<?php require_once 'includes/footer.php'; ?>
