<?php
/**
 * Shop Dashboard - หน้าหลักระบบร้านค้า
 * V3.0 - Unified Shop System
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/shop-data-source.php';
if (file_exists(__DIR__ . '/../classes/UnifiedShop.php')) {
    require_once __DIR__ . '/../classes/UnifiedShop.php';
}

$db = Database::getInstance()->getConnection();
$pageTitle = 'ภาพรวมร้านค้า';
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Initialize UnifiedShop
$shop = new UnifiedShop($db, null, $currentBotId);
$settings = $shop->getSettings();
$orderDataSource = getShopOrderDataSource($db, $currentBotId);
$isOdooMode = ($orderDataSource === 'odoo')
    && defined('ODOO_INTEGRATION_ENABLED')
    && ODOO_INTEGRATION_ENABLED === true;

// Get statistics
$stats = [
    'products' => 0,
    'active_products' => 0,
    'low_stock' => 0,
    'out_of_stock' => 0,
    'orders_today' => 0,
    'orders_pending' => 0,
    'orders_total' => 0,
    'revenue_today' => 0,
    'revenue_month' => 0
];

$recentOrders = [];

$productsTable = $shop->getItemsTable();
$ordersTable = $shop->getOrdersTable();

if ($productsTable) {
    try {
        // Product stats - แสดงสินค้าทั้งหมด ไม่ filter ตาม line_account_id
        $stmt = $db->query("SELECT 
            COUNT(*) as total,
            SUM(is_active = 1) as active,
            SUM(stock > 0 AND stock <= 5) as low_stock,
            SUM(stock <= 0) as out_of_stock
            FROM {$productsTable}");
        $productStats = $stmt->fetch();
        
        $stats['products'] = $productStats['total'] ?? 0;
        $stats['active_products'] = $productStats['active'] ?? 0;
        $stats['low_stock'] = $productStats['low_stock'] ?? 0;
        $stats['out_of_stock'] = $productStats['out_of_stock'] ?? 0;
    } catch (Exception $e) {}
}

if ($ordersTable && !$isOdooMode) {
    try {
        // Order stats
        $stmt = $db->prepare("SELECT 
            COUNT(*) as total,
            SUM(DATE(created_at) = CURDATE()) as today,
            SUM(status = 'pending') as pending
            FROM {$ordersTable} WHERE line_account_id = ? OR line_account_id IS NULL");
        $stmt->execute([$currentBotId]);
        $orderStats = $stmt->fetch();

        $stats['orders_total'] = $orderStats['total'] ?? 0;
        $stats['orders_today'] = $orderStats['today'] ?? 0;
        $stats['orders_pending'] = $orderStats['pending'] ?? 0;
        
        // Revenue stats
        $grandTotalCol = $shop->hasColumn($ordersTable, 'grand_total') ? 'grand_total' : 'total_amount';
        
        $stmt = $db->prepare("SELECT 
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN {$grandTotalCol} ELSE 0 END), 0) as today,
            COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN {$grandTotalCol} ELSE 0 END), 0) as month
            FROM {$ordersTable} 
            WHERE (line_account_id = ? OR line_account_id IS NULL) 
            AND status NOT IN ('cancelled', 'refunded')");
        $stmt->execute([$currentBotId]);
        $revenueStats = $stmt->fetch();
        
        $stats['revenue_today'] = $revenueStats['today'] ?? 0;
        $stats['revenue_month'] = $revenueStats['month'] ?? 0;
    } catch (Exception $e) {}
} elseif ($isOdooMode) {
    try {
        $db->query("SELECT 1 FROM odoo_webhooks_log LIMIT 1");

        $orderKeyExpr = "COALESCE(CAST(order_id AS CHAR), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')))";
        $stateExpr = "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state')), ''))";
        $amountExpr = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), '0') AS DECIMAL(12,2))";
        $customerExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '-')";

        $where = "status = 'success' AND {$orderKeyExpr} IS NOT NULL AND {$orderKeyExpr} != ''";
        $params = [];
        if ($shop->hasColumn('odoo_webhooks_log', 'line_account_id')) {
            $where .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $currentBotId;
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

        $statsSql = "
            SELECT
                COUNT(*) AS total,
                SUM(DATE(created_at) = CURDATE()) AS today,
                SUM(status IN ('draft','sent','pending','confirmed')) AS pending,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() AND status NOT IN ('cancel','cancelled') THEN amount_total ELSE 0 END), 0) AS revenue_today,
                COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status NOT IN ('cancel','cancelled') THEN amount_total ELSE 0 END), 0) AS revenue_month
            FROM (
                SELECT
                    order_key,
                    MIN(processed_at) AS created_at,
                    MAX(amount_total) AS amount_total,
                    SUBSTRING_INDEX(GROUP_CONCAT(order_state ORDER BY processed_at DESC), ',', 1) AS status
                FROM ({$baseSubquery}) s
                GROUP BY order_key
            ) o
        ";

        $stmt = $db->prepare($statsSql);
        $stmt->execute($params);
        $odooStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stats['orders_total'] = (int) ($odooStats['total'] ?? 0);
        $stats['orders_today'] = (int) ($odooStats['today'] ?? 0);
        $stats['orders_pending'] = (int) ($odooStats['pending'] ?? 0);
        $stats['revenue_today'] = (float) ($odooStats['revenue_today'] ?? 0);
        $stats['revenue_month'] = (float) ($odooStats['revenue_month'] ?? 0);

        $recentSql = "
            SELECT
                order_key AS order_number,
                MIN(processed_at) AS created_at,
                MAX(amount_total) AS total_amount,
                SUBSTRING_INDEX(GROUP_CONCAT(order_state ORDER BY processed_at DESC), ',', 1) AS status,
                SUBSTRING_INDEX(GROUP_CONCAT(customer_name ORDER BY processed_at DESC), ',', 1) AS display_name
            FROM ({$baseSubquery}) s
            GROUP BY order_key
            ORDER BY created_at DESC
            LIMIT 5
        ";

        $stmt = $db->prepare($recentSql);
        $stmt->execute($params);
        $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ordersTable = 'odoo_webhooks_log';
    } catch (Exception $e) {
        $recentOrders = [];
    }
}

// Get recent orders
if ($ordersTable && !$isOdooMode) {
    try {
        $stmt = $db->prepare("SELECT * FROM {$ordersTable} 
            WHERE line_account_id = ? OR line_account_id IS NULL 
            ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$currentBotId]);
        $recentOrders = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Get low stock products
$lowStockProducts = [];
if ($productsTable) {
    try {
        $stmt = $db->prepare("SELECT * FROM {$productsTable} 
            WHERE (line_account_id = ? OR line_account_id IS NULL) 
            AND stock <= 5 AND is_active = 1
            ORDER BY stock ASC LIMIT 5");
        $stmt->execute([$currentBotId]);
        $lowStockProducts = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Get pending slips count
$pendingSlips = 0;
$ordersWithPendingSlips = [];
if (!$isOdooMode) {
    try {
        $stmt = $db->prepare("SELECT DISTINCT t.id, t.order_number, t.grand_total, t.created_at, ps.image_url
            FROM transactions t 
            INNER JOIN payment_slips ps ON ps.transaction_id = t.id 
            WHERE ps.status = 'pending' AND (t.line_account_id = ? OR t.line_account_id IS NULL)
            ORDER BY ps.created_at DESC LIMIT 5");
        $stmt->execute([$currentBotId]);
        $ordersWithPendingSlips = $stmt->fetchAll();
        $pendingSlips = count($ordersWithPendingSlips);
        
        // Get total count
        $stmt = $db->prepare("SELECT COUNT(DISTINCT t.id) FROM transactions t 
            INNER JOIN payment_slips ps ON ps.transaction_id = t.id 
            WHERE ps.status = 'pending' AND (t.line_account_id = ? OR t.line_account_id IS NULL)");
        $stmt->execute([$currentBotId]);
        $pendingSlips = $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) {}
}

require_once '../includes/header.php';

// Status colors
$statusColors = [
    'draft' => 'gray',
    'sent' => 'blue',
    'sale' => 'green',
    'done' => 'green',
    'cancel' => 'red',
    'pending' => 'yellow',
    'confirmed' => 'blue',
    'paid' => 'green',
    'processing' => 'purple',
    'shipping' => 'indigo',
    'delivered' => 'green',
    'completed' => 'green',
    'cancelled' => 'gray',
    'refunded' => 'red'
];

$statusLabels = [
    'draft' => 'รอดำเนินการ',
    'sent' => 'ส่งใบเสนอราคา',
    'sale' => 'ยืนยันแล้ว',
    'done' => 'เสร็จสิ้น',
    'cancel' => 'ยกเลิก',
    'pending' => 'รอชำระเงิน',
    'confirmed' => 'ยืนยันแล้ว',
    'paid' => 'ชำระแล้ว',
    'processing' => 'กำลังเตรียม',
    'shipping' => 'กำลังจัดส่ง',
    'delivered' => 'จัดส่งแล้ว',
    'completed' => 'เสร็จสิ้น',
    'cancelled' => 'ยกเลิก',
    'refunded' => 'คืนเงิน'
];

// Payment status labels for COD
$paymentStatusLabels = [
    'pending' => 'รอชำระเงิน',
    'cod_pending' => 'COD - รอเก็บเงินปลายทาง',
    'paid' => 'ชำระแล้ว',
    'failed' => 'ชำระไม่สำเร็จ',
    'refunded' => 'คืนเงินแล้ว'
];
?>

<?php if ($pendingSlips > 0): ?>
<!-- Pending Slips Alert -->
<div class="mb-6 p-4 bg-orange-100 border border-orange-300 rounded-xl">
    <div class="flex items-center justify-between">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-orange-500 rounded-full flex items-center justify-center mr-4">
                <i class="fas fa-receipt text-white text-xl"></i>
            </div>
            <div>
                <h3 class="font-bold text-orange-700 text-lg">มีสลิปรอตรวจสอบ <?= $pendingSlips ?> รายการ</h3>
                <p class="text-sm text-orange-600">ลูกค้าอัพโหลดสลิปแล้ว กรุณาตรวจสอบและอนุมัติ</p>
            </div>
        </div>
        <a href="orders.php?pending_slip=1" class="px-6 py-3 bg-orange-500 text-white rounded-lg hover:bg-orange-600 font-semibold flex items-center gap-2">
            <i class="fas fa-eye"></i>ตรวจสอบเลย
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Shop Status -->
<div class="mb-6 p-4 rounded-xl <?= ($settings['is_open'] ?? 1) ? 'bg-green-100' : 'bg-red-100' ?>">
    <div class="flex items-center justify-between">
        <div class="flex items-center">
            <i class="fas <?= ($settings['is_open'] ?? 1) ? 'fa-store text-green-500' : 'fa-store-slash text-red-500' ?> text-2xl mr-3"></i>
            <div>
                <h2 class="font-bold text-lg"><?= htmlspecialchars($settings['shop_name'] ?? 'LINE Shop') ?></h2>
                <p class="text-sm <?= ($settings['is_open'] ?? 1) ? 'text-green-600' : 'text-red-600' ?>">
                    <?= ($settings['is_open'] ?? 1) ? '🟢 เปิดให้บริการ' : '🔴 ปิดให้บริการ' ?>
                </p>
            </div>
        </div>
        <a href="settings.php" class="px-4 py-2 bg-white rounded-lg shadow hover:shadow-md">
            <i class="fas fa-cog mr-2"></i>ตั้งค่า
        </a>
    </div>
</div>

<?php if ($isOdooMode): ?>
<div class="mb-6 p-4 bg-indigo-50 border border-indigo-200 rounded-xl text-indigo-800">
    <div class="flex items-center gap-2">
        <i class="fas fa-database"></i>
        <span class="font-semibold">โหมดข้อมูล: Odoo</span>
    </div>
    <p class="text-sm mt-1">Dashboard นี้ดึงยอดขาย/ออเดอร์จากข้อมูลที่รับเข้าจาก Odoo</p>
</div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">ยอดขายวันนี้</p>
                <p class="text-2xl font-bold text-green-600">฿<?= number_format($stats['revenue_today']) ?></p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-coins text-green-500 text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">ยอดขายเดือนนี้</p>
                <p class="text-2xl font-bold text-blue-600">฿<?= number_format($stats['revenue_month']) ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-chart-line text-blue-500 text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">ออเดอร์วันนี้</p>
                <p class="text-2xl font-bold"><?= number_format($stats['orders_today']) ?></p>
            </div>
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-shopping-bag text-purple-500 text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">รอดำเนินการ</p>
                <p class="text-2xl font-bold text-yellow-600"><?= number_format($stats['orders_pending']) ?></p>
            </div>
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-clock text-yellow-500 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
    <a href="orders.php" class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition-shadow text-center relative">
        <?php if ($stats['orders_pending'] > 0): ?>
        <span class="absolute -top-2 -right-2 w-6 h-6 bg-yellow-500 text-white text-xs rounded-full flex items-center justify-center font-bold"><?= $stats['orders_pending'] ?></span>
        <?php endif; ?>
        <div class="w-14 h-14 bg-blue-100 rounded-xl flex items-center justify-center mx-auto mb-2">
            <i class="fas fa-receipt text-blue-500 text-2xl"></i>
        </div>
        <p class="font-semibold text-sm">คำสั่งซื้อ</p>
        <p class="text-xs text-gray-500"><?= $stats['orders_total'] ?> รายการ</p>
    </a>
    
    <?php if ($pendingSlips > 0): ?>
    <a href="orders.php?pending_slip=1" class="bg-orange-50 rounded-xl shadow p-4 hover:shadow-lg transition-shadow text-center border-2 border-orange-300 relative">
        <span class="absolute -top-2 -right-2 w-6 h-6 bg-orange-500 text-white text-xs rounded-full flex items-center justify-center font-bold animate-pulse"><?= $pendingSlips ?></span>
        <div class="w-14 h-14 bg-orange-100 rounded-xl flex items-center justify-center mx-auto mb-2">
            <i class="fas fa-file-invoice text-orange-500 text-2xl"></i>
        </div>
        <p class="font-semibold text-sm text-orange-700">รอตรวจสลิป</p>
        <p class="text-xs text-orange-600">ต้องตรวจสอบ</p>
    </a>
    <?php endif; ?>
    
    <a href="products.php" class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition-shadow text-center relative">
        <?php if ($stats['low_stock'] > 0): ?>
        <span class="absolute -top-2 -right-2 w-6 h-6 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold"><?= $stats['low_stock'] ?></span>
        <?php endif; ?>
        <div class="w-14 h-14 bg-green-100 rounded-xl flex items-center justify-center mx-auto mb-2">
            <i class="fas fa-box text-green-500 text-2xl"></i>
        </div>
        <p class="font-semibold text-sm">สินค้า</p>
        <p class="text-xs text-gray-500"><?= $stats['products'] ?> รายการ</p>
    </a>
    
    <a href="categories.php" class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition-shadow text-center">
        <div class="w-14 h-14 bg-purple-100 rounded-xl flex items-center justify-center mx-auto mb-2">
            <i class="fas fa-folder text-purple-500 text-2xl"></i>
        </div>
        <p class="font-semibold text-sm">หมวดหมู่</p>
        <p class="text-xs text-gray-500">จัดการ</p>
    </a>
    
    <a href="../liff-redeem-points.php" class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition-shadow text-center">
        <div class="w-14 h-14 bg-yellow-100 rounded-xl flex items-center justify-center mx-auto mb-2">
            <i class="fas fa-coins text-yellow-500 text-2xl"></i>
        </div>
        <p class="font-semibold text-sm">แต้มสะสม</p>
        <p class="text-xs text-gray-500">Loyalty</p>
    </a>
    
    <a href="settings.php" class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition-shadow text-center">
        <div class="w-14 h-14 bg-gray-100 rounded-xl flex items-center justify-center mx-auto mb-2">
            <i class="fas fa-cog text-gray-500 text-2xl"></i>
        </div>
        <p class="font-semibold text-sm">ตั้งค่า</p>
        <p class="text-xs text-gray-500">ร้านค้า</p>
    </a>
</div>

<?php if (!empty($ordersWithPendingSlips)): ?>
<!-- Pending Slips Section -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b flex justify-between items-center bg-orange-50">
        <h3 class="font-semibold text-orange-700">
            <i class="fas fa-receipt mr-2"></i>สลิปรอตรวจสอบ
        </h3>
        <a href="orders.php?pending_slip=1" class="text-sm text-orange-500 hover:text-orange-600 font-medium">ดูทั้งหมด →</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-4">
        <?php foreach ($ordersWithPendingSlips as $slip): ?>
        <a href="order-detail.php?id=<?= $slip['id'] ?>" class="border-2 border-orange-200 rounded-xl p-3 hover:border-orange-400 hover:shadow-md transition-all flex items-center gap-3">
            <div class="w-16 h-16 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                <img src="<?= htmlspecialchars($slip['image_url']) ?>" class="w-full h-full object-cover">
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-semibold text-sm truncate">#<?= htmlspecialchars($slip['order_number']) ?></p>
                <p class="text-green-600 font-bold">฿<?= number_format($slip['grand_total']) ?></p>
                <p class="text-xs text-gray-500"><?= date('d/m H:i', strtotime($slip['created_at'])) ?></p>
            </div>
            <div class="flex-shrink-0">
                <span class="px-2 py-1 bg-orange-100 text-orange-600 text-xs rounded-full">รอตรวจ</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Recent Orders -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold">คำสั่งซื้อล่าสุด</h3>
            <a href="orders.php" class="text-sm text-green-500 hover:text-green-600">ดูทั้งหมด →</a>
        </div>
        <div class="divide-y">
            <?php if (empty($recentOrders)): ?>
            <div class="p-8 text-center text-gray-500">
                <i class="fas fa-inbox text-4xl mb-2"></i>
                <p>ยังไม่มีคำสั่งซื้อ</p>
            </div>
            <?php else: ?>
            <?php foreach ($recentOrders as $order): ?>
            <?php 
                $status = $order['status'] ?? 'pending';
                $color = $statusColors[$status] ?? 'gray';
                $label = $statusLabels[$status] ?? $status;
                $orderNum = str_replace('ORD', '', $order['order_number']);
                $grandTotal = $order['grand_total'] ?? $order['total_amount'] ?? 0;
                $detailUrl = (!$isOdooMode && !empty($order['id'])) ? ('order-detail.php?id=' . $order['id']) : null;
            ?>
            <?php if ($detailUrl): ?>
            <a href="<?= $detailUrl ?>" class="p-4 flex items-center justify-between hover:bg-gray-50">
            <?php else: ?>
            <div class="p-4 flex items-center justify-between">
            <?php endif; ?>
                <div>
                    <p class="font-medium">#<?= $orderNum ?></p>
                    <p class="text-sm text-gray-500"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
                </div>
                <div class="text-right">
                    <p class="font-bold text-green-600">฿<?= number_format($grandTotal) ?></p>
                    <span class="inline-block px-2 py-1 text-xs rounded-full bg-<?= $color ?>-100 text-<?= $color ?>-700"><?= $label ?></span>
                </div>
            <?php if ($detailUrl): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Low Stock Alert -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold">
                <i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i>
                สินค้าใกล้หมด
            </h3>
            <a href="products.php" class="text-sm text-green-500 hover:text-green-600">ดูทั้งหมด →</a>
        </div>
        <div class="divide-y">
            <?php if (empty($lowStockProducts)): ?>
            <div class="p-8 text-center text-gray-500">
                <i class="fas fa-check-circle text-green-500 text-4xl mb-2"></i>
                <p>สินค้าทุกรายการมีสต็อกเพียงพอ</p>
            </div>
            <?php else: ?>
            <?php foreach ($lowStockProducts as $product): ?>
            <div class="p-4 flex items-center justify-between">
                <div class="flex items-center">
                    <?php if ($product['image_url']): ?>
                    <img src="<?= htmlspecialchars($product['image_url']) ?>" class="w-12 h-12 rounded-lg object-cover mr-3">
                    <?php else: ?>
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center mr-3">
                        <i class="fas fa-image text-gray-300"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <p class="font-medium"><?= htmlspecialchars($product['name']) ?></p>
                        <p class="text-sm text-gray-500">฿<?= number_format($product['price']) ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <?php if ($product['stock'] <= 0): ?>
                    <span class="px-3 py-1 bg-red-100 text-red-700 rounded-full text-sm font-bold">หมด</span>
                    <?php else: ?>
                    <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-sm font-bold">เหลือ <?= $product['stock'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- System Info -->
<div class="mt-6 bg-white rounded-xl shadow p-4">
    <h3 class="font-semibold mb-3">ข้อมูลระบบ</h3>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div>
            <p class="text-gray-500">เวอร์ชัน</p>
            <p class="font-medium">UnifiedShop V3.0</p>
        </div>
        <div>
            <p class="text-gray-500">ตารางสินค้า</p>
            <p class="font-medium"><?= $productsTable ?? 'ไม่พบ' ?></p>
        </div>
        <div>
            <p class="text-gray-500">ตารางคำสั่งซื้อ</p>
            <p class="font-medium"><?= $ordersTable ?? 'ไม่พบ' ?></p>
        </div>
        <div>
            <p class="text-gray-500">โหมด</p>
            <p class="font-medium"><?= $isOdooMode ? 'Odoo (Webhook Data)' : 'Shop (Local DB)' ?></p>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
