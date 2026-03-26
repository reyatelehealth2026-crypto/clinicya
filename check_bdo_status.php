<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$res = $db->query("SELECT state, amount_total, amount_net_to_pay FROM odoo_bdos WHERE bdo_name = 'BDO2603-02047'")->fetch(PDO::FETCH_ASSOC);
echo json_encode($res, JSON_PRETTY_PRINT);
