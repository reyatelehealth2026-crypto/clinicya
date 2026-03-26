<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$bdo = $conn->query("SELECT id FROM odoo_bdos WHERE bdo_name = 'BDO2603-02047'")->fetch_assoc();
if ($bdo) {
    $id = $bdo['id'];
    $context = $conn->query("SELECT financial_summary_json FROM odoo_bdo_context WHERE bdo_id = '$id'")->fetch_assoc();
    echo $context ? $context['financial_summary_json'] : 'Context empty';
} else {
    echo 'BDO not found';
}
