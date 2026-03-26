<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooSyncService.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$syncService = new OdooSyncService($db);

// รัน Backfill สำหรับ BDO (5000 records)
$stats = $syncService->backfillFromWebhookLog(5000, 0);

echo json_encode($stats, JSON_PRETTY_PRINT);
