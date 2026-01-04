<?php
/**
 * Inventory Products Tab - จัดการสินค้า/บริการ
 * Tab content for inventory/index.php
 * Moved from shop/products.php
 */

// Initialize UnifiedShop
if (file_exists(__DIR__ . '/../../classes/UnifiedShop.php')) {
    require_once __DIR__ . '/../../classes/UnifiedShop.php';
}

$currentBotId = $_SESSION['current_bot_id'] ?? 1;
$shop = new UnifiedShop($db, null, $currentBotId);
$tablesExist = $shop->isReady();
$useBusinessItems = $shop->isV25();
$productsTable = $shop->getItemsTable() ?? 'products';
$categoriesTable = $shop->getCategoriesTable() ?? 'product_categories';

// Create tables if not exist
if (!$tablesExist) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS product_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            image_url VARCHAR(500),
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $db->exec("CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT DEFAULT NULL,
            category_id INT,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            sale_price DECIMAL(10,2) NULL,
            image_url VARCHAR(500),
            item_type ENUM('physical','digital','service','booking','content') DEFAULT 'physical',
            delivery_method ENUM('shipping','email','line','download','onsite') DEFAULT 'shipping',
            stock INT DEFAULT 0,
            sku VARCHAR(100),
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("INSERT INTO product_categories (name, sort_order) VALUES 
            ('สินค้าทั่วไป', 1), ('สินค้าแนะนำ', 2), ('โปรโมชั่น', 3)");
        
        $tablesExist = true;
        $productsTable = 'products';
        $categoriesTable = 'product_categories';
    } catch (Exception $e) {
        $error = "ไม่สามารถสร้างตารางได้: " . $e->getMessage();
    }
}

// Item types
$itemTypes = [
    'physical' => ['icon' => '📦', 'label' => 'สินค้าจัดส่ง'],
    'digital' => ['icon' => '🎮', 'label' => 'สินค้าดิจิทัล'],
    'service' => ['icon' => '💆', 'label' => 'บริการ'],
    'booking' => ['icon' => '📅', 'label' => 'จองคิว'],
    'content' => ['icon' => '📚', 'label' => 'เนื้อหา']
];

