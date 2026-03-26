<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$res = $db->query("SELECT name FROM odoo_customers_cache WHERE partner_id = 125417 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($res) {
    echo "Found in odoo_customers_cache: " . $res['name'];
} else {
    // ลองหาในตาราง users เผื่อมีชื่อ
    $res2 = $db->query("SELECT name FROM users WHERE id = 125417 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($res2) {
        echo "Found in users: " . $res2['name'];
    } else {
        echo "Name not found";
    }
}
