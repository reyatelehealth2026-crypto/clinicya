<?php
require_once 'config/config.php';
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

echo "<h2>Debug Categories</h2>";
echo "<style>body{font-family:sans-serif;padding:20px;} table{border-collapse:collapse;} td,th{border:1px solid #ddd;padding:5px;}</style>";

// 1. Categories in product_categories
echo "<h3>1. หมวดหมู่ทั้งหมด (product_categories)</h3>";
$cats = $db->query("SELECT id, name, cny_code FROM product_categories ORDER BY id")->fetchAll();
echo "<table><tr><th>ID</th><th>Name</th><th>CNY Code</th></tr>";
foreach ($cats as $c) {
    echo "<tr><td>{$c['id']}</td><td>{$c['name']}</td><td>{$c['cny_code']}</td></tr>";
}
echo "</table>";

// 2. Products by category_id
echo "<h3>2. จำนวนสินค้าแต่ละ category_id</h3>";
$bycat = $db->query("SELECT category_id, COUNT(*) as cnt FROM business_items GROUP BY category_id ORDER BY category_id")->fetchAll();
echo "<table><tr><th>Category ID</th><th>Count</th><th>Category Name</th></tr>";
foreach ($bycat as $b) {
    $catName = '-';
    foreach ($cats as $c) {
        if ($c['id'] == $b['category_id']) { $catName = $c['name']; break; }
    }
    $color = $catName == '-' ? 'red' : 'black';
    echo "<tr style='color:$color'><td>{$b['category_id']}</td><td>{$b['cnt']}</td><td>$catName</td></tr>";
}
echo "</table>";

// 3. Products with invalid category_id
echo "<h3>3. สินค้าที่ category_id ไม่มีในตาราง categories</h3>";
$invalid = $db->query("SELECT p.id, p.name, p.category_id FROM business_items p 
    LEFT JOIN product_categories c ON p.category_id = c.id 
    WHERE c.id IS NULL AND p.category_id IS NOT NULL LIMIT 20")->fetchAll();
if (empty($invalid)) {
    echo "ไม่มี (ดี)";
} else {
    echo "<table><tr><th>Product ID</th><th>Name</th><th>Invalid Category ID</th></tr>";
    foreach ($invalid as $i) {
        echo "<tr><td>{$i['id']}</td><td>" . mb_substr($i['name'],0,30) . "</td><td style='color:red'>{$i['category_id']}</td></tr>";
    }
    echo "</table>";
    
    // Show unique invalid category_ids
    $uniqueInvalid = $db->query("SELECT DISTINCT category_id FROM business_items p 
        LEFT JOIN product_categories c ON p.category_id = c.id 
        WHERE c.id IS NULL AND p.category_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    echo "<br>Invalid category_ids: " . implode(', ', $uniqueInvalid);
}

echo "<br><br>Done at " . date('Y-m-d H:i:s');
