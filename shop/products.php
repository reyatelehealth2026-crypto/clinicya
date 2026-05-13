<?php
/**
 * Shop Products - CNY Style UI with business_items table
 * Display products from business_items with CNY-style grid layout
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'สินค้า - ร้านยา';

// Check if table exists
$tableExists   = false;
$productsTable = 'business_items';
try {
    $db->query("SELECT 1 FROM {$productsTable} LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {
    // Try legacy products table
    try {
        $db->query("SELECT 1 FROM products LIMIT 1");
        $tableExists   = true;
        $productsTable = 'products';
    } catch (PDOException $e2) {
        // No table
    }
}

if (!$tableExists) {
    require_once __DIR__ . '/../includes/components/page-header.php';
    require_once __DIR__ . '/../includes/header.php';
    echo getPageHeaderStyles();
    echo renderPageHeader('สินค้าร้านยา', '', null,
        [['label' => 'ร้านค้า', 'href' => null], ['label' => 'สินค้า', 'href' => null]]);
    ?>
    <div style="background:var(--color-amber-50);border-left:4px solid var(--color-amber-500);padding:var(--space-6);border-radius:var(--radius-lg);">
        <h2 style="font-size:var(--text-xl);font-weight:700;color:var(--color-amber-800);margin:0 0 var(--space-4);">
            <i class="fas fa-exclamation-triangle" style="margin-right:var(--space-2);"></i>ยังไม่ได้ติดตั้งระบบสินค้า
        </h2>
        <p style="color:var(--color-amber-700);margin:0 0 var(--space-4);">กรุณารันคำสั่งต่อไปนี้เพื่อสร้างตาราง:</p>
        <div style="background:var(--color-dark-900);color:#4ade80;padding:var(--space-4);border-radius:var(--radius-md);font-family:var(--font-mono);font-size:var(--text-sm);margin-bottom:var(--space-4);">
            php install/install_fresh.php
        </div>
        <a href="../" style="display:inline-flex;align-items:center;gap:var(--space-2);padding:10px var(--space-4);background:var(--color-primary-600);color:#fff;border-radius:var(--radius-md);font-size:var(--text-sm);font-weight:600;text-decoration:none;">
            <i class="fas fa-arrow-left"></i>กลับหน้าหลัก
        </a>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Check available columns
$hasPhotoPath         = false;
$hasProductPrice      = false;
$hasEnable            = false;
$hasGenericName       = false;
$hasUsageInstructions = false;
$hasPropertiesOther   = false;
$hasNameEn            = false;

try {
    $stmt = $db->query("SHOW COLUMNS FROM {$productsTable}");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($col['Field'] === 'photo_path')         $hasPhotoPath         = true;
        if ($col['Field'] === 'product_price')       $hasProductPrice      = true;
        if ($col['Field'] === 'enable')              $hasEnable            = true;
        if ($col['Field'] === 'generic_name')        $hasGenericName       = true;
        if ($col['Field'] === 'usage_instructions')  $hasUsageInstructions = true;
        if ($col['Field'] === 'properties_other')    $hasPropertiesOther   = true;
        if ($col['Field'] === 'name_en')             $hasNameEn            = true;
    }
} catch (Exception $e) {}

// Get filters
$search      = $_GET['search'] ?? '';
$category    = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 24;
$offset      = ($page - 1) * $perPage;

// Build query
$where  = ["1=1"];
$params = [];

// Active filter - use enable if available, otherwise is_active
if ($hasEnable) {
    $where[] = "enable = 1";
} else {
    $where[] = "is_active = 1";
}

if ($search) {
    $searchFields = ["name LIKE :search1", "sku LIKE :search2"];
    if ($hasNameEn) $searchFields[] = "name_en LIKE :search3";
    $where[]    = "(" . implode(" OR ", $searchFields) . ")";
    $searchTerm = "%{$search}%";
    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    if ($hasNameEn) $params[':search3'] = $searchTerm;
}

if ($category) {
    $where[]             = "category_id = :category";
    $params[':category'] = (int)$category;
}

if ($stockFilter === 'in') {
    $where[] = "stock > 0";
} elseif ($stockFilter === 'out') {
    $where[] = "stock <= 0";
} elseif ($stockFilter === 'low') {
    $where[] = "stock > 0 AND stock <= 5";
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM {$productsTable} WHERE {$whereClause}");
$countStmt->execute($params);
$totalProducts = $countStmt->fetchColumn();
$totalPages    = ceil($totalProducts / $perPage);

// Get products for current page
$imageCol = $hasPhotoPath ? "COALESCE(photo_path, image_url) as display_image" : "image_url as display_image";
$stmt = $db->prepare("
    SELECT *, {$imageCol}
    FROM {$productsTable}
    WHERE {$whereClause}
    ORDER BY name
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories
$categories = [];
try {
    $catTable = 'product_categories';
    $db->query("SELECT 1 FROM {$catTable} LIMIT 1");
    $catStmt    = $db->query("SELECT id, name FROM {$catTable} WHERE is_active = 1 ORDER BY sort_order, name");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get last sync time if available
$lastSync = null;
try {
    $syncStmt = $db->query("SELECT MAX(last_synced_at) as last_sync FROM {$productsTable} WHERE last_synced_at IS NOT NULL");
    $lastSync = $syncStmt->fetchColumn();
} catch (Exception $e) {}

require_once __DIR__ . '/../includes/components/page-header.php';
require_once __DIR__ . '/../includes/components/toolbar.php';
require_once __DIR__ . '/../includes/components/empty-state.php';
require_once __DIR__ . '/../includes/components/pagination.php';
require_once __DIR__ . '/../includes/header.php';
?>

<?= getPageHeaderStyles() ?>
<?= getToolbarStyles() ?>
<?= getEmptyStateStyles() ?>
<?= getPaginationStyles() ?>

<style>
.products-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-6);
    margin-bottom: var(--space-6);
}
.product-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    transition: box-shadow var(--transition-base), transform var(--transition-base);
}
.product-card:hover {
    box-shadow: var(--shadow-glass-md);
    transform: translateY(-2px);
}
.product-card-img {
    position: relative;
    aspect-ratio: 1/1;
    background: var(--color-slate-100);
    overflow: hidden;
}
.product-card-img img {
    width: 100%; height: 100%; object-fit: cover;
    transition: transform var(--transition-slow);
}
.product-card:hover .product-card-img img { transform: scale(1.05); }
.product-card-placeholder {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    font-size: 64px; color: var(--color-slate-300);
}
.stock-badge {
    position: absolute; top: var(--space-2); right: var(--space-2);
    padding: 4px 10px; border-radius: var(--radius-full);
    font-size: var(--text-xs); font-weight: 600; color: #fff;
}
.stock-in  { background: var(--color-emerald-500); }
.stock-low { background: var(--color-amber-500); }
.stock-out { background: var(--color-rose-500); }
.edit-badge {
    position: absolute; top: var(--space-2); left: var(--space-2);
    padding: 4px 10px; border-radius: var(--radius-full);
    font-size: var(--text-xs); font-weight: 500;
    background: rgba(255,255,255,0.85); color: var(--color-dark-700);
    text-decoration: none; opacity: 0; transition: opacity var(--transition-fast);
}
.product-card:hover .edit-badge { opacity: 1; }
.product-card-body { padding: var(--space-4); }
.product-card-sku  { font-size: var(--text-xs); color: var(--color-dark-500); margin-bottom: 4px; }
.product-card-name {
    font-weight: 600; color: var(--color-dark-800); font-size: var(--text-sm);
    line-height: 1.4; display: -webkit-box;
    -webkit-line-clamp: 2; -webkit-box-orient: vertical;
    overflow: hidden; min-height: 40px; margin-bottom: var(--space-2);
}
.product-card-generic {
    font-size: var(--text-xs); color: var(--color-primary-600);
    margin-bottom: var(--space-3);
}
.product-card-meta {
    display: flex; align-items: center;
    justify-content: space-between; margin-bottom: var(--space-3);
}
.product-price { font-size: var(--text-2xl); font-weight: 700; color: var(--color-primary-600); }
.btn-detail {
    display: block; width: 100%; text-align: center;
    padding: 10px; border-radius: var(--radius-md);
    background: var(--color-primary-600); color: #fff;
    font-size: var(--text-sm); font-weight: 600; text-decoration: none;
    transition: background var(--transition-fast);
    box-sizing: border-box;
}
.btn-detail:hover { background: var(--color-primary-700); }
@media (max-width: 1280px) { .products-grid { grid-template-columns: repeat(3,1fr); } }
@media (max-width: 768px)  { .products-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px)  { .products-grid { grid-template-columns: 1fr; } }
.dark .product-card { background: var(--color-dark-800); border-color: var(--color-dark-700); }
.dark .product-card-img { background: var(--color-dark-700); }
.dark .product-card-name { color: var(--color-slate-100); }
.dark .product-card-sku  { color: var(--color-slate-400); }
.dark .edit-badge { background: rgba(30,41,59,0.85); color: var(--color-slate-200); }
</style>

<?php
$subtitleParts = ['ทั้งหมด ' . number_format($totalProducts) . ' รายการ'];
if ($lastSync) {
    $subtitleParts[] = 'Sync ล่าสุด: ' . date('d/m/Y H:i', strtotime($lastSync));
}

echo renderPageHeader(
    'สินค้าร้านยา',
    implode(' · ', $subtitleParts),
    null,
    [['label' => 'ร้านค้า', 'href' => null], ['label' => 'สินค้า', 'href' => null]]
);

$categoryOptions = array_map(fn($c) => ['value' => $c['id'], 'label' => $c['name']], $categories);
$selects = array_values(array_filter([
    !empty($categories) ? [
        'name'        => 'category',
        'value'       => $category,
        'placeholder' => 'ทุกหมวดหมู่',
        'options'     => $categoryOptions,
    ] : null,
    [
        'name'        => 'stock',
        'value'       => $stockFilter,
        'placeholder' => 'สต็อกทั้งหมด',
        'options'     => [
            ['value' => 'in',  'label' => 'มีสินค้า'],
            ['value' => 'low', 'label' => 'ใกล้หมด (≤5)'],
            ['value' => 'out', 'label' => 'หมดสต็อก'],
        ],
    ],
]));

echo renderToolbar([
    'search'    => ['name' => 'search', 'value' => $search, 'placeholder' => 'ค้นหาชื่อยา, SKU…'],
    'selects'   => $selects,
    'resetHref' => ($search || $category || $stockFilter) ? '?' : null,
    'chips'     => [
        ['href' => '/inventory?tab=products', 'icon' => 'fas fa-table', 'label' => 'มุมมองตาราง', 'tone' => 'neutral'],
        ['href' => '/admin/setup-cny.php',    'icon' => 'fas fa-sync',  'label' => 'Sync จาก CNY',  'tone' => 'success'],
    ],
    'meta'      => 'พบ ' . number_format($totalProducts) . ' รายการ',
]);
?>

<!-- Products Grid -->
<?php if (empty($products)): ?>
<?= renderEmptyState(
    'fas fa-box-open',
    'ไม่พบสินค้า',
    $totalProducts == 0 ? 'Sync สินค้าจาก CNY API หรือเพิ่มสินค้าใหม่' : 'ลองปรับตัวกรองหรือค้นหาใหม่'
) ?>
<?php else: ?>
<div class="products-grid">
    <?php foreach ($products as $product):
        // Get price - from product_price JSON or price column
        $price = 0;
        $unit  = '';
        if ($hasProductPrice && !empty($product['product_price'])) {
            $priceData = is_string($product['product_price'])
                ? json_decode($product['product_price'], true)
                : $product['product_price'];
            if (is_array($priceData) && !empty($priceData[0])) {
                $price = (float)($priceData[0]['price'] ?? 0);
                $unit  = $priceData[0]['unit'] ?? '';
            }
        }
        if ($price == 0) {
            $price = (float)($product['price'] ?? 0);
        }

        $stock    = (int)($product['stock'] ?? 0);
        $inStock  = $stock > 0;
        $imageUrl = $product['display_image'] ?? '';

        if (!$inStock) {
            $badgeClass = 'stock-out';
            $badgeLabel = '<i class="fas fa-times" style="margin-right:4px;"></i>หมด';
            $stockColor = 'var(--color-rose-600)';
        } elseif ($stock <= 5) {
            $badgeClass = 'stock-low';
            $badgeLabel = '<i class="fas fa-exclamation" style="margin-right:4px;"></i>เหลือ ' . $stock;
            $stockColor = 'var(--color-amber-600)';
        } else {
            $badgeClass = 'stock-in';
            $badgeLabel = '<i class="fas fa-check" style="margin-right:4px;"></i>มีสินค้า';
            $stockColor = 'var(--color-emerald-600)';
        }
    ?>
    <div class="product-card">
        <div class="product-card-img">
            <?php if (!empty($imageUrl)): ?>
            <img src="<?= htmlspecialchars($imageUrl) ?>"
                 alt="<?= htmlspecialchars($product['name']) ?>"
                 loading="lazy"
                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 200%22%3E%3Crect fill=%22%23f3f4f6%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2220%22%3ENo Image%3C/text%3E%3C/svg%3E'">
            <?php else: ?>
            <div class="product-card-placeholder"><i class="fas fa-pills"></i></div>
            <?php endif; ?>
            <span class="stock-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
            <a href="/inventory?tab=products&search=<?= urlencode($product['sku'] ?? $product['name']) ?>"
               class="edit-badge">
                <i class="fas fa-edit" style="margin-right:4px;"></i>แก้ไข
            </a>
        </div>

        <div class="product-card-body">
            <?php if (!empty($product['sku'])): ?>
            <div class="product-card-sku">SKU: <?= htmlspecialchars($product['sku']) ?></div>
            <?php endif; ?>

            <div class="product-card-name"><?= htmlspecialchars($product['name']) ?></div>

            <?php if ($hasGenericName && !empty($product['generic_name'])): ?>
            <div class="product-card-generic"><?= htmlspecialchars($product['generic_name']) ?></div>
            <?php endif; ?>

            <div class="product-card-meta">
                <div>
                    <div class="product-price">฿<?= number_format($price, 2) ?></div>
                    <?php if ($unit): ?>
                    <div style="font-size:var(--text-xs);color:var(--color-dark-500);"><?= htmlspecialchars($unit) ?></div>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:var(--text-xs);color:var(--color-dark-500);">คงเหลือ</div>
                    <div style="font-weight:700;font-size:var(--text-sm);color:<?= $stockColor ?>;"><?= number_format($stock) ?></div>
                </div>
            </div>

            <a href="product-detail.php?id=<?= $product['id'] ?>" class="btn-detail">
                <i class="fas fa-eye" style="margin-right:var(--space-2);"></i>ดูรายละเอียด
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1):
    $baseUrl = '?' . http_build_query(array_diff_key($_GET, ['page' => ''])) . '&';
    echo renderPagination($page, $totalPages, $perPage, $baseUrl, ['total' => $totalProducts]);
endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
