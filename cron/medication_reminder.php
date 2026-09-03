<?php
/**
 * Medication Reminder Notification
 * แจ้งเตือนผู้ใช้ให้ทานยาตามเวลาที่ตั้งไว้
 * 
 * Run: php cron/medication_reminder.php
 * Schedule: Every 15 minutes (* / 15 * * * *)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/NotificationGate.php';

$db = Database::getInstance()->getConnection();

echo "=== Medication Reminder Notification ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// Get current time rounded to nearest 15 minutes
$currentHour = date('H');
$currentMinute = floor(date('i') / 15) * 15;
$currentTime = sprintf('%02d:%02d', $currentHour, $currentMinute);
$currentTimeEnd = sprintf('%02d:%02d', $currentHour, $currentMinute + 14);

echo "Checking reminders for time range: {$currentTime} - {$currentTimeEnd}\n\n";

// Find active reminders that should be sent now
// reminder_times is stored as JSON array like ["08:00", "12:00", "18:00"]
$sql = "SELECT r.*, 
               u.line_user_id, u.display_name,
               la.channel_access_token,
               unp.drug_reminders as notify_enabled
        FROM medication_reminders r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN line_accounts la ON r.line_account_id = la.id
        LEFT JOIN user_notification_preferences unp ON r.user_id = unp.user_id
        WHERE r.is_active = 1
          AND (r.start_date IS NULL OR r.start_date <= CURDATE())
          AND (r.end_date IS NULL OR r.end_date >= CURDATE())
          AND (unp.drug_reminders IS NULL OR unp.drug_reminders = 1)
          AND u.line_user_id IS NOT NULL
          AND la.channel_access_token IS NOT NULL";

$stmt = $db->query($sql);
$reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($reminders) . " active reminders\n\n";

$notified = 0;
$errors = 0;
$skipped = 0;

foreach ($reminders as $reminder) {
    // Parse reminder times
    $reminderTimes = json_decode($reminder['reminder_times'], true) ?: [];

    // Check if current time matches any reminder time
    $shouldNotify = false;
    $matchedTime = '';

    foreach ($reminderTimes as $time) {
        // Parse time (format: HH:MM)
        $timeParts = explode(':', $time);
        $reminderHour = (int) $timeParts[0];
        $reminderMinute = isset($timeParts[1]) ? (int) $timeParts[1] : 0;

        // Round to nearest 15 minutes
        $reminderMinuteRounded = floor($reminderMinute / 15) * 15;

        if ($reminderHour == $currentHour && $reminderMinuteRounded == $currentMinute) {
            $shouldNotify = true;
            $matchedTime = $time;
            break;
        }
    }

    if (!$shouldNotify) {
        continue;
    }

    // Check if already notified today for this time
    $stmt = $db->prepare("
        SELECT id FROM medication_reminder_logs 
        WHERE reminder_id = ? 
        AND DATE(sent_at) = CURDATE() 
        AND reminder_time = ?
    ");
    $stmt->execute([$reminder['id'], $matchedTime]);
    if ($stmt->fetchColumn()) {
        $skipped++;
        continue;
    }

    echo "Processing: {$reminder['medication_name']}\n";
    echo "  User: {$reminder['display_name']} ({$reminder['line_user_id']})\n";
    echo "  Time: {$matchedTime}\n";
    echo "  Dosage: {$reminder['dosage']}\n";

    // Create Flex Message
    $flexMessage = createMedicationReminderFlex($reminder, $matchedTime);

    // Send via LINE API
    try {
        // ผ่าน NotificationGate เสมอ — เตือนทานยาได้รับการยกเว้นช่วงห้ามรบกวน
        // และเพดานต่อวัน เพราะลูกค้าเป็นคนตั้งเวลานั้นเอง (NotificationGate::POLICY)
        $gate = new NotificationGate($db);
        $result = $gate->send([
            'user_id' => (int) $reminder['user_id'],
            'line_user_id' => $reminder['line_user_id'],
            'line_account_id' => $reminder['line_account_id'] ?? null,
            'channel_access_token' => $reminder['channel_access_token'],
            'event_type' => 'medication_dose',
            'dedupe_key' => 'dose:' . $reminder['id'] . ':' . $matchedTime . ':' . date('Y-m-d'),
            'messages' => [$flexMessage],
        ])['sent'];

        if ($result) {
            // Log the notification
            logReminderSent($db, $reminder['id'], $matchedTime, 'sent');

            echo "  SUCCESS: Notification sent\n\n";
            $notified++;
        } else {
            logReminderSent($db, $reminder['id'], $matchedTime, 'failed');
            echo "  ERROR: Failed to send\n\n";
            $errors++;
        }
    } catch (Exception $e) {
        logReminderSent($db, $reminder['id'], $matchedTime, 'error', $e->getMessage());
        echo "  ERROR: " . $e->getMessage() . "\n\n";
        $errors++;
    }
}

// ---------------------------------------------------------------------------
// รอบเก็บงาน "เลื่อนเตือน" — ลูกค้ากด ⏰ เตือนอีกครั้ง บน Flex
// MemberPostbackRouter เขียน snooze_until ไว้ รอบนี้มาหยิบที่ถึงเวลาแล้ว
// เคลียร์ snooze_until เป็น NULL หลังส่ง แถวยังคง status='snoozed' ไว้เป็น
// หลักฐาน adherence ว่าลูกค้าเลื่อนสล็อตนั้น
// ---------------------------------------------------------------------------
$snoozeSql = "SELECT h.id AS history_id, h.scheduled_time,
                     r.*, u.line_user_id, u.display_name, la.channel_access_token
              FROM medication_taken_history h
              JOIN medication_reminders r ON h.reminder_id = r.id
              JOIN users u ON r.user_id = u.id
              LEFT JOIN line_accounts la ON r.line_account_id = la.id
              WHERE h.status = 'snoozed'
                AND h.snooze_until IS NOT NULL
                AND h.snooze_until <= NOW()
                AND r.is_active = 1
                AND u.line_user_id IS NOT NULL
                AND la.channel_access_token IS NOT NULL
              LIMIT 200";

try {
    $snoozed = $db->query($snoozeSql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // tenant นี้ยังไม่ได้รัน migration_2026-09-03_member_flex_flow.sql
    $snoozed = [];
    echo "Snooze pickup skipped: " . $e->getMessage() . "\n";
}

echo "Snoozed due now: " . count($snoozed) . "\n";

foreach ($snoozed as $row) {
    $slot = substr((string) $row['scheduled_time'], 0, 5);

    try {
        $gate = new NotificationGate($db);
        $sent = $gate->send([
            'user_id' => (int) $row['user_id'],
            'line_user_id' => $row['line_user_id'],
            'line_account_id' => $row['line_account_id'] ?? null,
            'channel_access_token' => $row['channel_access_token'],
            'event_type' => 'medication_dose',
            'dedupe_key' => 'dose:snooze:' . $row['history_id'],
            'messages' => [createMedicationReminderFlex($row, $slot)],
        ])['sent'];

        // เคลียร์คิวไม่ว่าจะส่งผ่านหรือไม่ กันวนส่งซ้ำทุกรอบ cron
        $clear = $db->prepare("UPDATE medication_taken_history SET snooze_until = NULL WHERE id = ?");
        $clear->execute([(int) $row['history_id']]);

        if ($sent) {
            $notified++;
        } else {
            $skipped++;
        }
    } catch (Exception $e) {
        echo "  Snooze resend error: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "=== Summary ===\n";
echo "Notified: {$notified}\n";
echo "Skipped (already sent): {$skipped}\n";
echo "Errors: {$errors}\n";
echo "Done!\n";

/**
 * Create medication reminder Flex Message
 */
