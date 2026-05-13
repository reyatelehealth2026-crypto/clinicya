<?php
/**
 * Shop - จัดการคำสั่งซื้อ/รายการ
 * V2.5 - รองรับทั้ง orders และ transactions
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';
require_once __DIR__ . '/../includes/shop-data-source.php';

$db             = Database::getInstance()->getConnection();
$activityLogger = ActivityLogger::getInstance($db);
$pageTitle      = 'รายการ/คำสั่งซื้อ';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentBotId    = $_SESSION['current_bot_id'] ?? 1;
$orderDataSource = getShopOrderDataSource($db, $currentBotId);
$isOdooMode      = ($orderDataSource === 'odoo')
    && defined('ODOO_INTEGRATION_ENABLED')
    && ODOO_INTEGRATION_ENABLED === true;

if ($isOdooMode) {
    $statusFilter = strtolower(trim($_GET['status'] ?? ''));
    $searchFilter = trim($_GET['q'] ?? '');

    $odooOrders  = [];
    $statusCounts = [];
    $odooError   = null;

    try {
        $db->query("SELECT 1 FROM odoo_webhooks_log LIMIT 1");

        $orderKeyExpr = "COALESCE(CAST(order_id AS CHAR), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')))";
        $stateExpr    = "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state')), ''))";
        $amountExpr   = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), '0') AS DECIMAL(12,2))";
        $customerExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '-')";

        $where  = "status = 'success' AND {$orderKeyExpr} IS NOT NULL AND {$orderKeyExpr} != ''";
        $params = [];

        try {
            $stmtCol = $db->query("SHOW COLUMNS FROM odoo_webhooks_log LIKE 'line_account_id'");
            if ($stmtCol && $stmtCol->rowCount() > 0) {
                $where   .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $currentBotId;
            }
        } catch (Exception $e) {}

        if ($searchFilter !== '') {
            $where   .= " AND ({$orderKeyExpr} LIKE ? OR {$customerExpr} LIKE ?)";
            $like     = '%' . $searchFilter . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $baseSubquery = "
            SELECT
                {$orderKeyExpr} AS order_key,
                processed_at,
                {$amountExpr} AS amount_total,
                {$stateExpr} AS order_state,
                {$customerExpr} AS customer_name
            FROM odoo_webhooks_log
            WHERE {$where}
        ";

        $orderSnapshotSql = "
            SELECT
                order_key,
                MIN(processed_at) AS created_at,
                MAX(processed_at) AS updated_at,
                MAX(amount_total) AS total_amount,
                SUBSTRING_INDEX(GROUP_CONCAT(order_state ORDER BY processed_at DESC), ',', 1) AS status,
                SUBSTRING_INDEX(GROUP_CONCAT(customer_name ORDER BY processed_at DESC), ',', 1) AS customer_name
            FROM ({$baseSubquery}) s
            GROUP BY order_key
        ";

        $listSql    = "SELECT * FROM ({$orderSnapshotSql}) o WHERE 1=1";
        $listParams = $params;
        if ($statusFilter !== '') {
            $listSql    .= " AND o.status = ?";
            $listParams[] = $statusFilter;
        }
        $listSql .= " ORDER BY o.updated_at DESC LIMIT 200";

        $stmt       = $db->prepare($listSql);
        $stmt->execute($listParams);
        $odooOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = "SELECT o.status, COUNT(*) AS c FROM ({$orderSnapshotSql}) o GROUP BY o.status";
        $stmt     = $db->prepare($countSql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statusCounts[$row['status']] = (int)$row['c'];
        }
    } catch (Exception $e) {
        $odooError = 'ไม่สามารถโหลดข้อมูล Odoo ได้: ' . $e->getMessage();
    }

    $statusLabels = [
        'draft'     => 'รอดำเนินการ',
        'sent'      => 'ส่งใบเสนอราคา',
        'pending'   => 'รอการยืนยัน',
        'confirmed' => 'ยืนยันแล้ว',
        'sale'      => 'ยืนยันการขาย',
        'done'      => 'เสร็จสิ้น',
        'paid'      => 'ชำระแล้ว',
        'cancel'    => 'ยกเลิก',
        'cancelled' => 'ยกเลิก',
    ];

    $statusColors = [
        'draft'     => 'bg-gray-100 text-gray-700',
        'sent'      => 'bg-blue-100 text-blue-700',
        'pending'   => 'bg-yellow-100 text-yellow-700',
        'confirmed' => 'bg-indigo-100 text-indigo-700',
        'sale'      => 'bg-green-100 text-green-700',
        'done'      => 'bg-green-100 text-green-700',
        'paid'      => 'bg-emerald-100 text-emerald-700',
        'cancel'    => 'bg-red-100 text-red-700',
        'cancelled' => 'bg-red-100 text-red-700',
    ];

    require_once __DIR__ . '/../includes/components/page-header.php';
    require_once __DIR__ . '/../includes/components/toolbar.php';
    require_once __DIR__ . '/../includes/components/data-table.php';
    require_once __DIR__ . '/../includes/components/empty-state.php';
    require_once __DIR__ . '/../includes/header.php';

    echo getPageHeaderStyles();
    echo getToolbarStyles();
    echo getDataTableStyles();
    echo getEmptyStateStyles();

    echo renderPageHeader(
        'รายการคำสั่งซื้อ',
        'โหมด Odoo (Read-only)',
        null,
        [['label' => 'ร้านค้า', 'href' => null], ['label' => 'คำสั่งซื้อ', 'href' => null]]
    );
    ?>

    <div style="margin-bottom:var(--space-4);padding:var(--space-4);background:var(--color-primary-50);border:1px solid var(--color-primary-100);border-radius:var(--radius-lg);display:flex;gap:var(--space-3);align-items:flex-start;">
        <i class="fas fa-database" style="color:var(--color-primary-600);margin-top:2px;"></i>
        <div>
            <p style="font-weight:600;color:var(--color-primary-800);margin:0 0 4px;">โหมด Odoo (Read-only)</p>
            <p style="font-size:var(--text-sm);color:var(--color-primary-700);margin:0;">หน้านี้แสดงคำสั่งซื้อจากข้อมูลที่รับเข้าจาก Odoo และปิดการแก้ไขสถานะในหลังบ้านชั่วคราว</p>
        </div>
    </div>

    <?php if ($odooError): ?>
    <div style="margin-bottom:var(--space-4);padding:var(--space-4);background:var(--color-rose-50);color:var(--color-rose-700);border-radius:var(--radius-lg);">
        <i class="fas fa-exclamation-circle" style="margin-right:var(--space-2);"></i><?= htmlspecialchars($odooError) ?>
    </div>
    <?php endif; ?>

    <?php
    echo renderToolbar([
        'search'  => ['name' => 'q', 'value' => $searchFilter, 'placeholder' => 'ค้นหาเลขออเดอร์/ชื่อลูกค้า'],
        'selects' => [[
            'name'        => 'status',
            'value'       => $statusFilter,
            'placeholder' => 'ทุกสถานะ',
            'options'     => array_map(
                fn($k, $l) => ['value' => $k, 'label' => $l . ' (' . ($statusCounts[$k] ?? 0) . ')'],
                array_keys($statusLabels), array_values($statusLabels)
            ),
        ]],
    ]);

    $odooColumns = [
        ['key' => 'order_key',    'label' => 'เลขออเดอร์', 'align' => 'left',
            'render' => fn($o) => '<strong>#' . htmlspecialchars($o['order_key']) . '</strong>'],
        ['key' => 'customer_name','label' => 'ลูกค้า',     'align' => 'left',
            'render' => fn($o) => htmlspecialchars($o['customer_name'] ?? '-')],
        ['key' => 'created_at',   'label' => 'วันที่',      'align' => 'left',
            'render' => fn($o) => !empty($o['created_at']) ? date('d/m/Y H:i', strtotime($o['created_at'])) : '-'],
        ['key' => 'total_amount', 'label' => 'ยอดรวม',     'align' => 'right',
            'render' => fn($o) => '<span style="color:var(--color-emerald-600);font-weight:600;">฿' . number_format((float)($o['total_amount'] ?? 0), 2) . '</span>'],
        ['key' => 'status',       'label' => 'สถานะ',      'align' => 'center',
            'render' => function($o) use ($statusLabels, $statusColors) {
                $s     = strtolower($o['status'] ?? '');
                $label = $statusLabels[$s] ?? ($o['status'] ?? '-');
                $cls   = $statusColors[$s]  ?? 'bg-gray-100 text-gray-700';
                return '<span class="' . $cls . '" style="padding:3px 10px;border-radius:999px;font-size:var(--text-xs);font-weight:500;">' . htmlspecialchars($label) . '</span>';
            }],
    ];

    echo renderDataTable(
        $odooColumns,
        $odooOrders,
        ['emptyContent' => renderEmptyState('fas fa-inbox', 'ไม่พบข้อมูลคำสั่งซื้อ')]
    );

    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Transactions mode ─────────────────────────────────────────────────────────
$_useTransactions = true;
$_ordersTable     = 'transactions';
$_itemsTable      = 'transaction_items';
$_itemsFk         = 'transaction_id';
$tablesExist      = true;

// Check if transactions table exists
try {
    $db->query("SELECT 1 FROM transactions LIMIT 1");
} catch (Exception $e) {
    $tablesExist = false;
    $error = "ตาราง transactions ยังไม่ถูกสร้าง กรุณารัน migration ก่อน";
}

require_once __DIR__ . '/../includes/components/page-header.php';
require_once __DIR__ . '/../includes/components/toolbar.php';
require_once __DIR__ . '/../includes/components/empty-state.php';
require_once __DIR__ . '/../includes/header.php';

echo getPageHeaderStyles();
echo getToolbarStyles();
echo getEmptyStateStyles();

echo renderPageHeader(
    'รายการ/คำสั่งซื้อ',
    '',
    null,
    [['label' => 'ร้านค้า', 'href' => null], ['label' => 'คำสั่งซื้อ', 'href' => null]]
);

if (isset($error)): ?>
<div style="margin-bottom:var(--space-4);padding:var(--space-4);background:var(--color-rose-50);color:var(--color-rose-700);border-radius:var(--radius-lg);">
    <i class="fas fa-exclamation-circle" style="margin-right:var(--space-2);"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif;

if (!$tablesExist): ?>
<div style="padding:var(--space-4);background:var(--color-amber-50);color:var(--color-amber-700);border-radius:var(--radius-lg);">
    <i class="fas fa-exclamation-triangle" style="margin-right:var(--space-2);"></i>ระบบคำสั่งซื้อยังไม่พร้อมใช้งาน
</div>
<?php
require_once __DIR__ . '/../includes/footer.php';
exit;
endif;

$lineManager = new LineAccountManager($db);
$line        = $lineManager->getLineAPI($currentBotId);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $orderId = $_POST['order_id'] ?? '';

    if ($action === 'update_status' && $orderId) {
        $newStatus = $_POST['status'];
        $stmt = $db->prepare("UPDATE {$_ordersTable} SET status = ? WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$newStatus, $orderId, $currentBotId]);

        // WMS Integration: Set wms_status to pending_pick when order is confirmed or paid
        if (in_array($newStatus, ['confirmed', 'paid'])) {
            try {
                $stmt = $db->prepare("UPDATE {$_ordersTable} SET wms_status = 'pending_pick' WHERE id = ? AND (wms_status IS NULL OR wms_status = '')");
                $stmt->execute([$orderId]);
            } catch (Exception $e) {
                // wms_status column may not exist, ignore
            }
        }

        // Log activity
        $activityLogger->logOrder(ActivityLogger::ACTION_UPDATE, 'อัพเดทสถานะคำสั่งซื้อ', [
            'entity_type' => 'order',
            'entity_id'   => $orderId,
            'new_value'   => ['status' => $newStatus]
        ]);

        $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.display_name, u.reply_token, u.reply_token_expires FROM {$_ordersTable} o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order && $order['line_user_id']) {
            $statusText = ['confirmed' => '✅ ยืนยันแล้ว', 'paid' => '💰 ชำระเงินแล้ว', 'shipping' => '🚚 กำลังจัดส่ง', 'delivered' => '📦 จัดส่งแล้ว', 'cancelled' => '❌ ยกเลิก'];
            $msg = "📋 อัพเดทรายการ #{$order['order_number']}\n\nสถานะ: " . ($statusText[$newStatus] ?? $newStatus);
            if ($newStatus === 'shipping' && !empty($_POST['tracking'])) {
                $stmt = $db->prepare("UPDATE {$_ordersTable} SET shipping_tracking = ? WHERE id = ?");
                $stmt->execute([$_POST['tracking'], $orderId]);
                $msg .= "\n🚚 เลขพัสดุ: " . $_POST['tracking'];
            }
            // ใช้ sendMessage ถ้ามี หรือ fallback ไป pushMessage
            if (method_exists($line, 'sendMessage')) {
                $line->sendMessage($order['line_user_id'], $msg, $order['reply_token'] ?? null, $order['reply_token_expires'] ?? null, $db);
            } else {
                $line->pushMessage($order['line_user_id'], $msg);
            }
        }
    } elseif ($action === 'approve_payment' && $orderId) {
        $stmt = $db->prepare("UPDATE {$_ordersTable} SET payment_status = 'paid', status = 'paid' WHERE id = ?");
        $stmt->execute([$orderId]);

        // WMS Integration: Set wms_status to pending_pick when payment approved
        try {
            $stmt = $db->prepare("UPDATE {$_ordersTable} SET wms_status = 'pending_pick' WHERE id = ? AND (wms_status IS NULL OR wms_status = '')");
            $stmt->execute([$orderId]);
        } catch (Exception $e) {
            // wms_status column may not exist, ignore
        }

        // Log activity
        $activityLogger->logOrder(ActivityLogger::ACTION_APPROVE, 'อนุมัติการชำระเงิน', [
            'entity_type' => 'order',
            'entity_id'   => $orderId,
            'new_value'   => ['payment_status' => 'paid', 'status' => 'paid']
        ]);

        $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.reply_token, u.reply_token_expires FROM {$_ordersTable} o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if ($order && $order['line_user_id']) {
            // ใช้ sendMessage ถ้ามี หรือ fallback ไป pushMessage
            $msg = "✅ ยืนยันการชำระเงินแล้ว!\n\nรายการ #{$order['order_number']}\nกำลังเตรียมดำเนินการ";
            if (method_exists($line, 'sendMessage')) {
                $line->sendMessage($order['line_user_id'], $msg, $order['reply_token'] ?? null, $order['reply_token_expires'] ?? null, $db);
            } else {
                $line->pushMessage($order['line_user_id'], $msg);
            }
        }
    }
    // Use JavaScript redirect since headers may already be sent
    echo "<script>window.location.href = 'orders.php';</script>";
    exit;
}

// Get orders
$statusFilter  = $_GET['status'] ?? '';
$typeFilter    = $_GET['type'] ?? '';
$botIdForQuery = $currentBotId ?? $_SESSION['current_bot_id'] ?? null;

$sql = "SELECT o.*, u.display_name, u.picture_url,
        (SELECT COUNT(*) FROM {$_itemsTable} WHERE {$_itemsFk} = o.id) as item_count
        FROM {$_ordersTable} o
        JOIN users u ON o.user_id = u.id";

if ($botIdForQuery) {
    $sql    .= " WHERE (o.line_account_id = ? OR o.line_account_id IS NULL)";
    $params  = [$botIdForQuery];
} else {
    $sql    .= " WHERE 1=1";
    $params  = [];
}

if ($statusFilter) {
    $sql     .= " AND o.status = ?";
    $params[] = $statusFilter;
}
if ($typeFilter && $_useTransactions) {
    $sql     .= " AND o.transaction_type = ?";
    $params[] = $typeFilter;
}

// Filter by pending slips
if (isset($_GET['pending_slip']) && $_GET['pending_slip'] == '1') {
    $sql .= " AND o.id IN (SELECT DISTINCT transaction_id FROM payment_slips WHERE status = 'pending')";
}

$sql .= " ORDER BY o.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Count by status
$statusCounts = [];
try {
    if ($botIdForQuery) {
        $stmt = $db->prepare("SELECT status, COUNT(*) as c FROM {$_ordersTable} WHERE (line_account_id = ? OR line_account_id IS NULL) GROUP BY status");
        $stmt->execute([$botIdForQuery]);
    } else {
        $stmt = $db->query("SELECT status, COUNT(*) as c FROM {$_ordersTable} GROUP BY status");
    }
    while ($row = $stmt->fetch()) {
        $statusCounts[$row['status']] = $row['c'];
    }
} catch (Exception $e) {}

// Count pending slips (uploaded but not approved)
$pendingSlipsCount      = 0;
$ordersWithPendingSlips = [];
try {
    $sql = "SELECT DISTINCT t.id, t.order_number
            FROM transactions t
            INNER JOIN payment_slips ps ON ps.transaction_id = t.id
            WHERE ps.status = 'pending'";
    if ($botIdForQuery) {
        $sql .= " AND (t.line_account_id = ? OR t.line_account_id IS NULL)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$botIdForQuery]);
    } else {
        $stmt = $db->query($sql);
    }
    $ordersWithPendingSlips = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $pendingSlipsCount      = count($ordersWithPendingSlips);
} catch (Exception $e) {}

$transactionTypes = [
    'purchase'     => ['icon' => '🛒', 'label' => 'ซื้อสินค้า'],
    'booking'      => ['icon' => '📅', 'label' => 'จองคิว'],
    'subscription' => ['icon' => '🔄', 'label' => 'สมัครสมาชิก'],
    'redemption'   => ['icon' => '🎁', 'label' => 'แลกของรางวัล']
];

// Count dispensing records
$dispenseCount = 0;
try {
    if ($botIdForQuery) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM dispensing_records WHERE line_account_id = ?");
        $stmt->execute([$botIdForQuery]);
    } else {
        $stmt = $db->query("SELECT COUNT(*) FROM dispensing_records");
    }
    $dispenseCount = $stmt->fetchColumn();
} catch (Exception $e) {}

// Check if viewing dispense tab
$viewDispense = isset($_GET['view']) && $_GET['view'] === 'dispense';

// Get dispensing records if viewing dispense tab
$dispenseRecords = [];
if ($viewDispense) {
    try {
        $sql = "SELECT d.*, u.display_name, u.picture_url
                FROM dispensing_records d
                JOIN users u ON d.user_id = u.id";
        if ($botIdForQuery) {
            $sql .= " WHERE d.line_account_id = ?";
            $sql .= " ORDER BY d.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$botIdForQuery]);
        } else {
            $sql .= " ORDER BY d.created_at DESC";
            $stmt = $db->query($sql);
        }
        $dispenseRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$statuses = [
    'pending'   => ['label' => 'รอยืนยัน',  'color' => 'yellow'],
    'confirmed' => ['label' => 'ยืนยันแล้ว', 'color' => 'blue'],
    'paid'      => ['label' => 'ชำระแล้ว',   'color' => 'green'],
    'shipping'  => ['label' => 'กำลังส่ง',   'color' => 'purple'],
    'delivered' => ['label' => 'ส่งแล้ว',    'color' => 'gray'],
    'cancelled' => ['label' => 'ยกเลิก',     'color' => 'red']
];
$statusColors = [
    'pending'   => 'var(--color-amber-100)',   'pending_c'   => 'var(--color-amber-700)',
    'confirmed' => 'var(--color-primary-100)', 'confirmed_c' => 'var(--color-primary-700)',
    'paid'      => 'var(--color-emerald-100)', 'paid_c'      => 'var(--color-emerald-700)',
    'shipping'  => 'var(--color-violet-600)',  'shipping_c'  => '#ffffff',
    'delivered' => 'var(--color-slate-100)',   'delivered_c' => 'var(--color-dark-700)',
    'cancelled' => 'var(--color-rose-50)',     'cancelled_c' => 'var(--color-rose-700)',
];
?>

<style>
.order-type-bar { display:flex; flex-wrap:wrap; gap:var(--space-2); margin-bottom:var(--space-4); }
.order-type-chip {
    padding:6px 14px; border-radius:var(--radius-md); font-size:var(--text-sm);
    text-decoration:none; background:#fff; border:1px solid var(--color-slate-200);
    color:var(--color-dark-700); transition:all var(--transition-fast);
}
.order-type-chip:hover { background:var(--color-slate-50); }
.order-type-chip.chip-active { background:var(--color-primary-600); color:#fff; border-color:var(--color-primary-600); }
.order-type-chip.chip-dispense { background:var(--color-emerald-500); color:#fff; border-color:var(--color-emerald-500); }
.status-filter-bar { display:flex; flex-wrap:wrap; gap:var(--space-2); margin-bottom:var(--space-6); }
.status-chip {
    padding:8px 16px; border-radius:var(--radius-md); font-size:var(--text-sm);
    text-decoration:none; background:#fff; border:1px solid var(--color-slate-200);
    color:var(--color-dark-700); transition:all var(--transition-fast);
}
.status-chip:hover { background:var(--color-slate-50); }
.slip-alert {
    margin-bottom:var(--space-4); padding:var(--space-4);
    background:var(--color-amber-50); border:1px solid var(--color-amber-200);
    border-radius:var(--radius-lg); display:flex; align-items:center;
    justify-content:space-between; flex-wrap:wrap; gap:var(--space-3);
}
.order-card {
    background:#fff; border:1px solid var(--color-slate-200);
    border-radius:var(--radius-lg); overflow:hidden; margin-bottom:var(--space-4);
    box-shadow:0 1px 3px rgba(15,23,42,0.04);
}
.order-card-header {
    padding:var(--space-4); border-bottom:1px solid var(--color-slate-100);
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:var(--space-3);
}
.order-card-delivery {
    padding:var(--space-3) var(--space-4); background:var(--color-primary-50);
    border-bottom:1px solid var(--color-slate-100); font-size:var(--text-sm);
}
.order-card-footer {
    padding:var(--space-3) var(--space-4); background:var(--color-slate-50);
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:var(--space-2);
}
.order-status-pill { padding:4px 12px; border-radius:var(--radius-full); font-size:var(--text-xs); font-weight:500; }
.btn-sm {
    padding:8px 16px; border-radius:var(--radius-md); font-size:var(--text-sm); font-weight:500;
    cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px;
    transition:all var(--transition-fast); border:none;
}
.btn-outline { background:#fff; border:1px solid var(--color-slate-200); color:var(--color-dark-800); }
.btn-outline:hover { background:var(--color-slate-50); }
.btn-primary-sm { background:var(--color-primary-600); color:#fff; }
.btn-primary-sm:hover { background:var(--color-primary-700); }
.dispense-card {
    background:#fff; border:1px solid var(--color-slate-200);
    border-radius:var(--radius-lg); overflow:hidden; margin-bottom:var(--space-4);
    box-shadow:0 1px 3px rgba(15,23,42,0.04);
}
.dispense-card-header {
    padding:var(--space-4); border-bottom:1px solid var(--color-slate-100);
    background:var(--color-emerald-50);
    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:var(--space-3);
}
.dispense-item {
    display:flex; align-items:flex-start; gap:var(--space-3);
    padding:var(--space-3); background:var(--color-slate-50);
    border-radius:var(--radius-md); margin-bottom:var(--space-3);
}
.dark .order-card, .dark .dispense-card {
    background:var(--color-dark-800); border-color:var(--color-dark-700);
}
.dark .order-card-header  { border-color:var(--color-dark-700); }
.dark .order-card-footer  { background:var(--color-dark-900); border-color:var(--color-dark-700); }
.dark .order-card-delivery { background:rgba(99,102,241,0.08); border-color:var(--color-dark-700); }
.dark .order-type-chip, .dark .status-chip {
    background:var(--color-dark-800); border-color:var(--color-dark-700); color:var(--color-slate-200);
}
.dark .btn-outline { background:var(--color-dark-800); border-color:var(--color-dark-700); color:var(--color-slate-200); }
.dark .dispense-item { background:var(--color-dark-700); }
.dark .dispense-card-header { background:rgba(16,185,129,0.08); border-color:var(--color-dark-700); }
</style>

<?php if ($_useTransactions): ?>
<div class="order-type-bar">
    <a href="?" class="order-type-chip <?= !$typeFilter && !$viewDispense ? 'chip-active' : '' ?>">ทุกประเภท</a>
    <?php foreach ($transactionTypes as $key => $type): ?>
    <a href="?type=<?= $key ?><?= $statusFilter ? '&status='.$statusFilter : '' ?>"
       class="order-type-chip <?= $typeFilter === $key ? 'chip-active' : '' ?>"><?= $type['icon'] ?> <?= $type['label'] ?></a>
    <?php endforeach; ?>
    <a href="?view=dispense"
       class="order-type-chip <?= $viewDispense ? 'chip-dispense' : '' ?>">
        💊 จ่ายยา
        <?php if ($dispenseCount > 0): ?>
        <span style="margin-left:4px;padding:1px 8px;background:var(--color-emerald-600);color:#fff;border-radius:var(--radius-full);font-size:var(--text-xs);"><?= $dispenseCount ?></span>
        <?php endif; ?>
    </a>
</div>
<?php endif; ?>

<?php if (!$viewDispense): ?>

<?php if ($pendingSlipsCount > 0): ?>
<div class="slip-alert">
    <div style="display:flex;align-items:center;gap:var(--space-3);">
        <i class="fas fa-receipt" style="font-size:20px;color:var(--color-amber-500);"></i>
        <div>
            <p style="font-weight:600;color:var(--color-amber-700);margin:0;">มีสลิปรอตรวจสอบ <?= $pendingSlipsCount ?> รายการ</p>
            <p style="font-size:var(--text-sm);color:var(--color-amber-600);margin:0;">กรุณาตรวจสอบและอนุมัติสลิปการชำระเงิน</p>
        </div>
    </div>
    <a href="?pending_slip=1<?= $typeFilter ? '&type='.$typeFilter : '' ?>"
       class="btn-sm" style="background:var(--color-amber-500);color:#fff;">
        <i class="fas fa-eye"></i>ดูรายการ
    </a>
</div>
<?php endif; ?>

<div class="status-filter-bar">
    <a href="?<?= $typeFilter ? 'type='.$typeFilter : '' ?>"
       class="status-chip"
       style="<?= !$statusFilter && !isset($_GET['pending_slip']) ? 'background:var(--color-emerald-500);color:#fff;border-color:var(--color-emerald-500);' : '' ?>">
        ทั้งหมด <span style="font-size:var(--text-xs);margin-left:4px;">(<?= array_sum($statusCounts) ?>)</span>
    </a>
    <?php if ($pendingSlipsCount > 0): ?>
    <a href="?pending_slip=1<?= $typeFilter ? '&type='.$typeFilter : '' ?>"
       class="status-chip"
       style="<?= isset($_GET['pending_slip']) ? 'background:var(--color-amber-500);color:#fff;border-color:var(--color-amber-500);' : '' ?>">
        <i class="fas fa-receipt"></i> รอตรวจสลิป
        <span style="margin-left:4px;padding:1px 8px;background:var(--color-amber-600);color:#fff;border-radius:var(--radius-full);font-size:var(--text-xs);"><?= $pendingSlipsCount ?></span>
    </a>
    <?php endif; ?>
    <?php foreach ($statuses as $key => $status): ?>
    <a href="?status=<?= $key ?><?= $typeFilter ? '&type='.$typeFilter : '' ?>"
       class="status-chip"
       style="<?= $statusFilter === $key ? 'background:var(--color-' . $status['color'] . '-500);color:#fff;border-color:var(--color-' . $status['color'] . '-500);' : '' ?>">
        <?= $status['label'] ?> <span style="font-size:var(--text-xs);margin-left:4px;">(<?= $statusCounts[$key] ?? 0 ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<?php if (empty($orders)): ?>
<?= renderEmptyState('fas fa-shopping-bag', 'ยังไม่มีคำสั่งซื้อ', 'คำสั่งซื้อจาก LINE Shop จะปรากฏที่นี่') ?>
<?php else: ?>
<?php foreach ($orders as $order):
    $transType      = $order['transaction_type'] ?? 'purchase';
    $typeInfo       = $transactionTypes[$transType] ?? $transactionTypes['purchase'];
    $hasPendingSlip = in_array($order['id'], $ordersWithPendingSlips);
    $deliveryInfo   = json_decode($order['delivery_info'] ?? '{}', true);
    $statusKey      = $order['status'] ?? 'pending';
    $statusLabel    = $statuses[$statusKey]['label'] ?? $statusKey;
    $badgeBg        = $statusColors[$statusKey]         ?? 'var(--color-slate-100)';
    $badgeColor     = $statusColors[$statusKey . '_c']  ?? 'var(--color-dark-700)';
?>
<div class="order-card" <?= $hasPendingSlip ? 'style="outline:2px solid var(--color-amber-400);"' : '' ?>>
    <?php if ($hasPendingSlip): ?>
    <div style="background:var(--color-amber-500);color:#fff;padding:8px var(--space-4);font-size:var(--text-sm);display:flex;align-items:center;justify-content:space-between;">
        <span><i class="fas fa-receipt" style="margin-right:var(--space-2);"></i><strong>มีสลิปรอตรวจสอบ</strong></span>
        <a href="order-detail.php?id=<?= $order['id'] ?>" style="background:#fff;color:var(--color-amber-600);padding:4px 12px;border-radius:var(--radius-sm);font-size:var(--text-xs);font-weight:600;text-decoration:none;">ตรวจสอบเลย</a>
    </div>
    <?php endif; ?>

    <div class="order-card-header">
        <div style="display:flex;align-items:center;gap:var(--space-3);">
            <img src="<?= $order['picture_url'] ?: 'https://via.placeholder.com/40' ?>"
                 style="width:40px;height:40px;border-radius:var(--radius-full);object-fit:cover;" alt="">
            <div>
                <div style="display:flex;align-items:center;gap:var(--space-2);">
                    <span style="font-weight:600;color:var(--color-dark-800);">#<?= htmlspecialchars($order['order_number']) ?></span>
                    <?php if ($_useTransactions && $transType !== 'purchase'): ?>
                    <span style="padding:2px 8px;background:rgba(124,58,237,0.1);color:var(--color-violet-600);border-radius:var(--radius-full);font-size:var(--text-xs);"><?= $typeInfo['icon'] ?> <?= $typeInfo['label'] ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:var(--text-sm);color:var(--color-dark-500);">
                    <?= htmlspecialchars($order['display_name']) ?> · <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?>
                </div>
            </div>
        </div>
        <div style="text-align:right;">
            <span class="order-status-pill" style="background:<?= $badgeBg ?>;color:<?= $badgeColor ?>;"><?= $statusLabel ?></span>
            <div style="font-size:var(--text-lg);font-weight:700;color:var(--color-emerald-600);margin-top:4px;">฿<?= number_format($order['grand_total'], 2) ?></div>
        </div>
    </div>

    <?php if (!empty($deliveryInfo['name']) || !empty($deliveryInfo['phone']) || !empty($deliveryInfo['address'])): ?>
    <div class="order-card-delivery">
        <div style="display:flex;align-items:flex-start;gap:var(--space-3);">
            <i class="fas fa-truck" style="color:var(--color-primary-500);margin-top:2px;flex-shrink:0;"></i>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:var(--space-2);flex:1;">
                <?php if (!empty($deliveryInfo['name'])): ?>
                <div><span style="color:var(--color-dark-500);">ผู้รับ:</span> <span style="font-weight:500;"><?= htmlspecialchars($deliveryInfo['name']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($deliveryInfo['phone'])): ?>
                <div><span style="color:var(--color-dark-500);">โทร:</span> <span style="font-weight:500;"><?= htmlspecialchars($deliveryInfo['phone']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($deliveryInfo['address'])): ?>
                <div style="grid-column:1/-1;"><span style="color:var(--color-dark-500);">ที่อยู่:</span> <span style="font-weight:500;"><?= htmlspecialchars($deliveryInfo['address']) ?></span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="order-card-footer">
        <div style="font-size:var(--text-sm);color:var(--color-dark-500);">
            <span><i class="fas fa-box" style="margin-right:4px;"></i><?= $order['item_count'] ?> รายการ</span>
            <?php if ($order['shipping_tracking']): ?>
            <span style="margin-left:var(--space-4);"><i class="fas fa-truck" style="margin-right:4px;"></i><?= htmlspecialchars($order['shipping_tracking']) ?></span>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:var(--space-2);">
            <a href="order-detail.php?id=<?= $order['id'] ?>" class="btn-sm btn-outline">
                <i class="fas fa-eye"></i>ดูรายละเอียด
            </a>
            <?php if ($order['status'] === 'pending'): ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action"   value="update_status">
                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                <input type="hidden" name="status"   value="confirmed">
                <button type="submit" class="btn-sm btn-primary-sm">
                    <i class="fas fa-check"></i>ยืนยัน
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<?php if ($viewDispense): ?>
<div style="margin-bottom:var(--space-6);">
    <span style="padding:8px 16px;background:var(--color-emerald-500);color:#fff;border-radius:var(--radius-md);font-size:var(--text-sm);font-weight:500;">💊 รายการจ่ายยา (<?= count($dispenseRecords) ?>)</span>
</div>

<?php if (empty($dispenseRecords)): ?>
<?= renderEmptyState('fas fa-pills', 'ยังไม่มีรายการจ่ายยา') ?>
<?php else: ?>
<?php foreach ($dispenseRecords as $record):
    $items = json_decode($record['items'], true) ?: [];
?>
<div class="dispense-card">
    <div class="dispense-card-header">
        <div style="display:flex;align-items:center;gap:var(--space-3);">
            <img src="<?= $record['picture_url'] ?: 'https://via.placeholder.com/40' ?>"
                 style="width:40px;height:40px;border-radius:var(--radius-full);object-fit:cover;" alt="">
            <div>
                <div style="display:flex;align-items:center;gap:var(--space-2);">
                    <span style="font-weight:600;color:var(--color-emerald-700);">#<?= htmlspecialchars($record['order_number']) ?></span>
                    <span style="padding:2px 8px;background:var(--color-emerald-100);color:var(--color-emerald-700);border-radius:var(--radius-full);font-size:var(--text-xs);">💊 จ่ายยา</span>
                </div>
                <div style="font-size:var(--text-sm);color:var(--color-dark-500);"><?= htmlspecialchars($record['display_name']) ?> · <?= date('d/m/Y H:i', strtotime($record['created_at'])) ?></div>
            </div>
        </div>
        <div style="text-align:right;">
            <span style="padding:4px 12px;border-radius:var(--radius-full);font-size:var(--text-xs);font-weight:500;background:var(--color-emerald-100);color:var(--color-emerald-600);">
                <?= $record['payment_status'] === 'paid' ? '✅ ชำระแล้ว' : '⏳ รอชำระ' ?>
            </span>
            <div style="font-size:var(--text-lg);font-weight:700;color:var(--color-emerald-600);margin-top:4px;">฿<?= number_format($record['total_amount'], 2) ?></div>
        </div>
    </div>

    <!-- Items List -->
    <div style="padding:var(--space-4);">
        <?php foreach ($items as $item): ?>
        <div class="dispense-item">
            <div style="flex-shrink:0;font-size:24px;">
                <?php if (!empty($item['isMedicine']) && $item['isMedicine'] !== false): ?>
                <?= ($item['usageType'] ?? 'internal') === 'external' ? '🧴' : '💊' ?>
                <?php else: ?>📦<?php endif; ?>
            </div>
            <div style="flex:1;">
                <p style="font-weight:500;color:var(--color-dark-800);margin:0 0 4px;"><?= htmlspecialchars($item['name']) ?></p>
                <p style="font-size:var(--text-sm);color:var(--color-dark-500);margin:0;">จำนวน: <?= $item['qty'] ?> <?= htmlspecialchars($item['unit'] ?? 'ชิ้น') ?></p>

                <?php if (!empty($item['isMedicine']) && $item['isMedicine'] !== false): ?>
                <div style="margin-top:var(--space-2);font-size:var(--text-xs);">
                    <?php if (!empty($item['indication'])): ?>
                    <p style="color:var(--color-primary-600);margin:2px 0;">📋 ข้อบ่งใช้: <?= htmlspecialchars($item['indication']) ?></p>
                    <?php endif; ?>
                    <p style="color:var(--color-violet-600);margin:2px 0;">
                        💊 รับประทานครั้งละ <?= $item['dosage'] ?? 1 ?> <?= $item['dosageUnit'] ?? 'เม็ด' ?>
                        <?php
                        $freq = $item['frequency'] ?? '3';
                        echo $freq === 'prn' ? 'เมื่อมีอาการ' : $freq . ' ครั้ง/วัน';
                        ?>
                    </p>
                    <?php
                    $mealText  = ['before' => 'ก่อนอาหาร', 'after' => 'หลังอาหาร', 'with' => 'พร้อมอาหาร'];
                    $timeIcons = ['morning' => '🌅', 'noon' => '☀️', 'evening' => '🌆', 'bedtime' => '🌙'];
                    ?>
                    <p style="color:var(--color-amber-600);margin:2px 0;">
                        ⏰ <?= $mealText[$item['mealTiming'] ?? 'after'] ?? 'หลังอาหาร' ?>
                        <?php if (!empty($item['timeOfDay'])): ?>
                        | <?= implode(' ', array_map(fn($t) => $timeIcons[$t] ?? '', $item['timeOfDay'])) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>

                <?php if (!empty($item['notes'])): ?>
                <p style="margin-top:4px;font-size:var(--text-xs);color:var(--color-dark-500);">📝 <?= htmlspecialchars($item['notes']) ?></p>
                <?php endif; ?>
            </div>
            <div style="text-align:right;">
                <p style="font-weight:700;color:var(--color-emerald-600);margin:0;">฿<?= number_format(($item['price'] ?? 0) * ($item['qty'] ?? 1), 2) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="padding:var(--space-3) var(--space-4);background:var(--color-slate-50);border-top:1px solid var(--color-slate-200);display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:var(--text-sm);color:var(--color-dark-500);">
            <span><i class="fas fa-box" style="margin-right:4px;"></i><?= count($items) ?> รายการ</span>
            <?php
            $paymentText = ['cash' => '💵 เงินสด', 'transfer' => '📱 โอนเงิน', 'credit' => '💳 บัตรเครดิต', 'later' => '⏰ จ่ายทีหลัง'];
            ?>
            <span style="margin-left:var(--space-4);"><?= $paymentText[$record['payment_method']] ?? htmlspecialchars($record['payment_method']) ?></span>
        </div>
        <a href="../chat.php?user=<?= $record['user_id'] ?>" class="btn-sm" style="background:var(--color-emerald-500);color:#fff;">
            <i class="fas fa-comments"></i>แชท
        </a>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
