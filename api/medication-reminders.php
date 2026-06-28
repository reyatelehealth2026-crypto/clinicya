<?php
/**
 * Medication Reminders API
 * Requirements: 15.1, 15.2, 15.3, 15.4, 15.5, 15.6, 15.7
 * - Display list of active medication schedules (15.1)
 * - Allow selection from order history or manual entry (15.2)
 * - Capture medication name, dosage, frequency, and reminder times (15.3)
 * - Send LINE push notification with medication details (15.4)
 * - Include "Mark as Taken" action button (15.5)
 * - Record timestamp and update adherence tracking (15.6)
 * - Display adherence percentage and missed doses (15.7)
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/config.php';
require_once '../config/database.php';
// Route root-domain (Mini App / LIFF) request to the tenant DB by line_account_id (split-brain fix).
require_once '../bootstrap/route_by_account.php';

$db = Database::getInstance()->getConnection();

// Ensure tables exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS medication_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        line_user_id VARCHAR(50),
        line_account_id INT,
        medication_name VARCHAR(255) NOT NULL,
        dosage VARCHAR(100) COMMENT 'e.g., 1 tablet, 5ml',
        frequency VARCHAR(50) COMMENT 'daily, twice_daily, custom',
        reminder_times JSON COMMENT 'Array of times like [\"08:00\", \"20:00\"]',
        start_date DATE,
        end_date DATE,
        notes TEXT,
        is_active TINYINT(1) DEFAULT 1,
        product_id INT COMMENT 'Link to product if from order',
        order_id INT COMMENT 'Link to order if from order history',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_line_user (line_user_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $db->exec("CREATE TABLE IF NOT EXISTS medication_taken_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reminder_id INT NOT NULL,
        user_id INT NOT NULL,
        scheduled_time TIME,
        taken_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('taken', 'skipped', 'missed') DEFAULT 'taken',
        notes TEXT,
        INDEX idx_reminder (reminder_id),
        INDEX idx_user (user_id),
        INDEX idx_date (taken_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;

$action = $input['action'] ?? $_GET['action'] ?? 'list';
$lineUserId = $input['line_user_id'] ?? $_GET['line_user_id'] ?? '';
$lineAccountId = $input['line_account_id'] ?? $_GET['line_account_id'] ?? null;

// Get user_id from line_user_id
$userId = null;
if ($lineUserId) {
    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $userId = $stmt->fetchColumn();
}

try {
    switch ($action) {
        case 'list':
            // Get all medication reminders - Requirement 15.1
            if (!$userId && !$lineUserId) {
                echo json_encode(['success' => true, 'reminders' => []]);
                exit;
            }
            
            $sql = "SELECT r.*, 
                           (SELECT COUNT(*) FROM medication_taken_history h 
                            WHERE h.reminder_id = r.id AND h.status = 'taken' 
                            AND DATE(h.taken_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as taken_count_7d,
                           (SELECT COUNT(*) FROM medication_taken_history h 
                            WHERE h.reminder_id = r.id AND h.status = 'missed' 
                            AND DATE(h.taken_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as missed_count_7d
                    FROM medication_reminders r
                    WHERE r.user_id = ? AND r.is_active = 1
                    ORDER BY r.created_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate adherence for each reminder - Requirement 15.7
            foreach ($reminders as &$reminder) {
                $reminder['reminder_times'] = json_decode($reminder['reminder_times'], true) ?: [];
                $totalDoses = $reminder['taken_count_7d'] + $reminder['missed_count_7d'];
                $reminder['adherence_percent'] = $totalDoses > 0 
                    ? round(($reminder['taken_count_7d'] / $totalDoses) * 100) 
                    : 100;
            }
            
            echo json_encode(['success' => true, 'reminders' => $reminders]);
            break;
            
        case 'add':
            // Add new medication reminder - Requirements 15.2, 15.3
            if (!$userId) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            $medicationName = $input['medication_name'] ?? '';
            $dosage = $input['dosage'] ?? '';
            $frequency = $input['frequency'] ?? 'daily';
            $reminderTimes = $input['reminder_times'] ?? ['08:00'];
            $startDate = $input['start_date'] ?? date('Y-m-d');
            $endDate = $input['end_date'] ?? null;
            $notes = $input['notes'] ?? '';
            $productId = $input['product_id'] ?? null;
            $orderId = $input['order_id'] ?? null;
            
            if (empty($medicationName)) {
                echo json_encode(['success' => false, 'error' => 'กรุณาระบุชื่อยา']);
                exit;
            }
            
            $stmt = $db->prepare("INSERT INTO medication_reminders 
                (user_id, line_user_id, line_account_id, medication_name, dosage, frequency, 
                 reminder_times, start_date, end_date, notes, product_id, order_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId, $lineUserId, $lineAccountId, $medicationName, $dosage, $frequency,
                json_encode($reminderTimes), $startDate, $endDate, $notes, $productId, $orderId
            ]);
            
            $reminderId = $db->lastInsertId();
            
            echo json_encode([
                'success' => true, 
                'reminder_id' => $reminderId,
                'message' => 'เพิ่มการเตือนทานยาแล้ว'
            ]);
            break;
            
        case 'update':
            // Update medication reminder
            if (!$userId) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            $reminderId = $input['reminder_id'] ?? 0;
            
            // Verify ownership
            $stmt = $db->prepare("SELECT id FROM medication_reminders WHERE id = ? AND user_id = ?");
            $stmt->execute([$reminderId, $userId]);
            if (!$stmt->fetchColumn()) {
                echo json_encode(['success' => false, 'error' => 'Reminder not found']);
                exit;
            }
            
            $updates = [];
            $params = [];
            
            $allowedFields = ['medication_name', 'dosage', 'frequency', 'reminder_times', 
                             'start_date', 'end_date', 'notes', 'is_active'];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "$field = ?";
                    $value = $input[$field];
                    if ($field === 'reminder_times' && is_array($value)) {
                        $value = json_encode($value);
                    }
                    $params[] = $value;
                }
            }
            
            if (!empty($updates)) {
                $params[] = $reminderId;
                $sql = "UPDATE medication_reminders SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }
            
            echo json_encode(['success' => true, 'message' => 'อัพเดทการเตือนแล้ว']);
            break;
            
        case 'delete':
            // Delete (deactivate) medication reminder
            if (!$userId) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            $reminderId = $input['reminder_id'] ?? 0;
            
            $stmt = $db->prepare("UPDATE medication_reminders SET is_active = 0 WHERE id = ? AND user_id = ?");
            $stmt->execute([$reminderId, $userId]);
            
            echo json_encode(['success' => true, 'message' => 'ลบการเตือนแล้ว']);
            break;
            
        case 'mark_taken':
            // Mark medication as taken - Requirements 15.5, 15.6
            if (!$userId) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            $reminderId = $input['reminder_id'] ?? 0;
            $scheduledTime = $input['scheduled_time'] ?? null;
            $status = $input['status'] ?? 'taken';
            $notes = $input['notes'] ?? '';
            
            // Verify ownership
            $stmt = $db->prepare("SELECT id FROM medication_reminders WHERE id = ? AND user_id = ?");
            $stmt->execute([$reminderId, $userId]);
            if (!$stmt->fetchColumn()) {
                echo json_encode(['success' => false, 'error' => 'Reminder not found']);
                exit;
            }
            
            $stmt = $db->prepare("INSERT INTO medication_taken_history 
                (reminder_id, user_id, scheduled_time, status, notes)
                VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$reminderId, $userId, $scheduledTime, $status, $notes]);
            
            echo json_encode([
                'success' => true, 
                'message' => $status === 'taken' ? 'บันทึกการทานยาแล้ว' : 'บันทึกแล้ว'
            ]);
            break;
            
        case 'history':
            // Get medication history - Requirement 15.7
            if (!$userId) {
                echo json_encode(['success' => true, 'history' => []]);
                exit;
            }
            
            $reminderId = $input['reminder_id'] ?? $_GET['reminder_id'] ?? null;
            $days = $input['days'] ?? $_GET['days'] ?? 7;
            
            $sql = "SELECT h.*, r.medication_name, r.dosage
                    FROM medication_taken_history h
                    JOIN medication_reminders r ON h.reminder_id = r.id
                    WHERE h.user_id = ? 
                    AND DATE(h.taken_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
            $params = [$userId, $days];
            
            if ($reminderId) {
                $sql .= " AND h.reminder_id = ?";
                $params[] = $reminderId;
            }
            
            $sql .= " ORDER BY h.taken_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'history' => $history]);
            break;
            
        case 'adherence':
            // Get adherence statistics - Requirement 15.7
            if (!$userId) {
                echo json_encode(['success' => true, 'adherence' => []]);
                exit;
            }
            
            $days = $input['days'] ?? $_GET['days'] ?? 7;
            
            $sql = "SELECT 
                        r.id, r.medication_name,
                        COUNT(CASE WHEN h.status = 'taken' THEN 1 END) as taken_count,
                        COUNT(CASE WHEN h.status = 'missed' THEN 1 END) as missed_count,
                        COUNT(CASE WHEN h.status = 'skipped' THEN 1 END) as skipped_count
                    FROM medication_reminders r
                    LEFT JOIN medication_taken_history h ON r.id = h.reminder_id 
                        AND DATE(h.taken_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                    WHERE r.user_id = ? AND r.is_active = 1
                    GROUP BY r.id";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$days, $userId]);
            $adherence = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate percentages
            foreach ($adherence as &$item) {
                $total = $item['taken_count'] + $item['missed_count'];
                $item['adherence_percent'] = $total > 0 
                    ? round(($item['taken_count'] / $total) * 100) 
                    : 100;
            }
            
            echo json_encode(['success' => true, 'adherence' => $adherence]);
            break;
            
        case 'from_order':
            // Get medications from order history - Requirement 15.2
            if (!$userId) {
                echo json_encode(['success' => true, 'medications' => []]);
                exit;
            }
            
            $sql = "SELECT DISTINCT oi.product_id, oi.name as medication_name, 
                           p.usage as dosage_info, o.id as order_id
                    FROM order_items oi
                    JOIN orders o ON oi.order_id = o.id
                    LEFT JOIN business_items p ON oi.product_id = p.id
                    WHERE o.user_id = ? AND o.status IN ('delivered', 'completed')
                    ORDER BY o.created_at DESC
                    LIMIT 20";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'medications' => $medications]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
