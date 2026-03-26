<?php
require_once '/www/wwwroot/cny.re-ya.com/config/config.php';
require_once '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
$api = new OdooAPIClient($db);

$bdos = $db->query("SELECT id, bdo_id FROM odoo_bdos")->fetchAll(PDO::FETCH_ASSOC);

echo "Processing " . count($bdos) . " BDOs..." . PHP_EOL;

foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    $localId = (int)$bdo['id'];
    
    $freshData = $api->getBdoDetail(null, $bdoId);
    
    if ($freshData && isset($freshData['bdo'])) {
        $bdoInfo = $freshData['bdo'];
        $amount = (float)($bdoInfo['amount_net_to_pay'] ?? $bdoInfo['amount_total'] ?? 0);
        
        $stmt1 = $db->prepare("UPDATE odoo_bdos SET amount_total = ?, updated_at = NOW() WHERE id = ?");
        $stmt1->execute([$amount, $localId]);
        
        $freshData['line_account_id'] = 3;
        $bdoContextManager->openContext($freshData);
        
        echo "BDO $bdoId: $amount" . PHP_EOL;
    }
}
echo "Sync complete.";
