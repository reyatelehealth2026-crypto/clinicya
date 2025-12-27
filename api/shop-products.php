<?php
/**
 * Shop Products API - Pagination Support
 * สำหรับโหลดสินค้าแบบ pagination ใน LIFF Shop
 * ใช้ตาราง business_items และ item_categories
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Action handler
$action = $_GET['action'] ?? 'products';

// Handle categories request
if ($action === 'categories') {
    try {
        // ใช้ item_categories เป็นหลัก
        $catTable = 'item_categories';
        try {
            $db->query("SELECT 1 FROM item_categories LIMIT 1");
        } catch (Exception $e) {
            $catTable = 'business_categories';
        }
        
        $sql = "SELECT id, name, cny_code FROM {$catTable} WHERE is_active = 1 ORDER BY id";
        $stmt = $db->query($sql);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format categories
        $formattedCategories = array_map(function($cat) {
            return [
                'id' => (int)$cat['id'],
                'name' => $cat['name'],
                'code' => $cat['cny_code'] ?? null
            ];
        }, $categories);
        
        echo json_encode([
            'success' => true,
            'categories' => $formattedCategories
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Check columns exist
$hasIsFeatured = $hasIsBestseller = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
    $hasIsFeatured = in_array('is_featured', $cols);
    $hasIsBestseller = in_array('is_bestseller', $cols);
} catch (Exception $e) {}

// Check if requesting single product
$productId = $_GET['product_id'] ?? null;
if ($productId) {
    try {
        $featuredCol = $hasIsFeatured ? "COALESCE(is_featured, 0)" : "0";
        $bestsellerCol = $hasIsBestseller ? "COALESCE(is_bestseller, 0)" : "0";
        
        $sql = "SELECT id, name, sku, barcode, price, sale_price, stock, image_url, 
                       unit, manufacturer, generic_name, description, usage_instructions, category_id,
                       $featuredCol as is_featured,
                       $bestsellerCol as is_bestseller
                FROM business_items WHERE id = ? AND is_active = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            echo json_encode([
                'success' => true,
                'product' => [
                    'id' => (int)$product['id'],
                    'name' => $product['name'],
                    'sku' => $product['sku'],
                    'barcode' => $product['barcode'],
                    'price' => (float)$product['price'],
                    'sale_price' => $product['sale_price'] ? (float)$product['sale_price'] : null,
                    'stock' => (int)($product['stock'] ?? 999),
                    'image_url' => $product['image_url'],
                    'unit' => $product['unit'] ?? 'ชิ้น',
                    'manufacturer' => $product['manufacturer'],
                    'generic_name' => $product['generic_name'],
                    'description' => $product['description'],
                    'usage_instructions' => $product['usage_instructions'],
                    'category_id' => $product['category_id'] ? (int)$product['category_id'] : null,
                    'is_featured' => (int)($product['is_featured'] ?? 0),
                    'is_bestseller' => (int)($product['is_bestseller'] ?? 0)
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Product not found']);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(10, (int)($_GET['limit'] ?? 20)));
$categoryId = $_GET['category'] ?? null;
$lineAccountId = $_GET['account'] ?? null;
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'newest'; // newest, price_asc, price_desc, name
$featuredOnly = isset($_GET['featured']) && $_GET['featured'] == '1';

$offset = ($page - 1) * $limit;

// Build column selects
$featuredCol = $hasIsFeatured ? "COALESCE(is_featured, 0)" : "0";
$bestsellerCol = $hasIsBestseller ? "COALESCE(is_bestseller, 0)" : "0";

try {
    // Build query
    $where = ["is_active = 1"];
    $params = [];
    
    if ($lineAccountId) {
        // แสดงสินค้าทั้งหมด ไม่ว่าจะเป็นของ account ไหน
        // $where[] = "(line_account_id = ? OR line_account_id IS NULL)";
        // $params[] = $lineAccountId;
    }
    
    if ($categoryId) {
        $where[] = "category_id = ?";
        $params[] = $categoryId;
    }
    
    if ($featuredOnly && $hasIsFeatured) {
        $where[] = "is_featured = 1";
    }
    
    if ($search) {
        $where[] = "(name LIKE ? OR sku LIKE ? OR barcode LIKE ? OR generic_name LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Sort
    switch ($sort) {
        case 'price_asc':
            $orderBy = "COALESCE(sale_price, price) ASC";
            break;
        case 'price_desc':
            $orderBy = "COALESCE(sale_price, price) DESC";
            break;
        case 'name':
            $orderBy = "name ASC";
            break;
        case 'popular':
            $orderBy = "id DESC";
            break;
        default:
            $orderBy = "id DESC";
    }
    
    // Count total
    $countSql = "SELECT COUNT(*) FROM business_items WHERE {$whereClause}";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    
    // Get products
    $sql = "SELECT id, name, sku, barcode, price, sale_price, stock, image_url, 
                   unit, manufacturer, generic_name, description, usage_instructions, category_id,
                   $featuredCol as is_featured,
                   $bestsellerCol as is_bestseller
            FROM business_items 
            WHERE {$whereClause}
            ORDER BY {$orderBy}
            LIMIT {$limit} OFFSET {$offset}";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format products
    $formattedProducts = array_map(function($p) {
        return [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'sku' => $p['sku'],
            'barcode' => $p['barcode'],
            'price' => (float)$p['price'],
            'sale_price' => $p['sale_price'] ? (float)$p['sale_price'] : null,
            'stock' => (int)($p['stock'] ?? 999),
            'image_url' => $p['image_url'],
            'unit' => $p['unit'] ?? 'ชิ้น',
            'manufacturer' => $p['manufacturer'],
            'generic_name' => $p['generic_name'],
            'description' => $p['description'],
            'usage_instructions' => $p['usage_instructions'],
            'category_id' => $p['category_id'] ? (int)$p['category_id'] : null,
            'is_featured' => (int)($p['is_featured'] ?? 0),
            'is_bestseller' => (int)($p['is_bestseller'] ?? 0)
        ];
    }, $products);
    
    $totalPages = ceil($total / $limit);
    $hasMore = $page < $totalPages;
    
    echo json_encode([
        'success' => true,
        'products' => $formattedProducts,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_more' => $hasMore
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
}
