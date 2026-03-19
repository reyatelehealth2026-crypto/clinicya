<?php
/**
 * Migration: Add odoo_phone and odoo_email columns to odoo_line_users table
 * Run once on the server, then delete this file.
 * 
 * Usage: php api/migrate_odoo_phone_email.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Check if columns already exist
    $cols = $db->query("SHOW COLUMNS FROM odoo_line_users")->fetchAll(PDO::FETCH_COLUMN);

    $added = [];

    if (!in_array('odoo_phone', $cols)) {
        $db->exec("ALTER TABLE odoo_line_users ADD COLUMN odoo_phone VARCHAR(50) DEFAULT NULL AFTER odoo_customer_code");
        $added[] = 'odoo_phone';
    }

    if (!in_array('odoo_email', $cols)) {
        $db->exec("ALTER TABLE odoo_line_users ADD COLUMN odoo_email VARCHAR(255) DEFAULT NULL AFTER odoo_phone");
        $added[] = 'odoo_email';
    }

    if (count($added) > 0) {
        echo "SUCCESS: Added columns: " . implode(', ', $added) . "\n";
    } else {
        echo "OK: Columns already exist, no changes needed.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
