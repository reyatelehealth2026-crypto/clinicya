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

// Run sync
echo "Starting sync ({$full})...\n";
flush();

ob_start();
include __DIR__ . '/cron/sync_odoo_dashboard_cache.php';
$output = ob_get_clean();

echo "\n=== SYNC OUTPUT ===\n";
echo nl2br($output);
