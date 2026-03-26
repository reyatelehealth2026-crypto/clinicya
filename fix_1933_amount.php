<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);
$bdoContextManager = new BdoContextManager($db);

$bdoId = 46812; // BDO ID จริงของ 1933
$lineUserId = 'U4c7ffa843bfda6f40f774193aacc0757';

$freshData = $api->getBdoDetail($lineUserId, $bdoId);

if ($freshData && isset($freshData['data'])) {
    $data = $freshData['data'];
    $netToPay = (float)($data['summary']['net_to_pay'] ?? $data['bdo']['amount_net_to_pay'] ?? 1774.0);
    
    // อัปเดตตารางหลัก
    $stmt = $db->prepare("UPDATE odoo_bdos SET amount_total = ? WHERE bdo_id = ?");
    $stmt->execute([$netToPay, $bdoId]);
    
    // อัปเดต Context
    $data['line_account_id'] = 3;
    $bdoContextManager->openContext($data);
    
    echo "Successfully updated BDO $bdoId to amount: $netToPay";
} else {
    echo "Failed to fetch/sync BDO $bdoId";
}
