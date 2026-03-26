<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);
$ctx = new BdoContextManager($db);

$partnerId = 125417;
$bdos = $db->prepare("SELECT bdo_id, bdo_name FROM odoo_bdos WHERE partner_id = ?");
$bdos->execute([$partnerId]);
$list = $bdos->fetchAll(PDO::FETCH_ASSOC);

echo "Syncing " . count($list) . " BDOs for partner $partnerId..." . PHP_EOL;

foreach ($list as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    $fresh = $api->getBdoDetail(null, $bdoId);
    
    if ($fresh && isset($fresh['data'])) {
        $data = $fresh['data'];
        $bInfo = $data['bdo'];
        $net = (float)($data['summary']['net_to_pay'] ?? $bInfo['amount_net_to_pay'] ?? 0);
        
        // อัปเดตตารางหลัก odoo_bdos
        $stmt = $db->prepare("UPDATE odoo_bdos SET amount_total = ?, amount_net_to_pay = ?, state = ?, updated_at = NOW() WHERE bdo_id = ?");
        $stmt->execute([$net, $net, $bInfo['state'], $bdoId]);
        
        // อัปเดต Context
        $data['line_account_id'] = 3;
        $ctx->openContext($data);
        
        echo "BDO {$bdo['bdo_name']} (ID: $bdoId) -> Net: $net, State: {$bInfo['state']}" . PHP_EOL;
    }
    usleep(200000); // กัน Rate Limit
}
echo "Sync complete.";
