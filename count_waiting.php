<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$res = $db->query("SELECT COUNT(*) FROM odoo_bdos WHERE state = 'waiting'");
echo "Total waiting BDOs: " . $res->fetchColumn();
