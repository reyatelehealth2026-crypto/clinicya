<?php
/**
 * WMS Dashboard Sub-tab
 * Shows status counts, today's metrics, overdue orders, performance summary
 * Requirements: 6.1, 6.2, 6.3, 6.4
 */

require_once __DIR__ . '/../components/page-header.php';
require_once __DIR__ . '/../components/empty-state.php';

// Get dashboard stats (already loaded in parent)
$stats = $dashboardStats;
$statusCounts = $stats['status_counts'] ?? [];
$todayMetrics = $stats['today'] ?? [];

// Get overdue orders (SLA: 24 hours)
$overdueOrders = [];
try {
    $overdueOrders = $wmsService->getOverdueOrders(24);
} catch (Exception $e) {}

// Status labels and colors
$statusConfig = [
    'pending_pick' => ['label' => 'รอหยิบ', 'color' => 'yellow', 'icon' => 'fa-clock'],
    'picking' => ['label' => 'กำลังหยิบ', 'color' => 'blue', 'icon' => 'fa-hand-pointer'],
    'picked' => ['label' => 'หยิบแล้ว', 'color' => 'indigo', 'icon' => 'fa-check'],
    'packing' => ['label' => 'กำลังแพ็ค', 'color' => 'purple', 'icon' => 'fa-box-open'],
    'packed' => ['label' => 'แพ็คแล้ว', 'color' => 'pink', 'icon' => 'fa-box'],
    'ready_to_ship' => ['label' => 'พร้อมส่ง', 'color' => 'orange', 'icon' => 'fa-truck-loading'],
    'shipped' => ['label' => 'จัดส่งแล้ว', 'color' => 'green', 'icon' => 'fa-shipping-fast'],
    'on_hold' => ['label' => 'พักไว้', 'color' => 'red', 'icon' => 'fa-pause-circle'],
];
?>

<?= getPageHeaderStyles() ?>
<?= getEmptyStateStyles() ?>

<?= renderPageHeader(
    'WMS Dashboard',
    'ภาพรวมสถานะออเดอร์, เมตริกวันนี้ และออเดอร์เกิน SLA'
) ?>

