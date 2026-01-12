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
    
    echo "=== LINE Accounts ===\n\n";
    
    $stmt = $pdo->query("SELECT id, name, channel_id, is_default FROM line_accounts ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        echo "ID: {$row['id']}, Name: {$row['name']}, Channel: {$row['channel_id']}, Default: {$row['is_default']}\n";
    }
    
    echo "\n=== AI Settings by Line Account ===\n\n";
    
    $stmt = $pdo->query("SELECT id, line_account_id, ai_mode, is_enabled FROM ai_settings ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        $lineId = $row['line_account_id'] ?: 'NULL';
        echo "ID: {$row['id']}, Line Account: {$lineId}, Mode: {$row['ai_mode']}, Enabled: {$row['is_enabled']}\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
