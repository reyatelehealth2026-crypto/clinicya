<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/classes/BdoContextManager.php';

$bdoName = 'BDO2603-02047';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// 1. Get Cached Data
$query = "SELECT c.financial_summary_json FROM odoo_bdo_context c 
             JOIN odoo_bdos b ON c.bdo_id = b.id 
             WHERE b.bdo_name = '$bdoName'";
$res = $conn->query($query);
$cache = $res->fetch_assoc();

// 2. Get Live Data
$live = BdoContextManager::fetchBdoFromOdoo($bdoName);

echo '--- CACHE ---' . PHP_EOL;
echo $cache ? $cache['financial_summary_json'] : 'Not found';
echo PHP_EOL . '--- LIVE ---' . PHP_EOL;
echo json_encode($live['financial_summary'] ?? 'Not found', JSON_PRETTY_PRINT);
