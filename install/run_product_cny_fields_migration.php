<?php
/**
 * Run Product CNY Fields Migration
 * Adds CNY API compatible fields to business_items table
 */

require_once __DIR__ . '/../config/database.php';

echo "<h2>Product CNY Fields Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Get existing columns
    $existingCols = [];
    $stmt = $db->query("SHOW COLUMNS FROM business_items");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingCols[] = $row['Field'];
    }
    
    $migrations = [
        'name_en' => "ALTER TABLE business_items ADD COLUMN name_en VARCHAR(500) NULL AFTER name",
        'generic_name' => "ALTER TABLE business_items ADD COLUMN generic_name VARCHAR(500) NULL COMMENT 'ชื่อสามัญ/สารสำคัญ'",
        'usage_instructions' => "ALTER TABLE business_items ADD COLUMN usage_instructions TEXT NULL COMMENT 'วิธีใช้'",
        'manufacturer' => "ALTER TABLE business_items ADD COLUMN manufacturer VARCHAR(255) NULL COMMENT 'ผู้ผลิต'",
        'barcode' => "ALTER TABLE business_items ADD COLUMN barcode VARCHAR(100) NULL",
        'unit' => "ALTER TABLE business_items ADD COLUMN unit VARCHAR(100) NULL COMMENT 'หน่วยจำนวน'",
        'base_unit' => "ALTER TABLE business_items ADD COLUMN base_unit VARCHAR(50) NULL COMMENT 'หน่วยนับ เช่น ขวด, กล่อง, แผง'"
    ];
    
    foreach ($migrations as $col => $sql) {
        if (!in_array($col, $existingCols)) {
            $db->exec($sql);
            echo "✅ Added column: {$col}\n";
        } else {
            echo "⏭️ Column already exists: {$col}\n";
        }
    }
    
    // Add index for barcode
    try {
        $db->exec("CREATE INDEX idx_business_items_barcode ON business_items(barcode)");
        echo "✅ Created index: idx_business_items_barcode\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "⏭️ Index already exists: idx_business_items_barcode\n";
        } else {
            throw $e;
        }
    }
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
