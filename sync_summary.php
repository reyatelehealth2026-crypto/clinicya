<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();

$today = date('Y-m-d 00:00:00');
$stmt = $db->prepare("SELECT bdo_id, amount FROM odoo_bdo_context WHERE updated_at >= ?");
$stmt->execute([$today]);
$synced = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total BDOs synced today: " . count($synced) . PHP_EOL;
echo json_encode($synced, JSON_PRETTY_PRINT);
