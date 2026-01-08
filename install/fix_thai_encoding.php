<?php
/**
 * Fix Thai Encoding for Activity Logs
 * แก้ไขปัญหาภาษาไทยแสดงเป็น ??? ในตาราง logs
 * 
 * วิธีใช้: เปิดผ่าน browser หรือรัน php install/fix_thai_encoding.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Ensure connection uses UTF-8
$db->exec("SET NAMES utf8mb4");
$db->exec("SET CHARACTER SET utf8mb4");
$db->exec("SET character_set_connection=utf8mb4");

echo "=== Fix Thai Encoding for Activity Logs ===\n\n";

// Tables to fix
$tables = [
    'activity_logs',
    'admin_activity_log',
    'dev_logs',
    'system_logs',
    'admin_users',
    'users'
];

foreach ($tables as $table) {
    echo "Checking table: {$table}...\n";
    
    try {
        // Check if table exists
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
        
        // Convert table to utf8mb4
        echo "  Converting to utf8mb4_unicode_ci...\n";
        $db->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Also set default charset
        $db->exec("ALTER TABLE {$table} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        echo "  ✓ Table {$table} converted successfully\n\n";
        
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            echo "  - Table doesn't exist, skipping\n\n";
        } else {
            echo "  ✗ Error: " . $e->getMessage() . "\n\n";
        }
    }
}

// Also fix specific text columns that might have wrong encoding
$textColumns = [
    'activity_logs' => ['description', 'user_name', 'admin_name'],
    'admin_activity_log' => ['details'],
    'dev_logs' => ['message', 'source', 'data'],
    'admin_users' => ['display_name', 'username'],
];

foreach ($textColumns as $table => $columns) {
    try {
        $db->query("SELECT 1 FROM {$table} LIMIT 1");
        
        foreach ($columns as $column) {
            try {
                // Get column type
                $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
                $colInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($colInfo) {
                    $type = $colInfo['Type'];
                    echo "Fixing column {$table}.{$column} ({$type})...\n";
                    
                    // Modify column to ensure utf8mb4
                    $db->exec("ALTER TABLE {$table} MODIFY {$column} {$type} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    echo "  ✓ Fixed\n";
                }
            } catch (PDOException $e) {
                echo "  - Column {$column} error: " . $e->getMessage() . "\n";
            }
        }
    } catch (PDOException $e) {
        // Table doesn't exist
    }
}

echo "\n=== Testing Thai Insert ===\n";

// Test insert Thai text
try {
    $testText = 'ทดสอบภาษาไทย - Test Thai ' . date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("INSERT INTO activity_logs (log_type, action, description, admin_name) VALUES (?, ?, ?, ?)");
    $stmt->execute(['system', 'test', $testText, 'ทดสอบ Admin']);
    
    $lastId = $db->lastInsertId();
    
    // Read back
    $stmt = $db->prepare("SELECT description, admin_name FROM activity_logs WHERE id = ?");
    $stmt->execute([$lastId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Inserted: {$testText}\n";
    echo "Read back: {$row['description']}\n";
    echo "Admin name: {$row['admin_name']}\n";
    
    if ($row['description'] === $testText) {
        echo "\n✓ Thai encoding is working correctly!\n";
    } else {
        echo "\n✗ Thai encoding still has issues\n";
    }
    
    // Clean up test record
    $db->exec("DELETE FROM activity_logs WHERE id = {$lastId}");
    
} catch (PDOException $e) {
    echo "Test error: " . $e->getMessage() . "\n";
}

echo "\n=== Done ===\n";
