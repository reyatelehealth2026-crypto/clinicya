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

$db = Database::getInstance()->getConnection();
$activityLogger = ActivityLogger::getInstance($db);
$pageTitle = 'รายการ/คำสั่งซื้อ';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentBotId = $_SESSION['current_bot_id'] ?? 1;
$orderDataSource = getShopOrderDataSource($db, $currentBotId);
$isOdooMode = ($orderDataSource === 'odoo')
    && defined('ODOO_INTEGRATION_ENABLED')
    && ODOO_INTEGRATION_ENABLED === true;

if ($isOdooMode) {
    $statusFilter = strtolower(trim($_GET['status'] ?? ''));
    $searchFilter = trim($_GET['q'] ?? '');

    $odooOrders = [];
    $statusCounts = [];
    $odooError = null;

    try {
        $db->query("SELECT 1 FROM odoo_webhooks_log LIMIT 1");

        $orderKeyExpr = "COALESCE(CAST(order_id AS CHAR), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')))";
        $stateExpr = "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state')), ''))";
        $amountExpr = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), '0') AS DECIMAL(12,2))";
        $customerExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '-')";

        $where = "status = 'success' AND {$orderKeyExpr} IS NOT NULL AND {$orderKeyExpr} != ''";
        $params = [];

        try {
            $stmtCol = $db->query("SHOW COLUMNS FROM odoo_webhooks_log LIKE 'line_account_id'");
            if ($stmtCol && $stmtCol->rowCount() > 0) {
                $where .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $currentBotId;
            }
        } catch (Exception $e) {
        }

        if ($searchFilter !== '') {
            $where .= " AND ({$orderKeyExpr} LIKE ? OR {$customerExpr} LIKE ?)";
            $like = '%' . $searchFilter . '%';
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

        $listSql = "SELECT * FROM ({$orderSnapshotSql}) o WHERE 1=1";
        $listParams = $params;
        if ($statusFilter !== '') {
            $listSql .= " AND o.status = ?";
            $listParams[] = $statusFilter;
        }
        $listSql .= " ORDER BY o.updated_at DESC LIMIT 200";

        $stmt = $db->prepare($listSql);
        $stmt->execute($listParams);
        $odooOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = "SELECT o.status, COUNT(*) AS c FROM ({$orderSnapshotSql}) o GROUP BY o.status";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statusCounts[$row['status']] = (int) $row['c'];
        }
    } catch (Exception $e) {
        $odooError = 'ไม่สามารถโหลดข้อมูล Odoo ได้: ' . $e->getMessage();
    }

    $statusLabels = [
        'draft' => 'รอดำเนินการ',
        'sent' => 'ส่งใบเสนอราคา',
        'pending' => 'รอการยืนยัน',
        'confirmed' => 'ยืนยันแล้ว',
        'sale' => 'ยืนยันการขาย',
        'done' => 'เสร็จสิ้น',
        'paid' => 'ชำระแล้ว',
        'cancel' => 'ยกเลิก',
        'cancelled' => 'ยกเลิก',
    ];

    $statusColors = [
        'draft' => 'bg-gray-100 text-gray-700',
        'sent' => 'bg-blue-100 text-blue-700',
        'pending' => 'bg-yellow-100 text-yellow-700',
        'confirmed' => 'bg-indigo-100 text-indigo-700',
        'sale' => 'bg-green-100 text-green-700',
        'done' => 'bg-green-100 text-green-700',
        'paid' => 'bg-emerald-100 text-emerald-700',
        'cancel' => 'bg-red-100 text-red-700',
        'cancelled' => 'bg-red-100 text-red-700',
    ];

    require_once __DIR__ . '/../includes/header.php';
    ?>

    <div class="mb-4 p-4 bg-indigo-50 border border-indigo-200 text-indigo-800 rounded-lg">
        <div class="flex items-start gap-3">
            <i class="fas fa-database text-indigo-600 mt-0.5"></i>
            <div>
                <p class="font-semibold">โหมด Odoo (Read-only)</p>
                <p class="text-sm">หน้านี้แสดงคำสั่งซื้อจากข้อมูลที่รับเข้าจาก Odoo และปิดการแก้ไขสถานะในหลังบ้านชั่วคราว</p>
            </div>
        </div>
    </div>

    <?php if ($odooError): ?>
    <div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($odooError) ?>
    </div>
    <?php endif; ?>

    <form method="GET" class="mb-4 bg-white rounded-xl shadow p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
        <input
            type="text"
            name="q"
            value="<?= htmlspecialchars($searchFilter) ?>"
            placeholder="ค้นหาเลขออเดอร์/ชื่อลูกค้า"
            class="w-full px-4 py-2 border rounded-lg"
        >
        <select name="status" class="w-full px-4 py-2 border rounded-lg">
            <option value="">ทุกสถานะ</option>
            <?php foreach ($statusLabels as $key => $label): ?>
            <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?> (<?= $statusCounts[$key] ?? 0 ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
            <i class="fas fa-search mr-1"></i>ค้นหา
        </button>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="p-4 border-b font-semibold">รายการคำสั่งซื้อจาก Odoo (<?= count($odooOrders) ?> รายการ)</div>

        <?php if (empty($odooOrders)): ?>
        <div class="p-10 text-center text-gray-500">
            <i class="fas fa-inbox text-5xl mb-3"></i>
            <p>ไม่พบข้อมูลคำสั่งซื้อ</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left">เลขออเดอร์</th>
                        <th class="px-4 py-3 text-left">ลูกค้า</th>
                        <th class="px-4 py-3 text-left">วันที่</th>
                        <th class="px-4 py-3 text-right">ยอดรวม</th>
                        <th class="px-4 py-3 text-center">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($odooOrders as $order): ?>
                    <?php
                        $status = strtolower($order['status'] ?? '');
                        $statusLabel = $statusLabels[$status] ?? ($order['status'] ?? '-');
                        $statusClass = $statusColors[$status] ?? 'bg-gray-100 text-gray-700';
                    ?>
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium">#<?= htmlspecialchars($order['order_key']) ?></td>
                        <td class="px-4 py-3"><?= htmlspecialchars($order['customer_name'] ?? '-') ?></td>
                        <td class="px-4 py-3"><?= !empty($order['created_at']) ? date('d/m/Y H:i', strtotime($order['created_at'])) : '-' ?></td>
                        <td class="px-4 py-3 text-right text-green-600 font-semibold">฿<?= number_format((float) ($order['total_amount'] ?? 0), 2) ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 rounded-full text-xs <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Use transactions table (unified with LIFF checkout)
$_useTransactions = true;
$_ordersTable = 'transactions';
$_itemsTable = 'transaction_items';
$_itemsFk = 'transaction_id';
$tablesExist = true;

// Check if transactions table exists
try {
    $db->query("SELECT 1 FROM transactions LIMIT 1");
} catch (Exception $e) {
    $tablesExist = false;
    $error = "ตาราง transactions ยังไม่ถูกสร้าง กรุณารัน migration ก่อน";
}

require_once __DIR__ . '/../includes/header.php';

if (isset($error)): ?>
<div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif;

if (!$tablesExist): ?>
<div class="bg-yellow-100 text-yellow-700 p-4 rounded-lg">
    <i class="fas fa-exclamation-triangle mr-2"></i>ระบบคำสั่งซื้อยังไม่พร้อมใช้งาน
</div>
<?php 
require_once __DIR__ . '/../includes/footer.php';
exit;
endif;

$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($currentBotId);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
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
            'entity_id' => $orderId,
            'new_value' => ['status' => $newStatus]
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
            'entity_id' => $orderId,
            'new_value' => ['payment_status' => 'paid', 'status' => 'paid']
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
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$botIdForQuery = $currentBotId ?? $_SESSION['current_bot_id'] ?? null;

$sql = "SELECT o.*, u.display_name, u.picture_url,
        (SELECT COUNT(*) FROM {$_itemsTable} WHERE {$_itemsFk} = o.id) as item_count
        FROM {$_ordersTable} o 
        JOIN users u ON o.user_id = u.id";

if ($botIdForQuery) {
    $sql .= " WHERE (o.line_account_id = ? OR o.line_account_id IS NULL)";
    $params = [$botIdForQuery];
} else {
    $sql .= " WHERE 1=1";
    $params = [];
}

if ($statusFilter) {
    $sql .= " AND o.status = ?";
    $params[] = $statusFilter;
}
if ($typeFilter && $_useTransactions) {
    $sql .= " AND o.transaction_type = ?";
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
$pendingSlipsCount = 0;
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
    $pendingSlipsCount = count($ordersWithPendingSlips);
} catch (Exception $e) {}

$transactionTypes = [
    'purchase' => ['icon' => '🛒', 'label' => 'ซื้อสินค้า'],
    'booking' => ['icon' => '📅', 'label' => 'จองคิว'],
    'subscription' => ['icon' => '🔄', 'label' => 'สมัครสมาชิก'],
    'redemption' => ['icon' => '🎁', 'label' => 'แลกของรางวัล']
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
?>

<?php if ($_useTransactions): ?>
<div class="mb-4 flex flex-wrap gap-2">
    <a href="?" class="px-3 py-1.5 rounded-lg text-sm <?= !$typeFilter && !$viewDispense ? 'bg-purple-500 text-white' : 'bg-white hover:bg-gray-50' ?>">ทุกประเภท</a>
    <?php foreach ($transactionTypes as $key => $type): ?>
    <a href="?type=<?= $key ?><?= $statusFilter ? '&status='.$statusFilter : '' ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $typeFilter === $key ? 'bg-purple-500 text-white' : 'bg-white hover:bg-gray-50' ?>"><?= $type['icon'] ?> <?= $type['label'] ?></a>
    <?php endforeach; ?>
    <a href="?view=dispense" class="px-3 py-1.5 rounded-lg text-sm <?= $viewDispense ? 'bg-green-500 text-white' : 'bg-white hover:bg-gray-50' ?>">💊 จ่ายยา <?php if ($dispenseCount > 0): ?><span class="ml-1 px-1.5 py-0.5 bg-green-600 text-white rounded-full text-xs"><?= $dispenseCount ?></span><?php endif; ?></a>
</div>
<?php endif; ?>

<?php if (!$viewDispense): ?>
<?php if ($pendingSlipsCount > 0): ?>
<div class="mb-4 p-4 bg-orange-100 border border-orange-300 rounded-lg flex items-center justify-between">
    <div class="flex items-center">
        <i class="fas fa-receipt text-orange-500 text-xl mr-3"></i>
        <div>
            <p class="font-semibold text-orange-700">มีสลิปรอตรวจสอบ <?= $pendingSlipsCount ?> รายการ</p>
            <p class="text-sm text-orange-600">กรุณาตรวจสอบและอนุมัติสลิปการชำระเงิน</p>
        </div>
    </div>
    <a href="?pending_slip=1<?= $typeFilter ? '&type='.$typeFilter : '' ?>" class="px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600">
        <i class="fas fa-eye mr-1"></i>ดูรายการ
    </a>
</div>
<?php endif; ?>

<div class="mb-6 flex flex-wrap gap-2">
    <a href="?<?= $typeFilter ? 'type='.$typeFilter : '' ?>" class="px-4 py-2 rounded-lg <?= !$statusFilter && !isset($_GET['pending_slip']) ? 'bg-green-500 text-white' : 'bg-white hover:bg-gray-50' ?>">ทั้งหมด <span class="ml-1 text-sm">(<?= array_sum($statusCounts) ?>)</span></a>
    <?php if ($pendingSlipsCount > 0): ?>
    <a href="?pending_slip=1<?= $typeFilter ? '&type='.$typeFilter : '' ?>" class="px-4 py-2 rounded-lg flex items-center gap-2 <?= isset($_GET['pending_slip']) ? 'bg-orange-500 text-white' : 'bg-white hover:bg-gray-50' ?>">
        <i class="fas fa-receipt"></i>รอตรวจสลิป 
        <span class="px-2 py-0.5 bg-orange-600 text-white rounded-full text-xs"><?= $pendingSlipsCount ?></span>
    </a>
    <?php endif; ?>
    <?php
    $statuses = [
        'pending' => ['label' => 'รอยืนยัน', 'color' => 'yellow'],
        'confirmed' => ['label' => 'ยืนยันแล้ว', 'color' => 'blue'],
        'paid' => ['label' => 'ชำระแล้ว', 'color' => 'green'],
        'shipping' => ['label' => 'กำลังส่ง', 'color' => 'purple'],
        'delivered' => ['label' => 'ส่งแล้ว', 'color' => 'gray'],
        'cancelled' => ['label' => 'ยกเลิก', 'color' => 'red']
    ];
    foreach ($statuses as $key => $status):
    ?>
    <a href="?status=<?= $key ?><?= $typeFilter ? '&type='.$typeFilter : '' ?>" class="px-4 py-2 rounded-lg <?= $statusFilter === $key ? 'bg-'.$status['color'].'-500 text-white' : 'bg-white hover:bg-gray-50' ?>"><?= $status['label'] ?> <span class="ml-1 text-sm">(<?= $statusCounts[$key] ?? 0 ?>)</span></a>
    <?php endforeach; ?>
</div>

<div class="space-y-4">
    <?php foreach ($orders as $order): ?>
    <?php 
    $transType = $order['transaction_type'] ?? 'purchase'; 
    $typeInfo = $transactionTypes[$transType] ?? $transactionTypes['purchase'];
    $hasPendingSlip = in_array($order['id'], $ordersWithPendingSlips);
    $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
    ?>
    <div class="bg-white rounded-xl shadow overflow-hidden <?= $hasPendingSlip ? 'ring-2 ring-orange-400' : '' ?>">
        <?php if ($hasPendingSlip): ?>
        <div class="bg-orange-500 text-white px-4 py-2 text-sm flex items-center">
            <i class="fas fa-receipt mr-2"></i>
            <span class="font-semibold">มีสลิปรอตรวจสอบ</span>
            <a href="order-detail.php?id=<?= $order['id'] ?>" class="ml-auto bg-white text-orange-500 px-3 py-1 rounded text-xs font-semibold hover:bg-orange-50">ตรวจสอบเลย</a>
        </div>
        <?php endif; ?>
        <div class="p-4 border-b flex justify-between items-center">
            <div class="flex items-center">
                <img src="<?= $order['picture_url'] ?: 'https://via.placeholder.com/40' ?>" class="w-10 h-10 rounded-full mr-3">
                <div>
                    <div class="flex items-center gap-2">
                        <p class="font-semibold">#<?= $order['order_number'] ?></p>
                        <?php if ($_useTransactions && $transType !== 'purchase'): ?>
                        <span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs"><?= $typeInfo['icon'] ?> <?= $typeInfo['label'] ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-500"><?= $order['display_name'] ?> • <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
                </div>
            </div>
            <div class="text-right">
                <?php $statusColors = ['pending' => 'bg-yellow-100 text-yellow-600', 'confirmed' => 'bg-blue-100 text-blue-600', 'paid' => 'bg-green-100 text-green-600', 'shipping' => 'bg-purple-100 text-purple-600', 'delivered' => 'bg-gray-100 text-gray-600', 'cancelled' => 'bg-red-100 text-red-600']; ?>
                <span class="px-3 py-1 rounded-full text-sm <?= $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-600' ?>"><?= $statuses[$order['status']]['label'] ?? $order['status'] ?></span>
                <p class="text-lg font-bold text-green-600 mt-1">฿<?= number_format($order['grand_total'], 2) ?></p>
            </div>
        </div>
        <?php if (!empty($deliveryInfo['name']) || !empty($deliveryInfo['phone']) || !empty($deliveryInfo['address'])): ?>
        <div class="px-4 py-3 bg-blue-50 border-b text-sm">
            <div class="flex items-start gap-4">
                <div class="text-blue-600"><i class="fas fa-truck"></i></div>
                <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-2">
                    <?php if (!empty($deliveryInfo['name'])): ?>
                    <div><span class="text-gray-500">ผู้รับ:</span> <span class="font-medium"><?= htmlspecialchars($deliveryInfo['name']) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($deliveryInfo['phone'])): ?>
                    <div><span class="text-gray-500">โทร:</span> <span class="font-medium"><?= htmlspecialchars($deliveryInfo['phone']) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($deliveryInfo['address'])): ?>
                    <div class="md:col-span-3"><span class="text-gray-500">ที่อยู่:</span> <span class="font-medium"><?= htmlspecialchars($deliveryInfo['address']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="p-4 bg-gray-50 flex justify-between items-center">
            <div class="text-sm text-gray-600">
                <span><i class="fas fa-box mr-1"></i><?= $order['item_count'] ?> รายการ</span>
                <?php if ($order['shipping_tracking']): ?>
                <span class="ml-4"><i class="fas fa-truck mr-1"></i><?= $order['shipping_tracking'] ?></span>
                <?php endif; ?>
            </div>
            <div class="flex space-x-2">
                <a href="order-detail.php?id=<?= $order['id'] ?>" class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50 text-sm"><i class="fas fa-eye mr-1"></i>ดูรายละเอียด</a>
                <?php if ($order['status'] === 'pending'): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                    <input type="hidden" name="status" value="confirmed">
                    <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm"><i class="fas fa-check mr-1"></i>ยืนยัน</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($orders)): ?>
    <div class="bg-white rounded-xl shadow p-8 text-center text-gray-500">
        <i class="fas fa-shopping-bag text-6xl mb-4"></i>
        <p>ยังไม่มีคำสั่งซื้อ</p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($viewDispense): ?>
<!-- Dispensing Records Section -->
<div class="mb-6 flex flex-wrap gap-2">
    <span class="px-4 py-2 bg-green-500 text-white rounded-lg">💊 รายการจ่ายยา (<?= count($dispenseRecords) ?>)</span>
</div>

<div class="space-y-4">
    <?php foreach ($dispenseRecords as $record): ?>
    <?php $items = json_decode($record['items'], true) ?: []; ?>
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="p-4 border-b flex justify-between items-center bg-green-50">
            <div class="flex items-center">
                <img src="<?= $record['picture_url'] ?: 'https://via.placeholder.com/40' ?>" class="w-10 h-10 rounded-full mr-3">
                <div>
                    <div class="flex items-center gap-2">
                        <p class="font-semibold text-green-700">#<?= $record['order_number'] ?></p>
                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">💊 จ่ายยา</span>
                    </div>
                    <p class="text-sm text-gray-500"><?= $record['display_name'] ?> • <?= date('d/m/Y H:i', strtotime($record['created_at'])) ?></p>
                </div>
            </div>
            <div class="text-right">
                <span class="px-3 py-1 rounded-full text-sm bg-green-100 text-green-600"><?= $record['payment_status'] === 'paid' ? '✅ ชำระแล้ว' : '⏳ รอชำระ' ?></span>
                <p class="text-lg font-bold text-green-600 mt-1">฿<?= number_format($record['total_amount'], 2) ?></p>
            </div>
        </div>
        
        <!-- Items List -->
        <div class="p-4 space-y-3">
            <?php foreach ($items as $item): ?>
            <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                <div class="flex-shrink-0">
                    <?php if (!empty($item['isMedicine']) && $item['isMedicine'] !== false): ?>
                    <span class="text-2xl"><?= ($item['usageType'] ?? 'internal') === 'external' ? '🧴' : '💊' ?></span>
                    <?php else: ?>
                    <span class="text-2xl">📦</span>
                    <?php endif; ?>
                </div>
                <div class="flex-1">
                    <p class="font-medium text-gray-800"><?= htmlspecialchars($item['name']) ?></p>
                    <p class="text-sm text-gray-500">จำนวน: <?= $item['qty'] ?> <?= $item['unit'] ?? 'ชิ้น' ?></p>
                    
                    <?php if (!empty($item['isMedicine']) && $item['isMedicine'] !== false): ?>
                    <div class="mt-2 text-xs space-y-1">
                        <?php if (!empty($item['indication'])): ?>
                        <p class="text-blue-600">📋 ข้อบ่งใช้: <?= htmlspecialchars($item['indication']) ?></p>
                        <?php endif; ?>
                        <p class="text-purple-600">
                            💊 รับประทานครั้งละ <?= $item['dosage'] ?? 1 ?> <?= $item['dosageUnit'] ?? 'เม็ด' ?> 
                            <?php 
                            $freq = $item['frequency'] ?? '3';
                            echo $freq === 'prn' ? 'เมื่อมีอาการ' : $freq . ' ครั้ง/วัน';
                            ?>
                        </p>
                        <?php 
                        $mealText = ['before' => 'ก่อนอาหาร', 'after' => 'หลังอาหาร', 'with' => 'พร้อมอาหาร'];
                        $timeIcons = ['morning' => '🌅', 'noon' => '☀️', 'evening' => '🌆', 'bedtime' => '🌙'];
                        ?>
                        <p class="text-yellow-600">
                            ⏰ <?= $mealText[$item['mealTiming'] ?? 'after'] ?? 'หลังอาหาร' ?>
                            <?php if (!empty($item['timeOfDay'])): ?>
                            | <?= implode(' ', array_map(fn($t) => $timeIcons[$t] ?? '', $item['timeOfDay'])) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($item['notes'])): ?>
                    <p class="mt-1 text-xs text-gray-500">📝 <?= htmlspecialchars($item['notes']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-right">
                    <p class="font-bold text-green-600">฿<?= number_format(($item['price'] ?? 0) * ($item['qty'] ?? 1), 2) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="p-4 bg-gray-50 flex justify-between items-center border-t">
            <div class="text-sm text-gray-600">
                <span><i class="fas fa-box mr-1"></i><?= count($items) ?> รายการ</span>
                <?php 
                $paymentText = ['cash' => '💵 เงินสด', 'transfer' => '📱 โอนเงิน', 'credit' => '💳 บัตรเครดิต', 'later' => '⏰ จ่ายทีหลัง'];
                ?>
                <span class="ml-4"><?= $paymentText[$record['payment_method']] ?? $record['payment_method'] ?></span>
            </div>
            <a href="../chat.php?user=<?= $record['user_id'] ?>" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                <i class="fas fa-comments mr-1"></i>แชท
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($dispenseRecords)): ?>
    <div class="bg-white rounded-xl shadow p-8 text-center text-gray-500">
        <i class="fas fa-pills text-6xl mb-4"></i>
        <p>ยังไม่มีรายการจ่ายยา</p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
