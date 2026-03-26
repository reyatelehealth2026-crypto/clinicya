<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();

// เลือก BDO รายการอื่นที่ไม่ใช่ 46926
$stmt = $db->query("SELECT bdo_id, bdo_name, amount_total, state, updated_at FROM odoo_bdos WHERE bdo_id != 46926 ORDER BY updated_at DESC LIMIT 1");
$bdo = $stmt->fetch(PDO::FETCH_ASSOC);

if ($bdo) {
    echo json_encode($bdo, JSON_PRETTY_PRINT);
} else {
    echo "No other BDO found.";
}
