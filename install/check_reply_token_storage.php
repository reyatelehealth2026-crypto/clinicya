<?php
/**
 * Reply Token Storage Diagnostic Script
 * 
 * This script checks:
 * 1. If reply_token columns exist in users and messages tables
 * 2. Current reply_token data in database
 * 3. Recent webhook activity
 * 4. Reply token expiration status
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');

$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo "<h1 style='color: red;'>Database Connection Failed</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Please check your database configuration in config/config.php</p>";
    exit;
}

echo "<h1>Reply Token Storage Diagnostic</h1>";
echo "<style>
    body { font-family: monospace; padding: 20px; }
    h2 { color: #2563eb; margin-top: 30px; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f3f4f6; }
    .success { color: #059669; }
    .error { color: #dc2626; }
    .warning { color: #d97706; }
    pre { background: #f3f4f6; padding: 10px; overflow-x: auto; }
</style>";

// 1. Check if columns exist
echo "<h2>1. Column Existence Check</h2>";

$tables = ['users', 'messages'];
foreach ($tables as $table) {
    echo "<h3>Table: {$table}</h3>";
    
    $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE 'reply_token%'");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($columns) > 0) {
        echo "<p class='success'>✓ Reply token columns found</p>";
        echo "<table>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td>{$col['Field']}</td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>✗ No reply_token columns found in {$table}</p>";
        echo "<p class='warning'>⚠ You may need to run database migration</p>";
    }
}

// 2. Check current reply_token data in users table
echo "<h2>2. Current Reply Tokens in Users Table</h2>";

$stmt = $db->query("
    SELECT 
        id,
        display_name,
        reply_token,
        reply_token_expires,
        CASE 
            WHEN reply_token_expires IS NULL THEN 'No expiry set'
            WHEN reply_token_expires > NOW() THEN 'Valid'
            ELSE 'Expired'
        END as status,
        TIMESTAMPDIFF(SECOND, NOW(), reply_token_expires) as seconds_until_expiry,
        last_interaction
    FROM users 
    WHERE reply_token IS NOT NULL
    ORDER BY reply_token_expires DESC
    LIMIT 20
");

$tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($tokens) > 0) {
    echo "<p class='success'>✓ Found " . count($tokens) . " users with reply tokens</p>";
    echo "<table>";
    echo "<tr><th>User ID</th><th>Name</th><th>Token (first 20 chars)</th><th>Expires</th><th>Status</th><th>Seconds Left</th><th>Last Interaction</th></tr>";
    foreach ($tokens as $token) {
        $statusClass = $token['status'] === 'Valid' ? 'success' : 'error';
        echo "<tr>";
        echo "<td>{$token['id']}</td>";
        echo "<td>{$token['display_name']}</td>";
        echo "<td>" . substr($token['reply_token'], 0, 20) . "...</td>";
        echo "<td>{$token['reply_token_expires']}</td>";
        echo "<td class='{$statusClass}'>{$token['status']}</td>";
        echo "<td>{$token['seconds_until_expiry']}</td>";
        echo "<td>{$token['last_interaction']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No users with reply tokens found</p>";
    echo "<p class='warning'>⚠ This suggests reply tokens are not being saved</p>";
}

// 3. Check reply_token data in messages table
echo "<h2>3. Recent Messages with Reply Tokens</h2>";

$stmt = $db->query("
    SELECT 
        id,
        user_id,
        message_type,
        reply_token,
        created_at,
        TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago
    FROM messages 
    WHERE reply_token IS NOT NULL
    ORDER BY created_at DESC
    LIMIT 20
");

$messageTokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($messageTokens) > 0) {
    echo "<p class='success'>✓ Found " . count($messageTokens) . " messages with reply tokens</p>";
    echo "<table>";
    echo "<tr><th>Message ID</th><th>User ID</th><th>Type</th><th>Token (first 20 chars)</th><th>Created</th><th>Seconds Ago</th></tr>";
    foreach ($messageTokens as $msg) {
        echo "<tr>";
        echo "<td>{$msg['id']}</td>";
        echo "<td>{$msg['user_id']}</td>";
        echo "<td>{$msg['message_type']}</td>";
        echo "<td>" . substr($msg['reply_token'], 0, 20) . "...</td>";
        echo "<td>{$msg['created_at']}</td>";
        echo "<td>{$msg['seconds_ago']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No messages with reply tokens found</p>";
}

// 4. Check recent webhook activity
echo "<h2>4. Recent Webhook Activity (Last 10 Messages)</h2>";

$stmt = $db->query("
    SELECT 
        m.id,
        m.user_id,
        u.display_name,
        m.message_type,
        m.created_at,
        TIMESTAMPDIFF(SECOND, m.created_at, NOW()) as seconds_ago,
        m.reply_token IS NOT NULL as has_reply_token
    FROM messages m
    LEFT JOIN users u ON m.user_id = u.id
    WHERE m.direction = 'incoming'
    ORDER BY m.created_at DESC
    LIMIT 10
");

$recentMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($recentMessages) > 0) {
    echo "<table>";
    echo "<tr><th>Message ID</th><th>User ID</th><th>Name</th><th>Type</th><th>Created</th><th>Seconds Ago</th><th>Has Reply Token?</th></tr>";
    foreach ($recentMessages as $msg) {
        $tokenStatus = $msg['has_reply_token'] ? "<span class='success'>Yes</span>" : "<span class='error'>No</span>";
        echo "<tr>";
        echo "<td>{$msg['id']}</td>";
        echo "<td>{$msg['user_id']}</td>";
        echo "<td>{$msg['display_name']}</td>";
        echo "<td>{$msg['message_type']}</td>";
        echo "<td>{$msg['created_at']}</td>";
        echo "<td>{$msg['seconds_ago']}</td>";
        echo "<td>{$tokenStatus}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>⚠ No recent incoming messages found</p>";
}

// 5. Check webhook.php code
echo "<h2>5. Webhook Code Check</h2>";

$webhookFile = __DIR__ . '/../webhook.php';
if (file_exists($webhookFile)) {
    $webhookContent = file_get_contents($webhookFile);
    
    // Check if reply_token saving code exists
    if (strpos($webhookContent, 'reply_token') !== false) {
        echo "<p class='success'>✓ Reply token handling code found in webhook.php</p>";
        
        // Check if error is being ignored
        if (strpos($webhookContent, '// Ignore error') !== false) {
            echo "<p class='warning'>⚠ WARNING: Errors are being silently ignored in reply_token saving code</p>";
            echo "<p>This means if there's an error saving the token, you won't know about it.</p>";
        }
        
        // Check if column existence is being checked
        if (strpos($webhookContent, "SHOW COLUMNS FROM users LIKE 'reply_token'") !== false) {
            echo "<p class='success'>✓ Code checks for column existence before saving</p>";
        }
    } else {
        echo "<p class='error'>✗ No reply_token handling code found in webhook.php</p>";
    }
} else {
    echo "<p class='error'>✗ webhook.php file not found</p>";
}

// 6. Summary and recommendations
echo "<h2>6. Summary & Recommendations</h2>";

$hasColumns = count($columns) > 0;
$hasUserTokens = count($tokens) > 0;
$hasMessageTokens = count($messageTokens) > 0;

echo "<h3>Status:</h3>";
echo "<ul>";
echo "<li>Columns exist: " . ($hasColumns ? "<span class='success'>Yes</span>" : "<span class='error'>No</span>") . "</li>";
echo "<li>Users with tokens: " . ($hasUserTokens ? "<span class='success'>Yes (" . count($tokens) . ")</span>" : "<span class='error'>No</span>") . "</li>";
echo "<li>Messages with tokens: " . ($hasMessageTokens ? "<span class='success'>Yes (" . count($messageTokens) . ")</span>" : "<span class='error'>No</span>") . "</li>";
echo "</ul>";

echo "<h3>Recommendations:</h3>";
echo "<ol>";

if (!$hasColumns) {
    echo "<li class='error'><strong>Run database migration</strong> - The reply_token columns don't exist in your database</li>";
}

if (!$hasUserTokens && !$hasMessageTokens) {
    echo "<li class='warning'><strong>Test webhook</strong> - Send a message from LINE app and check if token is saved</li>";
    echo "<li class='warning'><strong>Enable error logging</strong> - Replace '// Ignore error' with proper error logging in webhook.php (line ~829)</li>";
    echo "<li class='warning'><strong>Check webhook URL</strong> - Verify LINE webhook is pointing to correct URL</li>";
    echo "<li class='warning'><strong>Check LINE webhook logs</strong> - Check LINE Developers Console for webhook delivery status</li>";
}

if ($hasMessageTokens && !$hasUserTokens) {
    echo "<li class='warning'><strong>Users table update issue</strong> - Tokens are saved to messages but not users table</li>";
    echo "<li class='warning'><strong>Check UPDATE query</strong> - The UPDATE statement in webhook.php may be failing</li>";
}

echo "<li><strong>Implement fallback</strong> - Use Push API when reply_token is expired or missing</li>";
echo "</ol>";

echo "<h3>Next Steps:</h3>";
echo "<pre>";
echo "1. Send a test message from LINE app\n";
echo "2. Immediately refresh this page to see if token was saved\n";
echo "3. Check token expiry time (should be ~19 minutes from message time)\n";
echo "4. If no token saved, check error_log for PHP errors\n";
echo "5. Consider adding detailed logging to webhook.php reply_token section\n";
echo "</pre>";

echo "<hr>";
echo "<p><small>Generated at: " . date('Y-m-d H:i:s') . "</small></p>";

?>
