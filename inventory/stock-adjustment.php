<?php
/**
 * Stock Adjustment - ปรับสต็อก
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/InventoryService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;
$pageTitle = 'ปรับสต็อก (Stock Adjustment)';

$inventoryService = new InventoryService($db, $lineAccountId);

// Get products
$products = [];
try {
    $stmt = $db->prepare("SELECT id, name, sku, stock FROM business_items WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get adjustments
$adjustments = $inventoryService->getAdjustments(['limit' => 50]);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Create Adjustment -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold"><i class="fas fa-sliders-h mr-2 text-orange-500"></i>สร้างรายการปรับสต็อก</h2>
        </div>
        <form id="adjustmentForm" class="p-4 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">สินค้า *</label>
                <select name="product_id" required class="w-full px-3 py-2 border rounded-lg" onchange="updateStock(this)">
                    <option value="">-- เลือกสินค้า --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-stock="<?= $p['stock'] ?>">
                        <?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>) - Stock: <?= $p['stock'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="p-3 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-500">สต็อกปัจจุบัน</p>
                <p class="text-2xl font-bold" id="currentStock">-</p>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ประเภท *</label>
                    <select name="adjustment_type" required class="w-full px-3 py-2 border rounded-lg">
                        <option value="increase">เพิ่ม (+)</option>
                        <option value="decrease">ลด (-)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">จำนวน *</label>
                    <input type="number" name="quantity" min="1" required class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">เหตุผล *</label>
                <select name="reason" required class="w-full px-3 py-2 border rounded-lg">
                    <option value="physical_count">นับสต็อกจริง</option>
                    <option value="damaged">สินค้าเสียหาย</option>
                    <option value="expired">สินค้าหมดอายุ</option>
                    <option value="lost">สินค้าสูญหาย</option>
                    <option value="found">พบสินค้าเพิ่ม</option>
                    <option value="correction">แก้ไขข้อมูล</option>
                    <option value="other">อื่นๆ</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">รายละเอียดเพิ่มเติม</label>
                <textarea name="reason_detail" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" name="action" value="create" class="flex-1 px-4 py-2 bg-orange-600 text-white rounded-lg">
                    <i class="fas fa-save mr-1"></i>บันทึก (Draft)
                </button>
                <button type="submit" name="action" value="confirm" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg">
                    <i class="fas fa-check mr-1"></i>บันทึก & ยืนยัน
                </button>
            </div>
        </form>
    </div>
    
    <!-- Recent Adjustments -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold"><i class="fas fa-history mr-2 text-gray-500"></i>รายการปรับสต็อกล่าสุด</h2>
        </div>
        <div class="overflow-x-auto max-h-[500px]">
            <table class="w-full">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">เลขที่</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">จำนวน</th>
                        <th class="px-3 py-2 text-center text-xs font-medium text-gray-500">สถานะ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($adjustments as $adj): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 text-xs font-mono"><?= htmlspecialchars($adj['adjustment_number']) ?></td>
                        <td class="px-3 py-2 text-sm"><?= htmlspecialchars($adj['product_name']) ?></td>
                        <td class="px-3 py-2 text-center">
                            <span class="<?= $adj['adjustment_type'] === 'increase' ? 'text-green-600' : 'text-red-600' ?>">
                                <?= $adj['adjustment_type'] === 'increase' ? '+' : '-' ?><?= $adj['quantity'] ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <?php if ($adj['status'] === 'draft'): ?>
                            <button onclick="confirmAdj(<?= $adj['id'] ?>)" class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs">
                                Draft - ยืนยัน
                            </button>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs">Confirmed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function updateStock(select) {
    const stock = select.options[select.selectedIndex].dataset.stock || 0;
    document.getElementById('currentStock').textContent = stock;
}

document.getElementById('adjustmentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    const action = e.submitter.value;
    
    // Create adjustment
    const res = await fetch('../api/inventory.php?action=create_adjustment', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    const result = await res.json();
    
    if (result.success) {
        if (action === 'confirm') {
            // Confirm immediately
            await fetch('../api/inventory.php?action=confirm_adjustment&id=' + result.data.id, { method: 'POST' });
        }
        location.reload();
    } else {
        alert(result.message || 'Error');
    }
});

async function confirmAdj(id) {
    if (!confirm('ยืนยันการปรับสต็อกนี้?')) return;
    const res = await fetch('../api/inventory.php?action=confirm_adjustment&id=' + id, { method: 'POST' });
    const result = await res.json();
    if (result.success) location.reload();
    else alert(result.message || 'Error');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
