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
    $hasIsFeatured   = in_array('is_featured', $cols);
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

    $action    = $_POST['action'];
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
                $value      = (int)($_POST['value'] ?? 0);
                $column     = $action === 'bulk_featured' ? 'is_featured' : 'is_bestseller';

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
$catTable   = 'item_categories';
try {
    try { $db->query("SELECT 1 FROM item_categories LIMIT 1"); }
    catch (Exception $e) {
        try { $db->query("SELECT 1 FROM business_categories LIMIT 1"); $catTable = 'business_categories'; }
        catch (Exception $e2) { $catTable = 'product_categories'; }
    }
    $stmt       = $db->query("SELECT * FROM $catTable ORDER BY id");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get filter params
$filterCategory = $_GET['category'] ?? '';
$filterType     = $_GET['type'] ?? ''; // featured, bestseller, normal
$search         = trim($_GET['search'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 50;
$offset         = ($page - 1) * $perPage;

// Build query
$where  = ["is_active = 1"];
$params = [];

if ($lineAccountId) {
    $where[]  = "(line_account_id = ? OR line_account_id IS NULL)";
    $params[] = $lineAccountId;
}

if ($filterCategory) {
    $where[]  = "category_id = ?";
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
    $where[]    = "(name LIKE ? OR sku LIKE ? OR barcode LIKE ?)";
    $searchTerm = "%{$search}%";
    $params     = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

$whereClause = implode(' AND ', $where);

// Count total
$stmt          = $db->prepare("SELECT COUNT(*) FROM business_items WHERE $whereClause");
$stmt->execute($params);
$totalProducts = (int)$stmt->fetchColumn();
$totalPages    = ceil($totalProducts / $perPage);

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
$featuredCount   = $db->query("SELECT COUNT(*) FROM business_items WHERE is_active = 1 AND COALESCE(is_featured, 0) = 1")->fetchColumn();
$bestsellerCount = $db->query("SELECT COUNT(*) FROM business_items WHERE is_active = 1 AND COALESCE(is_bestseller, 0) = 1")->fetchColumn();

// Best Seller per category
$bestsellerByCategory = [];
$stmt = $db->query("SELECT category_id, COUNT(*) as cnt FROM business_items WHERE is_active = 1 AND COALESCE(is_bestseller, 0) = 1 GROUP BY category_id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $bestsellerByCategory[$row['category_id']] = $row['cnt'];
}

require_once __DIR__ . '/../includes/components/page-header.php';
require_once __DIR__ . '/../includes/components/toolbar.php';
require_once __DIR__ . '/../includes/components/pagination.php';
require_once __DIR__ . '/../includes/components/empty-state.php';
require_once __DIR__ . '/../includes/header.php';
?>

<?= getPageHeaderStyles() ?>
<?= getToolbarStyles() ?>
<?= getPaginationStyles() ?>
<?= getEmptyStateStyles() ?>

<style>
.promo-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-4);
    margin-bottom: var(--space-6);
}
.promo-stat-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    padding: var(--space-4);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    display: flex; align-items: center; gap: var(--space-4);
    text-decoration: none;
    transition: box-shadow var(--transition-fast);
}
.promo-stat-card:hover { box-shadow: var(--shadow-glass); }
.promo-stat-card.ring-active { outline: 2px solid var(--color-primary-400); }
.promo-stat-icon {
    width: 48px; height: 48px;
    border-radius: var(--radius-md);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.promo-stat-label { font-size: var(--text-sm); color: var(--color-dark-500); margin: 0; }
.promo-stat-value {
    font-size: var(--text-2xl); font-weight: 700;
    color: var(--color-dark-800); margin: 0;
    font-family: var(--font-mono);
}
.bs-section {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    margin-bottom: var(--space-6);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    overflow: hidden;
}
.bs-section-header {
    padding: var(--space-4);
    border-bottom: 1px solid var(--color-slate-200);
    font-weight: 600; font-size: var(--text-sm);
    color: var(--color-dark-800);
}
.bs-section-body { padding: var(--space-4); display: flex; flex-wrap: wrap; gap: var(--space-2); }
.bulk-bar {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    padding: var(--space-3) var(--space-4);
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    margin-bottom: var(--space-4);
    display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-3);
}
.promo-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: var(--space-4);
}
.promo-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(15,23,42,0.04);
    transition: box-shadow var(--transition-fast);
}
.promo-card:hover { box-shadow: var(--shadow-glass); }
.promo-card.ring-featured   { outline: 2px solid var(--color-amber-400); }
.promo-card.ring-bestseller { outline: 2px solid var(--color-rose-400); }
.promo-card.ring-both       { outline: 2px solid var(--color-violet-600); }
.promo-card-img { position: relative; aspect-ratio: 1/1; background: var(--color-slate-100); }
.promo-card-img img { width:100%; height:100%; object-fit:cover; }
.promo-card-placeholder { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:32px; color:var(--color-slate-300); }
.promo-card-badges { position:absolute; top:6px; right:6px; display:flex; gap:3px; z-index:1; }
.promo-badge { padding:2px 6px; border-radius:4px; font-size:10px; font-weight:700; color:#fff; }
.promo-badge-f  { background: var(--color-amber-500); }
.promo-badge-bs { background: var(--color-rose-500); }
.promo-card-cb { position:absolute; top:6px; left:6px; z-index:1; }
.promo-card-body { padding: var(--space-2); }
.promo-card-name {
    font-weight:500; font-size:var(--text-xs); color:var(--color-dark-800);
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:32px;
}
.promo-card-sku  { font-size:10px; color:var(--color-dark-500); margin-top:2px; }
.promo-card-price { font-weight:700; font-size:var(--text-sm); margin-top:4px; }
.promo-card-actions { padding: 0 var(--space-2) var(--space-2); display:flex; gap:4px; }
.promo-btn {
    flex:1; padding:6px; border-radius:var(--radius-sm); font-size:var(--text-xs); font-weight:500;
    border:none; cursor:pointer; transition: all var(--transition-fast);
}
.promo-btn-f-off  { background:var(--color-slate-100); color:var(--color-dark-600); }
.promo-btn-f-off:hover  { background:var(--color-amber-100); }
.promo-btn-f-on   { background:var(--color-amber-500); color:#fff; }
.promo-btn-bs-off { background:var(--color-slate-100); color:var(--color-dark-600); }
.promo-btn-bs-off:hover { background:var(--color-rose-100); }
.promo-btn-bs-on  { background:var(--color-rose-500); color:#fff; }
@media (max-width: 1280px) { .promo-grid { grid-template-columns: repeat(5,1fr); } }
@media (max-width: 1024px) { .promo-grid { grid-template-columns: repeat(4,1fr); } }
@media (max-width: 768px)  { .promo-grid { grid-template-columns: repeat(3,1fr); } .promo-stat-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px)  { .promo-grid { grid-template-columns: repeat(2,1fr); } }
.dark .promo-stat-card,
.dark .bs-section,
.dark .bulk-bar,
.dark .promo-card { background: var(--color-dark-800); border-color: var(--color-dark-700); }
.dark .promo-stat-label { color: var(--color-slate-400); }
.dark .promo-stat-value, .dark .promo-card-name { color: var(--color-slate-100); }
.dark .bs-section-header { color: var(--color-slate-100); border-color: var(--color-dark-700); }
.dark .promo-card-img { background: var(--color-dark-700); }
.dark .promo-btn-f-off, .dark .promo-btn-bs-off { background: var(--color-dark-700); color: var(--color-slate-300); }
</style>

<?php
echo renderPageHeader(
    'จัดการสินค้าเด่น / Best Seller',
    'กำหนดสินค้าเด่นและ Best Seller สำหรับหน้าร้านค้า',
    null,
    [['label' => 'ร้านค้า', 'href' => null], ['label' => 'โปรโมชั่น', 'href' => null]]
);
?>

<!-- KPI Stats -->
<div class="promo-stat-grid">
    <a href="?type=featured" class="promo-stat-card <?= $filterType === 'featured' ? 'ring-active' : '' ?>">
        <div class="promo-stat-icon" style="background:var(--color-amber-50);color:var(--color-amber-600);">
            <i class="fas fa-star"></i>
        </div>
        <div>
            <p class="promo-stat-label">สินค้าเด่น</p>
            <p class="promo-stat-value" style="color:var(--color-amber-600);"><?= number_format($featuredCount) ?></p>
        </div>
    </a>
    <a href="?type=bestseller" class="promo-stat-card <?= $filterType === 'bestseller' ? 'ring-active' : '' ?>">
        <div class="promo-stat-icon" style="background:var(--color-rose-50);color:var(--color-rose-600);">
            <i class="fas fa-fire"></i>
        </div>
        <div>
            <p class="promo-stat-label">Best Seller</p>
            <p class="promo-stat-value" style="color:var(--color-rose-600);"><?= number_format($bestsellerCount) ?></p>
        </div>
    </a>
    <div class="promo-stat-card">
        <div class="promo-stat-icon" style="background:var(--color-emerald-50);color:var(--color-emerald-600);">
            <i class="fas fa-tags"></i>
        </div>
        <div>
            <p class="promo-stat-label">หมวดหมู่</p>
            <p class="promo-stat-value"><?= count($categories) ?></p>
        </div>
    </div>
    <div class="promo-stat-card">
        <div class="promo-stat-icon" style="background:var(--color-primary-50);color:var(--color-primary-600);">
            <i class="fas fa-box"></i>
        </div>
        <div>
            <p class="promo-stat-label">สินค้าทั้งหมด</p>
            <p class="promo-stat-value"><?= number_format($totalProducts) ?></p>
        </div>
    </div>
</div>

<!-- Best Seller by Category -->
<?php if (!empty($bestsellerByCategory)): ?>
<div class="bs-section">
    <div class="bs-section-header">
        <i class="fas fa-fire" style="color:var(--color-rose-500);margin-right:var(--space-2);"></i>Best Seller แยกตามหมวดหมู่
    </div>
    <div class="bs-section-body">
        <?php foreach ($categories as $cat):
            $cnt = $bestsellerByCategory[$cat['id']] ?? 0;
            $catName = $cat['name'];
            if (strpos($catName, '-') !== false) {
                $parts     = explode('-', $catName, 2);
                $code      = $parts[0];
                $shortName = mb_substr($parts[1] ?? $catName, 0, 15);
            } else {
                $code      = mb_substr($catName, 0, 3);
                $shortName = mb_substr($catName, 0, 15);
            }
            $isActive = ($filterCategory == $cat['id'] && $filterType === 'bestseller');
        ?>
        <a href="?category=<?= $cat['id'] ?>&type=bestseller"
           class="toolbar-chip <?= $cnt > 0 ? 'toolbar-chip-danger' : 'toolbar-chip-neutral' ?> <?= $isActive ? 'toolbar-chip-active' : '' ?>">
            <span style="font-weight:700;"><?= htmlspecialchars($code) ?></span>
            <span><?= htmlspecialchars($shortName) ?></span>
            <?php if ($cnt > 0): ?>
            <span style="background:var(--color-rose-500);color:#fff;border-radius:var(--radius-full);padding:1px 6px;font-size:10px;font-weight:700;"><?= $cnt ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Toolbar -->
<?php
echo renderToolbar([
    'search'  => ['name' => 'search', 'value' => $search, 'placeholder' => 'ค้นหาชื่อ, SKU, Barcode…'],
    'selects' => [
        [
            'name'        => 'category',
            'value'       => $filterCategory,
            'placeholder' => 'ทุกหมวดหมู่',
            'options'     => array_map(fn($c) => ['value' => $c['id'], 'label' => $c['name']], $categories),
        ],
        [
            'name'        => 'type',
            'value'       => $filterType,
            'placeholder' => 'ทุกประเภท',
            'options'     => [
                ['value' => 'featured',   'label' => '⭐ สินค้าเด่น'],
                ['value' => 'bestseller', 'label' => '🔥 Best Seller'],
                ['value' => 'normal',     'label' => 'สินค้าปกติ'],
            ],
        ],
    ],
    'resetHref' => 'promotions.php',
    'meta'      => 'พบ ' . number_format($totalProducts) . ' รายการ',
]);
?>

<!-- Bulk Actions Bar -->
<div class="bulk-bar">
    <label style="display:flex;align-items:center;gap:var(--space-2);font-size:var(--text-sm);cursor:pointer;">
        <input type="checkbox" id="selectAll" style="width:16px;height:16px;accent-color:var(--color-primary-600);">
        <span style="color:var(--color-dark-700);">เลือกทั้งหมด</span>
    </label>
    <span style="color:var(--color-slate-300);">|</span>
    <span id="selectedCount" style="font-size:var(--text-sm);color:var(--color-dark-500);">เลือก 0 รายการ</span>
    <span style="color:var(--color-slate-300);">|</span>
    <button type="button" class="toolbar-bulk-btn toolbar-bulk-btn-warning"
            onclick="bulkAction('featured', 1)" disabled id="btnSetFeatured">
        <i class="fas fa-star"></i><span>ตั้งเป็นเด่น</span>
    </button>
    <button type="button" class="toolbar-bulk-btn toolbar-bulk-btn-danger"
            onclick="bulkAction('bestseller', 1)" disabled id="btnSetBestseller">
        <i class="fas fa-fire"></i><span>ตั้งเป็น Best Seller</span>
    </button>
    <button type="button" class="toolbar-bulk-btn toolbar-bulk-btn-neutral"
            onclick="bulkAction('featured', 0); bulkAction('bestseller', 0);" disabled id="btnClear">
        <i class="fas fa-times"></i><span>ยกเลิกทั้งหมด</span>
    </button>
</div>

<!-- Products Grid -->
<?php if (empty($products)): ?>
<?= renderEmptyState('fas fa-box-open', 'ไม่พบสินค้า', 'ลองปรับตัวกรองหรือค้นหาใหม่') ?>
<?php else: ?>
<div class="promo-grid">
<?php foreach ($products as $product):
    $isFeatured   = (int)$product['is_featured'];
    $isBestseller = (int)$product['is_bestseller'];
    $ringClass    = $isFeatured && $isBestseller
        ? 'ring-both'
        : ($isFeatured ? 'ring-featured' : ($isBestseller ? 'ring-bestseller' : ''));
?>
<div class="promo-card <?= $ringClass ?>" data-id="<?= $product['id'] ?>">
    <div class="promo-card-img">
        <div class="promo-card-cb">
            <input type="checkbox" class="product-checkbox" value="<?= $product['id'] ?>"
                   style="width:18px;height:18px;accent-color:var(--color-primary-600);background:#fff;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,0.3);">
        </div>
        <div class="promo-card-badges">
            <?php if ($isFeatured):   ?><span class="promo-badge promo-badge-f">⭐</span><?php endif; ?>
            <?php if ($isBestseller): ?><span class="promo-badge promo-badge-bs">🔥</span><?php endif; ?>
        </div>
        <?php if ($product['image_url']): ?>
        <img src="<?= htmlspecialchars($product['image_url']) ?>" loading="lazy"
             alt="<?= htmlspecialchars($product['name']) ?>">
        <?php else: ?>
        <div class="promo-card-placeholder"><i class="fas fa-image"></i></div>
        <?php endif; ?>
    </div>
    <div class="promo-card-body">
        <div class="promo-card-name"><?= htmlspecialchars($product['name']) ?></div>
        <div class="promo-card-sku"><?= htmlspecialchars($product['sku'] ?? '-') ?></div>
        <div class="promo-card-price">
            <?php if ($product['sale_price']): ?>
            <span style="color:var(--color-rose-600);">฿<?= number_format($product['sale_price']) ?></span>
            <?php else: ?>
            <span>฿<?= number_format($product['price']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="promo-card-actions">
        <button onclick="toggle('featured', <?= $product['id'] ?>, this)"
                class="promo-btn <?= $isFeatured ? 'promo-btn-f-on' : 'promo-btn-f-off' ?>">
            <i class="<?= $isFeatured ? 'fas' : 'far' ?> fa-star"></i>
        </button>
        <button onclick="toggle('bestseller', <?= $product['id'] ?>, this)"
                class="promo-btn <?= $isBestseller ? 'promo-btn-bs-on' : 'promo-btn-bs-off' ?>">
            <i class="fas fa-fire"></i>
        </button>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Pagination -->
<?php if ($totalPages > 1):
    $baseUrl = '?' . http_build_query(array_diff_key($_GET, ['page' => ''])) . '&';
    echo '<div style="margin-top:var(--space-6);">'
        . renderPagination($page, $totalPages, $perPage, $baseUrl, ['total' => $totalProducts])
        . '</div>';
endif; ?>

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
    const res  = await fetch('promotions.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
        location.reload();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
