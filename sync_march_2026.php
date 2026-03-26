<?php
require_once '/www/wwwroot/cny.re-ya.com/config/config.php';
require_once '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
$api = new OdooAPIClient($db, 3);

$bdos = $db->query("SELECT bdo_id FROM odoo_bdos WHERE created_at >= '2026-03-01 00:00:00' OR updated_at >= '2026-03-01 00:00:00'")->fetchAll(PDO::FETCH_ASSOC);

echo "Processing " . count($bdos) . " BDOs for March 2026..." . PHP_EOL;

$synced = 0;
foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    
    $freshData = $api->getBdoDetail(null, $bdoId);
    
    if ($freshData && isset($freshData['data'])) {
        $data = $freshData['data'];
        $bInfo = $data['bdo'];
        $net = (float)($data['summary']['net_to_pay'] ?? $bInfo['amount_net_to_pay'] ?? $bInfo['amount_total'] ?? 0);
        
        $stmt = $db->prepare("UPDATE odoo_bdos SET amount_total = ?, amount_net_to_pay = ?, state = ?, updated_at = NOW() WHERE bdo_id = ?");
        $stmt->execute([$net, $net, $bInfo['state'], $bdoId]);
        
        $data['line_account_id'] = 3;
        $bdoContextManager->openContext($data);
        
        $synced++;
    }
    usleep(300000);
}
echo "Sync complete. Total: $synced BDOs updated.";
