<?php
/**
 * Run Accounting Migration
 * สร้างตารางสำหรับระบบบัญชี AP, AR, Expenses
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>💰 Accounting Management Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Define tables to check
    $accountingTables = [
        'expense_categories',
        'expenses',
        'account_payables',
        'account_receivables',
        'payment_vouchers',
        'receipt_vouchers'
    ];
    
    // Check if tables already exist
    echo "📋 Checking existing tables:\n";
    $existingTables = [];
    foreach ($accountingTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $existingTables[] = $table;
            echo "⚠️ Table '$table' already exists\n";
        } else {
            echo "➡️ Table '$table' will be created\n";
        }
    }
    echo "\n";
    
    // Read migration file
    $sqlFile = __DIR__ . '/../database/migration_accounting.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "🔄 Running migration...\n\n";
    
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
                $skipped++;
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
    if ($skipped > 0) {
        echo "⚠️ Skipped: $skipped statements\n";
    }
    if ($errors > 0) {
        echo "❌ Errors: $errors statements\n";
    }
    echo "========================================\n";

    
    // Verify tables
    echo "\n📋 Verifying tables:\n";
    foreach ($accountingTables as $table) {
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
    
    // Verify default expense categories
    echo "\n📋 Verifying default expense categories:\n";
    $stmt = $db->query("SELECT COUNT(*) FROM expense_categories WHERE is_default = 1");
    $defaultCount = $stmt->fetchColumn();
    echo "✅ Default expense categories: $defaultCount\n";
    
    if ($defaultCount > 0) {
        $stmt = $db->query("SELECT name, name_en, expense_type FROM expense_categories WHERE is_default = 1");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($categories as $cat) {
            echo "   - {$cat['name']} ({$cat['name_en']}) - {$cat['expense_type']}\n";
        }
    }
    
    echo "\n🎉 Accounting Migration completed!\n";
    echo "\n<a href='../accounting.php'>👉 Go to Accounting Management</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
