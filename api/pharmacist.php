<?php
/**
 * Pharmacist API
 * API สำหรับ Pharmacist Dashboard
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

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
                
                echo json_encode(['success' => $result, 'message' => $result ? 'Message sent' : 'Failed to send']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
            
            if (!$sessionId || !$userId || empty($drugs)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            try {
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
                
                // Add items to user's cart if requested
                $addToCart = $input['add_to_cart'] ?? false;
                error_log("approve_drugs: add_to_cart=" . ($addToCart ? 'true' : 'false') . ", drugs count=" . count($drugs) . ", userId={$userId}");
                
                if ($addToCart) {
                    $cartAdded = 0;
                    try {
                        foreach ($drugs as $drug) {
                            $productId = (int)($drug['id'] ?? 0);
                            $quantity = (int)($drug['quantity'] ?? 1);
                            error_log("approve_drugs: Processing drug id={$productId}, quantity={$quantity}");
                            if ($productId <= 0) {
                                error_log("approve_drugs: Skipping drug with invalid id");
                                continue;
                            }
                            
                            // Check if item already in cart (use product_id column)
                            $stmt = $db->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
                            $stmt->execute([$userId, $productId]);
                            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($existing) {
                                // Update quantity
                                $stmt = $db->prepare("UPDATE cart SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?");
                                $stmt->execute([$quantity, $existing['id']]);
                                error_log("approve_drugs: Updated cart item {$existing['id']}");
                            } else {
                                // Insert new cart item
                                $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, quantity, created_at) VALUES (?, ?, ?, NOW())");
                                $stmt->execute([$userId, $productId, $quantity]);
                                error_log("approve_drugs: Inserted cart item, lastInsertId=" . $db->lastInsertId());
                            }
                            $cartAdded++;
                        }
                        error_log("Added {$cartAdded} items to cart for user {$userId}");
                    } catch (Exception $cartError) {
                        error_log("Cart add error (non-fatal): " . $cartError->getMessage());
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Drugs approved' . ($lineSent ? ' and sent to customer' : ' (LINE notification pending)') . ($addToCart ? ' - items added to cart' : '')
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
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'get_drugs':
            // Get ALL items from business_items - no limit, no is_active filter for pharmacist
            try {
                $stmt = $db->query("
                    SELECT id, name, price, generic_name, description, usage_instructions, sku
                    FROM business_items 
                    ORDER BY name
                ");
                $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'drugs' => $drugs, 'count' => count($drugs)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request method']);
