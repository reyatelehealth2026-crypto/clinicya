<?php
/**
 * Products Grid View - แสดงสินค้าแบบ Grid
 * สไตล์คล้าย cnypharmacy.com
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'สินค้าทั้งหมด';
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
$perPage = in_array($perPage, [25, 50, 100]) ? $perPage : 25;

// Filters
$categoryId = isset($_GET['category']) ? intval($_GET['category']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'latest';
$stockFilter = isset($_GET['stock']) ? $_GET['stock'] : 'all';

// Build query
$where = ["bi.line_account_id = ?"];
$params = [$currentBotId];

if ($categoryId > 0) {
    $where[] = "bi.category_id = ?";
    $params[] = $categoryId;
}

if ($search) {
    $where[] = "(bi.name LIKE ? OR bi.sku LIKE ? OR bi.description LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($stockFilter === 'instock') {
    $where[] = "bi.stock > 0";
} elseif ($stockFilter === 'outofstock') {
    $where[] = "bi.stock = 0";
}

$whereClause = implode(' AND ', $where);

// Sort
$orderBy = match($sortBy) {
    'name_asc' => 'bi.name ASC',
    'name_desc' => 'bi.name DESC',
    'price_asc' => 'bi.price ASC',
    'price_desc' => 'bi.price DESC',
    'sku' => 'bi.sku ASC',
    default => 'bi.id DESC'
};

// Count total
$countSql = "SELECT COUNT(*) FROM business_items bi WHERE $whereClause";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalProducts = $stmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);
$offset = ($page - 1) * $perPage;

// Get products
$sql = "SELECT bi.*, pc.name as category_name 
        FROM business_items bi 
        LEFT JOIN product_categories pc ON bi.category_id = pc.id 
        WHERE $whereClause 
        ORDER BY $orderBy 
        LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter - แสดงทั้งหมด ไม่ filter ตาม line_account_id
$catSql = "SELECT id, name, (SELECT COUNT(*) FROM business_items WHERE category_id = pc.id) as product_count 
           FROM product_categories pc ORDER BY name";
$stmt = $db->query($catSql);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
.product-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; transition: all 0.3s; }
.product-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,0.12); }
.product-img { width: 100%; aspect-ratio: 1; object-fit: cover; background: #f5f5f5; }
.product-info { padding: 12px; }
.product-sku { font-size: 11px; color: #888; margin-bottom: 4px; }
.product-name { font-size: 13px; font-weight: 500; color: #333; line-height: 1.4; height: 36px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.product-price { font-size: 16px; font-weight: 700; color: #e53935; margin-top: 8px; }
.product-price-old { font-size: 12px; color: #999; text-decoration: line-through; margin-left: 6px; }
.stock-badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; }
.stock-in { background: #e8f5e9; color: #2e7d32; }
.stock-out { background: #ffebee; color: #c62828; }
.filter-section { background: #fff; border-radius: 12px; padding: 16px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.filter-label { font-size: 12px; font-weight: 600; color: #666; margin-bottom: 6px; }
.category-list { max-height: 300px; overflow-y: auto; }
.category-item { padding: 8px 12px; border-radius: 8px; cursor: pointer; transition: all 0.2s; display: flex; justify-content: space-between; align-items: center; }
.category-item:hover { background: #f5f5f5; }
.category-item.active { background: #e3f2fd; color: #1976d2; }
.category-count { font-size: 11px; background: #eee; padding: 2px 8px; border-radius: 10px; }
.view-toggle button { padding: 8px 12px; border: 1px solid #ddd; background: #fff; cursor: pointer; }
.view-toggle button.active { background: #1976d2; color: #fff; border-color: #1976d2; }
.pagination-wrap { display: flex; justify-content: center; gap: 4px; margin-top: 24px; }
.pagination-wrap a, .pagination-wrap span { padding: 8px 14px; border-radius: 8px; text-decoration: none; }
.pagination-wrap a { background: #fff; color: #333; border: 1px solid #ddd; }
.pagination-wrap a:hover { background: #f5f5f5; }
.pagination-wrap .current { background: #1976d2; color: #fff; }
.btn-buy { width: 100%; padding: 8px; background: linear-gradient(135deg, #e53935, #d32f2f); color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-buy:hover { background: linear-gradient(135deg, #d32f2f, #c62828); }
.search-box { position: relative; }
.search-box input { width: 100%; padding: 12px 16px 12px 44px; border: 2px solid #e0e0e0; border-radius: 12px; font-size: 14px; }
.search-box input:focus { border-color: #1976d2; outline: none; }
.search-box i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #999; }
.tag-badge { display: inline-block; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-right: 4px; }
.tag-promo { background: #fff3e0; color: #e65100; }
.tag-new { background: #e8f5e9; color: #2e7d32; }
.tag-hot { background: #ffebee; color: #c62828; }
</style>

<div class="container-fluid px-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-1">🛍️ สินค้าทั้งหมด</h1>
            <p class="text-muted mb-0">พบ <?= number_format($totalProducts) ?> รายการ</p>
        </div>
        <div class="d-flex gap-2">
            <a href="import-products.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-upload me-1"></i>นำเข้า CSV
            </a>
            <a href="products.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i>เพิ่มสินค้า
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Sidebar Filters -->
        <div class="col-lg-3 col-md-4">
            <div class="filter-section">
                <!-- Search -->
                <div class="mb-4">
                    <div class="filter-label">ค้นหาสินค้า</div>
                    <form method="GET" class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ชื่อ, รหัส, รายละเอียด...">
                        <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= $categoryId ?>"><?php endif; ?>
                    </form>
                </div>

                <!-- Categories -->
                <div class="mb-4">
                    <div class="filter-label">หมวดหมู่สินค้า</div>
                    <div class="category-list">
                        <a href="?<?= http_build_query(array_merge($_GET, ['category' => 0, 'page' => 1])) ?>" 
                           class="category-item <?= $categoryId == 0 ? 'active' : '' ?>">
                            <span>ทั้งหมด</span>
                            <span class="category-count"><?= number_format($totalProducts) ?></span>
                        </a>
                        <?php foreach ($categories as $cat): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['category' => $cat['id'], 'page' => 1])) ?>" 
                           class="category-item <?= $categoryId == $cat['id'] ? 'active' : '' ?>">
                            <span><?= htmlspecialchars($cat['name']) ?></span>
                            <span class="category-count"><?= $cat['product_count'] ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Stock Filter -->
                <div class="mb-4">
                    <div class="filter-label">สถานะสต็อก</div>
                    <select class="form-select form-select-sm" onchange="location.href='?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>&stock='+this.value">
                        <option value="all" <?= $stockFilter == 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                        <option value="instock" <?= $stockFilter == 'instock' ? 'selected' : '' ?>>มีสินค้า</option>
                        <option value="outofstock" <?= $stockFilter == 'outofstock' ? 'selected' : '' ?>>สินค้าหมด</option>
                    </select>
                </div>

                <!-- Sort -->
                <div>
                    <div class="filter-label">เรียงลำดับ</div>
                    <select class="form-select form-select-sm" onchange="location.href='?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>&sort='+this.value">
                        <option value="latest" <?= $sortBy == 'latest' ? 'selected' : '' ?>>ล่าสุด</option>
                        <option value="name_asc" <?= $sortBy == 'name_asc' ? 'selected' : '' ?>>ชื่อ A-Z</option>
                        <option value="name_desc" <?= $sortBy == 'name_desc' ? 'selected' : '' ?>>ชื่อ Z-A</option>
                        <option value="price_asc" <?= $sortBy == 'price_asc' ? 'selected' : '' ?>>ราคาต่ำ-สูง</option>
                        <option value="price_desc" <?= $sortBy == 'price_desc' ? 'selected' : '' ?>>ราคาสูง-ต่ำ</option>
                        <option value="sku" <?= $sortBy == 'sku' ? 'selected' : '' ?>>รหัสสินค้า</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="col-lg-9 col-md-8">
            <!-- Top Bar -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-3">
                    <span class="text-muted">แสดง</span>
                    <select class="form-select form-select-sm" style="width: auto;" onchange="location.href='?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>&per_page='+this.value">
                        <option value="25" <?= $perPage == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                    <span class="text-muted">รายการ</span>
                </div>
                <div class="view-toggle btn-group">
                    <button class="active" title="Grid View"><i class="fas fa-th-large"></i></button>
                    <button onclick="location.href='products.php'" title="List View"><i class="fas fa-list"></i></button>
                </div>
            </div>

            <?php if (empty($products)): ?>
            <div class="text-center py-5">
                <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                <h5 class="text-muted">ไม่พบสินค้า</h5>
                <p class="text-muted">ลองเปลี่ยนตัวกรองหรือค้นหาใหม่</p>
            </div>
            <?php else: ?>
            <!-- Product Grid -->
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                <div class="product-card">
                    <a href="product-detail.php?id=<?= $product['id'] ?>">
                        <?php if ($product['image_url']): ?>
                        <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="product-img" loading="lazy">
                        <?php else: ?>
                        <div class="product-img d-flex align-items-center justify-content-center">
                            <i class="fas fa-image fa-3x text-muted"></i>
                        </div>
                        <?php endif; ?>
                    </a>
                    
                    <div class="product-info">
                        <!-- Stock Badge -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <?php if ($product['stock'] > 0): ?>
                            <span class="stock-badge stock-in"><i class="fas fa-check-circle me-1"></i>มีสินค้า</span>
                            <?php else: ?>
                            <span class="stock-badge stock-out"><i class="fas fa-times-circle me-1"></i>หมด</span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- SKU -->
                        <div class="product-sku">
                            รหัส: <strong><?= htmlspecialchars($product['sku'] ?: '-') ?></strong>
                        </div>
                        
                        <!-- Name -->
                        <a href="product-detail.php?id=<?= $product['id'] ?>" class="text-decoration-none">
                            <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                        </a>
                        
                        <!-- Category -->
                        <?php if ($product['category_name']): ?>
                        <div class="mt-1">
                            <span class="tag-badge tag-promo"><?= htmlspecialchars($product['category_name']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Price -->
                        <div class="product-price">
                            ฿<?= number_format($product['sale_price'] ?: $product['price'], 2) ?>
                            <?php if ($product['sale_price'] && $product['sale_price'] < $product['price']): ?>
                            <span class="product-price-old">฿<?= number_format($product['price'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Buy Button -->
                        <button class="btn-buy mt-3" onclick="addToCart(<?= $product['id'] ?>)">
                            <i class="fas fa-shopping-cart me-1"></i>ซื้อเลย
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-wrap">
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                <?php if ($startPage > 2): ?><span>...</span><?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <?php if ($i == $page): ?>
                <span class="current"><?= $i ?></span>
                <?php else: ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"><?= $totalPages ?></a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            
            <!-- Go to page -->
            <div class="text-center mt-3">
                <span class="text-muted me-2">ไปหน้า:</span>
                <input type="number" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" 
                       class="form-control form-control-sm d-inline-block" style="width: 80px;"
                       onchange="if(this.value >= 1 && this.value <= <?= $totalPages ?>) location.href='?<?= http_build_query(array_merge($_GET, ['page' => ''])) ?>page='+this.value">
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function addToCart(productId) {
    // TODO: Implement add to cart
    alert('เพิ่มสินค้า ID: ' + productId + ' ลงตะกร้า');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
