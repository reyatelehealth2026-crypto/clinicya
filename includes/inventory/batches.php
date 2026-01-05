<?php
/**
 * Inventory Batches Tab - จัดการ Batch/Lot
 * Tab content for inventory/index.php
 * 
 * Features:
 * - Batch list with expiry countdown
 * - Near-expiry alerts
 * - FIFO/FEFO display
 * 
 * Requirements: 8.3, 8.4, 9.4, 9.6
 */

require_once __DIR__ . '/../../classes/BatchService.php';

$batchService = new BatchService($db, $lineAccountId);

// Get filter parameters
$filterProduct = $_GET['product_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterExpiry = $_GET['expiry'] ?? '';

// Build filters
$filters = [];
if ($filterProduct) $filters['product_id'] = (int)$filterProduct;
if ($filterStatus) $filters['status'] = $filterStatus;
if ($filterExpiry === 'has_stock') $filters['has_stock'] = true;

// Get batches based on filter
$batches = [];
$expiringBatches = [];
$expiredBatches = [];

if ($filterExpiry === 'expiring') {
    $batches = $batchService->getExpiringBatches(90, $filters);
} elseif ($filterExpiry === 'expired') {
    $batches = $batchService->getExpiredBatches($filters);
} else {
    $batches = $batchService->getBatches($filters);
}

// Get summary counts
$expiringCount = count($batchService->getExpiringBatches(30));
$expiredCount = count($batchService->getExpiredBatches(['has_stock' => true]));
$totalBatches = count($batchService->getBatches(['status' => 'active']));
$totalStock = 0;
foreach ($batchService->getBatches(['status' => 'active']) as $b) {
    $totalStock += $b['quantity_available'];
}

// Get products for filter dropdown
$products = [];
try {
    $stmt = $db->prepare("SELECT id, name, sku FROM business_items WHERE is_active = 1 AND line_account_id = ? ORDER BY name");
    $stmt->execute([$lineAccountId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Status labels
$statusLabels = [
    'active' => ['label' => 'ใช้งาน', 'color' => 'green'],
    'quarantine' => ['label' => 'กักกัน', 'color' => 'yellow'],
    'expired' => ['label' => 'หมดอายุ', 'color' => 'red'],
    'disposed' => ['label' => 'ทำลายแล้ว', 'color' => 'gray']
];

/**
 * Get expiry badge color based on days until expiry
 */
function getExpiryBadgeColor($days) {
    if ($days === null) return 'gray';
    if ($days < 0) return 'red';
    if ($days <= 30) return 'red';
    if ($days <= 90) return 'yellow';
    return 'green';
}

/**
 * Format expiry countdown text
 */
function formatExpiryCountdown($days) {
    if ($days === null) return 'ไม่มีวันหมดอายุ';
    if ($days < 0) return 'หมดอายุแล้ว ' . abs($days) . ' วัน';
    if ($days === 0) return 'หมดอายุวันนี้!';
    if ($days <= 30) return 'อีก ' . $days . ' วัน';
    if ($days <= 90) return 'อีก ' . $days . ' วัน';
    return 'อีก ' . $days . ' วัน';
}
?>

<div class="space-y-6">
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-6 text-white">
            <p class="text-blue-100 text-sm">Batch ทั้งหมด</p>
            <p class="text-3xl font-bold"><?= number_format($totalBatches) ?></p>
        </div>
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-6 text-white">
            <p class="text-green-100 text-sm">สต็อกพร้อมจ่าย</p>
            <p class="text-3xl font-bold"><?= number_format($totalStock) ?></p>
        </div>
        <a href="?tab=batches&expiry=expiring" class="bg-gradient-to-r from-yellow-500 to-yellow-600 rounded-xl p-6 text-white hover:shadow-lg transition-shadow">
            <p class="text-yellow-100 text-sm">ใกล้หมดอายุ (30 วัน)</p>
            <p class="text-3xl font-bold"><?= number_format($expiringCount) ?></p>
            <?php if ($expiringCount > 0): ?>
            <p class="text-yellow-200 text-xs mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>ต้องดำเนินการ</p>
            <?php endif; ?>
        </a>
        <a href="?tab=batches&expiry=expired" class="bg-gradient-to-r from-red-500 to-red-600 rounded-xl p-6 text-white hover:shadow-lg transition-shadow">
            <p class="text-red-100 text-sm">หมดอายุแล้ว</p>
            <p class="text-3xl font-bold"><?= number_format($expiredCount) ?></p>
            <?php if ($expiredCount > 0): ?>
            <p class="text-red-200 text-xs mt-1"><i class="fas fa-ban mr-1"></i>รอทำลาย</p>
            <?php endif; ?>
        </a>
    </div>

    <!-- Near-Expiry Alert Banner -->
    <?php if ($expiringCount > 0 && $filterExpiry !== 'expiring'): ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="bg-yellow-100 rounded-full p-3">
                <i class="fas fa-clock text-yellow-600 text-xl"></i>
            </div>
            <div>
                <p class="font-semibold text-yellow-800">มี <?= $expiringCount ?> Batch ใกล้หมดอายุภายใน 30 วัน</p>
                <p class="text-sm text-yellow-600">กรุณาตรวจสอบและดำเนินการจ่ายก่อนหมดอายุ (FEFO)</p>
            </div>
        </div>
        <a href="?tab=batches&expiry=expiring" class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600">
            ดูรายการ
        </a>
    </div>
    <?php endif; ?>


    <!-- Filters -->
    <div class="bg-white rounded-xl shadow p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <input type="hidden" name="tab" value="batches">
            <div>
                <label class="block text-sm font-medium mb-1">สินค้า</label>
                <select name="product_id" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $filterProduct == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">สถานะ</label>
                <select name="status" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach ($statusLabels as $status => $info): ?>
                    <option value="<?= $status ?>" <?= $filterStatus === $status ? 'selected' : '' ?>>
                        <?= $info['label'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">วันหมดอายุ</label>
                <select name="expiry" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <option value="expiring" <?= $filterExpiry === 'expiring' ? 'selected' : '' ?>>ใกล้หมดอายุ (90 วัน)</option>
                    <option value="expired" <?= $filterExpiry === 'expired' ? 'selected' : '' ?>>หมดอายุแล้ว</option>
                    <option value="has_stock" <?= $filterExpiry === 'has_stock' ? 'selected' : '' ?>>มีสต็อกเท่านั้น</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-search mr-1"></i>ค้นหา
                </button>
                <?php if ($filterProduct || $filterStatus || $filterExpiry): ?>
                <a href="?tab=batches" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">ล้าง</a>
                <?php endif; ?>
            </div>
            <div class="flex items-end">
                <button type="button" onclick="openCreateBatchModal()" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-plus mr-1"></i>เพิ่ม Batch
                </button>
            </div>
        </form>
    </div>

    <!-- FIFO/FEFO Info -->
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-info-circle text-blue-500 text-xl mt-1"></i>
            <div>
                <p class="font-semibold text-blue-800">หลักการจ่ายสินค้า FIFO/FEFO</p>
                <p class="text-sm text-blue-600 mt-1">
                    <strong>FEFO (First Expired First Out):</strong> สินค้าที่มีวันหมดอายุ จะจ่ายตัวที่หมดอายุก่อน<br>
                    <strong>FIFO (First In First Out):</strong> สินค้าที่ไม่มีวันหมดอายุ จะจ่ายตัวที่รับเข้ามาก่อน
                </p>
            </div>
        </div>
    </div>

    <!-- Batches Table -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold">
                <i class="fas fa-layer-group mr-2 text-blue-500"></i>
                รายการ Batch/Lot
                <?php if ($filterExpiry === 'expiring'): ?>
                <span class="text-yellow-600">(ใกล้หมดอายุ)</span>
                <?php elseif ($filterExpiry === 'expired'): ?>
                <span class="text-red-600">(หมดอายุแล้ว)</span>
                <?php endif; ?>
            </h2>
            <span class="text-sm text-gray-500"><?= count($batches) ?> รายการ</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Batch/Lot</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ตำแหน่ง</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จำนวน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">พร้อมจ่าย</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">วันหมดอายุ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">นับถอยหลัง</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">วันที่รับ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สถานะ</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($batches)): ?>
                    <tr><td colspan="10" class="px-4 py-8 text-center text-gray-500">ไม่พบข้อมูล Batch</td></tr>
                    <?php else: ?>
                    <?php foreach ($batches as $batch): 
                        $statusInfo = $statusLabels[$batch['status']] ?? $statusLabels['active'];
                        $expiryColor = getExpiryBadgeColor($batch['days_until_expiry']);
                        $expiryText = formatExpiryCountdown($batch['days_until_expiry']);
                        
                        // Get product name
                        $productName = '';
                        try {
                            $stmt = $db->prepare("SELECT name FROM business_items WHERE id = ?");
                            $stmt->execute([$batch['product_id']]);
                            $productName = $stmt->fetchColumn() ?: 'Unknown';
                        } catch (Exception $e) {}
                    ?>
                    <tr class="hover:bg-gray-50 <?= $batch['is_expired'] ? 'bg-red-50' : ($batch['is_near_expiry'] ? 'bg-yellow-50' : '') ?>">
                        <td class="px-4 py-3">
                            <div class="font-mono font-bold text-blue-600"><?= htmlspecialchars($batch['batch_number']) ?></div>
                            <?php if ($batch['lot_number']): ?>
                            <div class="text-xs text-gray-500">Lot: <?= htmlspecialchars($batch['lot_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-sm"><?= htmlspecialchars($productName) ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($batch['location_code']): ?>
                            <span class="font-mono text-sm bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($batch['location_code']) ?></span>
                            <?php else: ?>
                            <span class="text-gray-400 text-sm">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center"><?= number_format($batch['quantity']) ?></td>
                        <td class="px-4 py-3 text-center font-bold <?= $batch['quantity_available'] > 0 ? 'text-green-600' : 'text-gray-400' ?>">
                            <?= number_format($batch['quantity_available']) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($batch['expiry_date']): ?>
                            <span class="text-sm"><?= date('d/m/Y', strtotime($batch['expiry_date'])) ?></span>
                            <?php else: ?>
                            <span class="text-gray-400 text-sm">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($batch['days_until_expiry'] !== null): ?>
                            <span class="px-2 py-1 bg-<?= $expiryColor ?>-100 text-<?= $expiryColor ?>-700 rounded text-xs font-medium">
                                <?php if ($batch['is_expired']): ?>
                                <i class="fas fa-ban mr-1"></i>
                                <?php elseif ($batch['is_near_expiry']): ?>
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <?php endif; ?>
                                <?= $expiryText ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400 text-xs">ไม่มี</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-600">
                            <?= date('d/m/Y', strtotime($batch['received_at'])) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $statusInfo['color'] ?>-100 text-<?= $statusInfo['color'] ?>-700 rounded text-xs">
                                <?= $statusInfo['label'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <div class="flex justify-center gap-1">
                                <button onclick="viewBatch(<?= $batch['id'] ?>)" class="p-2 text-blue-600 hover:bg-blue-50 rounded" title="ดูรายละเอียด">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($batch['status'] === 'active'): ?>
                                <button onclick="editBatch(<?= $batch['id'] ?>)" class="p-2 text-gray-600 hover:bg-gray-50 rounded" title="แก้ไข">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($batch['is_expired'] || $batch['status'] === 'expired'): ?>
                                <button onclick="disposeBatch(<?= $batch['id'] ?>)" class="p-2 text-red-600 hover:bg-red-50 rounded" title="ทำลาย">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                                <?php endif; ?>
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


<!-- Create Batch Modal -->
<div id="createBatchModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-plus-circle mr-2 text-green-500"></i>เพิ่ม Batch ใหม่</h3>
            <button onclick="closeCreateBatchModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="createBatchForm" class="p-4 space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">สินค้า <span class="text-red-500">*</span></label>
                <select name="product_id" required class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- เลือกสินค้า --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Batch Number <span class="text-red-500">*</span></label>
                    <input type="text" name="batch_number" required placeholder="เช่น B2024001"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Lot Number</label>
                    <input type="text" name="lot_number" placeholder="เช่น L001"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">จำนวน <span class="text-red-500">*</span></label>
                    <input type="number" name="quantity" required min="1" placeholder="จำนวนที่รับเข้า"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ราคาทุน/หน่วย</label>
                    <input type="number" name="cost_price" step="0.01" min="0" placeholder="0.00"
                           class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">วันผลิต</label>
                    <input type="date" name="manufacture_date" class="w-full px-3 py-2 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">วันหมดอายุ</label>
                    <input type="date" name="expiry_date" class="w-full px-3 py-2 border rounded-lg">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">หมายเหตุ</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border rounded-lg" 
                          placeholder="รายละเอียดเพิ่มเติม..."></textarea>
            </div>
            <div class="flex gap-2 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-save mr-1"></i>บันทึก
                </button>
                <button type="button" onclick="closeCreateBatchModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    ยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Batch Modal -->
<div id="viewBatchModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg"><i class="fas fa-layer-group mr-2 text-blue-500"></i>รายละเอียด Batch</h3>
            <button onclick="closeViewBatchModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="viewBatchContent" class="p-4">
            <!-- Content loaded via JS -->
        </div>
    </div>
</div>

<!-- Dispose Batch Modal -->
<div id="disposeBatchModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-lg text-red-600"><i class="fas fa-trash-alt mr-2"></i>ทำลาย Batch</h3>
            <button onclick="closeDisposeBatchModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="disposeBatchForm" class="p-4 space-y-4">
            <input type="hidden" name="batch_id" id="disposeBatchId">
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                การทำลาย Batch จะไม่สามารถย้อนกลับได้ กรุณาระบุเหตุผลการทำลาย
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Batch Number</label>
                <div id="disposeBatchNumber" class="font-mono font-bold text-lg">-</div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">เหตุผลการทำลาย <span class="text-red-500">*</span></label>
                <select name="reason_type" class="w-full px-3 py-2 border rounded-lg mb-2">
                    <option value="expired">หมดอายุ</option>
                    <option value="damaged">เสียหาย</option>
                    <option value="recalled">เรียกคืน</option>
                    <option value="other">อื่นๆ</option>
                </select>
                <textarea name="reason" required rows="2" class="w-full px-3 py-2 border rounded-lg" 
                          placeholder="รายละเอียดเพิ่มเติม..."></textarea>
            </div>
            <div class="flex gap-2 pt-4">
                <button type="submit" class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                    <i class="fas fa-trash-alt mr-1"></i>ยืนยันทำลาย
                </button>
                <button type="button" onclick="closeDisposeBatchModal()" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                    ยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>


<script>
// Modal functions
function openCreateBatchModal() {
    document.getElementById('createBatchModal').classList.remove('hidden');
    document.getElementById('createBatchForm').reset();
}

function closeCreateBatchModal() {
    document.getElementById('createBatchModal').classList.add('hidden');
}

function closeViewBatchModal() {
    document.getElementById('viewBatchModal').classList.add('hidden');
}

function closeDisposeBatchModal() {
    document.getElementById('disposeBatchModal').classList.add('hidden');
}

// View batch details
async function viewBatch(id) {
    try {
        const response = await fetch(`api/batches.php?action=get&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            const batch = data.batch;
            const expiryStatus = batch.is_expired ? 'หมดอายุแล้ว' : 
                                 (batch.is_near_expiry ? 'ใกล้หมดอายุ' : 'ปกติ');
            const expiryColor = batch.is_expired ? 'red' : (batch.is_near_expiry ? 'yellow' : 'green');
            
            document.getElementById('viewBatchContent').innerHTML = `
                <div class="space-y-4">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="text-sm text-gray-500">Batch Number</div>
                        <div class="font-mono font-bold text-xl text-blue-600">${batch.batch_number}</div>
                        ${batch.lot_number ? `<div class="text-sm text-gray-500">Lot: ${batch.lot_number}</div>` : ''}
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-500">จำนวนรับเข้า</div>
                            <div class="font-bold">${batch.quantity}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">พร้อมจ่าย</div>
                            <div class="font-bold text-green-600">${batch.quantity_available}</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-500">วันที่รับ</div>
                            <div>${new Date(batch.received_at).toLocaleDateString('th-TH')}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">วันหมดอายุ</div>
                            <div>${batch.expiry_date ? new Date(batch.expiry_date).toLocaleDateString('th-TH') : '-'}</div>
                        </div>
                    </div>
                    ${batch.expiry_date ? `
                    <div class="bg-${expiryColor}-50 border border-${expiryColor}-200 rounded-lg p-3">
                        <div class="text-sm text-${expiryColor}-700">
                            <i class="fas fa-clock mr-1"></i>
                            ${expiryStatus} ${batch.days_until_expiry !== null ? `(${Math.abs(batch.days_until_expiry)} วัน)` : ''}
                        </div>
                    </div>
                    ` : ''}
                    <div>
                        <div class="text-sm text-gray-500">ตำแหน่งจัดเก็บ</div>
                        <div class="font-mono">${batch.location_code || '-'}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">สถานะ</div>
                        <div class="capitalize">${batch.status}</div>
                    </div>
                </div>
            `;
            document.getElementById('viewBatchModal').classList.remove('hidden');
        } else {
            alert('ไม่พบข้อมูล Batch');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

// Edit batch
async function editBatch(id) {
    // For now, just view - can extend to edit modal
    viewBatch(id);
}

// Dispose batch
async function disposeBatch(id) {
    try {
        const response = await fetch(`api/batches.php?action=get&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('disposeBatchId').value = id;
            document.getElementById('disposeBatchNumber').textContent = data.batch.batch_number;
            document.getElementById('disposeBatchModal').classList.remove('hidden');
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Create batch form
    document.getElementById('createBatchForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const data = Object.fromEntries(formData);
        data.action = 'create';
        
        try {
            const response = await fetch('api/batches.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            
            if (result.success) {
                closeCreateBatchModal();
                location.reload();
            } else {
                alert(result.error || 'ไม่สามารถสร้าง Batch ได้');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('เกิดข้อผิดพลาด');
        }
    });
    
    // Dispose batch form
    document.getElementById('disposeBatchForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if (!confirm('ยืนยันการทำลาย Batch นี้?')) return;
        
        const formData = new FormData(this);
        const data = {
            action: 'dispose',
            id: formData.get('batch_id'),
            reason: formData.get('reason_type') + ': ' + formData.get('reason'),
            pharmacist_id: 1 // TODO: Get from session
        };
        
        try {
            const response = await fetch('api/batches.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            
            if (result.success) {
                closeDisposeBatchModal();
                location.reload();
            } else {
                alert(result.error || 'ไม่สามารถทำลาย Batch ได้');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('เกิดข้อผิดพลาด');
        }
    });
});
</script>
