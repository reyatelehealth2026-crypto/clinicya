<?php
/**
 * Accounting API - จัดการบัญชีรายรับ-รายจ่าย
 * 
 * Endpoints:
 * - AP: list, detail, record_payment
 * - AR: list, detail, record_receipt
 * - Expense: list, create, update, delete
 * - Dashboard: summary
 * 
 * Requirements: 1.2, 2.2, 3.2, 6.1
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AccountPayableService.php';
require_once __DIR__ . '/../classes/AccountReceivableService.php';
require_once __DIR__ . '/../classes/ExpenseService.php';
require_once __DIR__ . '/../classes/ExpenseCategoryService.php';
require_once __DIR__ . '/../classes/AccountingDashboardService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

// Initialize services
$apService = new AccountPayableService($db, $lineAccountId);
$arService = new AccountReceivableService($db, $lineAccountId);
$expenseService = new ExpenseService($db, $lineAccountId);
$categoryService = new ExpenseCategoryService($db, $lineAccountId);
$dashboardService = new AccountingDashboardService($db, $lineAccountId);

// Get action from request
$action = $_REQUEST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

try {
    switch ($action) {
        // ==================== Account Payable (AP) ====================
        case 'ap_list':
            handleApList($apService);
            break;
            
        case 'ap_detail':
            handleApDetail($apService);
            break;
            
        case 'ap_record_payment':
            handleApRecordPayment($apService, $adminId);
            break;

        case 'ap_aging':
            handleApAging($apService);
            break;
            
        case 'ap_overdue':
            handleApOverdue($apService);
            break;
            
        case 'ap_upcoming':
            handleApUpcoming($apService);
            break;
            
        // ==================== Account Receivable (AR) ====================
        case 'ar_list':
            handleArList($arService);
            break;
            
        case 'ar_detail':
            handleArDetail($arService);
            break;
            
        case 'ar_record_receipt':
            handleArRecordReceipt($arService, $adminId);
            break;
            
        case 'ar_aging':
            handleArAging($arService);
            break;
            
        case 'ar_overdue':
            handleArOverdue($arService);
            break;
            
        case 'ar_upcoming':
            handleArUpcoming($arService);
            break;
            
        // ==================== Expenses ====================
        case 'expense_list':
            handleExpenseList($expenseService);
            break;
            
        case 'expense_detail':
            handleExpenseDetail($expenseService);
            break;
            
        case 'expense_create':
            handleExpenseCreate($expenseService, $adminId);
            break;
            
        case 'expense_update':
            handleExpenseUpdate($expenseService);
            break;
            
        case 'expense_delete':
            handleExpenseDelete($expenseService);
            break;
            
        case 'expense_monthly':
            handleExpenseMonthlySummary($expenseService);
            break;
            
        // ==================== Expense Categories ====================
        case 'category_list':
            handleCategoryList($categoryService);
            break;
            
        case 'category_create':
            handleCategoryCreate($categoryService);
            break;
            
        case 'category_update':
            handleCategoryUpdate($categoryService);
            break;
            
        case 'category_delete':
            handleCategoryDelete($categoryService);
            break;
            
        // ==================== Dashboard ====================
        case 'dashboard_summary':
            handleDashboardSummary($dashboardService);
            break;
            
        case 'dashboard_full':
            handleDashboardFull($dashboardService);
            break;
            
        case 'dashboard_upcoming':
            handleDashboardUpcoming($dashboardService);
            break;
            
        case 'dashboard_overdue':
            handleDashboardOverdue($dashboardService);
            break;
            
        case 'dashboard_aging':
            handleDashboardAging($dashboardService);
            break;
            
        case 'dashboard_cashflow':
            handleDashboardCashflow($dashboardService);
            break;
            
        default:
            jsonResponse(false, 'Invalid action: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage());
}


// ==================== AP Handler Functions ====================

/**
 * Handle AP list request
 * Requirement 1.2: Display all outstanding payables sorted by due date
 */
