<?php
/**
 * Run Performance Feature Flags Migration
 * 
 * Adds settings for gradual rollout of inbox v2 performance features
 * 
 * Requirements: Task 25.1 - Feature flag for gradual rollout
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Starting Performance Feature Flags Migration...\n";
    echo "==============================================\n\n";
    
    // Read migration file
    $migrationFile = __DIR__ . '/../database/migration_performance_feature_flags.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: {$migrationFile}");
    }
    
    $sql = file_get_contents($migrationFile);
    
    if ($sql === false) {
        throw new Exception("Failed to read migration file");
    }
    
    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    echo "Found " . count($statements) . " SQL statements to execute\n\n";
    
    // Execute each statement
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $index => $statement) {
        try {
            // Skip comments
            if (preg_match('/^--/', trim($statement))) {
                continue;
            }
            
            echo "Executing statement " . ($index + 1) . "...\n";
            $db->exec($statement);
            $successCount++;
            echo "✓ Success\n\n";
            
        } catch (PDOException $e) {
            $errorCount++;
            echo "✗ Error: " . $e->getMessage() . "\n\n";
            
            // Continue with other statements even if one fails
            // (some might fail if already applied)
        }
    }
    
    echo "==============================================\n";
    echo "Migration Summary:\n";
    echo "  Successful: {$successCount}\n";
    echo "  Errors: {$errorCount}\n";
    echo "==============================================\n\n";
    
    // Verify settings were added
    echo "Verifying settings...\n";
    $stmt = $db->query("
        SELECT setting_key, setting_value 
        FROM vibe_selling_settings 
        WHERE setting_key LIKE 'performance%' 
        OR setting_key = 'websocket_enabled'
        ORDER BY setting_key
    ");
    
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($settings) > 0) {
        echo "\nPerformance Feature Flag Settings:\n";
        echo "-----------------------------------\n";
        foreach ($settings as $setting) {
            echo "  {$setting['setting_key']}: {$setting['setting_value']}\n";
        }
        echo "\n";
    } else {
        echo "⚠ Warning: No performance settings found in database\n\n";
    }
    
    echo "✓ Migration completed successfully!\n\n";
    
    echo "Next Steps:\n";
    echo "-----------\n";
    echo "1. Enable performance features for internal team:\n";
    echo "   UPDATE vibe_selling_settings \n";
    echo "   SET setting_value = '1' \n";
    echo "   WHERE setting_key = 'performance_upgrade_enabled';\n\n";
    
    echo "2. Set internal team user IDs (comma-separated):\n";
    echo "   UPDATE vibe_selling_settings \n";
    echo "   SET setting_value = '1,2,3' \n";
    echo "   WHERE setting_key = 'performance_internal_users';\n\n";
    
    echo "3. Adjust rollout percentage for A/B testing:\n";
    echo "   UPDATE vibe_selling_settings \n";
    echo "   SET setting_value = '10' \n";
    echo "   WHERE setting_key = 'performance_rollout_percentage';\n\n";
    
    echo "4. Enable WebSocket real-time updates:\n";
    echo "   UPDATE vibe_selling_settings \n";
    echo "   SET setting_value = '1' \n";
    echo "   WHERE setting_key = 'websocket_enabled';\n\n";
    
} catch (Exception $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

