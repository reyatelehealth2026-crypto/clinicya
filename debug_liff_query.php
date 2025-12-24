<?php
/**
 * Debug LIFF Query - ตรวจสอบว่า query ดึง LIFF ID ได้ถูกต้องหรือไม่
 */
require_once 'config/config.php';
require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');

$db = Database::getInstance()->getConnection();
$lineAccountId = $_GET['account'] ?? 1;

echo "<h1>Debug LIFF Query</h1>";
echo "<p>Account ID requested: <strong>$lineAccountId</strong></p>";

// Test 1: Simple query
echo "<h2>1. Simple Query</h2>";
try {
    $stmt = $db->prepare("SELECT id, name, liff_id FROM line_accounts WHERE id = ?");
    $stmt->execute([$lineAccountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($row, true) . "</pre>";
    echo "<p>LIFF ID value: <code>" . var_export($row['liff_id'] ?? null, true) . "</code></p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test 2: Same query as liff-app.php
echo "<h2>2. Same Query as liff-app.php</h2>";
try {
    $stmt = $db->prepare("SELECT la.*, ss.shop_name, ss.logo_url 
        FROM line_accounts la 
        LEFT JOIN shop_settings ss ON la.id = ss.line_account_id
        WHERE la.id = ? OR la.is_default = 1 
        ORDER BY (la.id = ?) DESC, la.is_default DESC LIMIT 1");
    $stmt->execute([$lineAccountId, $lineAccountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<pre>" . print_r($account, true) . "</pre>";
    
    if ($account) {
        $liffId = $account['liff_id'] ?? '';
        echo "<p>Extracted LIFF ID: <code>" . var_export($liffId, true) . "</code></p>";
        echo "<p>Is empty? " . (empty($liffId) ? 'YES' : 'NO') . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test 3: Check column type
echo "<h2>3. Column Info</h2>";
try {
    $stmt = $db->query("SHOW COLUMNS FROM line_accounts WHERE Field = 'liff_id'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($col, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test 4: Raw value check
echo "<h2>4. Raw Value Check</h2>";
try {
    $stmt = $db->query("SELECT id, name, liff_id, HEX(liff_id) as hex_value, LENGTH(liff_id) as len FROM line_accounts");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>LIFF ID</th><th>HEX</th><th>Length</th></tr>";
    foreach ($rows as $r) {
        echo "<tr>";
        echo "<td>{$r['id']}</td>";
        echo "<td>{$r['name']}</td>";
        echo "<td><code>{$r['liff_id']}</code></td>";
        echo "<td><code>{$r['hex_value']}</code></td>";
        echo "<td>{$r['len']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test 5: What liff-app.php would output
echo "<h2>5. What JavaScript CONFIG would be</h2>";
$liffId = '';
try {
    $stmt = $db->prepare("SELECT la.*, ss.shop_name, ss.logo_url 
        FROM line_accounts la 
        LEFT JOIN shop_settings ss ON la.id = ss.line_account_id
        WHERE la.id = ? OR la.is_default = 1 
        ORDER BY (la.id = ?) DESC, la.is_default DESC LIMIT 1");
    $stmt->execute([$lineAccountId, $lineAccountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($account) {
        $liffId = $account['liff_id'] ?? '';
    }
} catch (Exception $e) {}

echo "<pre>";
echo "const CONFIG = {\n";
echo "    LIFF_ID: '" . $liffId . "',\n";
echo "    ACCOUNT_ID: " . (int)$lineAccountId . "\n";
echo "};\n";
echo "</pre>";

echo "<p>LIFF_ID is " . (empty($liffId) ? "<span style='color:red'>EMPTY</span>" : "<span style='color:green'>SET: $liffId</span>") . "</p>";

echo "<hr>";
echo "<p><a href='liff-app.php?account=$lineAccountId'>Test liff-app.php</a></p>";
