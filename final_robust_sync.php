<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);
$ctx = new BdoContextManager($db);

$bdos = $db->query("SELECT bdo_id FROM odoo_bdos")->fetchAll(PDO::FETCH_ASSOC);

echo "Starting robust sync for " . count($bdos) . " BDOs..." . PHP_EOL;

foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    
    $freshData = null;
    try {
        $freshData = $api->getBdoDetail(null, $bdoId);
    } catch (Exception $e) {
        if ($e->getMessage() === 'RATE_LIMIT_EXCEEDED') {
            echo "Rate limit hit, waiting 60s..." . PHP_EOL;
            sleep(60);
            $freshData = $api->getBdoDetail(null, $bdoId);
        }
    }
    
    if ($freshData && isset($freshData['data'])) {
        $data = $freshData['data'];
        $bInfo = $data['bdo'];
        $net = (float)($data['summary']['net_to_pay'] ?? $bInfo['amount_net_to_pay'] ?? $bInfo['amount_total'] ?? 0);
        
        $stmt = $db->prepare("UPDATE odoo_bdos SET amount_total = ?, amount_net_to_pay = ?, state = ?, updated_at = NOW() WHERE bdo_id = ?");
        $stmt->execute([$net, $net, $bInfo['state'], $bdoId]);
        
        $data['line_account_id'] = 3;
        $ctx->openContext($data);
        
        echo "BDO $bdoId: Synced. Net: $net" . PHP_EOL;
    }
    usleep(500000); // เพิ่ม delay เป็น 0.5s เพื่อความปลอดภัย
}
echo "Sync complete.";
