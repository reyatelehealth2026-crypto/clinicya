<?php
/**
 * Low Stock Alerts - แจ้งเตือนสินค้าใกล้หมด + Bulk Order + Reorder Point
 * 
 * DEPRECATED: This file has been consolidated into inventory/index.php
 * Redirects to: inventory/index.php?tab=low-stock
 */
require_once __DIR__ . '/../includes/redirects.php';
handleRedirect();

// Fallback if redirect doesn't work
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/InventoryService.php';
require_once __DIR__ . '/../classes/SupplierService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;
$pageTitle = 'สินค้าใกล้หมด & จุดสั่งซื้อ (ROP)';

$inventoryService = new InventoryService($db, $lineAccountId);
$supplierService = new SupplierService($db, $lineAccountId);

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM stock_movements LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

// Get suppliers for dropdown
$suppliers = [];
try {
    $suppliers = $supplierService->getAll(['is_active' => 1]);
} catch (Exception $e) {}

// Get low stock products with supplier info
$lowStockProducts = [];
if ($tableExists) {
    $lowStockProducts = $inventoryService->getLowStockProductsWithSupplier();
}

// Categorize products
$outOfStock = [];
$criticalStock = [];
$warningStock = [];

foreach ($lowStockProducts as $p) {
    if ($p['stock'] <= 0) {
        $outOfStock[] = $p;
    } elseif ($p['stock'] <= ($p['reorder_point'] ?? 5) / 2) {
        $criticalStock[] = $p;
    } else {
        $warningStock[] = $p;
    }
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
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-red-50 border border-red-200 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-600 text-sm font-medium">หมดสต็อก</p>
                    <p class="text-3xl font-bold text-red-700"><?= count($outOfStock) ?></p>
                </div>
                <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-times-circle text-red-500 text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-orange-50 border border-orange-200 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-600 text-sm font-medium">วิกฤต</p>
                    <p class="text-3xl font-bold text-orange-700"><?= count($criticalStock) ?></p>
                </div>
                <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-orange-500 text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-yellow-600 text-sm font-medium">ใกล้หมด</p>
                    <p class="text-3xl font-bold text-yellow-700"><?= count($warningStock) ?></p>
                </div>
                <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation text-yellow-500 text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-600 text-sm font-medium">รวมทั้งหมด</p>
                    <p class="text-3xl font-bold text-blue-700"><?= count($lowStockProducts) ?></p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-boxes text-blue-500 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Action Bar -->
    <div id="bulkActionBar" class="hidden bg-blue-600 text-white rounded-xl p-4 flex items-center justify-between sticky top-0 z-10 shadow-lg">
        <div class="flex items-center gap-3">
            <span class="font-medium">เลือกแล้ว <span id="selectedCount">0</span> รายการ</span>
        </div>
        <div class="flex gap-2">
            <button onclick="clearSelection()" class="px-4 py-2 bg-white/20 rounded-lg hover:bg-white/30">
                <i class="fas fa-times mr-1"></i>ยกเลิก
            </button>
            <button onclick="openBulkOrderModal()" class="px-4 py-2 bg-green-500 rounded-lg hover:bg-green-600">
                <i class="fas fa-cart-plus mr-1"></i>สร้าง PO
            </button>
        </div>
    </div>

    <!-- All Low Stock Products -->
    <?php if (!empty($lowStockProducts)): ?>
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center flex-wrap gap-2">
            <h2 class="font-semibold"><i class="fas fa-exclamation-circle mr-2 text-orange-500"></i>สินค้าถึงจุดสั่งซื้อ (Reorder Point)</h2>
            <div class="flex gap-2">
                <button onclick="selectAll()" class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">
                    <i class="fas fa-check-double mr-1"></i>เลือกทั้งหมด
                </button>
                <button onclick="autoReorder()" class="px-3 py-1.5 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700">
                    <i class="fas fa-magic mr-1"></i>Auto Reorder
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-center w-10">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" class="w-4 h-4 rounded">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สต็อก</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ROP</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สั่งซื้อ</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Supplier</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สถานะ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($lowStockProducts as $p): 
                        $rop = $p['reorder_point'] ?? 5;
                        $orderQty = max($rop * 2 - $p['stock'], $rop); // Default order qty
                        $status = $p['stock'] <= 0 ? 'out' : ($p['stock'] <= $rop / 2 ? 'critical' : 'warning');
                        $statusColors = ['out' => 'red', 'critical' => 'orange', 'warning' => 'yellow'];
                        $statusLabels = ['out' => 'หมด', 'critical' => 'วิกฤต', 'warning' => 'ใกล้หมด'];
                    ?>
                    <tr class="hover:bg-gray-50 product-row" 
                        data-id="<?= $p['id'] ?>" 
                        data-name="<?= htmlspecialchars($p['name']) ?>"
                        data-sku="<?= htmlspecialchars($p['sku'] ?? '') ?>"
                        data-supplier="<?= $p['supplier_id'] ?? '' ?>"
                        data-cost="<?= $p['cost_price'] ?? 0 ?>"
                        data-order-qty="<?= $orderQty ?>">
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" class="product-checkbox w-4 h-4 rounded" onchange="updateSelection()">
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium"><?= htmlspecialchars($p['name']) ?></div>
                        </td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($p['sku'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $statusColors[$status] ?>-100 text-<?= $statusColors[$status] ?>-700 rounded font-bold">
                                <?= $p['stock'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-600"><?= $rop ?></td>
                        <td class="px-4 py-3 text-center">
                            <input type="number" class="order-qty w-16 px-2 py-1 border rounded text-center text-sm" 
                                   value="<?= $orderQty ?>" min="1">
                        </td>
                        <td class="px-4 py-3">
                            <select class="supplier-select px-2 py-1 border rounded text-sm w-full max-w-[150px]">
                                <option value="">-- เลือก --</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($p['supplier_id'] ?? '') == $s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $statusColors[$status] ?>-100 text-<?= $statusColors[$status] ?>-700 rounded text-xs">
                                <?= $statusLabels[$status] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-green-50 border border-green-200 rounded-xl p-8 text-center">
        <i class="fas fa-check-circle text-green-500 text-4xl mb-3"></i>
        <p class="text-green-700 font-medium">สต็อกสินค้าทั้งหมดอยู่ในระดับปกติ</p>
    </div>
    <?php endif; ?>
</div>

<!-- Bulk Order Modal -->
<div id="bulkOrderModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-hidden">
        <div class="p-4 border-b flex justify-between items-center bg-green-50">
            <h3 class="font-semibold text-green-700"><i class="fas fa-cart-plus mr-2"></i>สร้างใบสั่งซื้อ (Bulk Order)</h3>
            <button onclick="closeBulkOrderModal()" class="p-2 hover:bg-green-100 rounded"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-4 overflow-y-auto max-h-[60vh]">
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Supplier *</label>
                <select id="bulkSupplier" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- เลือก Supplier --</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= $s['code'] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">หรือจะสร้าง PO แยกตาม Supplier ของแต่ละสินค้า</p>
            </div>
            
            <div class="mb-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" id="groupBySupplier" class="w-4 h-4 rounded">
                    <span class="text-sm">แยก PO ตาม Supplier ของสินค้า</span>
                </label>
            </div>
            
            <div class="border rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left">สินค้า</th>
                            <th class="px-3 py-2 text-center">จำนวน</th>
                            <th class="px-3 py-2 text-right">ราคา/หน่วย</th>
                            <th class="px-3 py-2 text-right">รวม</th>
                        </tr>
                    </thead>
                    <tbody id="bulkOrderItems" class="divide-y">
                        <!-- Items will be populated by JS -->
                    </tbody>
                    <tfoot class="bg-gray-100">
                        <tr>
                            <td colspan="3" class="px-3 py-2 font-bold text-right">รวมทั้งหมด</td>
                            <td class="px-3 py-2 font-bold text-right" id="bulkOrderTotal">฿0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="p-4 border-t flex gap-2">
            <button onclick="closeBulkOrderModal()" class="flex-1 px-4 py-2 bg-gray-200 rounded-lg">ยกเลิก</button>
            <button onclick="createBulkPO()" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <i class="fas fa-check mr-1"></i>สร้าง PO
            </button>
        </div>
    </div>
</div>

<script>
let selectedProducts = [];

function updateSelection() {
    selectedProducts = [];
    document.querySelectorAll('.product-checkbox:checked').forEach(cb => {
        const row = cb.closest('.product-row');
        selectedProducts.push({
            id: row.dataset.id,
            name: row.dataset.name,
            sku: row.dataset.sku,
            supplier_id: row.querySelector('.supplier-select').value,
            cost: parseFloat(row.dataset.cost) || 0,
            quantity: parseInt(row.querySelector('.order-qty').value) || 1
        });
    });
    
    document.getElementById('selectedCount').textContent = selectedProducts.length;
    document.getElementById('bulkActionBar').classList.toggle('hidden', selectedProducts.length === 0);
    document.getElementById('selectAllCheckbox').checked = 
        document.querySelectorAll('.product-checkbox').length === document.querySelectorAll('.product-checkbox:checked').length;
}

function toggleSelectAll(cb) {
    document.querySelectorAll('.product-checkbox').forEach(checkbox => {
        checkbox.checked = cb.checked;
    });
    updateSelection();
}

function selectAll() {
    document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = true);
    document.getElementById('selectAllCheckbox').checked = true;
    updateSelection();
}

function clearSelection() {
    document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('selectAllCheckbox').checked = false;
    updateSelection();
}

function openBulkOrderModal() {
    if (selectedProducts.length === 0) {
        alert('กรุณาเลือกสินค้าอย่างน้อย 1 รายการ');
        return;
    }
    
    // Populate items
    const tbody = document.getElementById('bulkOrderItems');
    tbody.innerHTML = '';
    let total = 0;
    
    selectedProducts.forEach(p => {
        const subtotal = p.quantity * p.cost;
        total += subtotal;
        tbody.innerHTML += `
            <tr>
                <td class="px-3 py-2">${p.name}</td>
                <td class="px-3 py-2 text-center">${p.quantity}</td>
                <td class="px-3 py-2 text-right">฿${p.cost.toFixed(2)}</td>
                <td class="px-3 py-2 text-right">฿${subtotal.toFixed(2)}</td>
            </tr>
        `;
    });
    
    document.getElementById('bulkOrderTotal').textContent = '฿' + total.toFixed(2);
    document.getElementById('bulkOrderModal').classList.remove('hidden');
    document.getElementById('bulkOrderModal').classList.add('flex');
}

function closeBulkOrderModal() {
    document.getElementById('bulkOrderModal').classList.add('hidden');
    document.getElementById('bulkOrderModal').classList.remove('flex');
}

async function createBulkPO() {
    const supplierId = document.getElementById('bulkSupplier').value;
    const groupBySupplier = document.getElementById('groupBySupplier').checked;
    
    if (!supplierId && !groupBySupplier) {
        alert('กรุณาเลือก Supplier หรือเลือก "แยก PO ตาม Supplier"');
        return;
    }
    
    try {
        const res = await fetch('../api/inventory.php?action=bulk_create_po', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                supplier_id: supplierId,
                group_by_supplier: groupBySupplier,
                items: selectedProducts
            })
        });
        const result = await res.json();
        
        if (result.success) {
            alert(`สร้าง PO สำเร็จ ${result.data.po_count} รายการ`);
            if (result.data.po_ids.length === 1) {
                window.location.href = 'po-detail.php?id=' + result.data.po_ids[0];
            } else {
                window.location.href = 'purchase-orders.php';
            }
        } else {
            alert(result.message || 'เกิดข้อผิดพลาด');
        }
    } catch (err) {
        alert('เกิดข้อผิดพลาด: ' + err.message);
    }
}

async function autoReorder() {
    if (!confirm('ระบบจะสร้าง PO อัตโนมัติสำหรับสินค้าที่ถึงจุดสั่งซื้อ (ROP)\nโดยจะแยก PO ตาม Supplier ของแต่ละสินค้า\n\nดำเนินการต่อ?')) {
        return;
    }
    
    // Select all and group by supplier
    selectAll();
    document.getElementById('groupBySupplier').checked = true;
    
    try {
        const res = await fetch('../api/inventory.php?action=auto_reorder', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        const result = await res.json();
        
        if (result.success) {
            alert(`Auto Reorder สำเร็จ!\nสร้าง PO ${result.data.po_count} รายการ\nสินค้า ${result.data.item_count} รายการ`);
            window.location.href = 'purchase-orders.php';
        } else {
            alert(result.message || 'เกิดข้อผิดพลาด');
        }
    } catch (err) {
        alert('เกิดข้อผิดพลาด: ' + err.message);
    }
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
