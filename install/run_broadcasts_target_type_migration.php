<?php
/**
 * Run Broadcasts Target Type Migration
 * Fix target_type column size to support all values
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

$db = Database::getInstance()->getConnection();

echo "Starting broadcasts target_type migration...\n";

try {
    // Read migration file
    $sql = file_get_contents(__DIR__ . '/../database/migration_broadcasts_target_type.sql');
    
    // Execute migration
    $db->exec($sql);
    
    echo "✓ Migration completed successfully!\n";
    echo "✓ broadcasts.target_type column updated to VARCHAR(20)\n";
    
} catch (PDOException $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
