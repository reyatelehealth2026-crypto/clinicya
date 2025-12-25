<?php
/**
 * Sync Categories from CNY API
 * ดึงข้อมูล category จาก CNY API แล้ว assign ให้สินค้าโดยตรง
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/CnyPharmacyAPI.php';

$db = Database::getInstance()->getConnection();
$cnyApi = new CnyPharmacyAPI($db);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Sync Categories from CNY</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; max-width: 1200px; margin: 20px auto; padding: 20px; background: #f5f5f5; }
.card { background: white; border-radius: 12px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
h1 { color: #1E293B; } h2 { color: #475569; }
.success { color: #10B981; } .error { color: #EF4444; } .warning { color: #F59E0B; } .info { color: #3B82F6; }
table { width: 100%; border-collapse: collapse; margin: 15px 0; }
th, td { padding: 10px; text-align: left; border-bottom: 1px solid #E2E8F0; font-size: 13px; }
th { background: #F8FAFC; }
.btn { display: inline-block; padding: 12px 24px; background: #10B981; color: white; text-decoration: none; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; margin: 5px; }
.btn:hover { background: #059669; }
.btn-blue { background: #3B82F6; } .btn-blue:hover { background: #2563EB; }
.btn-orange { background: #F59E0B; } .btn-orange:hover { background: #D97706; }
.progress { background: #E2E8F0; border-radius: 8px; height: 20px; margin: 10px 0; overflow: hidden; }
.progress-bar { background: #10B981; height: 100%; transition: width 0.3s; }
pre { background: #1E293B; color: #10B981; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 11px; max-height: 300px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.badge-green { background: #D1FAE5; color: #065F46; }
.badge-yellow { background: #FEF3C7; color: #92400E; }
.badge-red { background: #FEE2E2; color: #991B1B; }
</style></head><body>";

echo "<h1>🔄 Sync Categories from CNY API</h1>";

// 22 Categories มาตรฐาน - Map code to name
$standardCategories = [
    'CNS' => ['name' => 'ระบบประสาทส่วนกลาง', 'icon' => '🧠'],
    'IMM' => ['name' => 'แก้แพ้และภูมิคุ้มกัน', 'icon' => '🛡️'],
    'MSJ' => ['name' => 'ระบบกล้ามเนื้อและข้อ', 'icon' => '💪'],
    'RIS' => ['name' => 'ระบบทางเดินหายใจ', 'icon' => '🫁'],
    'HCD' => ['name' => 'อุปกรณ์ดูแลสุขภาพ', 'icon' => '🩺'],
    'VIT' => ['name' => 'วิตามิน เกลือแร่', 'icon' => '💊'],
    'HER' => ['name' => 'สมุนไพรและยาแผนโบราณ', 'icon' => '🌿'],
    'FMC' => ['name' => 'สินค้าอุปโภค-บริโภค', 'icon' => '🛒'],
    'HOR' => ['name' => 'ฮอร์โมน ยาคุมกำเนิด สูตินรี และระบบทางเดินปัสสาวะ', 'icon' => '💉'],
    'CDS' => ['name' => 'ระบบหัวใจและหลอดเลือด', 'icon' => '❤️'],
    'INF' => ['name' => 'การติดเชื้อ', 'icon' => '🦠'],
    'GIS' => ['name' => 'ระบบทางเดินอาหาร', 'icon' => '🍽️'],
    'SKI' => ['name' => 'ระบบผิวหนัง', 'icon' => '🧴'],
    'NUT' => ['name' => 'ผลิตภัณฑ์เสริมอาหารและโภชนาการ', 'icon' => '🥗'],
    'COS' => ['name' => 'เวชสำอางค์และเครื่องสำอางค์', 'icon' => '💄'],
    'SHP' => ['name' => 'ค่าขนส่ง', 'icon' => '🚚'],
    'END' => ['name' => 'ระบบต่อมไร้ท่อ', 'icon' => '🔬'],
    'ENT' => ['name' => 'ตา หู จมูก และช่องปาก', 'icon' => '👁️'],
    'OFC' => ['name' => 'อุปกรณ์สำนักงานภายในร้าน', 'icon' => '📎'],
    'ELE' => ['name' => 'อุปกรณ์เครื่องใช้ไฟฟ้า', 'icon' => '🔌'],
    'OTC' => ['name' => 'ยาสามัญประจำบ้าน', 'icon' => '💊'],
    'SM'  => ['name' => 'สินค้าสมนาคุณ', 'icon' => '🎁'],
];

// Detect categories table
$catTable = 'item_categories';
try {
    $db->query("SELECT 1 FROM item_categories LIMIT 1");
} catch (Exception $e) {
    $catTable = 'product_categories';
}

// ========== Step 1: Fetch from CNY API ==========
if (isset($_POST['fetch_cny'])) {
    echo "<div class='card'>";
    echo "<h2>📥 กำลังดึงข้อมูลจาก CNY API...</h2>";
    
    $result = $cnyApi->getAllProductsCached();
    
    if (!$result['success']) {
        echo "<p class='error'>❌ Error: " . ($result['error'] ?? 'Unknown error') . "</p>";
    } else {
        $products = $result['data'];
        $totalProducts = count($products);
        
        echo "<p class='success'>✓ ดึงข้อมูลสำเร็จ: <strong>$totalProducts</strong> สินค้า</p>";
        
        // Extract unique categories from CNY data
        $cnyCategories = [];
        foreach ($products as $p) {
            $cat = $p['category'] ?? '';
            if (!empty($cat) && !isset($cnyCategories[$cat])) {
                // Extract main code (first 3 chars)
                $mainCode = strtoupper(substr($cat, 0, 3));
                $cnyCategories[$cat] = [
                    'full_code' => $cat,
                    'main_code' => $mainCode,
                    'count' => 0
                ];
            }
            if (!empty($cat)) {
                $cnyCategories[$cat]['count']++;
            }
        }
        
        // Sort by count
        uasort($cnyCategories, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        echo "<p>พบ <strong>" . count($cnyCategories) . "</strong> categories จาก CNY</p>";
        
        // Show categories found
        echo "<table>";
        echo "<tr><th>CNY Category Code</th><th>Main Code</th><th>Standard Name</th><th>Products</th></tr>";
        foreach ($cnyCategories as $code => $info) {
            $mainCode = $info['main_code'];
            $stdName = isset($standardCategories[$mainCode]) 
                ? $standardCategories[$mainCode]['icon'] . ' ' . $standardCategories[$mainCode]['name']
                : '<span class="badge badge-yellow">ไม่พบ</span>';
            echo "<tr>";
            echo "<td><code>$code</code></td>";
            echo "<td><strong>{$mainCode}</strong></td>";
            echo "<td>$stdName</td>";
            echo "<td>{$info['count']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Save to session for next step
        $_SESSION['cny_products'] = $products;
        $_SESSION['cny_categories'] = $cnyCategories;
    }
    echo "</div>";
}

// ========== Step 2: Sync Categories & Products ==========
if (isset($_POST['sync_all'])) {
    echo "<div class='card'>";
    echo "<h2>🔄 กำลัง Sync Categories และ Products...</h2>";
    
    // Fetch fresh data
    $result = $cnyApi->getAllProductsCached();
    if (!$result['success']) {
        echo "<p class='error'>❌ Error fetching data</p>";
        echo "</div></body></html>";
        exit;
    }
    
    $products = $result['data'];
    $totalProducts = count($products);
    
    // Check columns
    $hasCnyCode = false;
    $hasIcon = false;
    try {
        $stmt = $db->query("SHOW COLUMNS FROM $catTable LIKE 'cny_code'");
        $hasCnyCode = $stmt->rowCount() > 0;
        $stmt = $db->query("SHOW COLUMNS FROM $catTable LIKE 'icon'");
        $hasIcon = $stmt->rowCount() > 0;
    } catch (Exception $e) {}
    
    // Add columns if needed
    if (!$hasCnyCode) {
        try {
            $db->exec("ALTER TABLE $catTable ADD COLUMN cny_code VARCHAR(10) NULL");
            $hasCnyCode = true;
        } catch (Exception $e) {}
    }
    if (!$hasIcon) {
        try {
            $db->exec("ALTER TABLE $catTable ADD COLUMN icon VARCHAR(50) NULL");
            $hasIcon = true;
        } catch (Exception $e) {}
    }
    
    // Step 2a: Create/Update 22 standard categories
    echo "<h3>📁 สร้าง 22 Categories มาตรฐาน...</h3>";
    $catMap = []; // code => id
    
    foreach ($standardCategories as $code => $info) {
        try {
            // Check if exists
            $stmt = $db->prepare("SELECT id FROM $catTable WHERE cny_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $existingId = $stmt->fetchColumn();
            
            $fullName = $code . '-' . $info['name'];
            
            if ($existingId) {
                // Update
                if ($hasIcon) {
                    $stmt = $db->prepare("UPDATE $catTable SET name = ?, icon = ?, is_active = 1 WHERE id = ?");
                    $stmt->execute([$fullName, $info['icon'], $existingId]);
                } else {
                    $stmt = $db->prepare("UPDATE $catTable SET name = ?, is_active = 1 WHERE id = ?");
                    $stmt->execute([$fullName, $existingId]);
                }
                $catMap[$code] = $existingId;
            } else {
                // Insert
                if ($hasIcon) {
                    $stmt = $db->prepare("INSERT INTO $catTable (name, cny_code, icon, is_active) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$fullName, $code, $info['icon']]);
                } else {
                    $stmt = $db->prepare("INSERT INTO $catTable (name, cny_code, is_active) VALUES (?, ?, 1)");
                    $stmt->execute([$fullName, $code]);
                }
                $catMap[$code] = $db->lastInsertId();
            }
        } catch (Exception $e) {
            echo "<p class='error'>Error creating $code: " . $e->getMessage() . "</p>";
        }
    }
    echo "<p class='success'>✓ สร้าง/อัพเดท " . count($catMap) . " categories</p>";
    
    // Step 2b: Assign categories to products
    echo "<h3>📦 Assign Categories ให้สินค้า...</h3>";
    
    $assigned = 0;
    $notFound = 0;
    $errors = 0;
    $batchSize = 100;
    $batch = [];
    
    // Build product map: id => category_code
    $productCatMap = [];
    foreach ($products as $p) {
        $id = $p['id'] ?? null;
        $sku = $p['sku'] ?? null;
        $cat = $p['category'] ?? '';
        
        if ($id && !empty($cat)) {
            // Extract main code (first 3 chars)
            $mainCode = strtoupper(substr($cat, 0, 3));
            if (isset($catMap[$mainCode])) {
                $productCatMap[$id] = $catMap[$mainCode];
            }
        }
    }
    
    echo "<p>พบ " . count($productCatMap) . " สินค้าที่มี category จาก CNY</p>";
    
    // Batch update
    $updated = 0;
    foreach ($productCatMap as $productId => $categoryId) {
        try {
            $stmt = $db->prepare("UPDATE business_items SET category_id = ? WHERE id = ?");
            $stmt->execute([$categoryId, $productId]);
            if ($stmt->rowCount() > 0) {
                $updated++;
            }
        } catch (Exception $e) {
            $errors++;
        }
        
        // Progress
        if ($updated % 500 == 0 && $updated > 0) {
            echo "<p class='info'>... อัพเดทแล้ว $updated รายการ</p>";
            flush();
        }
    }
    
    echo "<p class='success'>✓ Assign สำเร็จ: <strong>$updated</strong> รายการ</p>";
    if ($errors > 0) {
        echo "<p class='warning'>⚠️ Errors: $errors</p>";
    }
    
    // Check remaining
    $stmt = $db->query("SELECT COUNT(*) FROM business_items WHERE category_id IS NULL OR category_id = 0");
    $remaining = $stmt->fetchColumn();
    if ($remaining > 0) {
        echo "<p class='warning'>⚠️ ยังเหลือสินค้าที่ไม่มี category: $remaining รายการ</p>";
    }
    
    echo "</div>";
}

// ========== Current Status ==========
echo "<div class='card'>";
echo "<h2>📊 สถานะปัจจุบัน</h2>";

// Categories
$stmt = $db->query("SELECT COUNT(*) FROM $catTable");
$catCount = $stmt->fetchColumn();

// Products
$stmt = $db->query("SELECT COUNT(*) FROM business_items");
$totalProducts = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM business_items WHERE category_id IS NOT NULL AND category_id > 0");
$withCategory = $stmt->fetchColumn();

$noCategory = $totalProducts - $withCategory;

echo "<table>";
echo "<tr><td>Categories ในระบบ</td><td><strong>$catCount</strong></td></tr>";
echo "<tr><td>สินค้าทั้งหมด</td><td><strong>$totalProducts</strong></td></tr>";
echo "<tr><td class='success'>มี category แล้ว</td><td class='success'><strong>$withCategory</strong></td></tr>";
echo "<tr><td class='" . ($noCategory > 0 ? 'warning' : 'success') . "'>ไม่มี category</td><td class='" . ($noCategory > 0 ? 'warning' : 'success') . "'><strong>$noCategory</strong></td></tr>";
echo "</table>";
echo "</div>";

// ========== Actions ==========
echo "<div class='card'>";
echo "<h2>🚀 Actions</h2>";

echo "<form method='POST' style='margin-bottom:15px;'>";
echo "<p><strong>Step 1:</strong> ดึงข้อมูลจาก CNY API เพื่อดู categories ที่มี</p>";
echo "<button type='submit' name='fetch_cny' class='btn btn-blue'>📥 Fetch from CNY API</button>";
echo "</form>";

echo "<form method='POST'>";
echo "<p><strong>Step 2:</strong> Sync ทั้งหมด - สร้าง 22 categories + assign ให้สินค้าจาก CNY data</p>";
echo "<button type='submit' name='sync_all' class='btn'>🔄 Sync All (Categories + Products)</button>";
echo "</form>";

echo "<hr style='margin:20px 0;'>";
echo "<p><a href='setup_categories.php' class='btn btn-orange'>⚙️ Setup Categories (Manual)</a></p>";
echo "</div>";

// ========== Categories in DB ==========
echo "<div class='card'>";
echo "<h2>📁 Categories ในระบบ</h2>";

$stmt = $db->query("SELECT c.*, (SELECT COUNT(*) FROM business_items WHERE category_id = c.id) as product_count FROM $catTable c ORDER BY c.id");
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($cats) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Icon</th><th>Code</th><th>Name</th><th>Products</th></tr>";
    foreach ($cats as $c) {
        echo "<tr>";
        echo "<td>{$c['id']}</td>";
        echo "<td>" . ($c['icon'] ?? '-') . "</td>";
        echo "<td><strong>" . ($c['cny_code'] ?? '-') . "</strong></td>";
        echo "<td>{$c['name']}</td>";
        echo "<td>{$c['product_count']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>ยังไม่มี categories - กรุณา Sync</p>";
}
echo "</div>";

echo "<p style='text-align:center;color:#94A3B8;margin-top:20px;'>Generated at " . date('Y-m-d H:i:s') . "</p>";
echo "</body></html>";
