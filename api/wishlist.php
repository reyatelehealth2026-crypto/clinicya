<?php
/**
 * Wishlist API - จัดการรายการโปรด
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance()->getConnection();

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_wishlist (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        line_user_id VARCHAR(50),
        product_id INT NOT NULL,
        line_account_id INT,
        price_when_added DECIMAL(10,2) DEFAULT 0,
        notify_on_sale TINYINT(1) DEFAULT 1,
        notify_on_restock TINYINT(1) DEFAULT 0,
        notified_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_product (user_id, product_id),
        INDEX idx_line_user (line_user_id),
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;

$action = $input['action'] ?? $_GET['action'] ?? '';
$lineUserId = $input['line_user_id'] ?? $_GET['line_user_id'] ?? '';
$productId = $input['product_id'] ?? $_GET['product_id'] ?? 0;
$lineAccountId = $input['line_account_id'] ?? $_GET['line_account_id'] ?? null;

// Get user_id from line_user_id
$userId = null;
if ($lineUserId) {
    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $userId = $stmt->fetchColumn();
}

try {
    switch ($action) {
        case 'add':
            // เพิ่มรายการโปรด
            if (!$userId || !$productId) {
                echo json_encode(['success' => false, 'error' => 'Missing user or product']);
                exit;
            }
            
            // Get current price
            $stmt = $db->prepare("SELECT price, sale_price FROM business_items WHERE id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            $currentPrice = $product['sale_price'] ?: $product['price'];
            
            $stmt = $db->prepare("INSERT INTO user_wishlist 
                (user_id, line_user_id, product_id, line_account_id, price_when_added, notify_on_sale) 
                VALUES (?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE notify_on_sale = 1, price_when_added = ?");
            $stmt->execute([$userId, $lineUserId, $productId, $lineAccountId, $currentPrice, $currentPrice]);
            
            echo json_encode(['success' => true, 'message' => 'เพิ่มรายการโปรดแล้ว']);
            break;
            
        case 'remove':
            // ลบรายการโปรด
            if (!$userId || !$productId) {
                echo json_encode(['success' => false, 'error' => 'Missing user or product']);
                exit;
            }
            
            $stmt = $db->prepare("DELETE FROM user_wishlist WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$userId, $productId]);
            
            echo json_encode(['success' => true, 'message' => 'ลบออกจากรายการโปรดแล้ว']);
            break;
            
        case 'toggle':
            // สลับสถานะรายการโปรด
            if (!$userId || !$productId) {
                echo json_encode(['success' => false, 'error' => 'Missing user or product']);
                exit;
            }
            
            // Check if exists
            $stmt = $db->prepare("SELECT id FROM user_wishlist WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$userId, $productId]);
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                $stmt = $db->prepare("DELETE FROM user_wishlist WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$userId, $productId]);
                echo json_encode(['success' => true, 'is_favorite' => false, 'message' => 'ลบออกจากรายการโปรดแล้ว']);
            } else {
                $stmt = $db->prepare("SELECT price, sale_price FROM business_items WHERE id = ?");
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                $currentPrice = $product['sale_price'] ?: $product['price'];
                
                $stmt = $db->prepare("INSERT INTO user_wishlist 
                    (user_id, line_user_id, product_id, line_account_id, price_when_added, notify_on_sale) 
                    VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->execute([$userId, $lineUserId, $productId, $lineAccountId, $currentPrice]);
                echo json_encode(['success' => true, 'is_favorite' => true, 'message' => 'เพิ่มรายการโปรดแล้ว']);
            }
            break;
            
        case 'check':
            // ตรวจสอบว่าเป็นรายการโปรดหรือไม่
            if (!$userId || !$productId) {
                echo json_encode(['success' => true, 'is_favorite' => false]);
                exit;
            }
            
            $stmt = $db->prepare("SELECT id FROM user_wishlist WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$userId, $productId]);
            $isFavorite = $stmt->fetchColumn() ? true : false;
            
            echo json_encode(['success' => true, 'is_favorite' => $isFavorite]);
            break;
            
        case 'list':
        default:
            // ดึงรายการโปรดทั้งหมด
            if (!$userId && !$lineUserId) {
                echo json_encode(['success' => true, 'items' => []]);
                exit;
            }
            
            $sql = "SELECT w.*, p.name, p.sku, p.price, p.sale_price, p.image_url, p.stock,
                           CASE WHEN p.sale_price IS NOT NULL AND p.sale_price < w.price_when_added 
                                THEN 1 ELSE 0 END as is_on_sale,
                           CASE WHEN p.sale_price IS NOT NULL 
                                THEN ROUND((1 - p.sale_price / w.price_when_added) * 100) 
                                ELSE 0 END as discount_percent
                    FROM user_wishlist w
                    JOIN business_items p ON w.product_id = p.id
                    WHERE ";
            
            if ($userId) {
                $sql .= "w.user_id = ?";
                $params = [$userId];
            } else {
                $sql .= "w.line_user_id = ?";
                $params = [$lineUserId];
            }
            
            $sql .= " ORDER BY w.created_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'items' => $items, 'count' => count($items)]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