function handleApList($apService) {
    $filters = [
        'status' => $_GET['status'] ?? null,
        'supplier_id' => $_GET['supplier_id'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
        'search' => $_GET['search'] ?? null,
        'sort_by' => $_GET['sort_by'] ?? 'due_date',
        'sort_order' => $_GET['sort_order'] ?? 'ASC',
        'limit' => $_GET['limit'] ?? 100,
        'offset' => $_GET['offset'] ?? 0
    ];
    
    // Remove null values
    $filters = array_filter($filters, fn($v) => $v !== null);
    
    $records = $apService->getAll($filters);
    $totalOutstanding = $apService->getTotalOutstanding();
    $totalOverdue = $apService->getTotalOverdue();
    
    jsonResponse(true, 'OK', [
        'records' => $records,
        'count' => count($records),
        'total_outstanding' => $totalOutstanding,
        'total_overdue' => $totalOverdue
    ]);
}

/**
 * Handle AP detail request
 */
function handleApDetail($apService) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(false, 'AP ID is required');
    }
    
    $record = $apService->getById($id);
    if (!$record) {
        jsonResponse(false, 'Account Payable not found');
    }
    
    jsonResponse(true, 'OK', ['record' => $record]);
}

/**
 * Handle AP payment recording
 * Requirement 1.3: Record payment to supplier, create Payment Voucher
 */
function handleApRecordPayment($apService, $adminId) {
    $data = getRequestData();
    
    $apId = (int)($data['ap_id'] ?? 0);
    if (!$apId) {
        jsonResponse(false, 'AP ID is required');
    }
    
    // Validate required payment fields
    if (empty($data['amount']) || $data['amount'] <= 0) {
        jsonResponse(false, 'Valid payment amount is required');
    }
    if (empty($data['payment_method'])) {
        jsonResponse(false, 'Payment method is required');
    }
    
    $paymentData = [
        'amount' => (float)$data['amount'],
        'payment_method' => $data['payment_method'],
        'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
        'bank_account' => $data['bank_account'] ?? null,
        'reference_number' => $data['reference_number'] ?? null,
        'cheque_number' => $data['cheque_number'] ?? null,
        'cheque_date' => $data['cheque_date'] ?? null,
        'attachment_path' => $data['attachment_path'] ?? null,
        'notes' => $data['notes'] ?? null,
        'created_by' => $adminId
    ];
    
    $voucherId = $apService->recordPayment($apId, $paymentData);
    $updatedRecord = $apService->getById($apId);
    
    jsonResponse(true, 'Payment recorded successfully', [
        'voucher_id' => $voucherId,
        'record' => $updatedRecord
    ]);
}

/**
 * Handle AP aging report
 * Requirement 5.1: Display payables grouped by age brackets
 */
function handleApAging($apService) {
    $report = $apService->getAgingReport();
    jsonResponse(true, 'OK', ['aging_report' => $report]);
}

/**
 * Handle AP overdue list
 */
function handleApOverdue($apService) {
    $records = $apService->getOverdue();
    $total = $apService->getTotalOverdue();
    
    jsonResponse(true, 'OK', [
        'records' => $records,
        'count' => count($records),
        'total_overdue' => $total
    ]);
}

/**
 * Handle AP upcoming due list
 */
function handleApUpcoming($apService) {
    $days = (int)($_GET['days'] ?? 7);
    $records = $apService->getUpcomingDue($days);
    
    jsonResponse(true, 'OK', [
        'records' => $records,
        'count' => count($records),
        'days_ahead' => $days
    ]);
}


// ==================== AR Handler Functions ====================

/**
 * Handle AR list request
 * Requirement 2.2: Display all outstanding receivables sorted by due date
 */
