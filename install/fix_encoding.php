<?php
/**
 * Fix Thai Encoding in Database
 * แก้ปัญหาภาษาไทยแสดงเป็น ????
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';
require_once 'config/database.php';

echo "<h1>🔧 Fix Thai Encoding</h1>";
echo "<meta charset='UTF-8'>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Set connection charset
    $db->exec("SET NAMES utf8mb4");
    $db->exec("SET CHARACTER SET utf8mb4");
    
    echo "<p style='color:green'>✅ Database connected</p>";
    
    // 1. Check current charset
    echo "<h2>1. Current Database Charset:</h2>";
    $stmt = $db->query("SELECT @@character_set_database, @@collation_database");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Database charset: {$row['@@character_set_database']}</p>";
    echo "<p>Database collation: {$row['@@collation_database']}</p>";
    
    // 2. Convert tables to utf8mb4
    echo "<h2>2. Converting Tables to UTF8MB4:</h2>";
    
    $tables = ['line_accounts', 'users', 'messages', 'admin_users', 'business_items', 'orders'];
    
    foreach ($tables as $table) {
        try {
            $db->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "<p>✅ $table - converted</p>";
        } catch (Exception $e) {
            echo "<p>⚠️ $table - " . $e->getMessage() . "</p>";
        }
    }
    
    // 3. Check line_accounts data
    echo "<h2>3. Current LINE Accounts:</h2>";
    $stmt = $db->query("SELECT id, name, basic_id FROM line_accounts");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>Basic ID</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['name']}</td>";
        echo "<td>{$row['basic_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 4. Update test - ถ้าชื่อเป็น ???? ให้อัพเดทใหม่
    echo "<h2>4. Fix Account Name:</h2>";
    echo "<form method='post'>";
    echo "<p>Account ID: <input type='number' name='account_id' value='3'></p>";
    echo "<p>New Name: <input type='text' name='new_name' value='อร่อยซอยสาม'></p>";
    echo "<button type='submit' name='update'>Update Name</button>";
    echo "</form>";
    
    if (isset($_POST['update'])) {
        $accountId = $_POST['account_id'];
        $newName = $_POST['new_name'];
        $stmt = $db->prepare("UPDATE line_accounts SET name = ? WHERE id = ?");
        $stmt->execute([$newName, $accountId]);
        echo "<p style='color:green'>✅ Updated account $accountId to: $newName</p>";
        echo "<script>location.reload();</script>";
    }
    
    echo "<br><br>";
    echo "<a href='index.php' style='padding:10px 20px; background:#4CAF50; color:white; text-decoration:none; border-radius:5px;'>🏠 Go to Dashboard</a>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}
