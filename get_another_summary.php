<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();

// ค้นหา BDO อื่นที่ไม่ใช่ 46926 และมีข้อมูล summary
$stmt = $db->prepare("SELECT bdo_id, financial_summary_json FROM odoo_bdo_context WHERE bdo_id != 46926 AND financial_summary_json != '[]' LIMIT 1");
$stmt->execute();
$context = $stmt->fetch(PDO::FETCH_ASSOC);

if ($context) {
    echo "BDO ID: " . $context['bdo_id'] . PHP_EOL;
    echo "Financial Summary: " . PHP_EOL;
    echo json_encode(json_decode($context['financial_summary_json']), JSON_PRETTY_PRINT);
} else {
    echo "No other BDOs with summary found yet.";
}
