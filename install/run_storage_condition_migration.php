<?php
/**
 * Run Storage Condition Migration
 * เพิ่ม column storage_condition ให้กับ business_items table
 */

require_once __DIR__ . '/../config/database.php';

echo "<h2>🔧 Running Storage Condition Migration</h2>";
echo "<pre>";

try {
    $db = getDB();
    
    // Check if column exists
    $stmt = $db->query("
        SELECT COUNT(*) as cnt 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'business_items' 
        AND COLUMN_NAME = 'storage_condition'
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['cnt'] > 0) {
        echo "✅ Column 'storage_condition' already exists in business_items table.\n";
    } else {
        // Add the column
        $db->exec("
            ALTER TABLE `business_items` 
            ADD COLUMN `storage_condition` VARCHAR(255) DEFAULT NULL 
            COMMENT 'สภาพการจัดเก็บ/ตำแหน่งจัดเก็บ'
        ");
        echo "✅ Column 'storage_condition' added successfully!\n";
    }
    
    echo "\n🎉 Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
