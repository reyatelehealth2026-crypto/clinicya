<?php
require '/www/wwwroot/cny.re-ya.com/config/config.php';
require '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require '/www/wwwroot/cny.re-ya.com/classes/OdooSyncService.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$syncService = new OdooSyncService($db);

// ดึง webhook logs ทั้งหมดที่เกี่ยวข้องกับ BDO
$stmt = $db->prepare("SELECT id, event_type, payload FROM odoo_webhooks_log WHERE event_type IN ('bdo.confirmed', 'bdo.done') ORDER BY id ASC");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Processing " . count($logs) . " BDO events..." . PHP_EOL;

foreach ($logs as $log) {
    $payload = json_decode($log['payload'], true);
    if ($payload) {
        $syncService->syncWebhook($payload, $log['event_type'], $log['id']);
        echo "Processed Event: " . $log['event_type'] . " | Webhook ID: " . $log['id'] . PHP_EOL;
    }
}
echo "Sync complete.";
