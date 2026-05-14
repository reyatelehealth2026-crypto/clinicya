<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$db->exec('ALTER TABLE odoo_bdo_context MODIFY line_user_id VARCHAR(255) NULL');
$db->exec('ALTER TABLE odoo_bdo_context MODIFY line_account_id INT NULL');
echo 'Constraints relaxed.';
