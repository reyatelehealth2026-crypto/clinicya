<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$query = "SELECT c.financial_summary_json FROM odoo_bdo_context c 
             JOIN odoo_bdos b ON c.bdo_id = b.id 
             WHERE b.bdo_name = 'BDO2603-02047'";
$res = $conn->query($query);
$cache = $res->fetch_assoc();
echo $cache ? $cache['financial_summary_json'] : 'Not found';
