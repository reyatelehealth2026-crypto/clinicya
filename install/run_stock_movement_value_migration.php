<?php
/**
 * Run Stock Movement Value Migration
 * เพิ่ม value_change column สำหรับ tracking มูลค่าการเคลื่อนไหว stock
 * Requirements: 6.3
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>💰 Stock Movement Value Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    $success = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "🔄 Running Stock Movement Value migration...\n\n";
    
    // Step 1: Check if stock_movements table exists, create if not
    echo "📋 Checking stock_movements table:\n";
    $stmt = $db->query("SHOW TABLES LIKE 'stock_movements'");
    if ($stmt->rowCount() == 0) {
        echo "➡️ Creating stock_movements table...\n";
        $db->exec("
            CREATE TABLE `stock_movements` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `line_account_id` INT DEFAULT NULL,
                `product_id` INT NOT NULL,
                `movement_type` VARCHAR(50) NOT NULL COMMENT 'goods_receive, disposal, adjustment_in, adjustment_out, sale',
                `quantity` INT NOT NULL COMMENT 'Positive for in, negative for out',
                `stock_before` INT NOT NULL DEFAULT 0,
                `stock_after` INT NOT NULL DEFAULT 0,
                `reference_type` VARCHAR(50) NULL COMMENT 'goods_receive, batch_disposal, adjustment, order',
                `reference_id` INT NULL,
                `reference_number` VARCHAR(50) NULL,
                `notes` TEXT NULL,
                `unit_cost` DECIMAL(10,2) NULL COMMENT 'Unit cost at time of movement',
                `value_change` DECIMAL(12,2) NULL COMMENT 'Cost impact: quantity × unit_cost',
                `created_by` INT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_product` (`product_id`),
                INDEX `idx_movement_type` (`movement_type`),
                INDEX `idx_reference` (`reference_type`, `reference_id`),
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_line_account` (`line_account_id`),
                INDEX `idx_value_change` (`value_change`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "✅ Created stock_movements table\n";
        $success++;
    } else {
        echo "✅ Table 'stock_movements' exists\n";
    }
    echo "\n";
    
    // Step 2: Get existing columns
    echo "📋 Checking existing columns:\n";
    $stmt = $db->query("DESCRIBE stock_movements");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Define columns to add
    $columnsToAdd = [
        'value_change' => "DECIMAL(12,2) NULL COMMENT 'Cost impact: quantity × unit_cost'",
        'unit_cost' => "DECIMAL(10,2) NULL COMMENT 'Unit cost at time of movement'",
        'stock_before' => "INT NOT NULL DEFAULT 0 COMMENT 'Stock quantity before movement'",
        'stock_after' => "INT NOT NULL DEFAULT 0 COMMENT 'Stock quantity after movement'"
    ];
    
    foreach (array_keys($columnsToAdd) as $col) {
        if (in_array($col, $existingColumns)) {
            echo "⚠️ Column '$col' already exists\n";
        } else {
            echo "➡️ Column '$col' will be added\n";
        }
    }
    echo "\n";
    
    // Step 3: Add columns
    echo "📝 Adding columns to stock_movements table:\n";
    foreach ($columnsToAdd as $colName => $colDef) {
        if (!in_array($colName, $existingColumns)) {
            try {
                $sql = "ALTER TABLE `stock_movements` ADD COLUMN `$colName` $colDef";
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
    
    // Step 4: Add indexes
    echo "📝 Adding indexes:\n";
    $indexes = [
        'idx_value_change' => 'value_change'
    ];
    
    foreach ($indexes as $indexName => $columnName) {
        try {
            // Check if index exists
            $stmt = $db->query("SHOW INDEX FROM `stock_movements` WHERE Key_name = '$indexName'");
            if ($stmt->rowCount() == 0) {
                $sql = "ALTER TABLE `stock_movements` ADD INDEX `$indexName` (`$columnName`)";
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
    
    // Verify columns
    echo "\n📋 Verifying columns in stock_movements table:\n";
    $stmt = $db->query("DESCRIBE stock_movements");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $targetColumns = array_keys($columnsToAdd);
    foreach ($columns as $col) {
        if (in_array($col['Field'], $targetColumns)) {
            echo "✅ Column '{$col['Field']}' exists (Type: {$col['Type']})\n";
        }
    }
    
    // Show table structure
    echo "\n📋 Current stock_movements table structure:\n";
    foreach ($columns as $col) {
        $null = $col['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['Default'] !== null ? "DEFAULT '{$col['Default']}'" : '';
        echo "   - {$col['Field']}: {$col['Type']} $null $default\n";
    }
    
    echo "\n🎉 Stock Movement Value Migration completed!\n";
    echo "\n<a href='../inventory/index.php'>👉 Go to Inventory</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
