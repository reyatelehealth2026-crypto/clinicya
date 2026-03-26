<?php
require_once '/www/wwwroot/cny.re-ya.com/config/config.php';
require_once '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
$api = new OdooAPIClient($db);

// ค้นหา BDO ที่อัปเดตใน 10 วันที่ผ่านมา
$stmt = $db->prepare("SELECT bdo_id FROM odoo_bdos WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 10 DAY)");
$stmt->execute();
$bdos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($bdos) . " BDOs to sync from the last 10 days." . PHP_EOL;

$syncedCount = 0;
foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    $freshData = $api->getBdoDetail(null, $bdoId);
    
    if ($freshData && isset($freshData['data'])) {
        $data = $freshData['data'];
        $bdoInfo = $data['bdo'];
        $amount = (float)($data['summary']['net_to_pay'] ?? $bdoInfo['amount_net_to_pay'] ?? $bdoInfo['amount_total'] ?? 0);
        
        $db->prepare("UPDATE odoo_bdos SET amount_total = ?, state = ?, updated_at = NOW() WHERE bdo_id = ?")
           ->execute([$amount, $bdoInfo['state'], $bdoId]);
        
        $data['line_account_id'] = 3;
        $bdoContextManager->openContext($data);
        
        $syncedCount++;
        echo "BDO $bdoId: Synced. Amount: $amount" . PHP_EOL;
    }
}
echo "Sync complete. Total: $syncedCount BDOs updated.";
