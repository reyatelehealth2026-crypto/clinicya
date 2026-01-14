<?php
/**
 * Debug Realtime API Response
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug Realtime API Response</h2>";

$lineAccountId = 1;

// Same query as API
$stmt = $db->prepare("
    SELECT 
        u.id,
        u.display_name,
        u.picture_url,
        u.line_user_id,
        (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
        (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time,
        (SELECT direction FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_direction,
        (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread_count
    FROM users u
    WHERE u.line_account_id = ?
    AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id)
    ORDER BY last_time DESC
    LIMIT 10
");
$stmt->execute([$lineAccountId]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Raw Query Results:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Last Message</th><th>Type</th><th>Time</th><th>Direction</th></tr>";

foreach ($conversations as $conv) {
    $msg = htmlspecialchars(mb_substr($conv['last_message'] ?? '', 0, 50));
    echo "<tr>";
    echo "<td>{$conv['id']}</td>";
    echo "<td>{$conv['display_name']}</td>";
    echo "<td>{$msg}</td>";
    echo "<td>{$conv['last_type']}</td>";
    echo "<td>{$conv['last_time']}</td>";
    echo "<td>{$conv['last_direction']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check jame.ver specifically
echo "<h3>jame.ver (ID 15) Details:</h3>";
$jame = null;
foreach ($conversations as $conv) {
    if ($conv['id'] == 15) {
        $jame = $conv;
        break;
    }
}

if ($jame) {
    echo "<p>Last Message: " . htmlspecialchars($jame['last_message'] ?? '') . "</p>";
    echo "<p>Last Type: " . ($jame['last_type'] ?? '') . "</p>";
    echo "<p>Last Time: " . ($jame['last_time'] ?? '') . "</p>";
    
    // Format like API does
    $lastMsg = $jame['last_message'];
    if ($jame['last_type'] === 'image') {
        $lastMsg = '📷 รูปภาพ';
    } elseif ($jame['last_type'] === 'sticker') {
        $lastMsg = '😊 สติกเกอร์';
    }
    echo "<p>Formatted: " . htmlspecialchars($lastMsg) . "</p>";
    
    if ($jame['last_type'] === 'image') {
        echo "<p style='color:red;font-weight:bold;'>⚠️ PROBLEM: last_type is 'image' but should be 'text'!</p>";
    } else {
        echo "<p style='color:green;font-weight:bold;'>✓ last_type is correct: " . $jame['last_type'] . "</p>";
    }
} else {
    echo "<p>jame.ver not found in results</p>";
}

// Also check what inbox-v2.php query returns
echo "<h3>inbox-v2.php Query Result for jame.ver:</h3>";
$sql = "SELECT u.*, 
        (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
        (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time
        FROM users u 
        WHERE u.id = 15";
$stmt = $db->query($sql);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<p>last_msg: " . htmlspecialchars($result['last_msg'] ?? '') . "</p>";
echo "<p>last_type: " . ($result['last_type'] ?? '') . "</p>";
echo "<p>last_time: " . ($result['last_time'] ?? '') . "</p>";
