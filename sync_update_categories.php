<?php
/**
 * Update Product Categories from CNY Data
 * อัพเดทหมวดหมู่สินค้าจากข้อมูล CNY API
 * Standalone version - ไม่ต้องพึ่ง method ใหม่ใน CnyPharmacyAPI
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    require_once 'config/config.php';
    require_once 'config/database.php';
} catch (Exception $e) {
    die("Error loading files: " . $e->getMessage());
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// ==================== HELPER FUNCTIONS ====================

/**
 * Parse category name from CNY format
 * Example: "GIS-04-แก้ท้องเสีย-ท้องผูก" -> "แก้ท้องเสีย-ท้องผูก"
 */
function parseCategoryName($cnyCategoryCode) {
    if (empty($cnyCategoryCode)) {
        return null;
    }
    
    $parts = explode('-', $cnyCategoryCode);
    $thaiParts = [];
    $foundThai = false;
    
    foreach ($parts as $part) {
        if (preg_match('/[\x{0E00}-\x{0E7F}]/u', $part)) {
            $foundThai = true;
        }
        if ($foundThai) {
            $thaiParts[] = $part;
        }
    }
    
    if (!empty($thaiParts)) {
        return implode('-', $thaiParts);
    }
    
    if (count($parts) > 2) {
        return implode('-', array_slice($parts, 2));
    }
    
    return $cnyCategoryCode;
}

/**
 * Get or create category
 */
