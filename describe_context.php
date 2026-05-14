<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$res = $db->query("DESCRIBE odoo_bdo_context");
foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . " " . $row['Null'] . "\n";
}
