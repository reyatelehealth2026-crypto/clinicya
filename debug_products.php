<?php
/**
 * Debug Products - ตรวจสอบสินค้า
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;

echo "<h2>Debug Products</h2>";
echo "<p>current_bot_id: " . ($currentBotId ?? 'NULL') . "</p>";

// ดูสินค้าทั้งหมด
echo "<h3>1. สินค้าทั้งหมด (ไม่กรอง)</h3>";
try {
    $stmt = $db->query("SELECT id, name, line_account_id, is_active FROM business_items ORDER BY id DESC LIMIT 20");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>พบ " . count($products) . " สินค้า</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>line_account_id</th><th>is_active</th></tr>";
    foreach ($products as $p) {
        echo "<tr>";
        echo "<td>{$p['id']}</td>";
        echo "<td>" . htmlspecialchars($p['name']) . "</td>";
        echo "<td>" . ($p['line_account_id'] ?? 'NULL') . "</td>";
        echo "<td>" . ($p['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// ดูสินค้าที่กรองตาม bot
echo "<h3>2. สินค้าที่กรองตาม current_bot_id = {$currentBotId}</h3>";
try {
    $stmt = $db->prepare("SELECT id, name, line_account_id, is_active FROM business_items WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1");
    $stmt->execute([$currentBotId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>พบ " . count($products) . " สินค้า</p>";
    if (count($products) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Name</th><th>line_account_id</th></tr>";
        foreach ($products as $p) {
            echo "<tr>";
            echo "<td>{$p['id']}</td>";
            echo "<td>" . htmlspecialchars($p['name']) . "</td>";
            echo "<td>" . ($p['line_account_id'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// ดูโครงสร้างตาราง
echo "<h3>3. โครงสร้างตาราง products</h3>";
try {
    $stmt = $db->query("DESCRIBE products");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>
