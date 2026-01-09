<?php
/**
 * Landing Products API
 * API สำหรับค้นหาสินค้าเพื่อเลือกแสดงบน Landing Page
 */

header('Content-Type: application/json');

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Simple auth check for AJAX
if (empty($_SESSION['admin_user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'products' => []]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'search':
            $query = trim($_GET['q'] ?? '');
            if (strlen($query) < 2) {
                echo json_encode(['products' => []]);
                exit;
            }
            
            $allProducts = [];
            
            // Search in business_items table
            try {
                $sql = "SELECT id, name, sku, price, image_url, 'business_items' as source
                        FROM business_items 
                        WHERE is_active = 1 AND (name LIKE ? OR sku LIKE ? OR barcode LIKE ?)
                        ORDER BY name ASC LIMIT 10";
                $stmt = $db->prepare($sql);
                $stmt->execute(["%{$query}%", "%{$query}%", "%{$query}%"]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $allProducts = array_merge($allProducts, $items);
            } catch (PDOException $e) {
                // Table doesn't exist or error - log for debugging
                error_log("business_items search error: " . $e->getMessage());
            }
            
            // Search in cny_products table
            try {
                $sql = "SELECT id, name, sku, price, image_url, 'cny_products' as source
                        FROM cny_products 
                        WHERE is_active = 1 AND (name LIKE ? OR sku LIKE ?)
                        ORDER BY name ASC LIMIT 10";
                $stmt = $db->prepare($sql);
                $stmt->execute(["%{$query}%", "%{$query}%"]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $allProducts = array_merge($allProducts, $items);
            } catch (PDOException $e) {
                // Table doesn't exist or error
                error_log("cny_products search error: " . $e->getMessage());
            }
            
            // Search in products table (if exists and has data)
            try {
                $sql = "SELECT id, name, sku, price, image_url, 'products' as source
                        FROM products 
                        WHERE is_active = 1 AND (name LIKE ? OR sku LIKE ?)
                        ORDER BY name ASC LIMIT 10";
                $stmt = $db->prepare($sql);
                $stmt->execute(["%{$query}%", "%{$query}%"]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $allProducts = array_merge($allProducts, $items);
            } catch (PDOException $e) {
                // Table doesn't exist or error
                error_log("products search error: " . $e->getMessage());
            }
            
            // Limit to 20 results
            $allProducts = array_slice($allProducts, 0, 20);
            
            echo json_encode(['products' => $allProducts, 'query' => $query, 'count' => count($allProducts)]);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action', 'products' => []]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'products' => []]);
}
