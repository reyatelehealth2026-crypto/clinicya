<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();

$bdoId = 46926; // ใช้ BDO ID จริงจาก JSON
$lineUserId = 'U4c7ffa843bfda6f40f774193aacc0757';
$amountNetToPay = 13468.00;

// อัปเดต Context บังคับให้ยอดตรง
$stmt = $db->prepare("
    INSERT INTO odoo_bdo_context 
    (bdo_id, line_user_id, amount, state, updated_at) 
    VALUES (?, ?, ?, 'waiting', NOW())
    ON DUPLICATE KEY UPDATE amount = ?, updated_at = NOW()
");
$stmt->execute([$bdoId, $lineUserId, $amountNetToPay, $amountNetToPay]);

echo "Forced update complete. BDO ID: $bdoId, Amount: $amountNetToPay";
