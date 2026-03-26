<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$res = $db->query("SHOW TABLES");
foreach ($res->fetchAll(PDO::FETCH_COLUMN) as $table) {
    echo $table . "\n";
}
