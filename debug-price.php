<?php
// Debug price extraction from cny_products
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug CNY Products Price</h2>";

// Check if product_price column has data
$stmt = $db->query("SELECT COUNT(*) as total, 
                           SUM(CASE WHEN product_price IS NOT NULL AND product_price != '' THEN 1 ELSE 0 END) as with_price
                    FROM cny_products");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p><strong>Total products:</strong> {$stats['total']}</p>";
echo "<p><strong>Products with product_price:</strong> {$stats['with_price']}</p>";

echo "<hr>";

$stmt = $db->query("SELECT id, sku, product_price FROM cny_products WHERE product_price IS NOT NULL AND product_price != '' LIMIT 5");
$count = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $count++;
    echo "<hr>";
    echo "<p><strong>SKU:</strong> " . htmlspecialchars($row['sku']) . "</p>";
    echo "<p><strong>product_price type:</strong> " . gettype($row['product_price']) . "</p>";
    echo "<p><strong>product_price raw (first 500 chars):</strong></p>";
    echo "<pre>" . htmlspecialchars(substr($row['product_price'] ?? 'NULL', 0, 500)) . "</pre>";
    
    // Try to decode
    $decoded = json_decode($row['product_price'], true);
    
    // Handle double-encoded
    if (is_string($decoded)) {
        echo "<p><strong>Double-encoded! Decoding again...</strong></p>";
        $decoded = json_decode($decoded, true);
    }
    
    echo "<p><strong>json_decode result:</strong> " . (is_array($decoded) ? 'Array with ' . count($decoded) . ' items' : 'FAILED - ' . json_last_error_msg()) . "</p>";
    
    if (is_array($decoded) && !empty($decoded)) {
        echo "<p><strong>First price entry:</strong></p>";
        echo "<pre>" . print_r($decoded[0], true) . "</pre>";
        
        // Extract price
        $price = 0;
        foreach ($decoded as $p) {
            $group = $p['customer_group'] ?? '';
            if (strpos($group, 'GEN') !== false) {
                $price = floatval($p['price'] ?? 0);
                break;
            }
        }
        if ($price == 0 && isset($decoded[0]['price'])) {
            $price = floatval($decoded[0]['price']);
        }
        echo "<p><strong>Extracted price:</strong> <span style='color:green;font-size:20px;'>" . $price . "</span></p>";
    }
}

if ($count == 0) {
    echo "<p style='color:red;'><strong>ไม่พบข้อมูล product_price ในตาราง cny_products!</strong></p>";
    echo "<p>ต้อง sync ข้อมูลจาก CNY API ใหม่เพื่อให้ได้ product_price</p>";
    
    // Show sample without price
    echo "<h3>Sample products without price:</h3>";
    $stmt2 = $db->query("SELECT id, sku, name FROM cny_products LIMIT 5");
    while ($row2 = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        echo "<p>SKU: {$row2['sku']} - {$row2['name']}</p>";
    }
}
