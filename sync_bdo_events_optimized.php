<?php
require_once '/www/wwwroot/cny.re-ya.com/config/config.php';
require_once '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/OdooSyncService.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$syncService = new OdooSyncService($db);

$stmt = $db->prepare("SELECT id, event_type, payload FROM odoo_webhooks_log WHERE event_type IN ('bdo.confirmed', 'bdo.done') ORDER BY id ASC");
$stmt->execute();

$processed = 0;
while ($log = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $payload = json_decode($log['payload'], true);
    if ($payload) {
        $syncService->syncWebhook($payload, $log['event_type'], $log['id']);
        $processed++;
        if ($processed % 100 === 0) echo "Processed $processed events..." . PHP_EOL;
    }
}
echo "Sync complete. Total: $processed events processed.";
