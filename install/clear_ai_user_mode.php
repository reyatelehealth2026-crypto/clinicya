<?php
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$name = defined('DB_NAME') ? DB_NAME : '';
$user = defined('DB_USER') ? DB_USER : '';
$pass = defined('DB_PASS') ? DB_PASS : '';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== AI User Mode ===\n\n";
    
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'ai_user_mode'");
    if ($stmt->rowCount() == 0) {
        echo "Table ai_user_mode does not exist\n";
    } else {
        // Show current modes
        $stmt = $pdo->query("SELECT * FROM ai_user_mode ORDER BY id DESC LIMIT 10");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Current user modes:\n";
        foreach ($rows as $row) {
            echo "- User: {$row['user_id']}, Mode: {$row['ai_mode']}, Expires: {$row['expires_at']}\n";
        }
        
        // Clear all or update to sales
        if (isset($_GET['clear'])) {
            $pdo->exec("DELETE FROM ai_user_mode");
            echo "\nCleared all user AI modes!\n";
        } elseif (isset($_GET['fix'])) {
            $pdo->exec("UPDATE ai_user_mode SET ai_mode = 'sales'");
            echo "\nUpdated all user AI modes to 'sales'!\n";
        }
        
        echo "\nAdd ?clear to URL to clear all modes\n";
        echo "Add ?fix to URL to update all to sales mode\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
