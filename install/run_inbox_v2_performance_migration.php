<?php
/**
 * Run Inbox v2 Performance Upgrade Migration
 * เพิ่ม performance indexes สำหรับ AJAX conversation switching, cursor-based pagination,
 * และ efficient polling
 * - Covering indexes for conversation list
 * - Cursor-based pagination indexes
 * - Polling query indexes
 * - Performance metrics table
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>⚡ Inbox v2 Performance Upgrade Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    $success = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "🔄 Running Inbox v2 Performance migration...\n\n";
    
    // =====================================================
    // Part 1: Add columns to users table
    // =====================================================
    echo "📝 Adding columns to users table:\n";
    
    $columns = [
        [
            'name' => 'last_message_at',
            'definition' => 'DATETIME NULL',
            'after' => 'last_interaction'
        ],
        [
            'name' => 'unread_count',
            'definition' => 'INT DEFAULT 0',
            'after' => 'last_message_at'
        ]
    ];
    
    foreach ($columns as $column) {
        try {
            // Check if column exists
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM information_schema.columns 
                WHERE table_schema = DATABASE() 
                AND table_name = 'users' 
                AND column_name = ?
            ");
            $stmt->execute([$column['name']]);
            $exists = $stmt->fetchColumn() > 0;
            
            if ($exists) {
                echo "⚠️ Column '{$column['name']}' already exists\n";
                $skipped++;
            } else {
                $sql = "ALTER TABLE users ADD COLUMN {$column['name']} {$column['definition']} AFTER {$column['after']}";
                $db->exec($sql);
                echo "✅ Added column '{$column['name']}'\n";
                $success++;
            }
        } catch (PDOException $e) {
            echo "❌ Error adding column '{$column['name']}': " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n";
    
    // =====================================================
    // Part 2: Add performance indexes
    // =====================================================
    echo "📝 Adding performance indexes:\n";
    
    // Define indexes to add
    $indexes = [
        [
            'table' => 'users',
            'name' => 'idx_account_last_msg_cover',
            'columns' => 'line_account_id, last_message_at DESC, id, display_name(100), unread_count',
            'description' => 'Covering index for conversation list'
        ],
        [
            'table' => 'messages',
            'name' => 'idx_user_id_cursor',
            'columns' => 'user_id, id DESC',
            'description' => 'Cursor-based pagination index'
        ],
        [
            'table' => 'messages',
            'name' => 'idx_account_created_direction',
            'columns' => 'line_account_id, created_at DESC, direction',
            'description' => 'Polling query index for delta updates'
        ],
        [
            'table' => 'messages',
            'name' => 'idx_user_unread',
            'columns' => 'user_id, is_read, direction',
            'description' => 'Unread count index'
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
                echo "✅ Added index '{$index['name']}' - {$index['description']}\n";
                $success++;
            }
        } catch (PDOException $e) {
            echo "❌ Error adding index '{$index['name']}': " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n";
    
    // =====================================================
    // Part 3: Create performance_metrics table
    // =====================================================
    echo "📝 Creating performance_metrics table:\n";
    
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'performance_metrics'");
        if ($stmt->rowCount() > 0) {
            echo "⚠️ Table 'performance_metrics' already exists\n";
            $skipped++;
        } else {
            $sql = "
                CREATE TABLE performance_metrics (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT NULL COMMENT 'LINE account for multi-tenant tracking',
                    metric_type ENUM(
                        'page_load', 
                        'conversation_switch', 
                        'message_render', 
                        'api_call',
                        'scroll_performance',
                        'cache_hit',
                        'cache_miss'
                    ) NOT NULL,
                    duration_ms INT NOT NULL COMMENT 'Duration in milliseconds',
                    operation_details JSON NULL COMMENT 'Additional context about the operation',
                    user_agent VARCHAR(255) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_type_created (metric_type, created_at),
                    INDEX idx_account_type (line_account_id, metric_type),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";
            $db->exec($sql);
            echo "✅ Created table 'performance_metrics'\n";
            $success++;
        }
    } catch (PDOException $e) {
        echo "❌ Error creating performance_metrics table: " . $e->getMessage() . "\n";
        $errors++;
    }
    
    echo "\n";
    
    // =====================================================
    // Part 4: Initialize data for existing users
    // =====================================================
    echo "📝 Initializing data for existing users:\n";
    
    try {
        // Check if last_message_at column exists and has NULL values
        $stmt = $db->query("SELECT COUNT(*) FROM users WHERE last_message_at IS NULL");
        $nullCount = $stmt->fetchColumn();
        
        if ($nullCount > 0) {
            echo "   Found $nullCount users with NULL last_message_at\n";
            
            // Update last_message_at from most recent message
            $sql = "
                UPDATE users u
                LEFT JOIN (
                    SELECT user_id, MAX(created_at) as last_msg
                    FROM messages
                    GROUP BY user_id
                ) m ON u.id = m.user_id
                SET u.last_message_at = m.last_msg
                WHERE u.last_message_at IS NULL AND m.last_msg IS NOT NULL
            ";
            $db->exec($sql);
            $updated = $db->query("SELECT ROW_COUNT()")->fetchColumn();
            echo "✅ Updated last_message_at for $updated users\n";
            $success++;
        } else {
            echo "⚠️ All users already have last_message_at set\n";
            $skipped++;
        }
    } catch (PDOException $e) {
        echo "❌ Error initializing last_message_at: " . $e->getMessage() . "\n";
        $errors++;
    }
    
    try {
        // Update unread_count from messages table
        $sql = "
            UPDATE users u
            LEFT JOIN (
                SELECT user_id, COUNT(*) as unread
                FROM messages
                WHERE direction = 'incoming' AND is_read = 0
                GROUP BY user_id
            ) m ON u.id = m.user_id
            SET u.unread_count = COALESCE(m.unread, 0)
        ";
        $db->exec($sql);
        $updated = $db->query("SELECT ROW_COUNT()")->fetchColumn();
        echo "✅ Updated unread_count for $updated users\n";
        $success++;
    } catch (PDOException $e) {
        echo "❌ Error initializing unread_count: " . $e->getMessage() . "\n";
        $errors++;
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
    
    // =====================================================
    // Verification
    // =====================================================
    echo "\n📋 Verifying migration:\n";
    
    // Check users table columns
    echo "\n📋 Users table columns:\n";
    $stmt = $db->query("SHOW COLUMNS FROM users WHERE Field IN ('last_message_at', 'unread_count')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   ✅ {$row['Field']}: {$row['Type']}\n";
    }
    
    // Check users table indexes
    echo "\n📋 Users table performance indexes:\n";
    $stmt = $db->query("SHOW INDEX FROM users WHERE Key_name LIKE 'idx_account_last_msg%'");
    $indexFound = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   ✅ {$row['Key_name']} ({$row['Column_name']})\n";
        $indexFound = true;
    }
    if (!$indexFound) {
        echo "   ⚠️ No performance indexes found\n";
    }
    
    // Check messages table indexes
    echo "\n📋 Messages table performance indexes:\n";
    $stmt = $db->query("SHOW INDEX FROM messages WHERE Key_name IN ('idx_user_id_cursor', 'idx_account_created_direction', 'idx_user_unread')");
    $indexCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   ✅ {$row['Key_name']} ({$row['Column_name']})\n";
        $indexCount++;
    }
    if ($indexCount == 0) {
        echo "   ⚠️ No performance indexes found\n";
    }
    
    // Check performance_metrics table
    echo "\n📋 Performance metrics table:\n";
    $stmt = $db->query("SHOW TABLES LIKE 'performance_metrics'");
    if ($stmt->rowCount() > 0) {
        $countStmt = $db->query("SELECT COUNT(*) FROM performance_metrics");
        $count = $countStmt->fetchColumn();
        echo "   ✅ Table exists ($count rows)\n";
        
        // Show metric types
        $stmt = $db->query("SHOW COLUMNS FROM performance_metrics LIKE 'metric_type'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo "   📊 Available metric types:\n";
            preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
            if (isset($matches[1])) {
                $values = explode("','", $matches[1]);
                foreach ($values as $val) {
                    echo "      - $val\n";
                }
            }
        }
    } else {
        echo "   ❌ Table NOT found\n";
    }
    
    // Show sample data
    echo "\n📊 Sample user data:\n";
    $stmt = $db->query("
        SELECT id, display_name, last_message_at, unread_count 
        FROM users 
        WHERE last_message_at IS NOT NULL 
        ORDER BY last_message_at DESC 
        LIMIT 5
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "   - User #{$row['id']}: {$row['display_name']} | Last msg: {$row['last_message_at']} | Unread: {$row['unread_count']}\n";
    }
    
    echo "\n🎉 Inbox v2 Performance Migration completed!\n";
    echo "\n📝 Next steps:\n";
    echo "   1. Test conversation list loading performance\n";
    echo "   2. Test cursor-based pagination for messages\n";
    echo "   3. Monitor performance metrics in the dashboard\n";
    echo "   4. Verify AJAX conversation switching works smoothly\n";
    echo "\n<a href='../inbox-v2.php'>👉 Go to Inbox v2</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>
