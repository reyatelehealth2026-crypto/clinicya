<?php
/**
 * Shop Products API
 * สำหรับโหลดสินค้าแบบ pagination ใน LIFF Shop
 *
 * แหล่งข้อมูล:
 *   - shop_settings.order_data_source = 'odoo' → odoo_products_cache (storefront_enabled = 1)
 *   - อื่น ๆ → business_items (เดิม)
 * รูปสินค้า (Odoo): MANAGER_PRODUCT_PHOTO_BASE_URL + /uploads/product_photo/{code}.jpg
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
require_once __DIR__ . '/../includes/odoo-storefront-catalog.php';

$db = Database::getInstance()->getConnection();

$useCnyProducts = false;

$lineAccountId = (int) ($_GET['account'] ?? $_GET['line_account_id'] ?? 0);
$useOdoo = useOdooStorefrontCatalog($db, $lineAccountId);

// Action handler
$action = $_GET['action'] ?? 'products';

// ─── Categories ─────────────────────────────────────────────────────────────
if ($action === 'categories') {
    try {
        if ($useCnyProducts) {
            $stmt = $db->query("SELECT DISTINCT category_code as id, category_name as name
                               FROM cny_products
                               WHERE enable = '1' AND category_name IS NOT NULL AND category_name != ''
                               ORDER BY category_name");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedCategories = array_map(function ($cat, $index) {
                return [
                    'id' => $cat['id'] ?: ($index + 1),
                    'name' => $cat['name'],
                    'code' => $cat['id'],
                ];
            }, $categories, array_keys($categories));
        } elseif ($useOdoo) {
            $stmt = $db->prepare(
                "SELECT DISTINCT category FROM odoo_products_cache
                 WHERE line_account_id = ?
                   AND storefront_enabled = 1
                   AND is_active = 1
                   AND category IS NOT NULL AND category <> ''
                 ORDER BY category ASC"
            );
            $stmt->execute([$lineAccountId]);
            $cats = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $formattedCategories = [];
            foreach ($cats as $cname) {
                $formattedCategories[] = [
                    'id' => $cname,
                    'name' => $cname,
                    'code' => $cname,
                ];
            }
        } else {
            $catTable = null;
            try {
                $db->query('SELECT 1 FROM item_categories LIMIT 1');
                $catTable = 'item_categories';
            } catch (Exception $e) {
                try {
                    $db->query('SELECT 1 FROM product_categories LIMIT 1');
                    $catTable = 'product_categories';
                } catch (Exception $e2) {
                }
            }

            if ($catTable) {
                $stmt = $db->query("SELECT id, name FROM {$catTable} ORDER BY id");
                $formattedCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $formattedCategories = [];
            }
        }

        echo json_encode([
            'success' => true,
            'categories' => $formattedCategories,
            'category_id_is_string' => $useOdoo,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ─── Single product ───────────────────────────────────────────────────────────
$productId = $_GET['product_id'] ?? null;
$productSku = $_GET['sku'] ?? null;

if ($productId || $productSku) {
    try {
        if ($useCnyProducts) {
            if ($productSku) {
                $stmt = $db->prepare("SELECT * FROM cny_products WHERE sku = ? AND enable = '1'");
                $stmt->execute([$productSku]);
            } else {
                $stmt = $db->prepare('SELECT * FROM cny_products WHERE id = ? AND enable = \'1\'');
                $stmt->execute([$productId]);
            }
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($product) {
                $priceData = json_decode($product['product_price'], true);
                $price = $priceData[0]['price'] ?? 0;
                $unit = $priceData[0]['unit'] ?? '';

                echo json_encode([
                    'success' => true,
                    'product' => [
                        'id' => (int) $product['id'],
                        'sku' => $product['sku'],
                        'name' => $product['name'],
                        'name_en' => $product['name_en'],
                        'price' => (float) $price,
                        'sale_price' => null,
                        'stock' => (int) ($product['qty'] ?? 0),
                        'image_url' => $product['photo_path'],
                        'unit' => $unit,
                        'manufacturer' => $product['manufacturer'],
                        'generic_name' => $product['spec_name'],
                        'description' => $product['description'],
                        'usage_instructions' => $product['usage'],
                        'category_id' => $product['category_code'],
                        'category_name' => $product['category_name'],
                        'barcode' => $product['barcode'],
                        'is_featured' => 0,
                        'is_bestseller' => 0,
                        'is_flash_sale' => 0,
                        'is_choice' => 0,
                        'flash_sale_end' => null,
                    ],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'error' => 'Product not found']);
            }
        } elseif ($useOdoo) {
            $hasOverrides = schema_table_has_column($db, 'odoo_products_cache', 'admin_overrides');
            $extra = $hasOverrides ? ', admin_overrides' : '';
            if ($productSku) {
                $stmt = $db->prepare(
                    "SELECT *{$extra} FROM odoo_products_cache
                     WHERE line_account_id = ? AND sku = ?
                       AND storefront_enabled = 1 AND is_active = 1 LIMIT 1"
                );
                $stmt->execute([$lineAccountId, $productSku]);
            } else {
                $stmt = $db->prepare(
                    "SELECT *{$extra} FROM odoo_products_cache
                     WHERE line_account_id = ? AND id = ?
                       AND storefront_enabled = 1 AND is_active = 1 LIMIT 1"
                );
                $stmt->execute([$lineAccountId, (int) $productId]);
            }
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($product) {
                $out = formatOdooProductForLiff($product);
                echo json_encode(['success' => true, 'product' => $out], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'error' => 'Product not found']);
            }
        } else {
            $stmt = $db->prepare('SELECT * FROM business_items WHERE id = ? AND is_active = 1');
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($product) {
                echo json_encode([
                    'success' => true,
                    'product' => [
                        'id' => (int) $product['id'],
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'barcode' => $product['barcode'] ?? null,
                        'price' => (float) $product['price'],
                        'sale_price' => $product['sale_price'] ? (float) $product['sale_price'] : null,
                        'stock' => (int) ($product['stock'] ?? 999),
                        'image_url' => $product['image_url'],
                        'unit' => $product['unit'] ?? 'ชิ้น',
                        'manufacturer' => $product['manufacturer'] ?? null,
                        'generic_name' => $product['generic_name'] ?? null,
                        'description' => $product['description'],
                        'usage_instructions' => $product['usage_instructions'] ?? null,
                        'category_id' => $product['category_id'],
                        'is_featured' => (int) ($product['is_featured'] ?? 0),
                        'is_bestseller' => (int) ($product['is_bestseller'] ?? 0),
                        'is_flash_sale' => (int) ($product['is_flash_sale'] ?? 0),
                        'is_choice' => (int) ($product['is_choice'] ?? 0),
                        'flash_sale_end' => $product['flash_sale_end'] ?? null,
                        'product_source' => 'business_items',
                    ],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'error' => 'Product not found']);
            }
        }
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ─── List ────────────────────────────────────────────────────────────────────
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(50, max(10, (int) ($_GET['limit'] ?? 20)));
$categoryId = $_GET['category'] ?? null;
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$type = $_GET['type'] ?? null;

$offset = ($page - 1) * $limit;

try {
    if ($useCnyProducts) {
        $where = ["enable = '1'"];
        $params = [];

        if ($categoryId) {
            $where[] = 'category_code = ?';
            $params[] = $categoryId;
        }

        if ($search) {
            $where[] = '(name LIKE ? OR name_en LIKE ? OR sku LIKE ? OR barcode LIKE ?)';
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $where);

        switch ($sort) {
            case 'price_asc':
                $orderBy = "CAST(JSON_UNQUOTE(JSON_EXTRACT(product_price, '$[0].price')) AS DECIMAL(10,2)) ASC";
                break;
            case 'price_desc':
                $orderBy = "CAST(JSON_UNQUOTE(JSON_EXTRACT(product_price, '$[0].price')) AS DECIMAL(10,2)) DESC";
                break;
            case 'name':
                $orderBy = 'name ASC';
                break;
            default:
                $orderBy = 'id DESC';
        }

        $countSql = "SELECT COUNT(*) FROM cny_products WHERE {$whereClause}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT * FROM cny_products WHERE {$whereClause} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedProducts = array_map(function ($p) {
            $priceData = json_decode($p['product_price'], true);
            $price = $priceData[0]['price'] ?? 0;
            $unit = $priceData[0]['unit'] ?? '';

            return [
                'id' => (int) $p['id'],
                'sku' => $p['sku'],
                'name' => $p['name'],
                'name_en' => $p['name_en'],
                'price' => (float) $price,
                'sale_price' => null,
                'stock' => (int) ($p['qty'] ?? 0),
                'image_url' => $p['photo_path'],
                'unit' => $unit,
                'manufacturer' => $p['manufacturer'],
                'generic_name' => $p['spec_name'],
                'description' => $p['description'],
                'category_id' => $p['category_code'],
                'category_name' => $p['category_name'],
                'barcode' => $p['barcode'],
                'is_featured' => 0,
                'is_bestseller' => 0,
                'is_flash_sale' => 0,
                'is_choice' => 0,
                'flash_sale_end' => null,
            ];
        }, $products);
    } elseif ($useOdoo) {
        $hasOverrides = schema_table_has_column($db, 'odoo_products_cache', 'admin_overrides');
        $hasFeatured = schema_table_has_column($db, 'odoo_products_cache', 'featured_order');
        $extra = $hasOverrides ? ', admin_overrides' : '';

        $where = ['line_account_id = ?', 'storefront_enabled = 1', 'is_active = 1'];
        $params = [$lineAccountId];

        if ($categoryId !== null && $categoryId !== '') {
            $where[] = 'category = ?';
            $params[] = $categoryId;
        }

        if ($type === 'featured' && $hasFeatured) {
            $where[] = 'featured_order IS NOT NULL';
        } elseif (in_array($type, ['flash_sale', 'choice'], true)) {
            // ไม่มีคอลัมน์เทียบเท่าใน Odoo cache — คืนว่าง
            $where[] = '1=0';
        }

        if ($search !== '') {
            $where[] = '(name LIKE ? OR sku LIKE ? OR product_code LIKE ? OR barcode LIKE ? OR generic_name LIKE ?)';
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like, $like);
        }

        $whereClause = implode(' AND ', $where);

        switch ($sort) {
            case 'price_asc':
                $orderBy = 'COALESCE(NULLIF(online_price,0), list_price) ASC';
                break;
            case 'price_desc':
                $orderBy = 'COALESCE(NULLIF(online_price,0), list_price) DESC';
                break;
            case 'name':
                $orderBy = 'name ASC';
                break;
            default:
                if ($hasFeatured) {
                    $orderBy = 'featured_order IS NULL, featured_order ASC, name ASC';
                } else {
                    $orderBy = 'id DESC';
                }
        }

        $countSql = "SELECT COUNT(*) FROM odoo_products_cache WHERE {$whereClause}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT *{$extra} FROM odoo_products_cache WHERE {$whereClause} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedProducts = [];
        foreach ($products as $p) {
            $formattedProducts[] = formatOdooProductForLiff($p);
        }
    } else {
        $where = ['is_active = 1'];
        $params = [];

        if ($categoryId) {
            $where[] = 'category_id = ?';
            $params[] = $categoryId;
        }

        if ($type === 'flash_sale') {
            $where[] = 'is_flash_sale = 1';
        } elseif ($type === 'choice') {
            $where[] = 'is_choice = 1';
        } elseif ($type === 'featured') {
            $where[] = 'is_featured = 1';
        }

        if ($search) {
            $where[] = '(name LIKE ? OR sku LIKE ? OR barcode LIKE ?)';
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $where);

        switch ($sort) {
            case 'price_asc':
                $orderBy = 'COALESCE(sale_price, price) ASC';
                break;
            case 'price_desc':
                $orderBy = 'COALESCE(sale_price, price) DESC';
                break;
            case 'name':
                $orderBy = 'name ASC';
                break;
            default:
                $orderBy = 'id DESC';
        }

        $countSql = "SELECT COUNT(*) FROM business_items WHERE {$whereClause}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT * FROM business_items WHERE {$whereClause} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedProducts = array_map(function ($p) {
            return [
                'id' => (int) $p['id'],
                'name' => $p['name'],
                'sku' => $p['sku'],
                'barcode' => $p['barcode'] ?? null,
                'price' => (float) $p['price'],
                'sale_price' => $p['sale_price'] ? (float) $p['sale_price'] : null,
                'stock' => (int) ($p['stock'] ?? 999),
                'image_url' => $p['image_url'],
                'unit' => $p['unit'] ?? 'ชิ้น',
                'manufacturer' => $p['manufacturer'] ?? null,
                'generic_name' => $p['generic_name'] ?? null,
                'description' => $p['description'],
                'category_id' => $p['category_id'],
                'is_featured' => (int) ($p['is_featured'] ?? 0),
                'is_bestseller' => (int) ($p['is_bestseller'] ?? 0),
                'is_flash_sale' => (int) ($p['is_flash_sale'] ?? 0),
                'is_choice' => (int) ($p['is_choice'] ?? 0),
                'flash_sale_end' => $p['flash_sale_end'] ?? null,
                'product_source' => 'business_items',
            ];
        }, $products);
    }

    $totalPages = ceil($total / $limit);
    $hasMore = $page < $totalPages;

    $source = $useCnyProducts ? 'cny_products' : ($useOdoo ? 'odoo_products_cache' : 'business_items');

    echo json_encode([
        'success' => true,
        'products' => $formattedProducts,
        'source' => $source,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_more' => $hasMore,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
