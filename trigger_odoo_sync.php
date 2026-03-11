<?php
/**
 * Web-based Sync Trigger for Odoo Dashboard Cache
 * URL: /trigger_odoo_sync.php?token=sync123&full=1
 */

$token = $_GET['token'] ?? '';
if ($token !== 'sync123') {
    http_response_code(403);
    die('Invalid token');
}

$full = isset($_GET['full']) ? 'full' : 'incremental';

// Run sync using shell_exec instead of include to avoid exit() issues
$cronFile = __DIR__ . '/cron/sync_odoo_dashboard_cache.php';
$cmd = "php {$cronFile} {$full} 2>&1";

echo "<h3>Starting sync ({$full})...</h3>";
echo "<pre style='background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:8px;'>";
flush();

$output = shell_exec($cmd);
echo htmlspecialchars($output);
echo "</pre>";

// Check results
try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance()->getConnection();
    
    $counts = [];
    $tables = ['odoo_orders_summary', 'odoo_customers_cache', 'odoo_invoices_cache'];
    foreach ($tables as $t) {
        $counts[$t] = $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }
    
    echo "<h4>Current Table Counts:</h4>";
    echo "<ul>";
    foreach ($counts as $t => $c) {
        echo "<li>{$t}: {$c} rows</li>";
    }
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error checking counts: " . htmlspecialchars($e->getMessage()) . "</p>";
}
