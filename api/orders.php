<?php
/**
 * Orders API - จัดการออเดอร์
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

try {
    switch ($action) {
        case 'my_orders':
            handleMyOrders($db);
            break;
        case 'detail':
            handleOrderDetail($db);
            break;
        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage());
}

/**
 * ดึงออเดอร์ของผู้ใช้
 */
function handleMyOrders($db) {
    $lineUserId = $_GET['line_user_id'] ?? '';
    $lineAccountId = $_GET['line_account_id'] ?? 1;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $status = $_GET['status'] ?? '';
    
    if (empty($lineUserId)) {
        jsonResponse(false, 'Missing line_user_id');
    }
    
    // Get user - ค้นหาจาก line_user_id
    $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // ถ้าไม่พบ user ให้ return empty array แทนที่จะ error
        jsonResponse(true, 'OK', ['orders' => []]);
    }
    
    // Check which table exists - ใช้ transactions เป็นหลัก
    $ordersTable = 'transactions';
    $orderItemsTable = 'transaction_items';
    
    try {
        $db->query("SELECT 1 FROM transactions LIMIT 1");
    } catch (Exception $e) {
        // Try orders table
        try {
            $db->query("SELECT 1 FROM orders LIMIT 1");
            $ordersTable = 'orders';
            $orderItemsTable = 'order_items';
        } catch (Exception $e2) {
            jsonResponse(true, 'OK', ['orders' => []]);
        }
    }
    
    // Build query - ค้นหาจาก user_id
    $sql = "SELECT * FROM {$ordersTable} WHERE user_id = ?";
    $params = [$user['id']];
    
    // Filter by transaction_type if using transactions table
    if ($ordersTable === 'transactions') {
        $sql .= " AND transaction_type = 'purchase'";
    }
    
    if (!empty($status)) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get items for each order
    foreach ($orders as &$order) {
        $orderId = $order['id'];
        $orderIdField = $ordersTable === 'transactions' ? 'transaction_id' : 'order_id';
        
        try {
            // Get items with full product details
            $stmt = $db->prepare("
                SELECT oi.id, oi.product_id, oi.quantity,
                       COALESCE(oi.product_name, p.name) as name,
                       COALESCE(oi.product_price, p.sale_price, p.price, 0) as price,
                       COALESCE(oi.subtotal, oi.product_price * oi.quantity, 0) as subtotal,
                       COALESCE(p.image_url, '') as image,
                       p.sku, p.description, p.unit,
                       p.manufacturer, p.generic_name, p.usage_instructions
                FROM {$orderItemsTable} oi
                LEFT JOIN business_items p ON oi.product_id = p.id
                WHERE oi.{$orderIdField} = ?
            ");
            $stmt->execute([$orderId]);
            $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Fallback
            try {
                $stmt = $db->prepare("SELECT * FROM {$orderItemsTable} WHERE {$orderIdField} = ?");
                $stmt->execute([$orderId]);
                $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {
                $order['items'] = [];
            }
        }
        
        // Parse delivery_info JSON
        if (!empty($order['delivery_info'])) {
            $order['delivery_info'] = json_decode($order['delivery_info'], true) ?: [];
        } else {
            $order['delivery_info'] = [];
        }
        
        // Normalize fields for LIFF
        $order['order_id'] = $order['id'];
        $order['order_number'] = $order['order_number'] ?? $order['id'];
        $order['total_amount'] = $order['grand_total'] ?? $order['total_amount'] ?? 0;
    }
    unset($order);
    
    jsonResponse(true, 'OK', ['orders' => $orders]);
}

/**
 * ดึงรายละเอียดออเดอร์
 */
function handleOrderDetail($db) {
    $orderId = $_GET['order_id'] ?? '';
    $lineUserId = $_GET['line_user_id'] ?? '';
    
    if (empty($orderId)) {
        jsonResponse(false, 'Missing order_id');
    }
    
    // Check which table exists
    $ordersTable = 'orders';
    $orderItemsTable = 'order_items';
    $orderIdField = 'order_id';
    
    try {
        $db->query("SELECT 1 FROM orders LIMIT 1");
    } catch (Exception $e) {
        try {
            $db->query("SELECT 1 FROM transactions LIMIT 1");
            $ordersTable = 'transactions';
            $orderItemsTable = 'transaction_items';
            $orderIdField = 'transaction_id';
        } catch (Exception $e2) {
            jsonResponse(false, 'ไม่พบตารางออเดอร์');
        }
    }
    
    // Get order
    $stmt = $db->prepare("SELECT * FROM {$ordersTable} WHERE {$orderIdField} = ? OR id = ?");
    $stmt->execute([$orderId, $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        jsonResponse(false, 'ไม่พบออเดอร์นี้');
    }
    
    // Verify ownership if line_user_id provided
    if (!empty($lineUserId)) {
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || $order['user_id'] != $user['id']) {
            jsonResponse(false, 'ไม่มีสิทธิ์ดูออเดอร์นี้');
        }
    }
    
    // Get items
    $itemOrderIdField = $ordersTable === 'transactions' ? 'transaction_id' : 'order_id';
    try {
        $stmt = $db->prepare("
            SELECT oi.*, p.name, p.image_url as image, p.sku
            FROM {$orderItemsTable} oi
            LEFT JOIN business_items p ON oi.product_id = p.id
            WHERE oi.{$itemOrderIdField} = ?
        ");
        $stmt->execute([$order['id']]);
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $order['items'] = [];
    }
    
    // Normalize order_id
    if (!isset($order['order_id'])) {
        $order['order_id'] = $order['transaction_id'] ?? $order['id'];
    }
    
    jsonResponse(true, 'OK', ['order' => $order]);
}

function jsonResponse($success, $message, $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        ...$data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
