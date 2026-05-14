<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT COUNT(*) FROM odoo_bdos WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
echo "Total updated recently: " . $stmt->fetchColumn();
