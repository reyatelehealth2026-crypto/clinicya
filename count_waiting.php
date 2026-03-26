<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$res = $db->query("SELECT COUNT(*) FROM odoo_bdos WHERE state = 'waiting'");
echo "Total waiting BDOs: " . $res->fetchColumn();
