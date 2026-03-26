<?php
require_once '/www/wwwroot/cny.re-ya.com/config/config.php';
require_once '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
// Force default account ID to 3 as requested
$api = new OdooAPIClient($db, 3);

$bdos = $db->query("SELECT bdo_id FROM odoo_bdos")->fetchAll(PDO::FETCH_ASSOC);

echo "Syncing " . count($bdos) . " total BDOs from Odoo API..." . PHP_EOL;

$synced = 0;
foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    
    // ดึงข้อมูลสดจาก Odoo (API-First)
    $freshData = $api->getBdoDetail(null, $bdoId);
    
    if ($freshData && isset($freshData['data'])) {
        $data = $freshData['data'];
        $bInfo = $data['bdo'];
        $net = (float)($data['summary']['net_to_pay'] ?? $bInfo['amount_net_to_pay'] ?? $bInfo['amount_total'] ?? 0);
        
        // อัปเดตตารางหลัก odoo_bdos
        $stmt = $db->prepare("UPDATE odoo_bdos SET amount_total = ?, amount_net_to_pay = ?, state = ?, updated_at = NOW() WHERE bdo_id = ?");
        $stmt->execute([$net, $net, $bInfo['state'], $bdoId]);
        
        // อัปเดต Context (ให้ข้อมูล Rich Data ครบตามโครงสร้าง API)
        $data['line_account_id'] = 3;
        $bdoContextManager->openContext($data);
        
        $synced++;
        if ($synced % 100 === 0) echo "Processed $synced BDOs..." . PHP_EOL;
    }
    usleep(250000); // 250ms delay
}
echo "Full Sync complete. Processed: $synced BDOs.";
EOF
,file: