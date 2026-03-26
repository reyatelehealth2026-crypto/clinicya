<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();

// 1. ตรวจสอบ Column ที่ต้องเพิ่ม
$bdosCols = $db->query("DESCRIBE odoo_bdos")->fetchAll(PDO::FETCH_COLUMN);
$ctxCols = $db->query("DESCRIBE odoo_bdo_context")->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff($ctxCols, $bdosCols);

// 2. ข้อมูลสรุป BDO ที่จะทำการย้าย
$stmt = $db->query("
    SELECT 
        b.bdo_id, 
        b.bdo_name, 
        b.amount_total,
        c.financial_summary_json,
        c.selected_invoices_json
    FROM odoo_bdos b
    LEFT JOIN odoo_bdo_context c ON b.bdo_id = c.bdo_id
    WHERE c.financial_summary_json IS NOT NULL OR c.selected_invoices_json IS NOT NULL
    LIMIT 10
");
$sample = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Missing columns in odoo_bdos: " . implode(', ', $missing) . PHP_EOL;
echo "Sample migration data (10 items):" . PHP_EOL;
echo json_encode($sample, JSON_PRETTY_PRINT);
