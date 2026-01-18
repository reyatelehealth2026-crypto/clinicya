<?php
/**
 * Verify Webhook Version - Check if production webhook has the latest code
 * 
 * This script checks if the reply_token saving code in webhook.php
 * matches the expected version with proper error logging.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🔍 Webhook Version Verification</h1>";
echo "<p>Checking if production webhook.php has the latest reply_token code...</p>";

// Check webhook.php file
$webhookPath = __DIR__ . '/../webhook.php';
if (!file_exists($webhookPath)) {
    echo "<p style='color: red;'>❌ webhook.php not found at: {$webhookPath}</p>";
    exit;
}

$webhookContent = file_get_contents($webhookPath);

echo "<h2>1. Code Version Checks</h2>";

// Check for new error logging
if (strpos($webhookContent, 'Reply token saved for user') !== false) {
    echo "<p style='color: green;'>✓ Found new success logging: 'Reply token saved for user'</p>";
} else {
    echo "<p style='color: red;'>✗ Missing new success logging</p>";
}

if (strpos($webhookContent, 'Reply token save failed') !== false) {
    echo "<p style='color: green;'>✓ Found new error logging: 'Reply token save failed'</p>";
} else {
    echo "<p style='color: red;'>✗ Missing new error logging</p>";
}

// Check for 50 second expiry (not 19 minutes)
if (strpos($webhookContent, 'time() + 50') !== false) {
    echo "<p style='color: green;'>✓ Found correct expiry time: 50 seconds</p>";
} else {
    echo "<p style='color: orange;'>⚠ Expiry time might be incorrect (should be 50 seconds)</p>";
}

// Check if old SHOW COLUMNS check was removed from reply_token section
$replyTokenSection = substr($webhookContent, strpos($webhookContent, 'บันทึก reply_token ใน users table'), 1000);
if (strpos($replyTokenSection, 'SHOW COLUMNS') !== false) {
    echo "<p style='color: orange;'>⚠ Old SHOW COLUMNS check still present in reply_token section</p>";
} else {
    echo "<p style='color: green;'>✓ SHOW COLUMNS check removed from reply_token section</p>";
}

echo "<h2>2. Recent Error Logs</h2>";
echo "<p>Checking PHP error log for reply_token messages...</p>";

// Try to find error log
$errorLogPaths = [
    ini_get('error_log'),
    '/home/zrismpsz/public_html/emp.re-ya.net/error_log',
    __DIR__ . '/../error_log',
    '/tmp/php_errors.log'
];

$foundLog = false;
foreach ($errorLogPaths as $logPath) {
    if ($logPath && file_exists($logPath)) {
        echo "<p>Found error log: <code>{$logPath}</code></p>";
        
        // Read last 100 lines
        $lines = file($logPath);
        $recentLines = array_slice($lines, -100);
        
        $replyTokenLogs = array_filter($recentLines, function($line) {
            return stripos($line, 'reply token') !== false || stripos($line, 'reply_token') !== false;
        });
        
        if (count($replyTokenLogs) > 0) {
            echo "<h3>Recent Reply Token Logs:</h3>";
            echo "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>";
            foreach (array_slice($replyTokenLogs, -10) as $log) {
                echo htmlspecialchars($log);
            }
            echo "</pre>";
        } else {
            echo "<p style='color: orange;'>⚠ No reply_token logs found in recent entries</p>";
            echo "<p><em>This suggests either:</em></p>";
            echo "<ul>";
            echo "<li>No messages received since code update</li>";
            echo "<li>Webhook is not executing the new code</li>";
            echo "<li>Error logging is going to a different file</li>";
            echo "</ul>";
        }
        
        $foundLog = true;
        break;
    }
}

if (!$foundLog) {
    echo "<p style='color: orange;'>⚠ Could not find PHP error log</p>";
    echo "<p>Checked paths:</p><ul>";
    foreach ($errorLogPaths as $path) {
        echo "<li><code>" . htmlspecialchars($path) . "</code></li>";
    }
    echo "</ul>";
}

echo "<h2>3. Database Check</h2>";

$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo "<p style='color: red;'>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Cannot check database. Please verify database configuration.</p>";
    echo "<hr>";
    echo "<p><small>Generated at: " . date('Y-m-d H:i:s') . "</small></p>";
    exit;
}

try {
    
    // Check recent messages
    $stmt = $db->query("
        SELECT 
            id,
            user_id,
            message_type,
            reply_token,
            created_at,
            TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago
        FROM messages 
        WHERE direction = 'incoming'
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recentMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Last 5 Incoming Messages:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>User ID</th><th>Type</th><th>Has Token?</th><th>Created</th><th>Seconds Ago</th></tr>";
    
    foreach ($recentMessages as $msg) {
        $hasToken = !empty($msg['reply_token']) ? '✓ Yes' : '✗ No';
        $tokenColor = !empty($msg['reply_token']) ? 'green' : 'red';
        echo "<tr>";
        echo "<td>{$msg['id']}</td>";
        echo "<td>{$msg['user_id']}</td>";
        echo "<td>{$msg['message_type']}</td>";
        echo "<td style='color: {$tokenColor};'><strong>{$hasToken}</strong></td>";
        echo "<td>{$msg['created_at']}</td>";
        echo "<td>{$msg['seconds_ago']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check users table
    $stmt = $db->query("
        SELECT 
            id,
            name,
            reply_token,
            reply_token_expires,
            CASE 
                WHEN reply_token_expires IS NULL THEN 'No expiry set'
                WHEN reply_token_expires < NOW() THEN 'Expired'
                ELSE 'Valid'
            END as token_status
        FROM users 
        WHERE reply_token IS NOT NULL
        ORDER BY reply_token_expires DESC
        LIMIT 5
    ");
    $usersWithTokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Users with Reply Tokens:</h3>";
    if (count($usersWithTokens) > 0) {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>Name</th><th>Token (first 20)</th><th>Expires</th><th>Status</th></tr>";
        
        foreach ($usersWithTokens as $user) {
            $tokenPreview = substr($user['reply_token'], 0, 20) . '...';
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['name']}</td>";
            echo "<td><code>{$tokenPreview}</code></td>";
            echo "<td>{$user['reply_token_expires']}</td>";
            echo "<td>{$user['token_status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>❌ No users have reply_token saved</p>";
        echo "<p><strong>This confirms the issue: webhook is NOT saving tokens to users table</strong></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>4. Recommendations</h2>";
echo "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107;'>";
echo "<h3>Next Steps:</h3>";
echo "<ol>";
echo "<li><strong>Send a test LINE message</strong> to trigger the webhook</li>";
echo "<li><strong>Refresh this page</strong> to see if token was saved</li>";
echo "<li><strong>Check error logs</strong> for 'Reply token saved' or 'Reply token save failed' messages</li>";
echo "<li>If still no tokens saved, the webhook might be cached or using a different file</li>";
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<p><small>Generated at: " . date('Y-m-d H:i:s') . "</small></p>";
