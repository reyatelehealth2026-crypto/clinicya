<?php
$_SERVER['HTTP_HOST'] = 'cny.re-ya.com';
require_once 'config/config.php';
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

// Find webhook for BDO 46926
$stmt = $db->prepare("
    SELECT id, event_type, payload 
    FROM odoo_webhooks_log 
    WHERE event_type LIKE 'bdo.%' 
    AND JSON_EXTRACT(payload, '$.bdo_id') = 46926 
    ORDER BY id DESC 
    LIMIT 1
");
$stmt->execute();
$webhook = $stmt->fetch(PDO::FETCH_ASSOC);

if ($webhook) {
    echo "Found webhook ID: " . $webhook['id'] . "\n";
    echo "Event: " . $webhook['event_type'] . "\n";
    $payload = json_decode($webhook['payload'], true);
    echo "BDO ID: " . ($payload['bdo_id'] ?? 'N/A') . "\n";
    echo "BDO Name: " . ($payload['bdo_name'] ?? 'N/A') . "\n";
    echo "Amount Total: " . ($payload['amount_total'] ?? 'N/A') . "\n";
    echo "Amount Net to Pay: " . ($payload['amount_net_to_pay'] ?? ($payload['financial_summary']['amount_net_to_pay'] ?? 'N/A')) . "\n";
    
    // Check if financial_summary exists
    if (isset($payload['financial_summary'])) {
        echo "\nFinancial Summary:\n";
        print_r($payload['financial_summary']);
    }
    
    // Now re-sync this BDO
    echo "\n--- Re-syncing BDO ---\n";
    require_once 'classes/OdooSyncService.php';
    $syncService = new OdooSyncService($db);
    $result = $syncService->syncWebhook($payload, $webhook['event_type'], $webhook['id']);
    echo "Sync result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
    
    // Check the updated record
    $stmt2 = $db->prepare("SELECT bdo_id, bdo_name, amount_total, amount_net_to_pay FROM odoo_bdos WHERE bdo_id = 46926");
    $stmt2->execute();
    echo "\nUpdated BDO record:\n";
    print_r($stmt2->fetch(PDO::FETCH_ASSOC));
} else {
    echo "No webhook found for BDO 46926\n";
}
