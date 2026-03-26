<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();

// ลองค้นชื่อจากหลายๆ ตารางที่อาจเก็บข้อมูล Partner
$tables = ['odoo_partners', 'odoo_customers'];
foreach ($tables as $table) {
    $stmt = $db->prepare("SELECT name FROM $table WHERE partner_id = ? OR id = ? LIMIT 1");
    $stmt->execute([125417, 125417]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        echo "Found in $table: " . $res['name'];
        exit;
    }
}
echo "Name not found in known tables";
