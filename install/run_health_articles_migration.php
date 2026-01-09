<?php
/**
 * Run Health Articles Migration
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>Health Articles Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Create health_article_categories table
    echo "Creating health_article_categories table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS `health_article_categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_account_id` INT NULL,
            `name` VARCHAR(100) NOT NULL,
            `slug` VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `icon` VARCHAR(50) DEFAULT 'fas fa-folder',
            `sort_order` INT DEFAULT 0,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_category_active` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created table: health_article_categories\n";
    
    // Create health_articles table
    echo "Creating health_articles table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS `health_articles` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_account_id` INT NULL,
            `category_id` INT NULL,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL,
            `excerpt` TEXT NULL,
            `content` LONGTEXT NOT NULL,
            `featured_image` VARCHAR(500) NULL,
            `author_name` VARCHAR(100) NULL,
            `author_title` VARCHAR(100) NULL,
            `author_image` VARCHAR(500) NULL,
            `tags` JSON NULL,
            `meta_title` VARCHAR(255) NULL,
            `meta_description` VARCHAR(500) NULL,
            `meta_keywords` VARCHAR(500) NULL,
            `view_count` INT DEFAULT 0,
            `is_featured` TINYINT(1) DEFAULT 0,
            `is_published` TINYINT(1) DEFAULT 0,
            `published_at` TIMESTAMP NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_article_published` (`is_published`, `published_at`),
            INDEX `idx_article_featured` (`is_featured`, `is_published`),
            INDEX `idx_article_category` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created table: health_articles\n";
    
    // Insert default categories
    echo "Inserting default categories...\n";
    $categories = [
        ['สุขภาพทั่วไป', 'general-health', 'บทความเกี่ยวกับสุขภาพทั่วไป', 'fas fa-heartbeat', 1],
        ['โภชนาการ', 'nutrition', 'บทความเกี่ยวกับอาหารและโภชนาการ', 'fas fa-apple-alt', 2],
        ['ยาและวิตามิน', 'medicine-vitamins', 'ความรู้เกี่ยวกับยาและวิตามิน', 'fas fa-pills', 3],
        ['โรคและการรักษา', 'diseases-treatment', 'ข้อมูลโรคและวิธีการรักษา', 'fas fa-stethoscope', 4],
        ['สุขภาพจิต', 'mental-health', 'บทความเกี่ยวกับสุขภาพจิต', 'fas fa-brain', 5],
        ['ออกกำลังกาย', 'exercise', 'เคล็ดลับการออกกำลังกาย', 'fas fa-running', 6]
    ];
    
    $stmt = $db->prepare("INSERT IGNORE INTO health_article_categories (name, slug, description, icon, sort_order) VALUES (?, ?, ?, ?, ?)");
    foreach ($categories as $cat) {
        $stmt->execute($cat);
    }
    echo "✅ Inserted default categories\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Run /install/insert_sample_articles.php to add sample articles\n";
    echo "2. Go to Landing Settings > บทความ to manage articles\n";
    echo "3. View articles at: " . BASE_URL . "articles.php\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "⚠️ Tables already exist\n";
        echo "✅ Migration completed (tables already created)\n";
    } else {
        echo "❌ Migration failed: " . $e->getMessage() . "\n";
    }
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p><a href='insert_sample_articles.php'>Insert Sample Articles</a> | <a href='../admin/landing-settings.php?tab=articles'>Go to Article Management</a></p>";
