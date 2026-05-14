<?php
/**
 * WMS Exceptions Sub-tab
 * Shows orders with issues, resolution form
 * Requirements: 9.4, 9.5
 */

require_once __DIR__ . '/../components/page-header.php';
require_once __DIR__ . '/../components/empty-state.php';

// Get exception orders
$exceptionOrders = [];
try {
    $exceptionOrders = $wmsService->getExceptionOrders();
} catch (Exception $e) {}

// Status options for resolution
$statusOptions = [
    'pending_pick' => 'กลับไปรอหยิบ',
    'picking' => 'กลับไปหยิบต่อ',
    'picked' => 'กลับไปรอแพ็ค',
    'packing' => 'กลับไปแพ็คต่อ',
    'packed' => 'กลับไปรอจัดส่ง',
];
?>

<?= getPageHeaderStyles() ?>
<?= getEmptyStateStyles() ?>

<?= renderPageHeader(
    'Exceptions',
    'จัดการออเดอร์ที่มีปัญหา / พักไว้'
) ?>

<div class="space-y-6">
    <!-- Exception Orders -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold">
                <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                Orders with Issues
                <?php if (count($exceptionOrders) > 0): ?>
                <span class="ml-2 px-2 py-0.5 bg-red-100 text-red-600 text-sm rounded-full"><?= count($exceptionOrders) ?></span>
                <?php endif; ?>
            </h2>
        </div>
        
        <?php if (empty($exceptionOrders)): ?>
        <?= renderEmptyState('fas fa-check-circle', 'ไม่มีออเดอร์ที่มีปัญหา', 'ทุกออเดอร์ดำเนินการได้ปกติ') ?>
        <?php else: ?>
        <div class="divide-y">
            <?php foreach ($exceptionOrders as $order): ?>
            <div class="p-4 hover:bg-gray-50" id="exception-<?= $order['id'] ?>">
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                    <!-- Order Info -->
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="font-bold text-lg">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></span>
                            <span class="px-2 py-1 bg-red-100 text-red-600 rounded text-xs">
                                <?= $order['wms_status'] === 'on_hold' ? 'พักไว้' : 'มีปัญหา' ?>
                            </span>
                            <span class="text-sm text-gray-500">
                                รอมา <?= $order['hours_since_order'] ?> ชม.
                            </span>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-500">ลูกค้า:</span>
                                <span class="font-medium ml-2"><?= htmlspecialchars($order['customer_name'] ?? '-') ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500">ยอดรวม:</span>
                                <span class="font-medium ml-2">฿<?= number_format($order['total_amount'] ?? 0, 2) ?></span>
                            </div>
                        </div>
                        
                        <?php if ($order['wms_exception']): ?>
                        <div class="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                            <p class="text-sm text-red-700">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>ปัญหา:</strong> <?= htmlspecialchars($order['wms_exception']) ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Resolution Form -->
                    <div class="md:w-96">
                        <form method="POST" class="bg-gray-50 rounded-lg p-4">
                            <input type="hidden" name="wms_action" value="resolve_exception">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            
                            <div class="mb-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">วิธีแก้ไข</label>
                                <textarea name="resolution" required rows="2" 
                                          class="w-full px-3 py-2 border rounded-lg text-sm"
                                          placeholder="อธิบายวิธีแก้ไขปัญหา..."></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="block text-sm font-medium text-gray-700 mb-1">เปลี่ยนสถานะเป็น</label>
                                <select name="new_status" class="w-full px-3 py-2 border rounded-lg text-sm">
                                    <option value="">-- เลือกสถานะ --</option>
                                    <?php foreach ($statusOptions as $status => $label): ?>
                                    <option value="<?= $status ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="flex gap-2">
                                <button type="submit" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                                    <i class="fas fa-check mr-1"></i>แก้ไขแล้ว
                                </button>
                                <button type="button" onclick="cancelOrder(<?= $order['id'] ?>)" 
                                        class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 text-sm">
                                    <i class="fas fa-times mr-1"></i>ยกเลิก
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Put Order On Hold -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold">
                <i class="fas fa-pause-circle text-yellow-500 mr-2"></i>
                พักออเดอร์
            </h2>
        </div>
        <div class="p-4">
            <form method="POST" class="max-w-lg">
                <input type="hidden" name="wms_action" value="put_on_hold">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Order ID</label>
                    <input type="number" name="order_id" required 
                           class="w-full px-3 py-2 border rounded-lg"
                           placeholder="กรอก Order ID">
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">เหตุผล</label>
                    <textarea name="reason" required rows="2" 
                              class="w-full px-3 py-2 border rounded-lg"
                              placeholder="ระบุเหตุผลที่ต้องพักออเดอร์..."></textarea>
                </div>
                
                <button type="submit" class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600">
                    <i class="fas fa-pause mr-2"></i>พักออเดอร์
                </button>
            </form>
        </div>
    </div>

    <!-- Activity Log -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold">
                <i class="fas fa-history text-gray-500 mr-2"></i>
                Exception Activity Log
            </h2>
        </div>
        <div class="p-4">
            <?php
            // Get recent exception-related activities
            $activities = [];
            try {
                $stmt = $db->prepare("
                    SELECT wal.*, t.order_number, au.username as staff_name
                    FROM wms_activity_logs wal
                    LEFT JOIN transactions t ON wal.order_id = t.id
                    LEFT JOIN admin_users au ON wal.staff_id = au.id
                    WHERE wal.action IN ('item_short', 'item_damaged', 'on_hold', 'exception_resolved')
                    " . ($lineAccountId ? "AND wal.line_account_id = ?" : "") . "
                    ORDER BY wal.created_at DESC
                    LIMIT 20
                ");
                $stmt->execute($lineAccountId ? [$lineAccountId] : []);
                $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            ?>
            
            <?php if (empty($activities)): ?>
            <?= renderEmptyState('fas fa-clipboard-list', 'ยังไม่มีกิจกรรม', 'กิจกรรมที่เกี่ยวข้องกับ exception จะแสดงที่นี่') ?>
            <?php else: ?>
            <div class="space-y-3 max-h-96 overflow-y-auto">
                <?php foreach ($activities as $activity): 
                    $actionConfig = [
                        'item_short' => ['icon' => 'fa-exclamation', 'color' => 'yellow', 'label' => 'ของหมด'],
                        'item_damaged' => ['icon' => 'fa-times', 'color' => 'red', 'label' => 'เสียหาย'],
                        'on_hold' => ['icon' => 'fa-pause', 'color' => 'orange', 'label' => 'พักไว้'],
                        'exception_resolved' => ['icon' => 'fa-check', 'color' => 'green', 'label' => 'แก้ไขแล้ว'],
                    ];
                    $config = $actionConfig[$activity['action']] ?? ['icon' => 'fa-info', 'color' => 'gray', 'label' => $activity['action']];
                ?>
                <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                    <div class="w-8 h-8 bg-<?= $config['color'] ?>-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas <?= $config['icon'] ?> text-<?= $config['color'] ?>-500 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-medium">#<?= htmlspecialchars($activity['order_number'] ?? $activity['order_id']) ?></span>
                            <span class="px-2 py-0.5 bg-<?= $config['color'] ?>-100 text-<?= $config['color'] ?>-600 rounded text-xs">
                                <?= $config['label'] ?>
                            </span>
                        </div>
                        <?php if ($activity['notes']): ?>
                        <p class="text-sm text-gray-600 truncate"><?= htmlspecialchars($activity['notes']) ?></p>
                        <?php endif; ?>
                        <div class="text-xs text-gray-400 mt-1">
                            <?= date('d/m/Y H:i', strtotime($activity['created_at'])) ?>
                            <?php if ($activity['staff_name']): ?>
                            <span class="mx-1">•</span>
                            <?= htmlspecialchars($activity['staff_name']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function cancelOrder(orderId) {
    if (!confirm('ยืนยันยกเลิกออเดอร์นี้?')) return;
    
    // For now, just put on hold with cancellation reason
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="wms_action" value="put_on_hold">
        <input type="hidden" name="order_id" value="${orderId}">
        <input type="hidden" name="reason" value="ยกเลิกโดยผู้ดูแล">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>
