<?php
/**
 * Purchase Order Detail - รายละเอียดใบสั่งซื้อ
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PurchaseOrderService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

$poService = new PurchaseOrderService($db, $lineAccountId);

$poId = (int)($_GET['id'] ?? 0);
if (!$poId) {
    header('Location: purchase-orders.php');
    exit;
}

$po = $poService->getPO($poId);
if (!$po) {
    header('Location: purchase-orders.php');
    exit;
}

$items = $poService->getPOItems($poId);
$pageTitle = 'PO: ' . $po['po_number'];

// Get products for adding items
$products = [];
try {
    $stmt = $db->prepare("SELECT id, name, sku, cost_price, stock FROM business_items WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../includes/header.php';

$statusColors = [
    'draft' => 'bg-gray-100 text-gray-700',
    'submitted' => 'bg-blue-100 text-blue-700',
    'partial' => 'bg-yellow-100 text-yellow-700',
    'completed' => 'bg-green-100 text-green-700',
    'cancelled' => 'bg-red-100 text-red-700'
];
?>

<div class="mb-4">
    <a href="purchase-orders.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-1"></i>กลับ</a>
</div>

<!-- PO Header -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b flex justify-between items-center flex-wrap gap-2">
        <div>
            <h2 class="text-xl font-bold"><?= htmlspecialchars($po['po_number']) ?></h2>
            <p class="text-sm text-gray-500">Supplier: <?= htmlspecialchars($po['supplier_name']) ?></p>
        </div>
        <div class="flex items-center gap-2">
            <span class="px-3 py-1 rounded-full text-sm <?= $statusColors[$po['status']] ?>">
                <?= ucfirst($po['status']) ?>
            </span>
            <?php if ($po['status'] === 'draft'): ?>
            <button onclick="submitPO()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-paper-plane mr-1"></i>Submit
            </button>
            <button onclick="cancelPO()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                <i class="fas fa-times mr-1"></i>Cancel
            </button>
            <?php elseif (in_array($po['status'], ['submitted', 'partial'])): ?>
            <a href="goods-receive.php?po_id=<?= $poId ?>" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <i class="fas fa-truck-loading mr-1"></i>รับสินค้า
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div>
            <p class="text-gray-500">วันที่สั่ง</p>
            <p class="font-medium"><?= date('d/m/Y', strtotime($po['order_date'])) ?></p>
        </div>
        <div>
            <p class="text-gray-500">วันที่คาดว่าจะได้รับ</p>
            <p class="font-medium"><?= $po['expected_date'] ? date('d/m/Y', strtotime($po['expected_date'])) : '-' ?></p>
        </div>
        <div>
            <p class="text-gray-500">ยอดรวม</p>
            <p class="font-bold text-lg">฿<?= number_format($po['total_amount'], 2) ?></p>
        </div>
        <div>
            <p class="text-gray-500">หมายเหตุ</p>
            <p class="font-medium"><?= htmlspecialchars($po['notes'] ?? '-') ?></p>
        </div>
    </div>
</div>

<!-- Items -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex justify-between items-center">
        <h3 class="font-semibold"><i class="fas fa-list mr-2"></i>รายการสินค้า</h3>
        <?php if ($po['status'] === 'draft'): ?>
        <button onclick="openAddItemModal()" class="px-3 py-1.5 bg-green-600 text-white rounded-lg text-sm">
            <i class="fas fa-plus mr-1"></i>เพิ่มสินค้า
        </button>
        <?php endif; ?>
    </div>
    
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">สินค้า</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จำนวนสั่ง</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">รับแล้ว</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">ราคา/หน่วย</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">รวม</th>
                    <?php if ($po['status'] === 'draft'): ?>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($items)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">ยังไม่มีรายการสินค้า</td></tr>
                <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <p class="font-medium"><?= htmlspecialchars($item['product_name']) ?></p>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars($item['sku'] ?? '') ?></p>
                    </td>
                    <td class="px-4 py-3 text-center"><?= number_format($item['quantity']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="<?= $item['received_quantity'] >= $item['quantity'] ? 'text-green-600' : 'text-orange-600' ?>">
                            <?= number_format($item['received_quantity']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">฿<?= number_format($item['unit_cost'], 2) ?></td>
                    <td class="px-4 py-3 text-right font-medium">฿<?= number_format($item['subtotal'], 2) ?></td>
                    <?php if ($po['status'] === 'draft'): ?>
                    <td class="px-4 py-3 text-center">
                        <button onclick="removeItem(<?= $item['id'] ?>)" class="p-2 text-red-600 hover:bg-red-50 rounded">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Item Modal -->
<div id="addItemModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold">เพิ่มสินค้า</h3>
            <button onclick="closeAddItemModal()" class="p-2 hover:bg-gray-100 rounded"><i class="fas fa-times"></i></button>
        </div>
        <form id="addItemForm" class="p-4 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">สินค้า *</label>
                <select name="product_id" required class="w-full px-3 py-2 border rounded-lg" onchange="updateCost(this)">
                    <option value="">-- เลือกสินค้า --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-cost="<?= $p['cost_price'] ?? 0 ?>">
                        <?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>) - Stock: <?= $p['stock'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">จำนวน *</label>
                    <input type="number" name="quantity" min="1" value="1" required class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ราคา/หน่วย *</label>
                    <input type="number" name="unit_cost" min="0" step="0.01" required class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            <div class="flex gap-2 pt-4 border-t">
                <button type="button" onclick="closeAddItemModal()" class="flex-1 px-4 py-2 bg-gray-200 rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg">เพิ่ม</button>
            </div>
        </form>
    </div>
</div>

<script>
const poId = <?= $poId ?>;

function openAddItemModal() {
    document.getElementById('addItemForm').reset();
    document.getElementById('addItemModal').classList.remove('hidden');
    document.getElementById('addItemModal').classList.add('flex');
}
function closeAddItemModal() {
    document.getElementById('addItemModal').classList.add('hidden');
    document.getElementById('addItemModal').classList.remove('flex');
}

function updateCost(select) {
    const cost = select.options[select.selectedIndex].dataset.cost || 0;
    document.querySelector('[name="unit_cost"]').value = cost;
}

document.getElementById('addItemForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    data.po_id = poId;
    
    const res = await fetch('../api/inventory.php?action=add_po_item', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    const result = await res.json();
    
    if (result.success) {
        location.reload();
    } else {
        alert(result.message || 'Error');
    }
});

async function removeItem(itemId) {
    if (!confirm('ลบรายการนี้?')) return;
    const res = await fetch('../api/inventory.php?action=remove_po_item&item_id=' + itemId, { method: 'POST' });
    const result = await res.json();
    if (result.success) location.reload();
    else alert(result.message || 'Error');
}

async function submitPO() {
    if (!confirm('Submit PO นี้?')) return;
    const res = await fetch('../api/inventory.php?action=submit_po&id=' + poId, { method: 'POST' });
    const result = await res.json();
    if (result.success) location.reload();
    else alert(result.message || 'Error');
}

async function cancelPO() {
    const reason = prompt('เหตุผลที่ยกเลิก:');
    if (!reason) return;
    const formData = new FormData();
    formData.append('id', poId);
    formData.append('reason', reason);
    const res = await fetch('../api/inventory.php?action=cancel_po', { method: 'POST', body: formData });
    const result = await res.json();
    if (result.success) location.reload();
    else alert(result.message || 'Error');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
