<?php
/**
 * Check notifications and sessions in database
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Database Check</h2>";

try {
    // Check triage_sessions
    echo "<h3>Triage Sessions</h3>";
    $stmt = $db->query("SELECT COUNT(*) as total FROM triage_sessions");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total sessions: <strong>{$result['total']}</strong></p>";
    
    $stmt = $db->query("SELECT id, user_id, line_account_id, current_state, status, created_at FROM triage_sessions ORDER BY created_at DESC LIMIT 5");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($sessions, true) . "</pre>";
    
    // Check pharmacist_notifications
    echo "<h3>Pharmacist Notifications</h3>";
    $stmt = $db->query("SELECT COUNT(*) as total FROM pharmacist_notifications");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total notifications: <strong>{$result['total']}</strong></p>";
    
    $stmt = $db->query("SELECT COUNT(*) as pending FROM pharmacist_notifications WHERE status = 'pending'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Pending notifications: <strong>{$result['pending']}</strong></p>";
    
    $stmt = $db->query("SELECT id, user_id, triage_session_id, type, title, priority, status, created_at FROM pharmacist_notifications ORDER BY created_at DESC LIMIT 5");
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($notifications, true) . "</pre>";
    
    // Check table structure
    echo "<h3>Table Structure - pharmacist_notifications</h3>";
    $stmt = $db->query("DESCRIBE pharmacist_notifications");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td><td>{$col['Key']}</td><td>{$col['Default']}</td></tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