<div class="space-y-6">
    <!-- Status Cards Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
        <?php foreach ($statusConfig as $status => $config): 
            $count = $statusCounts[$status] ?? 0;
        ?>
        <a href="?tab=wms&wms_tab=<?= in_array($status, ['pending_pick', 'picking']) ? 'pick' : (in_array($status, ['picked', 'packing']) ? 'pack' : ($status === 'on_hold' ? 'exceptions' : 'ship')) ?>" 
           class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition cursor-pointer group">
            <div class="flex items-center justify-between mb-2">
                <div class="w-10 h-10 bg-<?= $config['color'] ?>-100 rounded-lg flex items-center justify-center group-hover:scale-110 transition">
                    <i class="fas <?= $config['icon'] ?> text-<?= $config['color'] ?>-500"></i>
                </div>
                <span class="text-2xl font-bold text-gray-800"><?= number_format($count) ?></span>
            </div>
            <p class="text-xs text-gray-500 truncate"><?= $config['label'] ?></p>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Today's Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm">จัดส่งวันนี้</p>
                    <p class="text-3xl font-bold"><?= number_format($todayMetrics['shipped'] ?? 0) ?></p>
                </div>
                <i class="fas fa-shipping-fast text-4xl text-green-300 opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">หยิบวันนี้</p>
                    <p class="text-3xl font-bold"><?= number_format($todayMetrics['picked'] ?? 0) ?></p>
                </div>
                <i class="fas fa-hand-pointer text-4xl text-blue-300 opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm">แพ็ควันนี้</p>
                    <p class="text-3xl font-bold"><?= number_format($todayMetrics['packed'] ?? 0) ?></p>
                </div>
                <i class="fas fa-box text-4xl text-purple-300 opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-orange-500 to-orange-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-100 text-sm">เวลาเฉลี่ย</p>
                    <p class="text-3xl font-bold">
                        <?php 
                        $avgMinutes = $todayMetrics['avg_fulfillment_minutes'] ?? null;
                        if ($avgMinutes) {
                            if ($avgMinutes >= 60) {
                                echo number_format($avgMinutes / 60, 1) . ' ชม.';
                            } else {
                                echo number_format($avgMinutes, 0) . ' นาที';
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </p>
                </div>
                <i class="fas fa-clock text-4xl text-orange-300 opacity-50"></i>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- In Progress Summary -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-tasks text-blue-500 mr-2"></i>
                สรุปงานค้าง
            </h3>
            <div class="space-y-3">
                <div class="flex justify-between items-center p-3 bg-yellow-50 rounded-lg">
                    <span class="text-gray-600">รอหยิบสินค้า</span>
                    <span class="font-bold text-yellow-600"><?= number_format($statusCounts['pending_pick'] ?? 0) ?></span>
                </div>
                <div class="flex justify-between items-center p-3 bg-indigo-50 rounded-lg">
                    <span class="text-gray-600">รอแพ็ค</span>
                    <span class="font-bold text-indigo-600"><?= number_format($statusCounts['picked'] ?? 0) ?></span>
                </div>
                <div class="flex justify-between items-center p-3 bg-orange-50 rounded-lg">
                    <span class="text-gray-600">รอจัดส่ง</span>
                    <span class="font-bold text-orange-600"><?= number_format(($statusCounts['packed'] ?? 0) + ($statusCounts['ready_to_ship'] ?? 0)) ?></span>
                </div>
                <div class="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                    <span class="text-gray-600">มีปัญหา/พักไว้</span>
                    <span class="font-bold text-red-600"><?= number_format($statusCounts['on_hold'] ?? 0) ?></span>
                </div>
            </div>
        </div>

        <!-- Overdue Orders -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>
                ออเดอร์เกิน SLA (24 ชม.)
                <?php if (count($overdueOrders) > 0): ?>
                <span class="ml-2 px-2 py-0.5 bg-red-100 text-red-600 text-xs rounded-full"><?= count($overdueOrders) ?></span>
                <?php endif; ?>
            </h3>
            
            <?php if (empty($overdueOrders)): ?>
            <?= renderEmptyState('fas fa-check-circle', 'ไม่มีออเดอร์เกิน SLA', 'ทุกออเดอร์อยู่ในช่วงเวลาที่กำหนด') ?>
            <?php else: ?>
            <div class="space-y-2 max-h-64 overflow-y-auto">
                <?php foreach (array_slice($overdueOrders, 0, 10) as $order): ?>
                <div class="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                    <div>
                        <span class="font-medium text-gray-800">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></span>
                        <span class="text-xs text-gray-500 ml-2"><?= htmlspecialchars($order['customer_name'] ?? '-') ?></span>
                    </div>
                    <div class="text-right">
                        <span class="text-xs px-2 py-1 bg-<?= $statusConfig[$order['wms_status']]['color'] ?? 'gray' ?>-100 text-<?= $statusConfig[$order['wms_status']]['color'] ?? 'gray' ?>-600 rounded">
                            <?= $statusConfig[$order['wms_status']]['label'] ?? $order['wms_status'] ?>
                        </span>
                        <p class="text-xs text-red-500 mt-1"><?= $order['hours_since_order'] ?> ชม.</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($overdueOrders) > 10): ?>
            <p class="text-center text-sm text-gray-500 mt-3">และอีก <?= count($overdueOrders) - 10 ?> รายการ</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-semibold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-bolt text-yellow-500 mr-2"></i>
            Quick Actions
        </h3>
        <div class="flex flex-wrap gap-3">
            <a href="?tab=wms&wms_tab=pick" class="px-4 py-2 bg-yellow-100 text-yellow-700 rounded-lg hover:bg-yellow-200 transition">
                <i class="fas fa-hand-pointer mr-2"></i>เริ่มหยิบสินค้า
            </a>
            <a href="?tab=wms&wms_tab=pack" class="px-4 py-2 bg-purple-100 text-purple-700 rounded-lg hover:bg-purple-200 transition">
                <i class="fas fa-box mr-2"></i>เริ่มแพ็คสินค้า
            </a>
            <a href="?tab=wms&wms_tab=ship" class="px-4 py-2 bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition">
                <i class="fas fa-shipping-fast mr-2"></i>บันทึกเลขพัสดุ
            </a>
            <?php if (($statusCounts['on_hold'] ?? 0) > 0): ?>
            <a href="?tab=wms&wms_tab=exceptions" class="px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition">
                <i class="fas fa-exclamation-circle mr-2"></i>ดูปัญหา (<?= $statusCounts['on_hold'] ?>)
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
