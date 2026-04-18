<?php
/**
 * Retail Checkout API
 * สร้างออเดอร์จากตะกร้า
 * - Validate stock
 * - Create order
 * - Generate PromptPay QR / LINE Pay
 * - Reserve stock until payment
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
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

$lineUserId = $input['line_user_id'] ?? null;
$paymentMethod = $input['payment_method'] ?? 'promptpay'; // promptpay, linepay, cod
$shippingMethod = $input['shipping_method'] ?? 'kerry'; // kerry, flash, thaipost

// Shipping address
$shipping = $input['shipping'] ?? [];
$shippingName = $shipping['name'] ?? '';
$shippingPhone = $shipping['phone'] ?? '';
$shippingAddress = $shipping['address'] ?? '';
$shippingDistrict = $shipping['district'] ?? '';
$shippingCity = $shipping['city'] ?? '';
$shippingProvince = $shipping['province'] ?? '';
$shippingZip = $shipping['zip'] ?? '';

// Order notes
$customerNotes = $input['notes'] ?? '';
$isGift = $input['is_gift'] ?? false;
$giftMessage = $input['gift_message'] ?? '';

// Validation
if (!$lineUserId) {
    echo json_encode(['success' => false, 'error' => 'LINE user ID required']);
    exit;
}

if (!$shippingName || !$shippingPhone || !$shippingAddress) {
    echo json_encode(['success' => false, 'error' => 'Shipping address incomplete']);
    exit;
}

// Valid payment methods
$validPayments = ['promptpay', 'linepay', 'cod'];
if (!in_array($paymentMethod, $validPayments)) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment method']);
    exit;
}

// Valid shipping methods
$validShipping = ['kerry', 'flash', 'thaipost', 'grab'];
if (!in_array($shippingMethod, $validShipping)) {
    echo json_encode(['success' => false, 'error' => 'Invalid shipping method']);
    exit;
}

try {
    $db->beginTransaction();
    
    // ============================================================
    // 1. GET CART ITEMS
    // ============================================================
    $cartStmt = $db->prepare("
        SELECT 
            c.id as cart_id,
            c.qty,
            c.unit_price,
            c.notes,
            p.id as product_id,
            p.sku,
            p.name,
            p.name_en,
            p.odoo_id,
            p.thumbnail_url,
            COALESCE(s.qty_available, 0) as stock_available,
            COALESCE(s.qty_reserved, 0) as stock_reserved,
            GREATEST(0, COALESCE(s.qty_available, 0) - COALESCE(s.qty_reserved, 0)) as stock_sellable
        FROM retail_carts c
        JOIN retail_products p ON c.product_id = p.id
        LEFT JOIN retail_product_stock s ON p.id = s.product_id
        WHERE c.line_user_id = ?
        FOR UPDATE
    ");
    $cartStmt->execute([$lineUserId]);
    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($cartItems)) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Cart is empty']);
        exit;
    }
    
    // ============================================================
    // 2. VALIDATE STOCK
    // ============================================================
    $outOfStockItems = [];
    foreach ($cartItems as $item) {
        if ($item['qty'] > $item['stock_sellable']) {
            $outOfStockItems[] = [
                'product_id' => $item['product_id'],
                'name' => $item['name'],
                'requested' => $item['qty'],
                'available' => $item['stock_sellable']
            ];
        }
    }
    
    if (!empty($outOfStockItems)) {
        $db->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Some items are out of stock',
            'out_of_stock' => $outOfStockItems
        ]);
        exit;
    }
    
    // ============================================================
    // 3. CALCULATE TOTALS
    // ============================================================
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += $item['unit_price'] * $item['qty'];
    }
    
    // Get shipping fee from settings or calculate
    $shippingFee = calculateShippingFee($subtotal, $shippingMethod, $shippingProvince, $db);
    
    // Calculate discount (if any)
    $discountAmount = 0;
    $discountCode = $input['discount_code'] ?? null;
    if ($discountCode) {
        $discountAmount = calculateDiscount($subtotal, $discountCode, $db);
    }
    
    // Tax (VAT 7% if applicable)
    $taxAmount = 0; // Most OTC items may be tax-exempt
    
    $totalAmount = $subtotal + $shippingFee - $discountAmount + $taxAmount;
    
    // ============================================================
    // 3.5 CREATE OR UPDATE CUSTOMER
    // ============================================================
    $customerStmt = $db->prepare("
        INSERT INTO retail_customers (line_user_id, phone, first_name, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            phone = COALESCE(VALUES(phone), phone),
            first_name = COALESCE(VALUES(first_name), first_name),
            updated_at = NOW()
    ");
    $customerStmt->execute([$lineUserId, $shippingPhone, $shippingName]);
    
    // Determine order status based on payment method
    $orderStatus = ($paymentMethod === 'cod') ? 'confirmed' : 'pending_payment';
    $paymentStatus = ($paymentMethod === 'cod') ? 'pending' : 'pending';
    
    // ============================================================
    // 4. CREATE ORDER
    // ============================================================
    $orderStmt = $db->prepare("
        INSERT INTO retail_orders (
            line_user_id,
            customer_name,
            customer_phone,
            subtotal,
            discount_amount,
            discount_code,
            shipping_fee,
            tax_amount,
            total_amount,
            shipping_name,
            shipping_phone,
            shipping_address,
            shipping_district,
            shipping_city,
            shipping_province,
            shipping_zip,
            shipping_method,
            shipping_method_name,
            payment_method,
            payment_status,
            status,
            customer_notes,
            is_gift,
            gift_message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $shippingMethodName = getShippingMethodName($shippingMethod);
    $customerEmail = $input['email'] ?? '';
    
    $orderStmt->execute([
        $lineUserId,
        $shippingName,
        $shippingPhone,
        $subtotal,
        $discountAmount,
        $discountCode,
        $shippingFee,
        $taxAmount,
        $totalAmount,
        $shippingName,
        $shippingPhone,
        $shippingAddress,
        $shippingDistrict,
        $shippingCity,
        $shippingProvince,
        $shippingZip,
        $shippingMethod,
        $shippingMethodName,
        $paymentMethod,
        $paymentMethod === 'cod' ? 'pending' : 'pending',
        $paymentMethod === 'cod' ? 'confirmed' : 'pending_payment',
        $customerNotes,
        $isGift ? 1 : 0,
        $giftMessage
    ]);
    
    $orderId = $db->lastInsertId();
    
    // Get order number
    $orderNumStmt = $db->prepare("SELECT order_number FROM retail_orders WHERE id = ?");
    $orderNumStmt->execute([$orderId]);
    $orderNumber = $orderNumStmt->fetchColumn();
    
    // ============================================================
    // 5. CREATE ORDER ITEMS + HANDLE STOCK
    // ============================================================
    
    // For COD: deduct stock immediately
    // For PromptPay/LINE Pay: reserve stock, deduct after payment
    $shouldDeductStock = ($paymentMethod === 'cod');
    
    $itemStmt = $db->prepare("
        INSERT INTO retail_order_items (
            order_id,
            product_id,
            odoo_product_id,
            sku,
            name,
            name_en,
            thumbnail_url,
            unit_price,
            qty,
            subtotal,
            discount_amount,
            total,
            deducted_from_stock,
            stock_deducted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($cartItems as $item) {
        $itemSubtotal = $item['unit_price'] * $item['qty'];
        $itemTotal = $itemSubtotal;
        
        $itemStmt->execute([
            $orderId,
            $item['product_id'],
            $item['odoo_id'],
            $item['sku'],
            $item['name'],
            $item['name_en'],
            $item['thumbnail_url'],
            $item['unit_price'],
            $item['qty'],
            $itemSubtotal,
            0,
            $itemTotal,
            $shouldDeductStock ? 1 : 0,
            $shouldDeductStock ? date('Y-m-d H:i:s') : null
        ]);
        
        // Handle stock based on payment method
        if ($shouldDeductStock) {
            // COD: Deduct stock immediately
            $deductStmt = $db->prepare("
                UPDATE retail_product_stock 
                SET 
                    qty_available = qty_available - ?,
                    qty_reserved = GREATEST(0, qty_reserved - ?),
                    last_sale_at = NOW()
                WHERE product_id = ?
            ");
            $deductStmt->execute([$item['qty'], $item['qty'], $item['product_id']]);
            
            // Log stock movement
            $logStmt = $db->prepare("
                INSERT INTO retail_stock_movements (
                    product_id, movement_type, qty, before_qty, after_qty,
                    reference_type, reference_id, reference_number, notes
                )
                SELECT 
                    ?,
                    'out',
                    ?,
                    qty_available + ?,
                    qty_available,
                    'order',
                    ?,
                    ?,
                    ?
                FROM retail_product_stock
                WHERE product_id = ?
            ");
            $logStmt->execute([
                $item['product_id'],
                $item['qty'],
                $item['qty'],
                $orderId,
                $orderNumber,
                "Order: {$orderNumber} - {$item['name']}",
                $item['product_id']
            ]);
        } else {
            // PromptPay/LINE Pay: Keep reserved, will deduct after payment
            // Release cart reservation but keep order reservation
            $releaseStmt = $db->prepare("
                UPDATE retail_product_stock 
                SET qty_reserved = GREATEST(0, qty_reserved - ?)
                WHERE product_id = ?
            ");
            $releaseStmt->execute([$item['qty'], $item['product_id']]);
        }
    }
    
    // ============================================================
    // 6. CLEAR CART (all items for this user)
    // ============================================================
    $clearCartStmt = $db->prepare("DELETE FROM retail_carts WHERE line_user_id = ?");
    $clearCartStmt->execute([$lineUserId]);
    
    // ============================================================
    // 7. CREATE PAYMENT RECORD + GENERATE PAYMENT DATA
    // ============================================================
    $paymentData = null;
    
    if ($paymentMethod === 'promptpay') {
        $paymentData = generatePromptPayQR($orderId, $orderNumber, $totalAmount, $db);
    } elseif ($paymentMethod === 'linepay') {
        $paymentData = generateLinePayRequest($orderId, $orderNumber, $totalAmount, $input, $db);
    } elseif ($paymentMethod === 'cod') {
        // Cash on Delivery - no payment record needed yet
        $paymentData = [
            'type' => 'cod',
            'message' => 'ชำระเงินปลายทาง'
        ];
    }
    
    $db->commit();
    
    // ============================================================
    // 8. RETURN RESPONSE
    // ============================================================
    echo json_encode([
        'success' => true,
        'message' => 'Order created successfully',
        'order' => [
            'id' => $orderId,
            'order_number' => $orderNumber,
            'status' => $paymentMethod === 'cod' ? 'confirmed' : 'pending_payment',
            'payment_method' => $paymentMethod,
            'payment_status' => $paymentMethod === 'cod' ? 'pending' : 'pending',
            'total_amount' => $totalAmount,
            'total_amount_formatted' => '฿' . number_format($totalAmount, 2),
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'discount' => $discountAmount,
            'item_count' => count($cartItems),
            'created_at' => date('Y-m-d H:i:s')
        ],
        'payment' => $paymentData,
        'redirect_url' => "/liff/?mode=retail#/order/{$orderId}"
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Checkout error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Checkout failed: ' . $e->getMessage()
    ]);
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function calculateShippingFee($subtotal, $method, $province, $db) {
    // Get free shipping threshold
    $thresholdStmt = $db->prepare("
        SELECT setting_value FROM retail_settings 
        WHERE setting_key = 'shipping_free_threshold'
    ");
    $thresholdStmt->execute();
    $freeThreshold = floatval($thresholdStmt->fetchColumn() ?: 999);
    
    if ($subtotal >= $freeThreshold) {
        return 0;
    }
    
    // Base rates by method
    $rates = [
        'kerry' => 50,
        'flash' => 45,
        'thaipost' => 40,
        'grab' => 80
    ];
    
    // Remote area surcharge
    $remoteProvinces = ['เชียงใหม่', 'เชียงราย', 'น่าน', 'พะเยา', 'แพร่', 'แม่ฮ่องสอน', 'ลำปาง', 'ลำพูน'];
    $baseRate = $rates[$method] ?? 50;
    
    if (in_array($province, $remoteProvinces)) {
        $baseRate += 20;
    }
    
    return $baseRate;
}

function getShippingMethodName($method) {
    $names = [
        'kerry' => 'Kerry Express',
        'flash' => 'Flash Express',
        'thaipost' => 'Thailand Post',
        'grab' => 'Grab Express'
    ];
    return $names[$method] ?? $method;
}

function calculateDiscount($subtotal, $code, $db) {
    // TODO: Implement promo code logic
    // For now, return 0
    return 0;
}

function generatePromptPayQR($orderId, $orderNumber, $amount, $db) {
    // Get PromptPay settings
    $settingsStmt = $db->prepare("
        SELECT setting_key, setting_value 
        FROM retail_settings 
        WHERE setting_key IN ('promptpay_number', 'promptpay_name')
    ");
    $settingsStmt->execute();
    $settings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $ppNumber = $settings['promptpay_number'] ?? '';
    $ppName = $settings['promptpay_name'] ?? 'Re-Ya Pharmacy';
    
    if (!$ppNumber) {
        return [
            'type' => 'promptpay',
            'error' => 'PromptPay not configured',
            'message' => 'กรุณาติดต่อร้านค้าเพื่อชำระเงิน'
        ];
    }
    
    // Generate PromptPay payload (TLV format)
    $payload = generatePromptPayPayload($ppNumber, $amount, $orderNumber);
    
    // Create payment record
    $db->prepare("
        INSERT INTO retail_payments (
            order_id, order_number, payment_method, amount,
            promptpay_ref, promptpay_qr_data, promptpay_expires_at, status
        ) VALUES (?, ?, 'promptpay', ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), 'pending')
    ");
    $stmt = $db->prepare("
        INSERT INTO retail_payments (
            order_id, order_number, payment_method, amount,
            promptpay_ref, promptpay_qr_data, promptpay_expires_at, status
        ) VALUES (?, ?, 'promptpay', ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), 'pending')
    ");
    $stmt->execute([
        $orderId,
        $orderNumber,
        $amount,
        $orderNumber,
        $payload
    ]);
    
    return [
        'type' => 'promptpay',
        'promptpay_number' => $ppNumber,
        'promptpay_name' => $ppName,
        'amount' => $amount,
        'amount_formatted' => '฿' . number_format($amount, 2),
        'qr_payload' => $payload,
        'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        'instructions' => [
            '1. เปิดแอพธนาคาร',
            '2. สแกน QR Code',
            '3. ตรวจสอบยอดและยืนยัน',
            '4. รอการตรวจสอบ 1-5 นาที'
        ]
    ];
}

function generatePromptPayPayload($mobileNumber, $amount, $ref) {
    // Simple PromptPay payload generation
    // In production, use a proper TLV library
    $sanitizedNumber = preg_replace('/[^0-9]/', '', $mobileNumber);
    
    // AID for PromptPay
    $aid = '00020101021129370016A000000677010111';
    
    // Mobile number
    $mobileTag = strlen($sanitizedNumber) === 10 ? '01130066' : '0113' . strlen($sanitizedNumber);
    $mobileData = $mobileTag . $sanitizedNumber;
    
    // Transaction amount
    $amountStr = number_format($amount, 2, '.', '');
    $amountTag = '54' . str_pad(strlen($amountStr), 2, '0', STR_PAD_LEFT) . $amountStr;
    
    // Country code and currency
    $country = '5303764'; // Thailand
    $currency = '5803THB';
    
    // Reference
    $refLen = str_pad(strlen($ref), 2, '0', STR_PAD_LEFT);
    $reference = '08' . $refLen . 'Re-Ya-' . $ref;
    $refTag = strlen($reference);
    $refData = '62' . str_pad($refTag, 2, '0', STR_PAD_LEFT) . $reference;
    
    // CRC placeholder
    $payload = $aid . $mobileData . $amountTag . $country . $currency . $refData . '6304';
    $crc = strtoupper(dechex(crc16($payload)));
    
    return $payload . str_pad($crc, 4, '0', STR_PAD_LEFT);
}

function crc16($data) {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($data); $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return $crc;
}

function generateLinePayRequest($orderId, $orderNumber, $amount, $input, $db) {
    // TODO: Implement LINE Pay API integration
    // For now, return placeholder
    
    $db->prepare("
        INSERT INTO retail_payments (
            order_id, order_number, payment_method, amount, status
        ) VALUES (?, ?, 'linepay', ?, 'pending')
    ");
    $stmt = $db->prepare("
        INSERT INTO retail_payments (
            order_id, order_number, payment_method, amount, status
        ) VALUES (?, ?, 'linepay', ?, 'pending')
    ");
    $stmt->execute([$orderId, $orderNumber, $amount]);
    
    return [
        'type' => 'linepay',
        'amount' => $amount,
        'message' => 'LINE Pay coming soon',
        'redirect_url' => null
    ];
}
