<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$query = 'SELECT id, bdo_name, amount_total, amount_net_to_pay FROM odoo_bdos WHERE bdo_name = "BDO2603-02047"';
$res = $conn->query($query);
echo json_encode($res->fetch_assoc(), JSON_PRETTY_PRINT);
