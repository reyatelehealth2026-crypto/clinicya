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
require_once '../includes/manager-product-photo.php';
require_once '../includes/shop-data-source.php';
require_once '../includes/odoo-storefront-catalog.php';
require_once '../classes/ActivityLogger.php';
require_once '../classes/AccountReceivableService.php';

$db = Database::getInstance()->getConnection();
$activityLogger = ActivityLogger::getInstance($db);

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
        case 'validate_promo':
            handleValidatePromo($jsonInput);
            break;
        case 'last_address':
            handleGetLastAddress();
            break;
        case 'promptpay_qr':
            handlePromptPayQR();
            break;
        case 'my_orders':
            handleMyOrders($jsonInput);
            break;
        case 'shop_info':
            handleGetShopInfo();
            break;
        case 'product_detail':
            handleGetProductDetail();
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

function getTableColumns(string $table): array {
    global $db;
    static $cache = [];

    if (!isset($cache[$table])) {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $stmt = $db->query("SHOW COLUMNS FROM `{$safeTable}`");
        $cache[$table] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[$table][$row['Field']] = true;
        }
    }

    return $cache[$table];
}

function hasTableColumn(string $table, string $column): bool {
    $columns = getTableColumns($table);
    return isset($columns[$column]);
}

/**
 * cart_items.product_source — แยก Odoo cache id กับ business_items id
 */
function ensureCartProductSourceSupport(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if (!hasTableColumn('cart_items', 'product_source')) {
            $db->exec("ALTER TABLE cart_items ADD COLUMN product_source VARCHAR(32) NOT NULL DEFAULT 'business_items' COMMENT 'business_items|odoo_products_cache' AFTER product_id");
        }
    } catch (Exception $e) {
        error_log('[checkout] cart product_source column: ' . $e->getMessage());

        return;
    }
    if (!hasTableColumn('cart_items', 'product_source')) {
        return;
    }
    try {
        $chk = $db->query("SHOW INDEX FROM cart_items WHERE Key_name = 'unique_user_product_source'");
        if ($chk && $chk->rowCount() > 0) {
            return;
        }
    } catch (Exception $e) {
        return;
    }
    foreach (['unique_cart_item', 'unique_user_product'] as $idx) {
        try {
            $db->exec("ALTER TABLE cart_items DROP INDEX `{$idx}`");
        } catch (Exception $e) {
            // ignore
        }
    }
    try {
        $db->exec('ALTER TABLE cart_items ADD UNIQUE KEY unique_user_product_source (user_id, product_id, product_source)');
    } catch (Exception $e) {
        error_log('[checkout] unique_user_product_source: ' . $e->getMessage());
    }
}

function resolveCartProductSource(?string $raw): string
{
    $s = strtolower(trim((string) $raw));

    return $s === 'odoo_products_cache' ? 'odoo_products_cache' : 'business_items';
}

/**
 * When the client omits product_source, follow shop_settings.order_data_source
 * so LINE mini-app / LIFF can add Odoo storefront lines without extra fields.
 */
function resolveCartProductSourceWithShopDefault(PDO $db, ?string $raw, int $lineAccountId): string
{
    $trimmed = $raw !== null ? trim((string) $raw) : '';
    if ($trimmed !== '') {
        return resolveCartProductSource($trimmed);
    }

    return getShopOrderDataSource($db, $lineAccountId) === 'odoo'
        ? 'odoo_products_cache'
        : 'business_items';
}

function odooCartLineUnitPrice(array $row): float
{
    $list = (float) ($row['o_list'] ?? 0);
    $online = (float) ($row['o_online'] ?? 0);
    if ($online > 0) {
        return $online;
    }

    return $list > 0 ? $list : 0.0;
}

/**
 * Unit price for order line (business or odoo-shaped row)
 */
function checkoutOrderUnitPrice(array $item): float
{
    $p = (float) ($item['price'] ?? 0);
    $s = isset($item['sale_price']) && $item['sale_price'] !== '' && $item['sale_price'] !== null
        ? (float) $item['sale_price'] : 0.0;
    if ($s > 0 && ($p <= 0 || $s < $p)) {
        return $s;
    }

    return $p > 0 ? $p : $s;
}

/**
 * Load cart lines from DB for checkout (same rules as GET cart)
 *
 * @return array<int, array<string, mixed>>
 */
