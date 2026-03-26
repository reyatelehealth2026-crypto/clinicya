<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoId = 46926; // BDO ID จริง

// Force Update Context ให้ยอดเป็น 13468.00 ตามจริง
$stmt = $db->prepare("
    UPDATE odoo_bdo_context 
    SET amount = 13468.00, updated_at = NOW()
    WHERE bdo_id = ?
");
$stmt->execute([$bdoId]);

// บังคับให้ตาราง odoo_bdos (ที่ Dashboard ใช้อ้างอิง) มีข้อมูลตรงกัน
$stmt = $db->prepare("
    UPDATE odoo_bdos 
    SET amount_total = 13468.00, updated_at = NOW()
    WHERE bdo_id = ?
");
$stmt->execute([$bdoId]);

echo "Force Sync Complete: BDO ID $bdoId updated to 13468.00 in both tables.";
