<?php
/**
 * Run Landing Page Migration
 * สร้างตารางสำหรับ FAQ, Testimonials, และ Landing Settings
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🏠 Landing Page Upgrade Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Define tables to check
    $landingTables = [
        'landing_faqs',
        'landing_testimonials',
        'landing_settings'
    ];
    
    // Check if tables already exist
    echo "📋 Checking existing tables:\n";
    $existingTables = [];
    foreach ($landingTables as $table) {
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
    $sqlFile = __DIR__ . '/../database/migration_landing_page.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Remove comments
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    
    // Split by semicolon followed by newline (to avoid splitting inside VALUES)
    $statements = preg_split('/;\s*\n/', $sql);
    $statements = array_filter(array_map('trim', $statements));
    
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
    foreach ($landingTables as $table) {
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
    
    // Verify default FAQ items
    echo "\n📋 Verifying default FAQ items:\n";
    $stmt = $db->query("SELECT COUNT(*) FROM landing_faqs WHERE is_active = 1");
    $faqCount = $stmt->fetchColumn();
    echo "✅ Active FAQ items: $faqCount\n";
    
    if ($faqCount > 0) {
        $stmt = $db->query("SELECT question FROM landing_faqs WHERE is_active = 1 ORDER BY sort_order LIMIT 5");
        $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($faqs as $faq) {
            echo "   - " . mb_substr($faq['question'], 0, 50) . "...\n";
        }
    }
    
    // Verify default testimonials
    echo "\n📋 Verifying default testimonials:\n";
    $stmt = $db->query("SELECT COUNT(*) FROM landing_testimonials WHERE status = 'approved'");
    $testimonialCount = $stmt->fetchColumn();
    echo "✅ Approved testimonials: $testimonialCount\n";
    
    if ($testimonialCount > 0) {
        $stmt = $db->query("SELECT customer_name, rating FROM landing_testimonials WHERE status = 'approved'");
        $testimonials = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($testimonials as $t) {
            echo "   - {$t['customer_name']} (⭐ {$t['rating']})\n";
        }
    }
    
    echo "\n🎉 Landing Page Migration completed!\n";
    echo "\n<a href='../index.php'>👉 Go to Landing Page</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
