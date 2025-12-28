<?php
/**
 * Fix All Loyalty Points Tables
 * 
 * Adds missing columns to existing tables
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔧 Fix Loyalty Points Tables</h2>";
echo "<pre>";

$db = Database::getInstance()->getConnection();

function columnExists($db, $table, $column) {
    $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $stmt->fetch() !== false;
}

function addColumnIfNotExists($db, $table, $column, $definition) {
    if (!columnExists($db, $table, $column)) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}");
        echo "✅ Added {$column} to {$table}\n";
        return true;
    }
    echo "⏭️ {$column} already exists in {$table}\n";
    return false;
}

try {
    echo "=== Fixing points_tiers table ===\n";
    addColumnIfNotExists($db, 'points_tiers', 'multiplier', 'DECIMAL(3,2) DEFAULT 1.00 AFTER `min_points`');
    addColumnIfNotExists($db, 'points_tiers', 'benefits', 'TEXT AFTER `multiplier`');
    addColumnIfNotExists($db, 'points_tiers', 'badge_color', "VARCHAR(20) DEFAULT '#6B7280' AFTER `benefits`");
    addColumnIfNotExists($db, 'points_tiers', 'icon', "VARCHAR(50) DEFAULT 'fa-medal' AFTER `badge_color`");
    addColumnIfNotExists($db, 'points_tiers', 'sort_order', 'INT DEFAULT 0 AFTER `icon`');
    
    echo "\n=== Fixing points_transactions table ===\n";
    addColumnIfNotExists($db, 'points_transactions', 'line_account_id', 'INT DEFAULT NULL AFTER `user_id`');
    addColumnIfNotExists($db, 'points_transactions', 'balance_after', 'INT DEFAULT 0 AFTER `description`');
    addColumnIfNotExists($db, 'points_transactions', 'expires_at', 'DATE DEFAULT NULL AFTER `balance_after`');
    
    echo "\n=== Fixing rewards table ===\n";
    addColumnIfNotExists($db, 'rewards', 'reward_type', "ENUM('discount', 'shipping', 'gift', 'product', 'coupon', 'voucher') DEFAULT 'gift' AFTER `points_required`");
    addColumnIfNotExists($db, 'rewards', 'reward_value', 'VARCHAR(255) DEFAULT NULL AFTER `reward_type`');
    addColumnIfNotExists($db, 'rewards', 'terms', 'TEXT AFTER `max_per_user`');
    addColumnIfNotExists($db, 'rewards', 'start_date', 'DATE DEFAULT NULL AFTER `terms`');
    addColumnIfNotExists($db, 'rewards', 'end_date', 'DATE DEFAULT NULL AFTER `start_date`');
    addColumnIfNotExists($db, 'rewards', 'sort_order', 'INT DEFAULT 0 AFTER `is_active`');
    
    echo "\n=== Fixing reward_redemptions table ===\n";
    addColumnIfNotExists($db, 'reward_redemptions', 'expires_at', 'DATE DEFAULT NULL AFTER `delivered_at`');
    addColumnIfNotExists($db, 'reward_redemptions', 'expiry_reminder_sent', 'TINYINT(1) DEFAULT 0 AFTER `expires_at`');
    
    echo "\n=== Fixing users table ===\n";
    addColumnIfNotExists($db, 'users', 'total_points', 'INT DEFAULT 0');
    addColumnIfNotExists($db, 'users', 'available_points', 'INT DEFAULT 0');
    addColumnIfNotExists($db, 'users', 'used_points', 'INT DEFAULT 0');
    addColumnIfNotExists($db, 'users', 'tier_id', 'INT DEFAULT NULL');
    
    // Now insert default data
    echo "\n=== Inserting default tiers ===\n";
    $stmt = $db->query("SELECT COUNT(*) FROM points_tiers");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("
            INSERT INTO `points_tiers` (`line_account_id`, `name`, `min_points`, `multiplier`, `badge_color`, `icon`, `sort_order`) VALUES
            (NULL, 'Bronze', 0, 1.00, '#CD7F32', 'fa-medal', 1),
            (NULL, 'Silver', 1000, 1.25, '#C0C0C0', 'fa-medal', 2),
            (NULL, 'Gold', 5000, 1.50, '#FFD700', 'fa-crown', 3),
            (NULL, 'Platinum', 15000, 2.00, '#E5E4E2', 'fa-gem', 4)
        ");
        echo "✅ Inserted default tiers\n";
    } else {
        echo "⏭️ Tiers already exist\n";
    }
    
    echo "\n========================================\n";
    echo "🎉 All fixes completed!\n";
    echo "========================================\n";
    echo "\n<a href='../admin-rewards.php'>👉 Go to Rewards Management</a>\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
