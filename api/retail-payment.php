<?php
/**
 * Retail Payment Callback API
 * รับ webhook/callback จากระบบชำระเงิน (PromptPay, LINE Pay)
 * - อัพเดทสถานะการชำระเงิน
 * - ตัด stock (สำหรับ PromptPay/LINE Pay ที่ไม่ได้ตัดตอนสร้างออเดอร์)
 * - ส่งแจ้งเตือนไปยังลูกค้า
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$action = $input['action'] ?? $_GET['action'] ?? 'verify';

// ============================================================
// VERIFY PAYMENT (Check status from bank/payment gateway)
// ============================================================
if ($action === 'verify') {
    $orderId = $input['order_id'] ?? null;
    $paymentRef = $input['payment_ref'] ?? null;
    $slipImage = $input['slip_image'] ?? null; // Base64 or URL
    
    if (!$orderId) {
        echo json_encode(['success' => false, 'error' => 'Order ID required']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Get order info
        $orderStmt = $db->prepare("
            SELECT * FROM retail_orders WHERE id = ? AND status = 'pending_payment'
        ");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Order not found or already processed']);
            exit;
        }
        
        // Get payment record
        $paymentStmt = $db->prepare("
            SELECT * FROM retail_payments WHERE order_id = ? AND status = 'pending'
        ");
        $paymentStmt->execute([$orderId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Payment record not found']);
            exit;
        }
        
        // TODO: Verify payment with bank/gateway API
        // For now, auto-approve if slip is provided or reference matches
        $isValid = true; // Placeholder - implement actual verification
        
        if (!$isValid) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Payment verification failed']);
            exit;
        }
        
        // Update payment status
        $updatePaymentStmt = $db->prepare("
            UPDATE retail_payments 
            SET status = 'completed', 
                completed_at = NOW(),
                slip_image_url = ?,
                slip_verified_at = NOW()
            WHERE id = ?
        ");
        $updatePaymentStmt->execute([$slipImage, $payment['id']]);
        
        // Update order status
        $updateOrderStmt = $db->prepare("
            UPDATE retail_orders 
            SET status = 'confirmed',
                payment_status = 'paid',
                paid_at = NOW()
            WHERE id = ?
        ");
        $updateOrderStmt->execute([$orderId]);
        
        // Deduct stock (for PromptPay/LINE Pay that wasn't deducted at checkout)
        $itemsStmt = $db->prepare("
            SELECT * FROM retail_order_items 
            WHERE order_id = ? AND deducted_from_stock = FALSE
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $item) {
            // Deduct stock
            $deductStmt = $db->prepare("
                UPDATE retail_product_stock 
                SET qty_available = GREATEST(0, qty_available - ?),
                    last_sale_at = NOW()
                WHERE product_id = ?
            ");
            $deductStmt->execute([$item['qty'], $item['product_id']]);
            
            // Mark as deducted
            $markStmt = $db->prepare("
                UPDATE retail_order_items 
                SET deducted_from_stock = TRUE, stock_deducted_at = NOW()
                WHERE id = ?
            ");
            $markStmt->execute([$item['id']]);
            
            // Log stock movement
            $logStmt = $db->prepare("
                INSERT INTO retail_stock_movements (
                    product_id, movement_type, qty, before_qty, after_qty,
                    reference_type, reference_id, reference_number, notes
                )
                SELECT 
                    ?, 'out', ?, qty_available + ?, qty_available,
                    'order', ?, ?, 'Payment confirmed - stock deducted'
                FROM retail_product_stock
                WHERE product_id = ?
            ");
            $logStmt->execute([
                $item['product_id'],
                $item['qty'],
                $item['qty'],
                $orderId,
                $order['order_number'],
                $item['product_id']
            ]);
        }
        
        // Update customer stats
        $updateCustomerStmt = $db->prepare("
            UPDATE retail_customers 
            SET total_spent = total_spent + ?,
                order_count = order_count + 1,
                points_balance = points_balance + FLOOR(? / 100)
            WHERE line_user_id = ?
        ");
        $updateCustomerStmt->execute([
            $order['total_amount'],
            $order['total_amount'],
            $order['line_user_id']
        ]);
        
        // Update product order counts
        foreach ($items as $item) {
            $updateProductStmt = $db->prepare("
                UPDATE retail_products 
                SET order_count = order_count + ?
                WHERE id = ?
            ");
            $updateProductStmt->execute([$item['qty'], $item['product_id']]);
        }
        
        $db->commit();
        
        // TODO: Send notification to LINE
        // sendPaymentConfirmationNotification($order);
        
        echo json_encode([
            'success' => true,
            'message' => 'Payment verified and order confirmed',
            'order' => [
                'id' => $orderId,
                'order_number' => $order['order_number'],
                'status' => 'confirmed'
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// CHECK PAYMENT STATUS
// ============================================================
if ($action === 'status') {
    $orderId = $_GET['order_id'] ?? null;
    
    if (!$orderId) {
        echo json_encode(['success' => false, 'error' => 'Order ID required']);
        exit;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 
                o.id, o.order_number, o.status, o.payment_status, o.total_amount,
                p.status as payment_status_detail,
                p.promptpay_qr_url,
                p.completed_at as paid_at
            FROM retail_orders o
            LEFT JOIN retail_payments p ON o.id = p.order_id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            echo json_encode(['success' => false, 'error' => 'Order not found']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'order' => $result
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// UPLOAD SLIP IMAGE
// ============================================================
if ($action === 'upload_slip') {
    $orderId = $input['order_id'] ?? null;
    $slipData = $input['slip_image'] ?? null; // Base64 image
    
    if (!$orderId || !$slipData) {
        echo json_encode(['success' => false, 'error' => 'Order ID and slip image required']);
        exit;
    }
    
    try {
        // Save slip image
        $uploadDir = __DIR__ . '/../uploads/payment_slips/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = 'slip_' . $orderId . '_' . time() . '.png';
        $filepath = $uploadDir . $filename;
        
        // Decode base64 and save
        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $slipData));
        file_put_contents($filepath, $imageData);
        
        // Update payment record
        $slipUrl = '/uploads/payment_slips/' . $filename;
        $updateStmt = $db->prepare("
            UPDATE retail_payments 
            SET slip_image_url = ?, status = 'processing'
            WHERE order_id = ?
        ");
        $updateStmt->execute([$slipUrl, $orderId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Slip uploaded successfully, awaiting verification',
            'slip_url' => $slipUrl
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
