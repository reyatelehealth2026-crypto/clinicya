<?php
/**
 * Fix movement_type column to support all types
 */

require_once __DIR__ . '/../config/database.php';

echo "<h2>Fix Movement Type Migration</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Fix stock_movements table
    try {
        $db->exec("
            ALTER TABLE stock_movements 
            MODIFY COLUMN movement_type VARCHAR(50) NOT NULL 
            COMMENT 'goods_receive, disposal, adjustment_in, adjustment_out, sale, return_restore, void_restore'
        ");
        echo "<p style='color:green'>✓ stock_movements.movement_type updated to VARCHAR(50)</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            echo "<p style='color:orange'>⚠ stock_movements table doesn't exist</p>";
        } else {
            echo "<p style='color:orange'>⚠ stock_movements: " . $e->getMessage() . "</p>";
        }
    }
    
    // Fix location_movements table
    try {
        $db->exec("
            ALTER TABLE location_movements 
            MODIFY COLUMN movement_type VARCHAR(50) NOT NULL 
            COMMENT 'put_away, pick, transfer, adjustment, disposal, return'
        ");
        echo "<p style='color:green'>✓ location_movements.movement_type updated to VARCHAR(50)</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            echo "<p style='color:orange'>⚠ location_movements table doesn't exist</p>";
        } else {
            echo "<p style='color:orange'>⚠ location_movements: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<h2>✅ Migration Complete!</h2>";
    echo "<p><a href='../pos.php'>Go to POS</a></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color:red'>Migration Failed</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
