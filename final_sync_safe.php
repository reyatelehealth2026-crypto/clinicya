<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/Core/Database.php';
require_once __DIR__ . '/classes/OdooSyncService.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$syncService = new OdooSyncService($db);

// จำกัดแค่ 500 รายการล่าสุด เพื่อไม่ให้ memory พัง
$stmt = $db->prepare("SELECT id, event_type, payload FROM odoo_webhooks_log WHERE event_type IN ('bdo.confirmed', 'bdo.done') ORDER BY id DESC LIMIT 500");
$stmt->execute();

$processed = 0;
while ($log = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $payload = json_decode($log['payload'], true);
    if ($payload) {
        $syncService->syncWebhook($payload, $log['event_type'], $log['id']);
        $processed++;
    }
    // บังคับเคลียร์ memory
    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
}
echo "Sync complete. Processed $processed events.";
