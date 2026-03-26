<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT COUNT(*) FROM odoo_bdos WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
echo "Total updated recently: " . $stmt->fetchColumn();
