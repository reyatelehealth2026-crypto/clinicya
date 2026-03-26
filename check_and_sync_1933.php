<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
$api = new OdooAPIClient($db);

$bdoName = 'BDO2603-01933';

// 1. ดึงข้อมูล
$stmt = $db->prepare("SELECT id, line_user_id FROM odoo_bdos WHERE bdo_name = ?");
$stmt->execute([$bdoName]);
$bdo = $stmt->fetch(PDO::FETCH_ASSOC);

if ($bdo) {
    $bdoId = (int)$bdo['id'];
    $lineUserId = $bdo['line_user_id'];
    
    echo "Fetching fresh data for $bdoName (ID: $bdoId)..." . PHP_EOL;
    $freshData = $api->getBdoDetail($lineUserId, $bdoId);
    
    if ($freshData) {
        // อัปเดต Context
        $freshData['line_account_id'] = 3;
        $bdoContextManager->openContext($freshData);
        
        echo "Correct Amount (net_to_pay): " . ($freshData['bdo']['amount_net_to_pay'] ?? 'N/A') . PHP_EOL;
        echo "Context updated successfully.";
    } else {
        echo "Failed: Could not fetch fresh BDO data";
    }
} else {
    echo "BDO $bdoName not found";
}
