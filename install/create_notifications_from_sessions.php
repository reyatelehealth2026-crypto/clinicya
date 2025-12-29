<?php
/**
 * Create pharmacist notifications from existing triage sessions
 * Run once to populate notifications for existing sessions
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Creating Notifications from Existing Sessions</h2>";

try {
    // First, ensure table has all required columns
    echo "<p>Checking table structure...</p>";
    
    // Add missing columns if they don't exist
    $alterQueries = [
        "ALTER TABLE pharmacist_notifications ADD COLUMN IF NOT EXISTS type VARCHAR(50) DEFAULT 'triage_alert'",
        "ALTER TABLE pharmacist_notifications ADD COLUMN IF NOT EXISTS notification_data JSON",
        "ALTER TABLE pharmacist_notifications ADD COLUMN IF NOT EXISTS triage_session_id INT NULL",
        "ALTER TABLE pharmacist_notifications ADD COLUMN IF NOT EXISTS priority ENUM('normal', 'urgent') DEFAULT 'normal'",
    ];
    
    foreach ($alterQueries as $query) {
        try {
            $db->exec($query);
        } catch (Exception $e) {
            // Column might already exist, ignore
        }
    }
    
    echo "<p>✅ Table structure updated</p>";
    
    // Get all active sessions without notifications
    $stmt = $db->query("
        SELECT ts.*, u.display_name, u.picture_url
        FROM triage_sessions ts
        LEFT JOIN users u ON ts.user_id = u.id
        LEFT JOIN pharmacist_notifications pn ON pn.triage_session_id = ts.id
        WHERE pn.id IS NULL
        ORDER BY ts.created_at DESC
    ");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($sessions) . " sessions without notifications</p>";
    
    foreach ($sessions as $session) {
        $triageData = json_decode($session['triage_data'] ?? '{}', true);
        $userName = $session['display_name'] ?? 'ไม่ระบุชื่อ';
        
        // Determine priority
        $severity = $triageData['severity'] ?? null;
        $priority = 'normal';
        $severityLevel = 'normal';
        
        if ($severity !== null) {
            if ($severity >= 8) {
                $severityLevel = 'critical';
                $priority = 'urgent';
            } elseif ($severity >= 6) {
                $severityLevel = 'high';
                $priority = 'urgent';
            } elseif ($severity >= 4) {
                $severityLevel = 'medium';
            }
        }
        
        // Build notification data
        $symptoms = $triageData['symptoms'] ?? '';
        if (is_array($symptoms)) {
            $symptoms = implode(', ', $symptoms);
        }
        
        $notificationData = json_encode([
            'symptoms' => $triageData['symptoms'] ?? '',
            'duration' => $triageData['duration'] ?? '',
            'severity' => $severity,
            'severity_level' => $severityLevel,
            'medical_history' => $triageData['medical_history'] ?? '',
            'red_flags' => $triageData['red_flags'] ?? [],
            'user_name' => $userName
        ], JSON_UNESCAPED_UNICODE);
        
        $title = "🩺 การซักประวัติ";
        if ($priority === 'urgent') {
            $title = "⚠️ การซักประวัติ - ต้องตรวจสอบ";
        }
        
        $message = "ลูกค้า: {$userName}\n";
        if (!empty($symptoms)) {
            $message .= "อาการ: {$symptoms}\n";
        }
        if ($severity !== null) {
            $message .= "ความรุนแรง: {$severity}/10\n";
        }
        $message .= "สถานะ: {$session['current_state']}";
        
        // Insert notification
        $stmt = $db->prepare("
            INSERT INTO pharmacist_notifications 
            (line_account_id, type, title, message, notification_data, user_id, triage_session_id, priority, status)
            VALUES (?, 'triage_session', ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $session['line_account_id'],
            $title,
            $message,
            $notificationData,
            $session['user_id'],
            $session['id'],
            $priority
        ]);
        
        echo "<p>✅ Created notification for session #{$session['id']} ({$userName})</p>";
    }
    
    echo "<h3>Done!</h3>";
    echo "<p><a href='/pharmacist-dashboard.php'>Go to Pharmacist Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
