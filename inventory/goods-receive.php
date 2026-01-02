<?php
/**
 * Goods Receive - รับสินค้าเข้าคลัง
 * 
 * DEPRECATED: This file has been consolidated into procurement.php
 * Redirects to: procurement.php?tab=gr
 */
require_once __DIR__ . '/../includes/redirects.php';
handleRedirect();

// Fallback if redirect doesn't work
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PurchaseOrderService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;
$pageTitle = 'รับสินค้าเข้าคลัง (Goods Receive)';

$poService = new PurchaseOrderService($db, $lineAccountId);

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM purchase_orders LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

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
if ($tableExists) {
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
}

require_once __DIR__ . '/../includes/header.php';

if (!$tableExists):
?>
<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
    <i class="fas fa-database text-yellow-500 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold text-yellow-700 mb-2">ยังไม่ได้ติดตั้งระบบ Inventory</h3>
    <p class="text-yellow-600 mb-4">กรุณา run migration script เพื่อสร้างตาราง database</p>
    <div class="bg-white rounded-lg p-4 text-left max-w-lg mx-auto">
        <p class="text-sm text-gray-600 mb-2">Run SQL file:</p>
        <code class="text-xs bg-gray-100 p-2 rounded block">database/migration_inventory.sql</code>
    </div>
</div>
<?php else: ?>

<div class="space-y-6">
    <!-- PO Selection -->
    <?php if (!$po): ?>
    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-file-alt mr-2 text-blue-500"></i>เลือก PO ที่ต้องการรับสินค้า</h2>
        
        <?php if (empty($pendingPOs)): ?>
        <div class="text-center py-8 text-gray-500">
            <i class="fas fa-inbox text-4xl mb-3"></i>
            <p>ไม่มี PO ที่รอรับสินค้า</p>
            <a href="purchase-orders.php" class="text-green-600 hover:underline mt-2 inline-block">
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
                            <a href="?po_id=<?= $p['id'] ?>" class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
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
                    <div class="p-2 bg-gray-50 rounded text-xs">
                        <div class="font-mono"><?= htmlspecialchars($gr['gr_number']) ?></div>
                        <div class="text-gray-500"><?= date('d/m/Y H:i', strtotime($gr['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <a href="?po_id=" class="mt-4 block text-center text-sm text-gray-500 hover:text-gray-700">
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
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($poItems as $item): 
                                $remaining = $item['quantity'] - $item['received_quantity'];
                            ?>
                            <tr class="<?= $remaining <= 0 ? 'bg-green-50' : '' ?>">
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
                                           name="items[<?= $item['id'] ?>]" 
                                           class="receive-qty w-20 px-2 py-1 border rounded text-center"
                                           min="0" 
                                           max="<?= $remaining ?>"
                                           data-max="<?= $remaining ?>"
                                           value="0">
                                    <?php else: ?>
                                    <span class="text-green-600"><i class="fas fa-check"></i></span>
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
    
    // Build items array
    const items = {};
    document.querySelectorAll('.receive-qty').forEach(input => {
        const qty = parseInt(input.value) || 0;
        if (qty > 0) {
            const itemId = input.name.match(/\[(\d+)\]/)[1];
            items[itemId] = qty;
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
        const res = await fetch('../api/inventory.php?action=create_gr', {
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
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