function createMedicationReminderFlex($reminder, $time)
{
    $medicationName = $reminder['medication_name'];
    $dosage = $reminder['dosage'] ?: '';
    $notes = $reminder['notes'] ?: '';

    // Determine icon based on frequency
    $frequency = $reminder['frequency'] ?? 'daily';
    $frequencyText = [
        'once' => 'ครั้งเดียว',
        'daily' => 'ทุกวัน',
        'twice_daily' => 'วันละ 2 ครั้ง',
        'three_times_daily' => 'วันละ 3 ครั้ง',
        'weekly' => 'สัปดาห์ละครั้ง',
        'as_needed' => 'เมื่อจำเป็น'
    ][$frequency] ?? $frequency;

    $bodyContents = [
        [
            'type' => 'text',
            'text' => '⏰ ถึงเวลาทานยาแล้ว!',
            'weight' => 'bold',
            'color' => '#11B0A6',
            'size' => 'md'
        ],
        [
            'type' => 'text',
            'text' => $medicationName,
            'weight' => 'bold',
            'size' => 'xl',
            'wrap' => true,
            'margin' => 'md'
        ],
        [
            'type' => 'separator',
            'margin' => 'lg'
        ],
        [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'lg',
            'contents' => [
                ['type' => 'text', 'text' => '💊 ขนาดยา', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                ['type' => 'text', 'text' => $dosage ?: '-', 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
            ]
        ],
        [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'sm',
            'contents' => [
                ['type' => 'text', 'text' => '🕐 เวลา', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                ['type' => 'text', 'text' => $time . ' น.', 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
            ]
        ],
        [
            'type' => 'box',
            'layout' => 'horizontal',
            'margin' => 'sm',
            'contents' => [
                ['type' => 'text', 'text' => '📅 ความถี่', 'size' => 'sm', 'color' => '#888888', 'flex' => 1],
                ['type' => 'text', 'text' => $frequencyText, 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
            ]
        ]
    ];

    // Add notes if present
    if ($notes) {
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'paddingAll' => 'md',
            'backgroundColor' => '#FEF3C7',
            'cornerRadius' => 'md',
            'contents' => [
                ['type' => 'text', 'text' => '📝 ' . $notes, 'size' => 'xs', 'color' => '#92400E', 'wrap' => true]
            ]
        ];
    }

    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#11B0A6',
            'paddingAll' => '15px',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => '💊', 'size' => 'xxl'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'contents' => [
                                ['type' => 'text', 'text' => 'แจ้งเตือนทานยา', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg'],
                                ['type' => 'text', 'text' => date('d/m/Y'), 'color' => '#FFFFFF', 'size' => 'xs', 'margin' => 'xs']
                            ]
                        ]
                    ]
                ]
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => $bodyContents
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'horizontal',
            'paddingAll' => '15px',
            'spacing' => 'sm',
            'contents' => [
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'postback',
                        'label' => '✅ ทานแล้ว',
                        'data' => "action=medication_taken&reminder_id={$reminder['id']}&time={$time}"
                    ],
                    'style' => 'primary',
                    'color' => '#10B981',
                    'height' => 'sm'
                ],
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'postback',
                        'label' => '⏰ เตือนอีกครั้ง',
                        'data' => "action=medication_snooze&reminder_id={$reminder['id']}&time={$time}"
                    ],
                    'style' => 'secondary',
                    'height' => 'sm'
                ]
            ]
        ]
    ];

    return [
        'type' => 'flex',
        'altText' => "💊 ถึงเวลาทานยา: {$medicationName}",
        'contents' => $bubble
    ];
}

/**
 * Log reminder sent
 */
function logReminderSent($db, $reminderId, $time, $status, $error = null)
{
    // Ensure log table exists
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS medication_reminder_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reminder_id INT NOT NULL,
            reminder_time VARCHAR(10),
            status VARCHAR(20) DEFAULT 'sent',
            error_message TEXT,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_reminder (reminder_id),
            INDEX idx_date (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
    }

    try {
        $stmt = $db->prepare("INSERT INTO medication_reminder_logs 
            (reminder_id, reminder_time, status, error_message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$reminderId, $time, $status, $error]);
    } catch (Exception $e) {
        error_log("Failed to log medication reminder: " . $e->getMessage());
    }
}
