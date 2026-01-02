<?php
/**
 * Sales Reports - รายงานยอดขายแบบครบวงจร
 * รวม: ยอดขาย, CRM, คลังสินค้า, Broadcast, Triage
 */

$pageTitle = 'รายงานยอดขาย';
require_once __DIR__ . '/../includes/header.php';

// Get date range
$startDate = $_GET['start'] ?? date('Y-m-01');
$endDate = $_GET['end'] ?? date('Y-m-d');
$period = $_GET['period'] ?? 'month';

// Quick period selection
if ($period === 'today') {
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d');
} elseif ($period === 'week') {
    $startDate = date('Y-m-d', strtotime('-7 days'));
    $endDate = date('Y-m-d');
} elseif ($period === 'month') {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
} elseif ($period === 'year') {
    $startDate = date('Y-01-01');
    $endDate = date('Y-m-d');
}

try {
    $db = Database::getInstance()->getConnection();
    $lineAccountId = $_SESSION['current_bot_id'] ?? null;
    
    // === Sales Summary ===
    $salesQuery = "SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as total_revenue,
        COALESCE(AVG(CASE WHEN status = 'completed' THEN total_amount ELSE NULL END), 0) as avg_order_value
        FROM transactions 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND (line_account_id = ? OR line_account_id IS NULL)";
    $stmt = $db->prepare($salesQuery);
    $stmt->execute([$startDate, $endDate, $lineAccountId]);
    $salesSummary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // === Daily Sales Chart Data ===
    $dailySalesQuery = "SELECT 
        DATE(created_at) as date,
        COUNT(*) as orders,
        COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as revenue
        FROM transactions 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND (line_account_id = ? OR line_account_id IS NULL)
        GROUP BY DATE(created_at)
        ORDER BY date";
    $stmt = $db->prepare($dailySalesQuery);
    $stmt->execute([$startDate, $endDate, $lineAccountId]);
    $dailySales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // === Top Products ===
    $topProductsQuery = "SELECT 
        p.name as product_name,
        SUM(ti.quantity) as total_qty,
        SUM(ti.quantity * ti.price) as total_revenue
        FROM transaction_items ti
        JOIN products p ON ti.product_id = p.id
        JOIN transactions t ON ti.transaction_id = t.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?
        AND t.status = 'completed'
        AND (t.line_account_id = ? OR t.line_account_id IS NULL)
        GROUP BY ti.product_id
        ORDER BY total_revenue DESC
        LIMIT 10";
    $stmt = $db->prepare($topProductsQuery);
    $stmt->execute([$startDate, $endDate, $lineAccountId]);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // === Customer Stats ===
    $customerQuery = "SELECT 
        COUNT(DISTINCT u.id) as total_customers,
        COUNT(DISTINCT CASE WHEN DATE(u.created_at) BETWEEN ? AND ? THEN u.id END) as new_customers
        FROM users u
        WHERE (u.line_account_id = ? OR u.line_account_id IS NULL)";
    $stmt = $db->prepare($customerQuery);
    $stmt->execute([$startDate, $endDate, $lineAccountId]);
    $customerStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // === Broadcast Stats ===
    $broadcastQuery = "SELECT 
        COUNT(*) as total_broadcasts,
        COALESCE(SUM(sent_count), 0) as total_sent,
        COALESCE(SUM(success_count), 0) as total_success
        FROM broadcast_logs 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND (line_account_id = ? OR line_account_id IS NULL)";
    $stmt = $db->prepare($broadcastQuery);
    $stmt->execute([$startDate, $endDate, $lineAccountId]);
    $broadcastStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // === Inventory Stats ===
    $inventoryQuery = "SELECT 
        COUNT(*) as total_products,
        SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN stock > 0 AND stock <= low_stock_threshold THEN 1 ELSE 0 END) as low_stock
        FROM products 
        WHERE (line_account_id = ? OR line_account_id IS NULL)
        AND is_active = 1";
    $stmt = $db->prepare($inventoryQuery);
    $stmt->execute([$lineAccountId]);
    $inventoryStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // === Recent Orders ===
    $recentOrdersQuery = "SELECT 
        t.id, t.order_number, t.total_amount, t.status, t.created_at,
        u.display_name as customer_name
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?
        AND (t.line_account_id = ? OR t.line_account_id IS NULL)
        ORDER BY t.created_at DESC
        LIMIT 10";
    $stmt = $db->prepare($recentOrdersQuery);
    $stmt->execute([$startDate, $endDate, $lineAccountId]);
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $salesSummary = ['total_orders' => 0, 'completed_orders' => 0, 'pending_orders' => 0, 'cancelled_orders' => 0, 'total_revenue' => 0, 'avg_order_value' => 0];
    $dailySales = [];
    $topProducts = [];
    $customerStats = ['total_customers' => 0, 'new_customers' => 0];
    $broadcastStats = ['total_broadcasts' => 0, 'total_sent' => 0, 'total_success' => 0];
    $inventoryStats = ['total_products' => 0, 'out_of_stock' => 0, 'low_stock' => 0];
    $recentOrders = [];
}

