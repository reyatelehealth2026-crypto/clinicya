<?php
/**
 * Fix Missing line_account_id Column in points_transactions
 * 
 * This migration adds the line_account_id column if it doesn't exist
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔧 Fix points_transactions Table</h2>";
echo "<pre>";

$db = Database::getInstance()->getConnection();

try {
    // Check if line_account_id column exists
    $stmt = $db->query("SHOW COLUMNS FROM points_transactions LIKE 'line_account_id'");
    $columnExists = $stmt->fetch();
    
    if (!$columnExists) {
        // Add the missing column
        $db->exec("ALTER TABLE `points_transactions` ADD COLUMN `line_account_id` INT DEFAULT NULL AFTER `user_id`");
        echo "✅ Added line_account_id column to points_transactions table\n";
        
        // Add index for better query performance
        $db->exec("ALTER TABLE `points_transactions` ADD INDEX `idx_line_account` (`line_account_id`)");
        echo "✅ Added index on line_account_id\n";
    } else {
        echo "⚠️ line_account_id column already exists, no changes needed\n";
    }
    
    echo "\n========================================\n";
    echo "🎉 Migration completed successfully!\n";
    echo "========================================\n";
    echo "\n<a href='../admin-rewards.php'>👉 Go to Rewards Management</a>\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
