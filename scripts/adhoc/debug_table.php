<?php
require __DIR__ . '/config/config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$res = $conn->query('DESCRIBE odoo_bdos');
while ($row = $res->fetch_assoc()) { echo $row['Field'] . " "; }
