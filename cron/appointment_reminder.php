<?php
/**
 * Appointment Reminder Cron Job
 * ส่งแจ้งเตือนนัดหมาย 2 ครั้ง:
 * 1. ก่อนถึงเวลา 10 นาที (reminder_10min_sent)
 * 2. ตอนถึงเวลานัด (reminder_now_sent)
 * 
 * Cron: * * * * * (ทุกนาที)
 * 
 * Test URL: /cron/appointment_reminder.php?key=appointment_cron_2025&debug=1
 */

$allowedKey = 'appointment_cron_2025';
$isCli = php_sapi_name() === 'cli';
$hasValidKey = isset($_GET['key']) && $_GET['key'] === $allowedKey;
$debugMode = isset($_GET['debug']) && $_GET['debug'] == '1';

if (!$isCli && !$hasValidKey) {
    http_response_code(403);
    die('Access denied');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// LIFF ID สำหรับ Video Call
define('VIDEO_CALL_LIFF_ID', '2008477880-FDhymfKU');

// Set while sweeping a tenant so each logged line carries its tenant id.
// Replaces the old per-tenant "--- tenant #N ---" header, which printed even
// when that tenant had nothing to do.
$GLOBALS['reya_tenant_tag'] = '';

function logMsg($msg, $isDebug = false) {
    global $debugMode;
    $tag = $GLOBALS['reya_tenant_tag'] ?? '';
    if ($debugMode) {
        echo "<pre>" . date('Y-m-d H:i:s') . " - " . htmlspecialchars($tag . $msg) . "</pre>\n";
    } else {
        echo date('Y-m-d H:i:s') . " - {$tag}{$msg}\n";
    }
}

/**
 * Run the reminder sweep against a single (already-selected) tenant DB.
 * Extracted so the CLI cron can loop every tenant DB — the original single-DB
 * body only ever hit the legacy fallback DB and never saw real bookings.
 */
function reya_run_reminders($db, $debugMode) {
// Auto-add columns if not exist
try {
    $cols = $db->query("DESCRIBE appointments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reminder_10min_sent', $cols)) {
        $db->exec("ALTER TABLE appointments ADD COLUMN reminder_10min_sent TINYINT(1) DEFAULT 0");
        logMsg("Added column: reminder_10min_sent");
    }
    if (!in_array('reminder_now_sent', $cols)) {
        $db->exec("ALTER TABLE appointments ADD COLUMN reminder_now_sent TINYINT(1) DEFAULT 0");
        logMsg("Added column: reminder_now_sent");
    }
    if (!in_array('cancelled_reason', $cols)) {
        $db->exec("ALTER TABLE appointments ADD COLUMN cancelled_reason TEXT NULL");
        logMsg("Added column: cancelled_reason");
    }
} catch (Exception $e) {
    logMsg("Column check error: " . $e->getMessage());
}

if ($debugMode) {
    echo "<h2>🔔 Appointment Reminder Debug</h2>";
    echo "<p><strong>Current Time:</strong> " . date('Y-m-d H:i:s') . "</p>";
}

if ($debugMode) {
    logMsg("Starting appointment reminder check...");
}

try {
    // ===== DEBUG: Show all upcoming appointments =====
    if ($debugMode) {
        $stmt = $db->query("
            SELECT a.id, a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                   a.reminder_10min_sent, a.reminder_now_sent,
                   u.line_user_id, u.display_name,
                   p.name as pharmacist_name,
                   la.channel_access_token IS NOT NULL as has_token
            FROM appointments a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN pharmacists p ON a.pharmacist_id = p.id
            LEFT JOIN line_accounts la ON a.line_account_id = la.id
            WHERE a.status = 'confirmed'
              AND a.appointment_date >= CURDATE()
            ORDER BY a.appointment_date, a.appointment_time
            LIMIT 20
        ");
        $allAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>📋 Upcoming Confirmed Appointments (" . count($allAppointments) . ")</h3>";
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
        echo "<tr><th>ID</th><th>Apt ID</th><th>Date</th><th>Time</th><th>User</th><th>Pharmacist</th><th>10min</th><th>Now</th><th>Token</th><th>Status</th></tr>";
        
        foreach ($allAppointments as $apt) {
            $aptDateTime = $apt['appointment_date'] . ' ' . $apt['appointment_time'];
            $aptTs = strtotime($aptDateTime);
            $nowTs = time();
            $diffMins = round(($aptTs - $nowTs) / 60);
            
            $statusColor = 'gray';
            $statusText = "In {$diffMins} mins";
            if ($diffMins <= 0) {
                $statusColor = 'red';
                $statusText = "Past ({$diffMins} mins)";
            } elseif ($diffMins <= 5) {
                $statusColor = 'green';
                $statusText = "NOW! ({$diffMins} mins)";
            } elseif ($diffMins <= 15) {
                $statusColor = 'orange';
                $statusText = "Soon ({$diffMins} mins)";
            }
            
            echo "<tr>";
            echo "<td>{$apt['id']}</td>";
            echo "<td>{$apt['appointment_id']}</td>";
            echo "<td>{$apt['appointment_date']}</td>";
            echo "<td>{$apt['appointment_time']}</td>";
            echo "<td>" . ($apt['display_name'] ?: $apt['line_user_id']) . "</td>";
            echo "<td>{$apt['pharmacist_name']}</td>";
            echo "<td>" . ($apt['reminder_10min_sent'] ? '✅' : '❌') . "</td>";
            echo "<td>" . ($apt['reminder_now_sent'] ? '✅' : '❌') . "</td>";
            echo "<td>" . ($apt['has_token'] ? '✅' : '❌') . "</td>";
            echo "<td style='color:{$statusColor}'>{$statusText}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<hr>";
    }

    // ===== แจ้งเตือนครั้งที่ 1: ก่อน 10 นาที =====
    // ใช้ TIMESTAMPDIFF แทน CONCAT เพื่อความแม่นยำ
    // Note: ใช้เฉพาะ columns ที่มีอยู่จริงในตาราง pharmacists
    $sql10min = "
        SELECT a.*, u.line_user_id, u.display_name as user_name, u.first_name, u.last_name,
               p.name as pharmacist_name, 
               COALESCE(p.title, '') as pharmacist_title, 
               COALESCE(p.image_url, '') as pharmacist_image,
               COALESCE(p.specialty, 'เภสัชกร') as specialty, 
               COALESCE(p.license_no, '') as license_no, 
               COALESCE(p.hospital, '') as hospital, 
               COALESCE(p.rating, 5.0) as pharmacist_rating,
               COALESCE(p.consultation_fee, 0) as consultation_fee, 
               COALESCE(p.consultation_duration, 15) as consultation_duration,
               la.channel_access_token, la.id as line_account_id
        FROM appointments a
        JOIN users u ON a.user_id = u.id
        JOIN pharmacists p ON a.pharmacist_id = p.id
        JOIN line_accounts la ON a.line_account_id = la.id
        WHERE a.status = 'confirmed'
          AND (a.reminder_10min_sent = 0 OR a.reminder_10min_sent IS NULL)
          AND TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(a.appointment_date, ' ', a.appointment_time)) BETWEEN 8 AND 12
          AND la.channel_access_token IS NOT NULL
          AND la.channel_access_token != ''
    ";
    
    $stmt = $db->query($sql10min);
    $appointments10min = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($appointments10min) > 0 || $debugMode) {
        logMsg("Found " . count($appointments10min) . " appointments for 10-min reminder");
    }
    
    if ($debugMode && count($appointments10min) == 0) {
        echo "<p>⚠️ No appointments found for 10-min reminder. Checking query...</p>";
        
        // Debug query
        $debugStmt = $db->query("
            SELECT a.appointment_id, a.appointment_date, a.appointment_time, a.status,
                   a.reminder_10min_sent,
                   TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(a.appointment_date, ' ', a.appointment_time)) as mins_until,
                   la.channel_access_token IS NOT NULL as has_token
            FROM appointments a
            LEFT JOIN line_accounts la ON a.line_account_id = la.id
            WHERE a.status = 'confirmed'
              AND TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(a.appointment_date, ' ', a.appointment_time)) BETWEEN 0 AND 30
        ");
        $debugResults = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($debugResults) > 0) {
            echo "<p>Found " . count($debugResults) . " appointments within 30 mins:</p>";
            echo "<ul>";
            foreach ($debugResults as $d) {
                echo "<li>{$d['appointment_id']}: {$d['mins_until']} mins, 10min_sent={$d['reminder_10min_sent']}, has_token={$d['has_token']}</li>";
            }
            echo "</ul>";
        }
    }
    
    foreach ($appointments10min as $apt) {
        if (empty($apt['line_user_id']) || empty($apt['channel_access_token'])) {
            logMsg("Skipping {$apt['appointment_id']}: missing line_user_id or token");
            continue;
        }
        
        $flexMessage = buildReminder10MinFlex($apt);
        
        if ($debugMode) {
            echo "<h4>📤 Sending 10-min reminder for {$apt['appointment_id']}</h4>";
            echo "<p>To: {$apt['line_user_id']}</p>";
        }
        
        $result = sendLineMessage($apt['channel_access_token'], $apt['line_user_id'], $flexMessage);
        
        if ($result['success']) {
            $db->prepare("UPDATE appointments SET reminder_10min_sent = 1 WHERE id = ?")->execute([$apt['id']]);
            logMsg("✓ Sent 10-min reminder for {$apt['appointment_id']}");
        } else {
            logMsg("✗ Failed 10-min reminder for {$apt['appointment_id']}: {$result['error']}");
        }
    }
    
    // ===== แจ้งเตือนครั้งที่ 2: ถึงเวลาแล้ว =====
    $sqlNow = "
        SELECT a.*, u.line_user_id, u.display_name as user_name, u.first_name, u.last_name,
               p.name as pharmacist_name, 
               COALESCE(p.title, '') as pharmacist_title, 
               COALESCE(p.image_url, '') as pharmacist_image,
               COALESCE(p.specialty, 'เภสัชกร') as specialty, 
               COALESCE(p.license_no, '') as license_no, 
               COALESCE(p.hospital, '') as hospital, 
               COALESCE(p.rating, 5.0) as pharmacist_rating,
               COALESCE(p.consultation_fee, 0) as consultation_fee, 
               COALESCE(p.consultation_duration, 15) as consultation_duration,
               la.channel_access_token, la.id as line_account_id
        FROM appointments a
        JOIN users u ON a.user_id = u.id
        JOIN pharmacists p ON a.pharmacist_id = p.id
        JOIN line_accounts la ON a.line_account_id = la.id
        WHERE a.status = 'confirmed'
          AND (a.reminder_now_sent = 0 OR a.reminder_now_sent IS NULL)
          AND TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(a.appointment_date, ' ', a.appointment_time)) BETWEEN -2 AND 2
          AND la.channel_access_token IS NOT NULL
          AND la.channel_access_token != ''
    ";
    
    $stmt = $db->query($sqlNow);
    $appointmentsNow = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($appointmentsNow) > 0 || $debugMode) {
        logMsg("Found " . count($appointmentsNow) . " appointments for NOW reminder");
    }
    
    foreach ($appointmentsNow as $apt) {
        if (empty($apt['line_user_id']) || empty($apt['channel_access_token'])) {
            logMsg("Skipping {$apt['appointment_id']}: missing line_user_id or token");
            continue;
        }
        
        $flexMessage = buildReminderNowFlex($apt);
        
        if ($debugMode) {
            echo "<h4>📤 Sending NOW reminder for {$apt['appointment_id']}</h4>";
            echo "<p>To: {$apt['line_user_id']}</p>";
        }
        
        $result = sendLineMessage($apt['channel_access_token'], $apt['line_user_id'], $flexMessage);
        
        if ($result['success']) {
            $db->prepare("UPDATE appointments SET reminder_now_sent = 1 WHERE id = ?")->execute([$apt['id']]);
            logMsg("✓ Sent NOW reminder for {$apt['appointment_id']}");
        } else {
            logMsg("✗ Failed NOW reminder for {$apt['appointment_id']}: {$result['error']}");
        }
    }
    
} catch (Exception $e) {
    logMsg("Error: " . $e->getMessage());
    if ($debugMode) {
        echo "<pre style='color:red'>" . $e->getTraceAsString() . "</pre>";
    }
}
} // end reya_run_reminders()

