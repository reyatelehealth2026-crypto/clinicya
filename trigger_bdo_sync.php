<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoContextManager = new BdoContextManager($db);
$api = new OdooAPIClient($db);

$bdoName = 'BDO2603-02047';

echo "Fetching fresh data for $bdoName..." . PHP_EOL;
$freshData = $api->fetchBdoDetails($bdoName);

if ($freshData) {
    if ($bdoContextManager->openContext($freshData)) {
        echo "Success: BDO Context updated for $bdoName" . PHP_EOL;
    } else {
        echo "Failed: Could not update BDO Context" . PHP_EOL;
    }
} else {
    echo "Failed: Could not fetch fresh BDO data from Odoo" . PHP_EOL;
}
