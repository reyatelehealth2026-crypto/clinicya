<?php
/**
 * Account Payable (AP) Tab Content
 * เจ้าหนี้การค้า - แสดงรายการเจ้าหนี้พร้อมตัวกรองและบันทึกการชำระเงิน
 * 
 * Requirements: 1.2, 1.3, 5.1, 5.4
 * - Display AP list with filters (status, supplier, date range)
 * - Show aging indicators
 * - Add payment recording modal
 * 
 * @package AccountingManagement
 * @version 1.0.0
 */

require_once __DIR__ . '/../../classes/AccountPayableService.php';
require_once __DIR__ . '/../../classes/SupplierService.php';
require_once __DIR__ . '/../../classes/AgingHelper.php';

// Initialize services
$apService = new AccountPayableService($db, $currentBotId);
$supplierService = new SupplierService($db, $currentBotId);

// Get filter values from request
$filterStatus = $_GET['status'] ?? '';
$filterSupplierId = $_GET['supplier_id'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterSearch = $_GET['search'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'due_date';
$sortOrder = $_GET['sort_order'] ?? 'ASC';

// Build filters array
$filters = [];
if ($filterStatus) {
    if ($filterStatus === 'overdue') {
        // Special handling for overdue - get open/partial that are past due
        $filters['status'] = ['open', 'partial'];
    } else {
        $filters['status'] = $filterStatus;
    }
}
if ($filterSupplierId) {
    $filters['supplier_id'] = $filterSupplierId;
}
if ($filterDateFrom) {
    $filters['date_from'] = $filterDateFrom;
}
if ($filterDateTo) {
    $filters['date_to'] = $filterDateTo;
}
if ($filterSearch) {
    $filters['search'] = $filterSearch;
}
$filters['sort_by'] = $sortBy;
$filters['sort_order'] = $sortOrder;

// Get AP records
$apRecords = $apService->getAll($filters);

// If filtering by overdue, filter results to only show overdue
if ($filterStatus === 'overdue') {
    $apRecords = array_filter($apRecords, function($record) {
        return $record['is_overdue'] ?? false;
    });
    $apRecords = array_values($apRecords); // Re-index array
}

// Get suppliers for filter dropdown
$suppliers = $supplierService->getAll(['is_active' => 1]);

// Get summary stats
$totalOutstanding = $apService->getTotalOutstanding();
$totalOverdue = $apService->getTotalOverdue();
$agingReport = $apService->getAgingReport();

// Status labels and colors
$statusLabels = [
    'open' => ['label' => 'รอชำระ', 'color' => 'bg-blue-100 text-blue-700'],
    'partial' => ['label' => 'ชำระบางส่วน', 'color' => 'bg-yellow-100 text-yellow-700'],
    'paid' => ['label' => 'ชำระแล้ว', 'color' => 'bg-green-100 text-green-700'],
    'cancelled' => ['label' => 'ยกเลิก', 'color' => 'bg-gray-100 text-gray-500'],
];

// Payment methods
$paymentMethods = [
    'cash' => 'เงินสด',
    'transfer' => 'โอนเงิน',
    'cheque' => 'เช็ค',
    'credit_card' => 'บัตรเครดิต',
];

// Format money helper
function formatMoneyAP($amount) {
    return number_format((float)$amount, 2);
}

// Get aging bracket color
function getAgingColor($daysOverdue) {
    if ($daysOverdue <= 0) return 'text-green-600';
    if ($daysOverdue <= 30) return 'text-yellow-600';
    if ($daysOverdue <= 60) return 'text-orange-600';
    return 'text-red-600';
}

// Get aging badge
function getAgingBadge($daysOverdue) {
    if ($daysOverdue <= 0) {
        $days = abs($daysOverdue);
        return "<span class='text-xs text-green-600'>อีก {$days} วัน</span>";
    }
    $bracket = AgingHelper::getAgingBracket($daysOverdue);
    $colors = [
        '1-30' => 'bg-yellow-100 text-yellow-700',
        '31-60' => 'bg-orange-100 text-orange-700',
        '61-90' => 'bg-red-100 text-red-700',
        '90+' => 'bg-red-200 text-red-800',
    ];
    $color = $colors[$bracket] ?? 'bg-gray-100 text-gray-700';
    return "<span class='px-2 py-0.5 text-xs rounded-full {$color}'>เกิน {$daysOverdue} วัน</span>";
}
?>

<style>
.ap-table th { white-space: nowrap; }
.ap-row:hover { background-color: #f8fafc; }
.filter-section { transition: all 0.3s; }
.aging-summary-card { transition: all 0.2s; cursor: pointer; }
.aging-summary-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.aging-summary-card.active { ring: 2px; ring-color: #3b82f6; }
</style>

<!-- Header with Summary Stats -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
    <div>
        <p class="text-gray-500">จัดการเจ้าหนี้การค้าและบันทึกการชำระเงิน</p>
    </div>
    <div class="flex items-center gap-4">
        <div class="text-right">
            <p class="text-sm text-gray-500">ยอดค้างชำระทั้งหมด</p>
            <p class="text-xl font-bold text-red-600">฿<?= formatMoneyAP($totalOutstanding) ?></p>
        </div>
        <?php if ($totalOverdue > 0): ?>
        <div class="text-right pl-4 border-l">
            <p class="text-sm text-gray-500">เกินกำหนด</p>
            <p class="text-xl font-bold text-orange-600">฿<?= formatMoneyAP($totalOverdue) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Aging Summary Cards - Requirement 5.1 -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
    <?php 
    $agingColors = [
        'current' => ['bg' => 'bg-green-50', 'text' => 'text-green-700', 'border' => 'border-green-200'],
        '1-30' => ['bg' => 'bg-yellow-50', 'text' => 'text-yellow-700', 'border' => 'border-yellow-200'],
        '31-60' => ['bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'border' => 'border-orange-200'],
        '61-90' => ['bg' => 'bg-red-50', 'text' => 'text-red-700', 'border' => 'border-red-200'],
        '90+' => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'border' => 'border-red-300'],
    ];
    foreach (AgingHelper::BRACKET_ORDER as $bracket): 
        $bracketData = $agingReport['brackets'][$bracket] ?? ['total' => 0, 'count' => 0];
        $colors = $agingColors[$bracket];
        $label = AgingHelper::getBracketLabel($bracket, 'th');
    ?>
    <div class="aging-summary-card <?= $colors['bg'] ?> border <?= $colors['border'] ?> rounded-lg p-3" 
         onclick="filterByAging('<?= $bracket ?>')">
        <p class="text-xs <?= $colors['text'] ?> opacity-75"><?= $label ?></p>
        <p class="text-lg font-bold <?= $colors['text'] ?>">฿<?= formatMoneyAP($bracketData['total']) ?></p>
        <p class="text-xs <?= $colors['text'] ?> opacity-60"><?= $bracketData['count'] ?> รายการ</p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters Section -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b flex items-center justify-between">
        <h3 class="font-semibold text-gray-800">
            <i class="fas fa-filter text-gray-400 mr-2"></i>ตัวกรอง
        </h3>
        <button type="button" onclick="toggleFilters()" class="text-sm text-blue-600 hover:text-blue-800">
            <i class="fas fa-chevron-down" id="filterToggleIcon"></i>
        </button>
    </div>
    
    <div class="filter-section p-4" id="filterSection">
        <form method="GET" action="accounting.php" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <input type="hidden" name="tab" value="ap">
            
            <!-- Status Filter -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">สถานะ</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">ทั้งหมด</option>
                    <option value="open" <?= $filterStatus === 'open' ? 'selected' : '' ?>>รอชำระ</option>
                    <option value="partial" <?= $filterStatus === 'partial' ? 'selected' : '' ?>>ชำระบางส่วน</option>
                    <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>ชำระแล้ว</option>
                    <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>เกินกำหนด</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
                </select>
            </div>
            
            <!-- Supplier Filter -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">Supplier</label>
                <select name="supplier_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?= $supplier['id'] ?>" <?= $filterSupplierId == $supplier['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($supplier['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Date From -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">วันครบกำหนด (จาก)</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <!-- Date To -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">วันครบกำหนด (ถึง)</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <!-- Search -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">ค้นหา</label>
                <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" 
                       placeholder="เลขที่ AP, Invoice, Supplier..."
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <!-- Filter Actions -->
            <div class="lg:col-span-5 flex items-center gap-3 pt-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-search mr-2"></i>ค้นหา
                </button>
                <a href="accounting.php?tab=ap" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                    <i class="fas fa-times mr-2"></i>ล้างตัวกรอง
                </a>
            </div>
        </form>
    </div>
</div>

<!-- AP List Table -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="p-4 border-b flex items-center justify-between">
        <h3 class="font-semibold text-gray-800">
            <i class="fas fa-file-invoice-dollar text-red-500 mr-2"></i>รายการเจ้าหนี้
            <span class="text-sm font-normal text-gray-500 ml-2">(<?= count($apRecords) ?> รายการ)</span>
        </h3>
        <div class="flex items-center gap-2">
            <!-- Sort Options -->
            <select onchange="changeSorting(this.value)" class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg">
                <option value="due_date-ASC" <?= ($sortBy === 'due_date' && $sortOrder === 'ASC') ? 'selected' : '' ?>>วันครบกำหนด (เร็ว→ช้า)</option>
                <option value="due_date-DESC" <?= ($sortBy === 'due_date' && $sortOrder === 'DESC') ? 'selected' : '' ?>>วันครบกำหนด (ช้า→เร็ว)</option>
                <option value="balance-DESC" <?= ($sortBy === 'balance' && $sortOrder === 'DESC') ? 'selected' : '' ?>>ยอดค้าง (มาก→น้อย)</option>
                <option value="balance-ASC" <?= ($sortBy === 'balance' && $sortOrder === 'ASC') ? 'selected' : '' ?>>ยอดค้าง (น้อย→มาก)</option>
                <option value="created_at-DESC" <?= ($sortBy === 'created_at' && $sortOrder === 'DESC') ? 'selected' : '' ?>>วันที่สร้าง (ใหม่→เก่า)</option>
            </select>
        </div>
    </div>
    
    <?php if (empty($apRecords)): ?>
    <div class="p-12 text-center text-gray-400">
        <i class="fas fa-inbox text-5xl mb-4"></i>
        <p class="text-lg">ไม่พบรายการเจ้าหนี้</p>
        <p class="text-sm mt-2">ลองปรับตัวกรองหรือสร้างรายการใหม่</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full ap-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เลขที่ AP</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">ยอดรวม</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">ชำระแล้ว</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">คงเหลือ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">ครบกำหนด</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($apRecords as $ap): 
                    $statusInfo = $statusLabels[$ap['status']] ?? ['label' => $ap['status'], 'color' => 'bg-gray-100 text-gray-700'];
                    $daysUntilDue = $ap['days_until_due'] ?? 0;
                    $isOverdue = $ap['is_overdue'] ?? false;
                ?>
                <tr class="ap-row <?= $isOverdue ? 'bg-red-50' : '' ?>">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?= htmlspecialchars($ap['ap_number']) ?></div>
                        <?php if (!empty($ap['po_number'])): ?>
                        <div class="text-xs text-gray-500">PO: <?= htmlspecialchars($ap['po_number'] ?? ($ap['metadata']['po_number'] ?? '-')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800"><?= htmlspecialchars($ap['supplier_name'] ?? '-') ?></div>
                        <?php if (!empty($ap['supplier_code'])): ?>
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($ap['supplier_code']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-gray-700"><?= htmlspecialchars($ap['invoice_number'] ?? '-') ?></div>
                        <?php if (!empty($ap['invoice_date'])): ?>
                        <div class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($ap['invoice_date'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-gray-900">
                        ฿<?= formatMoneyAP($ap['total_amount']) ?>
                    </td>
                    <td class="px-4 py-3 text-right text-green-600">
                        ฿<?= formatMoneyAP($ap['paid_amount']) ?>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold <?= $isOverdue ? 'text-red-600' : 'text-gray-900' ?>">
                        ฿<?= formatMoneyAP($ap['balance']) ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="<?= getAgingColor($daysUntilDue * -1) ?>">
                            <?= date('d/m/Y', strtotime($ap['due_date'])) ?>
                        </div>
                        <?php if ($isOverdue): ?>
                            <?= getAgingBadge(abs($daysUntilDue)) ?>
                        <?php elseif ($daysUntilDue >= 0 && $daysUntilDue <= 7 && in_array($ap['status'], ['open', 'partial'])): ?>
                            <span class="text-xs text-yellow-600">อีก <?= $daysUntilDue ?> วัน</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full <?= $statusInfo['color'] ?>">
                            <?= $statusInfo['label'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <button onclick="viewApDetail(<?= $ap['id'] ?>)" 
                                    class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg" 
                                    title="ดูรายละเอียด">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if (in_array($ap['status'], ['open', 'partial'])): ?>
                            <button onclick="openPaymentModal(<?= $ap['id'] ?>, '<?= htmlspecialchars($ap['ap_number']) ?>', '<?= htmlspecialchars($ap['supplier_name'] ?? '') ?>', <?= $ap['balance'] ?>)" 
                                    class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg" 
                                    title="บันทึกการชำระ">
                                <i class="fas fa-money-bill-wave"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Payment Recording Modal - Requirement 1.3 -->
<div id="paymentModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b sticky top-0 bg-white rounded-t-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-money-bill-wave text-green-500 mr-2"></i>บันทึกการชำระเงิน
                </h3>
                <button onclick="closePaymentModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <form id="paymentForm" onsubmit="submitPayment(event)" class="p-6 space-y-4">
            <input type="hidden" id="payment_ap_id" name="ap_id">
            
            <!-- AP Info Display -->
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">เลขที่ AP:</span>
                        <span id="modal_ap_number" class="font-medium text-gray-800 ml-2"></span>
                    </div>
                    <div>
                        <span class="text-gray-500">Supplier:</span>
                        <span id="modal_supplier_name" class="font-medium text-gray-800 ml-2"></span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-500">ยอดค้างชำระ:</span>
                        <span id="modal_balance" class="font-bold text-red-600 text-lg ml-2"></span>
                    </div>
                </div>
            </div>
            
            <!-- Payment Amount -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    จำนวนเงินที่ชำระ <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">฿</span>
                    <input type="number" id="payment_amount" name="amount" step="0.01" min="0.01" required
                           class="w-full pl-8 pr-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                           placeholder="0.00">
                </div>
                <div class="flex gap-2 mt-2">
                    <button type="button" onclick="setPaymentAmount('full')" 
                            class="text-xs px-3 py-1 bg-green-100 text-green-700 rounded-full hover:bg-green-200">
                        ชำระเต็มจำนวน
                    </button>
                    <button type="button" onclick="setPaymentAmount('half')" 
                            class="text-xs px-3 py-1 bg-blue-100 text-blue-700 rounded-full hover:bg-blue-200">
                        ชำระครึ่งหนึ่ง
                    </button>
                </div>
            </div>
            
            <!-- Payment Date -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    วันที่ชำระ <span class="text-red-500">*</span>
                </label>
                <input type="date" id="payment_date" name="payment_date" required
                       value="<?= date('Y-m-d') ?>"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Payment Method -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    วิธีการชำระ <span class="text-red-500">*</span>
                </label>
                <select id="payment_method" name="payment_method" required onchange="togglePaymentFields()"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    <option value="">-- เลือกวิธีการชำระ --</option>
                    <?php foreach ($paymentMethods as $value => $label): ?>
                    <option value="<?= $value ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Bank Account (for transfer) -->
            <div id="bankAccountField" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">บัญชีธนาคาร</label>
                <input type="text" id="bank_account" name="bank_account"
                       placeholder="ชื่อธนาคาร / เลขบัญชี"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Cheque Fields -->
            <div id="chequeFields" class="hidden space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">เลขที่เช็ค</label>
                    <input type="text" id="cheque_number" name="cheque_number"
                           class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">วันที่เช็ค</label>
                    <input type="date" id="cheque_date" name="cheque_date"
                           class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                </div>
            </div>
            
            <!-- Reference Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">เลขที่อ้างอิง</label>
                <input type="text" id="reference_number" name="reference_number"
                       placeholder="เลขที่ใบเสร็จ / เลขอ้างอิงการโอน"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ</label>
                <textarea id="payment_notes" name="notes" rows="2"
                          placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"
                          class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"></textarea>
            </div>
            
            <!-- Submit Buttons -->
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closePaymentModal()" 
                        class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                    ยกเลิก
                </button>
                <button type="submit" id="submitPaymentBtn"
                        class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-check mr-2"></i>บันทึกการชำระ
                </button>
            </div>
        </form>
    </div>
</div>

<!-- AP Detail Modal -->
<div id="apDetailModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b sticky top-0 bg-white rounded-t-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-file-invoice-dollar text-red-500 mr-2"></i>รายละเอียดเจ้าหนี้
                </h3>
                <button onclick="closeApDetailModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div id="apDetailContent" class="p-6">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
                <p class="text-gray-500 mt-2">กำลังโหลด...</p>
            </div>
        </div>
    </div>
</div>

<script>
// Current AP balance for validation
let currentApBalance = 0;

/**
 * Toggle filter section visibility
 */
function toggleFilters() {
    const section = document.getElementById('filterSection');
    const icon = document.getElementById('filterToggleIcon');
    section.classList.toggle('hidden');
    icon.classList.toggle('fa-chevron-down');
    icon.classList.toggle('fa-chevron-up');
}

/**
 * Change sorting and reload
 */
function changeSorting(value) {
    const [sortBy, sortOrder] = value.split('-');
    const url = new URL(window.location.href);
    url.searchParams.set('sort_by', sortBy);
    url.searchParams.set('sort_order', sortOrder);
    window.location.href = url.toString();
}

/**
 * Filter by aging bracket
 */
function filterByAging(bracket) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'ap');
    
    if (bracket === 'current') {
        // Show non-overdue open/partial
        url.searchParams.delete('status');
        url.searchParams.set('date_from', '<?= date('Y-m-d') ?>');
    } else {
        url.searchParams.set('status', 'overdue');
        url.searchParams.delete('date_from');
        url.searchParams.delete('date_to');
    }
    
    window.location.href = url.toString();
}

/**
 * Open payment modal
 */
function openPaymentModal(apId, apNumber, supplierName, balance) {
    currentApBalance = parseFloat(balance);
    
    document.getElementById('payment_ap_id').value = apId;
    document.getElementById('modal_ap_number').textContent = apNumber;
    document.getElementById('modal_supplier_name').textContent = supplierName || '-';
    document.getElementById('modal_balance').textContent = '฿' + formatNumber(balance);
    document.getElementById('payment_amount').max = balance;
    document.getElementById('payment_amount').value = '';
    
    // Reset form
    document.getElementById('paymentForm').reset();
    document.getElementById('payment_date').value = '<?= date('Y-m-d') ?>';
    togglePaymentFields();
    
    document.getElementById('paymentModal').classList.remove('hidden');
}

/**
 * Close payment modal
 */
function closePaymentModal() {
    document.getElementById('paymentModal').classList.add('hidden');
}

/**
 * Toggle payment method specific fields
 */
function togglePaymentFields() {
    const method = document.getElementById('payment_method').value;
    const bankField = document.getElementById('bankAccountField');
    const chequeFields = document.getElementById('chequeFields');
    
    bankField.classList.add('hidden');
    chequeFields.classList.add('hidden');
    
    if (method === 'transfer') {
        bankField.classList.remove('hidden');
    } else if (method === 'cheque') {
        chequeFields.classList.remove('hidden');
        bankField.classList.remove('hidden');
    }
}

/**
 * Set payment amount preset
 */
function setPaymentAmount(type) {
    const amountInput = document.getElementById('payment_amount');
    if (type === 'full') {
        amountInput.value = currentApBalance.toFixed(2);
    } else if (type === 'half') {
        amountInput.value = (currentApBalance / 2).toFixed(2);
    }
}

/**
 * Submit payment form
 */
async function submitPayment(event) {
    event.preventDefault();
    
    const form = document.getElementById('paymentForm');
    const submitBtn = document.getElementById('submitPaymentBtn');
    const formData = new FormData(form);
    
    // Validate amount
    const amount = parseFloat(formData.get('amount'));
    if (amount <= 0) {
        alert('กรุณาระบุจำนวนเงินที่ถูกต้อง');
        return;
    }
    if (amount > currentApBalance) {
        alert('จำนวนเงินเกินยอดค้างชำระ');
        return;
    }
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';
    
    try {
        const data = {
            action: 'ap_record_payment',
            ap_id: formData.get('ap_id'),
            amount: amount,
            payment_date: formData.get('payment_date'),
            payment_method: formData.get('payment_method'),
            bank_account: formData.get('bank_account'),
            reference_number: formData.get('reference_number'),
            cheque_number: formData.get('cheque_number'),
            cheque_date: formData.get('cheque_date'),
            notes: formData.get('notes')
        };
        
        const response = await fetch('api/accounting.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            closePaymentModal();
            // Redirect with success message
            window.location.href = 'accounting.php?tab=ap&success=payment_recorded';
        } else {
            alert('เกิดข้อผิดพลาด: ' + result.message);
        }
    } catch (error) {
        console.error('Payment error:', error);
        alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>บันทึกการชำระ';
    }
}

/**
 * View AP detail
 */
async function viewApDetail(apId) {
    document.getElementById('apDetailModal').classList.remove('hidden');
    document.getElementById('apDetailContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="text-gray-500 mt-2">กำลังโหลด...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`api/accounting.php?action=ap_detail&id=${apId}`);
        const result = await response.json();
        
        if (result.success) {
            renderApDetail(result.record);
        } else {
            document.getElementById('apDetailContent').innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>${result.message}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading AP detail:', error);
        document.getElementById('apDetailContent').innerHTML = `
            <div class="text-center py-8 text-red-500">
                <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                <p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
            </div>
        `;
    }
}

/**
 * Render AP detail content
 */
function renderApDetail(ap) {
    const statusColors = {
        'open': 'bg-blue-100 text-blue-700',
        'partial': 'bg-yellow-100 text-yellow-700',
        'paid': 'bg-green-100 text-green-700',
        'cancelled': 'bg-gray-100 text-gray-500'
    };
    const statusLabels = {
        'open': 'รอชำระ',
        'partial': 'ชำระบางส่วน',
        'paid': 'ชำระแล้ว',
        'cancelled': 'ยกเลิก'
    };
    
    let paymentsHtml = '';
    if (ap.payments && ap.payments.length > 0) {
        paymentsHtml = `
            <div class="mt-6">
                <h4 class="font-semibold text-gray-800 mb-3">
                    <i class="fas fa-history text-gray-400 mr-2"></i>ประวัติการชำระเงิน
                </h4>
                <div class="space-y-2">
                    ${ap.payments.map(p => `
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div>
                                <div class="font-medium text-gray-800">${p.voucher_number}</div>
                                <div class="text-xs text-gray-500">${formatDate(p.payment_date)} - ${getPaymentMethodLabel(p.payment_method)}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-semibold text-green-600">฿${formatNumber(p.amount)}</div>
                                ${p.reference_number ? `<div class="text-xs text-gray-500">Ref: ${p.reference_number}</div>` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    document.getElementById('apDetailContent').innerHTML = `
        <div class="space-y-6">
            <!-- Header Info -->
            <div class="flex items-start justify-between">
                <div>
                    <h4 class="text-xl font-bold text-gray-800">${ap.ap_number}</h4>
                    <p class="text-gray-500">${ap.supplier_name || '-'}</p>
                </div>
                <span class="px-3 py-1 text-sm rounded-full ${statusColors[ap.status] || 'bg-gray-100 text-gray-700'}">
                    ${statusLabels[ap.status] || ap.status}
                </span>
            </div>
            
            <!-- Amount Summary -->
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-gray-500">ยอดรวม</p>
                    <p class="text-xl font-bold text-gray-800">฿${formatNumber(ap.total_amount)}</p>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-green-600">ชำระแล้ว</p>
                    <p class="text-xl font-bold text-green-600">฿${formatNumber(ap.paid_amount)}</p>
                </div>
                <div class="bg-red-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-red-600">คงเหลือ</p>
                    <p class="text-xl font-bold text-red-600">฿${formatNumber(ap.balance)}</p>
                </div>
            </div>
            
            <!-- Details -->
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">Invoice:</span>
                    <span class="ml-2 text-gray-800">${ap.invoice_number || '-'}</span>
                </div>
                <div>
                    <span class="text-gray-500">วันที่ Invoice:</span>
                    <span class="ml-2 text-gray-800">${ap.invoice_date ? formatDate(ap.invoice_date) : '-'}</span>
                </div>
                <div>
                    <span class="text-gray-500">วันครบกำหนด:</span>
                    <span class="ml-2 text-gray-800 ${ap.is_overdue ? 'text-red-600 font-semibold' : ''}">${formatDate(ap.due_date)}</span>
                </div>
                <div>
                    <span class="text-gray-500">PO:</span>
                    <span class="ml-2 text-gray-800">${ap.po_number || '-'}</span>
                </div>
                <div>
                    <span class="text-gray-500">GR:</span>
                    <span class="ml-2 text-gray-800">${ap.gr_number || '-'}</span>
                </div>
                <div>
                    <span class="text-gray-500">วันที่สร้าง:</span>
                    <span class="ml-2 text-gray-800">${formatDate(ap.created_at)}</span>
                </div>
            </div>
            
            ${ap.notes ? `
            <div class="bg-yellow-50 rounded-lg p-4">
                <p class="text-sm text-yellow-800"><i class="fas fa-sticky-note mr-2"></i>${ap.notes}</p>
            </div>
            ` : ''}
            
            ${paymentsHtml}
            
            <!-- Actions -->
            ${['open', 'partial'].includes(ap.status) ? `
            <div class="pt-4 border-t">
                <button onclick="closeApDetailModal(); openPaymentModal(${ap.id}, '${ap.ap_number}', '${ap.supplier_name || ''}', ${ap.balance})" 
                        class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-money-bill-wave mr-2"></i>บันทึกการชำระเงิน
                </button>
            </div>
            ` : ''}
        </div>
    `;
}

/**
 * Close AP detail modal
 */
function closeApDetailModal() {
    document.getElementById('apDetailModal').classList.add('hidden');
}

/**
 * Format number with commas
 */
function formatNumber(num) {
    return parseFloat(num || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Format date
 */
function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/**
 * Get payment method label
 */
function getPaymentMethodLabel(method) {
    const labels = {
        'cash': 'เงินสด',
        'transfer': 'โอนเงิน',
        'cheque': 'เช็ค',
        'credit_card': 'บัตรเครดิต'
    };
    return labels[method] || method;
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePaymentModal();
        closeApDetailModal();
    }
});

// Close modals on backdrop click
document.getElementById('paymentModal').addEventListener('click', function(e) {
    if (e.target === this) closePaymentModal();
});
document.getElementById('apDetailModal').addEventListener('click', function(e) {
    if (e.target === this) closeApDetailModal();
});
</script>
