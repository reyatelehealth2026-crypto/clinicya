<?php
/**
 * Debug Shop Products - ตรวจสอบปัญหาสินค้าไม่แสดง
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>🔍 Debug Shop Products</h2>";
echo "<style>body{font-family:sans-serif;padding:20px;} table{border-collapse:collapse;margin:10px 0;} td,th{border:1px solid #ddd;padding:8px;}</style>";

// 1. Check which tables exist
echo "<h3>1. ตรวจสอบตารางที่มี</h3>";
$tables = ['products', 'business_items', 'product_categories', 'item_categories'];
foreach ($tables as $table) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM {$table}");
        $count = $stmt->fetchColumn();
        echo "✅ <b>{$table}</b>: {$count} rows<br>";
    } catch (Exception $e) {
        echo "❌ <b>{$table}</b>: ไม่มีตาราง<br>";
    }
}

// 2. Check UnifiedShop detection
echo "<h3>2. UnifiedShop Detection</h3>";
if (file_exists('classes/UnifiedShop.php')) {
    require_once 'classes/UnifiedShop.php';
    $shop = new UnifiedShop($db, null, 1);
    echo "Items Table: <b>" . ($shop->getItemsTable() ?? 'NULL') . "</b><br>";
    echo "Categories Table: <b>" . ($shop->getCategoriesTable() ?? 'NULL') . "</b><br>";
    echo "isReady: <b>" . ($shop->isReady() ? 'YES' : 'NO') . "</b><br>";
    echo "isV25: <b>" . ($shop->isV25() ? 'YES (business_items)' : 'NO (products)') . "</b><br>";
} else {
    echo "❌ UnifiedShop.php not found<br>";
}

// 3. Check products table structure
echo "<h3>3. โครงสร้างตาราง products</h3>";
try {
    $stmt = $db->query("SHOW COLUMNS FROM products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns: " . implode(', ', $columns) . "<br>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// 4. Check products count by is_active
echo "<h3>4. สถานะสินค้า</h3>";
try {
    $stmt = $db->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive
        FROM products");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<table>";
    echo "<tr><td>สินค้าทั้งหมด</td><td><b>{$stats['total']}</b></td></tr>";
    echo "<tr><td>เปิดขาย (is_active=1)</td><td style='color:green'><b>{$stats['active']}</b></td></tr>";
    echo "<tr><td>ปิดขาย (is_active=0)</td><td style='color:red'><b>{$stats['inactive']}</b></td></tr>";
    echo "</table>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// 5. Sample products
echo "<h3>5. ตัวอย่างสินค้า 10 รายการแรก</h3>";
try {
    $stmt = $db->query("SELECT id, name, price, stock, is_active, category_id FROM products ORDER BY id LIMIT 10");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table><tr><th>ID</th><th>Name</th><th>Price</th><th>Stock</th><th>Active</th><th>Category</th></tr>";
    foreach ($products as $p) {
        $activeColor = $p['is_active'] ? 'green' : 'red';
        echo "<tr>";
        echo "<td>{$p['id']}</td>";
        echo "<td>" . htmlspecialchars(mb_substr($p['name'], 0, 40)) . "</td>";
        echo "<td>" . number_format($p['price'], 2) . "</td>";
        echo "<td>{$p['stock']}</td>";
        echo "<td style='color:{$activeColor}'>" . ($p['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "<td>{$p['category_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// 6. Check if business_items exists and has data
echo "<h3>6. ตรวจสอบ business_items (ถ้ามี)</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM business_items");
    $count = $stmt->fetchColumn();
    echo "⚠️ <b>business_items มี {$count} rows</b> - UnifiedShop จะใช้ตารางนี้แทน products!<br>";
    echo "<span style='color:red'>นี่คือสาเหตุที่สินค้าไม่แสดง!</span><br>";
    echo "<br><b>วิธีแก้:</b><br>";
    echo "1. ลบตาราง business_items: <code>DROP TABLE business_items;</code><br>";
    echo "2. หรือ rename: <code>RENAME TABLE business_items TO business_items_backup;</code><br>";
} catch (Exception $e) {
    echo "✅ ไม่มีตาราง business_items (ดี)<br>";
}

echo "<br><hr><p>Debug completed at " . date('Y-m-d H:i:s') . "</p>";
