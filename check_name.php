<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
// ลองค้นชื่อจาก odoo_orders เพราะมี partner_id และ salesperson_name อยู่
$res = $db->query("SELECT order_name FROM odoo_orders WHERE partner_id = 125417 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo json_encode($res, JSON_PRETTY_PRINT);
