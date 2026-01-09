<?php
/**
 * Run Landing Page Migration
 * สร้างตารางสำหรับ FAQ, Testimonials, และ Landing Settings
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🏠 Landing Page Upgrade Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Define tables to check
    $landingTables = [
        'landing_faqs',
        'landing_testimonials',
        'landing_settings'
    ];
    
    // Check if tables already exist
    echo "📋 Checking existing tables:\n";
    $existingTables = [];
    foreach ($landingTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $existingTables[] = $table;
            echo "⚠️ Table '$table' already exists\n";
        } else {
            echo "➡️ Table '$table' will be created\n";
        }
    }
    echo "\n";
    
    $success = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "🔄 Running migration...\n\n";
    
    // Execute each statement directly instead of parsing SQL file
    // This avoids issues with semicolons inside VALUES
    
    // 1. SET NAMES
    $db->exec("SET NAMES utf8mb4");
    echo "✅ SET NAMES utf8mb4\n";
    $success++;
    
    // 2. Disable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    echo "✅ SET FOREIGN_KEY_CHECKS = 0\n";
    $success++;
    
    // 3. Create landing_faqs table
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `landing_faqs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `line_account_id` INT NULL,
                `question` VARCHAR(500) NOT NULL,
                `answer` TEXT NOT NULL,
                `sort_order` INT DEFAULT 0,
                `is_active` TINYINT(1) DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_faq_account` (`line_account_id`),
                INDEX `idx_faq_active` (`is_active`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✅ Created table: landing_faqs\n";
        $success++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "⚠️ Table landing_faqs already exists\n";
            $skipped++;
        } else {
            echo "❌ Error creating landing_faqs: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    // 4. Create landing_testimonials table
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `landing_testimonials` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `line_account_id` INT NULL,
                `customer_name` VARCHAR(100) NOT NULL,
                `customer_avatar` VARCHAR(255) NULL,
                `rating` TINYINT DEFAULT 5,
                `review_text` TEXT NOT NULL,
                `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                `source` VARCHAR(50) NULL COMMENT 'google, facebook, manual',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `approved_at` TIMESTAMP NULL,
                INDEX `idx_testimonial_account` (`line_account_id`),
                INDEX `idx_testimonial_status` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✅ Created table: landing_testimonials\n";
        $success++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "⚠️ Table landing_testimonials already exists\n";
            $skipped++;
        } else {
            echo "❌ Error creating landing_testimonials: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    // 5. Create landing_settings table
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `landing_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `line_account_id` INT NULL,
                `setting_key` VARCHAR(100) NOT NULL,
                `setting_value` TEXT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_landing_setting` (`line_account_id`, `setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✅ Created table: landing_settings\n";
        $success++;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "⚠️ Table landing_settings already exists\n";
            $skipped++;
        } else {
            echo "❌ Error creating landing_settings: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    // 6. Enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "✅ SET FOREIGN_KEY_CHECKS = 1\n";
    $success++;
    
    // 7. Insert default FAQ items
    $defaultFaqs = [
        ['ร้านยาเปิดให้บริการเวลาใด?', 'ร้านยาเปิดให้บริการทุกวัน ตั้งแต่เวลา 09:00 - 21:00 น. สามารถสอบถามเภสัชกรผ่าน LINE ได้ตลอดเวลาทำการ', 1],
        ['สามารถสั่งยาออนไลน์ได้อย่างไร?', 'สามารถสั่งยาผ่าน LINE Official Account ของร้าน โดยแชทสอบถามเภสัชกร หรือเลือกสินค้าจากร้านค้าออนไลน์ได้เลย', 2],
        ['มีบริการจัดส่งยาถึงบ้านหรือไม่?', 'มีบริการจัดส่งยาถึงบ้านทั่วประเทศ โดยจัดส่งผ่านขนส่งเอกชน ใช้เวลา 1-3 วันทำการ', 3],
        ['ต้องมีใบสั่งยาจากแพทย์หรือไม่?', 'ยาบางประเภทต้องมีใบสั่งยาจากแพทย์ เภสัชกรจะแจ้งให้ทราบหากยาที่ต้องการจำเป็นต้องใช้ใบสั่งยา', 4],
        ['มีบริการปรึกษาเภสัชกรฟรีหรือไม่?', 'มีบริการปรึกษาเภสัชกรฟรีผ่าน LINE และ Video Call สามารถนัดหมายล่วงหน้าได้', 5]
    ];
    
    $stmt = $db->prepare("
        INSERT IGNORE INTO `landing_faqs` (`question`, `answer`, `sort_order`, `is_active`) 
        VALUES (?, ?, ?, 1)
    ");
    
    $faqInserted = 0;
    foreach ($defaultFaqs as $faq) {
        try {
            $stmt->execute($faq);
            if ($stmt->rowCount() > 0) {
                $faqInserted++;
            }
        } catch (PDOException $e) {
            // Ignore duplicate errors
        }
    }
    echo "✅ Inserted $faqInserted default FAQ items\n";
    $success++;
    
    // 8. Insert default testimonials
    $defaultTestimonials = [
        ['คุณสมชาย', 5, 'บริการดีมาก เภสัชกรให้คำปรึกษาละเอียด ส่งยาถึงบ้านรวดเร็ว ประทับใจมากครับ'],
        ['คุณสมหญิง', 5, 'ใช้บริการมาหลายครั้งแล้ว ราคายาถูกกว่าร้านอื่น เภสัชกรใจดี ตอบคำถามรวดเร็ว'],
        ['คุณวิชัย', 4, 'สะดวกมากที่สั่งยาผ่าน LINE ได้ ไม่ต้องเดินทางไปร้าน เหมาะกับคนที่ไม่มีเวลา']
    ];
    
    $stmt = $db->prepare("
        INSERT IGNORE INTO `landing_testimonials` (`customer_name`, `rating`, `review_text`, `status`, `source`, `approved_at`) 
        VALUES (?, ?, ?, 'approved', 'manual', NOW())
    ");
    
    $testimonialInserted = 0;
    foreach ($defaultTestimonials as $testimonial) {
        try {
            $stmt->execute($testimonial);
            if ($stmt->rowCount() > 0) {
                $testimonialInserted++;
            }
        } catch (PDOException $e) {
            // Ignore duplicate errors
        }
    }
    echo "✅ Inserted $testimonialInserted default testimonials\n";
    $success++;
    
    echo "\n";
    echo "========================================\n";
    echo "✅ Success: $success statements\n";
    if ($skipped > 0) {
        echo "⚠️ Skipped: $skipped statements\n";
    }
    if ($errors > 0) {
        echo "❌ Errors: $errors statements\n";
    }
    echo "========================================\n";
    
    // Verify tables
    echo "\n📋 Verifying tables:\n";
    foreach ($landingTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            // Count rows
            $countStmt = $db->query("SELECT COUNT(*) FROM `$table`");
            $count = $countStmt->fetchColumn();
            echo "✅ Table '$table' exists ($count rows)\n";
        } else {
            echo "❌ Table '$table' NOT found\n";
        }
    }
    
    // Verify default FAQ items
    echo "\n📋 Verifying default FAQ items:\n";
    $stmt = $db->query("SELECT COUNT(*) FROM landing_faqs WHERE is_active = 1");
    $faqCount = $stmt->fetchColumn();
    echo "✅ Active FAQ items: $faqCount\n";
    
    if ($faqCount > 0) {
        $stmt = $db->query("SELECT question FROM landing_faqs WHERE is_active = 1 ORDER BY sort_order LIMIT 5");
        $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($faqs as $faq) {
            echo "   - " . mb_substr($faq['question'], 0, 50) . "...\n";
        }
    }
    
    // Verify default testimonials
    echo "\n📋 Verifying default testimonials:\n";
    $stmt = $db->query("SELECT COUNT(*) FROM landing_testimonials WHERE status = 'approved'");
    $testimonialCount = $stmt->fetchColumn();
    echo "✅ Approved testimonials: $testimonialCount\n";
    
    if ($testimonialCount > 0) {
        $stmt = $db->query("SELECT customer_name, rating FROM landing_testimonials WHERE status = 'approved'");
        $testimonials = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($testimonials as $t) {
            echo "   - {$t['customer_name']} (⭐ {$t['rating']})\n";
        }
    }
    
    echo "\n🎉 Landing Page Migration completed!\n";
    echo "\n<a href='../index.php'>👉 Go to Landing Page</a>\n";
    echo "<a href='../admin/landing-settings.php'>👉 Go to Landing Settings</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
