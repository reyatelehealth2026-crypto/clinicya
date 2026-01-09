<?php
/**
 * Migration Runner: Add Frozen Zone Type & Make zone_type flexible
 * เปลี่ยน zone_type จาก ENUM เป็น VARCHAR เพื่อรองรับประเภทโซนแบบ dynamic
 */

require_once __DIR__ . '/../config/config.php';

echo "<h2>Migration: Add Frozen Zone Type & Flexible Zone Types</h2>";
echo "<pre>";

try {
    // 1. Alter warehouse_locations.zone_type to VARCHAR
    echo "1. Updating warehouse_locations.zone_type to VARCHAR...\n";
    $db->exec("ALTER TABLE `warehouse_locations` 
               MODIFY COLUMN `zone_type` VARCHAR(50) DEFAULT 'general'");
    echo "   ✓ Done\n";

    // 2. Alter business_items.storage_zone_type to VARCHAR
    echo "2. Updating business_items.storage_zone_type to VARCHAR...\n";
    $db->exec("ALTER TABLE `business_items` 
               MODIFY COLUMN `storage_zone_type` VARCHAR(50) DEFAULT 'general'");
    echo "   ✓ Done\n";

    // 3. Insert frozen into zone_types table
    echo "3. Adding frozen to zone_types table...\n";
    $stmt = $db->prepare("INSERT INTO `zone_types` (`code`, `label`, `color`, `icon`, `description`, `sort_order`, `is_active`, `created_at`, `line_account_id`) 
                          VALUES ('frozen', 'ห้องแช่แข็ง', 'indigo', 'fa-temperature-low', 'สำหรับสินค้าที่ต้องเก็บในอุณหภูมิต่ำกว่า 0°C', 3, 1, NOW(), 1)
                          ON DUPLICATE KEY UPDATE label = VALUES(label), is_active = 1");
    $stmt->execute();
    echo "   ✓ Done\n";

    echo "\n<strong style='color:green'>✓ Migration completed successfully!</strong>\n";
    echo "ตอนนี้สามารถสร้างประเภทโซนใหม่ได้ไม่จำกัดแล้ว\n";

} catch (PDOException $e) {
    echo "\n<strong style='color:red'>✗ Error: " . $e->getMessage() . "</strong>\n";
}

echo "</pre>";
