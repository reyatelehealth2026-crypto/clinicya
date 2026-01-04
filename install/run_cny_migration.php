<?php
/**
 * Run CNY Products Migration
 * Creates the cny_products table
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Running CNY Products migration...\n";
    
    $sql = file_get_contents(__DIR__ . '/../database/migration_cny_products.sql');
    
    $db->exec($sql);
    
    echo "✓ Migration completed successfully!\n";
    echo "Table 'cny_products' is ready.\n";
    echo "\nNext step: Run sync script to populate data:\n";
    echo "php cron/sync_cny_products.php\n";
    
} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
