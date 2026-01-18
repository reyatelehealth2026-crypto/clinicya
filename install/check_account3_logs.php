<?php
/**
 * Check Account 3 Debug Logs
 * Shows recent error_log entries for Account 3 reply token debugging
 */

echo "=== Account 3 Reply Token Debug Logs ===\n\n";

// Check PHP error log location
$errorLogPaths = [
    '/home/zrismpsz/public_html/emp.re-ya.net/error_log',
    __DIR__ . '/../error_log',
    ini_get('error_log'),
    '/var/log/php_errors.log',
    '/tmp/php_errors.log'
];

echo "Checking error log locations:\n";
foreach ($errorLogPaths as $path) {
    if ($path && file_exists($path)) {
        echo "✓ Found: $path\n";
        
        // Read last 200 lines
        $lines = file($path);
        $recentLines = array_slice($lines, -200);
        
        echo "\n--- Recent Account 3 Debug Entries ---\n";
        $found = false;
        foreach ($recentLines as $line) {
            if (stripos($line, 'ACCOUNT 3') !== false) {
                echo $line;
                $found = true;
            }
        }
        
        if (!$found) {
            echo "No Account 3 debug entries found in last 200 lines.\n";
            echo "Try sending a message to Account 3 LINE bot first.\n";
        }
        
        break;
    } else {
        echo "✗ Not found: $path\n";
    }
}

echo "\n=== Instructions ===\n";
echo "1. Send a test message to Account 3 LINE bot (cnypharmacy)\n";
echo "2. Run this script again to see the debug logs\n";
echo "3. The logs will show:\n";
echo "   - What replyToken LINE sends in the event\n";
echo "   - Whether the token is NULL or has a value\n";
echo "   - Full event JSON from LINE\n";
echo "\n";
echo "If no logs appear, check:\n";
echo "- Webhook URL is correct: https://cny.re-ya.com/webhook.php?account=3\n";
echo "- LINE webhook is enabled in LINE Developers Console\n";
echo "- PHP error_log is enabled in php.ini\n";
