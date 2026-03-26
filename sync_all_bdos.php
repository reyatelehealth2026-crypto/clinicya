<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);
$bdoContextManager = new BdoContextManager($db);

// 1. ดึงรายการ BDO ทั้งหมดจาก Odoo (500 รายการล่าสุด)
echo "Fetching BDO List from Odoo..." . PHP_EOL;
$bdos = $api->getBdoList(null, ['limit' => 500]);

if (empty($bdos)) {
    echo "No BDOs found to sync." . PHP_EOL;
    exit;
}

echo "Found " . count($bdos) . " BDOs. Syncing..." . PHP_EOL;

foreach ($bdos as $bdo) {
    $bdoId = $bdo['bdo_id'];
    $lineUserId = $bdo['line_user_id'] ?? null;
    
    // 2. ดึงรายละเอียด BDO แต่ละตัว
    echo "Syncing BDO ID: $bdoId... ";
    $detail = $api->getBdoDetail($lineUserId, $bdoId);
    
    if ($detail) {
        // เพิ่ม line_account_id = 3
        $detail['line_account_id'] = 3;
        
        // 3. บันทึกข้อมูล
        $bdoContextManager->openContext($detail);
        echo "Success" . PHP_EOL;
    } else {
        echo "Failed" . PHP_EOL;
    }
}
echo "Sync complete.";
