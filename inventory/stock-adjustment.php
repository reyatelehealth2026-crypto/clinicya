<?php
/**
 * Stock Adjustment - ปรับสต็อก
 * 
 * DEPRECATED: This file has been consolidated into inventory/index.php
 * Redirects to: inventory/index.php?tab=adjustment
 */
require_once __DIR__ . '/../includes/redirects.php';
handleRedirect();

// Fallback if redirect doesn't work
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/InventoryService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;
$pageTitle = 'ปรับสต็อก (Stock Adjustment)';

$inventoryService = new InventoryService($db, $lineAccountId);

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM stock_adjustments LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

// Get products with barcode
$products = [];
try {
    $stmt = $db->prepare("SELECT id, name, sku, barcode, stock FROM business_items WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get adjustments
$adjustments = $tableExists ? $inventoryService->getAdjustments(['limit' => 50]) : [];

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

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Create Adjustment -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold"><i class="fas fa-sliders-h mr-2 text-orange-500"></i>สร้างรายการปรับสต็อก</h2>
        </div>
        <form id="adjustmentForm" class="p-4 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">สินค้า *</label>
                <div class="relative">
                    <input type="text" id="productSearch" placeholder="🔍 ค้นหาด้วย ชื่อ, รหัส SKU, หรือ Barcode..." 
                           class="w-full px-3 py-2 border rounded-lg pr-10" autocomplete="off">
                    <button type="button" onclick="openBarcodeScanner()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-barcode"></i>
                    </button>
                </div>
                <select name="product_id" id="productSelect" required class="w-full px-3 py-2 border rounded-lg mt-2" onchange="updateStock(this)">
                    <option value="">-- เลือกสินค้า --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" 
                            data-stock="<?= $p['stock'] ?>" 
                            data-sku="<?= htmlspecialchars($p['sku'] ?? '') ?>"
                            data-barcode="<?= htmlspecialchars($p['barcode'] ?? '') ?>"
                            data-name="<?= htmlspecialchars($p['name']) ?>">
                        <?= htmlspecialchars($p['name']) ?> <?= $p['sku'] ? "({$p['sku']})" : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div id="searchResults" class="hidden mt-1 border rounded-lg bg-white shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
            
            <div class="p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">สต็อกในระบบ</p>
                        <p class="text-3xl font-bold text-blue-600" id="currentStock">-</p>
                    </div>
                    <div id="productInfo" class="text-right hidden">
                        <p class="text-xs text-gray-500">SKU: <span id="productSku">-</span></p>
                        <p class="text-xs text-gray-500">Barcode: <span id="productBarcode">-</span></p>
                    </div>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">จำนวนที่นับได้จริง *</label>
                <input type="number" name="actual_count" id="actualCount" min="0" required 
                       class="w-full px-4 py-3 border-2 border-blue-300 rounded-lg text-xl font-bold text-center focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                       placeholder="กรอกจำนวนที่นับได้"
                       oninput="calculateDifference()">
            </div>
            
            <!-- Auto-calculated difference -->
            <div id="differenceBox" class="hidden p-4 rounded-lg border-2">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">ผลต่าง (ระบบคำนวณอัตโนมัติ)</p>
                        <p class="text-2xl font-bold" id="differenceText">-</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500">ประเภท</p>
                        <p class="text-lg font-semibold" id="adjustmentTypeText">-</p>
                    </div>
                </div>
            </div>
            
            <!-- Hidden fields for API -->
            <input type="hidden" name="adjustment_type" id="adjustmentType" value="increase">
            <input type="hidden" name="quantity" id="adjustmentQuantity" value="0">
            
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
// Product data for search
const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;

// Search functionality
const searchInput = document.getElementById('productSearch');
const searchResults = document.getElementById('searchResults');
const productSelect = document.getElementById('productSelect');

searchInput.addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();
    if (query.length < 1) {
        searchResults.classList.add('hidden');
        return;
    }
    
    const matches = products.filter(p => {
        const name = (p.name || '').toLowerCase();
        const sku = (p.sku || '').toLowerCase();
        const barcode = (p.barcode || '').toLowerCase();
        return name.includes(query) || sku.includes(query) || barcode.includes(query) || barcode === query;
    }).slice(0, 10);
    
    if (matches.length === 0) {
        searchResults.innerHTML = '<div class="p-3 text-gray-500 text-sm">ไม่พบสินค้า</div>';
    } else {
        searchResults.innerHTML = matches.map(p => `
            <div class="p-3 hover:bg-gray-50 cursor-pointer border-b last:border-0" onclick="selectProduct(${p.id})">
                <div class="font-medium">${p.name}</div>
                <div class="text-xs text-gray-500">
                    ${p.sku ? 'SKU: ' + p.sku : ''} 
                    ${p.barcode ? '| Barcode: ' + p.barcode : ''} 
                    | <span class="text-blue-600 font-semibold">Stock: ${p.stock}</span>
                </div>
            </div>
        `).join('');
    }
    searchResults.classList.remove('hidden');
});

