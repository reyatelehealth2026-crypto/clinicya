<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
try {
    $db->exec('ALTER TABLE odoo_bdo_context ADD COLUMN IF NOT EXISTS line_account_id INT NULL');
    echo 'Column line_account_id ready.';
} catch (Exception $e) {
    echo 'Error adding column: ' . $e->getMessage();
}
