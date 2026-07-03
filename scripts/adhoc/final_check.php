<?php
require __DIR__ . '/config/config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$bdo = $conn->query("SELECT id FROM odoo_bdos WHERE bdo_name = 'BDO2603-02047'")->fetch_assoc();
if ($bdo) {
    $id = $bdo['id'];
    $context = $conn->query("SELECT * FROM odoo_bdo_context WHERE bdo_id = '$id'")->fetch_assoc();
    echo $context ? json_encode($context, JSON_PRETTY_PRINT) : 'Context not found';
} else {
    echo 'BDO not found';
}
