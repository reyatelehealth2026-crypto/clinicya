<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
try {
    $db->exec('ALTER TABLE odoo_bdo_context ADD COLUMN IF NOT EXISTS line_account_id INT NULL');
    echo 'Column line_account_id ready.';
} catch (Exception $e) {
    echo 'Error adding column: ' . $e->getMessage();
}
