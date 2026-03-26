<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);

// 1. ค้นหา BDO ทั้งหมดที่มีสถานะ waiting
$bdos = $db->query("SELECT id, line_user_id FROM odoo_bdos WHERE state = 'waiting'")->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($bdos) . " waiting BDOs. Starting sync..." . PHP_EOL;

foreach ($bdos as $bdo) {
    $bdoId = (int)$bdo['id'];
    $lineUserId = $bdo['line_user_id'];
    
    echo "Processing BDO ID: $bdoId... ";
    
    // 2. ดึงข้อมูลสดจาก Odoo
    $freshData = $api->getBdoDetail($lineUserId, $bdoId);
    
    if ($freshData && isset($freshData['summary'])) {
        $summaryJson = json_encode($freshData['summary']);
        
        // 3. บังคับอัปเดต Context (พร้อม line_account_id = 3)
        $stmt = $db->prepare("
            INSERT INTO odoo_bdo_context 
            (bdo_id, line_user_id, financial_summary_json, line_account_id, updated_at) 
            VALUES (?, ?, ?, 3, NOW())
            ON DUPLICATE KEY UPDATE 
                financial_summary_json = ?, 
                line_account_id = 3, 
                updated_at = NOW()
        ");
        $stmt->execute([$bdoId, $lineUserId, $summaryJson, $summaryJson]);
        echo "Success" . PHP_EOL;
    } else {
        echo "Failed (No data)" . PHP_EOL;
    }
}
echo "Bulk sync complete.";