function getOrCreateCategory($db, $cnyCategoryCode, $lineAccountId) {
    if (empty($cnyCategoryCode)) {
        return null;
    }
    
    $categoryName = parseCategoryName($cnyCategoryCode);
    if (empty($categoryName)) {
        return null;
    }
    
    // Ensure cny_code column exists
    try {
        $stmt = $db->query("SHOW COLUMNS FROM product_categories LIKE 'cny_code'");
        if ($stmt->rowCount() == 0) {
            $db->exec("ALTER TABLE product_categories ADD COLUMN cny_code VARCHAR(100) NULL AFTER name");
            $db->exec("ALTER TABLE product_categories ADD INDEX idx_cny_code (cny_code)");
        }
    } catch (Exception $e) {}
    
    // Find by cny_code
    try {
        $stmt = $db->prepare("SELECT id FROM product_categories WHERE cny_code = ? LIMIT 1");
        $stmt->execute([$cnyCategoryCode]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return $existing['id'];
        }
    } catch (Exception $e) {}
    
    // Find by name
    try {
        $stmt = $db->prepare("SELECT id FROM product_categories WHERE name = ? LIMIT 1");
        $stmt->execute([$categoryName]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $db->prepare("UPDATE product_categories SET cny_code = ? WHERE id = ? AND (cny_code IS NULL OR cny_code = '')")
                ->execute([$cnyCategoryCode, $existing['id']]);
            return $existing['id'];
        }
    } catch (Exception $e) {}
    
    // Create new
    try {
        $stmt = $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM product_categories");
        $nextOrder = $stmt->fetchColumn();
        
        $stmt = $db->prepare("INSERT INTO product_categories (line_account_id, name, cny_code, sort_order, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$lineAccountId, $categoryName, $cnyCategoryCode, $nextOrder]);
        return $db->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Update categories for existing products
 */
function updateProductCategories($db, $lineAccountId) {
    $stats = ['total' => 0, 'updated' => 0, 'skipped' => 0, 'created_categories' => 0, 'errors' => []];
    
    // Get products with extra_data
    try {
        $stmt = $db->query("SELECT id, extra_data, category_id FROM business_items WHERE extra_data IS NOT NULL AND extra_data != ''");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Cannot fetch products: ' . $e->getMessage()];
    }
    
    $stats['total'] = count($products);
    
    foreach ($products as $product) {
        try {
            $extraData = json_decode($product['extra_data'], true);
            $cnyCategory = $extraData['cny_category'] ?? null;
            
            if (empty($cnyCategory)) {
                $stats['skipped']++;
                continue;
            }
            
            $categoryId = getOrCreateCategory($db, $cnyCategory, $lineAccountId);
            
            if ($categoryId && $categoryId != $product['category_id']) {
                $db->prepare("UPDATE business_items SET category_id = ? WHERE id = ?")
                    ->execute([$categoryId, $product['id']]);
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
        } catch (Exception $e) {
            $stats['errors'][] = ['id' => $product['id'], 'error' => $e->getMessage()];
        }
    }
    
    return ['success' => true, 'stats' => $stats];
}

// ==================== HANDLE ACTIONS ====================

$message = '';
$stats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_categories') {
        $result = updateProductCategories($db, $currentBotId);
        if ($result['success']) {
            $stats = $result['stats'];
            $message = "✅ อัพเดทหมวดหมู่สำเร็จ!";
        } else {
            $message = "❌ Error: " . ($result['error'] ?? 'Unknown error');
        }
    }
    
    if ($action === 'add_cny_code_column') {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM product_categories LIKE 'cny_code'");
            if ($stmt->rowCount() == 0) {
                $db->exec("ALTER TABLE product_categories ADD COLUMN cny_code VARCHAR(100) NULL AFTER name");
                $db->exec("ALTER TABLE product_categories ADD INDEX idx_cny_code (cny_code)");
                $message = "✅ เพิ่มคอลัมน์ cny_code สำเร็จ!";
            } else {
                $message = "✅ คอลัมน์ cny_code มีอยู่แล้ว";
            }
        } catch (Exception $e) {
            $message = "❌ Error: " . $e->getMessage();
        }
    }
}

// ==================== GET DATA ====================

// Check if cny_code column exists
$hasCnyCode = false;
try {
    $stmt = $db->query("SHOW COLUMNS FROM product_categories LIKE 'cny_code'");
    $hasCnyCode = $stmt->rowCount() > 0;
} catch (Exception $e) {}

// Get category stats
$categoryStats = [];
try {
    if ($hasCnyCode) {
        $stmt = $db->query("
            SELECT c.id, c.name, c.cny_code, COUNT(p.id) as product_count
            FROM product_categories c
            LEFT JOIN business_items p ON p.category_id = c.id
            GROUP BY c.id, c.name, c.cny_code
            ORDER BY c.sort_order
        ");
    } else {
        $stmt = $db->query("
            SELECT c.id, c.name, NULL as cny_code, COUNT(p.id) as product_count
            FROM product_categories c
            LEFT JOIN business_items p ON p.category_id = c.id
            GROUP BY c.id, c.name
            ORDER BY c.sort_order
        ");
    }
    $categoryStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get products without category
$productsWithoutCategory = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM business_items WHERE category_id IS NULL");
    $productsWithoutCategory = $stmt->fetchColumn();
} catch (Exception $e) {}

// Count CNY categories
$cnyCategories = array_filter($categoryStats, function($c) { return !empty($c['cny_code']); });

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัพเดทหมวดหมู่สินค้า - CNY Sync</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-folder-tree text-green-500 mr-2"></i>
                        อัพเดทหมวดหมู่สินค้า
                    </h1>
                    <p class="text-gray-500 mt-1">สร้างหมวดหมู่อัตโนมัติจากข้อมูล CNY Pharmacy API</p>
                </div>
                <a href="shop/products.php" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200">
                    <i class="fas fa-arrow-left mr-2"></i>กลับ
                </a>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-lg <?= strpos($message, '✅') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
            <?= $message ?>
        </div>
        <?php endif; ?>
        
        <?php if (!$hasCnyCode): ?>
        <div class="mb-6 p-4 bg-yellow-100 text-yellow-700 rounded-lg">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            <strong>ต้องเพิ่มคอลัมน์ cny_code ก่อน</strong>
            <form method="POST" class="mt-2">
                <input type="hidden" name="action" value="add_cny_code_column">
                <button type="submit" class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600">
                    <i class="fas fa-plus mr-2"></i>เพิ่มคอลัมน์ cny_code
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <?php if ($stats): ?>
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h3 class="font-semibold text-gray-700 mb-4">📊 ผลการดำเนินการ</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-3 bg-blue-50 rounded-lg">
                    <div class="text-2xl font-bold text-blue-600"><?= number_format($stats['total'] ?? 0) ?></div>
                    <div class="text-sm text-gray-500">ทั้งหมด</div>
                </div>
                <div class="text-center p-3 bg-green-50 rounded-lg">
                    <div class="text-2xl font-bold text-green-600"><?= number_format($stats['updated'] ?? 0) ?></div>
                    <div class="text-sm text-gray-500">อัพเดท</div>
                </div>
                <div class="text-center p-3 bg-gray-50 rounded-lg">
                    <div class="text-2xl font-bold text-gray-600"><?= number_format($stats['skipped'] ?? 0) ?></div>
                    <div class="text-sm text-gray-500">ข้าม</div>
                </div>
                <div class="text-center p-3 bg-red-50 rounded-lg">
                    <div class="text-2xl font-bold text-red-600"><?= count($stats['errors'] ?? []) ?></div>
                    <div class="text-sm text-gray-500">Error</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-xl shadow p-6">
                <h3 class="font-semibold text-gray-700 mb-4">
                    <i class="fas fa-chart-pie text-purple-500 mr-2"></i>สถานะปัจจุบัน
                </h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">หมวดหมู่ทั้งหมด</span>
                        <span class="font-bold text-purple-600"><?= count($categoryStats) ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">หมวดหมู่จาก CNY</span>
                        <span class="font-bold text-green-600"><?= count($cnyCategories) ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">สินค้าไม่มีหมวดหมู่</span>
                        <span class="font-bold <?= $productsWithoutCategory > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= number_format($productsWithoutCategory) ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">คอลัมน์ cny_code</span>
                        <span class="font-bold <?= $hasCnyCode ? 'text-green-600' : 'text-red-600' ?>">
                            <?= $hasCnyCode ? '✅ มี' : '❌ ไม่มี' ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow p-6">
                <h3 class="font-semibold text-gray-700 mb-4">
                    <i class="fas fa-cogs text-blue-500 mr-2"></i>การทำงาน
                </h3>
                <div class="space-y-3">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_categories">
                        <button type="submit" class="w-full px-4 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium <?= !$hasCnyCode ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= !$hasCnyCode ? 'disabled' : '' ?>>
                            <i class="fas fa-sync mr-2"></i>อัพเดทหมวดหมู่สินค้าที่มีอยู่
                        </button>
                        <p class="text-xs text-gray-500 mt-1">อ่าน extra_data และสร้างหมวดหมู่จาก cny_category</p>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Category List -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-6 py-4 border-b bg-gray-50">
                <h3 class="font-semibold text-gray-700">
                    <i class="fas fa-list text-green-500 mr-2"></i>หมวดหมู่ทั้งหมด (<?= count($categoryStats) ?>)
                </h3>
            </div>
            <div class="divide-y">
                <?php if (empty($categoryStats)): ?>
                <div class="p-8 text-center text-gray-500">
                    <i class="fas fa-folder-open text-4xl mb-2"></i>
                    <p>ยังไม่มีหมวดหมู่</p>
                </div>
                <?php else: ?>
                <?php foreach ($categoryStats as $cat): ?>
                <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-folder text-green-500"></i>
                        </div>
                        <div>
                            <div class="font-medium text-gray-800"><?= htmlspecialchars($cat['name']) ?></div>
                            <?php if (!empty($cat['cny_code'])): ?>
                            <div class="text-xs text-gray-400 font-mono"><?= htmlspecialchars($cat['cny_code']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm">
                            <?= number_format($cat['product_count']) ?> สินค้า
                        </span>
                        <?php if (!empty($cat['cny_code'])): ?>
                        <span class="px-2 py-1 bg-green-100 text-green-600 text-xs rounded">CNY</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- How it works -->
        <div class="bg-white rounded-xl shadow p-6 mt-6">
            <h3 class="font-semibold text-gray-700 mb-4">
                <i class="fas fa-info-circle text-blue-500 mr-2"></i>วิธีการทำงาน
            </h3>
            <div class="text-sm text-gray-600 space-y-2">
                <p>📌 ระบบจะอ่าน field <code class="bg-gray-100 px-1 rounded">cny_category</code> จาก extra_data ของสินค้า</p>
                <p>📌 ตัวอย่าง: <code class="bg-gray-100 px-1 rounded">GIS-04-แก้ท้องเสีย-ท้องผูก</code> → สร้างหมวดหมู่ <strong>"แก้ท้องเสีย-ท้องผูก"</strong></p>
                <p>📌 ถ้าหมวดหมู่มีอยู่แล้ว จะไม่สร้างซ้ำ</p>
                <p>📌 สินค้าจะถูกจัดเข้าหมวดหมู่อัตโนมัติ</p>
            </div>
        </div>
    </div>
</body>
</html>
