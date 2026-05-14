<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();

$stmt = $db->query("
    SELECT b.bdo_name, b.amount_net_to_pay, b.state, c.customer_name 
    FROM odoo_bdos b 
    LEFT JOIN odoo_customers_cache c ON b.partner_id = c.partner_id 
    ORDER BY b.updated_at DESC 
    LIMIT 10
");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
