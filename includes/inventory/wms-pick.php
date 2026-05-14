<?php
/**
 * WMS Pick Queue Sub-tab
 * Shows pending orders, start pick button, item checklist
 * Requirements: 1.2, 1.3, 1.4, 1.5
 */

require_once __DIR__ . '/../components/page-header.php';
require_once __DIR__ . '/../components/empty-state.php';

// Get pick queue
$pickQueue = [];
$pickingOrders = [];
try {
    $pickQueue = $wmsService->getPickQueue();
    
    // Also get orders currently being picked
    $stmt = $db->prepare("
        SELECT t.*, 
               (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) as item_count,
               (SELECT SUM(quantity) FROM transaction_items WHERE transaction_id = t.id) as total_quantity,
               u.display_name as customer_name
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE t.wms_status = 'picking'
        " . ($lineAccountId ? "AND t.line_account_id = ?" : "") . "
        ORDER BY t.pick_started_at ASC
    ");
    $stmt->execute($lineAccountId ? [$lineAccountId] : []);
    $pickingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get pick list for a specific order if viewing
$viewOrderId = $_GET['view_order'] ?? null;
$pickList = [];
$viewOrder = null;
if ($viewOrderId) {
    try {
        // Get order first
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$viewOrderId]);
        $viewOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Then get pick list
        if ($viewOrder) {
            try {
                $pickList = $wmsService->getPickList((int)$viewOrderId);
            } catch (Exception $e) {
                // Pick list may not be initialized yet, that's ok
                error_log("getPickList error: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log("viewOrder error: " . $e->getMessage());
    }
}
?>

<?= getPageHeaderStyles() ?>
<?= getEmptyStateStyles() ?>

<?= renderPageHeader(
    'Pick Queue',
    'รายการออเดอร์รอหยิบและกำลังหยิบ'
) ?>

<div class="space-y-6">
    <?php if ($viewOrder): ?>
    <!-- Pick List View for Specific Order -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <div>
                <h2 class="font-semibold text-lg">
                    <i class="fas fa-clipboard-list text-blue-500 mr-2"></i>
                    Pick List - Order #<?= htmlspecialchars($viewOrder['order_number'] ?? $viewOrder['id']) ?>
                </h2>
                <p class="text-sm text-gray-500">หยิบสินค้าตามรายการด้านล่าง</p>
            </div>
            <a href="?tab=wms&wms_tab=pick" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-arrow-left mr-2"></i>กลับ
            </a>
        </div>
        
        <div class="p-4">
            <!-- Order Info -->
            <div class="bg-gray-50 rounded-lg p-4 mb-4">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">ลูกค้า:</span>
                        <span class="font-medium ml-2"><?= htmlspecialchars($viewOrder['shipping_name'] ?? '-') ?></span>
                    </div>
                    <div>
                        <span class="text-gray-500">สถานะ:</span>
                        <span class="ml-2 px-2 py-1 bg-blue-100 text-blue-600 rounded text-xs"><?= $viewOrder['wms_status'] ?></span>
                    </div>
                    <div>
                        <span class="text-gray-500">จำนวนรายการ:</span>
                        <span class="font-medium ml-2"><?= count($pickList) ?></span>
                    </div>
                    <div>
                        <span class="text-gray-500">สร้างเมื่อ:</span>
                        <span class="font-medium ml-2"><?= date('d/m/Y H:i', strtotime($viewOrder['created_at'])) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Pick Items -->
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ตำแหน่ง</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จำนวน</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สถานะ</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php if (empty($pickList)): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">ไม่มีรายการ</td></tr>
                        <?php else: ?>
                        <?php foreach ($pickList as $item): 
                            $isPicked = $item['pick_status'] === 'picked';
                            $isShort = $item['pick_status'] === 'short';
                            $isDamaged = $item['pick_status'] === 'damaged';
                        ?>
                        <tr class="hover:bg-gray-50 <?= $isPicked ? 'bg-green-50' : '' ?>">
                            <td class="px-4 py-3">
                                <div class="font-medium <?= $isPicked ? 'line-through text-gray-400' : '' ?>">
                                    <?= htmlspecialchars($item['product_name']) ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="font-mono text-sm bg-gray-100 px-2 py-1 rounded">
                                    <?= htmlspecialchars($item['product_sku'] ?? '-') ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center text-sm text-gray-500">
                                <?= htmlspecialchars($item['storage_location'] ?? '-') ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="font-bold text-lg"><?= $item['quantity_required'] ?></span>
                                <?php if ($isPicked && $item['quantity_picked'] != $item['quantity_required']): ?>
                                <span class="text-xs text-orange-500 block">หยิบได้ <?= $item['quantity_picked'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($isPicked): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-600 rounded text-xs">
                                    <i class="fas fa-check mr-1"></i>หยิบแล้ว
                                </span>
                                <?php elseif ($isShort): ?>
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-600 rounded text-xs">
                                    <i class="fas fa-exclamation mr-1"></i>ของหมด
                                </span>
                                <?php elseif ($isDamaged): ?>
                                <span class="px-2 py-1 bg-red-100 text-red-600 rounded text-xs">
                                    <i class="fas fa-times mr-1"></i>เสียหาย
                                </span>
                                <?php else: ?>
                                <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-xs">รอหยิบ</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if (!$isPicked && !$isShort && !$isDamaged && $viewOrder['wms_status'] === 'picking'): ?>
                                <button onclick="confirmItemPicked(<?= $viewOrderId ?>, <?= $item['transaction_item_id'] ?>)" 
                                        class="px-3 py-1 bg-green-500 text-white rounded hover:bg-green-600 text-sm">
                                    <i class="fas fa-check mr-1"></i>หยิบแล้ว
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Complete Pick Button -->
            <?php if ($viewOrder['wms_status'] === 'picking'): ?>
            <div class="mt-4 flex justify-end gap-3">
                <form method="POST" class="inline">
                    <input type="hidden" name="wms_action" value="complete_pick">
                    <input type="hidden" name="order_id" value="<?= $viewOrderId ?>">
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        <i class="fas fa-check-double mr-2"></i>หยิบเสร็จสิ้น
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php else: ?>
    
    <!-- Currently Picking -->
    <?php if (!empty($pickingOrders)): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <h3 class="font-semibold text-blue-800 mb-3">
            <i class="fas fa-spinner fa-spin mr-2"></i>กำลังหยิบอยู่
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($pickingOrders as $order): ?>
            <div class="bg-white rounded-lg p-4 shadow-sm">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <span class="font-bold text-gray-800">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></span>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($order['customer_name'] ?? '-') ?></p>
                    </div>
                    <span class="px-2 py-1 bg-blue-100 text-blue-600 rounded text-xs">กำลังหยิบ</span>
                </div>
                <div class="text-sm text-gray-600 mb-3">
                    <i class="fas fa-box mr-1"></i><?= $order['item_count'] ?> รายการ (<?= $order['total_quantity'] ?> ชิ้น)
                </div>
                <a href="?tab=wms&wms_tab=pick&view_order=<?= $order['id'] ?>" 
                   class="block w-full text-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                    <i class="fas fa-clipboard-list mr-2"></i>ดู Pick List
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pick Queue -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold">
                <i class="fas fa-hand-pointer text-yellow-500 mr-2"></i>
                Pick Queue
                <?php if (count($pickQueue) > 0): ?>
                <span class="ml-2 px-2 py-0.5 bg-yellow-100 text-yellow-600 text-sm rounded-full"><?= count($pickQueue) ?></span>
                <?php endif; ?>
            </h2>
        </div>
        
        <?php if (empty($pickQueue)): ?>
        <?= renderEmptyState('fas fa-inbox', 'ไม่มีออเดอร์รอหยิบ', 'เมื่อมีออเดอร์ใหม่จะแสดงที่นี่') ?>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Order</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">ลูกค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">รายการ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สร้างเมื่อ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">รอมา</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($pickQueue as $order): 
                        $createdAt = strtotime($order['created_at']);
                        $hoursWaiting = round((time() - $createdAt) / 3600, 1);
                        $isOverdue = $hoursWaiting > 24;
                    ?>
                    <tr class="hover:bg-gray-50 <?= $isOverdue ? 'bg-red-50' : '' ?>">
                        <td class="px-4 py-3">
                            <span class="font-bold">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium"><?= htmlspecialchars($order['customer_name'] ?? '-') ?></div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($order['shipping_phone'] ?? '') ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="font-medium"><?= $order['item_count'] ?></span>
                            <span class="text-gray-400 text-sm">(<?= $order['total_quantity'] ?> ชิ้น)</span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?= date('d/m H:i', $createdAt) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="<?= $isOverdue ? 'text-red-600 font-bold' : 'text-gray-600' ?>">
                                <?= $hoursWaiting ?> ชม.
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <form method="POST" class="inline">
                                <input type="hidden" name="wms_action" value="start_pick">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <button type="submit" class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600">
                                    <i class="fas fa-play mr-1"></i>เริ่มหยิบ
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function confirmItemPicked(orderId, itemId) {
    if (!confirm('ยืนยันว่าหยิบสินค้านี้แล้ว?')) return;
    
    // Get base URL from meta tag or use relative path
    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '../';
    
    fetch(baseUrl + 'api/wms.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'confirm_item_picked',
            order_id: orderId,
            item_id: itemId
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error || data.message || 'เกิดข้อผิดพลาด');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Error: ' + err.message);
    });
}
</script>
