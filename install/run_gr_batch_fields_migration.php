<?php
/**
 * Run GR Batch Fields Migration
 * เพิ่ม columns สำหรับ batch tracking ใน goods_receive_items table
 * Requirements: 1.2, 4.2
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>📦 GR Batch Fields Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Define columns to add to goods_receive_items table
    $grItemsColumns = [
        'batch_number' => "VARCHAR(50) NULL COMMENT 'Batch number from supplier'",
        'lot_number' => "VARCHAR(50) NULL COMMENT 'Lot number from supplier'",
        'expiry_date' => "DATE NULL COMMENT 'Product expiry date'",
        'manufacture_date' => "DATE NULL COMMENT 'Product manufacture date'",
        'unit_cost' => "DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Unit cost at time of receive'"
    ];
    
    // Define indexes to add
    $indexes = [
        'idx_gri_batch_number' => 'batch_number',
        'idx_gri_expiry_date' => 'expiry_date'
    ];
    
    // Check if goods_receive_items table exists
    echo "📋 Checking goods_receive_items table:\n";
    $stmt = $db->query("SHOW TABLES LIKE 'goods_receive_items'");
    if ($stmt->rowCount() == 0) {
        throw new Exception("Table 'goods_receive_items' does not exist. Please run inventory migration first.");
    }
    echo "✅ Table 'goods_receive_items' exists\n\n";
    
    // Check existing columns
    echo "📋 Checking existing columns:\n";
    $stmt = $db->query("DESCRIBE goods_receive_items");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach (array_keys($grItemsColumns) as $col) {
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
    
    echo "🔄 Running GR Batch Fields migration...\n\n";
    
    // Step 1: Add columns to goods_receive_items table
    echo "📝 Adding columns to goods_receive_items table:\n";
    foreach ($grItemsColumns as $colName => $colDef) {
        if (!in_array($colName, $existingColumns)) {
            try {
                $sql = "ALTER TABLE `goods_receive_items` ADD COLUMN `$colName` $colDef";
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
    
    // Step 2: Add indexes
    echo "📝 Adding indexes:\n";
    foreach ($indexes as $indexName => $columnName) {
        try {
            // Check if index exists
            $stmt = $db->query("SHOW INDEX FROM `goods_receive_items` WHERE Key_name = '$indexName'");
            if ($stmt->rowCount() == 0) {
                $sql = "ALTER TABLE `goods_receive_items` ADD INDEX `$indexName` (`$columnName`)";
                $db->exec($sql);
                echo "✅ Added index: $indexName on $columnName\n";
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
    
    // Verify columns in goods_receive_items
    echo "\n📋 Verifying columns in goods_receive_items table:\n";
    $stmt = $db->query("DESCRIBE goods_receive_items");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $targetColumns = array_keys($grItemsColumns);
    foreach ($columns as $col) {
        if (in_array($col['Field'], $targetColumns)) {
            echo "✅ Column '{$col['Field']}' exists (Type: {$col['Type']})\n";
        }
    }
    
    // Verify indexes
    echo "\n📋 Verifying indexes:\n";
    foreach (array_keys($indexes) as $indexName) {
        $stmt = $db->query("SHOW INDEX FROM `goods_receive_items` WHERE Key_name = '$indexName'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Index '$indexName' exists\n";
        } else {
            echo "❌ Index '$indexName' NOT found\n";
        }
    }
    
    // Show table structure
    echo "\n📋 Current goods_receive_items table structure:\n";
    $stmt = $db->query("DESCRIBE goods_receive_items");
    $allColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allColumns as $col) {
        $null = $col['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['Default'] !== null ? "DEFAULT '{$col['Default']}'" : '';
        echo "   - {$col['Field']}: {$col['Type']} $null $default\n";
    }
    
    echo "\n🎉 GR Batch Fields Migration completed!\n";
    echo "\n<a href='../procurement.php'>👉 Go to Procurement</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
