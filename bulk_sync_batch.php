<?php
require_once '/www/wwwroot/cny.re-ya.com/config/config.php';
require_once '/www/wwwroot/cny.re-ya.com/modules/Core/Database.php';
require_once '/www/wwwroot/cny.re-ya.com/classes/OdooSyncService.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$syncService = new OdooSyncService($db);

$batchSize = 100;
$offset = 0;
$totalProcessed = 0;

echo "Starting Batch Sync..." . PHP_EOL;

while (true) {
    $stmt = $db->prepare("SELECT id, event_type, payload FROM odoo_webhooks_log WHERE event_type LIKE 'bdo.%' LIMIT ? OFFSET ?");
    $stmt->execute([$batchSize, $offset]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($logs)) break;

    foreach ($logs as $log) {
        $payload = json_decode($log['payload'], true);
        if ($payload) {
            $syncService->syncWebhook($payload, $log['event_type'], $log['id']);
        }
    }
    
    $totalProcessed += count($logs);
    $offset += $batchSize;
    echo "Processed $totalProcessed logs..." . PHP_EOL;
}
echo "Batch sync complete." . PHP_EOL;
