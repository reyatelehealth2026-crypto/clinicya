<?php
/**
 * Video Call API - จัดการ Video Call
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Error handling - catch all errors
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $error['message']]);
    }
});

try {
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../classes/ActivityLogger.php';

    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Check if tables exist and auto-migrate
try {
    $db->query("SELECT 1 FROM video_calls LIMIT 1");

    // Auto-add missing columns to video_call_signals
    $cols = $db->query("DESCRIBE video_call_signals")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('from_who', $cols)) {
        $db->exec("ALTER TABLE video_call_signals ADD COLUMN from_who VARCHAR(20) DEFAULT 'customer'");
    }
    if (!in_array('processed', $cols)) {
        $db->exec("ALTER TABLE video_call_signals ADD COLUMN processed TINYINT(1) DEFAULT 0");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'ยังไม่ได้รัน migration กรุณาเปิด run_video_call_migration.php ก่อน']);
    exit;
}

// GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // Debug endpoint - ดูข้อมูลทั้งหมด
    if ($action === 'debug') {
        $stmt = $db->query("SELECT * FROM video_calls ORDER BY created_at DESC LIMIT 10");
        $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $db->query("SELECT COUNT(*) as total FROM video_calls");
        $total = $stmt2->fetch()['total'];

        $stmt3 = $db->query("SELECT status, COUNT(*) as cnt FROM video_calls GROUP BY status");
        $byStatus = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'total_calls' => $total,
            'by_status' => $byStatus,
            'recent_calls' => $calls
        ], JSON_PRETTY_PRINT);
        exit;
    }

    if ($action === 'check_calls') {
        $accountId = $_GET['account_id'] ?? null;

        // ดึงสายทั้งหมดที่รอรับ พร้อมข้อมูลผู้ใช้จาก users table
        // First check if users table has phone column
        $hasPhone = false;
        try {
            $cols = $db->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
            $hasPhone = in_array('phone', $cols);
        } catch (Exception $e) {
        }

        $sql = "SELECT vc.id, vc.room_id, vc.user_id, vc.line_user_id, 
                       COALESCE(u.display_name, vc.display_name, 'ลูกค้า') as display_name, 
                       COALESCE(u.picture_url, vc.picture_url) as picture_url, 
                       vc.line_account_id, vc.status, vc.created_at" .
            ($hasPhone ? ", u.phone" : "") . "
                FROM video_calls vc 
                LEFT JOIN users u ON vc.user_id = u.id OR vc.line_user_id = u.line_user_id
                WHERE vc.status IN ('pending', 'ringing')
                ORDER BY vc.created_at DESC
                LIMIT 20";

        try {
            $stmt = $db->query($sql);
            $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fallback: simple query without join
            $stmt = $db->query("SELECT * FROM video_calls WHERE status IN ('pending', 'ringing') ORDER BY created_at DESC LIMIT 20");
            $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'success' => true,
            'calls' => $calls,
            'count' => count($calls)
        ]);
        exit;
    }

    if ($action === 'get_status') {
        $callId = $_GET['call_id'] ?? '';

        $stmt = $db->prepare("SELECT * FROM video_calls WHERE id = ? OR room_id = ?");
        $stmt->execute([$callId, $callId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($call) {
            // Get latest signal
            $stmt = $db->prepare("SELECT * FROM video_call_signals WHERE call_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$call['id']]);
            $signal = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'status' => $call['status'],
                'signal' => $signal ? [
                    'type' => $signal['signal_type'],
                    'data' => json_decode($signal['signal_data'], true)
                ] : null
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Call not found']);
        }
        exit;
    }

    // Get signals for WebRTC
    if ($action === 'get_signals') {
        $callId = $_GET['call_id'] ?? '';
        $forWho = $_GET['for'] ?? ''; // 'admin' or 'customer'

        // Get call ID
        $stmt = $db->prepare("SELECT id FROM video_calls WHERE id = ? OR room_id = ?");
        $stmt->execute([$callId, $callId]);
        $call = $stmt->fetch();

        if (!$call) {
            echo json_encode(['success' => false, 'error' => 'Call not found']);
            exit;
        }

        // Check if from_who column exists
        $hasFromWho = false;
        try {
            $cols = $db->query("DESCRIBE video_call_signals")->fetchAll(PDO::FETCH_COLUMN);
            $hasFromWho = in_array('from_who', $cols);
        } catch (Exception $e) {
        }

        // Get unprocessed signals for this recipient
        $fromWho = $forWho === 'admin' ? 'customer' : 'admin';

        // Debug: log all signals first
        $allSignals = $db->prepare("SELECT id, signal_type, from_who, processed FROM video_call_signals WHERE call_id = ? ORDER BY created_at ASC");
        $allSignals->execute([$call['id']]);
        $allSigs = $allSignals->fetchAll(PDO::FETCH_ASSOC);

        // SIMPLE: Get all signals for this call, filter in PHP
        $stmt = $db->prepare("SELECT * FROM video_call_signals WHERE call_id = ? ORDER BY created_at ASC");
        $stmt->execute([$call['id']]);
        $allSignalsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter based on who is asking
        $signals = [];
        $debugFilter = [];
        foreach ($allSignalsRaw as $sig) {
            $sigFrom = $sig['from_who'] ?? '';
            $sigType = $sig['signal_type'] ?? '';
            $processed = $sig['processed'] ?? 0;

            $debugFilter[] = [
                'id' => $sig['id'],
                'type' => $sigType,
                'from' => $sigFrom,
                'processed' => $processed,
                'forWho' => $forWho,
                'match' => false
            ];

            if ($forWho === 'customer') {
                // Customer wants signals FROM admin
                if ($sigFrom === 'admin') {
                    // answer: always include (ignore processed)
                    // ice-candidate, message, hangup: only if not processed
                    if ($sigType === 'answer' || in_array($sigType, ['ice-candidate', 'message', 'hangup']) && !$processed) {
                        $signals[] = $sig;
                        $debugFilter[count($debugFilter) - 1]['match'] = true;
                    }
                }
            } else {
                // Admin wants signals FROM customer
                if ($sigFrom === 'customer') {
                    // offer: always include (ignore processed)
                    // ice-candidate, message, hangup: only if not processed
                    if ($sigType === 'offer' || in_array($sigType, ['ice-candidate', 'message', 'hangup']) && !$processed) {
                        $signals[] = $sig;
                        $debugFilter[count($debugFilter) - 1]['match'] = true;
                    }
                }
            }
        }
        // NOTE: Don't overwrite $signals here!

        // Mark as processed (except offer and answer - they need to be received by both sides)
        if (!empty($signals)) {
            // Mark ice-candidate, message, and hangup as processed
            $idsToProcess = array_column(array_filter($signals, function ($s) {
                return in_array($s['signal_type'], ['ice-candidate', 'message', 'hangup']);
            }), 'id');

            if (!empty($idsToProcess)) {
                $placeholders = implode(',', array_fill(0, count($idsToProcess), '?'));
                $db->prepare("UPDATE video_call_signals SET processed = 1 WHERE id IN ($placeholders)")->execute($idsToProcess);
            }
        }

        // Format signals
        $formatted = array_map(function ($s) {
            return [
                'id' => $s['id'],
                'signal_type' => $s['signal_type'],
                'signal_data' => json_decode($s['signal_data'], true)
            ];
        }, $signals);

        echo json_encode([
            'success' => true,
            'signals' => $formatted,
            'debug' => [
                'call_id' => $call['id'],
                'for' => $forWho,
                'found_count' => count($signals),
                'all_signals' => $allSigs,
                'filter_debug' => $debugFilter
            ]
        ]);
        exit;
    }
}

// POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_POST['action'] ?? '';

    if ($action === 'create') {
        try {
            // Create new call
            $lineUserId = $input['user_id'] ?? '';
            $displayName = $input['display_name'] ?? 'ลูกค้า';
            $pictureUrl = $input['picture_url'] ?? '';
            $accountId = $input['account_id'] ?? null;

            // Validate account_id exists
            if ($accountId) {
                $stmt = $db->prepare("SELECT id FROM line_accounts WHERE id = ?");
                $stmt->execute([$accountId]);
                if (!$stmt->fetch()) {
                    $accountId = null; // Set to null if not found
                }
            }

            // Get or create user
            $userId = null;
            if ($lineUserId && $lineUserId !== 'guest') {
                $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
                $stmt->execute([$lineUserId]);
                $user = $stmt->fetch();
                $userId = $user ? $user['id'] : null;
            }

            $roomId = 'call_' . uniqid() . '_' . time();

            // Check which columns exist in video_calls table
            $columns = [];
            try {
                $colResult = $db->query("SHOW COLUMNS FROM video_calls");
                $columns = $colResult->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                $columns = ['room_id', 'status', 'created_at'];
            }

            // Build dynamic insert based on available columns
            $insertCols = ['room_id', 'status', 'created_at'];
            $insertVals = [$roomId, 'ringing', date('Y-m-d H:i:s')];

            if (in_array('user_id', $columns)) {
                $insertCols[] = 'user_id';
                $insertVals[] = $userId;
            }
            if (in_array('line_user_id', $columns)) {
                $insertCols[] = 'line_user_id';
                $insertVals[] = $lineUserId ?: null;
            }
            if (in_array('display_name', $columns)) {
                $insertCols[] = 'display_name';
                $insertVals[] = $displayName;
            }
            if (in_array('picture_url', $columns)) {
                $insertCols[] = 'picture_url';
                $insertVals[] = $pictureUrl;
            }
            if (in_array('line_account_id', $columns)) {
                $insertCols[] = 'line_account_id';
                $insertVals[] = $accountId;
            }

            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $colNames = implode(', ', $insertCols);

            $stmt = $db->prepare("INSERT INTO video_calls ($colNames) VALUES ($placeholders)");
            $stmt->execute($insertVals);

            $callId = $db->lastInsertId();

            echo json_encode(['success' => true, 'call_id' => $callId, 'room_id' => $roomId]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Create call failed: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'answer') {
        $callId = $input['call_id'] ?? '';

        $stmt = $db->prepare("UPDATE video_calls SET status = 'active', answered_at = NOW() WHERE id = ? OR room_id = ?");
        $stmt->execute([$callId, $callId]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reject') {
        $callId = $input['call_id'] ?? '';

        $stmt = $db->prepare("UPDATE video_calls SET status = 'rejected', ended_at = NOW() WHERE id = ? OR room_id = ?");
        $stmt->execute([$callId, $callId]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'end') {
        $callId = $input['call_id'] ?? '';
        $duration = $input['duration'] ?? 0;

        $stmt = $db->prepare("UPDATE video_calls SET status = 'completed', duration = ?, ended_at = NOW() WHERE id = ? OR room_id = ?");
        $stmt->execute([$duration, $callId, $callId]);

        // Log activity
        try {
            // Get customer name and correct call_id
            $custStmt = $db->prepare("
                SELECT c.id, u.display_name, u.line_user_id 
                FROM video_calls c 
                LEFT JOIN users u ON (c.user_id = u.id OR c.line_user_id = u.line_user_id)
                WHERE c.id = ? OR c.room_id = ?
                LIMIT 1
            ");
            $custStmt->execute([$callId, $callId]);
            $custData = $custStmt->fetch(PDO::FETCH_ASSOC);

            if ($custData) {
                $custName = $custData['display_name'] ?? 'ลูกค้า';
                $realCallId = $custData['id'];

                $logger = ActivityLogger::getInstance($db);
                $logger->logPharmacy(
                    ActivityLogger::ACTION_UPDATE,
                    "Video Call เสร็จสิ้นกับ $custName (ระยะเวลา: " . gmdate("H:i:s", $duration) . ")",
                    [
                        'entity_type' => 'video_call',
                        'entity_id' => $realCallId,
                        'user_id' => $db->lastInsertId(), // Placeholder, not real user_id
                        'extra_data' => ['duration' => $duration]
                    ]
                );
            }
        } catch (Exception $e) {
            error_log("Video call logging error: " . $e->getMessage());
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'signal') {
        $callId = $input['call_id'] ?? '';
        $signalType = $input['signal_type'] ?? '';
        $signalData = $input['signal_data'] ?? [];
        $fromWho = $input['from'] ?? 'customer'; // 'admin' or 'customer'

        // Get call ID if room_id provided
        $stmt = $db->prepare("SELECT id FROM video_calls WHERE id = ? OR room_id = ?");
        $stmt->execute([$callId, $callId]);
        $call = $stmt->fetch();

        if ($call) {
            // Always try to insert with from_who
            try {
                $stmt = $db->prepare("INSERT INTO video_call_signals (call_id, signal_type, signal_data, from_who, processed, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
                $stmt->execute([$call['id'], $signalType, json_encode($signalData), $fromWho]);
            } catch (Exception $e) {
                // Fallback without from_who
                $stmt = $db->prepare("INSERT INTO video_call_signals (call_id, signal_type, signal_data, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$call['id'], $signalType, json_encode($signalData)]);
            }

            $insertId = $db->lastInsertId();
            echo json_encode([
                'success' => true,
                'signal_type' => $signalType,
                'from' => $fromWho,
                'signal_id' => $insertId,
                'call_id' => $call['id']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Call not found']);
        }
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
