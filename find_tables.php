<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$res = $db->query("SHOW TABLES");
foreach ($res->fetchAll(PDO::FETCH_COLUMN) as $table) {
    echo $table . "\n";
}