searchInput.addEventListener('blur', function() {
    setTimeout(() => searchResults.classList.add('hidden'), 200);
});

searchInput.addEventListener('focus', function() {
    if (this.value.length >= 1) {
        this.dispatchEvent(new Event('input'));
    }
});

// Handle Enter key for barcode scanner
searchInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const query = this.value.trim();
        // Find exact barcode match
        const match = products.find(p => p.barcode === query || p.sku === query);
        if (match) {
            selectProduct(match.id);
            this.value = '';
        }
    }
});

function selectProduct(id) {
    productSelect.value = id;
    updateStock(productSelect);
    searchResults.classList.add('hidden');
    searchInput.value = '';
}

function updateStock(select) {
    const option = select.options[select.selectedIndex];
    const stock = option.dataset.stock || '-';
    const sku = option.dataset.sku || '-';
    const barcode = option.dataset.barcode || '-';
    
    document.getElementById('currentStock').textContent = stock;
    document.getElementById('productSku').textContent = sku || '-';
    document.getElementById('productBarcode').textContent = barcode || '-';
    
    if (select.value) {
        document.getElementById('productInfo').classList.remove('hidden');
        // Clear and recalculate
        document.getElementById('actualCount').value = '';
        calculateDifference();
    } else {
        document.getElementById('productInfo').classList.add('hidden');
        document.getElementById('differenceBox').classList.add('hidden');
    }
}

function calculateDifference() {
    const productSelect = document.getElementById('productSelect');
    const actualCountInput = document.getElementById('actualCount');
    const differenceBox = document.getElementById('differenceBox');
    const differenceText = document.getElementById('differenceText');
    const adjustmentTypeText = document.getElementById('adjustmentTypeText');
    const adjustmentType = document.getElementById('adjustmentType');
    const adjustmentQuantity = document.getElementById('adjustmentQuantity');
    
    const option = productSelect.options[productSelect.selectedIndex];
    const currentStock = parseInt(option.dataset.stock) || 0;
    const actualCount = parseInt(actualCountInput.value) || 0;
    
    if (!productSelect.value || actualCountInput.value === '') {
        differenceBox.classList.add('hidden');
        return;
    }
    
    const difference = actualCount - currentStock;
    
    differenceBox.classList.remove('hidden');
    
    if (difference > 0) {
        // เพิ่ม
        differenceBox.className = 'p-4 rounded-lg border-2 bg-green-50 border-green-300';
        differenceText.textContent = '+' + difference;
        differenceText.className = 'text-2xl font-bold text-green-600';
        adjustmentTypeText.textContent = 'เพิ่มสต็อก';
        adjustmentTypeText.className = 'text-lg font-semibold text-green-600';
        adjustmentType.value = 'increase';
        adjustmentQuantity.value = difference;
    } else if (difference < 0) {
        // ลด
        differenceBox.className = 'p-4 rounded-lg border-2 bg-red-50 border-red-300';
        differenceText.textContent = difference;
        differenceText.className = 'text-2xl font-bold text-red-600';
        adjustmentTypeText.textContent = 'ลดสต็อก';
        adjustmentTypeText.className = 'text-lg font-semibold text-red-600';
        adjustmentType.value = 'decrease';
        adjustmentQuantity.value = Math.abs(difference);
    } else {
        // เท่ากัน
        differenceBox.className = 'p-4 rounded-lg border-2 bg-gray-50 border-gray-300';
        differenceText.textContent = '0 (ไม่มีการเปลี่ยนแปลง)';
        differenceText.className = 'text-2xl font-bold text-gray-500';
        adjustmentTypeText.textContent = 'ตรงกัน';
        adjustmentTypeText.className = 'text-lg font-semibold text-gray-500';
        adjustmentType.value = 'increase';
        adjustmentQuantity.value = 0;
    }
}

function openBarcodeScanner() {
    searchInput.focus();
    searchInput.placeholder = '📷 สแกน Barcode หรือพิมพ์รหัส...';
}

document.getElementById('adjustmentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const quantity = parseInt(document.getElementById('adjustmentQuantity').value) || 0;
    if (quantity === 0) {
        alert('ไม่มีการเปลี่ยนแปลงสต็อก (จำนวนที่นับได้ตรงกับสต็อกในระบบ)');
        return;
    }
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    // Use calculated values
    data.adjustment_type = document.getElementById('adjustmentType').value;
    data.quantity = quantity;
    
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
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
