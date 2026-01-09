<?php
/**
 * Migration Runner: Add Frozen Zone Type & Make zone_type flexible
 * เปลี่ยน zone_type จาก ENUM เป็น VARCHAR เพื่อรองรับประเภทโซนแบบ dynamic
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Migration: Add Frozen Zone Type & Flexible Zone Types</h2>";
echo "<pre>";

try {
    // Disable strict mode temporarily
    $db->exec("SET sql_mode = ''");
    echo "SQL mode set\n";
} catch (Exception $e) {
    echo "SQL mode error: " . $e->getMessage() . "\n";
}

try {
    // 1. First fix any invalid zone_type values to 'general'
    echo "1. Fixing invalid zone_type values...\n";
    try {
        $db->exec("UPDATE `warehouse_locations` SET `zone_type` = 'general' WHERE `zone_type` NOT IN ('general', 'cold_storage', 'controlled', 'hazardous') OR `zone_type` IS NULL OR `zone_type` = ''");
        echo "   ✓ Done\n";
    } catch (Exception $e) {
        echo "   - Skipped (table may not exist)\n";
    }

    // 2. Alter warehouse_locations.zone_type to VARCHAR
    echo "2. Updating warehouse_locations.zone_type to VARCHAR...\n";
    try {
        $db->exec("ALTER TABLE `warehouse_locations` MODIFY COLUMN `zone_type` VARCHAR(50) NOT NULL DEFAULT 'general'");
        echo "   ✓ Done\n";
    } catch (Exception $e) {
        echo "   - Error: " . $e->getMessage() . "\n";
    }

    // 3. Fix invalid storage_zone_type values
    echo "3. Fixing invalid storage_zone_type values...\n";
    try {
        $db->exec("UPDATE `business_items` SET `storage_zone_type` = 'general' WHERE `storage_zone_type` NOT IN ('general', 'cold_storage', 'controlled', 'hazardous') OR `storage_zone_type` IS NULL OR `storage_zone_type` = ''");
        echo "   ✓ Done\n";
    } catch (Exception $e) {
        echo "   - Skipped (column may not exist)\n";
    }

    // 4. Alter business_items.storage_zone_type to VARCHAR
    echo "4. Updating business_items.storage_zone_type to VARCHAR...\n";
    try {
        $db->exec("ALTER TABLE `business_items` MODIFY COLUMN `storage_zone_type` VARCHAR(50) DEFAULT 'general'");
        echo "   ✓ Done\n";
    } catch (Exception $e) {
        echo "   - Error: " . $e->getMessage() . "\n";
    }

    // 5. Insert frozen into zone_types table
    echo "5. Adding frozen to zone_types table...\n";
    try {
        $stmt = $db->prepare("INSERT INTO `zone_types` (`code`, `label`, `color`, `icon`, `description`, `sort_order`, `is_active`, `created_at`, `line_account_id`) 
                              VALUES ('frozen', 'ห้องแช่แข็ง', 'indigo', 'fa-temperature-low', 'สำหรับสินค้าที่ต้องเก็บในอุณหภูมิต่ำกว่า 0°C', 3, 1, NOW(), 1)
                              ON DUPLICATE KEY UPDATE label = VALUES(label), is_active = 1");
        $stmt->execute();
        echo "   ✓ Done\n";
    } catch (Exception $e) {
        echo "   - Error: " . $e->getMessage() . "\n";
    }

    echo "\n<strong style='color:green'>✓ Migration completed!</strong>\n";
    echo "ตอนนี้สามารถสร้างประเภทโซนใหม่ได้ไม่จำกัดแล้ว\n";

} catch (PDOException $e) {
    echo "\n<strong style='color:red'>✗ Error: " . $e->getMessage() . "</strong>\n";
}

echo "</pre>";
