<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);

$bdos = $db->query("SELECT bdo_id FROM odoo_bdos")->fetchAll(PDO::FETCH_ASSOC);

echo "Processing " . count($bdos) . " BDOs with rich data..." . PHP_EOL;

foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['bdo_id'];
    
    // ดึงข้อมูลจริงจาก Odoo
    $freshData = $api->getBdoDetail(null, $bdoId);
    
    if ($freshData && isset($freshData['summary'])) {
        $summaryJson = json_encode($freshData['summary']);
        
        // อัปเดตตารางตรงๆ
        $stmt = $db->prepare("
            INSERT INTO odoo_bdo_context 
            (bdo_id, line_user_id, financial_summary_json, line_account_id, updated_at) 
            VALUES (?, ?, ?, 3, NOW())
            ON DUPLICATE KEY UPDATE 
                financial_summary_json = ?, 
                line_account_id = 3, 
                updated_at = NOW()
        ");
        // สมมติว่าได้ line_user_id มาจากข้อมูล bdo
        $lineUserId = $freshData['bdo']['line_user_id'] ?? null;
        $stmt->execute([$bdoId, $lineUserId, $summaryJson, $summaryJson]);
        
        echo "BDO $bdoId: Synced Rich Data Successfully." . PHP_EOL;
    } else {
        echo "BDO $bdoId: Failed to fetch rich data" . PHP_EOL;
    }
}
echo "Full sync complete.";
