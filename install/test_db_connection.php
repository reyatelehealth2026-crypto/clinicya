<?php
/**
 * Test Database Connection
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 10); // Timeout 10 seconds

echo "<h2>Test Database Connection</h2>";

// Database credentials
$host = 'localhost';
$dbname = 'zrismpsz_cny';
$user = 'zrismpsz_cny';
$pass = 'zrismpsz_cny'; // ⚠️ แก้รหัสผ่านให้ถูกต้อง

echo "<p>Attempting to connect...</p>";
echo "<ul>";
echo "<li>Host: {$host}</li>";
echo "<li>Database: {$dbname}</li>";
echo "<li>User: {$user}</li>";
echo "<li>Password: " . str_repeat('*', strlen($pass)) . "</li>";
echo "</ul>";

try {
    $start = microtime(true);
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    
    echo "<p style='color: green;'>✅ Connected successfully in {$elapsed}ms!</p>";
    
    // Test query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM line_accounts");
    $result = $stmt->fetch();
    
    echo "<p>✅ Found {$result['count']} LINE accounts</p>";
    
    echo "<hr>";
    echo "<p><a href='fix_webhook_url.php'>Go to Fix Webhook URL</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Connection failed!</p>";
    echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
    
    if (strpos($e->getMessage(), 'Access denied') !== false) {
        echo "<p>⚠️ <strong>รหัสผ่านผิด!</strong> ให้แก้ไขใน config/config.php</p>";
    } elseif (strpos($e->getMessage(), 'Unknown database') !== false) {
        echo "<p>⚠️ <strong>ไม่พบฐานข้อมูล!</strong> ให้สร้างใน cPanel → MySQL Databases</p>";
    } elseif (strpos($e->getMessage(), 'timed out') !== false) {
        echo "<p>⚠️ <strong>Connection timeout!</strong> Database server ไม่ตอบสนอง</p>";
    }
}
