<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$res = $db->query("SHOW INDEX FROM odoo_webhooks_log");
echo json_encode($res->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
