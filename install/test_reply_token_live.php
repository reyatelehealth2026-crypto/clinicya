<?php
/**
 * Live Reply Token Test
 * 
 * This script simulates a LINE webhook event to test if reply_token
 * is being saved correctly to both messages and users tables.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>🧪 Live Reply Token Test</h1>";

// Get test user
$db = null;
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}
$stmt = $db->query("SELECT * FROM users WHERE line_user_id IS NOT NULL LIMIT 1");
$testUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$testUser) {
    echo "<p style='color: red;'>❌ No users found in database</p>";
    exit;
}

echo "<h2>Test User</h2>";
echo "<ul>";
echo "<li><strong>ID:</strong> {$testUser['id']}</li>";
echo "<li><strong>Name:</strong> {$testUser['name']}</li>";
echo "<li><strong>LINE User ID:</strong> {$testUser['line_user_id']}</li>";
echo "</ul>";

// Generate a fake reply token (similar format to LINE)
$fakeReplyToken = bin2hex(random_bytes(16)) . bin2hex(random_bytes(16));

echo "<h2>Simulating Reply Token Save</h2>";
echo "<p>Fake token: <code>" . substr($fakeReplyToken, 0, 40) . "...</code></p>";

try {
    // Test 1: Save to messages table
    echo "<h3>Test 1: Save to messages table</h3>";
    $stmt = $db->prepare("INSERT INTO messages (user_id, direction, message_type, content, reply_token) VALUES (?, 'incoming', 'text', '[TEST] Reply token test', ?)");
    $stmt->execute([$testUser['id'], $fakeReplyToken]);
    $messageId = $db->lastInsertId();
    echo "<p style='color: green;'>✓ Saved to messages table (ID: {$messageId})</p>";
    
    // Verify
    $stmt = $db->prepare("SELECT reply_token FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $savedMessage = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($savedMessage && $savedMessage['reply_token'] === $fakeReplyToken) {
        echo "<p style='color: green;'>✓ Verified: Token saved correctly to messages</p>";
    } else {
        echo "<p style='color: red;'>✗ Token not saved correctly to messages</p>";
    }
    
    // Test 2: Save to users table (this is what webhook.php should do)
    echo "<h3>Test 2: Save to users table</h3>";
    $expires = date('Y-m-d H:i:s', time() + 50);
    
    echo "<p>Executing UPDATE query...</p>";
    echo "<pre>UPDATE users SET reply_token = ?, reply_token_expires = ? WHERE id = ?</pre>";
    echo "<p>Parameters: token={$fakeReplyToken}, expires={$expires}, user_id={$testUser['id']}</p>";
    
    $stmt = $db->prepare("UPDATE users SET reply_token = ?, reply_token_expires = ? WHERE id = ?");
    $result = $stmt->execute([$fakeReplyToken, $expires, $testUser['id']]);
    $rowCount = $stmt->rowCount();
    
    echo "<p>Query result: " . ($result ? 'Success' : 'Failed') . "</p>";
    echo "<p>Rows affected: {$rowCount}</p>";
    
    if ($result && $rowCount > 0) {
        echo "<p style='color: green;'>✓ UPDATE executed successfully</p>";
    } else {
        echo "<p style='color: orange;'>⚠ UPDATE executed but no rows affected (rowCount: {$rowCount})</p>";
    }
    
    // Verify
    $stmt = $db->prepare("SELECT reply_token, reply_token_expires FROM users WHERE id = ?");
    $stmt->execute([$testUser['id']]);
    $savedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h3>Verification</h3>";
    if ($savedUser && $savedUser['reply_token'] === $fakeReplyToken) {
        echo "<p style='color: green;'>✓ <strong>SUCCESS!</strong> Token saved correctly to users table</p>";
        echo "<p>Token: <code>" . substr($savedUser['reply_token'], 0, 40) . "...</code></p>";
        echo "<p>Expires: {$savedUser['reply_token_expires']}</p>";
        
        $secondsUntilExpiry = strtotime($savedUser['reply_token_expires']) - time();
        echo "<p>Expires in: {$secondsUntilExpiry} seconds</p>";
        
        if ($secondsUntilExpiry >= 45 && $secondsUntilExpiry <= 55) {
            echo "<p style='color: green;'>✓ Expiry time is correct (~50 seconds)</p>";
        } else {
            echo "<p style='color: orange;'>⚠ Expiry time seems off (expected ~50 seconds)</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ <strong>FAILED!</strong> Token NOT saved to users table</p>";
        echo "<p>Expected: <code>" . substr($fakeReplyToken, 0, 40) . "...</code></p>";
        echo "<p>Got: <code>" . ($savedUser['reply_token'] ? substr($savedUser['reply_token'], 0, 40) . '...' : 'NULL') . "</code></p>";
    }
    
    // Clean up test data
    echo "<h3>Cleanup</h3>";
    $stmt = $db->prepare("DELETE FROM messages WHERE id = ?");
    $stmt->execute([$messageId]);
    echo "<p>✓ Deleted test message</p>";
    
    // Leave the token in users table for verification
    echo "<p><em>Note: Token left in users table for verification. Will expire in ~50 seconds.</em></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<h2>Conclusion</h2>";
echo "<div style='background: #d1ecf1; padding: 15px; border-left: 4px solid #0c5460;'>";
echo "<p>If this test succeeds, it means:</p>";
echo "<ul>";
echo "<li>✓ Database columns exist and are writable</li>";
echo "<li>✓ The UPDATE query syntax is correct</li>";
echo "<li>✓ PHP can save tokens to users table</li>";
echo "</ul>";
echo "<p><strong>If webhook still doesn't save tokens, the issue is:</strong></p>";
echo "<ul>";
echo "<li>Webhook code is cached (opcache/APC)</li>";
echo "<li>Webhook is using a different file path</li>";
echo "<li>Webhook has a PHP error preventing execution</li>";
echo "</ul>";
echo "</div>";

echo "<hr>";
echo "<p><a href='verify_webhook_version.php'>→ Check Webhook Version</a></p>";
echo "<p><small>Generated at: " . date('Y-m-d H:i:s') . "</small></p>";
