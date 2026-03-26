<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);

$bdos = $db->query("SELECT bdo_id FROM odoo_bdos")->fetchAll(PDO::FETCH_ASSOC);

foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    $freshData = $api->getBdoDetail(null, $bdoId);
    
    if ($freshData && isset($freshData['data']['bdo'])) {
        $bdoInfo = $freshData['data']['bdo'];
        $netToPay = (float)($bdoInfo['amount_net_to_pay'] ?? $bdoInfo['amount_total'] ?? 0);
        
        // อัปเดต odoo_bdos ให้ amount_total เป็นค่า net_to_pay เพื่อให้ Dashboard แสดงผลได้ถูกต้องตามที่ Jame ต้องการ
        $stmt = $db->prepare("UPDATE odoo_bdos SET amount_total = ?, updated_at = NOW() WHERE bdo_id = ?");
        $stmt->execute([$netToPay, $bdoId]);
        
        echo "BDO $bdoId: Amount set to $netToPay" . PHP_EOL;
    }
}
echo "Sync complete.";
