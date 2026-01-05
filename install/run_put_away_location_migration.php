<?php
/**
 * Run Put Away & Location Management Migration
 * สร้างตารางสำหรับระบบจัดเก็บสินค้าและ Batch Tracking
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>📍 Put Away & Location Management Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Define tables to check
    $tables = [
        'warehouse_locations',
        'inventory_batches',
        'location_movements'
    ];
    
    // Define columns to add to business_items table
    $businessItemsColumns = [
        'movement_class' => "ENUM('A', 'B', 'C') DEFAULT 'C'",
        'storage_zone_type' => "ENUM('general', 'cold_storage', 'controlled', 'hazardous') DEFAULT 'general'",
        'default_location_id' => 'INT NULL',
        'requires_batch_tracking' => 'TINYINT(1) DEFAULT 0',
        'requires_expiry_tracking' => 'TINYINT(1) DEFAULT 0'
    ];
    
    // Check if tables already exist
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
    
    // Check existing columns in business_items table
    echo "📋 Checking columns in business_items table:\n";
    $stmt = $db->query("DESCRIBE business_items");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach (array_keys($businessItemsColumns) as $col) {
        if (in_array($col, $existingColumns)) {
            echo "⚠️ Column '$col' already exists\n";
        } else {
            echo "➡️ Column '$col' will be added\n";
        }
    }
    echo "\n";
    
    $success = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "🔄 Running Put Away & Location migration...\n\n";
    
    // Step 1: Create tables from SQL file
    echo "📝 Creating Put Away & Location tables:\n";
    
    $sqlFile = __DIR__ . '/../database/migration_put_away_location.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Extract CREATE TABLE statements
    preg_match_all('/CREATE TABLE IF NOT EXISTS[^;]+;/s', $sql, $matches);
    
    foreach ($matches[0] as $createStmt) {
        try {
            $db->exec($createStmt);
            preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $createStmt, $tableMatch);
            $tableName = $tableMatch[1] ?? 'unknown';
            echo "✅ Created table: $tableName\n";
            $success++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $createStmt, $tableMatch);
                $tableName = $tableMatch[1] ?? 'unknown';
                echo "⚠️ Skipped (already exists): $tableName\n";
                $skipped++;
            } else {
                echo "❌ Error: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
    echo "\n";
    
    // Step 2: Add columns to business_items table
    echo "📝 Adding columns to business_items table:\n";
    foreach ($businessItemsColumns as $colName => $colDef) {
        if (!in_array($colName, $existingColumns)) {
            try {
                $sql = "ALTER TABLE `business_items` ADD COLUMN `$colName` $colDef";
                $db->exec($sql);
                echo "✅ Added column: $colName\n";
                $success++;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    echo "⚠️ Skipped (already exists): $colName\n";
                    $skipped++;
                } else {
                    echo "❌ Error adding $colName: " . $e->getMessage() . "\n";
                    $errors++;
                }
            }
        } else {
            echo "⚠️ Skipped (already exists): $colName\n";
            $skipped++;
        }
    }
    echo "\n";
    
    // Step 3: Add index for default_location_id
    echo "📝 Adding indexes:\n";
    $indexes = [
        'idx_movement_class' => ['table' => 'business_items', 'column' => 'movement_class'],
        'idx_storage_zone_type' => ['table' => 'business_items', 'column' => 'storage_zone_type'],
        'idx_default_location' => ['table' => 'business_items', 'column' => 'default_location_id']
    ];
    
    foreach ($indexes as $indexName => $indexDef) {
        try {
            // Check if index exists
            $stmt = $db->query("SHOW INDEX FROM `{$indexDef['table']}` WHERE Key_name = '$indexName'");
            if ($stmt->rowCount() == 0) {
                $sql = "ALTER TABLE `{$indexDef['table']}` ADD INDEX `$indexName` (`{$indexDef['column']}`)";
                $db->exec($sql);
                echo "✅ Added index: $indexName on {$indexDef['table']}\n";
                $success++;
            } else {
                echo "⚠️ Skipped (already exists): $indexName\n";
                $skipped++;
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "⚠️ Skipped (already exists): $indexName\n";
                $skipped++;
            } else {
                echo "❌ Error adding index $indexName: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
    echo "\n";
    
    echo "========================================\n";
    echo "✅ Success: $success operations\n";
    if ($skipped > 0) {
        echo "⚠️ Skipped: $skipped operations\n";
    }
    if ($errors > 0) {
        echo "❌ Errors: $errors operations\n";
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
    
    // Verify columns in business_items
    echo "\n📋 Verifying columns in business_items table:\n";
    $stmt = $db->query("DESCRIBE business_items");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach (array_keys($businessItemsColumns) as $col) {
        if (in_array($col, $columns)) {
            echo "✅ Column '$col' exists\n";
        } else {
            echo "❌ Column '$col' NOT found\n";
        }
    }
    
    // Show zone_type enum values
    echo "\n📋 Zone Type values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM warehouse_locations LIKE 'zone_type'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }
    
    // Show ergonomic_level enum values
    echo "\n📋 Ergonomic Level values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM warehouse_locations LIKE 'ergonomic_level'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }
    
    // Show batch status enum values
    echo "\n📋 Batch Status values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM inventory_batches LIKE 'status'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }
    
    // Show movement_type enum values
    echo "\n📋 Movement Type values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM location_movements LIKE 'movement_type'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }
    
    echo "\n🎉 Put Away & Location Migration completed!\n";
    echo "\n<a href='../inventory/index.php'>👉 Go to Inventory</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
