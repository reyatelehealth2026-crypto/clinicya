<?php
/**
 * Purchase Orders - จัดการใบสั่งซื้อ
 * 
 * DEPRECATED: This file has been consolidated into procurement.php
 * Redirects to: procurement.php?tab=po
 */
require_once __DIR__ . '/../includes/redirects.php';
handleRedirect();

// Fallback if redirect doesn't work
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PurchaseOrderService.php';
require_once __DIR__ . '/../classes/SupplierService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;
$pageTitle = 'ใบสั่งซื้อ (Purchase Order)';

$poService = new PurchaseOrderService($db, $lineAccountId);
$supplierService = new SupplierService($db, $lineAccountId);

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM purchase_orders LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

// Get data
$statusFilter = $_GET['status'] ?? '';
$filters = ['status' => $statusFilter ?: null, 'limit' => 100];
$purchaseOrders = $tableExists ? $poService->getAllPOs($filters) : [];
$suppliers = $tableExists ? $supplierService->getAll(['is_active' => 1]) : [];

// Get products for adding items
$products = [];
try {
    // Check if cost_price column exists
    $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
    $hasCostPrice = in_array('cost_price', $cols);
    $costPriceCol = $hasCostPrice ? "cost_price" : "0 as cost_price";
    
    $stmt = $db->prepare("SELECT id, name, sku, {$costPriceCol}, stock FROM business_items WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Stats
$stats = ['draft' => 0, 'submitted' => 0, 'partial' => 0, 'completed' => 0];
foreach ($purchaseOrders as $po) {
    if (isset($stats[$po['status']])) $stats[$po['status']]++;
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

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <a href="?status=draft" class="bg-white rounded-xl shadow p-4 hover:shadow-lg <?= $statusFilter === 'draft' ? 'ring-2 ring-gray-400' : '' ?>">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-file text-gray-600"></i>
            </div>
            <div class="ml-3">
                <p class="text-xs text-gray-500">Draft</p>
                <p class="text-xl font-bold"><?= $stats['draft'] ?></p>
            </div>
        </div>
    </a>
    <a href="?status=submitted" class="bg-white rounded-xl shadow p-4 hover:shadow-lg <?= $statusFilter === 'submitted' ? 'ring-2 ring-blue-400' : '' ?>">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-paper-plane text-blue-600"></i>
            </div>
            <div class="ml-3">
                <p class="text-xs text-gray-500">Submitted</p>
                <p class="text-xl font-bold text-blue-600"><?= $stats['submitted'] ?></p>
            </div>
        </div>
    </a>
    <a href="?status=partial" class="bg-white rounded-xl shadow p-4 hover:shadow-lg <?= $statusFilter === 'partial' ? 'ring-2 ring-yellow-400' : '' ?>">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-clock text-yellow-600"></i>
            </div>
            <div class="ml-3">
                <p class="text-xs text-gray-500">Partial</p>
                <p class="text-xl font-bold text-yellow-600"><?= $stats['partial'] ?></p>
            </div>
        </div>
    </a>
    <a href="?status=completed" class="bg-white rounded-xl shadow p-4 hover:shadow-lg <?= $statusFilter === 'completed' ? 'ring-2 ring-green-400' : '' ?>">
        <div class="flex items-center">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-check-circle text-green-600"></i>
            </div>
            <div class="ml-3">
                <p class="text-xs text-gray-500">Completed</p>
                <p class="text-xl font-bold text-green-600"><?= $stats['completed'] ?></p>
            </div>
        </div>
    </a>
</div>

<!-- PO List -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex justify-between items-center flex-wrap gap-2">
        <h2 class="font-semibold"><i class="fas fa-file-invoice mr-2 text-blue-500"></i>รายการใบสั่งซื้อ</h2>
        <div class="flex gap-2">
            <?php if ($statusFilter): ?>
            <a href="purchase-orders.php" class="px-3 py-2 bg-gray-200 rounded-lg text-sm">ดูทั้งหมด</a>
            <?php endif; ?>
            <button onclick="openCreateModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-plus mr-1"></i>สร้าง PO
            </button>
        </div>
    </div>
    
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เลข PO</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">วันที่สั่ง</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">ยอดรวม</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($purchaseOrders)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">ไม่มีข้อมูล</td></tr>
                <?php else: ?>
                <?php foreach ($purchaseOrders as $po): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="po-detail.php?id=<?= $po['id'] ?>" class="font-mono text-blue-600 hover:underline"><?= htmlspecialchars($po['po_number']) ?></a>
                    </td>
                    <td class="px-4 py-3"><?= htmlspecialchars($po['supplier_name']) ?></td>
                    <td class="px-4 py-3 text-center text-sm"><?= date('d/m/Y', strtotime($po['order_date'])) ?></td>
                    <td class="px-4 py-3 text-right font-medium">฿<?= number_format($po['total_amount'], 2) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $statusColors = [
                            'draft' => 'bg-gray-100 text-gray-700',
                            'submitted' => 'bg-blue-100 text-blue-700',
                            'partial' => 'bg-yellow-100 text-yellow-700',
                            'completed' => 'bg-green-100 text-green-700',
                            'cancelled' => 'bg-red-100 text-red-700'
                        ];
                        $statusLabels = [
                            'draft' => 'Draft',
                            'submitted' => 'Submitted',
                            'partial' => 'Partial',
                            'completed' => 'Completed',
                            'cancelled' => 'Cancelled'
                        ];
                        ?>
                        <span class="px-2 py-1 rounded-full text-xs <?= $statusColors[$po['status']] ?? 'bg-gray-100' ?>">
                            <?= $statusLabels[$po['status']] ?? $po['status'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="po-detail.php?id=<?= $po['id'] ?>" class="p-2 text-blue-600 hover:bg-blue-50 rounded inline-block">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if (in_array($po['status'], ['submitted', 'partial'])): ?>
                        <a href="goods-receive.php?po_id=<?= $po['id'] ?>" class="p-2 text-green-600 hover:bg-green-50 rounded inline-block" title="รับสินค้า">
                            <i class="fas fa-truck-loading"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create PO Modal -->
<div id="createModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold">สร้างใบสั่งซื้อใหม่</h3>
            <button onclick="closeCreateModal()" class="p-2 hover:bg-gray-100 rounded"><i class="fas fa-times"></i></button>
        </div>
        <form id="createForm" class="p-4 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">Supplier *</label>
                <select name="supplier_id" required class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- เลือก Supplier --</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= $s['code'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">วันที่สั่ง</label>
                    <input type="date" name="order_date" value="<?= date('Y-m-d') ?>" class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">วันที่คาดว่าจะได้รับ</label>
                    <input type="date" name="expected_date" class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
            </div>
            <div class="flex gap-2 pt-4 border-t">
                <button type="button" onclick="closeCreateModal()" class="flex-1 px-4 py-2 bg-gray-200 rounded-lg">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg">สร้าง PO</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
    document.getElementById('createModal').classList.add('flex');
}
function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
    document.getElementById('createModal').classList.remove('flex');
}

document.getElementById('createForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    const res = await fetch('../api/inventory.php?action=create_po', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    const result = await res.json();
    
    if (result.success) {
        window.location.href = 'po-detail.php?id=' + result.data.id;
    } else {
        alert(result.message || 'Error');
    }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
