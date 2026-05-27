<?php
/**
 * Pharmacist API
 * API สำหรับ Pharmacist Dashboard
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/ActivityLogger.php';

$db = Database::getInstance()->getConnection();
$logger = ActivityLogger::getInstance($db);

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'available':
            // Get available pharmacists for LIFF app
            // Requirements: 13.8, 13.9 - Display pharmacist cards with photo, name, specialty
            $lineAccountId = $_GET['line_account_id'] ?? null;
            $limit = (int)($_GET['limit'] ?? 5);
            
            try {
                // Get current day of week (0=Sunday, 6=Saturday)
                $currentDayOfWeek = date('w');
                $currentTime = date('H:i:s');
                
                // Query pharmacists who are active and available today
                $sql = "
                    SELECT DISTINCT 
                        p.id,
                        p.name,
                        p.title,
                        p.specialty,
                        p.sub_specialty,
                        p.image_url as photo_url,
                        p.rating,
                        p.review_count,
                        p.consultation_fee,
                        p.consultation_duration,
                        p.bio,
                        ps.start_time,
                        ps.end_time
                    FROM pharmacists p
                    LEFT JOIN pharmacist_schedules ps ON p.id = ps.pharmacist_id 
                        AND ps.day_of_week = ? 
                        AND ps.is_available = 1
                    LEFT JOIN pharmacist_holidays ph ON p.id = ph.pharmacist_id 
                        AND ph.holiday_date = CURDATE()
                    WHERE p.is_active = 1 
                        AND p.is_available = 1
                        AND ph.id IS NULL
                        AND (p.line_account_id = ? OR p.line_account_id IS NULL)
                    ORDER BY 
                        CASE WHEN ps.start_time <= ? AND ps.end_time >= ? THEN 0 ELSE 1 END,
                        p.rating DESC,
                        p.review_count DESC
                    LIMIT ?
                ";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([$currentDayOfWeek, $lineAccountId, $currentTime, $currentTime, $limit]);
                $pharmacists = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Format the response
                $formattedPharmacists = array_map(function($p) use ($currentTime) {
                    // Check if currently available (within schedule)
                    $isOnline = false;
                    if ($p['start_time'] && $p['end_time']) {
                        $isOnline = ($currentTime >= $p['start_time'] && $currentTime <= $p['end_time']);
                    }
                    
                    return [
                        'id' => (int)$p['id'],
                        'name' => $p['title'] . $p['name'],
                        'specialty' => $p['specialty'] ?: 'เภสัชกรทั่วไป',
                        'sub_specialty' => $p['sub_specialty'],
                        'photo_url' => $p['photo_url'] ?: '',
                        'rating' => $p['rating'] ? number_format((float)$p['rating'], 1) : null,
                        'review_count' => (int)$p['review_count'],
                        'consultation_fee' => (float)$p['consultation_fee'],
                        'consultation_duration' => (int)$p['consultation_duration'],
                        'bio' => $p['bio'],
                        'is_online' => $isOnline,
                        'schedule' => $p['start_time'] && $p['end_time'] 
                            ? substr($p['start_time'], 0, 5) . ' - ' . substr($p['end_time'], 0, 5)
                            : null
                    ];
                }, $pharmacists);
                
                echo json_encode([
                    'success' => true, 
                    'pharmacists' => $formattedPharmacists,
                    'count' => count($formattedPharmacists)
                ]);
            } catch (Exception $e) {
                error_log("Pharmacist API available error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => $e->getMessage(), 'pharmacists' => []]);
            }
            break;
            
        case 'get_detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                exit;
            }
            
            try {
                // Get from triage_sessions directly
                $stmt = $db->prepare("
                    SELECT ts.id, ts.user_id, ts.triage_data, ts.current_state, ts.status as session_status,
                           ts.created_at, ts.line_account_id,
                           u.display_name, u.picture_url, u.phone, u.drug_allergies, u.medical_conditions
                    FROM triage_sessions ts
                    LEFT JOIN users u ON ts.user_id = u.id
                    WHERE ts.id = ?
                ");
                $stmt->execute([$id]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($data) {
                    $data['triage_data'] = json_decode($data['triage_data'] ?? '{}', true);
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Not found']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'get_stats':
            $lineAccountId = $_GET['line_account_id'] ?? null;
            
            try {
                $stats = [];
                
                // Pending
                $stmt = $db->prepare("SELECT COUNT(*) FROM pharmacist_notifications WHERE status = 'pending' AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$lineAccountId]);
                $stats['pending'] = $stmt->fetchColumn();
                
                // Urgent
                $stmt = $db->prepare("SELECT COUNT(*) FROM pharmacist_notifications WHERE status = 'pending' AND priority = 'urgent' AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$lineAccountId]);
                $stats['urgent'] = $stmt->fetchColumn();
                
                echo json_encode(['success' => true, 'stats' => $stats]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_status':
            $id = (int)($input['id'] ?? 0);
            $status = $input['status'] ?? '';
            
            if (!$id || !in_array($status, ['read', 'handled', 'dismissed'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            try {
                $stmt = $db->prepare("UPDATE pharmacist_notifications SET status = ?, handled_at = NOW() WHERE id = ?");
                $stmt->execute([$status, $id]);
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
        
        case 'update_session_status':
            $sessionId = (int)($input['session_id'] ?? 0);
            $status = $input['status'] ?? '';
            
            if (!$sessionId || !in_array($status, ['completed', 'cancelled', 'active'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            try {
                $completedAt = ($status === 'completed') ? ', completed_at = NOW()' : '';
                $stmt = $db->prepare("UPDATE triage_sessions SET status = ? {$completedAt} WHERE id = ?");
                $stmt->execute([$status, $sessionId]);
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'send_message':
            $userId = (int)($input['user_id'] ?? 0);
            $message = $input['message'] ?? '';
            
            if (!$userId || !$message) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            try {
                // Get user's line_account_id
                $stmt = $db->prepare("SELECT line_account_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                $lineAccountId = $userData['line_account_id'] ?? null;
                
                // Load PharmacistNotifier with lineAccountId
                require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
                $notifier = new \Modules\AIChat\Services\PharmacistNotifier($lineAccountId);
                
                $result = $notifier->sendToCustomer($userId, $message);
                
                // Log message
                $stmt = $db->prepare("INSERT INTO messages (user_id, message_type, content, direction, sent_by) VALUES (?, 'text', ?, 'outgoing', 'pharmacist')");
                $stmt->execute([$userId, $message]);
                
                // Log activity
                $logger->logMessage(ActivityLogger::ACTION_SEND, 'เภสัชกรส่งข้อความถึงลูกค้า', [
                    'user_id' => $userId,
                    'entity_type' => 'message',
                    'new_value' => ['message' => $message],
                    'line_account_id' => $lineAccountId
                ]);
                
                echo json_encode(['success' => $result, 'message' => $result ? 'Message sent' : 'Failed to send']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
        
        case 'create_dispense_session':
            // Create a new triage session for dispensing from inbox
            $userId = (int)($input['user_id'] ?? 0);
            
            if (!$userId) {
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }
            
            try {
                // Get user info
                $stmt = $db->prepare("SELECT display_name, line_account_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    echo json_encode(['success' => false, 'error' => 'User not found']);
                    exit;
                }
                
                // Create triage session for dispense
                $triageData = json_encode([
                    'source' => 'inbox_dispense',
                    'symptoms' => ['จ่ายยาจากกล่องข้อความ'],
                    'created_from' => 'inbox'
                ]);
                
                $stmt = $db->prepare("
                    INSERT INTO triage_sessions (user_id, line_account_id, current_state, triage_data, status, created_at)
                    VALUES (?, ?, 'dispense', ?, 'active', NOW())
                ");
                $stmt->execute([$userId, $user['line_account_id'] ?? 1, $triageData]);
                $sessionId = $db->lastInsertId();
                
                echo json_encode(['success' => true, 'session_id' => $sessionId]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
        
        case 'add_to_cart_direct':
            // Add items directly to user's cart (from inbox dispense)
            $userId = (int)($input['user_id'] ?? 0);
            $items = $input['items'] ?? [];
            $note = $input['note'] ?? '';
            
            if (!$userId || empty($items)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            try {
                // Get user's line_user_id
                $stmt = $db->prepare("SELECT line_user_id FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userLineId = $stmt->fetchColumn() ?: '';
                
                $addedCount = 0;
                foreach ($items as $item) {
                    $productId = (int)($item['product_id'] ?? 0);
                    $quantity = (int)($item['quantity'] ?? 1);
                    
                    if ($productId <= 0) continue;
                    
                    // Check if item already in cart
                    $stmt = $db->prepare("SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?");
                    $stmt->execute([$userId, $productId]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $stmt = $db->prepare("UPDATE cart_items SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$quantity, $existing['id']]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO cart_items (user_id, line_user_id, product_id, quantity, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([$userId, $userLineId, $productId, $quantity]);
                    }
                    $addedCount++;
                }
                
                echo json_encode(['success' => true, 'added_count' => $addedCount, 'message' => "Added {$addedCount} items to cart"]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'check_interactions':
            // Phase 2A: pre-approval safety check. Reuses existing AIChat DrugInteractionChecker.
            $userId = (int)($input['user_id'] ?? 0);
            $drugs = $input['drugs'] ?? [];
            if (!$userId || empty($drugs)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            try {
                $patientData = pharmacistBuildPatientData($db, $userId);
                $drugsNormalized = pharmacistNormalizeDrugsForChecker($drugs);
                require_once __DIR__ . '/../modules/AIChat/Services/DrugInteractionChecker.php';
                $checker = new \Modules\AIChat\Services\DrugInteractionChecker();
                $report = $checker->generateSafetyReport($drugsNormalized, $patientData);
                echo json_encode(['success' => true, 'report' => $report]);
            } catch (Exception $e) {
                error_log('check_interactions error: ' . $e->getMessage());
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'get_batches_for_drug':
            // Phase 2B: list available batches for a drug, FEFO-ordered.
            // Returns empty list if drug_batches table is absent — UI degrades to no-tracking mode.
            $drugId = (int)($input['drug_id'] ?? 0);
            $lineAccountId = (int)($input['line_account_id'] ?? 0);
            if (!$drugId) {
                echo json_encode(['success' => false, 'error' => 'Invalid drug_id']);
                exit;
            }
            try {
                $sql = "SELECT id, batch_number, expiry_date, quantity_on_hand
                        FROM drug_batches
                        WHERE drug_id = ?
                          AND quantity_on_hand > 0
                          " . ($lineAccountId ? "AND line_account_id = ?" : "") . "
                        ORDER BY expiry_date ASC, id ASC";
                $stmt = $db->prepare($sql);
                $stmt->execute($lineAccountId ? [$drugId, $lineAccountId] : [$drugId]);
                $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'batches' => $batches]);
            } catch (Exception $e) {
                // Table may not exist on this install. Tell the UI gracefully.
                echo json_encode(['success' => true, 'batches' => [], 'tracking_disabled' => true]);
            }
            break;

        case 'approve_drugs':
            // Support both notification_id (old) and session_id (new)
            $sessionId = (int)($input['session_id'] ?? $input['notification_id'] ?? 0);
            $userId = (int)($input['user_id'] ?? 0);
            $drugs = $input['drugs'] ?? [];
            $pharmacistNote = $input['note'] ?? '';
            $pharmacistName = $input['pharmacist_name'] ?? '';
            $pharmacistLicense = $input['pharmacist_license'] ?? '';
            $acknowledgedInteractions = array_map('strtolower', (array)($input['acknowledged_interactions'] ?? []));

            if (!$sessionId || !$userId || empty($drugs)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }

            try {
                // Phase 2C: prescription enforcement.
                // For any drug flagged requires_prescription, demand a valid prescription_approvals row.
                $rxApprovalId = (int)($input['prescription_approval_id'] ?? 0);
                $rxRequiredDrugs = [];
                try {
                    $drugIds = array_values(array_filter(array_map(
                        function ($d) { return (int)($d['id'] ?? 0); },
                        array_filter($drugs, function ($d) { return empty($d['isNonDrug']); })
                    )));
                    if (!empty($drugIds)) {
                        $placeholders = implode(',', array_fill(0, count($drugIds), '?'));
                        $stmt = $db->prepare("SELECT id, name FROM business_items
                            WHERE id IN ({$placeholders}) AND requires_prescription = 1");
                        $stmt->execute($drugIds);
                        $rxRequiredDrugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                } catch (Exception $rxColErr) {
                    // requires_prescription column missing on this install — skip enforcement.
                    $rxRequiredDrugs = [];
                }

                if (!empty($rxRequiredDrugs)) {
                    if (!$rxApprovalId) {
                        http_response_code(422);
                        echo json_encode([
                            'success' => false,
                            'error' => 'rx_required',
                            'message' => 'ต้องแนบใบสั่งยาที่อนุมัติแล้วก่อนจ่ายยาที่ต้องสั่งโดยแพทย์',
                            'requires_rx' => $rxRequiredDrugs,
                        ]);
                        exit;
                    }
                    try {
                        $stmt = $db->prepare("SELECT id, status, expires_at, line_account_id, user_id
                            FROM prescription_approvals WHERE id = ?");
                        $stmt->execute([$rxApprovalId]);
                        $approval = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$approval || $approval['status'] !== 'approved'
                            || ($approval['expires_at'] && strtotime($approval['expires_at']) < time())
                            || (int)$approval['user_id'] !== $userId) {
                            http_response_code(422);
                            echo json_encode([
                                'success' => false,
                                'error' => 'rx_invalid',
                                'message' => 'ใบสั่งยาที่แนบไม่ถูกต้องหรือหมดอายุ',
                            ]);
                            exit;
                        }
                    } catch (Exception $rxLookupErr) {
                        error_log('prescription_approvals lookup failed: ' . $rxLookupErr->getMessage());
                        http_response_code(422);
                        echo json_encode([
                            'success' => false,
                            'error' => 'rx_lookup_failed',
                            'message' => 'ไม่สามารถตรวจสอบใบสั่งยาได้',
                        ]);
                        exit;
                    }
                }

                // Phase 2A: server-side safety guard. Block severe/contraindicated unless acknowledged.
                $patientData = pharmacistBuildPatientData($db, $userId);
                $drugsNormalized = pharmacistNormalizeDrugsForChecker($drugs);
                require_once __DIR__ . '/../modules/AIChat/Services/DrugInteractionChecker.php';
                $checker = new \Modules\AIChat\Services\DrugInteractionChecker();
                $safetyReport = $checker->generateSafetyReport($drugsNormalized, $patientData);

                $blocking = [];
                foreach (($safetyReport['interactions'] ?? []) as $intx) {
                    if (in_array($intx['severity'] ?? '', ['severe', 'contraindicated'], true)) {
                        $key = strtolower(($intx['drug1'] ?? '') . '|' . ($intx['drug2'] ?? ''));
                        if (!in_array($key, $acknowledgedInteractions, true)) {
                            $blocking[] = ['type' => 'interaction'] + $intx;
                        }
                    }
                }
                foreach (($safetyReport['allergies'] ?? []) as $a) {
                    if (($a['severity'] ?? '') === 'contraindicated') {
                        $key = strtolower(($a['drug'] ?? '') . '|allergy|' . ($a['allergy'] ?? ''));
                        if (!in_array($key, $acknowledgedInteractions, true)) {
                            $blocking[] = ['type' => 'allergy'] + $a;
                        }
                    }
                }
                if (!empty($blocking)) {
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'error' => 'interaction_ack_required',
                        'message' => 'พบยาตีกัน/ข้อห้ามใช้ที่ต้องรับทราบก่อนจ่ายยา',
                        'blocking' => $blocking,
                        'report' => $safetyReport,
                    ]);
                    exit;
                }

                // Record interaction acknowledgments (best-effort; severity collapses to schema enum).
                $lineUserIdForAck = $patientData['_line_user_id'] ?? null;
                foreach (($safetyReport['interactions'] ?? []) as $intx) {
                    $key = strtolower(($intx['drug1'] ?? '') . '|' . ($intx['drug2'] ?? ''));
                    if (in_array($key, $acknowledgedInteractions, true)) {
                        try {
                            $sev = $intx['severity'] ?? 'moderate';
                            if ($sev === 'contraindicated') { $sev = 'severe'; }
                            $ackStmt = $db->prepare("INSERT INTO drug_interaction_acknowledgments (user_id, line_user_id, drug1_id, drug2_id, drug1_name, drug2_name, severity, acknowledged_at) VALUES (?, ?, 0, 0, ?, ?, ?, NOW())");
                            $ackStmt->execute([
                                $userId,
                                $lineUserIdForAck,
                                substr((string)($intx['drug1'] ?? ''), 0, 255),
                                substr((string)($intx['drug2'] ?? ''), 0, 255),
                                $sev,
                            ]);
                        } catch (Exception $ackErr) {
                            error_log('drug_interaction_acknowledgments insert failed: ' . $ackErr->getMessage());
                        }
                    }
                }

                // Phase 2B: atomically decrement stock for drugs picked from a batch,
                // and write an audit row to drug_dispense_lines. Graceful when tables
                // are absent (older installs without the migration applied).
                $batchAccountId = null;
                try {
                    $stmtAcc = $db->prepare("SELECT line_account_id FROM triage_sessions WHERE id = ?");
                    $stmtAcc->execute([$sessionId]);
                    $batchAccountId = $stmtAcc->fetchColumn() ?: null;
                    if (!$batchAccountId) {
                        $stmtAcc = $db->prepare("SELECT line_account_id FROM users WHERE id = ?");
                        $stmtAcc->execute([$userId]);
                        $batchAccountId = $stmtAcc->fetchColumn() ?: null;
                    }
                } catch (Exception $accErr) {
                    error_log('Phase 2B account lookup failed: ' . $accErr->getMessage());
                }

                $inventoryError = null;
                $inTx = false;
                try {
                    $db->beginTransaction();
                    $inTx = true;
                } catch (Exception $txErr) {
                    error_log('Phase 2B beginTransaction failed: ' . $txErr->getMessage());
                }

                foreach ($drugs as $drug) {
                    if (!empty($drug['isNonDrug'])) { continue; }
                    $drugId   = (int)($drug['id'] ?? 0);
                    $drugName = (string)($drug['name'] ?? '');
                    $qty      = max(1, (int)($drug['quantity'] ?? 1));
                    $batchId  = isset($drug['batch_id']) && (int)$drug['batch_id'] > 0 ? (int)$drug['batch_id'] : null;
                    $expiryAt = null;

                    if ($batchId) {
                        try {
                            $upd = $db->prepare("UPDATE drug_batches
                                SET quantity_on_hand = quantity_on_hand - ?
                                WHERE id = ? AND quantity_on_hand >= ?");
                            $upd->execute([$qty, $batchId, $qty]);
                            if ($upd->rowCount() === 0) {
                                $inventoryError = [
                                    'error'     => 'insufficient_stock',
                                    'message'   => "สต๊อกไม่พอสำหรับ batch #{$batchId}",
                                    'drug_id'   => $drugId,
                                    'batch_id'  => $batchId,
                                    'requested' => $qty,
                                ];
                                break;
                            }
                            $exp = $db->prepare("SELECT expiry_date FROM drug_batches WHERE id = ?");
                            $exp->execute([$batchId]);
                            $expiryAt = $exp->fetchColumn() ?: null;
                        } catch (Exception $e) {
                            // drug_batches table absent on this install — fall through without decrement.
                            error_log('drug_batches UPDATE failed: ' . $e->getMessage());
                            $batchId = null;
                        }
                    }

                    try {
                        $insLine = $db->prepare("INSERT INTO drug_dispense_lines
                            (session_id, user_id, line_account_id, drug_id, drug_name, batch_id, quantity, expiry_at_dispense, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $insLine->execute([
                            $sessionId,
                            $userId,
                            $batchAccountId,
                            $drugId,
                            substr($drugName, 0, 255),
                            $batchId,
                            $qty,
                            $expiryAt,
                        ]);
                    } catch (Exception $lineErr) {
                        // drug_dispense_lines table absent on this install — log and continue.
                        error_log('drug_dispense_lines insert failed: ' . $lineErr->getMessage());
                    }
                }

                if ($inventoryError) {
                    if ($inTx && $db->inTransaction()) { $db->rollBack(); }
                    http_response_code(422);
                    echo json_encode(['success' => false] + $inventoryError);
                    exit;
                }
                if ($inTx && $db->inTransaction()) {
                    $db->commit();
                }

                // Phase 2C: mark the prescription_approvals row as used (best-effort).
                if (!empty($rxApprovalId)) {
                    try {
                        $rxUsed = $db->prepare("UPDATE prescription_approvals
                            SET status = 'used', used_at = NOW()
                            WHERE id = ? AND status = 'approved'");
                        $rxUsed->execute([$rxApprovalId]);
                    } catch (Exception $rxUseErr) {
                        error_log('prescription_approvals mark-used failed: ' . $rxUseErr->getMessage());
                    }
                }

                // Get triage data from session directly
                $stmt = $db->prepare("SELECT triage_data, line_account_id FROM triage_sessions WHERE id = ?");
                $stmt->execute([$sessionId]);
                $sessionData = $stmt->fetch(PDO::FETCH_ASSOC);
                $triageData = json_decode($sessionData['triage_data'] ?? '{}', true);
                $lineAccountId = $sessionData['line_account_id'] ?? null;
                
                // If no line_account_id in session, get from user
                if (!$lineAccountId) {
                    $stmt = $db->prepare("SELECT line_account_id FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $lineAccountId = $userData['line_account_id'] ?? null;
                }
                
                // Try to send LINE message (but don't fail if it doesn't work)
                $lineSent = false;
                try {
                    require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
                    $notifier = new \Modules\AIChat\Services\PharmacistNotifier($lineAccountId);
                    $lineSent = $notifier->sendApprovalToCustomer($userId, $triageData, $drugs, $pharmacistName, $pharmacistLicense, $pharmacistNote);
                } catch (Exception $lineError) {
                    error_log("LINE send error (non-fatal): " . $lineError->getMessage());
                }
                
                // Update triage session status
                $stmt = $db->prepare("UPDATE triage_sessions SET status = 'completed', completed_at = NOW() WHERE id = ?");
                $stmt->execute([$sessionId]);
                
                // Save medical history (ignore if table doesn't exist)
                try {
                    $stmt = $db->prepare("
                        INSERT INTO medical_history (user_id, triage_session_id, symptoms, medications_prescribed, pharmacist_notes)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $userId,
                        $sessionId,
                        json_encode($triageData['symptoms'] ?? [], JSON_UNESCAPED_UNICODE),
                        json_encode($drugs, JSON_UNESCAPED_UNICODE),
                        $pharmacistNote
                    ]);
                } catch (Exception $historyError) {
                    error_log("Medical history save error (non-fatal): " . $historyError->getMessage());
                }
                
                // NEW (Option D): create a "pending_payment" transaction directly — bypass cart.
                // Reason: cart filters out is_active=0 products; dispense must work on any product
                // a pharmacist chose. Use `add_to_cart` flag name for backwards compatibility.
                $addToCart = $input['add_to_cart'] ?? false;
                $createdOrderId = null;
                $createdOrderNumber = null;
                error_log("approve_drugs: add_to_cart={$addToCart} drugs=" . count($drugs) . " userId={$userId}");

                if ($addToCart) {
                    try {
                        $stmt = $db->prepare("SELECT line_user_id FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $userLineId = $stmt->fetchColumn() ?: '';

                        $items = [];
                        $totalAmount = 0.0;
                        foreach ($drugs as $drug) {
                            $productId = (int)($drug['id'] ?? 0);
                            $quantity = max(1, (int)($drug['quantity'] ?? 1));
                            if ($productId <= 0) continue;
                            // Fetch product (ignore is_active filter so hidden-from-shop items are allowed)
                            $p = $db->prepare("SELECT id, name, price, sale_price, sku FROM business_items WHERE id = ?");
                            $p->execute([$productId]);
                            $row = $p->fetch(PDO::FETCH_ASSOC);
                            if (!$row) { error_log("approve_drugs: product {$productId} not found"); continue; }
                            $unitPrice = (float)($row['sale_price'] ?? null) > 0 ? (float)$row['sale_price'] : (float)$row['price'];
                            $lineTotal = $unitPrice * $quantity;
                            $totalAmount += $lineTotal;
                            $items[] = [
                                'product_id' => $productId, 'product_name' => $row['name'],
                                'sku' => $row['sku'], 'unit_price' => $unitPrice,
                                'quantity' => $quantity, 'line_total' => $lineTotal,
                                'note' => trim((string)($drug['instructions'] ?? '')),
                            ];
                        }

                        if (!empty($items)) {
                            $orderNumber = 'DSP' . date('YmdHis') . str_pad((string)$sessionId, 4, '0', STR_PAD_LEFT);
                            $deliveryInfo = json_encode([
                                'source' => 'pharmacist_dispense',
                                'session_id' => $sessionId,
                                'pharmacist_name' => $pharmacistName,
                                'pharmacist_license' => $pharmacistLicense,
                                'note' => $pharmacistNote,
                            ], JSON_UNESCAPED_UNICODE);

                            $db->beginTransaction();
                            $ins = $db->prepare("INSERT INTO transactions
                                (line_account_id, user_id, line_user_id, order_number, transaction_type, status, payment_status, payment_method, total_amount, grand_total, delivery_info, note, created_at)
                                VALUES (?, ?, ?, ?, 'purchase', 'pending', 'pending', 'transfer', ?, ?, ?, ?, NOW())");
                            $ins->execute([
                                $lineAccountId, $userId, $userLineId, $orderNumber,
                                $totalAmount, $totalAmount, $deliveryInfo,
                                'รอชำระเงิน — เภสัชกรอนุมัติยาผ่าน dispense (session #' . $sessionId . ')'
                            ]);
                            $createdOrderId = (int)$db->lastInsertId();
                            $createdOrderNumber = $orderNumber;

                            $insItem = $db->prepare("INSERT INTO transaction_items
                                (transaction_id, product_id, product_name, product_price, quantity, subtotal)
                                VALUES (?, ?, ?, ?, ?, ?)");
                            foreach ($items as $it) {
                                $insItem->execute([
                                    $createdOrderId, $it['product_id'], $it['product_name'],
                                    $it['unit_price'], $it['quantity'], $it['line_total']
                                ]);
                            }
                            $db->commit();
                            error_log("approve_drugs: created pending order #{$createdOrderId} ({$orderNumber}) total={$totalAmount}");
                        }
                    } catch (Exception $orderErr) {
                        if ($db->inTransaction()) { $db->rollBack(); }
                        error_log("approve_drugs: pending order create failed: " . $orderErr->getMessage());
                    }
                }
                
                // Log activity
                $logger->logPharmacy(ActivityLogger::ACTION_APPROVE, 'อนุมัติยาให้ลูกค้า', [
                    'user_id' => $userId,
                    'entity_type' => 'triage_session',
                    'entity_id' => $sessionId,
                    'new_value' => [
                        'drugs' => $drugs,
                        'pharmacist_name' => $pharmacistName,
                        'pharmacist_license' => $pharmacistLicense,
                        'note' => $pharmacistNote,
                        'add_to_cart' => $addToCart
                    ],
                    'line_account_id' => $lineAccountId
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Drugs approved' . ($lineSent ? ' + LINE sent' : '') . ($createdOrderId ? " - pending order #{$createdOrderId} created" : ''),
                    'order_id' => $createdOrderId,
                    'order_number' => $createdOrderNumber,
                ]);
            } catch (Exception $e) {
                error_log("approve_drugs error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'reject':
            // Support both notification_id (old) and session_id (new)
            $sessionId = (int)($input['session_id'] ?? $input['notification_id'] ?? 0);
            $userId = (int)($input['user_id'] ?? 0);
            $reason = $input['reason'] ?? 'ไม่สามารถแนะนำยาได้ กรุณาพบแพทย์';
            
            if (!$sessionId) {
                echo json_encode(['success' => false, 'error' => 'Invalid session ID']);
                exit;
            }
            
            try {
                // Get user's line_account_id
                $lineAccountId = null;
                if ($userId) {
                    $stmt = $db->prepare("SELECT line_account_id FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $lineAccountId = $userData['line_account_id'] ?? null;
                }
                
                // Send rejection message
                require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
                $notifier = new \Modules\AIChat\Services\PharmacistNotifier($lineAccountId);
                
                $message = "เภสัชกรแจ้ง:\n{$reason}\n\nกรุณาพบแพทย์หรือติดต่อเภสัชกรโดยตรง";
                $notifier->sendToCustomer($userId, $message);
                
                // Update triage session status
                $stmt = $db->prepare("UPDATE triage_sessions SET status = 'cancelled' WHERE id = ?");
                $stmt->execute([$sessionId]);
                
                // Log activity
                $logger->logPharmacy(ActivityLogger::ACTION_REJECT, 'ปฏิเสธคำขอยา', [
                    'user_id' => $userId,
                    'entity_type' => 'triage_session',
                    'entity_id' => $sessionId,
                    'new_value' => ['reason' => $reason],
                    'line_account_id' => $lineAccountId
                ]);
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'get_drugs':
            // Get ALL items from business_items - no limit, no is_active filter for pharmacist
            try {
                // Phase 2C: include requires_prescription flag (NULL-safe via COALESCE in case col missing).
                $hasRx = false;
                try {
                    $cols = $db->query("SHOW COLUMNS FROM business_items LIKE 'requires_prescription'")->fetchAll();
                    $hasRx = !empty($cols);
                } catch (Exception $colErr) {}
                $rxSelect = $hasRx ? ', requires_prescription' : ', 0 AS requires_prescription';
                $stmt = $db->query("
                    SELECT id, name, price, generic_name, description, usage_instructions, sku{$rxSelect}
                    FROM business_items
                    ORDER BY name
                ");
                $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode(['success' => true, 'drugs' => $drugs, 'count' => count($drugs)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'get_pending_rx':
            // Phase 2C: list approved-but-unused prescription_approvals for a patient
            // so the dispense UI can attach one when dispensing Rx-only drugs.
            $userId = (int)($input['user_id'] ?? 0);
            if (!$userId) {
                echo json_encode(['success' => false, 'error' => 'Invalid user_id']);
                exit;
            }
            try {
                $stmt = $db->prepare("SELECT id, approved_items, status, video_call_id, expires_at, created_at
                    FROM prescription_approvals
                    WHERE user_id = ?
                      AND status = 'approved'
                      AND (expires_at IS NULL OR expires_at > NOW())
                    ORDER BY created_at DESC
                    LIMIT 20");
                $stmt->execute([$userId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'approvals' => $rows]);
            } catch (Exception $e) {
                echo json_encode(['success' => true, 'approvals' => [], 'tracking_disabled' => true]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request method']);

// ─────────────────────────────────────────────────────────────────
// Phase 2A helpers — drug interaction safety report inputs
// ─────────────────────────────────────────────────────────────────

/**
 * Build the patient data array consumed by Modules\AIChat\Services\DrugInteractionChecker.
 * Returns an extra non-public key '_line_user_id' for downstream INSERT into
 * drug_interaction_acknowledgments (which does not have line_account_id).
 */
