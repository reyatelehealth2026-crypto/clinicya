<?php
/**
 * Add liff_id column to line_accounts table
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔧 Add LIFF ID Column</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if column exists
    $stmt = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'liff_id'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Column 'liff_id' already exists in line_accounts\n";
    } else {
        // Add column
        $db->exec("ALTER TABLE line_accounts ADD COLUMN liff_id VARCHAR(50) DEFAULT NULL AFTER channel_access_token");
        echo "✅ Added column 'liff_id' to line_accounts\n";
    }
    
    // Check unified_liff_id
    $stmt = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'unified_liff_id'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Column 'unified_liff_id' already exists\n";
    } else {
        $db->exec("ALTER TABLE line_accounts ADD COLUMN unified_liff_id VARCHAR(50) DEFAULT NULL AFTER liff_id");
        echo "✅ Added column 'unified_liff_id' to line_accounts\n";
    }
    
    // Show current structure
    echo "\n📋 Current line_accounts columns:\n";
    $stmt = $db->query("SHOW COLUMNS FROM line_accounts");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$row['Field']} ({$row['Type']})\n";
    }
    
    echo "\n🎉 Done!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
