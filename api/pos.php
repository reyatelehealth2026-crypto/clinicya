<?php
/**
 * POS API Endpoint
 * 
 * Handles all POS operations including:
 * - Cart operations (add, update, remove)
 * - Customer search and selection
 * - Discount application
 * - Payment processing
 * - Transaction completion
 * - Shift management
 * - Returns
 * - Reports
 * 
 * Requirements: 1.1-1.6, 2.1-2.4, 3.1-3.5, 4.1-4.7, 7.1-7.5, 8.1-8.5, 12.1-12.10
 */

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Check authentication
if (!isset($_SESSION['admin_user']) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit;
}
require_once __DIR__ . '/../classes/POSService.php';
require_once __DIR__ . '/../classes/POSPaymentService.php';
require_once __DIR__ . '/../classes/POSShiftService.php';
require_once __DIR__ . '/../classes/POSReturnService.php';
require_once __DIR__ . '/../classes/POSReceiptService.php';

// Optional services
if (file_exists(__DIR__ . '/../classes/InventoryService.php')) {
    require_once __DIR__ . '/../classes/InventoryService.php';
}
if (file_exists(__DIR__ . '/../classes/BatchService.php')) {
    require_once __DIR__ . '/../classes/BatchService.php';
}
if (file_exists(__DIR__ . '/../classes/LoyaltyPoints.php')) {
    require_once __DIR__ . '/../classes/LoyaltyPoints.php';
}

