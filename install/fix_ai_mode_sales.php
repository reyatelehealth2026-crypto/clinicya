<?php
/**
 * Fix AI mode to sales
 */
header('Content-Type: text/plain; charset=utf-8');

try {
    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance()->getConnection();
    
    // Update all to sales mode
    $db->exec("UPDATE ai_settings SET ai_mode = 'sales'");
    
    // Verify
    $stmt = $db->query("SELECT id, line_account_id, ai_mode, is_enabled FROM ai_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "AI Settings updated to sales mode:\n";
    foreach ($rows as $row) {
        echo "ID: {$row['id']}, Line Account: {$row['line_account_id']}, Mode: {$row['ai_mode']}, Enabled: {$row['is_enabled']}\n";
    }
    echo "\nDone!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