// Check columns
$hasItemType = false;
$hasNewColumns = false;
try {
    $stmt = $db->query("SHOW COLUMNS FROM {$productsTable} LIKE 'item_type'");
    $hasItemType = $stmt->rowCount() > 0;
    $stmt = $db->query("SHOW COLUMNS FROM {$productsTable} LIKE 'barcode'");
    $hasNewColumns = $stmt->rowCount() > 0;
} catch (Exception $e) {}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $cols = ['category_id', 'name', 'description', 'price', 'sale_price', 'image_url', 'stock', 'sku', 'is_active'];
        $data = [
            $_POST['category_id'] ?: null,
            $_POST['name'],
            $_POST['description'],
            (float)$_POST['price'],
            $_POST['sale_price'] ? (float)$_POST['sale_price'] : null,
            $_POST['image_url'],
            (int)$_POST['stock'],
            $_POST['sku'] ?: null,
            isset($_POST['is_active']) ? 1 : 0
        ];
        
        if ($hasNewColumns) {
            $cols = array_merge($cols, ['barcode', 'manufacturer', 'generic_name', 'usage_instructions', 'unit']);
            $data = array_merge($data, [
                $_POST['barcode'] ?: null,
                $_POST['manufacturer'] ?: null,
                $_POST['generic_name'] ?: null,
                $_POST['usage_instructions'] ?: null,
                $_POST['unit'] ?: 'ชิ้น'
            ]);
        }
        
        if ($hasItemType) {
            $cols = array_merge($cols, ['item_type', 'delivery_method']);
            $data = array_merge($data, [$_POST['item_type'] ?? 'physical', $_POST['delivery_method'] ?? 'shipping']);
        }
        
        // Promotion settings
        $cols = array_merge($cols, ['is_featured', 'is_flash_sale', 'is_choice', 'flash_sale_end']);
        $data = array_merge($data, [
            isset($_POST['is_featured']) ? 1 : 0,
            isset($_POST['is_flash_sale']) ? 1 : 0,
            isset($_POST['is_choice']) ? 1 : 0,
            !empty($_POST['flash_sale_end']) ? $_POST['flash_sale_end'] : null
        ]);
        
        if ($action === 'create') {
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $stmt = $db->prepare("INSERT INTO {$productsTable} (" . implode(',', $cols) . ") VALUES ({$placeholders})");
        } else {
            $sets = implode('=?, ', $cols) . '=?';
            $data[] = $_POST['id'];
            $stmt = $db->prepare("UPDATE {$productsTable} SET {$sets} WHERE id=?");
        }
        $stmt->execute($data);
        
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM {$productsTable} WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
    } elseif ($action === 'toggle') {
        $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
    } elseif ($action === 'bulk_deactivate') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = 0 WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        }
        
    } elseif ($action === 'bulk_activate') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = 1 WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        }
        
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['selected_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("DELETE FROM {$productsTable} WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
        }
        
    } elseif ($action === 'deactivate_out_of_stock') {
        $stmt = $db->query("UPDATE {$productsTable} SET is_active = 0 WHERE stock <= 0 AND is_active = 1");
        $_SESSION['bulk_message'] = 'ปิดสินค้าที่หมด stock แล้ว ' . $stmt->rowCount() . ' รายการ';
        
    } elseif ($action === 'deactivate_low_stock') {
        $stmt = $db->query("UPDATE {$productsTable} SET is_active = 0 WHERE stock <= 5 AND is_active = 1");
        $_SESSION['bulk_message'] = 'ปิดสินค้าที่ stock น้อย แล้ว ' . $stmt->rowCount() . ' รายการ';
    }
    
    header('Location: ?tab=products&' . http_build_query(array_diff_key($_GET, ['tab' => ''])));
    exit;
}

if (!$tablesExist): ?>
<div class="bg-yellow-100 text-yellow-700 p-4 rounded-lg">
    <i class="fas fa-exclamation-triangle mr-2"></i>ระบบร้านค้ายังไม่พร้อมใช้งาน
</div>
<?php return; endif;

// Get categories
$categories = [];
try {
    $stmt = $db->query("SELECT * FROM {$categoriesTable} WHERE is_active = 1 ORDER BY sort_order");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {}

// Filters
$categoryFilter = $_GET['category'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$searchFilter = $_GET['search'] ?? '';
$stockFilter = $_GET['stock_filter'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortDir = $_GET['dir'] ?? 'DESC';

// Validate sort
$allowedSorts = ['id', 'name', 'price', 'stock', 'created_at', 'sku'];
if (!in_array($sortBy, $allowedSorts)) $sortBy = 'created_at';
$sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100, 200])) $perPage = 50;

// Count total
$countSql = "SELECT COUNT(*) FROM {$productsTable} p WHERE 1=1";
$params = [];

if ($categoryFilter) {
    $countSql .= " AND p.category_id = ?";
    $params[] = (int)$categoryFilter;
}
if ($typeFilter && $hasItemType) {
    $countSql .= " AND p.item_type = ?";
    $params[] = $typeFilter;
}
if ($stockFilter) {
    switch ($stockFilter) {
        case 'low': $countSql .= " AND p.stock > 0 AND p.stock <= 5"; break;
        case 'out': $countSql .= " AND p.stock <= 0"; break;
        case 'inactive': $countSql .= " AND p.is_active = 0"; break;
    }
}
if ($searchFilter) {
    if ($hasNewColumns) {
        $countSql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.id = ?)";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = intval($searchFilter);
    } else {
        $countSql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.id = ?)";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = intval($searchFilter);
    }
}

$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalProducts = $stmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);
$offset = ($page - 1) * $perPage;