// ---- Driver ----------------------------------------------------------------
// CLI (cron): sweep EVERY active tenant DB via the platform registry, so
// reminders fire for real bookings (which live in reya_tenant_* DBs, not the
// legacy fallback DB the old single-connection code was hitting).
// Web (?key=...): the subdomain already resolved $db to one tenant — run it once.
if ($isCli) {
    $tenantIds = [];
    try {
        $platform = Database::platform()->getConnection();
        $tenantIds = $platform->query(
            "SELECT id FROM tenants WHERE status = 'active' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        logMsg("Platform registry unavailable, falling back to default DB: " . $e->getMessage());
    }

    if (empty($tenantIds)) {
        reya_run_reminders(Database::getInstance()->getConnection(), $debugMode);
    } else {
        logMsg("Sweeping " . count($tenantIds) . " active tenant(s)...");
        foreach ($tenantIds as $tid) {
            $GLOBALS['reya_tenant_tag'] = "[tenant #{$tid}] ";
            $tdb = null;
            try {
                $tdb = Database::forTenant((int) $tid)->getConnection();
                reya_run_reminders($tdb, $debugMode);
            } catch (\Throwable $e) {
                logMsg("error: " . $e->getMessage());
            } finally {
                // Hand this tenant's MySQL connection back before the next
                // iteration. Without this the pool keeps one open connection
                // per tenant for the whole run, so a sweep of N tenants holds
                // N+2 connections at once and trips max_user_connections.
                $tdb = null;
                Database::releaseTenant((int) $tid);
            }
        }
        $GLOBALS['reya_tenant_tag'] = '';
    }
} else {
    reya_run_reminders($db, $debugMode);
}

logMsg("Done.");

if ($debugMode) {
    echo "<hr><h3>🔧 Manual Test</h3>";
    echo "<p>To manually test sending a reminder, add <code>&test_apt=APT_ID</code> to the URL</p>";
    
    if (isset($_GET['test_apt'])) {
        $testAptId = $_GET['test_apt'];
        echo "<p>Testing appointment: {$testAptId}</p>";
        
        $stmt = $db->prepare("
            SELECT a.*, u.line_user_id, u.display_name as user_name, u.first_name, u.last_name,
                   p.name as pharmacist_name, 
                   COALESCE(p.title, '') as pharmacist_title, 
                   COALESCE(p.image_url, '') as pharmacist_image,
                   COALESCE(p.specialty, 'เภสัชกร') as specialty, 
                   COALESCE(p.license_no, '') as license_no, 
                   COALESCE(p.hospital, '') as hospital, 
                   COALESCE(p.rating, 5.0) as pharmacist_rating,
                   COALESCE(p.consultation_fee, 0) as consultation_fee, 
                   COALESCE(p.consultation_duration, 15) as consultation_duration,
                   la.channel_access_token, la.id as line_account_id
            FROM appointments a
            JOIN users u ON a.user_id = u.id
            JOIN pharmacists p ON a.pharmacist_id = p.id
            JOIN line_accounts la ON a.line_account_id = la.id
            WHERE a.appointment_id = ?
        ");
        $stmt->execute([$testAptId]);
        $testApt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($testApt) {
            echo "<p>Found appointment. Sending test reminder...</p>";
            $flexMessage = buildReminderNowFlex($testApt);
            $result = sendLineMessage($testApt['channel_access_token'], $testApt['line_user_id'], $flexMessage);
            
            if ($result['success']) {
                echo "<p style='color:green'>✅ Test message sent successfully!</p>";
            } else {
                echo "<p style='color:red'>❌ Failed: {$result['error']}</p>";
            }
        } else {
            echo "<p style='color:red'>Appointment not found</p>";
        }
    }
}

/**
 * Flex Message ครั้งที่ 1: ก่อน 10 นาที (สีส้ม - เตรียมตัว)
 */
function buildReminder10MinFlex($apt) {
    // Some tenant schemas have no varchar `appointment_id` column — fall back to
    // the numeric row id so the reference/deep-link is never blank.
    $apt['appointment_id'] = $apt['appointment_id'] ?? $apt['id'] ?? '';
    $baseUrl = rtrim(BASE_URL, '/');
    $appointmentDate = date('d/m/Y', strtotime($apt['appointment_date']));
    $appointmentTime = date('H:i', strtotime($apt['appointment_time']));
    $userName = $apt['first_name'] ?: $apt['user_name'] ?: 'คุณลูกค้า';
    
    // ข้อมูลเภสัชกร
    $specialty = $apt['specialty'] ?: 'เภสัชกรทั่วไป';
    $rating = $apt['pharmacist_rating'] ?: '4.9';
    $fee = $apt['consultation_fee'] > 0 ? number_format($apt['consultation_fee']) . ' บาท' : 'ฟรี';
    $duration = $apt['consultation_duration'] ?: 15;
    
    // ใช้ LIFF URL แทน URL ตรงๆ
    $liffBaseUrl = 'https://liff.line.me/' . VIDEO_CALL_LIFF_ID;
    $videoCallUrl = $liffBaseUrl . '?appointment=' . $apt['appointment_id'] . '&account=' . $apt['line_account_id'];
    
    // สร้าง pharmacist image box
    $pharmacistImageBox = $apt['pharmacist_image'] ? 
        ['type' => 'image', 'url' => $apt['pharmacist_image'], 'size' => 'full', 'aspectRatio' => '1:1', 'aspectMode' => 'cover'] :
        ['type' => 'text', 'text' => '👨‍⚕️', 'size' => 'xxl', 'align' => 'center', 'gravity' => 'center', 'offsetTop' => '15px'];
    
    $flex = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#FF9500',
            'paddingAll' => '20px',
            'contents' => [
                ['type' => 'text', 'text' => '⏰', 'size' => '3xl', 'align' => 'center'],
                ['type' => 'text', 'text' => 'เตรียมตัวให้พร้อม!', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'xl', 'align' => 'center', 'margin' => 'md'],
                ['type' => 'text', 'text' => 'อีก 10 นาทีจะถึงเวลานัด', 'color' => '#FFFFFF', 'size' => 'sm', 'align' => 'center']
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '20px',
            'spacing' => 'md',
            'contents' => [
                ['type' => 'text', 'text' => "สวัสดีค่ะ คุณ{$userName}", 'weight' => 'bold', 'size' => 'md'],
                ['type' => 'text', 'text' => 'กรุณาเตรียมตัวสำหรับการนัดหมาย', 'size' => 'sm', 'color' => '#666666', 'wrap' => true],
                ['type' => 'separator', 'margin' => 'lg'],
                // Pharmacist Card
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'lg',
                    'backgroundColor' => '#FFF5E6',
                    'cornerRadius' => '12px',
                    'paddingAll' => '12px',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'width' => '60px',
                            'height' => '60px',
                            'cornerRadius' => '30px',
                            'backgroundColor' => '#FFE0B2',
                            'contents' => [$pharmacistImageBox]
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'flex' => 1,
                            'margin' => 'md',
                            'contents' => [
                                ['type' => 'text', 'text' => $apt['pharmacist_name'], 'weight' => 'bold', 'size' => 'md', 'color' => '#333333'],
                                ['type' => 'text', 'text' => $specialty, 'size' => 'xs', 'color' => '#FF9500', 'weight' => 'bold'],
                                [
                                    'type' => 'box',
                                    'layout' => 'horizontal',
                                    'margin' => 'sm',
                                    'contents' => [
                                        ['type' => 'text', 'text' => '⭐ ' . $rating, 'size' => 'xs', 'color' => '#666666', 'flex' => 0],
                                        ['type' => 'text', 'text' => '•', 'size' => 'xs', 'color' => '#CCCCCC', 'margin' => 'sm', 'flex' => 0],
                                        ['type' => 'text', 'text' => $duration . ' นาที', 'size' => 'xs', 'color' => '#666666', 'margin' => 'sm', 'flex' => 0]
                                    ]
                                ]
                            ]
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [
                                ['type' => 'text', 'text' => $fee, 'size' => 'sm', 'weight' => 'bold', 'color' => '#FF9500', 'align' => 'end']
                            ]
                        ]
                    ]
                ],
                // Appointment Details
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'margin' => 'lg',
                    'backgroundColor' => '#F5F5F5',
                    'cornerRadius' => '12px',
                    'paddingAll' => '15px',
                    'contents' => [
                        ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
                            ['type' => 'text', 'text' => '📅 วันที่', 'size' => 'sm', 'color' => '#666666', 'flex' => 1],
                            ['type' => 'text', 'text' => $appointmentDate, 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
                        ]],
                        ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'contents' => [
                            ['type' => 'text', 'text' => '⏰ เวลา', 'size' => 'sm', 'color' => '#666666', 'flex' => 1],
                            ['type' => 'text', 'text' => $appointmentTime . ' น.', 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2, 'color' => '#FF9500']
                        ]],
                        ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'contents' => [
                            ['type' => 'text', 'text' => '🎫 รหัสนัด', 'size' => 'sm', 'color' => '#666666', 'flex' => 1],
                            ['type' => 'text', 'text' => $apt['appointment_id'], 'size' => 'xs', 'weight' => 'bold', 'align' => 'end', 'flex' => 2, 'color' => '#FF9500']
                        ]]
                    ]
                ],
                // Tips
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'margin' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '📋 เตรียมตัวก่อนโทร:', 'size' => 'sm', 'weight' => 'bold', 'color' => '#333333'],
                        ['type' => 'text', 'text' => '• หาที่เงียบและมีแสงสว่างเพียงพอ', 'size' => 'xs', 'color' => '#666666', 'margin' => 'sm'],
                        ['type' => 'text', 'text' => '• ตรวจสอบสัญญาณอินเทอร์เน็ต', 'size' => 'xs', 'color' => '#666666'],
                        ['type' => 'text', 'text' => '• เตรียมคำถามที่ต้องการปรึกษา', 'size' => 'xs', 'color' => '#666666']
                    ]
                ]
            ]
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'button', 'style' => 'primary', 'color' => '#FF9500', 'height' => 'md', 'action' => [
                    'type' => 'uri', 'label' => '📹  เตรียมพร้อม Video Call', 'uri' => $videoCallUrl
                ]]
            ]
        ]
    ];
    
    return ['type' => 'flex', 'altText' => "⏰ อีก 10 นาที! นัดหมายกับ{$apt['pharmacist_name']} ({$specialty}) เวลา {$appointmentTime} น.", 'contents' => $flex];
}

