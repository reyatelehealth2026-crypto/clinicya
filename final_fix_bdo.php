<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
$api = new OdooAPIClient($db);

// 1. ดึงข้อมูล bdo_id และ line_user_id
$stmt = $db->prepare("SELECT id, line_user_id FROM odoo_bdos WHERE bdo_name = 'BDO2603-02047'");
$stmt->execute();
$bdoData = $stmt->fetch(PDO::FETCH_ASSOC);

if ($bdoData) {
    $bdoId = (int)$bdoData['id'];
    $lineUserId = $bdoData['line_user_id'];
    
    // 2. ดึงข้อมูลสดจาก Odoo
    $freshData = $api->getBdoDetail($lineUserId, $bdoId);
    
    // 3. อัปเดต Context
    if ($freshData) {
        $bdoContextManager->openContext($freshData);
        echo 'Successfully synchronized BDO2603-02047';
    } else {
        echo 'Failed to fetch fresh data from Odoo';
    }
} else {
    echo 'BDO not found';
}
