<?php
/**
 * Purchase Order Detail - รายละเอียดใบสั่งซื้อ
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PurchaseOrderService.php';

// Load ProductUnitService if exists
$hasProductUnitService = file_exists(__DIR__ . '/../classes/ProductUnitService.php');
if ($hasProductUnitService) {
    require_once __DIR__ . '/../classes/ProductUnitService.php';
}

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

$poService = new PurchaseOrderService($db, $lineAccountId);

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM purchase_orders LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

$poId = (int)($_GET['id'] ?? 0);
if (!$poId || !$tableExists) {
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
$productUnits = []; // unit_id => unit data
try {
    // Check if cost_price and unit columns exist
    $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
    $hasCostPrice = in_array('cost_price', $cols);
    $hasUnit = in_array('unit', $cols);
    $costPriceCol = $hasCostPrice ? "cost_price" : "0 as cost_price";
    $unitCol = $hasUnit ? "unit" : "'ชิ้น' as unit";
    
    $stmt = $db->prepare("SELECT id, name, sku, {$costPriceCol}, {$unitCol}, stock FROM business_items WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get product units if table exists
    try {
        $db->query("SELECT 1 FROM product_units LIMIT 1");
        $stmt = $db->prepare("
            SELECT pu.*, bi.name as product_name 
            FROM product_units pu 
            JOIN business_items bi ON pu.product_id = bi.id
            WHERE pu.is_active = 1 AND pu.is_purchase_unit = 1
            ORDER BY pu.product_id, pu.factor
        ");
        $stmt->execute();
        $allUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by product_id
        foreach ($allUnits as $u) {
            if (!isset($productUnits[$u['product_id']])) {
                $productUnits[$u['product_id']] = [];
            }
            $productUnits[$u['product_id']][] = $u;
        }
    } catch (Exception $e) {
        // product_units table doesn't exist
    }
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
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">หน่วย</th>
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
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">ยังไม่มีรายการสินค้า</td></tr>
                <?php else: ?>
                <?php foreach ($items as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <p class="font-medium"><?= htmlspecialchars($item['product_name']) ?></p>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars($item['sku'] ?? '') ?></p>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-sm">
                            <?= htmlspecialchars($item['display_unit'] ?? $item['unit_name'] ?? 'ชิ้น') ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center"><?= number_format($item['quantity']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="<?= $item['received_quantity'] >= $item['quantity'] ? 'text-green-600' : 'text-orange-600' ?>">
                            <?= number_format($item['received_quantity']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($po['status'] === 'draft'): ?>
                        <input type="number" 
                               id="cost_<?= $item['id'] ?>" 
                               value="<?= $item['unit_cost'] ?>" 
                               min="0" 
                               step="0.01"
                               onchange="updateItemCost(<?= $item['id'] ?>, this.value)"
                               class="w-24 px-2 py-1 border rounded text-right text-sm">
                        <?php else: ?>
                        ฿<?= number_format($item['unit_cost'], 2) ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-medium" id="subtotal_<?= $item['id'] ?>">฿<?= number_format($item['subtotal'], 2) ?></td>
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
            <div class="relative">
                <label class="block text-sm font-medium mb-1">สินค้า *</label>
                <input type="text" 
                       id="productSearch" 
                       placeholder="พิมพ์ชื่อหรือ SKU เพื่อค้นหา..." 
                       autocomplete="off"
                       class="w-full px-3 py-2 border rounded-lg">
                <input type="hidden" name="product_id" id="selectedProductId" required>
                <div id="productSearchResults" class="absolute z-10 w-full bg-white border rounded-lg shadow-lg mt-1 max-h-60 overflow-y-auto hidden"></div>
                <div id="selectedProduct" class="mt-2 p-2 bg-green-50 border border-green-200 rounded-lg hidden">
                    <div class="flex justify-between items-center">
                        <span id="selectedProductName" class="text-sm font-medium text-green-800"></span>
                        <button type="button" onclick="clearSelectedProduct()" class="text-red-500 hover:text-red-700">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Unit Selection (shown if product has multiple units) -->
            <div id="unitSelectDiv" class="hidden">
                <label class="block text-sm font-medium mb-1">หน่วย *</label>
                <select name="unit_id" id="unitSelect" class="w-full px-3 py-2 border rounded-lg" onchange="onUnitChange(this)">
                    <option value="">-- เลือกหน่วย --</option>
                </select>
                <input type="hidden" name="unit_name" id="unitName">
                <input type="hidden" name="unit_factor" id="unitFactor" value="1">
            </div>
            
            <!-- Default Unit Display (shown if product has no multiple units) -->
            <div id="defaultUnitDiv">
                <label class="block text-sm font-medium mb-1">หน่วย</label>
                <input type="text" id="defaultUnit" readonly class="w-full px-3 py-2 border rounded-lg bg-gray-50" value="ชิ้น">
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
const productUnits = <?= json_encode($productUnits) ?>;
const allProducts = <?= json_encode($products) ?>;

function openAddItemModal() {
    document.getElementById('addItemForm').reset();
    document.getElementById('selectedProductId').value = '';
    document.getElementById('productSearch').value = '';
    document.getElementById('selectedProduct').classList.add('hidden');
    document.getElementById('productSearchResults').classList.add('hidden');
    document.getElementById('unitSelectDiv').classList.add('hidden');
    document.getElementById('defaultUnitDiv').classList.remove('hidden');
    document.getElementById('addItemModal').classList.remove('hidden');
    document.getElementById('addItemModal').classList.add('flex');
    setTimeout(() => document.getElementById('productSearch').focus(), 100);
}
function closeAddItemModal() {
    document.getElementById('addItemModal').classList.add('hidden');
    document.getElementById('addItemModal').classList.remove('flex');
}

// Product search functionality
const productSearchInput = document.getElementById('productSearch');
const productSearchResults = document.getElementById('productSearchResults');
let searchTimeout;

productSearchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim().toLowerCase();
    
    if (query.length < 1) {
        productSearchResults.classList.add('hidden');
        return;
    }
    
    searchTimeout = setTimeout(() => {
        const filtered = allProducts.filter(p => 
            p.name.toLowerCase().includes(query) || 
            (p.sku && p.sku.toLowerCase().includes(query))
        ).slice(0, 10);
        
        if (filtered.length === 0) {
            productSearchResults.innerHTML = '<div class="p-3 text-gray-500 text-sm">ไม่พบสินค้า</div>';
        } else {
            productSearchResults.innerHTML = filtered.map(p => `
                <div class="p-3 hover:bg-blue-50 cursor-pointer border-b last:border-b-0" 
                     onclick="selectProduct(${p.id}, '${escapeHtml(p.name)}', '${p.sku || ''}', ${p.cost_price || 0}, '${escapeHtml(p.unit || 'ชิ้น')}', ${p.stock})">
                    <div class="font-medium text-sm">${escapeHtml(p.name)}</div>
                    <div class="text-xs text-gray-500">SKU: ${p.sku || '-'} | Stock: ${p.stock} | ราคาทุน: ฿${(p.cost_price || 0).toLocaleString()}</div>
                </div>
            `).join('');
        }
        productSearchResults.classList.remove('hidden');
    }, 200);
});

productSearchInput.addEventListener('focus', function() {
    if (this.value.trim().length >= 1) {
        productSearchResults.classList.remove('hidden');
    }
});

// Close search results when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('#productSearch') && !e.target.closest('#productSearchResults')) {
        productSearchResults.classList.add('hidden');
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function selectProduct(id, name, sku, cost, unit, stock) {
    document.getElementById('selectedProductId').value = id;
    document.getElementById('selectedProductName').textContent = `${name} (${sku}) - Stock: ${stock}`;
    document.getElementById('selectedProduct').classList.remove('hidden');
    document.getElementById('productSearch').value = '';
    document.getElementById('productSearchResults').classList.add('hidden');
    document.querySelector('[name="unit_cost"]').value = cost;
    
    // Check for product units
    const hasUnits = productUnits[id] && productUnits[id].length > 0;
    
    if (hasUnits) {
        document.getElementById('unitSelectDiv').classList.remove('hidden');
        document.getElementById('defaultUnitDiv').classList.add('hidden');
        
        const unitSelect = document.getElementById('unitSelect');
        unitSelect.innerHTML = '<option value="">-- เลือกหน่วย --</option>';
        
        productUnits[id].forEach(u => {
            const opt = document.createElement('option');
            opt.value = u.id;
            opt.dataset.cost = u.cost_price || cost;
            opt.dataset.name = u.unit_name;
            opt.dataset.factor = u.factor;
            opt.textContent = u.unit_name + (u.factor > 1 ? ` (${u.factor} ชิ้น)` : '') + (u.is_base_unit ? ' [หลัก]' : '');
            unitSelect.appendChild(opt);
        });
    } else {
        document.getElementById('unitSelectDiv').classList.add('hidden');
        document.getElementById('defaultUnitDiv').classList.remove('hidden');
        document.getElementById('defaultUnit').value = unit;
        document.getElementById('unitName').value = unit;
        document.getElementById('unitFactor').value = 1;
    }
}

function clearSelectedProduct() {
    document.getElementById('selectedProductId').value = '';
    document.getElementById('selectedProduct').classList.add('hidden');
    document.getElementById('productSearch').value = '';
    document.querySelector('[name="unit_cost"]').value = '';
    document.getElementById('unitSelectDiv').classList.add('hidden');
    document.getElementById('defaultUnitDiv').classList.remove('hidden');
    document.getElementById('productSearch').focus();
}

function onUnitChange(select) {
    const option = select.options[select.selectedIndex];
    if (option.value) {
        document.querySelector('[name="unit_cost"]').value = option.dataset.cost || 0;
        document.getElementById('unitName').value = option.dataset.name;
        document.getElementById('unitFactor').value = option.dataset.factor || 1;
    }
}

document.getElementById('addItemForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const productId = document.getElementById('selectedProductId').value;
    if (!productId) {
        alert('กรุณาเลือกสินค้า');
        return;
    }
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    data.po_id = poId;
    
    // If no unit_id selected but has default unit name
    if (!data.unit_id && document.getElementById('defaultUnit').value) {
        data.unit_name = document.getElementById('defaultUnit').value;
    }
    
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

async function updateItemCost(itemId, newCost) {
    const res = await fetch('../api/inventory.php?action=update_po_item_cost', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ item_id: itemId, unit_cost: parseFloat(newCost) })
    });
    const result = await res.json();
    
    if (result.success) {
        document.getElementById('subtotal_' + itemId).textContent = '฿' + result.subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        setTimeout(() => location.reload(), 500);
    } else {
        alert(result.message || 'Error updating cost');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
