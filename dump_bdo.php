<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
require __DIR__ . '/classes/OdooAPIClient.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$api = new OdooAPIClient($db);
$freshData = $api->getBdoDetail(null, 46926);
var_dump($freshData);
