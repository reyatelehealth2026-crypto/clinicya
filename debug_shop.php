<?php
/**
 * Debug Shop - ตรวจสอบระบบ Shop
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug Shop System</h2>";

// ตรวจสอบตาราง business_items
echo "<h3>1. ตาราง business_items</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM business_items");
    $count = $stmt->fetchColumn();
    echo "<p style='color:green'>✅ ตาราง business_items มี {$count} รายการ</p>";
    
    // แสดงสินค้า
    $stmt = $db->query("SELECT * FROM business_items LIMIT 5");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($products) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Name</th><th>Price</th><th>Active</th></tr>";
        foreach ($products as $p) {
            echo "<tr>";
            echo "<td>{$p['id']}</td>";
            echo "<td>" . htmlspecialchars($p['name']) . "</td>";
            echo "<td>" . number_format($p['price'], 2) . "</td>";
            echo "<td>" . ($p['is_active'] ? 'Yes' : 'No') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

// ตรวจสอบตาราง product_categories
echo "<h3>2. ตาราง product_categories</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM product_categories");
    $count = $stmt->fetchColumn();
    echo "<p style='color:green'>✅ ตาราง product_categories มี {$count} รายการ</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

// ตรวจสอบตาราง business_items
echo "<h3>3. ตาราง business_items</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM business_items");
    $count = $stmt->fetchColumn();
    echo "<p style='color:green'>✅ ตาราง business_items มี {$count} รายการ</p>";
} catch (Exception $e) {
    echo "<p style='color:orange'>⚠️ ตาราง business_items ไม่มี (ใช้ products แทน)</p>";
}

// ตรวจสอบตาราง orders
echo "<h3>4. ตาราง orders</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM orders");
    $count = $stmt->fetchColumn();
    echo "<p style='color:green'>✅ ตาราง orders มี {$count} รายการ</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<h3>5. Links</h3>";
echo "<ul>";
echo "<li><a href='shop/products.php'>shop/products.php</a></li>";
echo "<li><a href='shop/orders.php'>shop/orders.php</a></li>";
echo "<li><a href='shop/categories.php'>shop/categories.php</a></li>";
echo "<li><a href='broadcast-products.php'>broadcast-products.php</a></li>";
echo "</ul>";
?>
