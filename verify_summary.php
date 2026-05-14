<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/modules/Core/Database.php';
use Modules\Core\Database;
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT financial_summary_json FROM odoo_bdo_context WHERE bdo_id = 46926");
$stmt->execute();
$context = $stmt->fetch(PDO::FETCH_ASSOC);
echo $context ? $context['financial_summary_json'] : 'Context empty';