function loadCheckoutCartLinesFromDb(PDO $db, int $userId, ?int $lineAccountId): array
{
    ensureCartProductSourceSupport($db);

    if (hasTableColumn('cart_items', 'product_source') && tableExists('odoo_products_cache')) {
        $stmt = $db->prepare(
            'SELECT c.*,
                    p.name AS bi_name,
                    p.price AS bi_price,
                    p.sale_price AS bi_sale_price,
                    p.image_url AS bi_image_url,
                    p.is_active AS bi_is_active,
                    o.name AS o_name,
                    o.list_price AS o_list,
                    o.online_price AS o_online,
                    o.product_code AS o_pc,
                    o.sku AS o_sku,
                    o.is_active AS o_is_active
             FROM cart_items c
             LEFT JOIN business_items p
               ON c.product_id = p.id
              AND IFNULL(NULLIF(c.product_source, \'\'), \'business_items\') = \'business_items\'
             LEFT JOIN odoo_products_cache o
               ON c.product_id = o.id
              AND c.product_source = \'odoo_products_cache\'
              AND o.line_account_id = ?
             WHERE c.user_id = ?'
        );
        $stmt->execute([$lineAccountId ?: 0, $userId]);
    } else {
        $stmt = $db->prepare(
            'SELECT c.*, p.name AS bi_name, p.price AS bi_price, p.sale_price AS bi_sale_price,
                    p.image_url AS bi_image_url, p.is_active AS bi_is_active,
                    NULL AS o_name, NULL AS o_list, NULL AS o_online, NULL AS o_pc, NULL AS o_sku, NULL AS o_is_active
             FROM cart_items c
             LEFT JOIN business_items p ON c.product_id = p.id
             WHERE c.user_id = ?'
        );
        $stmt->execute([$userId]);
    }
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($raw as $item) {
        $src = resolveCartProductSource($item['product_source'] ?? null);
        if ($src === 'odoo_products_cache') {
            $name = $item['o_name'] ?? '';
            if ($name === '') {
                continue;
            }
            $unit = odooCartLineUnitPrice([
                'o_list' => $item['o_list'] ?? 0,
                'o_online' => $item['o_online'] ?? 0,
            ]);
            $list = (float) ($item['o_list'] ?? 0);
            $on = (float) ($item['o_online'] ?? 0);
            $price = $list > 0 ? $list : ($on > 0 ? $on : 0);
            $sale = ($on > 0 && $list > 0 && $on < $list) ? $on : null;
            if ($list <= 0 && $on > 0) {
                $price = $on;
                $sale = null;
            }
            $out[] = [
                'product_id' => (int) $item['product_id'],
                'name' => $name,
                'price' => $price,
                'sale_price' => $sale,
                'quantity' => (int) $item['quantity'],
                'product_source' => 'odoo_products_cache',
                '_unit' => $unit,
            ];
        } else {
            $name = $item['bi_name'] ?? '';
            if ($name === '') {
                continue;
            }
            $bp = $item['bi_price'] ?? 0;
            $bs = $item['bi_sale_price'] ?? null;
            $unit = (float) (($bs !== null && $bs !== '' && (float) $bs > 0) ? $bs : $bp);
            $out[] = [
                'product_id' => (int) $item['product_id'],
                'name' => $name,
                'price' => (float) $bp,
                'sale_price' => ($bs !== null && $bs !== '') ? (float) $bs : null,
                'quantity' => (int) $item['quantity'],
                'product_source' => 'business_items',
                '_unit' => $unit,
            ];
        }
    }

    return $out;
}

function tableExists(string $table): bool {
    global $db;
    static $cache = [];

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    if ($table === '') {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    // NOTE: MariaDB's native prepare protocol does not support `?` placeholders
    // for `SHOW TABLES LIKE ?` (SQLSTATE[42000] 1064 near '?'). Query
    // information_schema.TABLES instead, with a quoted SHOW TABLES fallback.
    try {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        $quoted = $db->quote($table);
        $stmt = $db->query("SHOW TABLES LIKE {$quoted}");
        $cache[$table] = $stmt ? ($stmt->rowCount() > 0) : false;
    }

    return $cache[$table];
}

function decodeJsonArrayValue($value): array {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Add derived UI fields for shop (badges, discount %) — safe if columns are missing.
 */
function enrichProductRow(array &$p) {
    $price = isset($p['price']) && $p['price'] !== '' && $p['price'] !== null ? floatval($p['price']) : null;
    $sale = isset($p['sale_price']) && $p['sale_price'] !== '' && $p['sale_price'] !== null ? floatval($p['sale_price']) : null;
    if (!isset($p['is_flash_sale'])) {
        $p['is_flash_sale'] = false;
    }
    $p['discount_percent'] = isset($p['discount_percent']) && $p['discount_percent'] !== '' ? (int) $p['discount_percent'] : null;
    $p['promotion_label'] = $p['promotion_label'] ?? null;
    $p['badges'] = decodeJsonArrayValue($p['badges'] ?? []);
    if ($sale !== null && $price !== null && $sale < $price && $price > 0) {
        $p['discount_percent'] = (int) round((1 - $sale / $price) * 100);
        if ($p['promotion_label'] === null || $p['promotion_label'] === '') {
            $p['promotion_label'] = 'โปรโมชัน';
        }
        if (empty($p['badges'])) {
            $p['badges'][] = ['text' => '-' . $p['discount_percent'] . '%', 'color' => 'red'];
        }
    }
}

function getExistingUserIdFromLineUserId(?string $lineUserId): ?int {
    global $db;

    if (!$lineUserId) {
        return null;
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
    $stmt->execute([$lineUserId]);
    $userId = $stmt->fetchColumn();

    return $userId ? (int) $userId : null;
}

function buildShopImageGallery(array $product): array {
    $gallery = [];

    foreach (decodeJsonArrayValue($product['image_gallery'] ?? []) as $url) {
        if (is_string($url) && trim($url) !== '') {
            $gallery[] = trim($url);
        }
    }

    foreach (['image_url', 'photo_path'] as $field) {
        if (!empty($product[$field]) && is_string($product[$field])) {
            $gallery[] = trim($product[$field]);
        }
    }

    return array_values(array_unique(array_filter($gallery)));
}

function normalizeShopProductRow(array &$product): void {
    enrichProductRow($product);
    $product['image_gallery'] = buildShopImageGallery($product);
    $product['is_favorite'] = !empty($product['is_favorite']);
    $product['brand'] = $product['manufacturer'] ?? null;
    $product['category_name'] = $product['category_name'] ?? null;
    $product['unit'] = $product['unit'] ?? null;
}

function buildBusinessItemSelectFields(string $alias = 'p'): array {
    $fields = [
        "{$alias}.id",
        "{$alias}.name",
        hasTableColumn('business_items', 'description') ? "{$alias}.description" : "NULL AS description",
        "{$alias}.price",
        "{$alias}.sale_price",
        hasTableColumn('business_items', 'stock') ? "{$alias}.stock" : "NULL AS stock",
        hasTableColumn('business_items', 'sku') ? "{$alias}.sku" : "NULL AS sku",
        hasTableColumn('business_items', 'barcode') ? "{$alias}.barcode" : "NULL AS barcode",
        hasTableColumn('business_items', 'manufacturer') ? "{$alias}.manufacturer" : "NULL AS manufacturer",
        hasTableColumn('business_items', 'generic_name') ? "{$alias}.generic_name" : "NULL AS generic_name",
        hasTableColumn('business_items', 'usage_instructions') ? "{$alias}.usage_instructions" : "NULL AS usage_instructions",
        hasTableColumn('business_items', 'properties_other') ? "{$alias}.properties_other" : "NULL AS properties_other",
        hasTableColumn('business_items', 'unit') ? "{$alias}.unit" : "NULL AS unit",
        "{$alias}.category_id",
        hasTableColumn('business_items', 'image_gallery') ? "{$alias}.image_gallery" : "NULL AS image_gallery",
        hasTableColumn('business_items', 'photo_path') ? "{$alias}.photo_path" : "NULL AS photo_path",
        hasTableColumn('business_items', 'is_flash_sale') ? "{$alias}.is_flash_sale" : "0 AS is_flash_sale",
        hasTableColumn('business_items', 'promotion_label') ? "{$alias}.promotion_label" : "NULL AS promotion_label",
        hasTableColumn('business_items', 'badges') ? "{$alias}.badges" : "NULL AS badges",
    ];

    $imageSources = [];
    if (hasTableColumn('business_items', 'image_url')) {
        $imageSources[] = "NULLIF({$alias}.image_url, '')";
    }
    if (hasTableColumn('business_items', 'photo_path')) {
        $imageSources[] = "NULLIF({$alias}.photo_path, '')";
    }

    $fields[] = (count($imageSources) > 0 ? 'COALESCE(' . implode(', ', $imageSources) . ')' : 'NULL') . ' AS image_url';

    return $fields;
}

function buildProductSearchWhere(string $alias, string $search, array &$params): ?string {
    $searchableColumns = ['name', 'description', 'sku', 'manufacturer', 'generic_name'];
    $parts = [];

    foreach ($searchableColumns as $column) {
        if ($column === 'name' || hasTableColumn('business_items', $column)) {
            $parts[] = "{$alias}.{$column} LIKE ?";
            $params[] = "%{$search}%";
        }
    }

    if (empty($parts)) {
        return null;
    }

    return '(' . implode(' OR ', $parts) . ')';
}

function buildProductSortClause(string $sort, string $alias = 'p'): string {
    $effectivePrice = "COALESCE(NULLIF({$alias}.sale_price, ''), NULLIF({$alias}.price, ''), 0)";
    $discountExpr = "CASE WHEN {$alias}.price IS NOT NULL AND {$alias}.sale_price IS NOT NULL AND {$alias}.sale_price < {$alias}.price AND {$alias}.price > 0 THEN (1 - {$alias}.sale_price / {$alias}.price) ELSE 0 END";

    switch ($sort) {
        case 'price_asc':
            return "{$effectivePrice} ASC, {$alias}.id DESC";
        case 'price_desc':
            return "{$effectivePrice} DESC, {$alias}.id DESC";
        case 'discount':
            return "{$discountExpr} DESC, {$alias}.id DESC";
        case 'name_asc':
            return "{$alias}.name ASC";
        default:
            return "{$alias}.id DESC";
    }
}

function checkoutBuildOdooProductSortClause(string $sort, bool $hasFeatured): string
{
    $list = 'list_price';
    $online = 'online_price';
    $eff = "COALESCE(NULLIF({$online},0), NULLIF({$list},0), 0)";
    $disc = "CASE WHEN {$list} IS NOT NULL AND {$online} IS NOT NULL AND {$online} > 0 AND {$online} < {$list} AND {$list} > 0
        THEN (1 - {$online}/{$list}) ELSE 0 END";
    switch ($sort) {
        case 'price_asc':
            return "{$eff} ASC, id DESC";
        case 'price_desc':
            return "{$eff} DESC, id DESC";
        case 'discount':
            return "{$disc} DESC, id DESC";
        case 'name_asc':
            return 'name ASC';
        default:
            if ($hasFeatured) {
                return 'featured_order IS NULL, featured_order ASC, name ASC';
            }

            return 'id DESC';
    }
}

/**
 * Odoo storefront product list — same source as api/shop-products.php (odoo_products_cache).
 */
function checkoutHandleGetProductsOdoo(int $lineAccountId): void
{
    global $db;

    $categoryId = $_GET['category_id'] ?? null;
    $search = trim((string) ($_GET['search'] ?? ''));
    $sort = $_GET['sort'] ?? 'latest';
    $brand = trim((string) ($_GET['brand'] ?? ''));
    $limit = max(1, min(24, (int) ($_GET['limit'] ?? 12)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    try {
        $hasOverrides = schema_table_has_column($db, 'odoo_products_cache', 'admin_overrides');
        $hasFeatured = schema_table_has_column($db, 'odoo_products_cache', 'featured_order');
        $hasManufacturer = schema_table_has_column($db, 'odoo_products_cache', 'manufacturer');
        $extra = $hasOverrides ? ', admin_overrides' : '';

        $where = ['line_account_id = ?', 'storefront_enabled = 1', 'is_active = 1'];
        $params = [$lineAccountId];

        if ($categoryId !== null && $categoryId !== '') {
            $where[] = 'category = ?';
            $params[] = $categoryId;
        }

        if ($search !== '') {
            $where[] = '(name LIKE ? OR sku LIKE ? OR product_code LIKE ? OR barcode LIKE ? OR generic_name LIKE ?)';
            $like = "%{$search}%";
            array_push($params, $like, $like, $like, $like, $like);
        }

        if ($brand !== '' && $hasManufacturer) {
            $where[] = 'manufacturer = ?';
            $params[] = $brand;
        } elseif ($brand !== '') {
            jsonResponse(true, '', [
                'products' => [],
                'categories' => [],
                'brands' => [],
                'offset' => $offset,
                'limit' => $limit,
                'total' => 0,
                'has_more' => false,
                'product_catalog_source' => 'odoo_products_cache',
                'category_id_is_string' => true,
            ]);

            return;
        }

        $whereClause = implode(' AND ', $where);
        $orderBy = checkoutBuildOdooProductSortClause($sort, $hasFeatured);

        $countSql = "SELECT COUNT(*) FROM odoo_products_cache WHERE {$whereClause}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT *{$extra} FROM odoo_products_cache WHERE {$whereClause} ORDER BY {$orderBy} LIMIT ? OFFSET ?";
        $stmt = $db->prepare($sql);
        $pi = 1;
        foreach ($params as $p) {
            $stmt->bindValue($pi++, $p);
        }
        $stmt->bindValue($pi++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($pi, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $products = [];
        foreach ($rows as $row) {
            $p = formatOdooProductForLiff($row);
            normalizeShopProductRow($p);
            $products[] = $p;
        }

        $catStmt = $db->prepare(
            "SELECT DISTINCT category FROM odoo_products_cache
             WHERE line_account_id = ?
               AND storefront_enabled = 1
               AND is_active = 1
               AND category IS NOT NULL AND category <> ''
             ORDER BY category ASC"
        );
        $catStmt->execute([$lineAccountId]);
        $catNames = $catStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $categories = [];
        foreach ($catNames as $cname) {
            $categories[] = [
                'id' => $cname,
                'name' => $cname,
                'icon_url' => null,
            ];
        }

        $brands = [];
        if ($hasManufacturer) {
            $brandSql = "SELECT DISTINCT manufacturer FROM odoo_products_cache
                         WHERE line_account_id = ?
                           AND storefront_enabled = 1
                           AND is_active = 1
                           AND manufacturer IS NOT NULL AND manufacturer <> ''";
            $bp = [$lineAccountId];
            if ($categoryId !== null && $categoryId !== '') {
                $brandSql .= ' AND category = ?';
                $bp[] = $categoryId;
            }
            $brandSql .= ' ORDER BY manufacturer ASC LIMIT 16';
            $bStmt = $db->prepare($brandSql);
            $bStmt->execute($bp);
            $brands = array_values(array_filter(array_map(
                static fn(array $row) => $row['manufacturer'] ?? null,
                $bStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
            )));
        }

        jsonResponse(true, '', [
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total,
            'has_more' => $offset + $limit < $total,
            'product_catalog_source' => 'odoo_products_cache',
            'category_id_is_string' => true,
        ]);
    } catch (Exception $e) {
        jsonResponse(false, 'Error loading products: ' . $e->getMessage());
    }
}

/**
 * Single product for LINE mini-app product detail page (GET action=product_detail).
 */
function handleGetProductDetail() {
    global $db;

    $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    $lineAccountId = $_GET['line_account_id'] ?? null;
    $lineUserId = $_GET['line_user_id'] ?? null;

    if ($productId <= 0) {
        jsonResponse(false, 'Missing product_id');
    }

    $lineAccountInt = ($lineAccountId !== null && $lineAccountId !== '') ? (int) $lineAccountId : 0;
    if ($lineAccountInt > 0 && useOdooStorefrontCatalog($db, $lineAccountInt)) {
        try {
            $hasOverrides = schema_table_has_column($db, 'odoo_products_cache', 'admin_overrides');
            $extra = $hasOverrides ? ', admin_overrides' : '';
            $stmt = $db->prepare(
                "SELECT *{$extra} FROM odoo_products_cache
                 WHERE line_account_id = ? AND id = ?
                   AND storefront_enabled = 1 AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([$lineAccountInt, $productId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                jsonResponse(false, 'Product not found');
            }
            $product = formatOdooProductForLiff($row);
            normalizeShopProductRow($product);
            jsonResponse(true, '', ['product' => $product]);
        } catch (Exception $e) {
            jsonResponse(false, 'Error loading product: ' . $e->getMessage());
        }

        return;
    }

    try {
        $wishlistUserId = getExistingUserIdFromLineUserId($lineUserId);
        $canJoinWishlist = $wishlistUserId !== null && tableExists('user_wishlist');
        $selectFields = buildBusinessItemSelectFields('p');
        $selectFields[] = "c.name AS category_name";
        $selectFields[] = ($canJoinWishlist ? "CASE WHEN uw.id IS NULL THEN 0 ELSE 1 END AS is_favorite" : "0 AS is_favorite");

        $params = [];
        if ($canJoinWishlist) {
            $params[] = $wishlistUserId;
        }
        $params[] = $productId;

        $sql = "SELECT " . implode(', ', $selectFields) . "
                FROM business_items p
                LEFT JOIN business_categories c ON c.id = p.category_id AND c.is_active = 1
                " . ($canJoinWishlist ? "LEFT JOIN user_wishlist uw ON uw.product_id = p.id AND uw.user_id = ?" : "") . "
                WHERE p.id = ? AND p.is_active = 1";

        if ($lineAccountId !== null && $lineAccountId !== '') {
            $sql .= " AND (p.line_account_id = ? OR p.line_account_id IS NULL)";
            $params[] = $lineAccountId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            jsonResponse(false, 'Product not found');
        }

        normalizeShopProductRow($product);
        jsonResponse(true, '', ['product' => $product]);
    } catch (Exception $e) {
        jsonResponse(false, 'Error loading product: ' . $e->getMessage());
    }
}

/**
 * List shop orders (transactions) for LINE user — B2C CRM (MySQL), not Odoo
 */
function handleMyOrders($data) {
    global $db;

    $lineUserId = null;
    if (is_array($data) && !empty($data['line_user_id'])) {
        $lineUserId = $data['line_user_id'];
    }
    $lineAccountId = null;
    if (is_array($data) && isset($data['line_account_id']) && $data['line_account_id'] !== '') {
        $lineAccountId = (int) $data['line_account_id'];
    }

    if (!$lineUserId) {
        jsonResponse(false, 'Missing line_user_id');
    }

    $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        jsonResponse(true, '', ['orders' => [], 'total' => 0, 'source' => 'shop']);
    }

    $userId = (int) $user['id'];
    $params = [$userId];
    $sql = "SELECT id, order_number, status, payment_status, grand_total, created_at, tracking_number, line_account_id
            FROM transactions
            WHERE user_id = ?";
    if ($lineAccountId) {
        $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
        $params[] = $lineAccountId;
    }
    $sql .= " ORDER BY created_at DESC LIMIT 50";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($orders as $row) {
        $grand = isset($row['grand_total']) ? floatval($row['grand_total']) : null;
        $created = $row['created_at'] ?? null;
        $pay = $row['payment_status'] ?? '';
        $st = $row['status'] ?? '';
        $out[] = array_merge($row, [
            'order_name' => $row['order_number'] ?? '',
            'order_id' => isset($row['id']) ? (int) $row['id'] : 0,
            'amount_total' => $grand,
            'date_order' => $created,
            'state' => $st,
            'items_count' => 0,
            'is_paid' => in_array($pay, ['paid'], true) || in_array($st, ['confirmed', 'preparing', 'processing', 'shipped', 'shipping', 'delivered', 'completed'], true),
            'is_delivered' => in_array($st, ['delivered', 'completed'], true),
        ]);
    }

    jsonResponse(true, '', ['orders' => $out, 'total' => count($out), 'source' => 'shop']);
}

/**
 * Get products list for shop page
 */
function handleGetProducts() {
    global $db;

    $lineAccountId = $_GET['line_account_id'] ?? null;
    $lineAccountInt = ($lineAccountId !== null && $lineAccountId !== '') ? (int) $lineAccountId : 0;
    if ($lineAccountInt > 0 && useOdooStorefrontCatalog($db, $lineAccountInt)) {
        checkoutHandleGetProductsOdoo($lineAccountInt);

        return;
    }

    $categoryId = $_GET['category_id'] ?? null;
    $search = trim((string) ($_GET['search'] ?? ''));
    $sort = $_GET['sort'] ?? 'latest';
    $brand = trim((string) ($_GET['brand'] ?? ''));
    $lineUserId = $_GET['line_user_id'] ?? null;
    $limit = max(1, min(24, (int) ($_GET['limit'] ?? 12)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    try {
        $wishlistUserId = getExistingUserIdFromLineUserId($lineUserId);
        $canJoinWishlist = $wishlistUserId !== null && tableExists('user_wishlist');
        $selectFields = buildBusinessItemSelectFields('p');
        $selectFields[] = "c.name AS category_name";
        $selectFields[] = ($canJoinWishlist ? "CASE WHEN uw.id IS NULL THEN 0 ELSE 1 END AS is_favorite" : "0 AS is_favorite");

        $whereParts = ["p.is_active = 1"];
        $whereParams = [];

        if ($lineAccountId) {
            $whereParts[] = "(p.line_account_id = ? OR p.line_account_id IS NULL)";
            $whereParams[] = $lineAccountId;
        }

        if ($categoryId) {
            $whereParts[] = "p.category_id = ?";
            $whereParams[] = $categoryId;
        }

        if ($search) {
            $searchWhere = buildProductSearchWhere('p', $search, $whereParams);
            if ($searchWhere !== null) {
                $whereParts[] = $searchWhere;
            }
        }

        if ($brand !== '') {
            if (!hasTableColumn('business_items', 'manufacturer')) {
                jsonResponse(true, '', [
                    'products' => [],
                    'categories' => [],
                    'brands' => [],
                    'offset' => $offset,
                    'limit' => $limit,
                    'total' => 0,
                    'has_more' => false,
                ]);
            }
            $whereParts[] = "p.manufacturer = ?";
            $whereParams[] = $brand;
        }

        $whereSql = implode(' AND ', $whereParts);
        $sql = "SELECT " . implode(', ', $selectFields) . "
                FROM business_items p
                LEFT JOIN business_categories c ON c.id = p.category_id AND c.is_active = 1
                " . ($canJoinWishlist ? "LEFT JOIN user_wishlist uw ON uw.product_id = p.id AND uw.user_id = ?" : "") . "
                WHERE {$whereSql}
                ORDER BY " . buildProductSortClause($sort, 'p') . "
                LIMIT ? OFFSET ?";

        $stmt = $db->prepare($sql);
        $bindIndex = 1;
        if ($canJoinWishlist) {
            $stmt->bindValue($bindIndex++, $wishlistUserId, PDO::PARAM_INT);
        }
        foreach ($whereParams as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$row) {
            normalizeShopProductRow($row);
        }
        unset($row);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM business_items p WHERE {$whereSql}");
        $countStmt->execute($whereParams);
        $total = (int) $countStmt->fetchColumn();

        // Get categories
        $catIconExpr = hasTableColumn('business_categories', 'icon_url') ? 'icon_url' : 'NULL AS icon_url';
        $catSql = "SELECT id, name, {$catIconExpr} FROM business_categories WHERE is_active = 1";
        $catParams = [];
        if ($lineAccountId) {
            $catSql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $catParams[] = $lineAccountId;
        }
        $catSql .= " ORDER BY sort_order, name";
        $catStmt = $db->prepare($catSql);
        $catStmt->execute($catParams);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        $brands = [];
        if (hasTableColumn('business_items', 'manufacturer')) {
            $brandSql = "SELECT DISTINCT p.manufacturer
                         FROM business_items p
                         WHERE p.is_active = 1
                           AND p.manufacturer IS NOT NULL
                           AND p.manufacturer != ''";
            $brandParams = [];
            if ($lineAccountId) {
                $brandSql .= " AND (p.line_account_id = ? OR p.line_account_id IS NULL)";
                $brandParams[] = $lineAccountId;
            }
            if ($categoryId) {
                $brandSql .= " AND p.category_id = ?";
                $brandParams[] = $categoryId;
            }
            $brandSql .= " ORDER BY p.manufacturer ASC LIMIT 16";
            $brandStmt = $db->prepare($brandSql);
            $brandStmt->execute($brandParams);
            $brands = array_values(array_filter(array_map(static fn(array $row) => $row['manufacturer'] ?? null, $brandStmt->fetchAll(PDO::FETCH_ASSOC))));
        }

        jsonResponse(true, '', [
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total,
            'has_more' => $offset + $limit < $total,
        ]);
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

    ensureCartProductSourceSupport($db);

    $lineUserId = $data['line_user_id'] ?? null;
    $productId = (int) ($data['product_id'] ?? 0);
    $quantity = max(1, intval($data['quantity'] ?? 1));

    if (!$lineUserId || $productId <= 0) {
        jsonResponse(false, 'Missing required fields');
    }

    list($userId, $lineAccountId) = getUserIdFromLineUserId($lineUserId);

    if (!$userId) {
        jsonResponse(false, 'User not found');
    }

    $productSource = resolveCartProductSourceWithShopDefault($db, $data['product_source'] ?? null, (int) $lineAccountId);

    if ($productSource === 'odoo_products_cache' && !hasTableColumn('cart_items', 'product_source')) {
        jsonResponse(false, 'Cart migration required (product_source)');
    }

    if ($productSource === 'odoo_products_cache') {
        if (!tableExists('odoo_products_cache')) {
            jsonResponse(false, 'Product not found');
        }
        $stmt = $db->prepare(
            "SELECT id, name, list_price, online_price, saleable_qty
             FROM odoo_products_cache
             WHERE id = ? AND line_account_id = ?
               AND storefront_enabled = 1 AND is_active = 1"
        );
        $stmt->execute([$productId, $lineAccountId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            jsonResponse(false, 'Product not found');
        }
        $stock = (float) ($product['saleable_qty'] ?? 0);
        if ($stock < $quantity) {
            jsonResponse(false, 'Not enough stock');
        }
    } else {
        $stmt = $db->prepare('SELECT id, name, price, sale_price, stock FROM business_items WHERE id = ? AND is_active = 1');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            jsonResponse(false, 'Product not found');
        }
        if (isset($product['stock']) && $product['stock'] !== null && $product['stock'] < $quantity) {
            jsonResponse(false, 'Not enough stock');
        }
    }

    $hasPs = hasTableColumn('cart_items', 'product_source');

    try {
        if ($hasPs) {
            $stmt = $db->prepare(
                'INSERT INTO cart_items (user_id, line_user_id, product_id, product_source, quantity)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
            );
            $stmt->execute([$userId, $lineUserId, $productId, $productSource, $quantity]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO cart_items (user_id, line_user_id, product_id, quantity)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
            );
            $stmt->execute([$userId, $lineUserId, $productId, $quantity]);
        }
    } catch (Exception $e) {
        try {
            if ($hasPs) {
                $stmt = $db->prepare(
                    'SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ? AND product_source = ?'
                );
                $stmt->execute([$userId, $productId, $productSource]);
            } else {
                $stmt = $db->prepare('SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?');
                $stmt->execute([$userId, $productId]);
            }
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $newQty = $existing['quantity'] + $quantity;
                $stmt = $db->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?');
                $stmt->execute([$newQty, $existing['id']]);
            } elseif ($hasPs) {
                $stmt = $db->prepare(
                    'INSERT INTO cart_items (user_id, line_user_id, product_id, product_source, quantity) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$userId, $lineUserId, $productId, $productSource, $quantity]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO cart_items (user_id, line_user_id, product_id, quantity) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$userId, $lineUserId, $productId, $quantity]);
            }
        } catch (Exception $e2) {
            $stmt = $db->prepare('SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?');
            $stmt->execute([$userId, $productId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $newQty = $existing['quantity'] + $quantity;
                $stmt = $db->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?');
                $stmt->execute([$newQty, $existing['id']]);
            } else {
                $stmt = $db->prepare('INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)');
                $stmt->execute([$userId, $productId, $quantity]);
            }
        }
    }

    $stmt = $db->prepare('SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ?');
    $stmt->execute([$userId]);
    $cartCount = $stmt->fetchColumn() ?: 0;

    jsonResponse(true, 'Added to cart', [
        'cart_count' => (int) $cartCount,
        'product_name' => $product['name'],
    ]);
}

/**
 * Update cart item quantity
 */
function handleUpdateCart($data) {
    global $db;

    ensureCartProductSourceSupport($db);

    $lineUserId = $data['line_user_id'] ?? null;
    $productId = (int) ($data['product_id'] ?? 0);
    $quantity = intval($data['quantity'] ?? 0);

    if (!$lineUserId || $productId <= 0) {
        jsonResponse(false, 'Missing required fields');
    }

    list($userId, $lineAccountId) = getUserIdFromLineUserId($lineUserId);

    if (!$userId) {
        jsonResponse(false, 'User not found');
    }

    $productSource = resolveCartProductSourceWithShopDefault($db, $data['product_source'] ?? null, (int) $lineAccountId);

    $hasPs = hasTableColumn('cart_items', 'product_source');
    $whereExtra = $hasPs ? ' AND IFNULL(product_source, \'business_items\') = ?' : '';
    $whereParams = $hasPs ? [$userId, $productId, $productSource] : [$userId, $productId];

    if ($quantity <= 0) {
        $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?{$whereExtra}");
        $stmt->execute($whereParams);
    } else {
        if ($productSource === 'odoo_products_cache' && tableExists('odoo_products_cache')) {
            $stmt = $db->prepare(
                'SELECT saleable_qty FROM odoo_products_cache WHERE id = ? AND line_account_id = ? AND storefront_enabled = 1 AND is_active = 1'
            );
            $stmt->execute([$productId, $lineAccountId]);
            $stock = $stmt->fetchColumn();
            if ($stock !== null && $stock !== false && (float) $stock < $quantity) {
                jsonResponse(false, 'Not enough stock');
            }
        } else {
            $stmt = $db->prepare('SELECT stock FROM business_items WHERE id = ?');
            $stmt->execute([$productId]);
            $stock = $stmt->fetchColumn();
            if ($stock !== null && $stock !== false && $stock < $quantity) {
                jsonResponse(false, 'Not enough stock');
            }
        }

        $stmt = $db->prepare(
            "UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE user_id = ? AND product_id = ?{$whereExtra}"
        );
        $stmt->execute(array_merge([$quantity], $whereParams));
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

    ensureCartProductSourceSupport($db);

    $lineUserId = $data['line_user_id'] ?? null;
    $productId = (int) ($data['product_id'] ?? 0);

    if (!$lineUserId || $productId <= 0) {
        jsonResponse(false, 'Missing required fields');
    }

    list($userId, $lineAccountId) = getUserIdFromLineUserId($lineUserId);

    if (!$userId) {
        jsonResponse(false, 'User not found');
    }

    $productSource = resolveCartProductSourceWithShopDefault($db, $data['product_source'] ?? null, (int) $lineAccountId);

    if (hasTableColumn('cart_items', 'product_source')) {
        $stmt = $db->prepare(
            'DELETE FROM cart_items WHERE user_id = ? AND product_id = ? AND IFNULL(product_source, \'business_items\') = ?'
        );
        $stmt->execute([$userId, $productId, $productSource]);
    } else {
        $stmt = $db->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?');
        $stmt->execute([$userId, $productId]);
    }

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

    ensureCartProductSourceSupport($db);

    if (hasTableColumn('cart_items', 'product_source') && tableExists('odoo_products_cache')) {
        $stmt = $db->prepare(
            'SELECT c.*,
                    p.name AS bi_name,
                    p.price AS bi_price,
                    p.sale_price AS bi_sale_price,
                    p.image_url AS bi_image_url,
                    p.is_active AS bi_is_active,
                    o.name AS o_name,
                    o.list_price AS o_list,
                    o.online_price AS o_online,
                    o.product_code AS o_pc,
                    o.sku AS o_sku,
                    o.is_active AS o_is_active
             FROM cart_items c
             LEFT JOIN business_items p
               ON c.product_id = p.id
              AND IFNULL(NULLIF(c.product_source, \'\'), \'business_items\') = \'business_items\'
             LEFT JOIN odoo_products_cache o
               ON c.product_id = o.id
              AND c.product_source = \'odoo_products_cache\'
              AND o.line_account_id = ?
             WHERE c.user_id = ?'
        );
        $stmt->execute([$lineAccountId ?: 0, $userId]);
    } else {
        $stmt = $db->prepare(
            'SELECT c.*, p.name AS bi_name, p.price AS bi_price, p.sale_price AS bi_sale_price,
                    p.image_url AS bi_image_url, p.is_active AS bi_is_active,
                    NULL AS o_name, NULL AS o_list, NULL AS o_online, NULL AS o_pc, NULL AS o_sku, NULL AS o_is_active
             FROM cart_items c
             LEFT JOIN business_items p ON c.product_id = p.id
             WHERE c.user_id = ?'
        );
        $stmt->execute([$userId]);
    }
    $allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $debugInfo['raw_cart_count'] = count($allItems);

    $items = [];
    $filteredOut = [];
    foreach ($allItems as $item) {
        $src = resolveCartProductSource($item['product_source'] ?? null);
        if ($src === 'odoo_products_cache') {
            $name = $item['o_name'] ?? null;
            $unit = odooCartLineUnitPrice([
                'o_list' => $item['o_list'] ?? 0,
                'o_online' => $item['o_online'] ?? 0,
            ]);
            $item['name'] = $name;
            $item['price'] = (float) ($item['o_list'] ?? 0);
            $on = (float) ($item['o_online'] ?? 0);
            $item['sale_price'] = ($on > 0 && $on < (float) $item['price']) ? $on : (($on > 0 && (float) $item['price'] <= 0) ? $on : null);
            if ((float) $item['price'] <= 0 && $on > 0) {
                $item['price'] = $on;
                $item['sale_price'] = null;
            }
            $item['image_url'] = buildManagerProductPhotoUrl($item['o_pc'] ?? '', $item['o_sku'] ?? '');
            $item['is_active'] = isset($item['o_is_active']) ? (int) $item['o_is_active'] : 1;
            $item['product_source'] = 'odoo_products_cache';
            $lineSubtotal = $unit * (int) $item['quantity'];
            $item['subtotal'] = $lineSubtotal;
        } else {
            $item['name'] = $item['bi_name'] ?? null;
            $item['price'] = $item['bi_price'] ?? null;
            $item['sale_price'] = $item['bi_sale_price'] ?? null;
            $item['image_url'] = $item['bi_image_url'] ?? null;
            $item['is_active'] = $item['bi_is_active'] ?? null;
            $item['product_source'] = 'business_items';
            $bp = $item['bi_price'];
            $bs = $item['bi_sale_price'];
            $lineUnit = (float) (($bs !== null && $bs !== '' && (float) $bs > 0) ? $bs : ($bp ?? 0));
            $item['subtotal'] = $lineUnit * (int) $item['quantity'];
        }

        if (!empty($item['name'])) {
            $items[] = $item;
        } else {
            $filteredOut[] = [
                'product_id' => $item['product_id'],
                'reason' => 'product_deleted',
            ];
        }
    }

    $debugInfo['filtered_cart_count'] = count($items);
    $debugInfo['filtered_out'] = $filteredOut;

    $subtotal = 0;
    foreach ($items as &$item) {
        $item['subtotal'] = floatval($item['subtotal']);
        $subtotal += $item['subtotal'];
    }
    unset($item);

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
    $requestLineAccountId = $data['line_account_id'] ?? null;
    $address = $data['address'] ?? [];
    $paymentMethod = $data['payment_method'] ?? 'transfer';
    $displayName = $data['display_name'] ?? ($address['name'] ?? 'LIFF User');
    $cartItems = $data['cart_items'] ?? null; // Cart items from request
    $requestSubtotal = $data['subtotal'] ?? null;
    $requestShipping = $data['shipping'] ?? null;
    $requestTotal = $data['total'] ?? null;

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
            // Auto-create user
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

    // Fallback line_account_id
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

    // Full user row for notifications (also set when user was just INSERTed)
    $stmt = $db->prepare("SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $user = ['display_name' => $displayName, 'line_user_id' => $lineUserId];
    }

    // Use cart items from request if provided, otherwise get from database
    $items = [];
    if (!empty($cartItems) && is_array($cartItems)) {
        foreach ($cartItems as $item) {
            $unit = floatval($item['price'] ?? 0);
            $items[] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'name' => $item['name'] ?? '',
                'price' => $unit,
                'sale_price' => $unit,
                'quantity' => (int) ($item['quantity'] ?? 0),
                'product_source' => resolveCartProductSource($item['product_source'] ?? null),
                '_unit' => $unit,
            ];
        }
        error_log('handleCreateOrder: Using ' . count($items) . ' items from request');
    } else {
        $items = loadCheckoutCartLinesFromDb($db, (int) $userId, $lineAccountId ? (int) $lineAccountId : null);
        error_log('handleCreateOrder: Using ' . count($items) . ' items from database');
    }

    if (empty($items)) {
        jsonResponse(false, 'Cart is empty');
    }

    $subtotal = 0;
    foreach ($items as $item) {
        $unit = isset($item['_unit']) ? (float) $item['_unit'] : checkoutOrderUnitPrice($item);
        $subtotal += $unit * (int) $item['quantity'];
    }

    // Use request values if provided
    if ($requestSubtotal !== null) {
        $subtotal = floatval($requestSubtotal);
    }

    // Get shipping fee
    $shippingFee = 0;
    if ($requestShipping !== null) {
        $shippingFee = floatval($requestShipping);
    } else {
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
    }

    $total = ($requestTotal !== null) ? floatval($requestTotal) : ($subtotal + $shippingFee);

    // Build delivery info - keep all fields separate for future use
    $deliveryInfo = [
        'type' => 'shipping',
        'name' => $address['name'] ?? '',
        'phone' => $address['phone'] ?? '',
        'address' => $address['address'] ?? '',
        'subdistrict' => $address['subdistrict'] ?? '',
        'district' => $address['district'] ?? '',
        'province' => $address['province'] ?? '',
        'postcode' => $address['postcode'] ?? '',
        'full_address' => trim(implode(' ', array_filter([
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

        // กำหนดสถานะตาม payment method
        // COD: ข้ามขั้นตอนรอชำระเงิน ไปยืนยันออเดอร์เลย
        $orderStatus = ($paymentMethod === 'cod') ? 'confirmed' : 'pending';
        // payment_status ต้องอยู่ใน ENUM('pending','paid','failed','refunded') — cod ใช้ pending เหมือนกัน
        $paymentStatus = 'pending';

        // Auto-add payment_status column if missing (defensive migration)
        try {
            $db->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT 'pending'");
        } catch (Exception $e) {
            // Ignore: column already exists or DB doesn't support IF NOT EXISTS
        }

        // Level 1: full insert (all columns)
        $inserted = false;
        try {
            $stmt = $db->prepare("
                INSERT INTO transactions
                (line_account_id, transaction_type, order_number, user_id, line_user_id, total_amount, shipping_fee, grand_total, delivery_info, payment_method, status, payment_status)
                VALUES (?, 'purchase', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $lineAccountId,
                $orderNumber,
                $userId,
                $lineUserId,
                $subtotal,
                $shippingFee,
                $total,
                json_encode($deliveryInfo, JSON_UNESCAPED_UNICODE),
                $paymentMethod,
                $orderStatus,
                $paymentStatus
            ]);
            $inserted = true;
        } catch (Exception $e) {
            error_log("checkout create_order level1 failed: " . $e->getMessage());
        }

        // Level 2: without line_user_id
        if (!$inserted) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO transactions
                    (line_account_id, transaction_type, order_number, user_id, total_amount, shipping_fee, grand_total, delivery_info, payment_method, status, payment_status)
                    VALUES (?, 'purchase', ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $lineAccountId,
                    $orderNumber,
                    $userId,
                    $subtotal,
                    $shippingFee,
                    $total,
                    json_encode($deliveryInfo, JSON_UNESCAPED_UNICODE),
                    $paymentMethod,
                    $orderStatus,
                    $paymentStatus
                ]);
                $inserted = true;
            } catch (Exception $e) {
                error_log("checkout create_order level2 failed: " . $e->getMessage());
            }
        }

        // Level 3: without payment_status (oldest schema fallback)
        if (!$inserted) {
            $stmt = $db->prepare("
                INSERT INTO transactions
                (line_account_id, transaction_type, order_number, user_id, total_amount, shipping_fee, grand_total, delivery_info, payment_method, status)
                VALUES (?, 'purchase', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $lineAccountId,
                $orderNumber,
                $userId,
                $subtotal,
                $shippingFee,
                $total,
                json_encode($deliveryInfo, JSON_UNESCAPED_UNICODE),
                $paymentMethod,
                $orderStatus
            ]);
        }

        $orderId = $db->lastInsertId();

        foreach ($items as $item) {
            $unit = isset($item['_unit']) ? (float) $item['_unit'] : checkoutOrderUnitPrice($item);
            $itemSubtotal = $unit * (int) $item['quantity'];
            $src = $item['product_source'] ?? 'business_items';

            $stmt = $db->prepare(
                'INSERT INTO transaction_items (transaction_id, product_id, product_name, product_price, quantity, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $orderId,
                $item['product_id'],
                $item['name'],
                $unit,
                $item['quantity'],
                $itemSubtotal,
            ]);

            if ($src === 'odoo_products_cache' && tableExists('odoo_products_cache')) {
                $stmt = $db->prepare(
                    'UPDATE odoo_products_cache SET saleable_qty = saleable_qty - ?, updated_at = NOW()
                     WHERE id = ? AND line_account_id = ? AND saleable_qty >= ?'
                );
                $stmt->execute([(int) $item['quantity'], (int) $item['product_id'], (int) $lineAccountId, (int) $item['quantity']]);
            } else {
                $stmt = $db->prepare('UPDATE business_items SET stock = stock - ? WHERE id = ? AND stock >= ?');
                $stmt->execute([(int) $item['quantity'], (int) $item['product_id'], (int) $item['quantity']]);

                try {
                    $tableCheck = $db->query("SHOW TABLES LIKE 'stock_movements'");
                    if ($tableCheck->rowCount() > 0) {
                        $stmtStock = $db->prepare('SELECT stock FROM business_items WHERE id = ?');
                        $stmtStock->execute([(int) $item['product_id']]);
                        $currentStock = $stmtStock->fetchColumn();

                        $stmt = $db->prepare(
                            'INSERT INTO stock_movements
                             (line_account_id, product_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, reference_number, notes, created_by)
                             VALUES (?, ?, \'sale\', ?, ?, ?, \'order\', ?, ?, ?, NULL)'
                        );
                        $stmt->execute([
                            $lineAccountId,
                            $item['product_id'],
                            -$item['quantity'],
                            $currentStock + $item['quantity'],
                            $currentStock,
                            $orderId,
                            $orderNumber,
                            'ขายสินค้า: ' . $item['name'],
                        ]);
                        error_log("Stock movement recorded: product_id={$item['product_id']}, qty=-{$item['quantity']}, order={$orderNumber}");
                    }
                } catch (Exception $e) {
                    error_log('Stock movement error: ' . $e->getMessage());
                }
            }
        }

        // Clear cart
        $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$userId]);

        // WMS Integration: Set wms_status to pending_pick for COD orders (already confirmed)
        if ($orderStatus === 'confirmed') {
            try {
                $stmt = $db->prepare("UPDATE transactions SET wms_status = 'pending_pick' WHERE id = ?");
                $stmt->execute([$orderId]);
            } catch (Exception $e) {
                // wms_status column may not exist, ignore
            }
        }

        $db->commit();

        // Hook: Auto-create Account Receivable for credit sales
        // Requirement 8.2: WHEN an Invoice is created from shop order THEN the Accounting System SHALL automatically create corresponding AR record
        // Credit sales are identified by payment methods that don't require immediate payment (credit, cod)
        $arId = null;
        $creditPaymentMethods = ['credit', 'cod', 'term', 'invoice']; // Payment methods that create AR
        if (in_array(strtolower($paymentMethod), $creditPaymentMethods)) {
            try {
                $arService = new AccountReceivableService($db, $lineAccountId);
                $arId = $arService->createFromTransaction($orderId);
                error_log("AR created for order {$orderNumber}: AR ID = {$arId}");
            } catch (Exception $arError) {
                // Log AR creation error but don't fail the order
                error_log("AR creation from order failed: " . $arError->getMessage());
            }
        }

        // 🔔 แจ้งเตือน Telegram เมื่อมี order ใหม่
        notifyTelegramNewOrder($orderId, $orderNumber, $total, $user, $deliveryInfo);

        // 🔔 แจ้งเตือนผ่าน LINE/Email ด้วย NotificationService
        try {
            require_once __DIR__ . '/../classes/NotificationService.php';
            $notifier = new NotificationService($db, $lineAccountId);
            $notifier->notifyNewOrder([
                'id' => $orderId,
                'order_number' => $orderNumber,
                'total_amount' => $total,
                'customer_name' => $user['display_name'] ?? 'ลูกค้า'
            ]);
        } catch (Exception $e) {
            error_log("NotificationService error: " . $e->getMessage());
        }

        // Log activity
        global $activityLogger;
        $activityLogger->logOrder(ActivityLogger::ACTION_CREATE, 'สร้างคำสั่งซื้อใหม่', [
            'user_id' => $userId,
            'entity_type' => 'order',
            'entity_id' => $orderId,
            'new_value' => [
                'order_number' => $orderNumber,
                'total' => $total,
                'items_count' => count($items),
                'payment_method' => $paymentMethod
            ],
            'line_account_id' => $lineAccountId
        ]);

        jsonResponse(true, 'Order created', [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total' => $total,
            'payment_method' => $paymentMethod,
            'ar_id' => $arId
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

    // 🔔 แจ้งเตือนผ่าน LINE/Email ด้วย NotificationService
    try {
        require_once __DIR__ . '/../classes/NotificationService.php';
        $notifier = new NotificationService($db, $order['line_account_id'] ?? null);
        $notifier->notifyPayment([
            'id' => $orderId,
            'order_number' => $order['order_number'],
            'total_amount' => $order['grand_total'] ?? $order['total_amount'],
            'customer_name' => $slipUser['display_name'] ?? 'ลูกค้า'
        ], $imageUrl);
    } catch (Exception $e) {
        error_log("NotificationService payment error: " . $e->getMessage());
    }

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

    // Parse delivery info
    $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);

    jsonResponse(true, '', [
        'order' => [
            'id' => $order['id'],
            'order_id' => $order['id'],
            'order_number' => $order['order_number'],
            'status' => $order['status'],
            'payment_status' => $order['payment_status'],
            'payment_method' => $order['payment_method'],
            'total_amount' => floatval($order['total_amount']),
            'shipping_fee' => floatval($order['shipping_fee'] ?? 0),
            'grand_total' => floatval($order['grand_total']),
            'delivery_info' => $deliveryInfo,
            'tracking_number' => $order['tracking_number'] ?? null,
            'carrier' => $order['carrier'] ?? null,
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'items' => $formattedItems
        ],
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
 * Validate promo/coupon code
 * Requirements: 17.4, 17.5, 17.6, 17.7
 * - Validate code via API
 * - Return discount amount or error
 */
function handleValidatePromo($data) {
    global $db;

    $code = strtoupper(trim($data['code'] ?? ''));
    $lineUserId = $data['line_user_id'] ?? null;
    $lineAccountId = $data['line_account_id'] ?? null;
    $subtotal = floatval($data['subtotal'] ?? 0);

    if (!$code) {
        jsonResponse(false, 'กรุณากรอกโค้ดส่วนลด', ['valid' => false]);
    }

    try {
        // Check if promotions table exists
        $tableCheck = $db->query("SHOW TABLES LIKE 'promotions'");
        if ($tableCheck->rowCount() === 0) {
            // Fallback: check for hardcoded promo codes
            $discount = validateHardcodedPromo($code, $subtotal);
            if ($discount > 0) {
                jsonResponse(true, 'โค้ดถูกต้อง', [
                    'valid' => true,
                    'discount' => $discount,
                    'discount_type' => 'fixed',
                    'code' => $code
                ]);
            } else {
                jsonResponse(false, 'โค้ดไม่ถูกต้องหรือหมดอายุ', ['valid' => false]);
            }
            return;
        }

        // Query promotion from database
        $sql = "SELECT * FROM promotions WHERE code = ? AND is_active = 1";
        $params = [$code];

        if ($lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $lineAccountId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $promo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$promo) {
            jsonResponse(false, 'โค้ดไม่ถูกต้อง', ['valid' => false]);
        }

        // Check validity dates
        $now = date('Y-m-d H:i:s');
        if ($promo['start_date'] && $now < $promo['start_date']) {
            jsonResponse(false, 'โค้ดยังไม่เริ่มใช้งาน', ['valid' => false]);
        }
        if ($promo['end_date'] && $now > $promo['end_date']) {
            jsonResponse(false, 'โค้ดหมดอายุแล้ว', ['valid' => false]);
        }

        // Check minimum order amount
        $minOrder = floatval($promo['min_order_amount'] ?? 0);
        if ($minOrder > 0 && $subtotal < $minOrder) {
            jsonResponse(false, "ยอดสั่งซื้อขั้นต่ำ ฿" . number_format($minOrder, 0), ['valid' => false]);
        }

        // Check usage limit
        if ($promo['usage_limit'] && $promo['usage_count'] >= $promo['usage_limit']) {
            jsonResponse(false, 'โค้ดถูกใช้ครบจำนวนแล้ว', ['valid' => false]);
        }

        // Check per-user limit
        if ($lineUserId && $promo['per_user_limit']) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM promotion_usage WHERE promotion_id = ? AND line_user_id = ?");
            $stmt->execute([$promo['id'], $lineUserId]);
            $userUsage = $stmt->fetchColumn();

            if ($userUsage >= $promo['per_user_limit']) {
                jsonResponse(false, 'คุณใช้โค้ดนี้ครบจำนวนแล้ว', ['valid' => false]);
            }
        }

        // Calculate discount
        $discount = 0;
        $discountType = $promo['discount_type'] ?? 'fixed';

        if ($discountType === 'percentage') {
            $discount = $subtotal * (floatval($promo['discount_value']) / 100);
            // Apply max discount cap if set
            if ($promo['max_discount'] && $discount > $promo['max_discount']) {
                $discount = floatval($promo['max_discount']);
            }
        } else {
            $discount = floatval($promo['discount_value']);
        }

        // Ensure discount doesn't exceed subtotal
        $discount = min($discount, $subtotal);

        jsonResponse(true, 'โค้ดถูกต้อง', [
            'valid' => true,
            'discount' => $discount,
            'discount_type' => $discountType,
            'discount_value' => floatval($promo['discount_value']),
            'code' => $code,
            'promo_id' => $promo['id'],
            'promo_name' => $promo['name'] ?? $code
        ]);

    } catch (Exception $e) {
        error_log("Promo validation error: " . $e->getMessage());
        jsonResponse(false, 'ไม่สามารถตรวจสอบโค้ดได้', ['valid' => false]);
    }
}

/**
 * Validate hardcoded promo codes (fallback when no promotions table)
 */
function validateHardcodedPromo($code, $subtotal) {
    // Define some sample promo codes
    $promoCodes = [
        'WELCOME10' => ['type' => 'percentage', 'value' => 10, 'min' => 100],
        'SAVE50' => ['type' => 'fixed', 'value' => 50, 'min' => 300],
        'FREESHIP' => ['type' => 'fixed', 'value' => 50, 'min' => 0],
        'NEWUSER' => ['type' => 'percentage', 'value' => 15, 'min' => 200, 'max' => 100],
    ];

    if (!isset($promoCodes[$code])) {
        return 0;
    }

    $promo = $promoCodes[$code];

    // Check minimum
    if ($subtotal < $promo['min']) {
        return 0;
    }

    // Calculate discount
    if ($promo['type'] === 'percentage') {
        $discount = $subtotal * ($promo['value'] / 100);
        if (isset($promo['max']) && $discount > $promo['max']) {
            $discount = $promo['max'];
        }
    } else {
        $discount = $promo['value'];
    }

    return min($discount, $subtotal);
}


/**
 * Get last delivery address from user's previous orders
 */
function handleGetLastAddress() {
    global $db;

    $lineUserId = $_GET['line_user_id'] ?? null;
    $userId = $_GET['user_id'] ?? null;

    // Get user ID from line_user_id
    if ($lineUserId && !$userId) {
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $userId = $stmt->fetchColumn();
    }

    if (!$userId) {
        jsonResponse(false, 'User not found', ['address' => null]);
    }

    try {
        // Get last order with delivery info
        $stmt = $db->prepare("
            SELECT delivery_info
            FROM transactions
            WHERE user_id = ? AND delivery_info IS NOT NULL AND delivery_info != '' AND delivery_info != '{}'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['delivery_info']) {
            $deliveryInfo = json_decode($result['delivery_info'], true);

            if ($deliveryInfo && !empty($deliveryInfo['name'])) {
                // Check if we have separate fields or just combined address
                $hasSeperateFields = !empty($deliveryInfo['subdistrict']) || !empty($deliveryInfo['district']) || !empty($deliveryInfo['province']);

                jsonResponse(true, 'Last address found', [
                    'address' => [
                        'name' => $deliveryInfo['name'] ?? '',
                        'phone' => $deliveryInfo['phone'] ?? '',
                        'address' => $hasSeperateFields ? ($deliveryInfo['address'] ?? '') : '',
                        'subdistrict' => $deliveryInfo['subdistrict'] ?? '',
                        'district' => $deliveryInfo['district'] ?? '',
                        'province' => $deliveryInfo['province'] ?? '',
                        'postcode' => $deliveryInfo['postcode'] ?? ''
                    ]
                ]);
            }
        }

        // No previous address found, try to get from user profile
        $stmt = $db->prepare("SELECT display_name, phone, address FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            jsonResponse(true, 'User profile found', [
                'address' => [
                    'name' => $user['display_name'] ?? '',
                    'phone' => $user['phone'] ?? '',
                    'address' => $user['address'] ?? '',
                    'subdistrict' => '',
                    'district' => '',
                    'province' => '',
                    'postcode' => ''
                ]
            ]);
        }

        jsonResponse(true, 'No address found', ['address' => null]);

    } catch (Exception $e) {
        error_log("Get last address error: " . $e->getMessage());
        jsonResponse(false, 'Error getting address', ['address' => null]);
    }
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

/**
 * Generate PromptPay QR Code Image
 * Returns PNG image directly
 */
function handlePromptPayQR() {
    global $db;

    $amount = floatval($_GET['amount'] ?? 0);
    $lineAccountId = $_GET['account'] ?? $_GET['line_account_id'] ?? 1;

    // Get PromptPay number from shop settings
    $promptpayNumber = '';
    try {
        $stmt = $db->prepare("SELECT promptpay_number FROM shop_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        $promptpayNumber = $settings['promptpay_number'] ?? '';

        // Fallback to first shop settings if not found
        if (empty($promptpayNumber)) {
            $stmt = $db->query("SELECT promptpay_number FROM shop_settings WHERE promptpay_number IS NOT NULL AND promptpay_number != '' LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            $promptpayNumber = $settings['promptpay_number'] ?? '';
        }
    } catch (Exception $e) {
        error_log('PromptPay QR error: ' . $e->getMessage());
    }

    if (empty($promptpayNumber)) {
        // Return empty image or error
        header('Content-Type: image/png');
        $img = imagecreate(200, 200);
        $bg = imagecolorallocate($img, 255, 255, 255);
        $textColor = imagecolorallocate($img, 150, 150, 150);
        imagestring($img, 3, 30, 90, 'PromptPay not configured', $textColor);
        imagepng($img);
        imagedestroy($img);
        exit;
    }

    // Generate PromptPay payload
    $payload = generatePromptPayPayload($promptpayNumber, $amount);

    if (!$payload) {
        header('Content-Type: image/png');
        $img = imagecreate(200, 200);
        $bg = imagecolorallocate($img, 255, 255, 255);
        $textColor = imagecolorallocate($img, 150, 150, 150);
        imagestring($img, 3, 50, 90, 'Invalid PromptPay', $textColor);
        imagepng($img);
        imagedestroy($img);
        exit;
    }

    // Generate QR Code using external API (simple approach)
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($payload);

    // Fetch and output the QR image
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');

    $qrImage = @file_get_contents($qrUrl);
    if ($qrImage) {
        echo $qrImage;
    } else {
        // Fallback: generate simple QR using GD (basic)
        $img = imagecreate(200, 200);
        $bg = imagecolorallocate($img, 255, 255, 255);
        $textColor = imagecolorallocate($img, 0, 0, 0);
        imagestring($img, 3, 40, 90, 'QR Generation Error', $textColor);
        imagepng($img);
        imagedestroy($img);
    }
    exit;
}

/**
 * Generate PromptPay EMVCo QR Payload
 */
function generatePromptPayPayload($promptpayNumber, $amount = 0) {
    // Clean the number
    $target = preg_replace('/[^0-9]/', '', $promptpayNumber);

    // Determine target type
    if (strlen($target) === 10) {
        // Phone number - add country code
        $target = '0066' . substr($target, 1);
        $targetType = '01'; // Phone
    } elseif (strlen($target) === 13) {
        $targetType = '02'; // National ID
    } else {
        return null;
    }

    // Build EMVCo QR payload
    $payload = '';

    // Payload Format Indicator
    $payload .= '000201';

    // Point of Initiation Method (12 = dynamic QR with amount)
    $payload .= '010212';

    // Merchant Account Information (PromptPay)
    $merchantInfo = '';
    $merchantInfo .= '0016A000000677010111'; // AID
    $merchantInfo .= $targetType . sprintf('%02d', strlen($target)) . $target;
    $payload .= '29' . sprintf('%02d', strlen($merchantInfo)) . $merchantInfo;

    // Transaction Currency (THB = 764)
    $payload .= '5303764';

    // Transaction Amount (if provided)
    if ($amount > 0) {
        $amountStr = number_format($amount, 2, '.', '');
        $payload .= '54' . sprintf('%02d', strlen($amountStr)) . $amountStr;
    }

    // Country Code
    $payload .= '5802TH';

    // CRC placeholder
    $payload .= '6304';

    // Calculate CRC16
    $crc = calculateCRC16CCITT($payload);
    $payload .= strtoupper(sprintf('%04X', $crc));

    return $payload;
}

/**
 * Calculate CRC16-CCITT for EMVCo QR
 */
function calculateCRC16CCITT($str) {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($str); $i++) {
        $crc ^= ord($str[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x8000) {
                $crc = ($crc << 1) ^ 0x1021;
            } else {
                $crc <<= 1;
            }
        }
        $crc &= 0xFFFF;
    }
    return $crc;
}

/**
 * Get shop payment info: shop_name, promptpay_number, bank_accounts
 * ดึงข้อมูลการชำระเงินของร้าน: ชื่อร้าน, พร้อมเพย์, บัญชีธนาคาร
 *
 * Scoped by line_account_id resolved from line_user_id (via users table)
 * or directly from the line_account_id GET/POST parameter.
 *
 * GET /api/checkout.php?action=shop_info&line_user_id=U...
 * GET /api/checkout.php?action=shop_info&line_account_id=1
 *
 * Response:
 * {
 *   "success": true,
 *   "message": "",
 *   "shop_name": "...",
 *   "promptpay_number": "...",
 *   "bank_accounts": [{"bank_name":"...","account_name":"...","account_number":"..."}] | null
 * }
 */
function handleGetShopInfo() {
    global $db;

    // Resolve line_account_id — prefer to derive from the authenticated LINE user
    $lineUserId   = $_GET['line_user_id']   ?? $_POST['line_user_id']   ?? null;
    $lineAccountId = $_GET['line_account_id'] ?? $_POST['line_account_id'] ?? null;

    if ($lineUserId) {
        $stmt = $db->prepare("SELECT line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $lineAccountId = $row['line_account_id'];
        }
    }

    // Last resort: use the default active account
    if (!$lineAccountId) {
        $stmt = $db->query("SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $lineAccountId = $row['id'] ?? 1;
    }

    try {
        // Primary: fetch for the resolved tenant
        $stmt = $db->prepare(
            "SELECT shop_name, promptpay_number, bank_accounts
             FROM shop_settings
             WHERE line_account_id = ?
             LIMIT 1"
        );
        $stmt->execute([$lineAccountId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fallback: any row that has at least one payment field configured
        if (empty($settings)) {
            $stmt = $db->query(
                "SELECT shop_name, promptpay_number, bank_accounts
                 FROM shop_settings
                 WHERE (promptpay_number IS NOT NULL AND promptpay_number != '')
                    OR (bank_accounts IS NOT NULL AND bank_accounts != '')
                 LIMIT 1"
            );
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (empty($settings)) {
            // No shop settings found at all — return safe empty values
            jsonResponse(true, 'ไม่พบข้อมูลร้าน / Shop settings not found', [
                'shop_name'        => '',
                'promptpay_number' => '',
                'bank_accounts'    => null,
            ]);
        }

        // Decode bank_accounts TEXT column as JSON if it looks like JSON
        $bankAccountsRaw = $settings['bank_accounts'] ?? null;
        $bankAccounts = null;
        if (!empty($bankAccountsRaw)) {
            $decoded = json_decode($bankAccountsRaw, true);
            // json_decode returns null on failure; keep raw string only if it wasn't JSON
            $bankAccounts = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $bankAccountsRaw;
        }

        jsonResponse(true, '', [
            'shop_name'        => $settings['shop_name']        ?? '',
            'promptpay_number' => $settings['promptpay_number'] ?? '',
            'bank_accounts'    => $bankAccounts,
        ]);

    } catch (Exception $e) {
        error_log('handleGetShopInfo error: ' . $e->getMessage());
        jsonResponse(false, 'ไม่สามารถดึงข้อมูลร้านได้ / Unable to load shop info');
    }
}
