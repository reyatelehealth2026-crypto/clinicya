<?php
/**
 * Retail Products API
 * สำหรับโหลดสินค้า OTC แบบ pagination ใน LIFF Retail Shop
 * - กรองเฉพาะ OTC (ขายปลีกได้)
 * - ใช้ retail_price จาก Odoo
 * - Sync จาก Odoo ผ่าน retail_products table
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

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? 'products';

// ============================================================
// GET CATEGORIES
// ============================================================
if ($action === 'categories') {
    try {
        $stmt = $db->query("
            SELECT 
                category_code as id,
                category_name_th as name,
                category_name_en as name_en,
                icon,
                display_order
            FROM retail_category_mapping
            WHERE is_active = TRUE AND is_otc = TRUE
            ORDER BY display_order, category_name_th
        ");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'categories' => $categories
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================================
// GET SINGLE PRODUCT
// ============================================================
$productId = $_GET['product_id'] ?? null;
$productSku = $_GET['sku'] ?? null;

if ($productId || $productSku) {
    try {
        if ($productSku) {
            $stmt = $db->prepare("
                SELECT p.*, s.qty_available, s.qty_reserved,
                       (s.qty_available - s.qty_reserved) as qty_sellable
                FROM retail_products p
                LEFT JOIN retail_product_stock s ON p.id = s.product_id
                WHERE p.sku = ? AND p.is_active = TRUE
            ");
            $stmt->execute([$productSku]);
        } else {
            $stmt = $db->prepare("
                SELECT p.*, s.qty_available, s.qty_reserved,
                       (s.qty_available - s.qty_reserved) as qty_sellable
                FROM retail_products p
                LEFT JOIN retail_product_stock s ON p.id = s.product_id
                WHERE p.id = ? AND p.is_active = TRUE
            ");
            $stmt->execute([$productId]);
        }
        
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Parse images JSON
            $product['images'] = json_decode($product['images'] ?? '[]', true);
            
            // Get related products (same category)
            $relatedStmt = $db->prepare("
                SELECT id, name, retail_price, thumbnail_url, slug
                FROM retail_products
                WHERE category_code = ? 
                    AND id != ? 
                    AND is_active = TRUE
                ORDER BY RAND()
                LIMIT 4
            ");
            $relatedStmt->execute([$product['category_code'], $product['id']]);
            $relatedProducts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'product' => $product,
                'related_products' => $relatedProducts
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

// ============================================================
// LIST PRODUCTS
// ============================================================
try {
    // Pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    // Filters
    $search = $_GET['search'] ?? '';
    $category = $_GET['category'] ?? '';
    $filterType = $_GET['type'] ?? ''; // flash_sale, choice, featured, new
    
    // Sorting
    $sort = $_GET['sort'] ?? 'newest';
    $sortMap = [
        'newest' => 'p.created_at DESC',
        'price_asc' => 'p.retail_price ASC',
        'price_desc' => 'p.retail_price DESC',
        'name_asc' => 'p.name ASC',
        'name_desc' => 'p.name DESC',
        'bestseller' => 'p.is_bestseller DESC, p.order_count DESC',
        'popular' => 'p.view_count DESC'
    ];
    $orderBy = $sortMap[$sort] ?? $sortMap['newest'];
    
    // Build WHERE clause
    $where = ["p.is_active = TRUE", "p.is_otc = TRUE"];
    $params = [];
    
    if ($search) {
        $where[] = "(p.name LIKE ? OR p.name_en LIKE ? OR p.description LIKE ? OR p.sku LIKE ?)";
        $searchTerm = "%{$search}%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    if ($category) {
        $where[] = "p.category_code = ?";
        $params[] = $category;
    }
    
    // Special filters
    switch ($filterType) {
        case 'flash_sale':
            // TODO: Implement flash sale logic
            $where[] = "p.is_featured = TRUE";
            break;
        case 'choice':
            $where[] = "p.is_featured = TRUE";
            break;
        case 'featured':
            $where[] = "p.is_featured = TRUE";
            break;
        case 'new':
            $where[] = "p.is_new_arrival = TRUE";
            break;
        case 'bestseller':
            $where[] = "p.is_bestseller = TRUE";
            break;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get total count
    $countStmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM retail_products p
        WHERE {$whereClause}
    ");
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($total / $limit);
    
    // Get products
    $sql = "
        SELECT 
            p.id,
            p.sku,
            p.name,
            p.name_en,
            p.short_description,
            p.retail_price,
            p.member_price,
            p.wholesale_price,
            p.unit_of_measure,
            p.thumbnail_url,
            p.images,
            p.category_code,
            p.category_name,
            p.brand,
            p.is_rx,
            p.is_otc,
            p.is_featured,
            p.is_bestseller,
            p.is_new_arrival,
            p.slug,
            COALESCE(s.qty_available, 0) as stock_qty,
            COALESCE(s.qty_reserved, 0) as reserved_qty,
            GREATEST(0, COALESCE(s.qty_available, 0) - COALESCE(s.qty_reserved, 0)) as available_qty
        FROM retail_products p
        LEFT JOIN retail_product_stock s ON p.id = s.product_id
        WHERE {$whereClause}
        ORDER BY {$orderBy}
        LIMIT {$limit} OFFSET {$offset}
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse images JSON
    foreach ($products as &$product) {
        $product['images'] = json_decode($product['images'] ?? '[]', true);
    }
    
    echo json_encode([
        'success' => true,
        'products' => $products,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages
        ],
        'filters' => [
            'search' => $search,
            'category' => $category,
            'sort' => $sort,
            'type' => $filterType
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