/**
 * Flex Message ครั้งที่ 2: ถึงเวลาแล้ว (สีเขียว - เริ่มโทรเลย)
 */
function buildReminderNowFlex($apt) {
    $apt['appointment_id'] = $apt['appointment_id'] ?? $apt['id'] ?? '';
    $appointmentTime = date('H:i', strtotime($apt['appointment_time']));
    $userName = $apt['first_name'] ?: $apt['user_name'] ?: 'คุณลูกค้า';
    
    // ข้อมูลเภสัชกร
    $specialty = $apt['specialty'] ?: 'เภสัชกรทั่วไป';
    $rating = $apt['pharmacist_rating'] ?: '4.9';
    $fee = $apt['consultation_fee'] > 0 ? number_format($apt['consultation_fee']) . ' บาท' : 'ฟรี';
    $duration = $apt['consultation_duration'] ?: 15;
    $hospital = $apt['hospital'] ?: '';
    $licenseNo = $apt['license_no'] ?: '';
    
    // ใช้ LIFF URL แทน URL ตรงๆ
    $liffBaseUrl = 'https://liff.line.me/' . VIDEO_CALL_LIFF_ID;
    $videoCallUrl = $liffBaseUrl . '?appointment=' . $apt['appointment_id'] . '&account=' . $apt['line_account_id'];
    
    // สร้าง pharmacist image
    $pharmacistImageContent = $apt['pharmacist_image'] ? 
        ['type' => 'image', 'url' => $apt['pharmacist_image'], 'size' => 'full', 'aspectRatio' => '1:1', 'aspectMode' => 'cover'] :
        ['type' => 'text', 'text' => '👨‍⚕️', 'size' => 'xxl', 'align' => 'center', 'gravity' => 'center', 'offsetTop' => '18px'];
    
    // สร้าง details array
    $detailsContents = [
        ['type' => 'box', 'layout' => 'horizontal', 'contents' => [
            ['type' => 'text', 'text' => '🎫 รหัสนัด', 'size' => 'sm', 'color' => '#666666', 'flex' => 1],
            ['type' => 'text', 'text' => $apt['appointment_id'], 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2, 'color' => '#06C755']
        ]],
        ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'contents' => [
            ['type' => 'text', 'text' => '⏰ เวลานัด', 'size' => 'sm', 'color' => '#666666', 'flex' => 1],
            ['type' => 'text', 'text' => $appointmentTime . ' น.', 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
        ]],
        ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'contents' => [
            ['type' => 'text', 'text' => '💰 ค่าบริการ', 'size' => 'sm', 'color' => '#666666', 'flex' => 1],
            ['type' => 'text', 'text' => $fee, 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2, 'color' => '#06C755']
        ]],
        ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'contents' => [
            ['type' => 'text', 'text' => '⏱️ ระยะเวลา', 'size' => 'sm', 'color' => '#666666', 'flex' => 1],
            ['type' => 'text', 'text' => $duration . ' นาที', 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2]
        ]]
    ];
    
    $flex = [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#06C755',
            'paddingAll' => '25px',
            'contents' => [
                ['type' => 'text', 'text' => '📹', 'size' => '4xl', 'align' => 'center'],
                ['type' => 'text', 'text' => 'ถึงเวลานัดแล้ว!', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'xxl', 'align' => 'center', 'margin' => 'md'],
                ['type' => 'text', 'text' => 'กดปุ่มเริ่ม Video Call', 'color' => '#FFFFFF', 'size' => 'sm', 'align' => 'center', 'margin' => 'sm']
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '20px',
            'spacing' => 'md',
            'contents' => [
                // Pharmacist Card
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'backgroundColor' => '#E8F5E9',
                    'cornerRadius' => '15px',
                    'paddingAll' => '15px',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'width' => '70px',
                            'height' => '70px',
                            'cornerRadius' => '35px',
                            'backgroundColor' => '#C8E6C9',
                            'contents' => [$pharmacistImageContent],
                            'flex' => 0
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'flex' => 1,
                            'margin' => 'lg',
                            'contents' => [
                                ['type' => 'text', 'text' => $apt['pharmacist_name'], 'weight' => 'bold', 'size' => 'lg', 'color' => '#1B5E20'],
                                ['type' => 'text', 'text' => $specialty, 'size' => 'sm', 'color' => '#4CAF50', 'weight' => 'bold'],
                                [
                                    'type' => 'box',
                                    'layout' => 'horizontal',
                                    'margin' => 'sm',
                                    'contents' => [
                                        ['type' => 'text', 'text' => '⭐ ' . $rating, 'size' => 'xs', 'color' => '#666666', 'flex' => 0],
                                        ['type' => 'text', 'text' => '🟢 พร้อมรับสาย', 'size' => 'xs', 'color' => '#06C755', 'margin' => 'md', 'weight' => 'bold', 'flex' => 0]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                // Hospital & License (if available)
                $hospital || $licenseNo ? [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'margin' => 'md',
                    'contents' => array_filter([
                        $hospital ? ['type' => 'text', 'text' => '🏥 ' . $hospital, 'size' => 'xs', 'color' => '#888888', 'wrap' => true] : null,
                        $licenseNo ? ['type' => 'text', 'text' => '📋 ใบอนุญาต: ' . $licenseNo, 'size' => 'xs', 'color' => '#888888', 'margin' => 'xs'] : null
                    ])
                ] : ['type' => 'box', 'layout' => 'vertical', 'contents' => []],
                // Appointment Details
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'margin' => 'lg',
                    'backgroundColor' => '#F5F5F5',
                    'cornerRadius' => '10px',
                    'paddingAll' => '12px',
                    'contents' => $detailsContents
                ]
            ]
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'spacing' => 'sm',
            'contents' => [
                ['type' => 'button', 'style' => 'primary', 'color' => '#06C755', 'height' => 'md', 'action' => [
                    'type' => 'uri', 'label' => '📹 เริ่ม Video Call เลย!', 'uri' => $videoCallUrl
                ]],
                ['type' => 'text', 'text' => 'กดปุ่มด้านบนเพื่อเริ่มการสนทนา', 'size' => 'xs', 'color' => '#999999', 'align' => 'center', 'margin' => 'md']
            ]
        ]
    ];
    
    return ['type' => 'flex', 'altText' => "📹 ถึงเวลานัดแล้ว! กดเพื่อเริ่ม Video Call กับ{$apt['pharmacist_name']} ({$specialty})", 'contents' => $flex];
}

/**
 * ส่งข้อความ LINE
 */
function sendLineMessage($token, $userId, $message) {
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => json_encode(['to' => $userId, 'messages' => [$message]])
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200 ? ['success' => true] : ['success' => false, 'error' => "HTTP $httpCode: $response"];
}
