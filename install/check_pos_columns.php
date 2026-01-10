<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = Database::getInstance()->getConnection();
    
    echo "=== pos_transactions columns ===\n";
    $stmt = $db->query("SHOW COLUMNS FROM pos_transactions");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
    
    echo "\n=== pos_transaction_items columns ===\n";
    $stmt = $db->query("SHOW COLUMNS FROM pos_transaction_items");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
    
    echo "\n=== pos_shifts columns ===\n";
    $stmt = $db->query("SHOW COLUMNS FROM pos_shifts");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
    
    echo "\n=== pos_cash_movements exists? ===\n";
    $stmt = $db->query("SHOW TABLES LIKE 'pos_cash_movements'");
    echo $stmt->rowCount() > 0 ? "YES" : "NO";
    echo "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