// Get products
$sql = "SELECT p.*, c.name as category_name FROM {$productsTable} p 
        LEFT JOIN {$categoriesTable} c ON p.category_id = c.id 
        WHERE 1=1";
$params = [];

if ($categoryFilter) {
    $sql .= " AND p.category_id = ?";
    $params[] = (int)$categoryFilter;
}
if ($typeFilter && $hasItemType) {
    $sql .= " AND p.item_type = ?";
    $params[] = $typeFilter;
}
if ($stockFilter) {
    switch ($stockFilter) {
        case 'low': $sql .= " AND p.stock > 0 AND p.stock <= 5"; break;
        case 'out': $sql .= " AND p.stock <= 0"; break;
        case 'inactive': $sql .= " AND p.is_active = 0"; break;
    }
}
if ($searchFilter) {
    if ($hasNewColumns) {
        $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.id = ?)";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = intval($searchFilter);
    } else {
        $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.id = ?)";
        $params[] = "%{$searchFilter}%";
        $params[] = "%{$searchFilter}%";
        $params[] = intval($searchFilter);
    }
}

$sql .= " ORDER BY p.{$sortBy} {$sortDir} LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Stats
$statsStmt = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN stock > 0 AND stock <= 5 THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
    SUM(CASE WHEN stock > 0 AND stock <= 5 AND is_active = 1 THEN 1 ELSE 0 END) as low_stock_active,
    SUM(CASE WHEN stock <= 0 AND is_active = 1 THEN 1 ELSE 0 END) as out_of_stock_active
    FROM {$productsTable}");
$stats = $statsStmt->fetch();

// Build query string helper
function buildProductQuery($overrides = []) {
    $params = array_merge($_GET, $overrides);
    $params['tab'] = 'products';
    unset($params['_']);
    return http_build_query($params);
}

function productSortLink($column, $label) {
    $sortBy = $_GET['sort'] ?? 'created_at';
    $sortDir = $_GET['dir'] ?? 'DESC';
    $newDir = ($sortBy === $column && $sortDir === 'ASC') ? 'DESC' : 'ASC';
    $icon = '';
    if ($sortBy === $column) {
        $icon = $sortDir === 'ASC' ? '<i class="fas fa-sort-up ml-1"></i>' : '<i class="fas fa-sort-down ml-1"></i>';
    }
    return '<a href="?'.buildProductQuery(['sort' => $column, 'dir' => $newDir, 'page' => 1]).'" class="hover:text-blue-600">'.$label.$icon.'</a>';
}
?>

