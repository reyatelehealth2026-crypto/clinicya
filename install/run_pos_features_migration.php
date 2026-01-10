<?php
/**
 * Run POS Features Migration
 * Adds Hold/Park, Price Override, Cash Movements, Reprint tracking
 */

require_once __DIR__ . '/../config/database.php';

echo "<h2>POS Features Migration</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Read migration file
    $sql = file_get_contents(__DIR__ . '/../database/migration_pos_features.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success = 0;
    $errors = [];
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) continue;
        
        try {
            $db->exec($statement);
            $success++;
            echo "<p style='color:green'>✓ Executed: " . substr($statement, 0, 80) . "...</p>";
        } catch (PDOException $e) {
            // Ignore "column already exists" errors
            if (strpos($e->getMessage(), 'Duplicate column') !== false || 
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "<p style='color:orange'>⚠ Skipped (already exists): " . substr($statement, 0, 60) . "...</p>";
            } else {
                $errors[] = $e->getMessage();
                echo "<p style='color:red'>✗ Error: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    echo "<hr>";
    echo "<p><strong>Summary:</strong> {$success} statements executed successfully</p>";
    
    if (!empty($errors)) {
        echo "<p style='color:red'>" . count($errors) . " errors occurred</p>";
    }
    
    // Verify tables
    echo "<h3>Verification</h3>";
    
    // Check pos_cash_movements table
    $stmt = $db->query("SHOW TABLES LIKE 'pos_cash_movements'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✓ pos_cash_movements table exists</p>";
    } else {
        echo "<p style='color:red'>✗ pos_cash_movements table NOT found</p>";
    }
    
    // Check new columns in pos_transactions
    $stmt = $db->query("SHOW COLUMNS FROM pos_transactions LIKE 'hold_note'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✓ hold_note column exists</p>";
    }
    
    $stmt = $db->query("SHOW COLUMNS FROM pos_transactions LIKE 'reprint_count'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✓ reprint_count column exists</p>";
    }
    
    // Check new columns in pos_transaction_items
    $stmt = $db->query("SHOW COLUMNS FROM pos_transaction_items LIKE 'original_price'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✓ original_price column exists</p>";
    }
    
    echo "<h2>✅ Migration Complete!</h2>";
    echo "<p><a href='../pos.php'>Go to POS</a></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color:red'>Migration Failed</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