function pharmacistBuildPatientData(PDO $db, int $userId): array
{
    $allergies = [];
    $medicalConditions = [];
    $currentMedications = [];
    $lineUserId = null;

    try {
        $stmt = $db->prepare("SELECT line_user_id, drug_allergies, medical_conditions FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $lineUserId = $u['line_user_id'] ?? null;
        $allergies = array_values(array_filter(array_map('trim', explode(',', (string)($u['drug_allergies'] ?? '')))));
        $medicalConditions = array_values(array_filter(array_map('trim', explode(',', (string)($u['medical_conditions'] ?? '')))));
    } catch (Exception $e) {
        error_log('pharmacistBuildPatientData users lookup failed: ' . $e->getMessage());
    }

    if ($lineUserId) {
        try {
            $stmt = $db->prepare("SELECT medication_name FROM user_current_medications WHERE line_user_id = ? AND is_active = 1");
            $stmt->execute([$lineUserId]);
            $currentMedications = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            // user_current_medications may not exist on every install — non-fatal.
        }
    }

    return [
        'current_medications' => $currentMedications,
        'medical_conditions'  => $medicalConditions,
        'allergies'           => $allergies,
        '_line_user_id'       => $lineUserId,
    ];
}

/**
 * Normalize the dispense UI's camelCase drug payload to the snake_case shape
 * Modules\AIChat\Services\DrugInteractionChecker expects.
 */
function pharmacistNormalizeDrugsForChecker(array $drugs): array
{
    $out = [];
    foreach ($drugs as $d) {
        if (!empty($d['isNonDrug'])) { continue; } // skip non-pharmaceutical line items
        $out[] = [
            'id'           => $d['id']           ?? null,
            'name'         => $d['name']         ?? '',
            'generic_name' => $d['generic_name'] ?? $d['genericName'] ?? '',
        ];
    }
    return $out;
}
