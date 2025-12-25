<?php
/**
 * Run Onboarding Assistant Migration
 */

require_once 'config/config.php';
require_once 'modules/Core/Database.php';

use Modules\Core\Database;

echo "<h2>Running Onboarding Assistant Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Read migration file
    $sql = file_get_contents(__DIR__ . '/database/migration_onboarding_assistant.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) continue;
        
        try {
            $db->exec($statement);
            echo "✅ Executed: " . substr($statement, 0, 50) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠️ Table already exists, skipping...\n";
            } else {
                echo "❌ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n✅ Migration completed successfully!\n";
    
    // Verify tables
    echo "\n--- Verifying Tables ---\n";
    
    $tables = ['onboarding_sessions', 'setup_progress'];
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Table '$table' exists\n";
        } else {
            echo "❌ Table '$table' NOT found\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p><a href='/onboarding-assistant.php'>Go to Onboarding Assistant</a></p>";
