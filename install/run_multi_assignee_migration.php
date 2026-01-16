<?php
/**
 * Multi-Assignee Migration Runner
 * Adds support for assigning conversations to multiple admins
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Multi-Assignee Migration</title>";
echo "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:50px auto;padding:20px;}";
echo ".success{color:green;}.error{color:red;}.info{color:blue;}</style></head><body>";

echo "<h1>🔄 Multi-Assignee Migration</h1>";

try {
    // Read migration file
    $migrationFile = __DIR__ . '/../database/migration_multi_assignee.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: {$migrationFile}");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   strpos($stmt, '--') !== 0 && 
                   strpos($stmt, '/*') !== 0;
        }
    );
    
    echo "<h2>Executing Migration...</h2>";
    
    $db->beginTransaction();
    
    foreach ($statements as $index => $statement) {
        if (empty(trim($statement))) continue;
        
        echo "<p class='info'>Statement " . ($index + 1) . "...</p>";
        
        try {
            $db->exec($statement);
            echo "<p class='success'>✓ Success</p>";
        } catch (PDOException $e) {
            // Check if error is about table already exists
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "<p class='info'>⚠ Table already exists, skipping...</p>";
            } else {
                throw $e;
            }
        }
    }
    
    $db->commit();
    
    echo "<h2 class='success'>✅ Migration Completed Successfully!</h2>";
    
    // Show statistics
    echo "<h3>Statistics:</h3>";
    
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM conversation_multi_assignees");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Total assignments: <strong>{$count['count']}</strong></p>";
        
        $stmt = $db->query("SELECT COUNT(DISTINCT user_id) as count FROM conversation_multi_assignees WHERE status = 'active'");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Active conversations: <strong>{$count['count']}</strong></p>";
        
        $stmt = $db->query("SELECT COUNT(DISTINCT admin_id) as count FROM conversation_multi_assignees WHERE status = 'active'");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Admins with assignments: <strong>{$count['count']}</strong></p>";
    } catch (PDOException $e) {
        echo "<p class='info'>⚠ Could not fetch statistics: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    echo "<hr>";
    echo "<p><a href='../inbox-v2.php'>← กลับไปหน้า Inbox</a></p>";
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "<h2 class='error'>❌ Migration Failed</h2>";
    echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
