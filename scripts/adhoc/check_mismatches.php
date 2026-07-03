<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
require __DIR__ . '/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);

$bdos = $db->query("SELECT bdo_id, bdo_name, amount_total FROM odoo_bdos WHERE state != 'cancel'")->fetchAll(PDO::FETCH_ASSOC);

echo "Checking " . count($bdos) . " BDOs for discrepancies..." . PHP_EOL;

$mismatched = [];
foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    $localAmount = (float)$bdo['amount_total'];
    
    $fresh = $api->getBdoDetail(null, $bdoId);
    if ($fresh && isset($fresh['data']['summary']['net_to_pay'])) {
        $apiAmount = (float)$fresh['data']['summary']['net_to_pay'];
        
        if (abs($localAmount - $apiAmount) > 0.01) {
            $mismatched[] = [
                'bdo_name' => $bdo['bdo_name'],
                'local' => $localAmount,
                'api' => $apiAmount
            ];
        }
    }
    usleep(100000); // กัน rate limit
}

echo json_encode($mismatched, JSON_PRETTY_PRINT);
