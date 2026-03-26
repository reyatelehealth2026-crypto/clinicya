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
    
    if ($freshData && isset($freshData['data']['summary'])) {
        $summaryJson = json_encode($freshData['data']['summary']);
        $lineUserId = $freshData['data']['bdo']['line_user_id'] ?? null;
        
        $stmt = $db->prepare("
            INSERT INTO odoo_bdo_context (bdo_id, line_user_id, financial_summary_json, line_account_id, updated_at)
            VALUES (?, ?, ?, 3, NOW())
            ON DUPLICATE KEY UPDATE 
                financial_summary_json = ?, 
                updated_at = NOW()
        ");
        $stmt->execute([$bdoId, $lineUserId, $summaryJson, $summaryJson]);
    }
    usleep(200000); // กัน Rate Limit
}
echo "Completed sync for all BDOs.";