function handleArList($arService) {
    $filters = [
        'status' => $_GET['status'] ?? null,
        'user_id' => $_GET['user_id'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
        'search' => $_GET['search'] ?? null,
        'sort_by' => $_GET['sort_by'] ?? 'due_date',
        'sort_order' => $_GET['sort_order'] ?? 'ASC',
        'limit' => $_GET['limit'] ?? 100,
        'offset' => $_GET['offset'] ?? 0
    ];
    
    // Remove null values
    $filters = array_filter($filters, fn($v) => $v !== null);
    
    $records = $arService->getAll($filters);
    $totalOutstanding = $arService->getTotalOutstanding();
    $totalOverdue = $arService->getTotalOverdue();
    
    jsonResponse(true, 'OK', [
        'records' => $records,
        'count' => count($records),
        'total_outstanding' => $totalOutstanding,
        'total_overdue' => $totalOverdue
    ]);
}

/**
 * Handle AR detail request
 */
function handleArDetail($arService) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(false, 'AR ID is required');
    }
    
    $record = $arService->getById($id);
    if (!$record) {
        jsonResponse(false, 'Account Receivable not found');
    }
    
    jsonResponse(true, 'OK', ['record' => $record]);
}

/**
 * Handle AR receipt recording
 * Requirement 2.3: Record receipt from customer, create Receipt Voucher
 */
function handleArRecordReceipt($arService, $adminId) {
    $data = getRequestData();
    
    $arId = (int)($data['ar_id'] ?? 0);
    if (!$arId) {
        jsonResponse(false, 'AR ID is required');
    }
    
    // Validate required receipt fields
    if (empty($data['amount']) || $data['amount'] <= 0) {
        jsonResponse(false, 'Valid receipt amount is required');
    }
    if (empty($data['payment_method'])) {
        jsonResponse(false, 'Payment method is required');
    }
    
    $receiptData = [
        'amount' => (float)$data['amount'],
        'payment_method' => $data['payment_method'],
        'receipt_date' => $data['receipt_date'] ?? date('Y-m-d'),
        'bank_account' => $data['bank_account'] ?? null,
        'reference_number' => $data['reference_number'] ?? null,
        'slip_id' => $data['slip_id'] ?? null,
        'attachment_path' => $data['attachment_path'] ?? null,
        'notes' => $data['notes'] ?? null,
        'created_by' => $adminId
    ];
    
    $voucherId = $arService->recordReceipt($arId, $receiptData);
    $updatedRecord = $arService->getById($arId);
    
    jsonResponse(true, 'Receipt recorded successfully', [
        'voucher_id' => $voucherId,
        'record' => $updatedRecord
    ]);
}

/**
 * Handle AR aging report
 * Requirement 5.2: Display receivables grouped by age brackets
 */
function handleArAging($arService) {
    $report = $arService->getAgingReport();
    jsonResponse(true, 'OK', ['aging_report' => $report]);
}

/**
 * Handle AR overdue list
 */
function handleArOverdue($arService) {
    $records = $arService->getOverdue();
    $total = $arService->getTotalOverdue();
    
    jsonResponse(true, 'OK', [
        'records' => $records,
        'count' => count($records),
        'total_overdue' => $total
    ]);
}

/**
 * Handle AR upcoming due list
 */
function handleArUpcoming($arService) {
    $days = (int)($_GET['days'] ?? 7);
    $records = $arService->getUpcomingDue($days);
    
    jsonResponse(true, 'OK', [
        'records' => $records,
        'count' => count($records),
        'days_ahead' => $days
    ]);
}


// ==================== Expense Handler Functions ====================

/**
 * Handle expense list request
 * Requirement 3.2: Display expenses filterable by category, date range, and payment status
 */
