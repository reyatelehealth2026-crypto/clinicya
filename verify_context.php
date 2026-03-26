<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM odoo_bdo_context WHERE bdo_id = 46926");
$stmt->execute();
$context = $stmt->fetch(PDO::FETCH_ASSOC);

if ($context) {
    echo json_encode($context, JSON_PRETTY_PRINT);
} else {
    echo "Context not found";
}
