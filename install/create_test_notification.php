<?php
/**
 * Create test notification for testing LINE push
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Create Test Notification</h2>";

// Get user 28 info
$stmt = $db->query("SELECT id, display_name, line_user_id, line_account_id FROM users WHERE id = 28");
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User 28 not found");
}

echo "<p>User: {$user['display_name']} (ID: {$user['id']})</p>";
echo "<p>LINE Account ID: {$user['line_account_id']}</p>";

if (isset($_GET['create'])) {
    // Create new pending notification
    $stmt = $db->prepare("
        INSERT INTO pharmacist_notifications 
        (user_id, line_account_id, notification_type, priority, status, notification_data, created_at)
        VALUES (?, ?, 'triage_complete', 'normal', 'pending', ?, NOW())
    ");
    
    $notificationData = json_encode([
        'symptoms' => ['ปวดหัว', 'มีไข้'],
        'severity' => 5,
        'duration' => '2 วัน',
        'user_name' => $user['display_name'],
        'red_flags' => []
    ], JSON_UNESCAPED_UNICODE);
    
    $stmt->execute([$user['id'], $user['line_account_id'], $notificationData]);
    $newId = $db->lastInsertId();
    
    echo "<p style='color:green'>✅ Created notification ID: {$newId}</p>";
    echo "<p><a href='../pharmacist-dashboard.php'>ไปที่ Pharmacist Dashboard</a></p>";
} else {
    // Show current pending count
    $stmt = $db->query("SELECT COUNT(*) FROM pharmacist_notifications WHERE status = 'pending'");
    $pending = $stmt->fetchColumn();
    
    echo "<p>Pending notifications: {$pending}</p>";
    echo "<br><a href='?create=1' style='background:#10b981;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;'>➕ สร้าง Notification ใหม่</a>";
}