try {
    $db = Database::getInstance()->getConnection();
    $lineAccountId = $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 1;
    $userId = $_SESSION['admin_user']['id'] ?? $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    
    // Initialize services
    $posService = new POSService($db, $lineAccountId);
    $paymentService = new POSPaymentService($db, $lineAccountId);
    $shiftService = new POSShiftService($db, $lineAccountId);
    $returnService = new POSReturnService($db, $lineAccountId);
    $receiptService = new POSReceiptService($db, $lineAccountId);
    
    // Set optional services
    if (class_exists('InventoryService')) {
        $inventoryService = new InventoryService($db, $lineAccountId);
        $posService->setInventoryService($inventoryService);
        $returnService->setInventoryService($inventoryService);
    }
    
    if (class_exists('BatchService')) {
        $batchService = new BatchService($db, $lineAccountId);
        $posService->setBatchService($batchService);
        $returnService->setBatchService($batchService);
    }
    
    if (class_exists('LoyaltyPoints')) {
        $loyaltyPoints = new LoyaltyPoints($db, $lineAccountId);
        $posService->setLoyaltyPoints($loyaltyPoints);
        $paymentService->setLoyaltyPoints($loyaltyPoints);
        $returnService->setLoyaltyPoints($loyaltyPoints);
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    switch ($method) {
        case 'GET':
            $response = handleGet($action, $posService, $shiftService, $returnService, $receiptService, $userId);
            break;
            
        case 'POST':
            $response = handlePost($action, $input, $posService, $paymentService, $shiftService, $returnService, $receiptService, $userId);
            break;
            
        case 'PUT':
            $response = handlePut($action, $input, $posService, $shiftService);
            break;
            
        case 'DELETE':
            $response = handleDelete($action, $posService, $returnService);
            break;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Handle GET requests
 */
function handleGet($action, $posService, $shiftService, $returnService, $receiptService, $userId) {
    switch ($action) {
        // =========================================
        // Product Search
        // =========================================
        case 'search_products':
            try {
                $query = $_GET['q'] ?? '';
                if (strlen($query) < 1) {
                    return ['success' => true, 'data' => []];
                }
                $products = $posService->searchProducts($query);
                return ['success' => true, 'data' => $products];
            } catch (Exception $e) {
                error_log("search_products error: " . $e->getMessage());
                return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการค้นหา', 'data' => []];
            }
            
        // =========================================
        // Customer Search
        // =========================================
        case 'search_customers':
            $query = $_GET['q'] ?? '';
            if (strlen($query) < 2) {
                return ['success' => false, 'message' => 'กรุณาระบุคำค้นหาอย่างน้อย 2 ตัวอักษร'];
            }
            $customers = $posService->searchCustomers($query);
            return ['success' => true, 'data' => $customers];
            
        // =========================================
        // Transaction
        // =========================================
        case 'get_transaction':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $transaction = $posService->getTransaction($id);
            if (!$transaction) {
                return ['success' => false, 'message' => 'ไม่พบรายการ'];
            }
            return ['success' => true, 'data' => $transaction];
            
        case 'transaction_history':
            $filters = [
                'shift_id' => $_GET['shift_id'] ?? null,
                'date' => $_GET['date'] ?? null,
                'status' => $_GET['status'] ?? null,
                'search' => $_GET['search'] ?? null,
                'limit' => $_GET['limit'] ?? 100
            ];
            $transactions = $posService->getTransactionHistory(array_filter($filters));
            return ['success' => true, 'data' => $transactions];
            
        // =========================================
        // Hold/Recall
        // =========================================
        case 'held_transactions':
            $shiftId = $_GET['shift_id'] ?? null;
            $transactions = $posService->getHeldTransactions($shiftId ? (int)$shiftId : null);
            return ['success' => true, 'data' => $transactions];
            
        case 'find_transaction':
            $number = $_GET['number'] ?? '';
            if (!$number) {
                return ['success' => false, 'message' => 'กรุณาระบุเลขที่บิล'];
            }
            $transaction = $posService->findTransactionByNumber($number);
            if (!$transaction) {
                return ['success' => false, 'message' => 'ไม่พบบิล'];
            }
            return ['success' => true, 'data' => $transaction];
            
        // =========================================
        // Cash Movements
        // =========================================
        case 'cash_movements':
            $shiftId = (int)($_GET['shift_id'] ?? 0);
            if (!$shiftId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID กะ'];
            }
            $movements = $posService->getCashMovements($shiftId);
            return ['success' => true, 'data' => $movements];
            
        // =========================================
        // Shift
        // =========================================
        case 'current_shift':
            if (!$userId) {
                return ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'];
            }
            $shift = $shiftService->getCurrentShift($userId);
            return ['success' => true, 'data' => $shift];
            
        case 'get_shift':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $shift = $shiftService->getShift($id);
            return ['success' => true, 'data' => $shift];
            
        case 'shift_summary':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $summary = $shiftService->getShiftSummary($id);
            return ['success' => true, 'data' => $summary];
            
        case 'shifts':
            $filters = [
                'date' => $_GET['date'] ?? null,
                'cashier_id' => $_GET['cashier_id'] ?? null,
                'status' => $_GET['status'] ?? null,
                'limit' => $_GET['limit'] ?? 50
            ];
            $shifts = $shiftService->getShifts(array_filter($filters));
            return ['success' => true, 'data' => $shifts];
            
        // =========================================
        // Returns
        // =========================================
        case 'find_receipt':
            $receiptNumber = $_GET['receipt'] ?? '';
            if (!$receiptNumber) {
                return ['success' => false, 'message' => 'กรุณาระบุเลขที่ใบเสร็จ'];
            }
            $transaction = $returnService->findTransaction($receiptNumber);
            if (!$transaction) {
                return ['success' => false, 'message' => 'ไม่พบใบเสร็จ'];
            }
            return ['success' => true, 'data' => $transaction];
            
        case 'get_return':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $return = $returnService->getReturn($id);
            return ['success' => true, 'data' => $return];
            
        case 'returns':
            $filters = [
                'date' => $_GET['date'] ?? null,
                'status' => $_GET['status'] ?? null,
                'shift_id' => $_GET['shift_id'] ?? null,
                'limit' => $_GET['limit'] ?? 50
            ];
            $returns = $returnService->getReturns(array_filter($filters));
            return ['success' => true, 'data' => $returns];
            
        // =========================================
        // Receipt
        // =========================================
        case 'receipt':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $receipt = $receiptService->generateReceipt($id);
            return ['success' => true, 'data' => $receipt];
            
        case 'receipt_html':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $html = $receiptService->getReceiptHTML($id);
            return ['success' => true, 'data' => ['html' => $html]];
            
        case 'return_receipt':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $receipt = $receiptService->generateReturnReceipt($id);
            return ['success' => true, 'data' => $receipt];
            
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}


/**
 * Handle POST requests
 */
function handlePost($action, $input, $posService, $paymentService, $shiftService, $returnService, $receiptService, $userId) {
    switch ($action) {
        // =========================================
        // Transaction
        // =========================================
        case 'create_transaction':
            if (!$userId) {
                return ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'];
            }
            $customerId = $input['customer_id'] ?? null;
            $transaction = $posService->createTransaction($userId, $customerId);
            return ['success' => true, 'data' => $transaction, 'message' => 'สร้างรายการขายสำเร็จ'];
            
        case 'complete_transaction':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $payments = $input['payments'] ?? [];
            if (!$transactionId || empty($payments)) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $transaction = $posService->completeTransaction($transactionId, $payments);
            return ['success' => true, 'data' => $transaction, 'message' => 'ชำระเงินสำเร็จ'];
            
        case 'void_transaction':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $reason = $input['reason'] ?? '';
            $authorizedBy = (int)($input['authorized_by'] ?? $userId);
            if (!$transactionId || !$reason) {
                return ['success' => false, 'message' => 'กรุณาระบุเหตุผลในการยกเลิก'];
            }
            $posService->voidTransaction($transactionId, $reason, $authorizedBy);
            return ['success' => true, 'message' => 'ยกเลิกรายการสำเร็จ'];
            
        // =========================================
        // Cart Operations
        // =========================================
        case 'add_to_cart':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $productId = (int)($input['product_id'] ?? 0);
            $quantity = (int)($input['quantity'] ?? 1);
            if (!$transactionId || !$productId) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $item = $posService->addToCart($transactionId, $productId, $quantity);
            $transaction = $posService->getTransaction($transactionId);
            return ['success' => true, 'data' => ['item' => $item, 'transaction' => $transaction], 'message' => 'เพิ่มสินค้าสำเร็จ'];
            
        case 'update_cart_item':
            $itemId = (int)($input['item_id'] ?? 0);
            $quantity = (int)($input['quantity'] ?? 0);
            if (!$itemId || $quantity < 1) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $item = $posService->updateCartItem($itemId, $quantity);
            return ['success' => true, 'data' => $item, 'message' => 'อัพเดทสำเร็จ'];
            
        case 'remove_cart_item':
            $itemId = (int)($input['item_id'] ?? 0);
            if (!$itemId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $posService->removeFromCart($itemId);
            return ['success' => true, 'message' => 'ลบสินค้าสำเร็จ'];
            
        // =========================================
        // Discounts
        // =========================================
        case 'apply_item_discount':
            $itemId = (int)($input['item_id'] ?? 0);
            $type = $input['type'] ?? 'percent';
            $value = (float)($input['value'] ?? 0);
            if (!$itemId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $item = $posService->applyItemDiscount($itemId, $type, $value);
            return ['success' => true, 'data' => $item, 'message' => 'ใช้ส่วนลดสำเร็จ'];
            
        case 'apply_bill_discount':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $type = $input['type'] ?? 'percent';
            $value = (float)($input['value'] ?? 0);
            if (!$transactionId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $transaction = $posService->applyBillDiscount($transactionId, $type, $value);
            return ['success' => true, 'data' => $transaction, 'message' => 'ใช้ส่วนลดสำเร็จ'];
            
        // =========================================
        // Customer
        // =========================================
        case 'set_customer':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $customerId = (int)($input['customer_id'] ?? 0);
            if (!$transactionId || !$customerId) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $transaction = $posService->setCustomer($transactionId, $customerId);
            return ['success' => true, 'data' => $transaction, 'message' => 'เลือกลูกค้าสำเร็จ'];
            
        // =========================================
        // Hold/Recall Transaction
        // =========================================
        case 'hold_transaction':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $note = $input['note'] ?? '';
            if (!$transactionId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $transaction = $posService->holdTransaction($transactionId, $note);
            return ['success' => true, 'data' => $transaction, 'message' => 'พักบิลสำเร็จ'];
            
        case 'recall_transaction':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            if (!$transactionId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $transaction = $posService->recallTransaction($transactionId);
            return ['success' => true, 'data' => $transaction, 'message' => 'เรียกบิลกลับสำเร็จ'];
            
        case 'delete_held_transaction':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            if (!$transactionId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $posService->deleteHeldTransaction($transactionId);
            return ['success' => true, 'message' => 'ลบบิลที่พักไว้สำเร็จ'];
            
        // =========================================
        // Price Override
        // =========================================
        case 'override_price':
            $itemId = (int)($input['item_id'] ?? 0);
            $newPrice = (float)($input['new_price'] ?? 0);
            $reason = $input['reason'] ?? '';
            $authorizedBy = $input['authorized_by'] ?? $userId;
            if (!$itemId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $item = $posService->overrideItemPrice($itemId, $newPrice, $reason, $authorizedBy);
            return ['success' => true, 'data' => $item, 'message' => 'แก้ไขราคาสำเร็จ'];
            
        // =========================================
        // Cash Drawer Operations
        // =========================================
        case 'cash_in':
            if (!$userId) {
                return ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'];
            }
            $shiftId = (int)($input['shift_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $reason = $input['reason'] ?? '';
            if (!$shiftId || !$amount) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $movement = $posService->recordCashMovement($shiftId, 'in', $amount, $reason, $userId);
            return ['success' => true, 'data' => $movement, 'message' => 'บันทึกเงินเข้าสำเร็จ'];
            
        case 'cash_out':
            if (!$userId) {
                return ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'];
            }
            $shiftId = (int)($input['shift_id'] ?? 0);
            $amount = (float)($input['amount'] ?? 0);
            $reason = $input['reason'] ?? '';
            if (!$shiftId || !$amount) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $movement = $posService->recordCashMovement($shiftId, 'out', $amount, $reason, $userId);
            return ['success' => true, 'data' => $movement, 'message' => 'บันทึกเงินออกสำเร็จ'];
            
        // =========================================
        // Reprint Receipt
        // =========================================
        case 'reprint_receipt':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            if (!$transactionId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $posService->logReceiptReprint($transactionId, $userId);
            $html = $receiptService->getReceiptHTML($transactionId);
            return ['success' => true, 'data' => ['html' => $html], 'message' => 'พิมพ์ใบเสร็จซ้ำสำเร็จ'];
            
        // =========================================
        // Payment
        // =========================================
        case 'process_payment':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $paymentData = $input['payment'] ?? [];
            if (!$transactionId || empty($paymentData)) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $result = $paymentService->processPayment($transactionId, $paymentData);
            return ['success' => true, 'data' => $result, 'message' => 'บันทึกการชำระเงินสำเร็จ'];
            
        case 'calculate_points_redemption':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $points = (int)($input['points'] ?? 0);
            if (!$transactionId || !$points) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $result = $paymentService->processPointsRedemption($transactionId, $points);
            return ['success' => true, 'data' => $result];
            
        case 'calculate_change':
            $total = (float)($input['total'] ?? 0);
            $received = (float)($input['received'] ?? 0);
            $change = $paymentService->calculateChange($total, $received);
            return ['success' => true, 'data' => ['change' => $change]];
            
        // =========================================
        // Shift
        // =========================================
        case 'open_shift':
            if (!$userId) {
                return ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'];
            }
            $openingCash = (float)($input['opening_cash'] ?? 0);
            $shift = $shiftService->openShift($userId, $openingCash);
            return ['success' => true, 'data' => $shift, 'message' => 'เปิดกะสำเร็จ'];
            
        case 'close_shift':
            $shiftId = (int)($input['shift_id'] ?? 0);
            $closingCash = (float)($input['closing_cash'] ?? 0);
            if (!$shiftId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID กะ'];
            }
            $summary = $shiftService->closeShift($shiftId, $closingCash);
            return ['success' => true, 'data' => $summary, 'message' => 'ปิดกะสำเร็จ'];
            
        case 'calculate_variance':
            $shiftId = (int)($input['shift_id'] ?? 0);
            $actualCash = (float)($input['actual_cash'] ?? 0);
            if (!$shiftId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID กะ'];
            }
            $variance = $shiftService->calculateVariance($shiftId, $actualCash);
            return ['success' => true, 'data' => $variance];
            
        // =========================================
        // Returns
        // =========================================
        case 'create_return':
            if (!$userId) {
                return ['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ'];
            }
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $items = $input['items'] ?? [];
            $reason = $input['reason'] ?? '';
            if (!$transactionId || empty($items) || !$reason) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $return = $returnService->createReturn($transactionId, $items, $reason, $userId);
            return ['success' => true, 'data' => $return, 'message' => 'สร้างรายการคืนสินค้าสำเร็จ'];
            
        case 'process_return':
            $returnId = (int)($input['return_id'] ?? 0);
            $authorizedBy = $input['authorized_by'] ?? null;
            if (!$returnId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $return = $returnService->processReturn($returnId, $authorizedBy);
            return ['success' => true, 'data' => $return, 'message' => 'ดำเนินการคืนสินค้าสำเร็จ'];
            
        case 'cancel_return':
            $returnId = (int)($input['return_id'] ?? 0);
            if (!$returnId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $returnService->cancelReturn($returnId);
            return ['success' => true, 'message' => 'ยกเลิกรายการคืนสินค้าสำเร็จ'];
            
        case 'process_refund':
            $returnId = (int)($input['return_id'] ?? 0);
            $method = $input['method'] ?? 'cash';
            if (!$returnId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $result = $paymentService->processRefund($returnId, $method);
            return ['success' => true, 'data' => $result, 'message' => 'คืนเงินสำเร็จ'];
            
        // =========================================
        // Receipt
        // =========================================
        case 'print_receipt':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            if (!$transactionId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $receiptService->printReceipt($transactionId);
            return ['success' => true, 'message' => 'พิมพ์ใบเสร็จสำเร็จ'];
            
        case 'send_line_receipt':
            $transactionId = (int)($input['transaction_id'] ?? 0);
            $lineUserId = $input['line_user_id'] ?? '';
            if (!$transactionId || !$lineUserId) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $receiptService->sendLineReceipt($transactionId, $lineUserId);
            return ['success' => true, 'message' => 'ส่งใบเสร็จทาง LINE สำเร็จ'];
            
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

/**
 * Handle PUT requests
 */
function handlePut($action, $input, $posService, $shiftService) {
    switch ($action) {
        case 'update_cart_item':
            $itemId = (int)($input['item_id'] ?? 0);
            $quantity = (int)($input['quantity'] ?? 0);
            if (!$itemId || $quantity < 1) {
                return ['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน'];
            }
            $item = $posService->updateCartItem($itemId, $quantity);
            return ['success' => true, 'data' => $item];
            
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}

/**
 * Handle DELETE requests
 */
function handleDelete($action, $posService, $returnService) {
    switch ($action) {
        case 'remove_cart_item':
            $itemId = (int)($_GET['id'] ?? 0);
            if (!$itemId) {
                return ['success' => false, 'message' => 'กรุณาระบุ ID'];
            }
            $posService->removeFromCart($itemId);
            return ['success' => true, 'message' => 'ลบสินค้าสำเร็จ'];
            
        default:
            return ['success' => false, 'message' => 'Invalid action'];
    }
}
