<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$res = $db->query("DESCRIBE odoo_bdos");
$fields = [];
foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $row) { $fields[] = $row['Field']; }
echo implode(',', $fields);
