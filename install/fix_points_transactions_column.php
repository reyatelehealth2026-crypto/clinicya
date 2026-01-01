<?php
/**
 * Fix Missing line_account_id Column in points_transactions
 * 
 * This migration creates the table if not exists and adds the column if missing
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔧 Fix points_transactions Table</h2>";
echo "<pre>";

$db = Database::getInstance()->getConnection();

try {
    // First check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'points_transactions'");
    $tableExists = $stmt->fetch();
    
    if (!$tableExists) {
        // Create the table with all columns
        $db->exec("
            CREATE TABLE `points_transactions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `line_account_id` INT DEFAULT NULL,
                `points` INT NOT NULL,
                `type` ENUM('earn', 'redeem', 'expire', 'adjust', 'refund', 'bonus') NOT NULL,
                `reference_type` VARCHAR(50) DEFAULT NULL,
                `reference_id` INT DEFAULT NULL,
                `description` TEXT,
                `balance_after` INT DEFAULT 0,
                `expires_at` DATE DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_user` (`user_id`),
                INDEX `idx_line_account` (`line_account_id`),
                INDEX `idx_type` (`type`),
                INDEX `idx_created` (`created_at`),
                INDEX `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✅ Created points_transactions table with line_account_id column\n";
    } else {
        // Table exists, check if column exists
        $stmt = $db->query("SHOW COLUMNS FROM points_transactions LIKE 'line_account_id'");
        $columnExists = $stmt->fetch();
        
        if (!$columnExists) {
            // Add the missing column
            $db->exec("ALTER TABLE `points_transactions` ADD COLUMN `line_account_id` INT DEFAULT NULL AFTER `user_id`");
            echo "✅ Added line_account_id column to points_transactions table\n";
            
            // Add index for better query performance
            try {
                $db->exec("ALTER TABLE `points_transactions` ADD INDEX `idx_line_account` (`line_account_id`)");
                echo "✅ Added index on line_account_id\n";
            } catch (PDOException $e) {
                echo "⚠️ Index may already exist: " . $e->getMessage() . "\n";
            }
        } else {
            echo "✅ line_account_id column already exists\n";
        }
    }
    
    // Verify the fix
    echo "\n--- Verification ---\n";
    $stmt = $db->query("DESCRIBE points_transactions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns in points_transactions:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    echo "\n========================================\n";
    echo "🎉 Migration completed successfully!\n";
    echo "========================================\n";
    echo "\n<a href='../admin-rewards.php'>👉 Go to Rewards Management</a>\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Error code: " . $e->getCode() . "\n";
}

echo "</pre>";
