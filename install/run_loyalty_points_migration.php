<?php
/**
 * Run Loyalty Points Migration
 * สร้างตารางสำหรับระบบสะสมแต้มแลกของรางวัล
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🎁 Loyalty Points Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Read migration file
    $sqlFile = __DIR__ . '/../database/migration_loyalty_points.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) continue;
        
        try {
            $db->exec($statement);
            echo "✅ Executed: " . substr($statement, 0, 60) . "...\n";
            $success++;
        } catch (PDOException $e) {
            // Ignore duplicate column/table errors
            if (strpos($e->getMessage(), 'Duplicate') !== false || 
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠️ Skipped (already exists): " . substr($statement, 0, 60) . "...\n";
            } else {
                echo "❌ Error: " . $e->getMessage() . "\n";
                echo "   Statement: " . substr($statement, 0, 100) . "...\n";
                $errors++;
            }
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "✅ Success: $success statements\n";
    if ($errors > 0) {
        echo "❌ Errors: $errors statements\n";
    }
    echo "========================================\n";
    
    // Verify tables
    echo "\n📋 Verifying tables:\n";
    $tables = ['points_settings', 'points_transactions', 'rewards', 'reward_redemptions', 'points_tiers'];
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Table '$table' exists\n";
        } else {
            echo "❌ Table '$table' NOT found\n";
        }
    }
    
    // Check users columns
    echo "\n📋 Checking users table columns:\n";
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE '%points%'");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach (['total_points', 'available_points', 'used_points'] as $col) {
        $stmt = $db->query("SHOW COLUMNS FROM users LIKE '$col'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Column 'users.$col' exists\n";
        } else {
            echo "❌ Column 'users.$col' NOT found\n";
        }
    }
    
    echo "\n🎉 Migration completed!\n";
    echo "\n<a href='../loyalty-rewards.php'>👉 Go to Loyalty Rewards</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
