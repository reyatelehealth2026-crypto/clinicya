<?php
/**
 * Run Landing Banners & Featured Products Migration
 * สร้างตารางสำหรับ Banner Slider และ Featured Products
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🖼️ Landing Banners & Featured Products Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    $tables = ['landing_banners', 'landing_featured_products'];
    
    echo "📋 Checking existing tables:\n";
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "⚠️ Table '$table' already exists\n";
        } else {
            echo "➡️ Table '$table' will be created\n";
        }
    }
    echo "\n";
    
    $success = 0;
    $errors = 0;
    
    echo "🔄 Running migration...\n\n";
    
    // SET NAMES
    $db->exec("SET NAMES utf8mb4");
    echo "✅ SET NAMES utf8mb4\n";
    $success++;
    
    // Disable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✅ SET FOREIGN_KEY_CHECKS = 0\n";
    $success++;
    
    // Create landing_banners table
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `landing_banners` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `line_account_id` INT NULL,
                `title` VARCHAR(255) NULL,
                `image_url` VARCHAR(500) NOT NULL,
                `link_url` VARCHAR(500) NULL,
                `link_type` ENUM('none', 'internal', 'external') DEFAULT 'none',
                `sort_order` INT DEFAULT 0,
                `is_active` TINYINT(1) DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_banner_account` (`line_account_id`),
                INDEX `idx_banner_active` (`is_active`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✅ Created table: landing_banners\n";
        $success++;
    } catch (PDOException $e) {
        echo "❌ Error creating landing_banners: " . $e->getMessage() . "\n";
        $errors++;
    }
    
    // Create landing_featured_products table
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `landing_featured_products` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `line_account_id` INT NULL,
                `product_id` INT NOT NULL,
                `product_source` VARCHAR(50) DEFAULT 'products' COMMENT 'products, business_items, cny_products',
                `sort_order` INT DEFAULT 0,
                `is_active` TINYINT(1) DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_featured_account` (`line_account_id`),
                INDEX `idx_featured_product` (`product_id`),
                INDEX `idx_featured_active` (`is_active`, `sort_order`),
                UNIQUE KEY `uk_featured_product` (`line_account_id`, `product_id`, `product_source`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✅ Created table: landing_featured_products\n";
        $success++;
    } catch (PDOException $e) {
        echo "❌ Error creating landing_featured_products: " . $e->getMessage() . "\n";
        $errors++;
    }
    
    // Add product_source column if not exists
    try {
        $db->exec("ALTER TABLE landing_featured_products ADD COLUMN product_source VARCHAR(50) DEFAULT 'products' AFTER product_id");
        echo "✅ Added column: product_source\n";
        $success++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⚠️ Column product_source already exists\n";
        }
    }
    
    // Enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✅ SET FOREIGN_KEY_CHECKS = 1\n";
    $success++;
    
    echo "\n========================================\n";
    echo "✅ Success: $success statements\n";
    if ($errors > 0) {
        echo "❌ Errors: $errors statements\n";
    }
    echo "========================================\n";
    
    // Verify tables
    echo "\n📋 Verifying tables:\n";
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $countStmt = $db->query("SELECT COUNT(*) FROM `$table`");
            $count = $countStmt->fetchColumn();
            echo "✅ Table '$table' exists ($count rows)\n";
        } else {
            echo "❌ Table '$table' NOT found\n";
        }
    }
    
    echo "\n🎉 Migration completed!\n";
    echo "\n<a href='../admin/landing-settings.php'>👉 Go to Landing Settings</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