function handleExpenseList($expenseService) {
    $filters = [
        'category_id' => $_GET['category_id'] ?? null,
        'date_from' => $_GET['date_from'] ?? null,
        'date_to' => $_GET['date_to'] ?? null,
        'payment_status' => $_GET['payment_status'] ?? null,
        'expense_type' => $_GET['expense_type'] ?? null,
        'search' => $_GET['search'] ?? null,
        'sort_by' => $_GET['sort_by'] ?? 'expense_date',
        'sort_order' => $_GET['sort_order'] ?? 'DESC',
        'limit' => $_GET['limit'] ?? 100,
        'offset' => $_GET['offset'] ?? 0
    ];
    
    // Remove null values
    $filters = array_filter($filters, fn($v) => $v !== null);
    
    $records = $expenseService->getAll($filters);
    $countByStatus = $expenseService->getCountByStatus();
    
    jsonResponse(true, 'OK', [
        'records' => $records,
        'count' => count($records),
        'summary' => $countByStatus
    ]);
}

/**
 * Handle expense detail request
 */
function handleExpenseDetail($expenseService) {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(false, 'Expense ID is required');
    }
    
    $record = $expenseService->getById($id);
    if (!$record) {
        jsonResponse(false, 'Expense not found');
    }
    
    jsonResponse(true, 'OK', ['record' => $record]);
}

/**
 * Handle expense creation
 * Requirement 3.1: Create expense record with category, amount, date, description
 */
function handleExpenseCreate($expenseService, $adminId) {
    $data = getRequestData();
    
    // Validate required fields
    if (empty($data['category_id'])) {
        jsonResponse(false, 'Category is required');
    }
    if (empty($data['amount']) || $data['amount'] <= 0) {
        jsonResponse(false, 'Valid amount is required');
    }
    if (empty($data['expense_date'])) {
        jsonResponse(false, 'Expense date is required');
    }
    
    $expenseData = [
        'category_id' => (int)$data['category_id'],
        'amount' => (float)$data['amount'],
        'expense_date' => $data['expense_date'],
        'due_date' => $data['due_date'] ?? null,
        'description' => $data['description'] ?? null,
        'vendor_name' => $data['vendor_name'] ?? null,
        'reference_number' => $data['reference_number'] ?? null,
        'attachment_path' => $data['attachment_path'] ?? null,
        'payment_status' => $data['payment_status'] ?? 'unpaid',
        'notes' => $data['notes'] ?? null,
        'metadata' => $data['metadata'] ?? null,
        'created_by' => $adminId
    ];
    
    $id = $expenseService->create($expenseData);
    $record = $expenseService->getById($id);
    
    jsonResponse(true, 'Expense created successfully', [
        'id' => $id,
        'record' => $record
    ]);
}

/**
 * Handle expense update
 */
function handleExpenseUpdate($expenseService) {
    $data = getRequestData();
    
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(false, 'Expense ID is required');
    }
    
    // Remove id from data to prevent issues
    unset($data['id']);
    unset($data['action']);
    
    $expenseService->update($id, $data);
    $record = $expenseService->getById($id);
    
    jsonResponse(true, 'Expense updated successfully', ['record' => $record]);
}

/**
 * Handle expense deletion
 */
function handleExpenseDelete($expenseService) {
    $id = (int)($_REQUEST['id'] ?? 0);
    if (!$id) {
        jsonResponse(false, 'Expense ID is required');
    }
    
    $expenseService->delete($id);
    
    jsonResponse(true, 'Expense deleted successfully');
}

/**
 * Handle monthly expense summary
 */
function handleExpenseMonthlySummary($expenseService) {
    $month = $_GET['month'] ?? date('Y-m');
    $summary = $expenseService->getMonthlySummary($month);
    
    jsonResponse(true, 'OK', $summary);
}


// ==================== Category Handler Functions ====================

/**
 * Handle category list request
 */
function handleCategoryList($categoryService) {
    $filters = [
        'is_active' => isset($_GET['is_active']) ? (int)$_GET['is_active'] : null,
        'expense_type' => $_GET['expense_type'] ?? null,
        'search' => $_GET['search'] ?? null
    ];
    
    // Remove null values
    $filters = array_filter($filters, fn($v) => $v !== null);
    
    $categories = $categoryService->getAll($filters);
    
    jsonResponse(true, 'OK', [
        'categories' => $categories,
        'count' => count($categories)
    ]);
}

