<?php
require_once 'config/config.php';
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

echo "<h2>Debug Shop</h2>";

// Check tables
$tables = ['products', 'business_items', 'product_categories', 'item_categories'];
foreach ($tables as $t) {
    try {
        $c = $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo "$t: $c rows<br>";
    } catch (Exception $e) {
        echo "$t: NOT EXISTS<br>";
    }
}

// Products stats
try {
    $r = $db->query("SELECT COUNT(*) as t, SUM(is_active) as a FROM business_items")->fetch();
    echo "<br>Business items total: {$r['t']}, active: {$r['a']}<br>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// UnifiedShop check
if (file_exists('classes/UnifiedShop.php')) {
    include_once 'classes/UnifiedShop.php';
    $shop = new UnifiedShop($db, null, 1);
    echo "<br>UnifiedShop uses: " . $shop->getItemsTable() . "<br>";
    echo "isV25: " . ($shop->isV25() ? 'YES' : 'NO') . "<br>";
}
