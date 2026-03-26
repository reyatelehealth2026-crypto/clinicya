<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
 = 'SELECT id, bdo_name, amount_total, amount_net_to_pay FROM odoo_bdos WHERE bdo_name = BDO2603-02047';
 = ->query();
echo json_encode(->fetch_assoc(), JSON_PRETTY_PRINT);
