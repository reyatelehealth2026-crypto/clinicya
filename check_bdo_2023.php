<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$bdoName = 'BDO2603-02023';

$stmt = $db->prepare("SELECT bdo_id, amount_total, state, updated_at FROM odoo_bdos WHERE bdo_name = ?");
$stmt->execute([$bdoName]);
$bdo = $stmt->fetch(PDO::FETCH_ASSOC);

if ($bdo) {
    echo json_encode($bdo, JSON_PRETTY_PRINT);
} else {
    echo "BDO $bdoName not found.";
}
