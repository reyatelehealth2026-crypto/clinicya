<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();

$bdoName = 'BDO2603-02047';
$newAmount = 13468.00;

// อัปเดตตารางหลัก odoo_bdos ให้ตรงกับยอดเงินล่าสุด
$stmt = $db->prepare("UPDATE odoo_bdos SET amount_total = ? WHERE bdo_name = ?");
$stmt->execute([$newAmount, $bdoName]);

echo "Updated odoo_bdos for $bdoName. New Amount: $newAmount";
