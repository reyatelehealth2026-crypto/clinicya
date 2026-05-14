<?php
/**
 * WMS Ship Queue Sub-tab
 * Shows packed orders, carrier selection, tracking input
 * Requirements: 5.1, 5.2, 5.4
 */

require_once __DIR__ . '/../components/page-header.php';
require_once __DIR__ . '/../components/empty-state.php';

// Get ship queue (orders with status 'packed' or 'ready_to_ship')
$shipQueue = [];
try {
    $shipQueue = $wmsService->getShipQueue();
} catch (Exception $e) {}

// Get recently shipped orders
$recentlyShipped = [];
try {
    $stmt = $db->prepare("
        SELECT t.*, 
               u.display_name as customer_name
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.wms_status = 'shipped'
        " . ($lineAccountId ? "AND t.line_account_id = ?" : "") . "
        ORDER BY t.shipped_at DESC
        LIMIT 10
    ");
    $stmt->execute($lineAccountId ? [$lineAccountId] : []);
    $recentlyShipped = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Available carriers
$carriers = [
    'Kerry Express' => ['icon' => '🚚', 'tracking_url' => 'https://th.kerryexpress.com/th/track/?track='],
    'Flash Express' => ['icon' => '⚡', 'tracking_url' => 'https://flashexpress.com/fle/tracking?se='],
    'J&T Express' => ['icon' => '📦', 'tracking_url' => 'https://www.jtexpress.co.th/index/query/gzquery.html?billcode='],
    'Thailand Post' => ['icon' => '📮', 'tracking_url' => 'https://track.thailandpost.co.th/?trackNumber='],
    'DHL' => ['icon' => '🟡', 'tracking_url' => 'https://www.dhl.com/th-th/home/tracking.html?tracking-id='],
    'Ninja Van' => ['icon' => '🥷', 'tracking_url' => 'https://www.ninjavan.co/th-th/tracking?id='],
    'Best Express' => ['icon' => '🏆', 'tracking_url' => 'https://www.best-inc.co.th/track?bills='],
    'SCG Express' => ['icon' => '🔵', 'tracking_url' => 'https://www.scgexpress.co.th/tracking/'],
    'อื่นๆ' => ['icon' => '📦', 'tracking_url' => ''],
];
?>

<?= getPageHeaderStyles() ?>
<?= getEmptyStateStyles() ?>

<?= renderPageHeader(
    'Ship Queue',
    'รายการออเดอร์รอจัดส่งและจัดส่งล่าสุด'
) ?>

<div class="space-y-6">
    <!-- Ship Queue -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold">
                <i class="fas fa-shipping-fast text-orange-500 mr-2"></i>
                Ship Queue (รอจัดส่ง)
                <?php if (count($shipQueue) > 0): ?>
                <span class="ml-2 px-2 py-0.5 bg-orange-100 text-orange-600 text-sm rounded-full"><?= count($shipQueue) ?></span>
                <?php endif; ?>
            </h2>
        </div>
        
        <?php if (empty($shipQueue)): ?>
        <?= renderEmptyState('fas fa-inbox', 'ไม่มีออเดอร์รอจัดส่ง', 'เมื่อมีออเดอร์ที่แพ็คเสร็จจะแสดงที่นี่') ?>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Order</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">ลูกค้า / ที่อยู่</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สถานะ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">แพ็คเสร็จ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">ขนส่ง / เลขพัสดุ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($shipQueue as $order): ?>
                    <tr class="hover:bg-gray-50" id="order-row-<?= $order['id'] ?>">
                        <td class="px-4 py-3">
                            <span class="font-bold">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></span>
                            <div class="text-xs text-gray-500">฿<?= number_format($order['total_amount'] ?? 0, 2) ?></div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium"><?= htmlspecialchars($order['customer_name'] ?? '-') ?></div>
                            <div class="text-xs text-gray-500 max-w-xs truncate">
                                <?= htmlspecialchars($order['shipping_address'] ?? '-') ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $order['wms_status'] === 'ready_to_ship' ? 'green' : 'pink' ?>-100 text-<?= $order['wms_status'] === 'ready_to_ship' ? 'green' : 'pink' ?>-600 rounded text-xs">
                                <?= $order['wms_status'] === 'ready_to_ship' ? 'พร้อมส่ง' : 'แพ็คแล้ว' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?= $order['pack_completed_at'] ? date('d/m H:i', strtotime($order['pack_completed_at'])) : '-' ?>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" class="flex items-center gap-2 ship-form" data-order-id="<?= $order['id'] ?>">
                                <input type="hidden" name="wms_action" value="assign_tracking">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <select name="carrier" required class="px-2 py-1 border rounded text-sm w-32">
                                    <option value="">เลือกขนส่ง</option>
                                    <?php foreach ($carriers as $name => $info): ?>
                                    <option value="<?= htmlspecialchars($name) ?>"><?= $info['icon'] ?> <?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="tracking_number" required 
                                       placeholder="เลขพัสดุ" 
                                       class="px-2 py-1 border rounded text-sm w-36 font-mono">
                            </form>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button type="submit" form="" onclick="submitShipForm(<?= $order['id'] ?>)"
                                    class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                                <i class="fas fa-truck mr-1"></i>จัดส่ง
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recently Shipped -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold">
                <i class="fas fa-history text-green-500 mr-2"></i>
                จัดส่งล่าสุด
            </h2>
        </div>
        
        <?php if (empty($recentlyShipped)): ?>
        <?= renderEmptyState('fas fa-truck', 'ยังไม่มีการจัดส่ง', 'รายการจัดส่งล่าสุดจะแสดงที่นี่') ?>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Order</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">ลูกค้า</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">ขนส่ง</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">เลขพัสดุ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จัดส่งเมื่อ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Track</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($recentlyShipped as $order): 
                        $carrierInfo = $carriers[$order['carrier']] ?? ['icon' => '📦', 'tracking_url' => ''];
                        $trackingUrl = $carrierInfo['tracking_url'] . urlencode($order['shipping_tracking'] ?? '');
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <span class="font-bold">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium"><?= htmlspecialchars($order['customer_name'] ?? '-') ?></div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-lg mr-1"><?= $carrierInfo['icon'] ?></span>
                            <?= htmlspecialchars($order['carrier'] ?? '-') ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono bg-gray-100 px-2 py-1 rounded text-sm">
                                <?= htmlspecialchars($order['shipping_tracking'] ?? '-') ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?= $order['shipped_at'] ? date('d/m/Y H:i', strtotime($order['shipped_at'])) : '-' ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if (($order['shipping_tracking'] ?? '') && $carrierInfo['tracking_url']): ?>
                            <a href="<?= htmlspecialchars($trackingUrl) ?>" target="_blank"
                               class="px-3 py-1 bg-blue-100 text-blue-600 rounded hover:bg-blue-200 text-sm">
                                <i class="fas fa-external-link-alt mr-1"></i>Track
                            </a>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function submitShipForm(orderId) {
    const form = document.querySelector(`.ship-form[data-order-id="${orderId}"]`);
    if (!form) return;
    
    const carrier = form.querySelector('[name="carrier"]').value;
    const tracking = form.querySelector('[name="tracking_number"]').value;
    
    if (!carrier) {
        alert('กรุณาเลือกขนส่ง');
        return;
    }
    if (!tracking) {
        alert('กรุณากรอกเลขพัสดุ');
        return;
    }
    
    if (confirm(`ยืนยันจัดส่ง Order #${orderId}\nขนส่ง: ${carrier}\nเลขพัสดุ: ${tracking}`)) {
        form.submit();
    }
}
</script>
