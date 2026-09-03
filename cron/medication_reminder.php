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

/**
 * รอบเตือนของหนึ่งฐานข้อมูล — driver ท้ายไฟล์เรียกซ้ำต่อ tenant
 *
 * @return array{notified:int,skipped:int,errors:int}
 */
function reya_run_medication_reminders(PDO $db): array
{
    // ตารางบันทึกการส่งต้องมีก่อน query กันส่งซ้ำด้านล่าง เดิม cron สร้างตารางนี้
    // ใน logReminderSent() ซึ่งทำงานทีหลัง จึง fatal ทุกครั้งที่มี reminder ตรงเวลา
    // จริงบน DB ที่ยังไม่มีตาราง
    ensureReminderLogTable($db);

    // reminder เก่าบางแถวมี line_account_id ว่าง ทำให้ JOIN หลุดและถูกกรองทิ้งเงียบ ๆ
    // ใช้บัญชี LINE ที่ใช้งานได้ของ tenant นั้นเป็นค่าสำรอง
    $fallbackAccountId = defaultLineAccountId($db);


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
            LEFT JOIN line_accounts la
               ON la.id = COALESCE(r.line_account_id, u.line_account_id, {$fallbackAccountId})
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
                'line_account_id' => $reminder['line_account_id'] ?: $fallbackAccountId,
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
                  LEFT JOIN line_accounts la
                     ON la.id = COALESCE(r.line_account_id, u.line_account_id, {$fallbackAccountId})
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
                'line_account_id' => $row['line_account_id'] ?: $fallbackAccountId,
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

    echo "  -> notified={$notified} skipped={$skipped} errors={$errors}\n";

    return ['notified' => $notified, 'skipped' => $skipped, 'errors' => $errors];
}

/**
 * บัญชี LINE ที่ใช้ส่งได้ของ tenant นี้ ใช้เป็นค่าสำรองเมื่อ reminder ไม่ระบุบัญชี
 */
function defaultLineAccountId(PDO $db): int
{
    try {
        $id = $db->query(
            "SELECT id FROM line_accounts
             WHERE channel_access_token IS NOT NULL AND channel_access_token <> ''
             ORDER BY is_default DESC, is_active DESC, id ASC LIMIT 1"
        )->fetchColumn();
        return (int) ($id ?: 0);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * ตารางบันทึกการส่ง สร้างครั้งเดียวต่อ DB ต้องมีก่อน query กันส่งซ้ำ
 */
function ensureReminderLogTable(PDO $db): void
{
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
        echo "  ! could not ensure medication_reminder_logs: " . $e->getMessage() . "\n";
    }
}

// ---- Driver ----------------------------------------------------------------
// CLI (cron): กวาดทุก tenant ที่ active ผ่าน platform registry — reminder จริง
// อยู่ใน reya_tenant_* ไม่ใช่ DB fallback ตัวเดียวที่โค้ดเดิมต่อไปหา
// เดินตามรูปแบบเดียวกับ cron/appointment_reminder.php รวมถึงการคืน connection
// ต่อ tenant ก่อนวนรอบถัดไป ไม่งั้นจะชน max_user_connections
$total = ['notified' => 0, 'skipped' => 0, 'errors' => 0];

$tenantIds = [];
try {
    $tenantIds = Database::platform()->getConnection()
        ->query("SELECT id FROM tenants WHERE status = 'active' ORDER BY id")
        ->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    echo "Platform registry unavailable, falling back to default DB: " . $e->getMessage() . "\n";
}

if (!$tenantIds) {
    $total = reya_run_medication_reminders(Database::getInstance()->getConnection());
} else {
    echo "Sweeping " . count($tenantIds) . " active tenant(s)...\n\n";
    foreach ($tenantIds as $tid) {
        echo "[tenant #{$tid}]\n";
        $tdb = null;
        try {
            $tdb = Database::forTenant((int) $tid)->getConnection();
            $r = reya_run_medication_reminders($tdb);
            foreach ($total as $k => $_) {
                $total[$k] += $r[$k];
            }
        } catch (Throwable $e) {
            echo "  error: " . $e->getMessage() . "\n";
            $total['errors']++;
        } finally {
            $tdb = null;
            Database::releaseTenant((int) $tid);
        }
    }
}

echo "\n=== Summary (all tenants) ===\n";
echo "Notified: {$total['notified']}\n";
echo "Skipped (already sent): {$total['skipped']}\n";
echo "Errors: {$total['errors']}\n";
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
                        // flex 0 บนไอคอน + flex 1 บนกล่องข้อความ ไม่ให้ไอคอนกินความกว้าง
                        // และ shrink-to-fit ย่ออักษรให้พอดีบรรทัดเดียวแทนการตัดท้ายด้วย "..."
                        ['type' => 'text', 'text' => '💊', 'size' => 'xxl', 'flex' => 0],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'flex' => 1,
                            'contents' => [
                                ['type' => 'text', 'text' => 'แจ้งเตือนทานยา', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg', 'adjustMode' => 'shrink-to-fit'],
                                ['type' => 'text', 'text' => date('d/m/Y'), 'color' => '#FFFFFF', 'size' => 'xs', 'margin' => 'xs', 'adjustMode' => 'shrink-to-fit']
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
            // แนวตั้ง ไม่ใช่แนวนอน — ปุ่มคู่กันแนวนอนได้ความกว้างครึ่งใบ ทำให้
            // "⏰ เตือนอีกครั้ง" ถูกตัดเหลือ "เตือน..." บนเครื่องจริง
            'type' => 'box',
            'layout' => 'vertical',
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
