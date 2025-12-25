<?php
/**
 * Checkout API for LIFF
 * Handles cart, order creation, and slip upload
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance()->getConnection();

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Handle JSON body
$jsonInput = json_decode(file_get_contents('php://input'), true);
if ($jsonInput) {
    $action = $jsonInput['action'] ?? $action;
}

try {
    switch ($action) {
        case 'products':
            handleGetProducts();
            break;
        case 'cart':
            handleGetCart();
            break;
        case 'add_to_cart':
            handleAddToCart($jsonInput);
            break;
        case 'update_cart':
            handleUpdateCart($jsonInput);
            break;
        case 'remove_from_cart':
            handleRemoveFromCart($jsonInput);
            break;
        case 'clear_cart':
            handleClearCart($jsonInput);
            break;
        case 'create_order':
            handleCreateOrder($jsonInput);
            break;
        case 'upload_slip':
            handleUploadSlip();
            break;
        case 'get_order':
            handleGetOrder();
            break;
        case 'order':
            handleGetOrderItems();
            break;
        case 'update_payment_method':
            handleUpdatePaymentMethod($jsonInput);
            break;
        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage());
}

function jsonResponse($success, $message = '', $data = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Get products list for shop page
 */
function handleGetProducts() {
    global $db;
    
    $lineAccountId = $_GET['line_account_id'] ?? null;
    $categoryId = $_GET['category_id'] ?? null;
    $search = $_GET['search'] ?? null;
    
    try {
        $sql = "SELECT id, name, description, price, sale_price, image_url, stock, sku, barcode, 
                       manufacturer, generic_name, usage_instructions, unit, category_id
                FROM business_items WHERE is_active = 1";
        $params = [];
        
        if ($lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $lineAccountId;
        }
        
        if ($categoryId) {
            $sql .= " AND category_id = ?";
            $params[] = $categoryId;
        }
        
        if ($search) {
            $sql .= " AND (name LIKE ? OR description LIKE ? OR sku LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY id DESC LIMIT 100";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get categories
        $catSql = "SELECT id, name FROM business_categories WHERE is_active = 1";
        $catParams = [];
        if ($lineAccountId) {
            $catSql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $catParams[] = $lineAccountId;
        }
        $catSql .= " ORDER BY sort_order, name";
        $catStmt = $db->prepare($catSql);
        $catStmt->execute($catParams);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, '', ['products' => $products, 'categories' => $categories]);
    } catch (Exception $e) {
        jsonResponse(false, 'Error loading products: ' . $e->getMessage());
    }
}

/**
 * Get user ID from LINE user ID (helper function)
 */
function getUserIdFromLineUserId($lineUserId) {
    global $db;
    
    if (!$lineUserId) return [null, null];
    
    $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        return [$user['id'], $user['line_account_id']];
    }
    
    // Auto-create user
    $stmt = $db->query("SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1");
    $account = $stmt->fetch();
    $lineAccountId = $account['id'] ?? 1;
    
    $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (?, ?, 'LIFF User')");
    $stmt->execute([$lineAccountId, $lineUserId]);
    
    return [$db->lastInsertId(), $lineAccountId];
}

/**
 * Add item to cart
 */
