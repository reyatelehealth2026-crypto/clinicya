<?php
/**
 * Odoo Dashboard Local Cache Verification Tool
 * Run this to check if the local cache system is working properly
 * 
 * URL: /verify_odoo_local_cache.php
 * CLI: php verify_odoo_local_cache.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$isCli = php_sapi_name() === 'cli';
$results = [];
$allPass = true;

function check($name, $pass, $message = '') {
    global $results, $allPass;
    $results[] = ['name' => $name, 'pass' => $pass, 'message' => $message];
    if (!$pass) $allPass = false;
    return $pass;
}

function output($msg) {
    global $isCli;
    if ($isCli) {
        echo $msg . "\n";
    }
}

try {
    $db = Database::getInstance()->getConnection();
    output("✓ Database connection successful");
    
    // Check 1: Tables exist
    $tables = [
        'odoo_orders_summary',
        'odoo_customers_cache', 
        'odoo_invoices_cache',
        'odoo_slips_cache',
        'odoo_order_events',
        'odoo_dashboard_cache_meta',
        'odoo_sync_log'
    ];
    
    $existingTables = [];
    foreach ($tables as $table) {
        $exists = $db->query("SHOW TABLES LIKE '{$table}'")->rowCount() > 0;
        check("Table: {$table}", $exists, $exists ? 'Exists' : 'MISSING - run migration');
        if ($exists) $existingTables[] = $table;
    }
    
    if (empty($existingTables)) {
        check("Migration Status", false, "No local tables found. Please run: database/migration_odoo_dashboard_cache.sql");
    }
    
    // Check 2: Table data counts
    $counts = [];
    foreach ($existingTables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            $counts[$table] = (int)$count;
            $hasData = $count > 0;
            check("Data in {$table}", $hasData, $hasData ? "{$count} rows" : "Empty - needs sync");
        } catch (Exception $e) {
            check("Data in {$table}", false, "Error: " . $e->getMessage());
        }
    }
    
    // Check 3: Last sync job
    try {
        $lastSync = $db->query("
            SELECT id, job_type, started_at, status, records_processed 
            FROM odoo_sync_log 
            ORDER BY id DESC LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        
        if ($lastSync) {
            $syncTime = strtotime($lastSync['started_at']);
            $hoursAgo = round((time() - $syncTime) / 3600, 1);
            $statusOk = $lastSync['status'] === 'success';
            
            check("Last Sync Job", $statusOk, 
                "Job #{$lastSync['id']} ({$lastSync['job_type']}) at {$lastSync['started_at']} ({$hoursAgo}h ago) - {$lastSync['records_processed']} records - Status: {$lastSync['status']}"
            );
            
            if ($hoursAgo > 1) {
                check("Sync Freshness", false, "Last sync was {$hoursAgo} hours ago - cron may not be running");
            }
        } else {
            check("Last Sync Job", false, "No sync jobs found - cron has never run");
        }
    } catch (Exception $e) {
        check("Last Sync Job", false, "Error checking sync log: " . $e->getMessage());
    }
    
    // Check 4: Local API endpoint accessible
    $apiUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api/odoo-dashboard-local.php?action=health';
    $apiResponse = @file_get_contents($apiUrl);
    $apiData = $apiResponse ? json_decode($apiResponse, true) : null;
    
    if ($apiData && isset($apiData['success'])) {
        $apiOk = $apiData['success'] && $apiData['data']['status'] === 'ok';
        $localEnabled = $apiData['data']['local_enabled'] ?? false;
        check("Local API Endpoint", $apiOk, $apiOk ? "OK (local tables: " . ($localEnabled ? 'enabled' : 'disabled') . ")" : "API returned error");
    } else {
        check("Local API Endpoint", false, "Cannot reach API endpoint - check URL: {$apiUrl}");
    }
    
    // Check 5: Verify source table (webhook log) exists
    $webhookExists = $db->query("SHOW TABLES LIKE 'odoo_webhooks_log'")->rowCount() > 0;
    check("Source: odoo_webhooks_log", $webhookExists, $webhookExists ? 'Available for sync' : 'MISSING - no source data');
    
    if ($webhookExists) {
        $webhookCount = $db->query("SELECT COUNT(*) FROM odoo_webhooks_log WHERE status = 'success'")->fetchColumn();
        check("Source Data", $webhookCount > 0, "{$webhookCount} successful webhooks available for sync");
    }
    
} catch (Exception $e) {
    check("Database Connection", false, "Error: " . $e->getMessage());
    $allPass = false;
}

// Output results
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html>
<head>
    <title>Odoo Local Cache Verification</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 900px; margin: 2rem auto; padding: 1rem; background: #f8fafc; }
        h1 { color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem; }
        .result { display: flex; align-items: center; gap: 1rem; padding: 0.75rem; margin: 0.5rem 0; border-radius: 8px; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .pass { border-left: 4px solid #10b981; }
        .fail { border-left: 4px solid #ef4444; }
        .icon { font-size: 1.5rem; width: 2rem; text-align: center; }
        .name { font-weight: 600; color: #334155; min-width: 200px; }
        .message { color: #64748b; flex: 1; }
        .summary { padding: 1rem; border-radius: 8px; margin: 1rem 0; font-weight: 600; }
        .summary.ok { background: #d1fae5; color: #065f46; }
        .summary.fail { background: #fee2e2; color: #991b1b; }
        .actions { background: white; padding: 1rem; border-radius: 8px; margin-top: 1rem; }
        .actions h3 { margin-top: 0; color: #334155; }
        code { background: #f1f5f9; padding: 0.2rem 0.4rem; border-radius: 4px; font-family: monospace; }
        pre { background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔍 Odoo Dashboard Local Cache Verification</h1>
    
    <?php if ($allPass): ?>
    <div class="summary ok">✅ All checks passed! Local cache system is ready.</div>
    <?php else: ?>
    <div class="summary fail">⚠️ Some checks failed. See details below.</div>
    <?php endif; ?>
    
    <?php foreach ($results as $r): ?>
    <div class="result <?php echo $r['pass'] ? 'pass' : 'fail'; ?>">
        <span class="icon"><?php echo $r['pass'] ? '✅' : '❌'; ?></span>
        <span class="name"><?php echo htmlspecialchars($r['name']); ?></span>
        <span class="message"><?php echo htmlspecialchars($r['message']); ?></span>
    </div>
    <?php endforeach; ?>
    
    <div class="actions">
        <h3>📋 Next Steps</h3>
        <?php if (!$allPass): ?>
        <p><strong>If tables are missing:</strong></p>
        <pre>mysql -u root -p your_database < database/migration_odoo_dashboard_cache.sql</pre>
        
        <p><strong>If no data in tables:</strong></p>
        <pre>php cron/sync_odoo_dashboard_cache.php full</pre>
        
        <p><strong>To setup cron job:</strong></p>
        <pre># Edit crontab
crontab -e

# Add this line (runs every 5 minutes):
*/5 * * * * php <?php echo __DIR__; ?>/cron/sync_odoo_dashboard_cache.php</pre>
        <?php else: ?>
        <p>✅ System is ready! The dashboard will now use local cache tables for faster performance.</p>
        <p>Make sure the cron job is running every 5 minutes:</p>
        <pre>*/5 * * * * php <?php echo __DIR__; ?>/cron/sync_odoo_dashboard_cache.php</pre>
        <?php endif; ?>
        
        <p><strong>Test Local API:</strong></p>
        <pre>curl -X POST <?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']; ?>/api/odoo-dashboard-local.php \
  -H "Content-Type: application/json" \
  -d '{"action":"overview_kpi"}'</pre>
    </div>
</body>
</html>
    <?php
} else {
    // CLI output
    echo "\n" . ($allPass ? "✅ ALL CHECKS PASSED" : "⚠️ SOME CHECKS FAILED") . "\n";
    echo str_repeat("=", 50) . "\n";
    foreach ($results as $r) {
        echo ($r['pass'] ? "✅" : "❌") . " " . $r['name'] . ": " . $r['message'] . "\n";
    }
    
    if (!$allPass) {
        echo "\n📋 Next Steps:\n";
        echo "1. If tables missing: mysql -u root -p your_db < database/migration_odoo_dashboard_cache.sql\n";
        echo "2. If no data: php cron/sync_odoo_dashboard_cache.php full\n";
        echo "3. Setup cron: */5 * * * * php " . __DIR__ . "/cron/sync_odoo_dashboard_cache.php\n";
    }
}

exit($allPass ? 0 : 1);
