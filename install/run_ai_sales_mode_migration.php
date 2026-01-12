<?php
/**
 * Run AI Sales Mode Migration
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>AI Sales Mode Migration</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if columns exist
    $stmt = $db->query("SHOW COLUMNS FROM ai_settings LIKE 'ai_mode'");
    if ($stmt->rowCount() == 0) {
        // Add columns
        $db->exec("ALTER TABLE ai_settings 
            ADD COLUMN `ai_mode` ENUM('pharmacist', 'sales', 'support') DEFAULT 'pharmacist' COMMENT 'โหมด AI' AFTER `pharmacy_mode`,
            ADD COLUMN `business_info` TEXT COMMENT 'ข้อมูลธุรกิจ' AFTER `ai_mode`,
            ADD COLUMN `product_knowledge` TEXT COMMENT 'ข้อมูลสินค้าเพิ่มเติม' AFTER `business_info`,
            ADD COLUMN `sales_prompt` TEXT COMMENT 'Prompt สำหรับโหมดขาย' AFTER `product_knowledge`,
            ADD COLUMN `auto_load_products` TINYINT(1) DEFAULT 1 COMMENT 'โหลดสินค้าอัตโนมัติ' AFTER `sales_prompt`,
            ADD COLUMN `product_load_limit` INT DEFAULT 50 COMMENT 'จำนวนสินค้าที่โหลด' AFTER `auto_load_products`
        ");
        echo "<p style='color:green'>✅ Added new columns to ai_settings</p>";
    } else {
        echo "<p style='color:blue'>ℹ️ Columns already exist</p>";
    }
    
    echo "<p style='color:green'>✅ Migration completed!</p>";
    echo "<p><a href='../ai-settings.php'>Go to AI Settings</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}
