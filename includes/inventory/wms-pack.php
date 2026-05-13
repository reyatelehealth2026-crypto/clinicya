<?php
/**
 * WMS Pack Station Sub-tab
 * Shows picked orders, pack confirmation, print buttons
 * Requirements: 3.1, 3.2, 3.4, 3.5
 */

require_once __DIR__ . '/../components/page-header.php';
require_once __DIR__ . '/../components/empty-state.php';

// Get pack queue (orders with status 'picked')
$packQueue = [];
$packingOrders = [];
try {
    $packQueue = $wmsService->getPackQueue();
    
    // Also get orders currently being packed
    $stmt = $db->prepare("
        SELECT t.*, 
               (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) as item_count,
               (SELECT SUM(quantity) FROM transaction_items WHERE transaction_id = t.id) as total_quantity,
               u.display_name as customer_name,
               au.username as picker_name
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN admin_users au ON t.picker_id = au.id
        WHERE t.wms_status = 'packing'
        " . ($lineAccountId ? "AND t.line_account_id = ?" : "") . "
        ORDER BY t.pack_started_at ASC
    ");
    $stmt->execute($lineAccountId ? [$lineAccountId] : []);
    $packingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get order details if viewing
$viewOrderId = $_GET['view_order'] ?? null;
$viewOrder = null;
$orderItems = [];
if ($viewOrderId) {
    try {
        $stmt = $db->prepare("
            SELECT t.*, u.display_name as customer_name, au.username as picker_name
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN admin_users au ON t.picker_id = au.id
            WHERE t.id = ?
        ");
        $stmt->execute([$viewOrderId]);
        $viewOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($viewOrder) {
            $stmt = $db->prepare("
                SELECT ti.*, bi.image_url
                FROM transaction_items ti
                LEFT JOIN business_items bi ON ti.product_id = bi.id
                WHERE ti.transaction_id = ?
            ");
            $stmt->execute([$viewOrderId]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}
?>

<?= getPageHeaderStyles() ?>
<?= getEmptyStateStyles() ?>

<?= renderPageHeader(
    'Pack Station',
    'รายการออเดอร์รอแพ็คและกำลังแพ็ค'
) ?>

<div class="space-y-6">
    <?php if ($viewOrder): ?>
    <!-- Pack Order View -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <div>
                <h2 class="font-semibold text-lg">
                    <i class="fas fa-box text-purple-500 mr-2"></i>
                    Pack Order #<?= htmlspecialchars($viewOrder['order_number'] ?? $viewOrder['id']) ?>
                </h2>
                <p class="text-sm text-gray-500">ตรวจสอบและแพ็คสินค้า</p>
            </div>
            <a href="?tab=wms&wms_tab=pack" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200">
                <i class="fas fa-arrow-left mr-2"></i>กลับ
            </a>
        </div>
        
        <div class="p-4">
            <!-- Order & Shipping Info -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="font-medium text-gray-700 mb-3"><i class="fas fa-info-circle mr-2"></i>ข้อมูลออเดอร์</h4>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Order:</span>
                            <span class="font-medium">#<?= htmlspecialchars($viewOrder['order_number'] ?? $viewOrder['id']) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">สถานะ:</span>
                            <span class="px-2 py-1 bg-purple-100 text-purple-600 rounded text-xs"><?= $viewOrder['wms_status'] ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">หยิบโดย:</span>
                            <span><?= htmlspecialchars($viewOrder['picker_name'] ?? '-') ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">ยอดรวม:</span>
                            <span class="font-bold text-green-600">฿<?= number_format($viewOrder['total_amount'] ?? 0, 2) ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="bg-blue-50 rounded-lg p-4">
                    <h4 class="font-medium text-gray-700 mb-3"><i class="fas fa-shipping-fast mr-2"></i>ที่อยู่จัดส่ง</h4>
                    <div class="text-sm">
                        <p class="font-medium"><?= htmlspecialchars($viewOrder['shipping_name'] ?? '-') ?></p>
                        <p class="text-gray-600"><?= htmlspecialchars($viewOrder['shipping_phone'] ?? '') ?></p>
                        <p class="text-gray-600 mt-2"><?= nl2br(htmlspecialchars($viewOrder['shipping_address'] ?? '-')) ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Items to Pack -->
            <div class="mb-6">
                <h4 class="font-medium text-gray-700 mb-3"><i class="fas fa-list mr-2"></i>รายการสินค้า</h4>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จำนวน</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">ราคา</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($orderItems as $item): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <?php if ($item['image_url']): ?>
                                        <img src="<?= htmlspecialchars($item['image_url']) ?>" class="w-10 h-10 rounded object-cover">
                                        <?php else: ?>
                                        <div class="w-10 h-10 bg-gray-100 rounded flex items-center justify-center">
                                            <i class="fas fa-box text-gray-400"></i>
                                        </div>
                                        <?php endif; ?>
                                        <span class="font-medium"><?= htmlspecialchars($item['product_name']) ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="font-mono text-sm bg-gray-100 px-2 py-1 rounded">
                                        <?= htmlspecialchars($item['product_sku'] ?? '-') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center font-bold"><?= $item['quantity'] ?></td>
                                <td class="px-4 py-3 text-right">฿<?= number_format(($item['product_price'] ?? 0) * $item['quantity'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pack Actions -->
            <?php if ($viewOrder['wms_status'] === 'packing'): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                <h4 class="font-medium text-yellow-800 mb-3"><i class="fas fa-box-open mr-2"></i>ข้อมูลพัสดุ (ไม่บังคับ)</h4>
                <form method="POST" id="packForm" class="space-y-4">
                    <input type="hidden" name="wms_action" value="complete_pack">
                    <input type="hidden" name="order_id" value="<?= $viewOrderId ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">น้ำหนัก (กก.)</label>
                            <input type="number" name="weight" step="0.01" min="0" 
                                   class="w-full px-3 py-2 border rounded-lg" placeholder="0.00">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ขนาด (กxยxส ซม.)</label>
                            <input type="text" name="dimensions" 
                                   class="w-full px-3 py-2 border rounded-lg" placeholder="20x15x10">
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="flex flex-wrap gap-3 justify-between">
                <div class="flex gap-3">
                    <a href="/api/wms.php?action=print_packing_slip&order_id=<?= $viewOrderId ?>" target="_blank"
                       class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                        <i class="fas fa-file-alt mr-2"></i>พิมพ์ Packing Slip
                    </a>
                    <a href="/api/wms.php?action=print_shipping_label&order_id=<?= $viewOrderId ?>" target="_blank"
                       class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                        <i class="fas fa-tag mr-2"></i>พิมพ์ Shipping Label
                    </a>
                </div>
                <button type="submit" form="packForm" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                    <i class="fas fa-check-double mr-2"></i>แพ็คเสร็จสิ้น
                </button>
            </div>
            <?php elseif ($viewOrder['wms_status'] === 'picked'): ?>
            <div class="flex justify-end">
                <form method="POST">
                    <input type="hidden" name="wms_action" value="start_pack">
                    <input type="hidden" name="order_id" value="<?= $viewOrderId ?>">
                    <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                        <i class="fas fa-play mr-2"></i>เริ่มแพ็ค
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php else: ?>
    
    <!-- Currently Packing -->
    <?php if (!empty($packingOrders)): ?>
    <div class="bg-purple-50 border border-purple-200 rounded-xl p-4">
        <h3 class="font-semibold text-purple-800 mb-3">
            <i class="fas fa-spinner fa-spin mr-2"></i>กำลังแพ็คอยู่
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($packingOrders as $order): ?>
            <div class="bg-white rounded-lg p-4 shadow-sm">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <span class="font-bold text-gray-800">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></span>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($order['customer_name'] ?? '-') ?></p>
                    </div>
                    <span class="px-2 py-1 bg-purple-100 text-purple-600 rounded text-xs">กำลังแพ็ค</span>
                </div>
                <div class="text-sm text-gray-600 mb-3">
                    <i class="fas fa-box mr-1"></i><?= $order['item_count'] ?> รายการ
                    <span class="mx-2">|</span>
                    <i class="fas fa-user mr-1"></i><?= htmlspecialchars($order['picker_name'] ?? '-') ?>
                </div>
                <a href="?tab=wms&wms_tab=pack&view_order=<?= $order['id'] ?>" 
                   class="block w-full text-center px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
                    <i class="fas fa-box-open mr-2"></i>ดำเนินการแพ็ค
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pack Queue -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold">
                <i class="fas fa-box text-indigo-500 mr-2"></i>
                Pack Queue (รอแพ็ค)
                <?php if (count($packQueue) > 0): ?>
                <span class="ml-2 px-2 py-0.5 bg-indigo-100 text-indigo-600 text-sm rounded-full"><?= count($packQueue) ?></span>
                <?php endif; ?>
            </h2>
        </div>
        
        <?php if (empty($packQueue)): ?>
        <?= renderEmptyState('fas fa-inbox', 'ไม่มีออเดอร์รอแพ็ค', 'เมื่อมีออเดอร์ที่หยิบเสร็จจะแสดงที่นี่') ?>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Order</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">ลูกค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">รายการ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">หยิบโดย</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">หยิบเสร็จ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($packQueue as $order): ?>
                    <tr class="hover:bg-gray-50">
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
                        <td class="px-4 py-3 text-center text-sm">
                            <?= htmlspecialchars($order['picker_name'] ?? '-') ?>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?= $order['pick_completed_at'] ? date('d/m H:i', strtotime($order['pick_completed_at'])) : '-' ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="?tab=wms&wms_tab=pack&view_order=<?= $order['id'] ?>" 
                               class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 inline-block">
                                <i class="fas fa-box-open mr-1"></i>แพ็ค
                            </a>
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
