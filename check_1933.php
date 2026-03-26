<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoName = 'BDO2603-01933';

// 1. ดึงจากตารางหลัก odoo_bdos
$bdo = $db->prepare("SELECT * FROM odoo_bdos WHERE bdo_name = ?");
$bdo->execute([$bdoName]);
$bdoData = $bdo->fetch(PDO::FETCH_ASSOC);

// 2. ดึงจาก odoo_bdo_context (ถ้ามี)
$contextData = null;
if ($bdoData) {
    $ctx = $db->prepare("SELECT * FROM odoo_bdo_context WHERE bdo_id = ?");
    $ctx->execute([$bdoData['id']]); // ID จาก odoo_bdos น่าจะเป็น local ID
    $contextData = $ctx->fetch(PDO::FETCH_ASSOC);
}

echo json_encode(['bdo_table' => $bdoData, 'context_table' => $contextData], JSON_PRETTY_PRINT);
