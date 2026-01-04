<?php
/**
 * Account Receivable (AR) Tab Content
 * ลูกหนี้การค้า - แสดงรายการลูกหนี้พร้อมตัวกรองและบันทึกการรับเงิน
 * 
 * Requirements: 2.2, 2.3, 5.2, 5.4
 * - Display AR list with filters (status, customer, date range)
 * - Show aging indicators
 * - Add receipt recording modal
 * 
 * @package AccountingManagement
 * @version 1.0.0
 */

require_once __DIR__ . '/../../classes/AccountReceivableService.php';
require_once __DIR__ . '/../../classes/AgingHelper.php';

// Initialize services
$arService = new AccountReceivableService($db, $currentBotId);

// Get filter values from request
$filterStatus = $_GET['status'] ?? '';
$filterUserId = $_GET['user_id'] ?? '';
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
if ($filterUserId) {
    $filters['user_id'] = $filterUserId;
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

// Get AR records
$arRecords = $arService->getAll($filters);

// If filtering by overdue, filter results to only show overdue
if ($filterStatus === 'overdue') {
    $arRecords = array_filter($arRecords, function($record) {
        return $record['is_overdue'] ?? false;
    });
    $arRecords = array_values($arRecords); // Re-index array
}

// Get customers for filter dropdown (from users table)
$customersStmt = $db->prepare("
    SELECT DISTINCT u.id, u.display_name, u.phone 
    FROM users u 
    INNER JOIN account_receivables ar ON u.id = ar.user_id 
    WHERE ar.line_account_id = ? OR ar.line_account_id IS NULL
    ORDER BY u.display_name
");
$customersStmt->execute([$currentBotId]);
$customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary stats
$totalOutstanding = $arService->getTotalOutstanding();
$totalOverdue = $arService->getTotalOverdue();
$agingReport = $arService->getAgingReport();

// Status labels and colors
$statusLabels = [
    'open' => ['label' => 'รอรับชำระ', 'color' => 'bg-blue-100 text-blue-700'],
    'partial' => ['label' => 'รับบางส่วน', 'color' => 'bg-yellow-100 text-yellow-700'],
    'paid' => ['label' => 'รับครบแล้ว', 'color' => 'bg-green-100 text-green-700'],
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
function formatMoneyAR($amount) {
    return number_format((float)$amount, 2);
}

// Get aging bracket color
function getAgingColorAR($daysOverdue) {
    if ($daysOverdue <= 0) return 'text-green-600';
    if ($daysOverdue <= 30) return 'text-yellow-600';
    if ($daysOverdue <= 60) return 'text-orange-600';
    return 'text-red-600';
}

// Get aging badge
function getAgingBadgeAR($daysOverdue) {
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
.ar-table th { white-space: nowrap; }
.ar-row:hover { background-color: #f8fafc; }
.filter-section { transition: all 0.3s; }
.aging-summary-card { transition: all 0.2s; cursor: pointer; }
.aging-summary-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.aging-summary-card.active { ring: 2px; ring-color: #10b981; }
</style>

<!-- Header with Summary Stats -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
    <div>
        <p class="text-gray-500">จัดการลูกหนี้การค้าและบันทึกการรับเงิน</p>
    </div>
    <div class="flex items-center gap-4">
        <div class="text-right">
            <p class="text-sm text-gray-500">ยอดค้างรับทั้งหมด</p>
            <p class="text-xl font-bold text-green-600">฿<?= formatMoneyAR($totalOutstanding) ?></p>
        </div>
        <?php if ($totalOverdue > 0): ?>
        <div class="text-right pl-4 border-l">
            <p class="text-sm text-gray-500">เกินกำหนด</p>
            <p class="text-xl font-bold text-orange-600">฿<?= formatMoneyAR($totalOverdue) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Aging Summary Cards - Requirement 5.2 -->
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
         onclick="filterByAgingAR('<?= $bracket ?>')">
        <p class="text-xs <?= $colors['text'] ?> opacity-75"><?= $label ?></p>
        <p class="text-lg font-bold <?= $colors['text'] ?>">฿<?= formatMoneyAR($bracketData['total']) ?></p>
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
        <button type="button" onclick="toggleFiltersAR()" class="text-sm text-green-600 hover:text-green-800">
            <i class="fas fa-chevron-down" id="filterToggleIconAR"></i>
        </button>
    </div>
    
    <div class="filter-section p-4" id="filterSectionAR">
        <form method="GET" action="accounting.php" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <input type="hidden" name="tab" value="ar">
            
            <!-- Status Filter -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">สถานะ</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    <option value="">ทั้งหมด</option>
                    <option value="open" <?= $filterStatus === 'open' ? 'selected' : '' ?>>รอรับชำระ</option>
                    <option value="partial" <?= $filterStatus === 'partial' ? 'selected' : '' ?>>รับบางส่วน</option>
                    <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>รับครบแล้ว</option>
                    <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>เกินกำหนด</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
                </select>
            </div>
            
            <!-- Customer Filter -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">ลูกค้า</label>
                <select name="user_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($customers as $customer): ?>
                    <option value="<?= $customer['id'] ?>" <?= $filterUserId == $customer['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($customer['display_name'] ?: $customer['phone'] ?: 'ลูกค้า #' . $customer['id']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Date From -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">วันครบกำหนด (จาก)</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Date To -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">วันครบกำหนด (ถึง)</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Search -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">ค้นหา</label>
                <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" 
                       placeholder="เลขที่ AR, Invoice, ลูกค้า..."
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Filter Actions -->
            <div class="lg:col-span-5 flex items-center gap-3 pt-2">
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-search mr-2"></i>ค้นหา
                </button>
                <a href="accounting.php?tab=ar" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                    <i class="fas fa-times mr-2"></i>ล้างตัวกรอง
                </a>
            </div>
        </form>
    </div>
</div>

<!-- AR List Table -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="p-4 border-b flex items-center justify-between">
        <h3 class="font-semibold text-gray-800">
            <i class="fas fa-hand-holding-usd text-green-500 mr-2"></i>รายการลูกหนี้
            <span class="text-sm font-normal text-gray-500 ml-2">(<?= count($arRecords) ?> รายการ)</span>
        </h3>
        <div class="flex items-center gap-2">
            <!-- Sort Options -->
            <select onchange="changeSortingAR(this.value)" class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg">
                <option value="due_date-ASC" <?= ($sortBy === 'due_date' && $sortOrder === 'ASC') ? 'selected' : '' ?>>วันครบกำหนด (เร็ว→ช้า)</option>
                <option value="due_date-DESC" <?= ($sortBy === 'due_date' && $sortOrder === 'DESC') ? 'selected' : '' ?>>วันครบกำหนด (ช้า→เร็ว)</option>
                <option value="balance-DESC" <?= ($sortBy === 'balance' && $sortOrder === 'DESC') ? 'selected' : '' ?>>ยอดค้าง (มาก→น้อย)</option>
                <option value="balance-ASC" <?= ($sortBy === 'balance' && $sortOrder === 'ASC') ? 'selected' : '' ?>>ยอดค้าง (น้อย→มาก)</option>
                <option value="created_at-DESC" <?= ($sortBy === 'created_at' && $sortOrder === 'DESC') ? 'selected' : '' ?>>วันที่สร้าง (ใหม่→เก่า)</option>
            </select>
        </div>
    </div>
    
    <?php if (empty($arRecords)): ?>
    <div class="p-12 text-center text-gray-400">
        <i class="fas fa-inbox text-5xl mb-4"></i>
        <p class="text-lg">ไม่พบรายการลูกหนี้</p>
        <p class="text-sm mt-2">ลองปรับตัวกรองหรือสร้างรายการใหม่</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full ar-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เลขที่ AR</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ลูกค้า</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">ยอดรวม</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">รับแล้ว</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">คงเหลือ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">ครบกำหนด</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($arRecords as $ar): 
                    $statusInfo = $statusLabels[$ar['status']] ?? ['label' => $ar['status'], 'color' => 'bg-gray-100 text-gray-700'];
                    $daysUntilDue = $ar['days_until_due'] ?? 0;
                    $isOverdue = $ar['is_overdue'] ?? false;
                    $customerName = $ar['customer_name'] ?: ($ar['customer_phone'] ?: 'ลูกค้า #' . $ar['user_id']);
                ?>
                <tr class="ar-row <?= $isOverdue ? 'bg-red-50' : '' ?>">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?= htmlspecialchars($ar['ar_number']) ?></div>
                        <?php if (!empty($ar['metadata']['order_number'])): ?>
                        <div class="text-xs text-gray-500">Order: <?= htmlspecialchars($ar['metadata']['order_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800"><?= htmlspecialchars($customerName) ?></div>
                        <?php if (!empty($ar['customer_phone'])): ?>
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($ar['customer_phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-gray-700"><?= htmlspecialchars($ar['invoice_number'] ?? '-') ?></div>
                        <?php if (!empty($ar['invoice_date'])): ?>
                        <div class="text-xs text-gray-500"><?= date('d/m/Y', strtotime($ar['invoice_date'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-gray-900">
                        ฿<?= formatMoneyAR($ar['total_amount']) ?>
                    </td>
                    <td class="px-4 py-3 text-right text-green-600">
                        ฿<?= formatMoneyAR($ar['received_amount']) ?>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold <?= $isOverdue ? 'text-red-600' : 'text-gray-900' ?>">
                        ฿<?= formatMoneyAR($ar['balance']) ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="<?= getAgingColorAR($daysUntilDue * -1) ?>">
                            <?= date('d/m/Y', strtotime($ar['due_date'])) ?>
                        </div>
                        <?php if ($isOverdue): ?>
                            <?= getAgingBadgeAR(abs($daysUntilDue)) ?>
                        <?php elseif ($daysUntilDue >= 0 && $daysUntilDue <= 7 && in_array($ar['status'], ['open', 'partial'])): ?>
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
                            <button onclick="viewArDetail(<?= $ar['id'] ?>)" 
                                    class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg" 
                                    title="ดูรายละเอียด">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if (in_array($ar['status'], ['open', 'partial'])): ?>
                            <button onclick="openReceiptModal(<?= $ar['id'] ?>, '<?= htmlspecialchars($ar['ar_number']) ?>', '<?= htmlspecialchars(addslashes($customerName)) ?>', <?= $ar['balance'] ?>)" 
                                    class="p-2 text-gray-500 hover:text-green-600 hover:bg-green-50 rounded-lg" 
                                    title="บันทึกการรับเงิน">
                                <i class="fas fa-hand-holding-usd"></i>
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


<!-- Receipt Recording Modal - Requirement 2.3 -->
<div id="receiptModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b sticky top-0 bg-white rounded-t-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-hand-holding-usd text-green-500 mr-2"></i>บันทึกการรับเงิน
                </h3>
                <button onclick="closeReceiptModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <form id="receiptForm" onsubmit="submitReceipt(event)" class="p-6 space-y-4">
            <input type="hidden" id="receipt_ar_id" name="ar_id">
            
            <!-- AR Info Display -->
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">เลขที่ AR:</span>
                        <span id="modal_ar_number" class="font-medium text-gray-800 ml-2"></span>
                    </div>
                    <div>
                        <span class="text-gray-500">ลูกค้า:</span>
                        <span id="modal_customer_name" class="font-medium text-gray-800 ml-2"></span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-500">ยอดค้างรับ:</span>
                        <span id="modal_balance" class="font-bold text-green-600 text-lg ml-2"></span>
                    </div>
                </div>
            </div>
            
            <!-- Receipt Amount -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    จำนวนเงินที่รับ <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">฿</span>
                    <input type="number" id="receipt_amount" name="amount" step="0.01" min="0.01" required
                           class="w-full pl-8 pr-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                           placeholder="0.00">
                </div>
                <div class="flex gap-2 mt-2">
                    <button type="button" onclick="setReceiptAmount('full')" 
                            class="text-xs px-3 py-1 bg-green-100 text-green-700 rounded-full hover:bg-green-200">
                        รับเต็มจำนวน
                    </button>
                    <button type="button" onclick="setReceiptAmount('half')" 
                            class="text-xs px-3 py-1 bg-blue-100 text-blue-700 rounded-full hover:bg-blue-200">
                        รับครึ่งหนึ่ง
                    </button>
                </div>
            </div>
            
            <!-- Receipt Date -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    วันที่รับเงิน <span class="text-red-500">*</span>
                </label>
                <input type="date" id="receipt_date" name="receipt_date" required
                       value="<?= date('Y-m-d') ?>"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Payment Method -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    วิธีการรับเงิน <span class="text-red-500">*</span>
                </label>
                <select id="receipt_payment_method" name="payment_method" required onchange="toggleReceiptFields()"
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
                    <option value="">-- เลือกวิธีการรับเงิน --</option>
                    <?php foreach ($paymentMethods as $value => $label): ?>
                    <option value="<?= $value ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Bank Account (for transfer) -->
            <div id="bankAccountFieldAR" class="hidden">
                <label class="block text-sm font-medium text-gray-700 mb-1">บัญชีธนาคาร</label>
                <input type="text" id="receipt_bank_account" name="bank_account"
                       placeholder="ชื่อธนาคาร / เลขบัญชี"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Reference Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">เลขที่อ้างอิง</label>
                <input type="text" id="receipt_reference_number" name="reference_number"
                       placeholder="เลขที่ใบเสร็จ / เลขอ้างอิงการโอน"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent">
            </div>
            
            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ</label>
                <textarea id="receipt_notes" name="notes" rows="2"
                          placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"
                          class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"></textarea>
            </div>
            
            <!-- Submit Buttons -->
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeReceiptModal()" 
                        class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                    ยกเลิก
                </button>
                <button type="submit" id="submitReceiptBtn"
                        class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-check mr-2"></i>บันทึกการรับเงิน
                </button>
            </div>
        </form>
    </div>
</div>

<!-- AR Detail Modal -->
<div id="arDetailModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b sticky top-0 bg-white rounded-t-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-hand-holding-usd text-green-500 mr-2"></i>รายละเอียดลูกหนี้
                </h3>
                <button onclick="closeArDetailModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div id="arDetailContent" class="p-6">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
                <p class="text-gray-500 mt-2">กำลังโหลด...</p>
            </div>
        </div>
    </div>
</div>


<script>
// Current AR balance for validation
let currentArBalance = 0;

/**
 * Toggle filter section visibility
 */
function toggleFiltersAR() {
    const section = document.getElementById('filterSectionAR');
    const icon = document.getElementById('filterToggleIconAR');
    section.classList.toggle('hidden');
    icon.classList.toggle('fa-chevron-down');
    icon.classList.toggle('fa-chevron-up');
}

/**
 * Change sorting and reload
 */
function changeSortingAR(value) {
    const [sortBy, sortOrder] = value.split('-');
    const url = new URL(window.location.href);
    url.searchParams.set('sort_by', sortBy);
    url.searchParams.set('sort_order', sortOrder);
    window.location.href = url.toString();
}

/**
 * Filter by aging bracket
 */
function filterByAgingAR(bracket) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'ar');
    
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
 * Open receipt modal
 */
function openReceiptModal(arId, arNumber, customerName, balance) {
    currentArBalance = parseFloat(balance);
    
    document.getElementById('receipt_ar_id').value = arId;
    document.getElementById('modal_ar_number').textContent = arNumber;
    document.getElementById('modal_customer_name').textContent = customerName || '-';
    document.getElementById('modal_balance').textContent = '฿' + formatNumberAR(balance);
    document.getElementById('receipt_amount').max = balance;
    document.getElementById('receipt_amount').value = '';
    
    // Reset form
    document.getElementById('receiptForm').reset();
    document.getElementById('receipt_date').value = '<?= date('Y-m-d') ?>';
    toggleReceiptFields();
    
    document.getElementById('receiptModal').classList.remove('hidden');
}

/**
 * Close receipt modal
 */
function closeReceiptModal() {
    document.getElementById('receiptModal').classList.add('hidden');
}

/**
 * Toggle payment method specific fields
 */
function toggleReceiptFields() {
    const method = document.getElementById('receipt_payment_method').value;
    const bankField = document.getElementById('bankAccountFieldAR');
    
    bankField.classList.add('hidden');
    
    if (method === 'transfer' || method === 'cheque') {
        bankField.classList.remove('hidden');
    }
}

/**
 * Set receipt amount preset
 */
function setReceiptAmount(type) {
    const amountInput = document.getElementById('receipt_amount');
    if (type === 'full') {
        amountInput.value = currentArBalance.toFixed(2);
    } else if (type === 'half') {
        amountInput.value = (currentArBalance / 2).toFixed(2);
    }
}

/**
 * Submit receipt form
 */
async function submitReceipt(event) {
    event.preventDefault();
    
    const form = document.getElementById('receiptForm');
    const submitBtn = document.getElementById('submitReceiptBtn');
    const formData = new FormData(form);
    
    // Validate amount
    const amount = parseFloat(formData.get('amount'));
    if (amount <= 0) {
        alert('กรุณาระบุจำนวนเงินที่ถูกต้อง');
        return;
    }
    if (amount > currentArBalance) {
        alert('จำนวนเงินเกินยอดค้างรับ');
        return;
    }
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';
    
    try {
        const data = {
            action: 'ar_record_receipt',
            ar_id: formData.get('ar_id'),
            amount: amount,
            receipt_date: formData.get('receipt_date'),
            payment_method: formData.get('payment_method'),
            bank_account: formData.get('bank_account'),
            reference_number: formData.get('reference_number'),
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
            closeReceiptModal();
            // Redirect with success message
            window.location.href = 'accounting.php?tab=ar&success=receipt_recorded';
        } else {
            alert('เกิดข้อผิดพลาด: ' + result.message);
        }
    } catch (error) {
        console.error('Receipt error:', error);
        alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>บันทึกการรับเงิน';
    }
}

/**
 * View AR detail
 */
async function viewArDetail(arId) {
    document.getElementById('arDetailModal').classList.remove('hidden');
    document.getElementById('arDetailContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="text-gray-500 mt-2">กำลังโหลด...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`api/accounting.php?action=ar_detail&id=${arId}`);
        const result = await response.json();
        
        if (result.success) {
            renderArDetail(result.record);
        } else {
            document.getElementById('arDetailContent').innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>${result.message}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading AR detail:', error);
        document.getElementById('arDetailContent').innerHTML = `
            <div class="text-center py-8 text-red-500">
                <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                <p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
            </div>
        `;
    }
}

/**
 * Render AR detail content
 */
function renderArDetail(ar) {
    const statusColors = {
        'open': 'bg-blue-100 text-blue-700',
        'partial': 'bg-yellow-100 text-yellow-700',
        'paid': 'bg-green-100 text-green-700',
        'cancelled': 'bg-gray-100 text-gray-500'
    };
    const statusLabels = {
        'open': 'รอรับชำระ',
        'partial': 'รับบางส่วน',
        'paid': 'รับครบแล้ว',
        'cancelled': 'ยกเลิก'
    };
    
    const customerName = ar.customer_name || ar.customer_phone || 'ลูกค้า #' + ar.user_id;
    
    let receiptsHtml = '';
    if (ar.receipts && ar.receipts.length > 0) {
        receiptsHtml = `
            <div class="mt-6">
                <h4 class="font-semibold text-gray-800 mb-3">
                    <i class="fas fa-history text-gray-400 mr-2"></i>ประวัติการรับเงิน
                </h4>
                <div class="space-y-2">
                    ${ar.receipts.map(r => `
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div>
                                <div class="font-medium text-gray-800">${r.voucher_number}</div>
                                <div class="text-xs text-gray-500">${formatDateAR(r.receipt_date)} - ${getPaymentMethodLabelAR(r.payment_method)}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-semibold text-green-600">฿${formatNumberAR(r.amount)}</div>
                                ${r.reference_number ? `<div class="text-xs text-gray-500">Ref: ${r.reference_number}</div>` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    document.getElementById('arDetailContent').innerHTML = `
        <div class="space-y-6">
            <!-- Header Info -->
            <div class="flex items-start justify-between">
                <div>
                    <h4 class="text-xl font-bold text-gray-800">${ar.ar_number}</h4>
                    <p class="text-gray-500">${customerName}</p>
                    ${ar.customer_phone ? `<p class="text-sm text-gray-400">${ar.customer_phone}</p>` : ''}
                </div>
                <span class="px-3 py-1 text-sm rounded-full ${statusColors[ar.status] || 'bg-gray-100 text-gray-700'}">
                    ${statusLabels[ar.status] || ar.status}
                </span>
            </div>
            
            <!-- Amount Summary -->
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-gray-500">ยอดรวม</p>
                    <p class="text-xl font-bold text-gray-800">฿${formatNumberAR(ar.total_amount)}</p>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-green-600">รับแล้ว</p>
                    <p class="text-xl font-bold text-green-600">฿${formatNumberAR(ar.received_amount)}</p>
                </div>
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-blue-600">คงเหลือ</p>
                    <p class="text-xl font-bold text-blue-600">฿${formatNumberAR(ar.balance)}</p>
                </div>
            </div>
            
            <!-- Details -->
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">Invoice:</span>
                    <span class="ml-2 text-gray-800">${ar.invoice_number || '-'}</span>
                </div>
                <div>
                    <span class="text-gray-500">วันที่ Invoice:</span>
                    <span class="ml-2 text-gray-800">${ar.invoice_date ? formatDateAR(ar.invoice_date) : '-'}</span>
                </div>
                <div>
                    <span class="text-gray-500">วันครบกำหนด:</span>
                    <span class="ml-2 text-gray-800 ${ar.is_overdue ? 'text-red-600 font-semibold' : ''}">${formatDateAR(ar.due_date)}</span>
                </div>
                <div>
                    <span class="text-gray-500">Order:</span>
                    <span class="ml-2 text-gray-800">${ar.order_number || (ar.metadata?.order_number) || '-'}</span>
                </div>
                <div>
                    <span class="text-gray-500">วันที่สร้าง:</span>
                    <span class="ml-2 text-gray-800">${formatDateAR(ar.created_at)}</span>
                </div>
                ${ar.closed_at ? `
                <div>
                    <span class="text-gray-500">วันที่ปิด:</span>
                    <span class="ml-2 text-gray-800">${formatDateAR(ar.closed_at)}</span>
                </div>
                ` : ''}
            </div>
            
            ${ar.notes ? `
            <div class="bg-yellow-50 rounded-lg p-4">
                <p class="text-sm text-yellow-800"><i class="fas fa-sticky-note mr-2"></i>${ar.notes}</p>
            </div>
            ` : ''}
            
            ${receiptsHtml}
            
            <!-- Actions -->
            ${['open', 'partial'].includes(ar.status) ? `
            <div class="pt-4 border-t">
                <button onclick="closeArDetailModal(); openReceiptModal(${ar.id}, '${ar.ar_number}', '${customerName.replace(/'/g, "\\'")}', ${ar.balance})" 
                        class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-hand-holding-usd mr-2"></i>บันทึกการรับเงิน
                </button>
            </div>
            ` : ''}
        </div>
    `;
}

/**
 * Close AR detail modal
 */
function closeArDetailModal() {
    document.getElementById('arDetailModal').classList.add('hidden');
}

/**
 * Format number with commas
 */
function formatNumberAR(num) {
    return parseFloat(num || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Format date
 */
function formatDateAR(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

/**
 * Get payment method label
 */
function getPaymentMethodLabelAR(method) {
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
        closeReceiptModal();
        closeArDetailModal();
    }
});

// Close modals on backdrop click
document.getElementById('receiptModal').addEventListener('click', function(e) {
    if (e.target === this) closeReceiptModal();
});
document.getElementById('arDetailModal').addEventListener('click', function(e) {
    if (e.target === this) closeArDetailModal();
});
</script>