// Prepare chart data
$chartLabels = array_column($dailySales, 'date');
$chartRevenue = array_column($dailySales, 'revenue');
$chartOrders = array_column($dailySales, 'orders');
?>

<div class="content-area">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-800">📊 รายงานยอดขาย</h2>
            <p class="text-sm text-gray-500">ภาพรวมยอดขาย ลูกค้า สินค้า และการตลาด</p>
        </div>
        
        <!-- Date Filter -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex bg-white rounded-lg border overflow-hidden">
                <a href="?period=today" class="px-3 py-2 text-sm <?= $period === 'today' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">วันนี้</a>
                <a href="?period=week" class="px-3 py-2 text-sm <?= $period === 'week' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">7 วัน</a>
                <a href="?period=month" class="px-3 py-2 text-sm <?= $period === 'month' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">เดือนนี้</a>
                <a href="?period=year" class="px-3 py-2 text-sm <?= $period === 'year' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">ปีนี้</a>
            </div>
            <form class="flex items-center gap-2">
                <input type="date" name="start" value="<?= $startDate ?>" class="px-3 py-2 border rounded-lg text-sm">
                <span class="text-gray-400">-</span>
                <input type="date" name="end" value="<?= $endDate ?>" class="px-3 py-2 border rounded-lg text-sm">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
        <!-- Revenue -->
        <div class="bg-white rounded-xl p-4 shadow-sm border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500">รายได้รวม</p>
                    <p class="text-xl font-bold text-gray-800">฿<?= number_format($salesSummary['total_revenue']) ?></p>
                </div>
                <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-baht-sign text-green-600"></i>
                </div>
            </div>
        </div>
        
        <!-- Orders -->
        <div class="bg-white rounded-xl p-4 shadow-sm border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500">ออเดอร์ทั้งหมด</p>
                    <p class="text-xl font-bold text-gray-800"><?= number_format($salesSummary['total_orders']) ?></p>
                </div>
                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-shopping-cart text-blue-600"></i>
                </div>
            </div>
            <div class="mt-2 text-xs">
                <span class="text-green-600"><?= $salesSummary['completed_orders'] ?> สำเร็จ</span> |
                <span class="text-yellow-600"><?= $salesSummary['pending_orders'] ?> รอ</span>
            </div>
        </div>
        
        <!-- Avg Order -->
        <div class="bg-white rounded-xl p-4 shadow-sm border-l-4 border-purple-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500">เฉลี่ย/ออเดอร์</p>
                    <p class="text-xl font-bold text-gray-800">฿<?= number_format($salesSummary['avg_order_value']) ?></p>
                </div>
                <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-chart-line text-purple-600"></i>
                </div>
            </div>
        </div>
        
        <!-- Customers -->
        <div class="bg-white rounded-xl p-4 shadow-sm border-l-4 border-cyan-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500">ลูกค้าใหม่</p>
                    <p class="text-xl font-bold text-gray-800"><?= number_format($customerStats['new_customers']) ?></p>
                </div>
                <div class="w-10 h-10 bg-cyan-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-plus text-cyan-600"></i>
                </div>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                รวม <?= number_format($customerStats['total_customers']) ?> คน
            </div>
        </div>
        
        <!-- Broadcast -->
        <div class="bg-white rounded-xl p-4 shadow-sm border-l-4 border-orange-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500">Broadcast</p>
                    <p class="text-xl font-bold text-gray-800"><?= number_format($broadcastStats['total_broadcasts']) ?></p>
                </div>
                <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-paper-plane text-orange-600"></i>
                </div>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                ส่ง <?= number_format($broadcastStats['total_sent']) ?> ข้อความ
            </div>
        </div>
        
        <!-- Inventory -->
        <div class="bg-white rounded-xl p-4 shadow-sm border-l-4 border-red-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500">สินค้าหมด</p>
                    <p class="text-xl font-bold text-gray-800"><?= number_format($inventoryStats['out_of_stock']) ?></p>
                </div>
                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-box-open text-red-600"></i>
                </div>
            </div>
            <div class="mt-2 text-xs text-yellow-600">
                <?= $inventoryStats['low_stock'] ?> ใกล้หมด
            </div>
        </div>
    </div>
    
    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Revenue Chart -->
        <div class="bg-white rounded-xl p-4 shadow-sm">
            <h3 class="font-semibold text-gray-800 mb-4">📈 กราฟยอดขาย</h3>
            <canvas id="revenueChart" height="200"></canvas>
        </div>
        
        <!-- Orders Chart -->
        <div class="bg-white rounded-xl p-4 shadow-sm">
            <h3 class="font-semibold text-gray-800 mb-4">📦 กราฟออเดอร์</h3>
            <canvas id="ordersChart" height="200"></canvas>
        </div>
    </div>
    
    <!-- Tables Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Top Products -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="p-4 border-b">
                <h3 class="font-semibold text-gray-800">🏆 สินค้าขายดี</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left">#</th>
                            <th class="px-4 py-3 text-left">สินค้า</th>
                            <th class="px-4 py-3 text-right">จำนวน</th>
                            <th class="px-4 py-3 text-right">ยอดขาย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topProducts)): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">ไม่มีข้อมูล</td></tr>
                        <?php else: ?>
                        <?php foreach ($topProducts as $i => $product): ?>
                        <tr class="border-t hover:bg-gray-50">
                            <td class="px-4 py-3"><?= $i + 1 ?></td>
                            <td class="px-4 py-3 font-medium"><?= htmlspecialchars($product['product_name']) ?></td>
                            <td class="px-4 py-3 text-right"><?= number_format($product['total_qty']) ?></td>
                            <td class="px-4 py-3 text-right text-green-600">฿<?= number_format($product['total_revenue']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Orders -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="p-4 border-b flex items-center justify-between">
                <h3 class="font-semibold text-gray-800">🛒 ออเดอร์ล่าสุด</h3>
                <a href="/shop/orders" class="text-sm text-purple-600 hover:underline">ดูทั้งหมด</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left">เลขออเดอร์</th>
                            <th class="px-4 py-3 text-left">ลูกค้า</th>
                            <th class="px-4 py-3 text-right">ยอด</th>
                            <th class="px-4 py-3 text-center">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentOrders)): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">ไม่มีข้อมูล</td></tr>
                        <?php else: ?>
                        <?php foreach ($recentOrders as $order): ?>
                        <tr class="border-t hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="/shop/orders?id=<?= $order['id'] ?>" class="text-purple-600 hover:underline">
                                    #<?= $order['order_number'] ?? $order['id'] ?>
                                </a>
                            </td>
                            <td class="px-4 py-3"><?= htmlspecialchars($order['customer_name'] ?? 'ไม่ระบุ') ?></td>
                            <td class="px-4 py-3 text-right">฿<?= number_format($order['total_amount']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php
                                $statusColors = [
                                    'pending' => 'bg-yellow-100 text-yellow-700',
                                    'confirmed' => 'bg-blue-100 text-blue-700',
                                    'completed' => 'bg-green-100 text-green-700',
                                    'cancelled' => 'bg-red-100 text-red-700',
                                ];
                                $statusLabels = [
                                    'pending' => 'รอดำเนินการ',
                                    'confirmed' => 'ยืนยันแล้ว',
                                    'completed' => 'สำเร็จ',
                                    'cancelled' => 'ยกเลิก',
                                ];
                                $color = $statusColors[$order['status']] ?? 'bg-gray-100 text-gray-700';
                                $label = $statusLabels[$order['status']] ?? $order['status'];
                                ?>
                                <span class="px-2 py-1 rounded-full text-xs <?= $color ?>"><?= $label ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Revenue Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'รายได้ (บาท)',
            data: <?= json_encode($chartRevenue) ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '฿' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Orders Chart
const ordersCtx = document.getElementById('ordersChart').getContext('2d');
new Chart(ordersCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'ออเดอร์',
            data: <?= json_encode($chartOrders) ?>,
            backgroundColor: '#8b5cf6',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
