<?php
/**
 * Clear PHP OPcache
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== Clear OPcache ===\n\n";

if (function_exists('opcache_reset')) {
    $result = opcache_reset();
    echo "opcache_reset(): " . ($result ? "SUCCESS" : "FAILED") . "\n";
} else {
    echo "OPcache not available\n";
}

// Also invalidate specific file
$webhookPath = __DIR__ . '/../webhook.php';
if (function_exists('opcache_invalidate')) {
    $result = opcache_invalidate($webhookPath, true);
    echo "opcache_invalidate(webhook.php): " . ($result ? "SUCCESS" : "FAILED") . "\n";
}

// Show OPcache status
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status) {
        echo "\nOPcache Status:\n";
        echo "  - Enabled: " . ($status['opcache_enabled'] ? 'YES' : 'NO') . "\n";
        echo "  - Cache full: " . ($status['cache_full'] ? 'YES' : 'NO') . "\n";
        echo "  - Cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
    }
}

echo "\n=== Done ===\n";