<div class="space-y-4">
    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-box text-green-500 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">สินค้าทั้งหมด</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['total']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-check-circle text-blue-500 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">เปิดขาย</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['active']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-yellow-500 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">สินค้าใกล้หมด</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['low_stock']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-times-circle text-red-500 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">สินค้าหมด</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['out_of_stock']) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Actions -->
    <div class="flex flex-wrap justify-between items-center gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <form method="GET" class="flex items-center gap-2 flex-wrap">
                <input type="hidden" name="tab" value="products">
                <input type="text" name="search" value="<?= htmlspecialchars($searchFilter) ?>" placeholder="ค้นหา SKU/ชื่อ..." class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 w-40">
                <select name="category" class="px-4 py-2 border rounded-lg">
                    <option value="">ทุกหมวดหมู่</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="per_page" class="px-3 py-2 border rounded-lg">
                    <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>20/หน้า</option>
                    <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50/หน้า</option>
                    <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100/หน้า</option>
                    <option value="200" <?= $perPage == 200 ? 'selected' : '' ?>>200/หน้า</option>
                </select>
                <input type="hidden" name="sort" value="<?= $sortBy ?>">
                <input type="hidden" name="dir" value="<?= $sortDir ?>">
                <button type="submit" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200"><i class="fas fa-search"></i></button>
                <?php if ($searchFilter || $categoryFilter): ?>
                <a href="?tab=products" class="px-4 py-2 text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>
        <div class="flex items-center gap-2">
            <a href="/shop/categories" class="px-4 py-2 border rounded-lg hover:bg-gray-50"><i class="fas fa-folder mr-2"></i>หมวดหมู่</a>
            <button onclick="openProductModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600"><i class="fas fa-plus mr-2"></i>เพิ่มสินค้า</button>
        </div>
    </div>


    <!-- Quick Sort & Filter Buttons -->
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-gray-500 mr-2"><i class="fas fa-sort mr-1"></i>จัดเรียง:</span>
            
            <a href="?<?= buildProductQuery(['sort' => 'price', 'dir' => ($sortBy == 'price' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'price' ? 'bg-green-500 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-baht-sign mr-1"></i>ราคา
            </a>
            
            <a href="?<?= buildProductQuery(['sort' => 'stock', 'dir' => ($sortBy == 'stock' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'stock' ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-boxes-stacked mr-1"></i>สต็อก
            </a>
            
            <a href="?<?= buildProductQuery(['sort' => 'name', 'dir' => ($sortBy == 'name' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'name' ? 'bg-purple-500 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-font mr-1"></i>ชื่อ
            </a>
            
            <a href="?<?= buildProductQuery(['sort' => 'created_at', 'dir' => 'DESC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'created_at' ? 'bg-orange-500 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-clock mr-1"></i>ล่าสุด
            </a>

            <div class="border-l pl-3 ml-2">
                <span class="text-sm text-gray-500 mr-2"><i class="fas fa-filter mr-1"></i>กรอง:</span>
                
                <a href="?<?= buildProductQuery(['stock_filter' => 'low', 'page' => 1]) ?>" 
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $stockFilter == 'low' ? 'bg-yellow-500 text-white' : 'bg-yellow-100 hover:bg-yellow-200 text-yellow-700' ?>">
                    <i class="fas fa-exclamation-triangle mr-1"></i>ใกล้หมด
                </a>
                
                <a href="?<?= buildProductQuery(['stock_filter' => 'out', 'page' => 1]) ?>" 
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $stockFilter == 'out' ? 'bg-red-500 text-white' : 'bg-red-100 hover:bg-red-200 text-red-700' ?>">
                    <i class="fas fa-times-circle mr-1"></i>หมด
                </a>
                
                <a href="?<?= buildProductQuery(['stock_filter' => 'inactive', 'page' => 1]) ?>" 
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $stockFilter == 'inactive' ? 'bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-700' ?>">
                    <i class="fas fa-eye-slash mr-1"></i>ปิดขาย
                </a>
                
                <?php if ($stockFilter): ?>
                <a href="?<?= buildProductQuery(['stock_filter' => '']) ?>" class="px-2 py-1.5 text-gray-500 hover:text-red-500">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bulk Actions -->
    <?php 
    $bulkMessage = $_SESSION['bulk_message'] ?? null;
    unset($_SESSION['bulk_message']);
    ?>
    <?php if ($bulkMessage): ?>
    <div class="p-3 bg-green-100 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($bulkMessage) ?>
    </div>
    <?php endif; ?>
    
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500"><i class="fas fa-tasks mr-1"></i>Bulk Actions:</span>
                
                <?php if ($stats['out_of_stock_active'] > 0): ?>
                <form method="POST" class="inline" onsubmit="return confirm('ปิดสินค้าที่หมด stock ทั้งหมด?')">
                    <input type="hidden" name="action" value="deactivate_out_of_stock">
                    <button type="submit" class="px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-sm font-medium hover:bg-red-200">
                        <i class="fas fa-ban mr-1"></i>ปิดสินค้าหมด (<?= number_format($stats['out_of_stock_active']) ?>)
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($stats['low_stock_active'] > 0): ?>
                <form method="POST" class="inline" onsubmit="return confirm('ปิดสินค้าที่ stock น้อยกว่า 5?')">
                    <input type="hidden" name="action" value="deactivate_low_stock">
                    <button type="submit" class="px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-lg text-sm font-medium hover:bg-yellow-200">
                        <i class="fas fa-exclamation-triangle mr-1"></i>ปิดสินค้าใกล้หมด (<?= number_format($stats['low_stock_active']) ?>)
                    </button>
                </form>
                <?php endif; ?>
            </div>
            
            <div id="selectionActions" class="hidden flex items-center gap-2">
                <span class="text-sm text-gray-600"><span id="selectedCount">0</span> รายการที่เลือก:</span>
                <button type="button" onclick="bulkAction('bulk_activate')" class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-sm font-medium hover:bg-green-200">
                    <i class="fas fa-check mr-1"></i>เปิดขาย
                </button>
                <button type="button" onclick="bulkAction('bulk_deactivate')" class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300">
                    <i class="fas fa-eye-slash mr-1"></i>ปิดขาย
                </button>
                <button type="button" onclick="bulkAction('bulk_delete')" class="px-3 py-1.5 bg-red-500 text-white rounded-lg text-sm font-medium hover:bg-red-600">
                    <i class="fas fa-trash mr-1"></i>ลบ
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden form for bulk actions -->
    <form id="bulkForm" method="POST" class="hidden">
        <input type="hidden" name="action" id="bulkAction">
        <div id="bulkIds"></div>
    </form>

    <!-- Pagination Info -->
    <div class="flex justify-between items-center text-sm text-gray-600">
        <div>แสดง <?= number_format($offset + 1) ?>-<?= number_format(min($offset + $perPage, $totalProducts)) ?> จาก <?= number_format($totalProducts) ?> รายการ</div>
        <div class="flex items-center gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?= buildProductQuery(['page' => $page - 1]) ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?<?= buildProductQuery(['page' => $i]) ?>" class="px-3 py-1 border rounded <?= $i == $page ? 'bg-green-500 text-white' : 'hover:bg-gray-100' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?= buildProductQuery(['page' => $page + 1]) ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Products Table -->
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-2 py-3 text-center w-10">
                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll()" class="w-4 h-4 rounded">
                        </th>
                        <th class="px-2 py-3 text-left w-14">รูป</th>
                        <th class="px-2 py-3 text-left"><?= productSortLink('sku', 'SKU') ?></th>
                        <th class="px-2 py-3 text-left"><?= productSortLink('name', 'ชื่อสินค้า') ?></th>
                        <th class="px-2 py-3 text-left">หมวดหมู่</th>
                        <th class="px-2 py-3 text-right"><?= productSortLink('price', 'ราคา') ?></th>
                        <th class="px-2 py-3 text-center"><?= productSortLink('stock', 'Stock') ?></th>
                        <th class="px-2 py-3 text-center">สถานะ</th>
                        <th class="px-2 py-3 text-center w-28">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($products as $product): ?>
                    <tr class="hover:bg-gray-50 product-row" data-id="<?= $product['id'] ?>">
                        <td class="px-2 py-2 text-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="product-checkbox w-4 h-4 rounded" value="<?= $product['id'] ?>" onchange="updateSelection()">
                        </td>
                        <td class="px-2 py-2">
                            <div class="w-12 h-12 bg-gray-100 rounded overflow-hidden">
                                <?php if ($product['image_url']): ?>
                                <img src="<?= htmlspecialchars($product['image_url']) ?>" class="w-full h-full object-cover" loading="lazy" onerror="this.src='https://via.placeholder.com/48?text=No'">
                                <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-300"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-2 py-2">
                            <?php if (!empty($product['sku'])): ?>
                            <div class="font-mono text-xs font-medium text-gray-800 bg-blue-50 px-1.5 py-0.5 rounded inline-block"><?= htmlspecialchars($product['sku']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2">
                            <div class="font-medium text-gray-900 line-clamp-2"><?= htmlspecialchars($product['name']) ?></div>
                        </td>
                        <td class="px-2 py-2 text-xs text-gray-400"><?= htmlspecialchars($product['category_name'] ?? '-') ?></td>
                        <td class="px-2 py-2 text-right">
                            <?php if ($product['sale_price']): ?>
                            <div class="text-red-500 font-bold">฿<?= number_format($product['sale_price'], 2) ?></div>
                            <div class="text-xs text-gray-400 line-through">฿<?= number_format($product['price'], 2) ?></div>
                            <?php else: ?>
                            <div class="text-green-600 font-bold">฿<?= number_format($product['price'], 2) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2 text-center">
                            <?php if ($product['stock'] <= 0): ?>
                            <span class="px-2 py-1 bg-red-100 text-red-600 text-xs rounded-full">หมด</span>
                            <?php elseif ($product['stock'] <= 5): ?>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full"><?= $product['stock'] ?></span>
                            <?php else: ?>
                            <span class="text-gray-800 font-medium"><?= number_format($product['stock']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2 text-center">
                            <?php if ($product['is_active']): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-600 text-xs rounded-full">เปิด</span>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-gray-100 text-gray-500 text-xs rounded-full">ปิด</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2" onclick="event.stopPropagation()">
                            <div class="flex items-center justify-center gap-1">
                                <button onclick='editProduct(<?= json_encode($product, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="p-1.5 text-blue-500 hover:bg-blue-50 rounded"><i class="fas fa-edit"></i></button>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                    <button type="submit" class="p-1.5 text-gray-500 hover:bg-gray-100 rounded"><i class="fas <?= $product['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                                </form>
                                <form method="POST" onsubmit="return confirm('ลบสินค้านี้?')" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                    <button type="submit" class="p-1.5 text-red-500 hover:bg-red-50 rounded"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($products)): ?>
                    <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500"><i class="fas fa-box-open text-4xl mb-2"></i><p>ไม่พบสินค้า</p></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Product Modal -->
<div id="productModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-3xl mx-4 max-h-[95vh] overflow-hidden flex flex-col">
        <form method="POST" id="productForm">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId">
            
            <div class="px-6 py-4 bg-gradient-to-r from-blue-500 to-blue-600 text-white flex justify-between items-center">
                <h3 class="text-lg font-semibold" id="modalTitle">เพิ่มสินค้า</h3>
                <button type="button" onclick="closeProductModal()" class="text-white hover:text-blue-200 text-2xl"><i class="fas fa-times"></i></button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-6 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">รหัสสินค้า (SKU)</label>
                        <input type="text" name="sku" id="sku" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <?php if ($hasNewColumns): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">บาร์โค้ด</label>
                        <input type="text" name="barcode" id="barcode" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <?php endif; ?>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อสินค้า *</label>
                    <input type="text" name="name" id="name" required class="w-full px-3 py-2 border rounded-lg">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">หมวดหมู่</label>
                    <select name="category_id" id="category_id" class="w-full px-3 py-2 border rounded-lg">
                        <option value="">-- เลือก --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">รายละเอียด</label>
                    <textarea name="description" id="description" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">URL รูปภาพ</label>
                    <input type="url" name="image_url" id="image_url" class="w-full px-3 py-2 border rounded-lg">
                </div>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ราคา *</label>
                        <input type="number" name="price" id="price" required min="0" step="0.01" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ราคาลด</label>
                        <input type="number" name="sale_price" id="sale_price" min="0" step="0.01" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">จำนวน</label>
                        <input type="number" name="stock" id="stock" value="0" min="0" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <?php if ($hasNewColumns): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">หน่วย</label>
                        <input type="text" name="unit" id="unit" value="ชิ้น" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" id="is_active" checked class="w-4 h-4 text-green-500">
                    <label for="is_active" class="text-sm">เปิดขาย</label>
                </div>
                
                <!-- Promotion Settings -->
                <div class="p-4 bg-gradient-to-r from-orange-50 to-yellow-50 rounded-lg border border-orange-200">
                    <h4 class="text-sm font-semibold text-orange-700 mb-3"><i class="fas fa-star mr-2"></i>ตั้งค่าโปรโมชั่น</h4>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="is_featured" id="is_featured" class="w-4 h-4 text-orange-500">
                            <label for="is_featured" class="text-sm"><i class="fas fa-thumbs-up text-orange-500 mr-1"></i>สินค้าแนะนำ</label>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="is_flash_sale" id="is_flash_sale" class="w-4 h-4 text-red-500">
                            <label for="is_flash_sale" class="text-sm"><i class="fas fa-bolt text-red-500 mr-1"></i>Flash Sale</label>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="is_choice" id="is_choice" class="w-4 h-4 text-blue-500">
                            <label for="is_choice" class="text-sm"><i class="fas fa-award text-blue-500 mr-1"></i>Choice</label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="px-6 py-4 border-t flex justify-end space-x-2">
                <button type="button" onclick="closeProductModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600"><i class="fas fa-save mr-2"></i>บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openProductModal() {
    document.getElementById('productModal').classList.remove('hidden');
    document.getElementById('productModal').classList.add('flex');
    document.getElementById('formAction').value = 'create';
    document.getElementById('modalTitle').textContent = 'เพิ่มสินค้า';
    document.getElementById('productForm').reset();
    document.getElementById('is_active').checked = true;
}

function closeProductModal() {
    document.getElementById('productModal').classList.add('hidden');
    document.getElementById('productModal').classList.remove('flex');
}

function editProduct(product) {
    document.getElementById('productModal').classList.remove('hidden');
    document.getElementById('productModal').classList.add('flex');
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = product.id;
    document.getElementById('modalTitle').textContent = 'แก้ไขสินค้า';
    
    document.getElementById('sku').value = product.sku || '';
    document.getElementById('name').value = product.name || '';
    document.getElementById('description').value = product.description || '';
    document.getElementById('price').value = product.price || '';
    document.getElementById('sale_price').value = product.sale_price || '';
    document.getElementById('stock').value = product.stock || 0;
    document.getElementById('image_url').value = product.image_url || '';
    document.getElementById('category_id').value = product.category_id || '';
    document.getElementById('is_active').checked = product.is_active == 1;
    
    if (document.getElementById('barcode')) document.getElementById('barcode').value = product.barcode || '';
    if (document.getElementById('unit')) document.getElementById('unit').value = product.unit || 'ชิ้น';
    if (document.getElementById('is_featured')) document.getElementById('is_featured').checked = product.is_featured == 1;
    if (document.getElementById('is_flash_sale')) document.getElementById('is_flash_sale').checked = product.is_flash_sale == 1;
    if (document.getElementById('is_choice')) document.getElementById('is_choice').checked = product.is_choice == 1;
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    document.querySelectorAll('.product-checkbox').forEach(cb => cb.checked = selectAll.checked);
    updateSelection();
}

function updateSelection() {
    const checked = document.querySelectorAll('.product-checkbox:checked');
    const count = checked.length;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('selectionActions').classList.toggle('hidden', count === 0);
}

function bulkAction(action) {
    const checked = document.querySelectorAll('.product-checkbox:checked');
    if (checked.length === 0) return;
    
    const confirmMsg = {
        'bulk_activate': 'เปิดขายสินค้าที่เลือก?',
        'bulk_deactivate': 'ปิดขายสินค้าที่เลือก?',
        'bulk_delete': 'ลบสินค้าที่เลือก? การกระทำนี้ไม่สามารถย้อนกลับได้'
    };
    
    if (!confirm(confirmMsg[action])) return;
    
    document.getElementById('bulkAction').value = action;
    const idsContainer = document.getElementById('bulkIds');
    idsContainer.innerHTML = '';
    checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = cb.value;
        idsContainer.appendChild(input);
    });
    document.getElementById('bulkForm').submit();
}
</script>
