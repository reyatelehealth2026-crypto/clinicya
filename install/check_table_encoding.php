<?php
/**
 * Check Table Encoding
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Database & Table Encoding Check</h2>";

// Check database charset
$stmt = $db->query("SELECT @@character_set_database, @@collation_database");
$dbCharset = $stmt->fetch();
echo "<h3>Database Charset</h3>";
echo "<pre>";
print_r($dbCharset);
echo "</pre>";

// Check messages table
$stmt = $db->query("SHOW CREATE TABLE messages");
$tableInfo = $stmt->fetch();
echo "<h3>Messages Table</h3>";
echo "<pre>";
echo htmlspecialchars($tableInfo['Create Table']);
echo "</pre>";

// Check content column specifically
$stmt = $db->query("SHOW FULL COLUMNS FROM messages WHERE Field = 'content'");
$columnInfo = $stmt->fetch();
echo "<h3>Content Column</h3>";
echo "<pre>";
print_r($columnInfo);
echo "</pre>";

// Sample location messages
$stmt = $db->query("SELECT id, content, created_at FROM messages WHERE message_type = 'location' ORDER BY created_at DESC LIMIT 5");
$samples = $stmt->fetchAll();
echo "<h3>Sample Location Messages</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Content</th><th>Created At</th></tr>";
foreach ($samples as $msg) {
    echo "<tr>";
    echo "<td>{$msg['id']}</td>";
    echo "<td>" . htmlspecialchars($msg['content']) . "</td>";
    echo "<td>{$msg['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";
