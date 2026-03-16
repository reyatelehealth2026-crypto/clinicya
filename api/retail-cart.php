<?php
/**
 * Retail Cart API
 * จัดการตะกร้าสินค้าสำหรับ B2C Retail
 * - Add, remove, update cart items
 * - Stock reservation
 * - Cart summary calculation
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
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

// Get LINE user ID from header or param
$lineUserId = $_SERVER['HTTP_X_LINE_USER_ID'] ?? $_GET['line_user_id'] ?? $_POST['line_user_id'] ?? null;

if (!$lineUserId) {
    echo json_encode(['success' => false, 'error' => 'LINE user ID required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET CART
// ============================================================
if ($method === 'GET') {
    try {
        // Get cart items with product details
        $stmt = $db->prepare("
            SELECT 
                c.id as cart_id,
                c.qty,
                c.unit_price,
                c.notes,
                c.created_at as added_at,
                p.id as product_id,
                p.sku,
                p.name,
                p.name_en,
                p.retail_price,
                p.member_price,
                p.thumbnail_url,
                p.unit_of_measure,
                p.is_otc,
                p.slug,
                COALESCE(s.qty_available, 0) as stock_qty,
                COALESCE(s.qty_reserved, 0) as reserved_qty,
                GREATEST(0, COALESCE(s.qty_available, 0) - COALESCE(s.qty_reserved, 0)) as available_qty
            FROM retail_carts c
            JOIN retail_products p ON c.product_id = p.id
            LEFT JOIN retail_product_stock s ON p.id = s.product_id
            WHERE c.line_user_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$lineUserId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $subtotal = 0;
        $totalItems = 0;
        $totalQty = 0;
        $outOfStockItems = [];
        
        foreach ($items as $item) {
            $itemTotal = $item['unit_price'] * $item['qty'];
            $subtotal += $itemTotal;
            $totalItems++;
            $totalQty += $item['qty'];
            
            // Check if requested qty exceeds available
            if ($item['qty'] > $item['available_qty']) {
                $outOfStockItems[] = [
                    'cart_id' => $item['cart_id'],
                    'product_id' => $item['product_id'],
                    'name' => $item['name'],
                    'requested' => $item['qty'],
                    'available' => $item['available_qty']
                ];
            }
        }
        
        // Get customer info for member pricing
        $memberDiscount = 0;
        $memberStmt = $db->prepare("
            SELECT member_tier, points_balance 
            FROM retail_customers 
            WHERE line_user_id = ?
        ");
        $memberStmt->execute([$lineUserId]);
        $customer = $memberStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'cart' => [
                'line_user_id' => $lineUserId,
                'items' => $items,
                'summary' => [
                    'total_items' => $totalItems,
                    'total_qty' => $totalQty,
                    'subtotal' => $subtotal,
                    'subtotal_formatted' => '฿' . number_format($subtotal, 2)
                ],
                'customer' => $customer ?: null,
                'out_of_stock' => $outOfStockItems,
                'has_out_of_stock' => !empty($outOfStockItems)
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================================
// ADD TO CART (POST)
// ============================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $productId = $input['product_id'] ?? null;
    $qty = max(1, intval($input['qty'] ?? 1));
    $notes = $input['notes'] ?? '';
    
    if (!$productId) {
        echo json_encode(['success' => false, 'error' => 'Product ID required']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Get product info
        $productStmt = $db->prepare("
            SELECT p.*, s.qty_available, s.qty_reserved,
                   (s.qty_available - s.qty_reserved) as available_qty
            FROM retail_products p
            LEFT JOIN retail_product_stock s ON p.id = s.product_id
            WHERE p.id = ? AND p.is_active = TRUE AND p.is_otc = TRUE
            FOR UPDATE
        ");
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Product not found or not available']);
            exit;
        }
        
        // Check stock
        $availableQty = max(0, intval($product['available_qty'] ?? 0));
        
        // Check existing cart qty
        $existingStmt = $db->prepare("
            SELECT qty FROM retail_carts 
            WHERE line_user_id = ? AND product_id = ?
        ");
        $existingStmt->execute([$lineUserId, $productId]);
        $existingQty = $existingStmt->fetchColumn() ?: 0;
        
        $totalRequestedQty = $existingQty + $qty;
        
        if ($totalRequestedQty > $availableQty) {
            $db->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Insufficient stock',
                'available' => $availableQty,
                'requested' => $totalRequestedQty,
                'existing_in_cart' => $existingQty
            ]);
            exit;
        }
        
        // Check max quantity per order (e.g., 10 per product)
        $maxQty = 10;
        if ($totalRequestedQty > $maxQty) {
            $db->rollBack();
            echo json_encode([
                'success' => false,
                'error' => "Maximum {$maxQty} items per product",
                'max_allowed' => $maxQty
            ]);
            exit;
        }
        
        $unitPrice = $product['retail_price'];
        
        // Calculate reservation expiry (30 minutes)
        $reservedUntil = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        
        // Insert or update cart with reservation
        $upsertStmt = $db->prepare("
            INSERT INTO retail_carts (line_user_id, product_id, qty, unit_price, notes, is_reserved, reserved_until, updated_at)
            VALUES (?, ?, ?, ?, ?, TRUE, ?, NOW())
            ON DUPLICATE KEY UPDATE
                qty = qty + VALUES(qty),
                is_reserved = TRUE,
                reserved_until = VALUES(reserved_until),
                updated_at = NOW()
        ");
        $upsertStmt->execute([$lineUserId, $productId, $qty, $unitPrice, $notes, $reservedUntil]);
        
        // Reserve stock (add new qty)
        $reserveStmt = $db->prepare("
            UPDATE retail_product_stock 
            SET qty_reserved = qty_reserved + ?
            WHERE product_id = ?
        ");
        $reserveStmt->execute([$qty, $productId]);
        
        $db->commit();
        
        // Get updated cart count
        $countStmt = $db->prepare("
            SELECT SUM(qty) as total_qty, COUNT(*) as total_items
            FROM retail_carts
            WHERE line_user_id = ?
        ");
        $countStmt->execute([$lineUserId]);
        $cartCount = $countStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Added to cart',
            'product' => [
                'id' => $product['id'],
                'name' => $product['name'],
                'thumbnail' => $product['thumbnail_url']
            ],
            'added_qty' => $qty,
            'cart_count' => $cartCount
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// UPDATE CART (PUT)
// ============================================================
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $cartId = $input['cart_id'] ?? null;
    $productId = $input['product_id'] ?? null;
    $newQty = intval($input['qty'] ?? 0);
    $notes = $input['notes'] ?? null;
    
    if (!$cartId || !$productId) {
        echo json_encode(['success' => false, 'error' => 'Cart ID and Product ID required']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Get current cart item
        $currentStmt = $db->prepare("
            SELECT qty FROM retail_carts 
            WHERE id = ? AND line_user_id = ? AND product_id = ?
        ");
        $currentStmt->execute([$cartId, $lineUserId, $productId]);
        $currentQty = $currentStmt->fetchColumn();
        
        if ($currentQty === false) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Cart item not found']);
            exit;
        }
        
        // If qty = 0, remove item
        if ($newQty <= 0) {
            // Release reserved stock
            $releaseStmt = $db->prepare("
                UPDATE retail_product_stock 
                SET qty_reserved = GREATEST(0, qty_reserved - ?)
                WHERE product_id = ?
            ");
            $releaseStmt->execute([$currentQty, $productId]);
            
            // Delete cart item
            $deleteStmt = $db->prepare("
                DELETE FROM retail_carts 
                WHERE id = ? AND line_user_id = ?
            ");
            $deleteStmt->execute([$cartId, $lineUserId]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Item removed',
                'action' => 'removed'
            ]);
            exit;
        }
        
        // Check available stock
        $stockStmt = $db->prepare("
            SELECT qty_available, qty_reserved, (qty_available - qty_reserved + ?) as adjusted_available
            FROM retail_product_stock 
            WHERE product_id = ?
            FOR UPDATE
        ");
        $stockStmt->execute([$currentQty, $productId]);
        $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$stock || $newQty > $stock['adjusted_available']) {
            $db->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Insufficient stock',
                'available' => $stock['adjusted_available'] ?? 0,
                'requested' => $newQty
            ]);
            exit;
        }
        
        $qtyDiff = $newQty - $currentQty;
        
        // Update cart
        $updateStmt = $db->prepare("
            UPDATE retail_carts 
            SET qty = ?, notes = COALESCE(?, notes), updated_at = NOW()
            WHERE id = ? AND line_user_id = ?
        ");
        $updateStmt->execute([$newQty, $notes, $cartId, $lineUserId]);
        
        // Adjust reserved stock
        if ($qtyDiff != 0) {
            $reserveStmt = $db->prepare("
                UPDATE retail_product_stock 
                SET qty_reserved = GREATEST(0, qty_reserved + ?)
                WHERE product_id = ?
            ");
            $reserveStmt->execute([$qtyDiff, $productId]);
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Cart updated',
            'new_qty' => $newQty
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// REMOVE FROM CART (DELETE)
// ============================================================
if ($method === 'DELETE') {
    $cartId = $_GET['cart_id'] ?? null;
    $productId = $_GET['product_id'] ?? null;
    $clearAll = ($_GET['clear'] ?? '') === 'true';
    
    try {
        $db->beginTransaction();
        
        if ($clearAll) {
            // Release all reserved stock for this user
            $releaseStmt = $db->prepare("
                UPDATE retail_product_stock s
                JOIN retail_carts c ON s.product_id = c.product_id
                SET s.qty_reserved = GREATEST(0, s.qty_reserved - c.qty)
                WHERE c.line_user_id = ?
            ");
            $releaseStmt->execute([$lineUserId]);
            
            // Clear cart
            $deleteStmt = $db->prepare("
                DELETE FROM retail_carts WHERE line_user_id = ?
            ");
            $deleteStmt->execute([$lineUserId]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Cart cleared',
                'action' => 'clear_all'
            ]);
            exit;
        }
        
        if (!$cartId) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Cart ID required']);
            exit;
        }
        
        // Get item details before deleting
        $itemStmt = $db->prepare("
            SELECT product_id, qty FROM retail_carts 
            WHERE id = ? AND line_user_id = ?
        ");
        $itemStmt->execute([$cartId, $lineUserId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => 'Cart item not found']);
            exit;
        }
        
        // Release reserved stock
        $releaseStmt = $db->prepare("
            UPDATE retail_product_stock 
            SET qty_reserved = GREATEST(0, qty_reserved - ?)
            WHERE product_id = ?
        ");
        $releaseStmt->execute([$item['qty'], $item['product_id']]);
        
        // Delete cart item
        $deleteStmt = $db->prepare("
            DELETE FROM retail_carts 
            WHERE id = ? AND line_user_id = ?
        ");
        $deleteStmt->execute([$cartId, $lineUserId]);
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Item removed',
            'action' => 'remove'
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Invalid method
echo json_encode(['success' => false, 'error' => 'Invalid request method']);
