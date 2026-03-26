<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);

$bdos = $db->query("SELECT id, bdo_id FROM odoo_bdos")->fetchAll(PDO::FETCH_ASSOC);

foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    $localId = (int)$bdo['id'];
    
    $freshData = $api->getBdoDetail(null, $bdoId);
    
    if ($freshData && isset($freshData['data'])) {
        $netToPay = (float)($freshData['data']['summary']['net_to_pay'] ?? $freshData['data']['bdo']['amount_net_to_pay'] ?? 0);
        
        // อัปเดตทั้ง amount_total และ amount_net_to_pay
        $stmt = $db->prepare("UPDATE odoo_bdos SET amount_total = ?, amount_net_to_pay = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$netToPay, $netToPay, $localId]);
    }
}
echo "Sync complete: amount_net_to_pay updated.";
