<?php
/**
 * Check notification status in database
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Check Notification Status</h2>";

// Count by status
$stmt = $db->query("SELECT status, COUNT(*) as cnt FROM pharmacist_notifications GROUP BY status");
$statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Status Counts:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Status</th><th>Count</th></tr>";
foreach ($statusCounts as $row) {
    $status = $row['status'] ?: 'NULL';
    echo "<tr><td>{$status}</td><td>{$row['cnt']}</td></tr>";
}
echo "</table>";

// Show all notifications
$stmt = $db->query("SELECT id, user_id, status, priority, created_at FROM pharmacist_notifications ORDER BY id DESC LIMIT 20");
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Recent Notifications:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>User ID</th><th>Status</th><th>Priority</th><th>Created</th></tr>";
foreach ($notifications as $n) {
    $status = $n['status'] ?: 'NULL';
    echo "<tr><td>{$n['id']}</td><td>{$n['user_id']}</td><td>{$status}</td><td>{$n['priority']}</td><td>{$n['created_at']}</td></tr>";
}
echo "</table>";

// Reset to pending
if (isset($_GET['reset'])) {
    $stmt = $db->query("UPDATE pharmacist_notifications SET status = 'pending' WHERE status IN ('handled', 'read', 'dismissed') OR status IS NULL");
    echo "<br><strong style='color:green'>✅ Reset all to pending!</strong>";
    echo "<br><a href='?'>Refresh</a>";
}

echo "<br><br><a href='?reset=1' style='background:#ef4444;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;'>🔄 Reset All to Pending</a>";
