<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
$api = new OdooAPIClient($db);

$bdoId = 2265; // จากข้อมูลที่ค้นพบก่อนหน้า
$lineUserId = 'U4c7ffa843bfda6f40f774193aacc0757';

echo "Fetching fresh data for BDO ID: $bdoId..." . PHP_EOL;
$freshData = $api->getBdoDetail($lineUserId, $bdoId);

if ($freshData) {
    // บังคับอัปเดต Context แม้ line_user_id หรือ bdo_id จะเป็นค่าเดิม
    if ($bdoContextManager->openContext($freshData)) {
        echo "Success: BDO Context updated for BDO ID: $bdoId" . PHP_EOL;
    } else {
        echo "Failed: Could not update BDO Context" . PHP_EOL;
    }
} else {
    echo "Failed: Could not fetch fresh BDO data from Odoo" . PHP_EOL;
}