/**
 * Handle category creation
 * Requirement 3.3: Create expense category with name, description, and default expense type
 */
function handleCategoryCreate($categoryService) {
    $data = getRequestData();
    
    if (empty($data['name'])) {
        jsonResponse(false, 'Category name is required');
    }
    
    $categoryData = [
        'name' => $data['name'],
        'name_en' => $data['name_en'] ?? null,
        'description' => $data['description'] ?? null,
        'expense_type' => $data['expense_type'] ?? 'operating'
    ];
    
    $id = $categoryService->create($categoryData);
    $category = $categoryService->getById($id);
    
    jsonResponse(true, 'Category created successfully', [
        'id' => $id,
        'category' => $category
    ]);
}

/**
 * Handle category update
 */
function handleCategoryUpdate($categoryService) {
    $data = getRequestData();
    
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    if (!$id) {
        jsonResponse(false, 'Category ID is required');
    }
    
    // Remove id from data
    unset($data['id']);
    unset($data['action']);
    
    $categoryService->update($id, $data);
    $category = $categoryService->getById($id);
    
    jsonResponse(true, 'Category updated successfully', ['category' => $category]);
}

/**
 * Handle category deletion
 */
function handleCategoryDelete($categoryService) {
    $id = (int)($_REQUEST['id'] ?? 0);
    if (!$id) {
        jsonResponse(false, 'Category ID is required');
    }
    
    $categoryService->delete($id);
    
    jsonResponse(true, 'Category deleted successfully');
}


// ==================== Dashboard Handler Functions ====================

/**
 * Handle dashboard summary request
 * Requirement 6.1: Display total AP, total AR, and net position
 */
function handleDashboardSummary($dashboardService) {
    $summary = $dashboardService->getSummary();
    jsonResponse(true, 'OK', ['summary' => $summary]);
}

/**
 * Handle full dashboard data request
 * Returns all dashboard data in a single call
 */
function handleDashboardFull($dashboardService) {
    $upcomingDays = (int)($_GET['upcoming_days'] ?? 7);
    $expenseMonth = $_GET['expense_month'] ?? date('Y-m');
    
    $data = $dashboardService->getDashboardData($upcomingDays, $expenseMonth);
    jsonResponse(true, 'OK', $data);
}

/**
 * Handle dashboard upcoming payments request
 * Requirement 6.2: Show upcoming payments due within 7 days
 */
function handleDashboardUpcoming($dashboardService) {
    $days = (int)($_GET['days'] ?? 7);
    $upcoming = $dashboardService->getUpcomingPayments($days);
    jsonResponse(true, 'OK', $upcoming);
}

/**
 * Handle dashboard overdue summary request
 * Requirement 6.3: Show overdue amounts for both AP and AR
 */
function handleDashboardOverdue($dashboardService) {
    $overdue = $dashboardService->getOverdueSummary();
    jsonResponse(true, 'OK', $overdue);
}

/**
 * Handle dashboard aging summary request
 */
function handleDashboardAging($dashboardService) {
    $aging = $dashboardService->getAgingSummary();
    jsonResponse(true, 'OK', ['aging' => $aging]);
}

/**
 * Handle dashboard cash flow projection request
 */
function handleDashboardCashflow($dashboardService) {
    $days = (int)($_GET['days'] ?? 30);
    $cashflow = $dashboardService->getCashFlowProjection($days);
    jsonResponse(true, 'OK', ['cashflow' => $cashflow]);
}

// ==================== Helper Functions ====================

/**
 * Get request data from POST body or form data
 */
function getRequestData(): array {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
            return $input ?: [];
        }
        
        return $_POST;
    }
    
    return $_GET;
}

/**
 * Send JSON response and exit
 */
function jsonResponse(bool $success, string $message, array $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        ...$data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
