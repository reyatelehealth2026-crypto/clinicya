<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooAPIClient.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);
$freshData = $api->getBdoDetail(null, 46926);
var_dump($freshData);