function handleAddToCart($data) {
    global $db;
    
    $lineUserId = $data['line_user_id'] ?? null;
    $productId = $data['product_id'] ?? null;
    $quantity = max(1, intval($data['quantity'] ?? 1));
    
    if (!$lineUserId || !$productId) {
        jsonResponse(false, 'Missing required fields');
    }
    
    list($userId, $lineAccountId) = getUserIdFromLineUserId($lineUserId);
    
    if (!$userId) {
        jsonResponse(false, 'User not found');
    }
    
    // Check if product exists and is active
    $stmt = $db->prepare("SELECT id, name, price, sale_price, stock FROM business_items WHERE id = ? AND is_active = 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        jsonResponse(false, 'Product not found');
    }
    
    // Check stock
    if (isset($product['stock']) && $product['stock'] !== null && $product['stock'] < $quantity) {
        jsonResponse(false, 'Not enough stock');
    }
    
    // Add to cart using INSERT ... ON DUPLICATE KEY UPDATE
    try {
        $stmt = $db->prepare("
            INSERT INTO cart_items (user_id, product_id, quantity) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");
        $stmt->execute([$userId, $productId, $quantity]);
    } catch (Exception $e) {
        // Fallback: try simple insert/update
        $stmt = $db->prepare("SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$userId, $productId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $newQty = $existing['quantity'] + $quantity;
            $stmt = $db->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
            $stmt->execute([$newQty, $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $productId, $quantity]);
        }
    }
    
    // Get updated cart count
    $stmt = $db->prepare("SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cartCount = $stmt->fetchColumn() ?: 0;
    
    jsonResponse(true, 'Added to cart', [
        'cart_count' => intval($cartCount),
        'product_name' => $product['name']
    ]);
}

/**
 * Update cart item quantity
 */
function handleUpdateCart($data) {
    global $db;
    
    $lineUserId = $data['line_user_id'] ?? null;
    $productId = $data['product_id'] ?? null;
    $quantity = intval($data['quantity'] ?? 0);
    
    if (!$lineUserId || !$productId) {
        jsonResponse(false, 'Missing required fields');
    }
    
    list($userId, $lineAccountId) = getUserIdFromLineUserId($lineUserId);
    
    if (!$userId) {
        jsonResponse(false, 'User not found');
    }
    
    if ($quantity <= 0) {
        // Remove item if quantity is 0 or less
        $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$userId, $productId]);
    } else {
        // Check stock
        $stmt = $db->prepare("SELECT stock FROM business_items WHERE id = ?");
        $stmt->execute([$productId]);
        $stock = $stmt->fetchColumn();
        
        if ($stock !== null && $stock !== false && $stock < $quantity) {
            jsonResponse(false, 'Not enough stock');
        }
        
        // Update quantity
        $stmt = $db->prepare("UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$quantity, $userId, $productId]);
    }
    
    // Get updated cart count
    $stmt = $db->prepare("SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cartCount = $stmt->fetchColumn() ?: 0;
    
    jsonResponse(true, 'Cart updated', ['cart_count' => intval($cartCount)]);
}

/**
 * Remove item from cart
 */
function handleRemoveFromCart($data) {
    global $db;
    
    $lineUserId = $data['line_user_id'] ?? null;
    $productId = $data['product_id'] ?? null;
    
    if (!$lineUserId || !$productId) {
        jsonResponse(false, 'Missing required fields');
    }
    
    list($userId, $lineAccountId) = getUserIdFromLineUserId($lineUserId);
    
    if (!$userId) {
        jsonResponse(false, 'User not found');
    }
    
    $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $productId]);
    
    // Get updated cart count
    $stmt = $db->prepare("SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cartCount = $stmt->fetchColumn() ?: 0;
    
    jsonResponse(true, 'Item removed', ['cart_count' => intval($cartCount)]);
}

/**
 * Clear entire cart
 */
function handleClearCart($data) {
    global $db;
    
    $lineUserId = $data['line_user_id'] ?? null;
    
    if (!$lineUserId) {
        jsonResponse(false, 'Missing line_user_id');
    }
    
    list($userId, $lineAccountId) = getUserIdFromLineUserId($lineUserId);
    
    if (!$userId) {
        jsonResponse(false, 'User not found');
    }
    
    $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
    $stmt->execute([$userId]);
    
    jsonResponse(true, 'Cart cleared', ['cart_count' => 0]);
}

/**
 * Get cart items for user
 */
function handleGetCart() {
    global $db;
    
    $userId = $_GET['user_id'] ?? null;
    $lineUserId = $_GET['line_user_id'] ?? null;
    
    // Debug logging
    $debug = isset($_GET['debug']);
    $debugInfo = [
        'input_user_id' => $userId,
        'input_line_user_id' => $lineUserId,
        'line_user_id_length' => strlen($lineUserId ?? '')
    ];
    
    // Get user ID and line_account_id from LINE user ID
    $lineAccountId = null;
    if ($lineUserId) {
        $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $userId = $user['id'];
            $lineAccountId = $user['line_account_id'];
            $debugInfo['user_found'] = true;
            $debugInfo['db_user_id'] = $userId;
        } else {
            $debugInfo['user_found'] = false;
            // Auto-create user
            $stmt = $db->query("SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1");
            $account = $stmt->fetch();
            $lineAccountId = $account['id'] ?? 1;
            
            $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (?, ?, 'LIFF User')");
            $stmt->execute([$lineAccountId, $lineUserId]);
            $userId = $db->lastInsertId();
            $debugInfo['user_created'] = true;
            $debugInfo['new_user_id'] = $userId;
        }
    }
    
    if (!$userId) {
        if ($debug) {
            jsonResponse(false, 'User not found', ['debug' => $debugInfo]);
        }
        jsonResponse(false, 'User not found');
    }
    
    // Get cart items - use LEFT JOIN to include items even if product was deleted
    $stmt = $db->prepare("
        SELECT c.*, p.name, p.price, p.sale_price, p.image_url, p.is_active,
               (COALESCE(p.sale_price, p.price) * c.quantity) as subtotal
        FROM cart_items c
        LEFT JOIN business_items p ON c.product_id = p.id
        WHERE c.user_id = ?
    ");
    $stmt->execute([$userId]);
    $allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debugInfo['raw_cart_count'] = count($allItems);
    
    // Filter out items where product doesn't exist or is inactive
    $items = [];
    $filteredOut = [];
    foreach ($allItems as $item) {
        if ($item['name'] && $item['is_active']) {
            $items[] = $item;
        } else {
            $filteredOut[] = [
                'product_id' => $item['product_id'],
                'reason' => !$item['name'] ? 'product_deleted' : 'product_inactive'
            ];
        }
    }
    
    $debugInfo['filtered_cart_count'] = count($items);
    $debugInfo['filtered_out'] = $filteredOut;
    
    // Calculate totals
    $subtotal = 0;
    foreach ($items as &$item) {
        $item['subtotal'] = floatval($item['subtotal']);
        $subtotal += $item['subtotal'];
    }
    
    // Get shipping fee based on user's line_account_id
    if ($lineAccountId) {
        $stmt = $db->prepare("SELECT shipping_fee, free_shipping_min FROM shop_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (empty($settings)) {
        $stmt = $db->query("SELECT shipping_fee, free_shipping_min FROM shop_settings LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $shippingFee = floatval($settings['shipping_fee'] ?? 50);
    $freeShippingMin = floatval($settings['free_shipping_min'] ?? 500);
    
    if ($subtotal >= $freeShippingMin) {
        $shippingFee = 0;
    }
    
    $total = $subtotal + $shippingFee;
    
    $response = [
        'items' => $items,
        'subtotal' => $subtotal,
        'shipping_fee' => $shippingFee,
        'free_shipping_min' => $freeShippingMin,
        'total' => $total,
        'item_count' => count($items)
    ];
    
    if ($debug) {
        $response['debug'] = $debugInfo;
    }
    
    jsonResponse(true, '', $response);
}

/**
 * Create order from cart
 */
function handleCreateOrder($data) {
    global $db;
    
    $userId = $data['user_id'] ?? null;
    $lineUserId = $data['line_user_id'] ?? null;
    $requestLineAccountId = $data['line_account_id'] ?? null; // จาก request
    $address = $data['address'] ?? [];
    $paymentMethod = $data['payment_method'] ?? 'transfer';
    $displayName = $data['display_name'] ?? ($address['name'] ?? 'LIFF User');
    
    // Get user ID and line_account_id from LINE user ID
    $lineAccountId = null;
    if ($lineUserId) {
        $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $userId = $user['id'];
            $lineAccountId = $user['line_account_id'];
        } else {
            // Auto-create user - ใช้ line_account_id จาก request หรือ default
            $lineAccountId = $requestLineAccountId;
            if (!$lineAccountId) {
                $stmt = $db->query("SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1");
                $account = $stmt->fetch();
                $lineAccountId = $account['id'] ?? 1;
            }
            
            $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (?, ?, ?)");
            $stmt->execute([$lineAccountId, $lineUserId, $displayName]);
            $userId = $db->lastInsertId();
        }
    }
    
    // Fallback: ใช้ line_account_id จาก request หรือ default
    if (!$lineAccountId) {
        $lineAccountId = $requestLineAccountId;
        if (!$lineAccountId) {
            $stmt = $db->query("SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1");
            $account = $stmt->fetch();
            $lineAccountId = $account['id'] ?? 1;
        }
    }
    
    if (!$userId) {
        jsonResponse(false, 'User not found (line_user_id: ' . ($lineUserId ?? 'null') . ')');
    }
    
    // Get cart items
    $stmt = $db->prepare("
        SELECT c.*, p.name, p.price, p.sale_price
        FROM cart_items c
        JOIN business_items p ON c.product_id = p.id
        WHERE c.user_id = ?
    ");
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        jsonResponse(false, 'Cart is empty');
    }
    
    // Calculate totals
    $subtotal = 0;
    foreach ($items as $item) {
        $price = $item['sale_price'] ?? $item['price'];
        $subtotal += $price * $item['quantity'];
    }
    
    // Get shipping fee from shop_settings based on user's line_account_id
    if ($lineAccountId) {
        $stmt = $db->prepare("SELECT shipping_fee, free_shipping_min FROM shop_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (empty($settings)) {
        $stmt = $db->query("SELECT shipping_fee, free_shipping_min FROM shop_settings LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $shippingFee = floatval($settings['shipping_fee'] ?? 50);
    $freeShippingMin = floatval($settings['free_shipping_min'] ?? 500);
    
    if ($subtotal >= $freeShippingMin) {
        $shippingFee = 0;
    }
    
    $total = $subtotal + $shippingFee;
    
    // Build delivery info
    $deliveryInfo = [
        'type' => 'shipping',
        'name' => $address['name'] ?? '',
        'phone' => $address['phone'] ?? '',
        'address' => trim(implode(' ', array_filter([
            $address['address'] ?? '',
            $address['subdistrict'] ?? '',
            $address['district'] ?? '',
            $address['province'] ?? '',
            $address['postcode'] ?? ''
        ])))
    ];
    
    // Create order
    $db->beginTransaction();
    
    try {
        $orderNumber = 'TXN' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("
            INSERT INTO transactions 
            (line_account_id, transaction_type, order_number, user_id, total_amount, shipping_fee, grand_total, delivery_info, payment_method, status, payment_status)
            VALUES (?, 'purchase', ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')
        ");
        $stmt->execute([
            $lineAccountId,
            $orderNumber,
            $userId,
            $subtotal,
            $shippingFee,
            $total,
            json_encode($deliveryInfo, JSON_UNESCAPED_UNICODE),
            $paymentMethod
        ]);
        
        $orderId = $db->lastInsertId();
        
        // Add order items
        foreach ($items as $item) {
            $price = $item['sale_price'] ?? $item['price'];
            $itemSubtotal = $price * $item['quantity'];
            
            $stmt = $db->prepare("
                INSERT INTO transaction_items (transaction_id, product_id, product_name, product_price, quantity, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderId,
                $item['product_id'],
                $item['name'],
                $price,
                $item['quantity'],
                $itemSubtotal
            ]);
        }
        
        // Clear cart
        $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        $db->commit();
        
        // 🔔 แจ้งเตือน Telegram เมื่อมี order ใหม่
        notifyTelegramNewOrder($orderId, $orderNumber, $total, $user, $deliveryInfo);
        
        jsonResponse(true, 'Order created', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total' => $total,
            'payment_method' => $paymentMethod
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Send order confirmation message (for COD)
 */
function sendOrderConfirmation($order, $items) {
    global $db;
    
    try {
        // Get user's LINE user ID
        $stmt = $db->prepare("SELECT u.line_user_id, la.channel_access_token 
                              FROM users u 
                              JOIN line_accounts la ON u.line_account_id = la.id 
                              WHERE u.id = ?");
        $stmt->execute([$order['user_id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userData || !$userData['line_user_id'] || !$userData['channel_access_token']) {
            return false;
        }
        
        // Build items list
        $itemsText = "";
        foreach ($items as $item) {
            $price = $item['sale_price'] ?? $item['price'];
            $subtotal = $price * $item['quantity'];
            $itemsText .= "• {$item['name']} x{$item['quantity']} = ฿" . number_format($subtotal, 0) . "\n";
        }
        
        // Get delivery info
        $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
        
        // Build confirmation message
        $msg = "✅ สั่งซื้อสำเร็จ!\n";
        $msg .= "━━━━━━━━━━━━━━━\n";
        $msg .= "📋 เลขที่: #{$order['order_number']}\n";
        $msg .= "💳 ชำระเงิน: เก็บเงินปลายทาง (COD)\n";
        $msg .= "━━━━━━━━━━━━━━━\n";
        $msg .= $itemsText;
        $msg .= "━━━━━━━━━━━━━━━\n";
        $msg .= "💵 รวมทั้งหมด: ฿" . number_format($order['grand_total'], 0) . "\n";
        if (!empty($deliveryInfo['name'])) {
            $msg .= "━━━━━━━━━━━━━━━\n";
            $msg .= "📦 จัดส่งถึง: {$deliveryInfo['name']}\n";
            $msg .= "📞 โทร: {$deliveryInfo['phone']}\n";
            $msg .= "🏠 {$deliveryInfo['address']}";
        }
        $msg .= "\n\n🚚 รอการจัดส่ง 1-3 วันทำการ";
        
        // Call LINE API
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $userData['channel_access_token']
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'to' => $userData['line_user_id'],
                'messages' => [['type' => 'text', 'text' => $msg]]
            ])
        ]);
        
        curl_exec($ch);
        curl_close($ch);
        
        return true;
    } catch (Exception $e) {
        error_log('sendOrderConfirmation error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Upload payment slip
 */
function handleUploadSlip() {
    global $db;
    
    // Debug logging
    error_log("=== handleUploadSlip START ===");
    error_log("POST data: " . json_encode($_POST));
    error_log("FILES: " . json_encode(array_keys($_FILES)));
    
    $orderId = $_POST['order_id'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    
    error_log("orderId: {$orderId}, userId: {$userId}");
    
    if (!$orderId) {
        error_log("ERROR: Order ID required");
        jsonResponse(false, 'Order ID required');
    }
    
    if (!isset($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, 'No file uploaded');
    }
    
    $file = $_FILES['slip'];
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        jsonResponse(false, 'Invalid file type');
    }
    
    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        jsonResponse(false, 'File too large (max 5MB)');
    }
    
    // Get order info
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        jsonResponse(false, 'Order not found');
    }
    
    // Create upload directory
    $uploadDir = __DIR__ . '/../uploads/slips/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'slip_' . $order['order_number'] . '_' . time() . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(false, 'Failed to save file');
    }
    
    // Get base URL - use BASE_URL from config if available
    if (defined('BASE_URL')) {
        $baseUrl = rtrim(BASE_URL, '/');
    } else {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }
    $imageUrl = $baseUrl . '/uploads/slips/' . $filename;
    
    // Save to payment_slips table (use transaction_id and order_id)
    $slipSaved = false;
    try {
        // Insert with both order_id and transaction_id for compatibility
        $stmt = $db->prepare("INSERT INTO payment_slips (order_id, transaction_id, user_id, image_url, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$orderId, $orderId, $order['user_id'], $imageUrl]);
        $slipSaved = true;
        error_log("payment_slips saved: order_id={$orderId}, transaction_id={$orderId}, user_id={$order['user_id']}, image={$imageUrl}");
    } catch (Exception $e) {
        error_log('payment_slips insert error: ' . $e->getMessage());
        // Try alternative insert without user_id
        try {
            $stmt = $db->prepare("INSERT INTO payment_slips (order_id, transaction_id, image_url, status) VALUES (?, ?, ?, 'pending')");
            $stmt->execute([$orderId, $orderId, $imageUrl]);
            $slipSaved = true;
            error_log("payment_slips saved (without user_id): order_id={$orderId}");
        } catch (Exception $e2) {
            error_log('payment_slips insert error (retry): ' . $e2->getMessage());
        }
    }
    
    // Update order status
    $stmt = $db->prepare("UPDATE transactions SET status = 'paid', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$orderId]);
    
    // Send LINE receipt message
    sendReceiptMessage($order, $imageUrl);
    
    // 🔔 แจ้งเตือน Telegram เมื่อมีการอัพโหลดสลิป
    $stmt = $db->prepare("SELECT display_name FROM users WHERE id = ?");
    $stmt->execute([$order['user_id']]);
    $slipUser = $stmt->fetch(PDO::FETCH_ASSOC);
    notifyTelegramPayment($orderId, $order['order_number'], $imageUrl, $slipUser ?: []);
    
    jsonResponse(true, 'Slip uploaded', [
        'image_url' => $imageUrl
    ]);
}

/**
 * Send Flex Receipt message to LINE user
 * รูปสลิปอยู่บนสุด (hero) พร้อมรายละเอียดออเดอร์
 */
function sendReceiptMessage($order, $slipUrl = null) {
    global $db;
    
    try {
        // Get user's LINE user ID
        $stmt = $db->prepare("SELECT u.line_user_id, u.display_name, la.channel_access_token 
                              FROM users u 
                              JOIN line_accounts la ON u.line_account_id = la.id 
                              WHERE u.id = ?");
        $stmt->execute([$order['user_id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userData || !$userData['line_user_id'] || !$userData['channel_access_token']) {
            return false;
        }
        
        // Get order items
        $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
        $stmt->execute([$order['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get delivery info
        $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
        
        // Build Flex Receipt
        $flex = buildFlexReceipt($order, $items, $deliveryInfo, $slipUrl);
        
        $messages = [
            [
                'type' => 'flex',
                'altText' => '✅ แจ้งชำระเงินเรียบร้อย #' . $order['order_number'],
                'contents' => $flex
            ]
        ];
        
        // Call LINE API
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $userData['channel_access_token']
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'to' => $userData['line_user_id'],
                'messages' => $messages
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    } catch (Exception $e) {
        error_log('sendReceiptMessage error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Build Flex Receipt Bubble
 */
function buildFlexReceipt($order, $items, $deliveryInfo, $slipUrl) {
    // Build Item List
    $itemList = [];
    foreach ($items as $item) {
        $itemList[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                ['type' => 'text', 'text' => $item['product_name'], 'size' => 'sm', 'color' => '#555555', 'flex' => 4, 'wrap' => true],
                ['type' => 'text', 'text' => 'x' . $item['quantity'], 'size' => 'sm', 'color' => '#111111', 'align' => 'end', 'flex' => 1],
                ['type' => 'text', 'text' => '฿' . number_format($item['subtotal'], 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end', 'flex' => 2]
            ]
        ];
    }
    
    // Address String
    $addrParts = [];
    if (!empty($deliveryInfo['name'])) $addrParts[] = $deliveryInfo['name'];
    if (!empty($deliveryInfo['phone'])) $addrParts[] = $deliveryInfo['phone'];
    if (!empty($deliveryInfo['address'])) $addrParts[] = $deliveryInfo['address'];
    $addr = implode("\n", $addrParts) ?: 'ไม่ระบุที่อยู่';
    
    // Build bubble
    $bubble = [
        'type' => 'bubble',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => '✅ แจ้งชำระเงินเรียบร้อย', 'weight' => 'bold', 'size' => 'lg', 'color' => '#1DB446'],
                ['type' => 'text', 'text' => 'Order #' . $order['order_number'], 'size' => 'xs', 'color' => '#aaaaaa', 'margin' => 'xs'],
                ['type' => 'separator', 'margin' => 'lg'],
                ['type' => 'text', 'text' => '📦 ที่อยู่จัดส่ง', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                ['type' => 'text', 'text' => $addr, 'size' => 'xs', 'color' => '#666666', 'wrap' => true, 'margin' => 'sm'],
                ['type' => 'separator', 'margin' => 'lg'],
                ['type' => 'text', 'text' => '🛒 รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                ['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm', 'contents' => $itemList],
                ['type' => 'separator', 'margin' => 'lg'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'md',
                    'contents' => [
                        ['type' => 'text', 'text' => 'ยอดสินค้า', 'size' => 'sm', 'color' => '#555555'],
                        ['type' => 'text', 'text' => '฿' . number_format($order['total_amount'], 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end']
                    ]
                ],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'sm',
                    'contents' => [
                        ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm', 'color' => '#555555'],
                        ['type' => 'text', 'text' => $order['shipping_fee'] > 0 ? '฿' . number_format($order['shipping_fee'], 0) : 'ฟรี!', 'size' => 'sm', 'color' => $order['shipping_fee'] > 0 ? '#111111' : '#1DB446', 'align' => 'end']
                    ]
                ],
                ['type' => 'separator', 'margin' => 'md'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'md',
                    'contents' => [
                        ['type' => 'text', 'text' => 'ยอดสุทธิ', 'weight' => 'bold', 'size' => 'md'],
                        ['type' => 'text', 'text' => '฿' . number_format($order['grand_total'], 0), 'weight' => 'bold', 'size' => 'xl', 'align' => 'end', 'color' => '#1DB446']
                    ]
                ]
            ]
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => '🙏 ขอบคุณที่อุดหนุนครับ', 'align' => 'center', 'color' => '#aaaaaa', 'size' => 'xs'],
                ['type' => 'text', 'text' => '🚚 รอจัดส่ง 1-3 วันทำการ', 'align' => 'center', 'color' => '#888888', 'size' => 'xs', 'margin' => 'sm']
            ]
        ]
    ];
    
    // Add slip image as hero if available
    if ($slipUrl) {
        $bubble['hero'] = [
            'type' => 'image',
            'url' => $slipUrl,
            'size' => 'full',
            'aspectRatio' => '20:13',
            'aspectMode' => 'cover',
            'action' => ['type' => 'uri', 'uri' => $slipUrl]
        ];
    }
    
    return $bubble;
}

/**
 * Get order details
 */
function handleGetOrder() {
    global $db;
    
    $orderId = $_GET['order_id'] ?? null;
    
    if (!$orderId) {
        jsonResponse(false, 'Order ID required');
    }
    
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        jsonResponse(false, 'Order not found');
    }
    
    // Get items
    $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $order['items'] = $items;
    $order['delivery_info'] = json_decode($order['delivery_info'] ?? '{}', true);
    
    jsonResponse(true, '', ['order' => $order]);
}

/**
 * Get order items for checkout (from dispense)
 * Returns items in same format as cart for liff-checkout compatibility
 */
function handleGetOrderItems() {
    global $db;
    
    $orderId = $_GET['order_id'] ?? null;
    
    if (!$orderId) {
        jsonResponse(false, 'Order ID required');
    }
    
    // Get transaction
    $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        jsonResponse(false, 'Order not found');
    }
    
    // Get items
    $stmt = $db->prepare("SELECT ti.*, p.image_url 
        FROM transaction_items ti 
        LEFT JOIN business_items p ON ti.product_id = p.id 
        WHERE ti.transaction_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format items like cart format
    $formattedItems = [];
    foreach ($items as $item) {
        $formattedItems[] = [
            'product_id' => $item['product_id'],
            'name' => $item['product_name'],
            'price' => floatval($item['product_price']),
            'quantity' => intval($item['quantity']),
            'subtotal' => floatval($item['subtotal']),
            'image_url' => $item['image_url'] ?? null,
            'label_data' => json_decode($item['label_data'] ?? '{}', true)
        ];
    }
    
    jsonResponse(true, '', [
        'items' => $formattedItems,
        'total' => floatval($order['grand_total']),
        'subtotal' => floatval($order['total_amount']),
        'shipping_fee' => floatval($order['shipping_fee'] ?? 0),
        'order_number' => $order['order_number'],
        'order_id' => $order['id'],
        'payment_status' => $order['payment_status'],
        'is_dispense' => $order['transaction_type'] === 'dispense'
    ]);
}


/**
 * Update payment method for existing order (dispense)
 */
function handleUpdatePaymentMethod($data) {
    global $db;
    
    $orderId = $data['order_id'] ?? null;
    $paymentMethod = $data['payment_method'] ?? 'cash';
    
    if (!$orderId) {
        jsonResponse(false, 'Order ID required');
    }
    
    // Update payment method
    $stmt = $db->prepare("UPDATE transactions SET payment_method = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$paymentMethod, $orderId]);
    
    // If COD/cash, mark as paid immediately (for dispense at pharmacy)
    if ($paymentMethod === 'cod' || $paymentMethod === 'cash') {
        $stmt = $db->prepare("UPDATE transactions SET payment_status = 'paid', status = 'completed', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$orderId]);
    }
    
    jsonResponse(true, 'Payment method updated', ['order_id' => $orderId]);
}


/**
 * Notify Telegram when new order is created
 */
function notifyTelegramNewOrder($orderId, $orderNumber, $total, $user, $deliveryInfo) {
    global $db;
    
    try {
        // Get Telegram settings
        $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings || !$settings['is_enabled'] || !$settings['bot_token'] || !$settings['chat_id']) {
            return false;
        }
        
        // Check if notify_new_order is enabled
        if (!($settings['notify_new_order'] ?? 1)) {
            return false;
        }
        
        // Get order items
        $stmt = $db->prepare("SELECT product_name, quantity, subtotal FROM transaction_items WHERE transaction_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build message
        $itemList = "";
        foreach ($items as $item) {
            $itemList .= "  • {$item['product_name']} x{$item['quantity']} = ฿" . number_format($item['subtotal']) . "\n";
        }
        
        $customerName = $user['display_name'] ?? 'ลูกค้า';
        $phone = $deliveryInfo['phone'] ?? '-';
        $address = $deliveryInfo['address'] ?? '-';
        
        $message = "🛒 <b>ออเดอร์ใหม่!</b>\n\n";
        $message .= "📋 Order: <code>{$orderNumber}</code>\n";
        $message .= "👤 ลูกค้า: {$customerName}\n";
        $message .= "📱 โทร: {$phone}\n";
        $message .= "📍 ที่อยู่: {$address}\n\n";
        $message .= "📦 <b>รายการสินค้า:</b>\n{$itemList}\n";
        $message .= "💰 <b>ยอดรวม: ฿" . number_format($total) . "</b>\n\n";
        $message .= "🔗 <a href=\"" . rtrim(BASE_URL, '/') . "/shop/orders.php\">ดูรายละเอียด</a>";
        
        // Send to Telegram
        $url = "https://api.telegram.org/bot{$settings['bot_token']}/sendMessage";
        $data = [
            'chat_id' => $settings['chat_id'],
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        
        return true;
    } catch (Exception $e) {
        error_log('notifyTelegramNewOrder error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Notify Telegram when payment slip is uploaded
 */
function notifyTelegramPayment($orderId, $orderNumber, $slipUrl, $user) {
    global $db;
    
    try {
        // Get Telegram settings
        $stmt = $db->prepare("SELECT * FROM telegram_settings WHERE id = 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings || !$settings['is_enabled'] || !$settings['bot_token'] || !$settings['chat_id']) {
            return false;
        }
        
        // Check if notify_payment is enabled
        if (!($settings['notify_payment'] ?? 1)) {
            return false;
        }
        
        $customerName = $user['display_name'] ?? 'ลูกค้า';
        
        // Send photo with caption
        $url = "https://api.telegram.org/bot{$settings['bot_token']}/sendPhoto";
        $data = [
            'chat_id' => $settings['chat_id'],
            'photo' => $slipUrl,
            'caption' => "💳 <b>แจ้งชำระเงิน!</b>\n\n📋 Order: <code>{$orderNumber}</code>\n👤 ลูกค้า: {$customerName}\n\n🔗 <a href=\"" . rtrim(BASE_URL, '/') . "/shop/orders.php?pending_slip=1\">ตรวจสอบสลิป</a>",
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        
        return true;
    } catch (Exception $e) {
        error_log('notifyTelegramPayment error: ' . $e->getMessage());
        return false;
    }
}
