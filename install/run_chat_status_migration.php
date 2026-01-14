<?php
/**
 * Run Chat Status Migration
 * เพิ่ม chat_status column และ history table
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔄 Running Chat Status Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if chat_status column exists
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'chat_status'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        // Add chat_status column
        try {
            $db->exec("ALTER TABLE users ADD COLUMN chat_status VARCHAR(50) DEFAULT NULL");
            echo "✅ Added chat_status column to users table\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "ℹ️ chat_status column already exists\n";
            } else {
                throw $e;
            }
        }
        
        // Add index
        try {
            $db->exec("CREATE INDEX idx_users_chat_status ON users(chat_status)");
            echo "✅ Created index idx_users_chat_status\n";
        } catch (PDOException $e) {
            echo "⚠️ Index may already exist\n";
        }
    } else {
        echo "ℹ️ chat_status column already exists\n";
    }
    
    // Create chat_status_history table
    $db->exec("CREATE TABLE IF NOT EXISTS chat_status_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        line_account_id INT NOT NULL,
        old_status VARCHAR(50) DEFAULT NULL,
        new_status VARCHAR(50) DEFAULT NULL,
        changed_by INT DEFAULT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        note TEXT DEFAULT NULL,
        INDEX idx_user_status (user_id, line_account_id),
        INDEX idx_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ Created chat_status_history table\n";
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
