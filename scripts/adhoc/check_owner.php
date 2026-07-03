<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$bdo = $db->query("SELECT bdo_name, partner_id, line_user_id FROM odoo_bdos WHERE bdo_name = 'BDO2603-02047'")->fetch(PDO::FETCH_ASSOC);
echo json_encode($bdo, JSON_PRETTY_PRINT);
