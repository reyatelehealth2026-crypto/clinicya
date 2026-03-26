<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$bdo = $db->query("SELECT id FROM odoo_bdos WHERE bdo_name = 'BDO2603-02047'")->fetch(PDO::FETCH_ASSOC);
if ($bdo) {
    $id = $bdo['id'];
    $context = $db->query("SELECT financial_summary_json FROM odoo_bdo_context WHERE bdo_id = '$id'")->fetch(PDO::FETCH_ASSOC);
    echo $context ? $context['financial_summary_json'] : 'Context empty';
} else {
    echo 'BDO not found';
}
