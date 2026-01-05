<?php
/**
 * Put Away Tab - จัดเก็บสินค้าเข้าตำแหน่ง
 * Tab content for inventory/index.php
 * 
 * Features:
 * - Put away workflow UI
 * - Location suggestion display
 * - ABC classification badges
 * 
 * Requirements: 4.1, 4.2, 2.5
 */

require_once __DIR__ . '/../../classes/PutAwayService.php';
require_once __DIR__ . '/../../classes/LocationService.php';
require_once __DIR__ . '/../../classes/BatchService.php';

$putAwayService = new PutAwayService($db, $lineAccountId);
$locationService = new LocationService($db, $lineAccountId);
$batchService = new BatchService($db, $lineAccountId);

// Get ABC analysis summary
$abcSummary = $putAwayService->getABCAnalysisSummary();

// Get products for put-away
$products = [];
try {
    $stmt = $db->prepare("
        SELECT bi.id, bi.name, bi.sku, bi.barcode, bi.stock, bi.movement_class, 
               bi.storage_zone_type, bi.default_location_id, bi.drug_category,
               wl.location_code as current_location
        FROM business_items bi
        LEFT JOIN warehouse_locations wl ON bi.default_location_id = wl.id
        WHERE bi.is_active = 1 AND bi.line_account_id = ?
        ORDER BY bi.name
    ");
    $stmt->execute([$lineAccountId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get pending batches (batches without location)
$pendingBatches = [];
try {
    $stmt = $db->prepare("
        SELECT ib.*, bi.name as product_name, bi.sku, bi.movement_class
        FROM inventory_batches ib
        JOIN business_items bi ON ib.product_id = bi.id
        WHERE ib.location_id IS NULL 
          AND ib.status = 'active'
          AND ib.line_account_id = ?
        ORDER BY ib.received_at DESC
    ");
    $stmt->execute([$lineAccountId]);
    $pendingBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ABC class labels
$abcLabels = [
    'A' => ['label' => 'A - Fast Moving', 'color' => 'green', 'icon' => 'fa-bolt', 'desc' => 'สินค้าขายดี'],
    'B' => ['label' => 'B - Medium', 'color' => 'blue', 'icon' => 'fa-chart-line', 'desc' => 'สินค้าปานกลาง'],
    'C' => ['label' => 'C - Slow Moving', 'color' => 'gray', 'icon' => 'fa-clock', 'desc' => 'สินค้าขายช้า']
];

// Zone type labels
$zoneTypeLabels = [
    'general' => ['label' => 'ทั่วไป', 'color' => 'blue'],
    'cold_storage' => ['label' => 'ห้องเย็น', 'color' => 'cyan'],
    'controlled' => ['label' => 'ยาควบคุม', 'color' => 'red'],
    'hazardous' => ['label' => 'วัตถุอันตราย', 'color' => 'orange']
];
?>

<div class="space-y-6">
    <!-- ABC Analysis Summary -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm">Class A (Fast)</p>
                    <p class="text-3xl font-bold"><?= number_format($abcSummary['A'] ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-bolt text-2xl"></i>
                </div>
            </div>
            <p class="text-green-200 text-xs mt-2">Golden Zone แนะนำ</p>
        </div>
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm">Class B (Medium)</p>
                    <p class="text-3xl font-bold"><?= number_format($abcSummary['B'] ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-chart-line text-2xl"></i>
                </div>
            </div>
            <p class="text-blue-200 text-xs mt-2">ชั้นกลาง</p>
        </div>
        <div class="bg-gradient-to-r from-gray-500 to-gray-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-100 text-sm">Class C (Slow)</p>
                    <p class="text-3xl font-bold"><?= number_format($abcSummary['C'] ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-clock text-2xl"></i>
                </div>
            </div>
            <p class="text-gray-200 text-xs mt-2">ชั้นบน/ล่าง</p>
        </div>
        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm">รอจัดเก็บ</p>
                    <p class="text-3xl font-bold"><?= count($pendingBatches) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 rounded-full p-3">
                    <i class="fas fa-inbox text-2xl"></i>
                </div>
            </div>
            <p class="text-purple-200 text-xs mt-2">Batch ยังไม่มีตำแหน่ง</p>
        </div>
    </div>

    <!-- ABC Analysis Action -->
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-blue-100 rounded-full p-3">
                    <i class="fas fa-chart-pie text-blue-600 text-xl"></i>
                </div>
                <div>
                    <h3 class="font-semibold">ABC Analysis</h3>
                    <p class="text-sm text-gray-500">วิเคราะห์และจัดกลุ่มสินค้าตามความถี่การขาย</p>
                </div>
            </div>
            <button onclick="runABCAnalysis()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-sync-alt mr-1"></i>Run Analysis
            </button>
        </div>
    </div>


    <!-- Pending Batches for Put Away -->
    <?php if (!empty($pendingBatches)): ?>
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold">
                <i class="fas fa-inbox mr-2 text-purple-500"></i>
                Batch รอจัดเก็บ
            </h2>
            <span class="text-sm text-gray-500"><?= count($pendingBatches) ?> รายการ</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Batch</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ABC</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จำนวน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">วันหมดอายุ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">วันที่รับ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($pendingBatches as $batch): 
                        $abcInfo = $abcLabels[$batch['movement_class'] ?? 'C'] ?? $abcLabels['C'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <span class="font-mono font-bold text-blue-600"><?= htmlspecialchars($batch['batch_number']) ?></span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-sm"><?= htmlspecialchars($batch['product_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($batch['sku'] ?? '') ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $abcInfo['color'] ?>-100 text-<?= $abcInfo['color'] ?>-700 rounded text-xs font-bold">
                                <?= $batch['movement_class'] ?? 'C' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center font-medium"><?= number_format($batch['quantity_available']) ?></td>
                        <td class="px-4 py-3 text-center text-sm">
                            <?= $batch['expiry_date'] ? date('d/m/Y', strtotime($batch['expiry_date'])) : '-' ?>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-600">
                            <?= date('d/m/Y', strtotime($batch['received_at'])) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="suggestLocationForBatch(<?= $batch['id'] ?>)" 
                                    class="px-3 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                                <i class="fas fa-map-marker-alt mr-1"></i>จัดเก็บ
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Products with ABC Classification -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold">
                <i class="fas fa-boxes mr-2 text-blue-500"></i>
                สินค้าและตำแหน่งจัดเก็บ
            </h2>
            <div class="flex gap-2">
                <input type="text" id="productSearch" placeholder="ค้นหาสินค้า..." 
                       class="px-3 py-2 border rounded-lg text-sm" onkeyup="filterProducts()">
                <select id="abcFilter" class="px-3 py-2 border rounded-lg text-sm" onchange="filterProducts()">
                    <option value="">ทุก Class</option>
                    <option value="A">Class A</option>
                    <option value="B">Class B</option>
                    <option value="C">Class C</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full" id="productsTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ABC Class</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ประเภทโซน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ตำแหน่งปัจจุบัน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สต็อก</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($products)): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">ไม่พบข้อมูลสินค้า</td></tr>
                    <?php else: ?>
                    <?php foreach ($products as $product): 
                        $abcClass = $product['movement_class'] ?? 'C';
                        $abcInfo = $abcLabels[$abcClass] ?? $abcLabels['C'];
                        $zoneType = $product['storage_zone_type'] ?? 'general';
                        $zoneInfo = $zoneTypeLabels[$zoneType] ?? $zoneTypeLabels['general'];
                    ?>
                    <tr class="hover:bg-gray-50 product-row" 
                        data-name="<?= htmlspecialchars(strtolower($product['name'])) ?>"
                        data-sku="<?= htmlspecialchars(strtolower($product['sku'] ?? '')) ?>"
                        data-abc="<?= $abcClass ?>">
                        <td class="px-4 py-3">
                            <div class="font-medium"><?= htmlspecialchars($product['name']) ?></div>
                            <?php if ($product['drug_category']): ?>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($product['drug_category']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($product['sku'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $abcInfo['color'] ?>-100 text-<?= $abcInfo['color'] ?>-700 rounded text-xs font-bold" 
                                  title="<?= $abcInfo['desc'] ?>">
                                <i class="fas <?= $abcInfo['icon'] ?> mr-1"></i><?= $abcClass ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $zoneInfo['color'] ?>-100 text-<?= $zoneInfo['color'] ?>-700 rounded text-xs">
                                <?= $zoneInfo['label'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($product['current_location']): ?>
                            <span class="font-mono text-sm bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($product['current_location']) ?></span>
                            <?php else: ?>
                            <span class="text-gray-400 text-sm">ยังไม่กำหนด</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center font-medium"><?= number_format($product['stock'] ?? 0) ?></td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex justify-center gap-1">
                                <button onclick="suggestLocation(<?= $product['id'] ?>)" 
                                        class="p-2 text-green-600 hover:bg-green-50 rounded" title="แนะนำตำแหน่ง">
                                    <i class="fas fa-lightbulb"></i>
                                </button>
                                <button onclick="assignLocation(<?= $product['id'] ?>)" 
                                        class="p-2 text-blue-600 hover:bg-blue-50 rounded" title="กำหนดตำแหน่ง">
                                    <i class="fas fa-map-marker-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Location Suggestion Modal -->
<div id="suggestionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-lightbulb mr-2 text-yellow-500"></i>แนะนำตำแหน่งจัดเก็บ</h3>
            <button onclick="closeSuggestionModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="suggestionContent" class="p-4">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
                <p class="text-gray-500 mt-2">กำลังวิเคราะห์...</p>
            </div>
        </div>
    </div>
</div>

<!-- Assign Location Modal -->
<div id="assignModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-map-marker-alt mr-2 text-blue-500"></i>กำหนดตำแหน่งจัดเก็บ</h3>
            <button onclick="closeAssignModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="assignForm" class="p-4 space-y-4">
            <input type="hidden" name="product_id" id="assignProductId">
            <input type="hidden" name="batch_id" id="assignBatchId">
            <div id="assignProductInfo" class="bg-gray-50 rounded-lg p-3">
                <!-- Product info loaded via JS -->
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">เลือกตำแหน่ง <span class="text-red-500">*</span></label>
                <select name="location_id" id="assignLocationSelect" required class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- เลือกตำแหน่ง --</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">จำนวน</label>
                <input type="number" name="quantity" id="assignQuantity" min="1" value="1"
                       class="w-full px-3 py-2 border rounded-lg">
            </div>
            <div class="flex gap-2 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-check mr-1"></i>ยืนยัน
                </button>
                <button type="button" onclick="closeAssignModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    ยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ABC Analysis Result Modal -->
<div id="abcResultModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-chart-pie mr-2 text-blue-500"></i>ผลการวิเคราะห์ ABC</h3>
            <button onclick="closeABCResultModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="abcResultContent" class="p-4">
            <!-- Content loaded via JS -->
        </div>
    </div>
</div>


<script>
// Filter products
function filterProducts() {
    const search = document.getElementById('productSearch').value.toLowerCase();
    const abcFilter = document.getElementById('abcFilter').value;
    
    document.querySelectorAll('.product-row').forEach(row => {
        const name = row.dataset.name;
        const sku = row.dataset.sku;
        const abc = row.dataset.abc;
        
        const matchSearch = !search || name.includes(search) || sku.includes(search);
        const matchABC = !abcFilter || abc === abcFilter;
        
        row.style.display = (matchSearch && matchABC) ? '' : 'none';
    });
}

// Modal functions
function closeSuggestionModal() {
    document.getElementById('suggestionModal').classList.add('hidden');
}

function closeAssignModal() {
    document.getElementById('assignModal').classList.add('hidden');
}

function closeABCResultModal() {
    document.getElementById('abcResultModal').classList.add('hidden');
}

// Suggest location for product
async function suggestLocation(productId) {
    document.getElementById('suggestionModal').classList.remove('hidden');
    document.getElementById('suggestionContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="text-gray-500 mt-2">กำลังวิเคราะห์...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`api/put-away.php?action=suggest&product_id=${productId}`);
        const data = await response.json();
        
        if (data.success) {
            renderSuggestions(data, productId, null);
        } else {
            document.getElementById('suggestionContent').innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>${data.error || 'ไม่สามารถแนะนำตำแหน่งได้'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('suggestionContent').innerHTML = `
            <div class="text-center py-8 text-red-500">
                <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                <p>เกิดข้อผิดพลาด</p>
            </div>
        `;
    }
}

// Suggest location for batch
async function suggestLocationForBatch(batchId) {
    document.getElementById('suggestionModal').classList.remove('hidden');
    document.getElementById('suggestionContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="text-gray-500 mt-2">กำลังวิเคราะห์...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`api/put-away.php?action=suggest_batch&batch_id=${batchId}`);
        const data = await response.json();
        
        if (data.success) {
            renderSuggestions(data, data.batch?.product_id, batchId);
        } else {
            document.getElementById('suggestionContent').innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>${data.error || 'ไม่สามารถแนะนำตำแหน่งได้'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

// Render suggestions
function renderSuggestions(data, productId, batchId) {
    const abcColors = { A: 'green', B: 'blue', C: 'gray' };
    const abcClass = data.abc_class || 'C';
    
    let html = `
        <div class="space-y-4">
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-medium">${data.product?.name || 'สินค้า'}</div>
                        <div class="text-sm text-gray-500">SKU: ${data.product?.sku || '-'}</div>
                    </div>
                    <span class="px-3 py-1 bg-${abcColors[abcClass]}-100 text-${abcColors[abcClass]}-700 rounded-lg font-bold">
                        Class ${abcClass}
                    </span>
                </div>
            </div>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-sm">
                <i class="fas fa-info-circle text-blue-500 mr-1"></i>
                <strong>โซนที่ต้องการ:</strong> ${data.required_zone_type || 'general'}
                <br>
                <strong>ระดับ Ergonomic แนะนำ:</strong> ${data.preferred_ergonomic_level || 'golden'}
            </div>
    `;
    
    if (data.suggestions && data.suggestions.length > 0) {
        html += `<div class="space-y-2">`;
        data.suggestions.forEach((loc, index) => {
            const isTop = index === 0;
            html += `
                <div class="border ${isTop ? 'border-green-300 bg-green-50' : 'border-gray-200'} rounded-lg p-3 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        ${isTop ? '<span class="bg-green-500 text-white text-xs px-2 py-1 rounded">แนะนำ</span>' : ''}
                        <div>
                            <div class="font-mono font-bold">${loc.location_code}</div>
                            <div class="text-xs text-gray-500">
                                ${loc.reasons ? loc.reasons.join(', ') : ''}
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm">ว่าง ${loc.available_capacity} หน่วย</div>
                        <button onclick="selectLocation(${loc.id}, ${productId}, ${batchId || 'null'})" 
                                class="mt-1 px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                            เลือก
                        </button>
                    </div>
                </div>
            `;
        });
        html += `</div>`;
    } else {
        html += `
            <div class="text-center py-4 text-gray-500">
                <i class="fas fa-exclamation-circle text-2xl mb-2"></i>
                <p>ไม่พบตำแหน่งที่เหมาะสม</p>
                <p class="text-sm">กรุณาสร้างตำแหน่งใหม่ในโซน ${data.required_zone_type || 'general'}</p>
            </div>
        `;
    }
    
    html += `</div>`;
    document.getElementById('suggestionContent').innerHTML = html;
}

// Select location from suggestion
function selectLocation(locationId, productId, batchId) {
    closeSuggestionModal();
    
    document.getElementById('assignProductId').value = productId || '';
    document.getElementById('assignBatchId').value = batchId || '';
    document.getElementById('assignLocationSelect').value = locationId;
    
    // Load locations into select
    loadLocations(locationId);
    
    document.getElementById('assignModal').classList.remove('hidden');
}

// Assign location manually
async function assignLocation(productId) {
    document.getElementById('assignProductId').value = productId;
    document.getElementById('assignBatchId').value = '';
    
    // Load product info
    try {
        const response = await fetch(`api/put-away.php?action=suggest&product_id=${productId}`);
        const data = await response.json();
        
        if (data.product) {
            document.getElementById('assignProductInfo').innerHTML = `
                <div class="font-medium">${data.product.name}</div>
                <div class="text-sm text-gray-500">SKU: ${data.product.sku || '-'}</div>
            `;
        }
    } catch (error) {}
    
    // Load locations
    await loadLocations();
    
    document.getElementById('assignModal').classList.remove('hidden');
}

// Load locations into select
async function loadLocations(selectedId = null) {
    try {
        const response = await fetch('api/locations.php?action=list');
        const data = await response.json();
        
        if (data.success) {
            const select = document.getElementById('assignLocationSelect');
            select.innerHTML = '<option value="">-- เลือกตำแหน่ง --</option>';
            
            data.locations.forEach(loc => {
                const available = loc.capacity - loc.current_qty;
                const option = document.createElement('option');
                option.value = loc.id;
                option.textContent = `${loc.location_code} (${loc.zone_type}) - ว่าง ${available}`;
                if (selectedId && loc.id == selectedId) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading locations:', error);
    }
}

// Run ABC Analysis
async function runABCAnalysis() {
    if (!confirm('ต้องการ Run ABC Analysis ใหม่หรือไม่? ระบบจะวิเคราะห์ข้อมูลการขาย 90 วันย้อนหลัง')) return;
    
    try {
        const response = await fetch('api/put-away.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'run_abc_analysis' })
        });
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('abcResultContent').innerHTML = `
                <div class="space-y-4">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-green-700">
                        <i class="fas fa-check-circle mr-1"></i>
                        ${data.message}
                    </div>
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div class="bg-green-100 rounded-lg p-4">
                            <div class="text-2xl font-bold text-green-700">${data.summary?.A || 0}</div>
                            <div class="text-sm text-green-600">Class A</div>
                        </div>
                        <div class="bg-blue-100 rounded-lg p-4">
                            <div class="text-2xl font-bold text-blue-700">${data.summary?.B || 0}</div>
                            <div class="text-sm text-blue-600">Class B</div>
                        </div>
                        <div class="bg-gray-100 rounded-lg p-4">
                            <div class="text-2xl font-bold text-gray-700">${data.summary?.C || 0}</div>
                            <div class="text-sm text-gray-600">Class C</div>
                        </div>
                    </div>
                    <div class="text-center">
                        <button onclick="closeABCResultModal(); location.reload();" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            ปิดและรีเฟรช
                        </button>
                    </div>
                </div>
            `;
            document.getElementById('abcResultModal').classList.remove('hidden');
        } else {
            alert(data.error || 'ไม่สามารถ Run Analysis ได้');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาด');
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Assign form
    document.getElementById('assignForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const productId = formData.get('product_id');
        const batchId = formData.get('batch_id');
        const locationId = formData.get('location_id');
        const quantity = formData.get('quantity');
        
        const data = {
            action: batchId ? 'assign_batch' : 'assign_product',
            location_id: locationId,
            quantity: quantity
        };
        
        if (batchId) {
            data.batch_id = batchId;
        } else {
            data.product_id = productId;
        }
        
        try {
            const response = await fetch('api/put-away.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            
            if (result.success) {
                closeAssignModal();
                alert('จัดเก็บสำเร็จ');
                location.reload();
            } else {
                alert(result.error || 'ไม่สามารถจัดเก็บได้');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('เกิดข้อผิดพลาด');
        }
    });
});
</script>
