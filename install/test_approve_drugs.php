<?php
/**
 * Test script for approve_drugs API
 * Tests cart addition and LINE notification
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance()->getConnection();

echo "<h2>Test Approve Drugs API</h2>";

// Test parameters
$userId = 28; // JX4
$sessionId = 20; // Latest session

// Get user info
$stmt = $db->prepare("SELECT display_name, line_account_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p>User: {$user['display_name']} (ID: {$userId}), LINE Account: {$user['line_account_id']}</p>";

// Get LIFF ID for this account
$stmt = $db->prepare("SELECT liff_id FROM line_accounts WHERE id = ?");
$stmt->execute([$user['line_account_id']]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p>LIFF ID: " . ($account['liff_id'] ?? 'NOT SET') . "</p>";

// Check cart before
$stmt = $db->prepare("SELECT COUNT(*) FROM cart WHERE user_id = ?");
$stmt->execute([$userId]);
$cartBefore = $stmt->fetchColumn();
echo "<p>Cart items before: {$cartBefore}</p>";

// Test data
$testDrugs = [
    [
        'id' => 74, // ซีมอล 500มล.
        'name' => 'ซีมอล 500มล.',
        'genericName' => 'Paracetamol',
        'price' => 35,
        'quantity' => 2,
        'dosage' => '1-2',
        'unit' => 'เม็ด',
        'timing' => 'หลังอาหาร',
        'indication' => 'แก้ปวด ลดไข้',
        'isNonDrug' => false
    ]
];

echo "<h3>Test Data:</h3>";
echo "<pre>" . json_encode($testDrugs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";

// Simulate API call
echo "<h3>Simulating approve_drugs...</h3>";

$lineAccountId = $user['line_account_id'];

// Add to cart
foreach ($testDrugs as $drug) {
    $productId = (int)($drug['id'] ?? 0);
    $quantity = (int)($drug['quantity'] ?? 1);
    
    if ($productId <= 0) {
        echo "<p style='color:red'>Invalid product ID: {$productId}</p>";
        continue;
    }
    
    // Check if item already in cart
    $stmt = $db->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$userId, $productId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $stmt = $db->prepare("UPDATE cart SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$quantity, $existing['id']]);
        echo "<p style='color:green'>Updated cart item {$existing['id']}, added {$quantity}</p>";
    } else {
        $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, quantity, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $productId, $quantity]);
        echo "<p style='color:green'>Inserted new cart item, ID: " . $db->lastInsertId() . "</p>";
    }
}

// Check cart after
$stmt = $db->prepare("SELECT c.*, bi.name FROM cart c LEFT JOIN business_items bi ON c.product_id = bi.id WHERE c.user_id = ?");
$stmt->execute([$userId]);
$cartAfter = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<h3>Cart after ({$userId}):</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Product ID</th><th>Name</th><th>Quantity</th></tr>";
foreach ($cartAfter as $item) {
    echo "<tr><td>{$item['id']}</td><td>{$item['product_id']}</td><td>{$item['name']}</td><td>{$item['quantity']}</td></tr>";
}
echo "</table>";

// Test LIFF URL generation
echo "<h3>Test LIFF URL:</h3>";
require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
$notifier = new \Modules\AIChat\Services\PharmacistNotifier($lineAccountId);

// Use reflection to call private method
$reflection = new ReflectionClass($notifier);
$method = $reflection->getMethod('getCheckoutUrl');
$method->setAccessible(true);
$liffUrl = $method->invoke($notifier);

echo "<p>Generated LIFF URL: <a href='{$liffUrl}' target='_blank'>{$liffUrl}</a></p>";

echo "<hr><p><a href='check_cart.php'>Check Cart</a> | <a href='check_latest_sessions.php'>Check Sessions</a></p>";
