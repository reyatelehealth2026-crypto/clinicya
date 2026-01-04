<?php
/**
 * Expenses Tab Content
 * ค่าใช้จ่าย - แสดงรายการค่าใช้จ่ายพร้อมตัวกรองและจัดการหมวดหมู่
 * 
 * Requirements: 3.1, 3.2, 3.3, 3.5
 * - Display expense list with filters (category, date range, status)
 * - Add expense creation/edit modal
 * - Add category management section
 * 
 * @package AccountingManagement
 * @version 1.0.0
 */

require_once __DIR__ . '/../../classes/ExpenseService.php';
require_once __DIR__ . '/../../classes/ExpenseCategoryService.php';

// Initialize services
$expenseService = new ExpenseService($db, $currentBotId);
$categoryService = new ExpenseCategoryService($db, $currentBotId);

// Initialize default categories if needed
$categoryService->initializeDefaults();

// Get filter values from request
$filterCategoryId = $_GET['category_id'] ?? '';
$filterStatus = $_GET['payment_status'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterSearch = $_GET['search'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'expense_date';
$sortOrder = $_GET['sort_order'] ?? 'DESC';

// Build filters array
$filters = [];
if ($filterCategoryId) {
    $filters['category_id'] = $filterCategoryId;
}
if ($filterStatus) {
    $filters['payment_status'] = $filterStatus;
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

// Get expense records
$expenseRecords = $expenseService->getAll($filters);

// Get categories for filter dropdown and forms
$categories = $categoryService->getAll(['is_active' => 1]);

// Get summary stats
$countByStatus = $expenseService->getCountByStatus();
$totalUnpaid = $countByStatus['unpaid']['total_amount'] ?? 0;
$totalPaid = $countByStatus['paid']['total_amount'] ?? 0;
$unpaidCount = $countByStatus['unpaid']['count'] ?? 0;
$paidCount = $countByStatus['paid']['count'] ?? 0;

// Status labels and colors
$statusLabels = [
    'unpaid' => ['label' => 'ยังไม่ชำระ', 'color' => 'bg-red-100 text-red-700'],
    'paid' => ['label' => 'ชำระแล้ว', 'color' => 'bg-green-100 text-green-700'],
];

// Payment methods
$paymentMethods = [
    'cash' => 'เงินสด',
    'transfer' => 'โอนเงิน',
    'cheque' => 'เช็ค',
    'credit_card' => 'บัตรเครดิต',
];

// Expense types
$expenseTypes = [
    'operating' => 'ค่าใช้จ่ายดำเนินงาน',
    'administrative' => 'ค่าใช้จ่ายบริหาร',
    'financial' => 'ค่าใช้จ่ายทางการเงิน',
    'other' => 'อื่นๆ',
];

// Format money helper
function formatMoneyExp($amount) {
    return number_format((float)$amount, 2);
}
?>

<style>
.expense-table th { white-space: nowrap; }
.expense-row:hover { background-color: #f8fafc; }
.filter-section { transition: all 0.3s; }
.category-card { transition: all 0.2s; }
.category-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
</style>

<!-- Header with Summary Stats -->
<div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6">
    <div>
        <p class="text-gray-500">จัดการค่าใช้จ่ายและหมวดหมู่ค่าใช้จ่าย</p>
    </div>
    <div class="flex items-center gap-4">
        <div class="text-right">
            <p class="text-sm text-gray-500">ยังไม่ชำระ</p>
            <p class="text-xl font-bold text-red-600">฿<?= formatMoneyExp($totalUnpaid) ?></p>
            <p class="text-xs text-gray-400"><?= $unpaidCount ?> รายการ</p>
        </div>
        <div class="text-right pl-4 border-l">
            <p class="text-sm text-gray-500">ชำระแล้ว</p>
            <p class="text-xl font-bold text-green-600">฿<?= formatMoneyExp($totalPaid) ?></p>
            <p class="text-xs text-gray-400"><?= $paidCount ?> รายการ</p>
        </div>
        <button onclick="openExpenseModal()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-plus mr-2"></i>เพิ่มค่าใช้จ่าย
        </button>
    </div>
</div>

<!-- Quick Stats Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-lg shadow p-4 cursor-pointer hover:shadow-md" onclick="filterByStatus('')">
        <p class="text-xs text-gray-500">ทั้งหมด</p>
        <p class="text-lg font-bold text-gray-800">฿<?= formatMoneyExp($totalUnpaid + $totalPaid) ?></p>
        <p class="text-xs text-gray-400"><?= $unpaidCount + $paidCount ?> รายการ</p>
    </div>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 cursor-pointer hover:shadow-md" onclick="filterByStatus('unpaid')">
        <p class="text-xs text-red-600">ยังไม่ชำระ</p>
        <p class="text-lg font-bold text-red-700">฿<?= formatMoneyExp($totalUnpaid) ?></p>
        <p class="text-xs text-red-400"><?= $unpaidCount ?> รายการ</p>
    </div>
    <div class="bg-green-50 border border-green-200 rounded-lg p-4 cursor-pointer hover:shadow-md" onclick="filterByStatus('paid')">
        <p class="text-xs text-green-600">ชำระแล้ว</p>
        <p class="text-lg font-bold text-green-700">฿<?= formatMoneyExp($totalPaid) ?></p>
        <p class="text-xs text-green-400"><?= $paidCount ?> รายการ</p>
    </div>
    <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 cursor-pointer hover:shadow-md" onclick="openCategoryModal()">
        <p class="text-xs text-purple-600">หมวดหมู่</p>
        <p class="text-lg font-bold text-purple-700"><?= count($categories) ?></p>
        <p class="text-xs text-purple-400">จัดการหมวดหมู่</p>
    </div>
</div>

<!-- Filters Section -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b flex items-center justify-between">
        <h3 class="font-semibold text-gray-800">
            <i class="fas fa-filter text-gray-400 mr-2"></i>ตัวกรอง
        </h3>
        <button type="button" onclick="toggleFiltersExp()" class="text-sm text-purple-600 hover:text-purple-800">
            <i class="fas fa-chevron-down" id="filterToggleIconExp"></i>
        </button>
    </div>
    
    <div class="filter-section p-4" id="filterSectionExp">
        <form method="GET" action="accounting.php" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <input type="hidden" name="tab" value="expenses">
            
            <!-- Category Filter -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">หมวดหมู่</label>
                <select name="category_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($categories as $category): ?>
                    <option value="<?= $category['id'] ?>" <?= $filterCategoryId == $category['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Status Filter -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">สถานะ</label>
                <select name="payment_status" class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    <option value="">ทั้งหมด</option>
                    <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>ยังไม่ชำระ</option>
                    <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>ชำระแล้ว</option>
                </select>
            </div>
            
            <!-- Date From -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">วันที่ (จาก)</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>" 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
            </div>
            
            <!-- Date To -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">วันที่ (ถึง)</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>" 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
            </div>
            
            <!-- Search -->
            <div>
                <label class="block text-sm text-gray-600 mb-1">ค้นหา</label>
                <input type="text" name="search" value="<?= htmlspecialchars($filterSearch) ?>" 
                       placeholder="รายละเอียด, ผู้รับเงิน..."
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
            </div>
            
            <!-- Filter Actions -->
            <div class="lg:col-span-5 flex items-center gap-3 pt-2">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                    <i class="fas fa-search mr-2"></i>ค้นหา
                </button>
                <a href="accounting.php?tab=expenses" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                    <i class="fas fa-times mr-2"></i>ล้างตัวกรอง
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Expense List Table -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="p-4 border-b flex items-center justify-between">
        <h3 class="font-semibold text-gray-800">
            <i class="fas fa-receipt text-purple-500 mr-2"></i>รายการค่าใช้จ่าย
            <span class="text-sm font-normal text-gray-500 ml-2">(<?= count($expenseRecords) ?> รายการ)</span>
        </h3>
        <div class="flex items-center gap-2">
            <!-- Sort Options -->
            <select onchange="changeSortingExp(this.value)" class="text-sm px-3 py-1.5 border border-gray-200 rounded-lg">
                <option value="expense_date-DESC" <?= ($sortBy === 'expense_date' && $sortOrder === 'DESC') ? 'selected' : '' ?>>วันที่ (ใหม่→เก่า)</option>
                <option value="expense_date-ASC" <?= ($sortBy === 'expense_date' && $sortOrder === 'ASC') ? 'selected' : '' ?>>วันที่ (เก่า→ใหม่)</option>
                <option value="amount-DESC" <?= ($sortBy === 'amount' && $sortOrder === 'DESC') ? 'selected' : '' ?>>จำนวนเงิน (มาก→น้อย)</option>
                <option value="amount-ASC" <?= ($sortBy === 'amount' && $sortOrder === 'ASC') ? 'selected' : '' ?>>จำนวนเงิน (น้อย→มาก)</option>
                <option value="created_at-DESC" <?= ($sortBy === 'created_at' && $sortOrder === 'DESC') ? 'selected' : '' ?>>วันที่สร้าง (ใหม่→เก่า)</option>
            </select>
        </div>
    </div>
    
    <?php if (empty($expenseRecords)): ?>
    <div class="p-12 text-center text-gray-400">
        <i class="fas fa-receipt text-5xl mb-4"></i>
        <p class="text-lg">ไม่พบรายการค่าใช้จ่าย</p>
        <p class="text-sm mt-2">ลองปรับตัวกรองหรือเพิ่มรายการใหม่</p>
        <button onclick="openExpenseModal()" class="mt-4 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-plus mr-2"></i>เพิ่มค่าใช้จ่าย
        </button>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full expense-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เลขที่</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">หมวดหมู่</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">รายละเอียด</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้รับเงิน</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">จำนวนเงิน</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">วันที่</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($expenseRecords as $expense): 
                    $statusInfo = $statusLabels[$expense['payment_status']] ?? ['label' => $expense['payment_status'], 'color' => 'bg-gray-100 text-gray-700'];
                    $isOverdue = $expense['payment_status'] === 'unpaid' && !empty($expense['due_date']) && strtotime($expense['due_date']) < strtotime('today');
                ?>
                <tr class="expense-row <?= $isOverdue ? 'bg-red-50' : '' ?>">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?= htmlspecialchars($expense['expense_number']) ?></div>
                        <?php if (!empty($expense['reference_number'])): ?>
                        <div class="text-xs text-gray-500">Ref: <?= htmlspecialchars($expense['reference_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 text-xs rounded-full bg-purple-100 text-purple-700">
                            <?= htmlspecialchars($expense['category_name'] ?? '-') ?>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-gray-700 max-w-xs truncate"><?= htmlspecialchars($expense['description'] ?? '-') ?></div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-gray-700"><?= htmlspecialchars($expense['vendor_name'] ?? '-') ?></div>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-gray-900">
                        ฿<?= formatMoneyExp($expense['amount']) ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="text-gray-700"><?= date('d/m/Y', strtotime($expense['expense_date'])) ?></div>
                        <?php if (!empty($expense['due_date'])): ?>
                        <div class="text-xs <?= $isOverdue ? 'text-red-600 font-medium' : 'text-gray-500' ?>">
                            ครบกำหนด: <?= date('d/m/Y', strtotime($expense['due_date'])) ?>
                            <?php if ($isOverdue): ?>
                            <span class="text-red-600">(เกินกำหนด)</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full <?= $statusInfo['color'] ?>">
                            <?= $statusInfo['label'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <button onclick="viewExpenseDetail(<?= $expense['id'] ?>)" 
                                    class="p-2 text-gray-500 hover:text-purple-600 hover:bg-purple-50 rounded-lg" 
                                    title="ดูรายละเอียด">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if ($expense['payment_status'] === 'unpaid'): ?>
                            <button onclick="editExpense(<?= $expense['id'] ?>)" 
                                    class="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg" 
                                    title="แก้ไข">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="deleteExpense(<?= $expense['id'] ?>, '<?= htmlspecialchars($expense['expense_number']) ?>')" 
                                    class="p-2 text-gray-500 hover:text-red-600 hover:bg-red-50 rounded-lg" 
                                    title="ลบ">
                                <i class="fas fa-trash"></i>
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


<!-- Expense Create/Edit Modal - Requirement 3.1 -->
<div id="expenseModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b sticky top-0 bg-white rounded-t-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800" id="expenseModalTitle">
                    <i class="fas fa-receipt text-purple-500 mr-2"></i>เพิ่มค่าใช้จ่าย
                </h3>
                <button onclick="closeExpenseModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <form id="expenseForm" onsubmit="submitExpense(event)" class="p-6 space-y-4">
            <input type="hidden" id="expense_id" name="id">
            <input type="hidden" id="expense_action" name="action" value="expense_create">
            
            <!-- Category -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    หมวดหมู่ <span class="text-red-500">*</span>
                </label>
                <select id="expense_category_id" name="category_id" required
                        class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    <option value="">-- เลือกหมวดหมู่ --</option>
                    <?php foreach ($categories as $category): ?>
                    <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Amount -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    จำนวนเงิน <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">฿</span>
                    <input type="number" id="expense_amount" name="amount" step="0.01" min="0.01" required
                           class="w-full pl-8 pr-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                           placeholder="0.00">
                </div>
            </div>
            
            <!-- Expense Date -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    วันที่ค่าใช้จ่าย <span class="text-red-500">*</span>
                </label>
                <input type="date" id="expense_date" name="expense_date" required
                       value="<?= date('Y-m-d') ?>"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
            </div>
            
            <!-- Due Date (Optional) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">วันครบกำหนดชำระ</label>
                <input type="date" id="expense_due_date" name="due_date"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
            </div>
            
            <!-- Description -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">รายละเอียด</label>
                <textarea id="expense_description" name="description" rows="2"
                          placeholder="รายละเอียดค่าใช้จ่าย"
                          class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"></textarea>
            </div>
            
            <!-- Vendor Name -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ผู้รับเงิน / ร้านค้า</label>
                <input type="text" id="expense_vendor_name" name="vendor_name"
                       placeholder="ชื่อผู้รับเงินหรือร้านค้า"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
            </div>
            
            <!-- Reference Number -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">เลขที่อ้างอิง</label>
                <input type="text" id="expense_reference_number" name="reference_number"
                       placeholder="เลขที่ใบเสร็จ / เลขอ้างอิง"
                       class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
            </div>
            
            <!-- Payment Status - Requirement 3.5 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">สถานะการชำระ</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="payment_status" value="unpaid" checked
                               class="text-purple-600 focus:ring-purple-500">
                        <span class="text-gray-700">ยังไม่ชำระ</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="payment_status" value="paid"
                               class="text-purple-600 focus:ring-purple-500">
                        <span class="text-gray-700">ชำระแล้ว</span>
                    </label>
                </div>
            </div>
            
            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ</label>
                <textarea id="expense_notes" name="notes" rows="2"
                          placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"
                          class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"></textarea>
            </div>
            
            <!-- Submit Buttons -->
            <div class="flex gap-3 pt-4">
                <button type="button" onclick="closeExpenseModal()" 
                        class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                    ยกเลิก
                </button>
                <button type="submit" id="submitExpenseBtn"
                        class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-check mr-2"></i>บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Expense Detail Modal -->
<div id="expenseDetailModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b sticky top-0 bg-white rounded-t-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-receipt text-purple-500 mr-2"></i>รายละเอียดค่าใช้จ่าย
                </h3>
                <button onclick="closeExpenseDetailModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div id="expenseDetailContent" class="p-6">
            <div class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
                <p class="text-gray-500 mt-2">กำลังโหลด...</p>
            </div>
        </div>
    </div>
</div>

<!-- Category Management Modal - Requirement 3.3 -->
<div id="categoryModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b sticky top-0 bg-white rounded-t-2xl">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-tags text-purple-500 mr-2"></i>จัดการหมวดหมู่ค่าใช้จ่าย
                </h3>
                <button onclick="closeCategoryModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        
        <div class="p-6">
            <!-- Add New Category Form -->
            <div class="bg-gray-50 rounded-lg p-4 mb-6">
                <h4 class="font-medium text-gray-800 mb-3">เพิ่มหมวดหมู่ใหม่</h4>
                <form id="categoryForm" onsubmit="submitCategory(event)" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">ชื่อหมวดหมู่ (ไทย) <span class="text-red-500">*</span></label>
                        <input type="text" id="category_name" name="name" required
                               placeholder="เช่น ค่าน้ำ, ค่าไฟ"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">ชื่อหมวดหมู่ (อังกฤษ)</label>
                        <input type="text" id="category_name_en" name="name_en"
                               placeholder="e.g. Water, Electricity"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">ประเภทค่าใช้จ่าย</label>
                        <select id="category_expense_type" name="expense_type"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                            <?php foreach ($expenseTypes as $value => $label): ?>
                            <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                            <i class="fas fa-plus mr-2"></i>เพิ่มหมวดหมู่
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Category List -->
            <h4 class="font-medium text-gray-800 mb-3">หมวดหมู่ทั้งหมด</h4>
            <div id="categoryList" class="space-y-2">
                <?php foreach ($categories as $category): ?>
                <div class="category-card flex items-center justify-between p-3 bg-white border border-gray-200 rounded-lg">
                    <div class="flex items-center gap-3">
                        <span class="w-8 h-8 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center">
                            <i class="fas fa-tag text-sm"></i>
                        </span>
                        <div>
                            <p class="font-medium text-gray-800"><?= htmlspecialchars($category['name']) ?></p>
                            <p class="text-xs text-gray-500">
                                <?= htmlspecialchars($category['name_en'] ?? '') ?>
                                <?php if (!empty($category['expense_type'])): ?>
                                • <?= $expenseTypes[$category['expense_type']] ?? $category['expense_type'] ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if (!$category['is_default']): ?>
                        <button onclick="deleteCategory(<?= $category['id'] ?>, '<?= htmlspecialchars(addslashes($category['name'])) ?>')" 
                                class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg" 
                                title="ลบ">
                            <i class="fas fa-trash text-sm"></i>
                        </button>
                        <?php else: ?>
                        <span class="px-2 py-1 text-xs bg-gray-100 text-gray-500 rounded-full">ค่าเริ่มต้น</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>


<script>
/**
 * Toggle filter section visibility
 */
function toggleFiltersExp() {
    const section = document.getElementById('filterSectionExp');
    const icon = document.getElementById('filterToggleIconExp');
    section.classList.toggle('hidden');
    icon.classList.toggle('fa-chevron-down');
    icon.classList.toggle('fa-chevron-up');
}

/**
 * Change sorting and reload
 */
function changeSortingExp(value) {
    const [sortBy, sortOrder] = value.split('-');
    const url = new URL(window.location.href);
    url.searchParams.set('sort_by', sortBy);
    url.searchParams.set('sort_order', sortOrder);
    window.location.href = url.toString();
}

/**
 * Filter by payment status
 */
function filterByStatus(status) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'expenses');
    if (status) {
        url.searchParams.set('payment_status', status);
    } else {
        url.searchParams.delete('payment_status');
    }
    window.location.href = url.toString();
}

/**
 * Format number with commas
 */
function formatNumberExp(num) {
    return parseFloat(num).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ==================== Expense Modal Functions ====================

/**
 * Open expense modal for creating new expense
 */
function openExpenseModal() {
    document.getElementById('expenseModalTitle').innerHTML = '<i class="fas fa-receipt text-purple-500 mr-2"></i>เพิ่มค่าใช้จ่าย';
    document.getElementById('expenseForm').reset();
    document.getElementById('expense_id').value = '';
    document.getElementById('expense_action').value = 'expense_create';
    document.getElementById('expense_date').value = '<?= date('Y-m-d') ?>';
    document.getElementById('expenseModal').classList.remove('hidden');
}

/**
 * Close expense modal
 */
function closeExpenseModal() {
    document.getElementById('expenseModal').classList.add('hidden');
}

/**
 * Edit expense - load data and open modal
 */
async function editExpense(id) {
    try {
        const response = await fetch(`api/accounting.php?action=expense_detail&id=${id}`);
        const data = await response.json();
        
        if (!data.success) {
            alert(data.message || 'ไม่สามารถโหลดข้อมูลได้');
            return;
        }
        
        const expense = data.record;
        
        document.getElementById('expenseModalTitle').innerHTML = '<i class="fas fa-edit text-blue-500 mr-2"></i>แก้ไขค่าใช้จ่าย';
        document.getElementById('expense_id').value = expense.id;
        document.getElementById('expense_action').value = 'expense_update';
        document.getElementById('expense_category_id').value = expense.category_id;
        document.getElementById('expense_amount').value = expense.amount;
        document.getElementById('expense_date').value = expense.expense_date;
        document.getElementById('expense_due_date').value = expense.due_date || '';
        document.getElementById('expense_description').value = expense.description || '';
        document.getElementById('expense_vendor_name').value = expense.vendor_name || '';
        document.getElementById('expense_reference_number').value = expense.reference_number || '';
        document.getElementById('expense_notes').value = expense.notes || '';
        
        // Set payment status radio
        const statusRadios = document.querySelectorAll('input[name="payment_status"]');
        statusRadios.forEach(radio => {
            radio.checked = radio.value === expense.payment_status;
        });
        
        document.getElementById('expenseModal').classList.remove('hidden');
    } catch (error) {
        console.error('Error loading expense:', error);
        alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

/**
 * Submit expense form (create or update)
 */
async function submitExpense(event) {
    event.preventDefault();
    
    const form = document.getElementById('expenseForm');
    const submitBtn = document.getElementById('submitExpenseBtn');
    const formData = new FormData(form);
    
    // Validate required fields
    if (!formData.get('category_id')) {
        alert('กรุณาเลือกหมวดหมู่');
        return;
    }
    if (!formData.get('amount') || parseFloat(formData.get('amount')) <= 0) {
        alert('กรุณาระบุจำนวนเงินที่ถูกต้อง');
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';
    
    try {
        const action = formData.get('action');
        const expenseData = {
            action: action,
            category_id: formData.get('category_id'),
            amount: formData.get('amount'),
            expense_date: formData.get('expense_date'),
            due_date: formData.get('due_date') || null,
            description: formData.get('description') || null,
            vendor_name: formData.get('vendor_name') || null,
            reference_number: formData.get('reference_number') || null,
            payment_status: formData.get('payment_status'),
            notes: formData.get('notes') || null
        };
        
        // Add ID for update
        if (action === 'expense_update') {
            expenseData.id = formData.get('id');
        }
        
        const response = await fetch('api/accounting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(expenseData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            const successType = action === 'expense_create' ? 'created' : 'updated';
            window.location.href = `accounting.php?tab=expenses&success=${successType}`;
        } else {
            alert(data.message || 'เกิดข้อผิดพลาด');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>บันทึก';
        }
    } catch (error) {
        console.error('Error saving expense:', error);
        alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>บันทึก';
    }
}

/**
 * Delete expense
 */
async function deleteExpense(id, expenseNumber) {
    if (!confirm(`ต้องการลบค่าใช้จ่าย ${expenseNumber} หรือไม่?`)) {
        return;
    }
    
    try {
        const response = await fetch(`api/accounting.php?action=expense_delete&id=${id}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.location.href = 'accounting.php?tab=expenses&success=deleted';
        } else {
            alert(data.message || 'ไม่สามารถลบได้');
        }
    } catch (error) {
        console.error('Error deleting expense:', error);
        alert('เกิดข้อผิดพลาดในการลบข้อมูล');
    }
}

/**
 * View expense detail
 */
async function viewExpenseDetail(id) {
    document.getElementById('expenseDetailModal').classList.remove('hidden');
    document.getElementById('expenseDetailContent').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-spinner fa-spin text-3xl text-gray-400"></i>
            <p class="text-gray-500 mt-2">กำลังโหลด...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`api/accounting.php?action=expense_detail&id=${id}`);
        const data = await response.json();
        
        if (!data.success) {
            document.getElementById('expenseDetailContent').innerHTML = `
                <div class="text-center py-8 text-red-500">
                    <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                    <p>${data.message || 'ไม่สามารถโหลดข้อมูลได้'}</p>
                </div>
            `;
            return;
        }
        
        const expense = data.record;
        const statusClass = expense.payment_status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
        const statusLabel = expense.payment_status === 'paid' ? 'ชำระแล้ว' : 'ยังไม่ชำระ';
        
        document.getElementById('expenseDetailContent').innerHTML = `
            <div class="space-y-6">
                <!-- Header Info -->
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="text-xl font-bold text-gray-800">${expense.expense_number}</h4>
                        <p class="text-gray-500">${expense.category_name || '-'}</p>
                    </div>
                    <span class="px-3 py-1 text-sm rounded-full ${statusClass}">${statusLabel}</span>
                </div>
                
                <!-- Amount -->
                <div class="bg-purple-50 rounded-lg p-4 text-center">
                    <p class="text-sm text-purple-600">จำนวนเงิน</p>
                    <p class="text-3xl font-bold text-purple-700">฿${formatNumberExp(expense.amount)}</p>
                </div>
                
                <!-- Details Grid -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <p class="text-xs text-gray-500">วันที่ค่าใช้จ่าย</p>
                        <p class="font-medium text-gray-800">${formatDate(expense.expense_date)}</p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <p class="text-xs text-gray-500">วันครบกำหนด</p>
                        <p class="font-medium text-gray-800">${expense.due_date ? formatDate(expense.due_date) : '-'}</p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <p class="text-xs text-gray-500">ผู้รับเงิน</p>
                        <p class="font-medium text-gray-800">${expense.vendor_name || '-'}</p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg">
                        <p class="text-xs text-gray-500">เลขที่อ้างอิง</p>
                        <p class="font-medium text-gray-800">${expense.reference_number || '-'}</p>
                    </div>
                </div>
                
                <!-- Description -->
                ${expense.description ? `
                <div class="p-3 bg-gray-50 rounded-lg">
                    <p class="text-xs text-gray-500 mb-1">รายละเอียด</p>
                    <p class="text-gray-800">${expense.description}</p>
                </div>
                ` : ''}
                
                <!-- Notes -->
                ${expense.notes ? `
                <div class="p-3 bg-yellow-50 rounded-lg">
                    <p class="text-xs text-yellow-600 mb-1">หมายเหตุ</p>
                    <p class="text-gray-800">${expense.notes}</p>
                </div>
                ` : ''}
                
                <!-- Actions -->
                ${expense.payment_status === 'unpaid' ? `
                <div class="flex gap-3 pt-4 border-t">
                    <button onclick="closeExpenseDetailModal(); editExpense(${expense.id});" 
                            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-edit mr-2"></i>แก้ไข
                    </button>
                    <button onclick="closeExpenseDetailModal(); deleteExpense(${expense.id}, '${expense.expense_number}');" 
                            class="px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200">
                        <i class="fas fa-trash mr-2"></i>ลบ
                    </button>
                </div>
                ` : ''}
            </div>
        `;
    } catch (error) {
        console.error('Error loading expense detail:', error);
        document.getElementById('expenseDetailContent').innerHTML = `
            <div class="text-center py-8 text-red-500">
                <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                <p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
            </div>
        `;
    }
}

/**
 * Close expense detail modal
 */
function closeExpenseDetailModal() {
    document.getElementById('expenseDetailModal').classList.add('hidden');
}

// ==================== Category Modal Functions ====================

/**
 * Open category management modal
 */
function openCategoryModal() {
    document.getElementById('categoryModal').classList.remove('hidden');
}

/**
 * Close category modal
 */
function closeCategoryModal() {
    document.getElementById('categoryModal').classList.add('hidden');
}

/**
 * Submit new category
 */
async function submitCategory(event) {
    event.preventDefault();
    
    const form = document.getElementById('categoryForm');
    const formData = new FormData(form);
    
    if (!formData.get('name')) {
        alert('กรุณาระบุชื่อหมวดหมู่');
        return;
    }
    
    try {
        const categoryData = {
            action: 'category_create',
            name: formData.get('name'),
            name_en: formData.get('name_en') || null,
            expense_type: formData.get('expense_type')
        };
        
        const response = await fetch('api/accounting.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(categoryData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Reload page to show new category
            window.location.href = 'accounting.php?tab=expenses&success=created';
        } else {
            alert(data.message || 'เกิดข้อผิดพลาด');
        }
    } catch (error) {
        console.error('Error creating category:', error);
        alert('เกิดข้อผิดพลาดในการสร้างหมวดหมู่');
    }
}

/**
 * Delete category
 */
async function deleteCategory(id, name) {
    if (!confirm(`ต้องการลบหมวดหมู่ "${name}" หรือไม่?\n\nหมายเหตุ: ไม่สามารถลบหมวดหมู่ที่มีค่าใช้จ่ายอยู่ได้`)) {
        return;
    }
    
    try {
        const response = await fetch(`api/accounting.php?action=category_delete&id=${id}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            window.location.href = 'accounting.php?tab=expenses&success=deleted';
        } else {
            alert(data.message || 'ไม่สามารถลบหมวดหมู่ได้');
        }
    } catch (error) {
        console.error('Error deleting category:', error);
        alert('เกิดข้อผิดพลาดในการลบหมวดหมู่');
    }
}

/**
 * Format date to Thai format
 */
function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
</script>
