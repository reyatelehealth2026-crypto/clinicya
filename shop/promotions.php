<?php
/**
 * Promotions Management - จัดการสินค้าเด่น/Best Seller
 * - สินค้าเด่น (Featured): แสดงในหน้าแรก
 * - Best Seller: แสดงเป็นสินค้าขายดีในแต่ละหมวดหมู่
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['line_account_id'] ?? $_SESSION['current_bot_id'] ?? null;
$pageTitle = 'จัดการสินค้าเด่น / Best Seller';

// Check if columns exist
$hasIsFeatured = $hasIsBestseller = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
    $hasIsFeatured = in_array('is_featured', $cols);
    $hasIsBestseller = in_array('is_bestseller', $cols);
    
    // Add columns if not exist
    if (!$hasIsFeatured) {
        $db->exec("ALTER TABLE business_items ADD COLUMN is_featured TINYINT(1) DEFAULT 0");
        $hasIsFeatured = true;
    }
    if (!$hasIsBestseller) {
        $db->exec("ALTER TABLE business_items ADD COLUMN is_bestseller TINYINT(1) DEFAULT 0");
        $hasIsBestseller = true;
    }
} catch (Exception $e) {
    // Columns might already exist or table doesn't exist
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'];
    $productId = (int)($_POST['product_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'toggle_featured':
                $db->prepare("UPDATE business_items SET is_featured = NOT COALESCE(is_featured, 0) WHERE id = ?")->execute([$productId]);
                $stmt = $db->prepare("SELECT COALESCE(is_featured, 0) as is_featured FROM business_items WHERE id = ?");
                $stmt->execute([$productId]);
                echo json_encode(['success' => true, 'is_featured' => (int)$stmt->fetchColumn()]);
                exit;
                
            case 'toggle_bestseller':
                $db->prepare("UPDATE business_items SET is_bestseller = NOT COALESCE(is_bestseller, 0) WHERE id = ?")->execute([$productId]);
                $stmt = $db->prepare("SELECT COALESCE(is_bestseller, 0) as is_bestseller FROM business_items WHERE id = ?");
                $stmt->execute([$productId]);
                echo json_encode(['success' => true, 'is_bestseller' => (int)$stmt->fetchColumn()]);
                exit;
                
            case 'bulk_featured':
            case 'bulk_bestseller':
                $productIds = $_POST['product_ids'] ?? [];
                $value = (int)($_POST['value'] ?? 0);
                $column = $action === 'bulk_featured' ? 'is_featured' : 'is_bestseller';
                
                if (!empty($productIds)) {
                    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                    $db->prepare("UPDATE business_items SET $column = ? WHERE id IN ($placeholders)")->execute(array_merge([$value], $productIds));
                }
                echo json_encode(['success' => true, 'updated' => count($productIds)]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get categories
$categories = [];
$catTable = 'item_categories';
try {
    try { $db->query("SELECT 1 FROM item_categories LIMIT 1"); } 
    catch (Exception $e) { 
        try { $db->query("SELECT 1 FROM business_categories LIMIT 1"); $catTable = 'business_categories'; }
        catch (Exception $e2) { $catTable = 'product_categories'; }
    }
    $stmt = $db->query("SELECT * FROM $catTable ORDER BY id");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get filter params
$filterCategory = $_GET['category'] ?? '';
$filterType = $_GET['type'] ?? ''; // featured, bestseller, normal
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["is_active = 1"];
$params = [];

if ($lineAccountId) {
    $where[] = "(line_account_id = ? OR line_account_id IS NULL)";
    $params[] = $lineAccountId;
}

if ($filterCategory) {
    $where[] = "category_id = ?";
    $params[] = $filterCategory;
}

if ($filterType === 'featured') {
    $where[] = "COALESCE(is_featured, 0) = 1";
} elseif ($filterType === 'bestseller') {
    $where[] = "COALESCE(is_bestseller, 0) = 1";
} elseif ($filterType === 'normal') {
    $where[] = "COALESCE(is_featured, 0) = 0 AND COALESCE(is_bestseller, 0) = 0";
}

if ($search) {
    $where[] = "(name LIKE ? OR sku LIKE ? OR barcode LIKE ?)";
    $searchTerm = "%{$search}%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

$whereClause = implode(' AND ', $where);

// Count total
$stmt = $db->prepare("SELECT COUNT(*) FROM business_items WHERE $whereClause");
$stmt->execute($params);
$totalProducts = (int)$stmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);

// Get products
$sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id, 
               COALESCE(is_featured, 0) as is_featured,
               COALESCE(is_bestseller, 0) as is_bestseller
        FROM business_items WHERE $whereClause
        ORDER BY is_featured DESC, is_bestseller DESC, id DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$featuredCount = $db->query("SELECT COUNT(*) FROM business_items WHERE is_active = 1 AND COALESCE(is_featured, 0) = 1")->fetchColumn();
$bestsellerCount = $db->query("SELECT COUNT(*) FROM business_items WHERE is_active = 1 AND COALESCE(is_bestseller, 0) = 1")->fetchColumn();

// Best Seller per category
$bestsellerByCategory = [];
$stmt = $db->query("SELECT category_id, COUNT(*) as cnt FROM business_items WHERE is_active = 1 AND COALESCE(is_bestseller, 0) = 1 GROUP BY category_id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $bestsellerByCategory[$row['category_id']] = $row['cnt'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Stats Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <a href="?type=featured" class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition <?= $filterType === 'featured' ? 'ring-2 ring-yellow-400' : '' ?>">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-star text-yellow-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">สินค้าเด่น</p>
                <p class="text-2xl font-bold text-yellow-600"><?= number_format($featuredCount) ?></p>
            </div>
        </div>
    </a>
    <a href="?type=bestseller" class="bg-white rounded-xl shadow p-4 hover:shadow-lg transition <?= $filterType === 'bestseller' ? 'ring-2 ring-red-400' : '' ?>">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-fire text-red-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Best Seller</p>
                <p class="text-2xl font-bold text-red-600"><?= number_format($bestsellerCount) ?></p>
            </div>
        </div>
    </a>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-tags text-green-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">หมวดหมู่</p>
                <p class="text-2xl font-bold text-gray-800"><?= count($categories) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-box text-blue-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">สินค้าทั้งหมด</p>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($totalProducts) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Best Seller by Category Summary -->
<?php if (!empty($bestsellerByCategory)): ?>
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-fire text-red-500 mr-2"></i>Best Seller แยกตามหมวดหมู่</h2>
    </div>
    <div class="p-4">
        <div class="flex flex-wrap gap-2">
            <?php foreach ($categories as $cat): 
                $cnt = $bestsellerByCategory[$cat['id']] ?? 0;
                $catName = $cat['name'];
                if (strpos($catName, '-') !== false) {
                    $parts = explode('-', $catName, 2);
                    $code = $parts[0];
                    $shortName = mb_substr($parts[1] ?? $catName, 0, 15);
                } else {
                    $code = mb_substr($catName, 0, 3);
                    $shortName = mb_substr($catName, 0, 15);
                }
            ?>
            <a href="?category=<?= $cat['id'] ?>&type=bestseller" 
               class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm <?= $cnt > 0 ? 'bg-red-50 text-red-700 hover:bg-red-100' : 'bg-gray-50 text-gray-500 hover:bg-gray-100' ?> transition">
                <span class="font-bold"><?= $code ?></span>
                <span><?= htmlspecialchars($shortName) ?></span>
                <?php if ($cnt > 0): ?>
                <span class="px-2 py-0.5 bg-red-500 text-white text-xs rounded-full"><?= $cnt ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-filter mr-2 text-gray-400"></i>ตัวกรอง</h2>
    </div>
    <div class="p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ค้นหา</label>
                <input type="text" name="search" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="ชื่อ, SKU..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">หมวดหมู่</label>
                <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ประเภท</label>
                <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    <option value="">ทั้งหมด</option>
                    <option value="featured" <?= $filterType === 'featured' ? 'selected' : '' ?>>⭐ สินค้าเด่น</option>
                    <option value="bestseller" <?= $filterType === 'bestseller' ? 'selected' : '' ?>>🔥 Best Seller</option>
                    <option value="normal" <?= $filterType === 'normal' ? 'selected' : '' ?>>สินค้าปกติ</option>
                </select>
            </div>
            <div class="flex items-end gap-2 md:col-span-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"><i class="fas fa-search mr-1"></i>ค้นหา</button>
                <a href="promotions.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300"><i class="fas fa-redo mr-1"></i>รีเซ็ต</a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Actions -->
<div class="bg-white rounded-xl shadow mb-4">
    <div class="p-3 flex flex-wrap items-center gap-3">
        <label class="flex items-center cursor-pointer">
            <input type="checkbox" id="selectAll" class="w-4 h-4 text-blue-600 rounded">
            <span class="ml-2 text-sm text-gray-600">เลือกทั้งหมด</span>
        </label>
        <span class="text-gray-300">|</span>
        <span id="selectedCount" class="text-sm text-gray-500">เลือก 0 รายการ</span>
        <span class="text-gray-300">|</span>
        <button type="button" class="px-3 py-1.5 bg-yellow-500 text-white text-sm rounded-lg hover:bg-yellow-600 disabled:bg-gray-300 disabled:cursor-not-allowed" onclick="bulkAction('featured', 1)" disabled id="btnSetFeatured">
            <i class="fas fa-star mr-1"></i>ตั้งเป็นเด่น
        </button>
        <button type="button" class="px-3 py-1.5 bg-red-500 text-white text-sm rounded-lg hover:bg-red-600 disabled:bg-gray-300 disabled:cursor-not-allowed" onclick="bulkAction('bestseller', 1)" disabled id="btnSetBestseller">
            <i class="fas fa-fire mr-1"></i>ตั้งเป็น Best Seller
        </button>
        <button type="button" class="px-3 py-1.5 bg-gray-500 text-white text-sm rounded-lg hover:bg-gray-600 disabled:bg-gray-300 disabled:cursor-not-allowed" onclick="bulkAction('featured', 0); bulkAction('bestseller', 0);" disabled id="btnClear">
            <i class="fas fa-times mr-1"></i>ยกเลิกทั้งหมด
        </button>
    </div>
</div>

<!-- Products Grid -->
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
<?php if (empty($products)): ?>
    <div class="col-span-full bg-white rounded-xl shadow p-8 text-center">
        <i class="fas fa-box-open text-5xl text-gray-300 mb-4"></i>
        <p class="text-gray-500">ไม่พบสินค้า</p>
    </div>
<?php else: ?>
<?php foreach ($products as $product): 
    $isFeatured = (int)$product['is_featured'];
    $isBestseller = (int)$product['is_bestseller'];
    $ringClass = $isFeatured && $isBestseller ? 'ring-2 ring-purple-400' : ($isFeatured ? 'ring-2 ring-yellow-400' : ($isBestseller ? 'ring-2 ring-red-400' : ''));
?>
    <div class="bg-white rounded-xl shadow overflow-hidden product-card <?= $ringClass ?>" data-id="<?= $product['id'] ?>">
        <div class="relative">
            <div class="absolute top-2 left-2 z-10">
                <input type="checkbox" class="product-checkbox w-5 h-5 rounded bg-white shadow" value="<?= $product['id'] ?>">
            </div>
            <div class="absolute top-2 right-2 z-10 flex gap-1">
                <?php if ($isFeatured): ?><span class="px-1.5 py-0.5 bg-yellow-500 text-white text-[10px] font-bold rounded">⭐</span><?php endif; ?>
                <?php if ($isBestseller): ?><span class="px-1.5 py-0.5 bg-red-500 text-white text-[10px] font-bold rounded">🔥</span><?php endif; ?>
            </div>
            <div class="aspect-square bg-gray-100 flex items-center justify-center">
                <?php if ($product['image_url']): ?>
                <img src="<?= htmlspecialchars($product['image_url']) ?>" class="w-full h-full object-cover" loading="lazy">
                <?php else: ?>
                <i class="fas fa-image text-3xl text-gray-300"></i>
                <?php endif; ?>
            </div>
        </div>
        <div class="p-2">
            <h3 class="font-medium text-xs text-gray-800 line-clamp-2 h-8"><?= htmlspecialchars($product['name']) ?></h3>
            <p class="text-[10px] text-gray-400"><?= htmlspecialchars($product['sku'] ?? '-') ?></p>
            <div class="mt-1">
                <?php if ($product['sale_price']): ?>
                <span class="text-red-600 font-bold text-sm">฿<?= number_format($product['sale_price']) ?></span>
                <?php else: ?>
                <span class="font-bold text-sm">฿<?= number_format($product['price']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="px-2 pb-2 flex gap-1">
            <button onclick="toggle('featured', <?= $product['id'] ?>, this)" class="flex-1 py-1.5 rounded text-xs font-medium <?= $isFeatured ? 'bg-yellow-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-yellow-100' ?>">
                <i class="<?= $isFeatured ? 'fas' : 'far' ?> fa-star"></i>
            </button>
            <button onclick="toggle('bestseller', <?= $product['id'] ?>, this)" class="flex-1 py-1.5 rounded text-xs font-medium <?= $isBestseller ? 'bg-red-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-red-100' ?>">
                <i class="fas fa-fire"></i>
            </button>
        </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
    <p class="text-sm text-gray-500">แสดง <?= number_format(($page - 1) * $perPage + 1) ?> - <?= number_format(min($page * $perPage, $totalProducts)) ?> จาก <?= number_format($totalProducts) ?> รายการ</p>
    <div class="flex gap-1">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-3 py-2 bg-white border rounded-lg hover:bg-gray-50"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="px-3 py-2 border rounded-lg <?= $i == $page ? 'bg-blue-600 text-white' : 'bg-white hover:bg-gray-50' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-3 py-2 bg-white border rounded-lg hover:bg-gray-50"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('selectAll').addEventListener('change', function() {
    document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = this.checked);
    updateCount();
});
document.querySelectorAll('.product-checkbox').forEach(cb => cb.addEventListener('change', updateCount));

function updateCount() {
    const count = document.querySelectorAll('.product-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = `เลือก ${count} รายการ`;
    ['btnSetFeatured', 'btnSetBestseller', 'btnClear'].forEach(id => document.getElementById(id).disabled = count === 0);
}

async function toggle(type, id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_' + type);
    formData.append('product_id', id);
    const res = await fetch('promotions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
        location.reload();
    }
}

async function bulkAction(type, value) {
    const ids = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => cb.value);
    if (ids.length === 0) return;
    if (!confirm(`ต้องการ${value ? 'ตั้ง' : 'ยกเลิก'} ${type === 'featured' ? 'สินค้าเด่น' : 'Best Seller'} ${ids.length} รายการ?`)) return;
    
    const formData = new FormData();
    formData.append('action', 'bulk_' + type);
    formData.append('value', value);
    ids.forEach(id => formData.append('product_ids[]', id));
    const res = await fetch('promotions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
        location.reload();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
