<?php
/**
 * Procurement GR Tab - รับสินค้าเข้าคลัง
 * Tab content for procurement.php
 * 
 * Features:
 * - GR creation from PO
 * - GR detail view with cost breakdown
 * - Value tracking display
 * 
 * Requirements: 4.1, 4.4
 */

require_once __DIR__ . '/../../classes/PurchaseOrderService.php';

$poService = new PurchaseOrderService($db, $lineAccountId);
$adminId = $_SESSION['admin_user']['id'] ?? null;

// Check if viewing GR detail
$grId = $_GET['gr_id'] ?? null;
$grDetail = null;
$grItems = [];

if ($grId) {
    $grDetail = $poService->getGR($grId);
    if ($grDetail) {
        $grItems = $poService->getGRItems($grId);
    }
}

// Get PO ID from query
$poId = $_GET['po_id'] ?? null;
$po = null;
$poItems = [];
$existingGRs = [];

if ($poId) {
    $po = $poService->getPOById($poId);
    if ($po) {
        $poItems = $poService->getPOItems($poId);
        $existingGRs = $poService->getGRsByPO($poId);
    }
}

// Get pending POs for selection
$pendingPOs = [];
try {
    $stmt = $db->prepare("
        SELECT po.*, s.name as supplier_name,
            (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) as item_count
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.status IN ('submitted', 'partial')
        ORDER BY po.order_date DESC
    ");
    $stmt->execute();
    $pendingPOs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get recent GRs for listing
$recentGRs = [];
try {
    $recentGRs = $poService->getAllGRs(['limit' => 20]);
} catch (Exception $e) {}
?>

<div class="space-y-6">
    <?php if ($grDetail): ?>
    <!-- GR Detail View with Cost Breakdown -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold text-lg">
                <i class="fas fa-file-invoice mr-2 text-green-500"></i>
                รายละเอียดใบรับสินค้า: <?= htmlspecialchars($grDetail['gr_number']) ?>
            </h2>
            <a href="?tab=gr" class="px-3 py-1 bg-gray-100 text-gray-700 rounded text-sm hover:bg-gray-200">
                <i class="fas fa-arrow-left mr-1"></i>กลับ
            </a>
        </div>
        
        <!-- GR Info Summary -->
        <div class="p-4 grid grid-cols-1 md:grid-cols-4 gap-4 bg-gray-50 border-b">
            <div>
                <span class="text-gray-500 text-sm">เลขที่ GR</span>
                <div class="font-mono font-bold text-lg"><?= htmlspecialchars($grDetail['gr_number']) ?></div>
            </div>
            <div>
                <span class="text-gray-500 text-sm">เลขที่ PO</span>
                <div class="font-mono"><?= htmlspecialchars($grDetail['po_number'] ?? '-') ?></div>
            </div>
            <div>
                <span class="text-gray-500 text-sm">Supplier</span>
                <div><?= htmlspecialchars($grDetail['supplier_name'] ?? '-') ?></div>
            </div>
            <div>
                <span class="text-gray-500 text-sm">วันที่รับ</span>
                <div><?= date('d/m/Y H:i', strtotime($grDetail['created_at'])) ?></div>
            </div>
        </div>
        
        <!-- Items with Cost Breakdown -->
        <div class="p-4">
            <h3 class="font-semibold mb-3"><i class="fas fa-list mr-2 text-blue-500"></i>รายการสินค้า & มูลค่า</h3>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Batch/Lot</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จำนวนรับ</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">ราคาทุน/หน่วย</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">มูลค่ารวม</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">วันหมดอายุ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php 
                        $totalGRValue = 0;
                        foreach ($grItems as $item): 
                            $itemSubtotal = ($item['quantity'] ?? 0) * ($item['unit_cost'] ?? 0);
                            $totalGRValue += $itemSubtotal;
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium"><?= htmlspecialchars($item['product_name'] ?? 'Unknown') ?></div>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars($item['sku'] ?? '') ?></div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if (!empty($item['batch_number'])): ?>
                                <span class="font-mono text-sm bg-blue-50 text-blue-700 px-2 py-1 rounded"><?= htmlspecialchars($item['batch_number']) ?></span>
                                <?php if (!empty($item['lot_number'])): ?>
                                <div class="text-xs text-gray-500 mt-1">Lot: <?= htmlspecialchars($item['lot_number']) ?></div>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center font-medium"><?= number_format($item['quantity'] ?? 0) ?></td>
                            <td class="px-4 py-3 text-right">฿<?= number_format($item['unit_cost'] ?? 0, 2) ?></td>
                            <td class="px-4 py-3 text-right font-medium text-green-600">฿<?= number_format($itemSubtotal, 2) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if (!empty($item['expiry_date'])): ?>
                                <span class="text-sm"><?= date('d/m/Y', strtotime($item['expiry_date'])) ?></span>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-green-50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right font-semibold">มูลค่ารวมทั้งหมด (GR Value):</td>
                            <td class="px-4 py-3 text-right font-bold text-lg text-green-700">฿<?= number_format($totalGRValue, 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <!-- Value Flow Summary -->
        <div class="p-4 border-t bg-blue-50">
            <h3 class="font-semibold mb-3"><i class="fas fa-chart-line mr-2 text-blue-500"></i>สรุปการไหลของมูลค่า</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white rounded-lg p-4 text-center">
                    <div class="text-gray-500 text-sm">PO Value</div>
                    <div class="text-xl font-bold text-blue-600">฿<?= number_format($grDetail['total_amount'] ?? $totalGRValue, 2) ?></div>
                </div>
                <div class="flex items-center justify-center">
                    <i class="fas fa-arrow-right text-2xl text-gray-400"></i>
                </div>
                <div class="bg-white rounded-lg p-4 text-center">
                    <div class="text-gray-500 text-sm">GR Value (Stock เพิ่ม)</div>
                    <div class="text-xl font-bold text-green-600">+฿<?= number_format($totalGRValue, 2) ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($grDetail['notes'])): ?>
        <div class="p-4 border-t">
            <h3 class="font-semibold mb-2"><i class="fas fa-sticky-note mr-2 text-yellow-500"></i>หมายเหตุ</h3>
            <p class="text-gray-600"><?= nl2br(htmlspecialchars($grDetail['notes'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
    
    <?php elseif (!$po): ?>
    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-file-alt mr-2 text-blue-500"></i>เลือก PO ที่ต้องการรับสินค้า</h2>
        
        <?php if (empty($pendingPOs)): ?>
        <div class="text-center py-8 text-gray-500">
            <i class="fas fa-inbox text-4xl mb-3"></i>
            <p>ไม่มี PO ที่รอรับสินค้า</p>
            <a href="?tab=po" class="text-green-600 hover:underline mt-2 inline-block">
                <i class="fas fa-plus mr-1"></i>สร้าง PO ใหม่
            </a>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">เลขที่ PO</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Supplier</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">รายการ</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">ยอดรวม</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สถานะ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($pendingPOs as $p): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-sm"><?= htmlspecialchars($p['po_number']) ?></td>
                        <td class="px-4 py-3"><?= htmlspecialchars($p['supplier_name']) ?></td>
                        <td class="px-4 py-3 text-center"><?= $p['item_count'] ?> รายการ</td>
                        <td class="px-4 py-3 text-right font-medium">฿<?= number_format($p['total_amount'], 2) ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($p['status'] === 'partial'): ?>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs">รับบางส่วน</span>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs">รอรับสินค้า</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="?tab=gr&po_id=<?= $p['id'] ?>" class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                                <i class="fas fa-truck-loading mr-1"></i>รับสินค้า
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    
    <!-- PO Info & GR Form -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- PO Info -->
        <div class="bg-white rounded-xl shadow p-4">
            <h3 class="font-semibold mb-3"><i class="fas fa-file-invoice mr-2 text-blue-500"></i>ข้อมูล PO</h3>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">เลขที่:</span>
                    <span class="font-mono font-medium"><?= htmlspecialchars($po['po_number']) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Supplier:</span>
                    <span><?= htmlspecialchars($po['supplier_name']) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">วันที่สั่ง:</span>
                    <span><?= date('d/m/Y', strtotime($po['order_date'])) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">ยอดรวม:</span>
                    <span class="font-medium">฿<?= number_format($po['total_amount'], 2) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">สถานะ:</span>
                    <span class="px-2 py-0.5 bg-<?= $po['status'] === 'partial' ? 'yellow' : 'blue' ?>-100 text-<?= $po['status'] === 'partial' ? 'yellow' : 'blue' ?>-700 rounded text-xs">
                        <?= $po['status'] === 'partial' ? 'รับบางส่วน' : 'รอรับสินค้า' ?>
                    </span>
                </div>
            </div>
            
            <!-- Existing GRs -->
            <?php if (!empty($existingGRs)): ?>
            <div class="mt-4 pt-4 border-t">
                <h4 class="text-sm font-medium mb-2">ประวัติการรับสินค้า</h4>
                <div class="space-y-2">
                    <?php foreach ($existingGRs as $gr): ?>
                    <a href="?tab=gr&gr_id=<?= $gr['id'] ?>" class="block p-2 bg-gray-50 rounded text-xs hover:bg-gray-100 transition-colors">
                        <div class="font-mono font-medium text-blue-600"><?= htmlspecialchars($gr['gr_number']) ?></div>
                        <div class="text-gray-500"><?= date('d/m/Y H:i', strtotime($gr['created_at'])) ?></div>
                        <?php if (isset($gr['total_amount'])): ?>
                        <div class="text-green-600 font-medium">฿<?= number_format($gr['total_amount'], 2) ?></div>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <a href="?tab=gr" class="mt-4 block text-center text-sm text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-1"></i>เลือก PO อื่น
            </a>
        </div>
        
        <!-- GR Form -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-semibold"><i class="fas fa-truck-loading mr-2 text-green-500"></i>รับสินค้าเข้าคลัง</h3>
                <button onclick="fillAll()" class="px-3 py-1 bg-gray-100 text-gray-700 rounded text-sm hover:bg-gray-200">
                    <i class="fas fa-check-double mr-1"></i>รับครบทุกรายการ
                </button>
            </div>
            
            <form id="grForm" class="p-4">
                <input type="hidden" name="po_id" value="<?= $poId ?>">
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">สินค้า</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">สั่งซื้อ</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">รับแล้ว</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">คงเหลือ</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">รับครั้งนี้</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">Batch/Lot</th>
                                <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">วันหมดอายุ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($poItems as $item): 
                                $remaining = $item['quantity'] - $item['received_quantity'];
                            ?>
                            <tr class="<?= $remaining <= 0 ? 'bg-green-50' : '' ?>" data-item-id="<?= $item['id'] ?>">
                                <td class="px-3 py-2">
                                    <div class="font-medium"><?= htmlspecialchars($item['product_name']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($item['sku'] ?? '') ?></div>
                                </td>
                                <td class="px-3 py-2 text-center"><?= $item['quantity'] ?></td>
                                <td class="px-3 py-2 text-center text-green-600"><?= $item['received_quantity'] ?></td>
                                <td class="px-3 py-2 text-center font-medium <?= $remaining > 0 ? 'text-orange-600' : 'text-green-600' ?>">
                                    <?= $remaining ?>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <?php if ($remaining > 0): ?>
                                    <input type="number" 
                                           name="items[<?= $item['id'] ?>][quantity]" 
                                           class="receive-qty w-20 px-2 py-1 border rounded text-center"
                                           min="0" 
                                           max="<?= $remaining ?>"
                                           data-max="<?= $remaining ?>"
                                           value="0">
                                    <?php else: ?>
                                    <span class="text-green-600"><i class="fas fa-check"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2">
                                    <?php if ($remaining > 0): ?>
                                    <div class="space-y-1">
                                        <input type="text" 
                                               name="items[<?= $item['id'] ?>][batch_number]" 
                                               class="batch-input w-24 px-2 py-1 border rounded text-xs"
                                               placeholder="Batch No."
                                               title="เลข Batch">
                                        <input type="text" 
                                               name="items[<?= $item['id'] ?>][lot_number]" 
                                               class="lot-input w-24 px-2 py-1 border rounded text-xs"
                                               placeholder="Lot No."
                                               title="เลข Lot">
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-2">
                                    <?php if ($remaining > 0): ?>
                                    <div class="space-y-1">
                                        <input type="date" 
                                               name="items[<?= $item['id'] ?>][expiry_date]" 
                                               class="expiry-input w-32 px-2 py-1 border rounded text-xs"
                                               title="วันหมดอายุ">
                                        <input type="date" 
                                               name="items[<?= $item['id'] ?>][manufacture_date]" 
                                               class="mfg-input w-32 px-2 py-1 border rounded text-xs"
                                               title="วันผลิต">
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-4">
                    <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                    <textarea name="notes" rows="2" class="w-full px-3 py-2 border rounded-lg" placeholder="หมายเหตุการรับสินค้า (ถ้ามี)"></textarea>
                </div>
                
                <div class="mt-4 flex gap-2">
                    <button type="submit" name="action" value="draft" class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        <i class="fas fa-save mr-1"></i>บันทึก Draft
                    </button>
                    <button type="submit" name="action" value="confirm" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        <i class="fas fa-check mr-1"></i>บันทึก & ยืนยัน
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent GRs List (shown when not viewing detail or creating) -->
    <?php if (!$grDetail && !$po && !empty($recentGRs)): ?>
    <div class="bg-white rounded-xl shadow mt-6">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold"><i class="fas fa-history mr-2 text-gray-500"></i>ประวัติการรับสินค้าล่าสุด</h2>
            <span class="text-sm text-gray-500"><?= count($recentGRs) ?> รายการ</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">เลขที่ GR</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">เลขที่ PO</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Supplier</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">วันที่รับ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สถานะ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($recentGRs as $gr): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-sm font-medium text-blue-600"><?= htmlspecialchars($gr['gr_number']) ?></td>
                        <td class="px-4 py-3 font-mono text-sm"><?= htmlspecialchars($gr['po_number'] ?? '-') ?></td>
                        <td class="px-4 py-3"><?= htmlspecialchars($gr['supplier_name'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center text-sm"><?= date('d/m/Y H:i', strtotime($gr['created_at'])) ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php 
                            $statusColors = [
                                'draft' => 'yellow',
                                'confirmed' => 'green',
                                'cancelled' => 'red'
                            ];
                            $statusLabels = [
                                'draft' => 'Draft',
                                'confirmed' => 'ยืนยันแล้ว',
                                'cancelled' => 'ยกเลิก'
                            ];
                            $color = $statusColors[$gr['status']] ?? 'gray';
                            $label = $statusLabels[$gr['status']] ?? $gr['status'];
                            ?>
                            <span class="px-2 py-1 bg-<?= $color ?>-100 text-<?= $color ?>-700 rounded text-xs"><?= $label ?></span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="?tab=gr&gr_id=<?= $gr['id'] ?>" class="px-3 py-1 bg-blue-100 text-blue-700 rounded text-sm hover:bg-blue-200">
                                <i class="fas fa-eye mr-1"></i>ดูรายละเอียด
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function fillAll() {
    document.querySelectorAll('.receive-qty').forEach(input => {
        input.value = input.dataset.max;
    });
}

document.getElementById('grForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const action = e.submitter.value;
    
    const items = {};
    document.querySelectorAll('tr[data-item-id]').forEach(row => {
        const itemId = row.dataset.itemId;
        const qtyInput = row.querySelector('.receive-qty');
        if (!qtyInput) return;
        
        const qty = parseInt(qtyInput.value) || 0;
        if (qty > 0) {
            // Get batch fields
            const batchNumber = row.querySelector('.batch-input')?.value || '';
            const lotNumber = row.querySelector('.lot-input')?.value || '';
            const expiryDate = row.querySelector('.expiry-input')?.value || '';
            const manufactureDate = row.querySelector('.mfg-input')?.value || '';
            
            items[itemId] = {
                quantity: qty,
                batch_number: batchNumber,
                lot_number: lotNumber,
                expiry_date: expiryDate,
                manufacture_date: manufactureDate
            };
        }
    });
    
    if (Object.keys(items).length === 0) {
        alert('กรุณาระบุจำนวนที่ต้องการรับอย่างน้อย 1 รายการ');
        return;
    }
    
    const data = {
        po_id: formData.get('po_id'),
        items: items,
        notes: formData.get('notes'),
        confirm: action === 'confirm'
    };
    
    try {
        const res = await fetch('api/inventory.php?action=create_gr', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        
        if (result.success) {
            alert('บันทึกการรับสินค้าเรียบร้อย');
            location.reload();
        } else {
            alert(result.message || 'เกิดข้อผิดพลาด');
        }
    } catch (err) {
        alert('เกิดข้อผิดพลาด: ' + err.message);
    }
});
</script>
