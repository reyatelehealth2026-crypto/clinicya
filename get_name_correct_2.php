<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT customer_name FROM odoo_customers_cache WHERE customer_id = ? LIMIT 1");
$stmt->execute([125417]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);

if ($res) {
    echo "Found (customer_id): " . $res['customer_name'];
} else {
    echo "Name not found in odoo_customers_cache";
}
