<?php
/**
 * Run Inbox Chat Upgrade Migration
 * สร้างตารางสำหรับระบบ Inbox Chat ที่อัพเกรด
 * - Quick Reply Templates
 * - Conversation Assignments
 * - Customer Notes
 * - Message Analytics
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>💬 Inbox Chat Upgrade Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Define new tables to check
    $inboxTables = [
        'quick_reply_templates',
        'conversation_assignments',
        'customer_notes',
        'message_analytics'
    ];
    
    // Check if tables already exist
    echo "📋 Checking existing Inbox Chat tables:\n";
    foreach ($inboxTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "⚠️ Table '$table' already exists\n";
        } else {
            echo "➡️ Table '$table' will be created\n";
        }
    }
    echo "\n";
    
    $success = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "🔄 Running Inbox Chat migration...\n\n";
    
    // =====================================================
    // Part 1: Create new tables
    // =====================================================
    $sqlFile = __DIR__ . '/../database/migration_inbox_chat.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Extract CREATE TABLE statements
    preg_match_all('/CREATE TABLE IF NOT EXISTS[^;]+;/s', $sql, $matches);
    
    echo "📝 Creating Inbox Chat tables:\n";
    foreach ($matches[0] as $createStmt) {
        try {
            $db->exec($createStmt);
            // Extract table name for display
            preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $createStmt, $tableMatch);
            $tableName = $tableMatch[1] ?? 'unknown';
            echo "✅ Created table: $tableName\n";
            $success++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $createStmt, $tableMatch);
                $tableName = $tableMatch[1] ?? 'unknown';
                echo "⚠️ Skipped (already exists): $tableName\n";
                $skipped++;
            } else {
                echo "❌ Error: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
    
    echo "\n";
    
    // =====================================================
    // Part 2: Add indexes to existing tables
    // =====================================================
    echo "📝 Adding indexes to existing tables:\n";
    
    // Define indexes to add
    $indexes = [
        [
            'table' => 'messages',
            'name' => 'idx_user_direction',
            'columns' => 'user_id, direction'
        ],
        [
            'table' => 'messages',
            'name' => 'idx_account_created',
            'columns' => 'line_account_id, created_at DESC'
        ],
        [
            'table' => 'messages',
            'name' => 'idx_is_read',
            'columns' => 'is_read, direction'
        ],
        [
            'table' => 'users',
            'name' => 'idx_account_last_msg',
            'columns' => 'line_account_id, last_message_at DESC'
        ]
    ];
    
    foreach ($indexes as $index) {
        try {
            // Check if index already exists
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM information_schema.statistics 
                WHERE table_schema = DATABASE() 
                AND table_name = ? 
                AND index_name = ?
            ");
            $stmt->execute([$index['table'], $index['name']]);
            $exists = $stmt->fetchColumn() > 0;
            
            if ($exists) {
                echo "⚠️ Index '{$index['name']}' on '{$index['table']}' already exists\n";
                $skipped++;
            } else {
                // Check if table exists first
                $tableCheck = $db->query("SHOW TABLES LIKE '{$index['table']}'");
                if ($tableCheck->rowCount() == 0) {
                    echo "⚠️ Table '{$index['table']}' does not exist, skipping index\n";
                    $skipped++;
                    continue;
                }
                
                $sql = "ALTER TABLE `{$index['table']}` ADD INDEX `{$index['name']}` ({$index['columns']})";
                $db->exec($sql);
                echo "✅ Added index '{$index['name']}' on '{$index['table']}'\n";
                $success++;
            }
        } catch (PDOException $e) {
            echo "❌ Error adding index '{$index['name']}': " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "✅ Success: $success operations\n";
    if ($skipped > 0) {
        echo "⚠️ Skipped: $skipped operations\n";
    }
    if ($errors > 0) {
        echo "❌ Errors: $errors operations\n";
    }
    echo "========================================\n";
    
    // Verify tables
    echo "\n📋 Verifying Inbox Chat tables:\n";
    foreach ($inboxTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $countStmt = $db->query("SELECT COUNT(*) FROM `$table`");
            $count = $countStmt->fetchColumn();
            echo "✅ Table '$table' exists ($count rows)\n";
        } else {
            echo "❌ Table '$table' NOT found\n";
        }
    }
    
    // Show quick_reply_templates structure
    echo "\n📋 Quick Reply Templates structure:\n";
    $stmt = $db->query("DESCRIBE quick_reply_templates");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   - {$row['Field']}: {$row['Type']}\n";
    }
    
    // Show conversation_assignments status enum values
    echo "\n📋 Assignment Status values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM conversation_assignments LIKE 'status'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }
    
    // Verify indexes on messages table
    echo "\n📋 Indexes on messages table:\n";
    try {
        $stmt = $db->query("SHOW INDEX FROM messages WHERE Key_name LIKE 'idx_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "   - {$row['Key_name']} ({$row['Column_name']})\n";
        }
    } catch (PDOException $e) {
        echo "   ⚠️ Could not check indexes: " . $e->getMessage() . "\n";
    }
    
    // Verify indexes on users table
    echo "\n📋 Indexes on users table:\n";
    try {
        $stmt = $db->query("SHOW INDEX FROM users WHERE Key_name LIKE 'idx_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "   - {$row['Key_name']} ({$row['Column_name']})\n";
        }
    } catch (PDOException $e) {
        echo "   ⚠️ Could not check indexes: " . $e->getMessage() . "\n";
    }
    
    echo "\n🎉 Inbox Chat Migration completed!\n";
    echo "\n<a href='../inbox.php'>👉 Go to Inbox</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
