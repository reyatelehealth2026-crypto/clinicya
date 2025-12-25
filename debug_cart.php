<?php
/**
 * Debug Cart Issues
 * ตรวจสอบปัญหาตะกร้าว่างเปล่า
 */
header('Content-Type: text/html; charset=utf-8');
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Test user
$testLineUserId = $_GET['line_user_id'] ?? 'U95415c96eab157bdb8ee550b3280be85';

echo "<h1>🛒 Debug Cart</h1>";
echo "<p><strong>Testing line_user_id:</strong> <code>{$testLineUserId}</code></p>";
echo "<p><strong>Length:</strong> " . strlen($testLineUserId) . " (should be 33)</p>";

// 1. Check if user exists
echo "<h2>1. ตรวจสอบ User</h2>";
$stmt = $db->prepare("SELECT id, line_account_id, line_user_id, display_name, created_at FROM users WHERE line_user_id = ?");
$stmt->execute([$testLineUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "<p style='color:green'>✅ พบ User</p>";
    echo "<ul>";
    echo "<li><strong>User ID:</strong> {$user['id']}</li>";
    echo "<li><strong>Line Account ID:</strong> {$user['line_account_id']}</li>";
    echo "<li><strong>Display Name:</strong> {$user['display_name']}</li>";
    echo "<li><strong>Created:</strong> {$user['created_at']}</li>";
    echo "</ul>";
    
    $userId = $user['id'];
} else {
    echo "<p style='color:red'>❌ ไม่พบ User ในระบบ</p>";
    
    // Check similar users
    echo "<h3>Users ที่คล้ายกัน:</h3>";
    $stmt = $db->prepare("SELECT id, line_user_id, display_name FROM users WHERE line_user_id LIKE ? LIMIT 10");
    $stmt->execute(['%' . substr($testLineUserId, 0, 10) . '%']);
    $similar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($similar) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>line_user_id</th><th>display_name</th></tr>";
        foreach ($similar as $s) {
            echo "<tr><td>{$s['id']}</td><td>{$s['line_user_id']}</td><td>{$s['display_name']}</td></tr>";
        }
        echo "</table>";
    }
    exit;
}

// 2. Check cart_items table
echo "<h2>2. ตรวจสอบ cart_items</h2>";
$stmt = $db->prepare("SELECT * FROM cart_items WHERE user_id = ?");
$stmt->execute([$userId]);
$cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p><strong>จำนวน items ในตะกร้า:</strong> " . count($cartItems) . "</p>";

if ($cartItems) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Product ID</th><th>Quantity</th><th>Created</th></tr>";
    foreach ($cartItems as $item) {
        echo "<tr>";
        echo "<td>{$item['id']}</td>";
        echo "<td>{$item['product_id']}</td>";
        echo "<td>{$item['quantity']}</td>";
        echo "<td>" . ($item['created_at'] ?? 'N/A') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:orange'>⚠️ ตะกร้าว่างเปล่า</p>";
}

// 3. Check products
echo "<h2>3. ตรวจสอบ Products ที่อยู่ในตะกร้า</h2>";
if ($cartItems) {
    $productIds = array_column($cartItems, 'product_id');
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    
    $stmt = $db->prepare("SELECT id, name, price, is_active, stock FROM business_items WHERE id IN ({$placeholders})");
    $stmt->execute($productIds);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>Price</th><th>Active</th><th>Stock</th></tr>";
    foreach ($products as $p) {
        $activeColor = $p['is_active'] ? 'green' : 'red';
        echo "<tr>";
        echo "<td>{$p['id']}</td>";
        echo "<td>{$p['name']}</td>";
        echo "<td>฿" . number_format($p['price'], 2) . "</td>";
        echo "<td style='color:{$activeColor}'>" . ($p['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "<td>{$p['stock']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check for missing products
    $foundIds = array_column($products, 'id');
    $missingIds = array_diff($productIds, $foundIds);
    if ($missingIds) {
        echo "<p style='color:red'>❌ Products ที่หายไป: " . implode(', ', $missingIds) . "</p>";
    }
    
    // Check for inactive products
    $inactiveProducts = array_filter($products, fn($p) => !$p['is_active']);
    if ($inactiveProducts) {
        echo "<p style='color:orange'>⚠️ Products ที่ถูกปิดใช้งาน: " . implode(', ', array_column($inactiveProducts, 'id')) . "</p>";
    }
}

// 4. Test API call
echo "<h2>4. ทดสอบ API Call</h2>";
$apiUrl = BASE_URL . "/api/checkout.php?action=cart&line_user_id=" . urlencode($testLineUserId);
echo "<p><strong>API URL:</strong> <a href='{$apiUrl}' target='_blank'>{$apiUrl}</a></p>";

// Make internal call
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> {$httpCode}</p>";
echo "<p><strong>Response:</strong></p>";
echo "<pre style='background:#f5f5f5;padding:10px;overflow:auto;max-height:300px'>" . htmlspecialchars($response) . "</pre>";

// 5. Check cart_items table structure
echo "<h2>5. โครงสร้างตาราง cart_items</h2>";
try {
    $stmt = $db->query("DESCRIBE cart_items");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 6. Recent cart activity
echo "<h2>6. กิจกรรมตะกร้าล่าสุด (ทุก user)</h2>";
try {
    $stmt = $db->query("
        SELECT c.*, u.line_user_id, u.display_name, p.name as product_name
        FROM cart_items c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN business_items p ON c.product_id = p.id
        ORDER BY c.id DESC
        LIMIT 20
    ");
    $recentCarts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($recentCarts) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Cart ID</th><th>User ID</th><th>LINE User</th><th>Product</th><th>Qty</th></tr>";
        foreach ($recentCarts as $rc) {
            $highlight = ($rc['user_id'] == $userId) ? "style='background:#ffffcc'" : "";
            echo "<tr {$highlight}>";
            echo "<td>{$rc['id']}</td>";
            echo "<td>{$rc['user_id']}</td>";
            echo "<td>" . substr($rc['line_user_id'] ?? '', 0, 15) . "...</td>";
            echo "<td>{$rc['product_name']}</td>";
            echo "<td>{$rc['quantity']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>ไม่มีข้อมูลในตาราง cart_items</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='debug_cart.php?line_user_id={$testLineUserId}'>🔄 Refresh</a></p>";
