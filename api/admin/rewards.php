<?php
/**
 * Admin Rewards Management API
 * Requirements: 24.1-24.10 - Admin Rewards Management
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/LoyaltyPoints.php';

session_start();

// Check admin authentication
if (!isset($_SESSION['admin_user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;
$loyalty = new LoyaltyPoints($db, $lineAccountId);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($action, $db, $loyalty, $lineAccountId);
            break;
        case 'POST':
            handlePost($action, $db, $loyalty, $lineAccountId, $adminId);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


/**
 * Handle GET requests
 * Requirements: 24.1, 24.7 - Display rewards list and redemption requests
 */
function handleGet($action, $db, $loyalty, $lineAccountId) {
    switch ($action) {
        case 'list':
            // Requirement 24.1: Display list of all rewards with status, stock, and redemption count
            $rewards = getRewardsWithStats($db, $lineAccountId);
            echo json_encode(['success' => true, 'data' => $rewards]);
            break;
            
        case 'detail':
            $id = (int)($_GET['id'] ?? 0);
            $reward = $loyalty->getReward($id);
            if ($reward) {
                // Get redemption count for this reward
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM reward_redemptions WHERE reward_id = ?");
                $stmt->execute([$id]);
                $reward['redemption_count'] = $stmt->fetchColumn();
                echo json_encode(['success' => true, 'data' => $reward]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Reward not found']);
            }
            break;
            
        case 'redemptions':
            // Requirement 24.7: Display redemption requests list
            $status = $_GET['status'] ?? null;
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            
            $redemptions = getRedemptionsWithDetails($db, $lineAccountId, $status, $limit, $offset);
            $total = getRedemptionsCount($db, $lineAccountId, $status);
            
            echo json_encode([
                'success' => true,
                'data' => $redemptions,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'export':
            // Requirement 24.10: Export redemption report as CSV
            exportRedemptionsCSV($db, $lineAccountId);
            break;
            
        case 'summary':
            $summary = $loyalty->getPointsSummary();
            echo json_encode(['success' => true, 'data' => $summary]);
            break;
            
        default:
            // Default: return all rewards
            $rewards = $loyalty->getRewards(false);
            echo json_encode(['success' => true, 'data' => $rewards]);
    }
}


/**
 * Handle POST requests
 * Requirements: 24.2-24.9 - Create, update, disable rewards and manage redemptions
 */
function handlePost($action, $db, $loyalty, $lineAccountId, $adminId) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    switch ($action) {
        case 'create':
            // Requirements 24.2, 24.3, 24.4: Create new reward
            $result = createReward($db, $loyalty, $lineAccountId, $input);
            echo json_encode($result);
            break;
            
        case 'update':
            // Requirement 24.5: Update reward details
            $id = (int)($input['id'] ?? 0);
            $result = updateReward($db, $loyalty, $id, $input);
            echo json_encode($result);
            break;
            
        case 'disable':
            // Requirement 24.6: Disable reward (hide from catalog)
            $id = (int)($input['id'] ?? 0);
            $result = disableReward($db, $loyalty, $id);
            echo json_encode($result);
            break;
            
        case 'toggle':
            $id = (int)($input['id'] ?? 0);
            $result = toggleReward($db, $loyalty, $id);
            echo json_encode($result);
            break;
            
        case 'delete':
            $id = (int)($input['id'] ?? 0);
            $result = deleteReward($db, $loyalty, $id);
            echo json_encode($result);
            break;
            
        case 'approve':
            // Requirement 24.8: Approve redemption
            $redemptionId = (int)($input['redemption_id'] ?? 0);
            $notes = trim($input['notes'] ?? '');
            $result = approveRedemption($db, $loyalty, $redemptionId, $adminId, $notes, $lineAccountId);
            echo json_encode($result);
            break;
            
        case 'deliver':
            // Requirement 24.9: Mark redemption as delivered
            $redemptionId = (int)($input['redemption_id'] ?? 0);
            $notes = trim($input['notes'] ?? '');
            $result = deliverRedemption($db, $loyalty, $redemptionId, $adminId, $notes, $lineAccountId);
            echo json_encode($result);
            break;
            
        case 'cancel':
            $redemptionId = (int)($input['redemption_id'] ?? 0);
            $notes = trim($input['notes'] ?? '');
            $result = cancelRedemption($db, $loyalty, $redemptionId, $adminId, $notes, $lineAccountId);
            echo json_encode($result);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}


/**
 * Get rewards with statistics
 * Requirement 24.1: Display all rewards with status, stock, and redemption count
 */
function getRewardsWithStats($db, $lineAccountId) {
    $sql = "
        SELECT r.*, 
               COALESCE(rc.redemption_count, 0) as redemption_count,
               COALESCE(rc.pending_count, 0) as pending_count
        FROM rewards r
        LEFT JOIN (
            SELECT reward_id, 
                   COUNT(*) as redemption_count,
                   SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
            FROM reward_redemptions
            GROUP BY reward_id
        ) rc ON r.id = rc.reward_id
        WHERE r.line_account_id = ? OR r.line_account_id IS NULL
        ORDER BY r.created_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$lineAccountId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get redemptions with user and reward details
 * Requirement 24.7: Display user info, reward, code, and status
 */
function getRedemptionsWithDetails($db, $lineAccountId, $status = null, $limit = 20, $offset = 0) {
    $sql = "
        SELECT rr.*, 
               r.name as reward_name, 
               r.image_url as reward_image,
               r.reward_type,
               r.points_required,
               u.display_name, 
               u.picture_url,
               u.phone,
               u.line_user_id,
               a.username as approved_by_name
        FROM reward_redemptions rr 
        JOIN rewards r ON rr.reward_id = r.id 
        JOIN users u ON rr.user_id = u.id
        LEFT JOIN admin_users a ON rr.approved_by = a.id
        WHERE (rr.line_account_id = ? OR rr.line_account_id IS NULL)
    ";
    $params = [$lineAccountId];
    
    if ($status) {
        $sql .= " AND rr.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY rr.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRedemptionsCount($db, $lineAccountId, $status = null) {
    $sql = "SELECT COUNT(*) FROM reward_redemptions WHERE (line_account_id = ? OR line_account_id IS NULL)";
    $params = [$lineAccountId];
    
    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}


/**
 * Create a new reward
 * Requirements 24.2, 24.3, 24.4: Capture details and support reward types
 */
function createReward($db, $loyalty, $lineAccountId, $data) {
    // Validate required fields
    $name = trim($data['name'] ?? '');
    $pointsRequired = (int)($data['points_required'] ?? 0);
    
    if (empty($name)) {
        return ['success' => false, 'message' => 'กรุณากรอกชื่อรางวัล'];
    }
    if ($pointsRequired <= 0) {
        return ['success' => false, 'message' => 'กรุณากรอกแต้มที่ใช้แลก'];
    }
    
    // Validate reward type (Requirement 24.3)
    $validTypes = ['discount', 'shipping', 'gift', 'product', 'coupon', 'voucher'];
    $rewardType = $data['reward_type'] ?? 'gift';
    if (!in_array($rewardType, $validTypes)) {
        $rewardType = 'gift';
    }
    
    // Prepare data
    $rewardData = [
        'name' => $name,
        'description' => trim($data['description'] ?? ''),
        'image_url' => trim($data['image_url'] ?? ''),
        'points_required' => $pointsRequired,
        'reward_type' => $rewardType,
        'reward_value' => (($v = trim((string)($data['reward_value'] ?? ''))) === '' ? null : $v),
        'stock' => (int)($data['stock'] ?? -1), // -1 = unlimited (Requirement 24.4)
        'max_per_user' => (int)($data['max_per_user'] ?? 0),
        'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1
    ];
    
    // Handle validity period (Requirement 24.2)
    if (!empty($data['valid_from'])) {
        $rewardData['start_date'] = $data['valid_from'];
    }
    if (!empty($data['valid_until'])) {
        $rewardData['end_date'] = $data['valid_until'];
    }
    
    try {
        $id = $loyalty->createReward($rewardData);
        return ['success' => true, 'id' => $id, 'message' => 'เพิ่มรางวัลสำเร็จ'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
    }
}

/**
 * Update reward details
 * Requirement 24.5: Update with immediate reflection in LIFF
 */
function updateReward($db, $loyalty, $id, $data) {
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid reward ID'];
    }
    
    $updateData = [];
    
    // Only update provided fields
    if (isset($data['name'])) $updateData['name'] = trim($data['name']);
    if (isset($data['description'])) $updateData['description'] = trim($data['description']);
    if (isset($data['image_url'])) $updateData['image_url'] = trim($data['image_url']);
    if (isset($data['points_required'])) $updateData['points_required'] = (int)$data['points_required'];
    if (isset($data['reward_type'])) $updateData['reward_type'] = $data['reward_type'];
    if (isset($data['reward_value'])) {
        $rv = trim((string)$data['reward_value']);
        $updateData['reward_value'] = $rv === '' ? null : $rv;
    }
    if (isset($data['stock'])) $updateData['stock'] = (int)$data['stock'];
    if (isset($data['max_per_user'])) $updateData['max_per_user'] = (int)$data['max_per_user'];
    if (isset($data['is_active'])) $updateData['is_active'] = (int)$data['is_active'];
    
    if (empty($updateData)) {
        return ['success' => false, 'message' => 'No data to update'];
    }
    
    try {
        $loyalty->updateReward($id, $updateData);
        return ['success' => true, 'message' => 'อัปเดตรางวัลสำเร็จ'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
    }
}


/**
 * Disable reward
 * Requirement 24.6: Hide from catalog while preserving existing redemptions
 */
function disableReward($db, $loyalty, $id) {
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid reward ID'];
    }
    
    try {
        // Just set is_active to 0, don't delete - preserves existing redemptions
        $loyalty->updateReward($id, ['is_active' => 0]);
        return ['success' => true, 'message' => 'ปิดใช้งานรางวัลสำเร็จ'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
    }
}

/**
 * Toggle reward active status
 */
function toggleReward($db, $loyalty, $id) {
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid reward ID'];
    }
    
    $reward = $loyalty->getReward($id);
    if (!$reward) {
        return ['success' => false, 'message' => 'ไม่พบรางวัล'];
    }
    
    $newStatus = $reward['is_active'] ? 0 : 1;
    $loyalty->updateReward($id, ['is_active' => $newStatus]);
    
    return ['success' => true, 'is_active' => $newStatus, 'message' => $newStatus ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว'];
}

/**
 * Delete reward (soft delete by disabling, or hard delete if no redemptions)
 */
function deleteReward($db, $loyalty, $id) {
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Invalid reward ID'];
    }
    
    // Check if there are any redemptions
    $stmt = $db->prepare("SELECT COUNT(*) FROM reward_redemptions WHERE reward_id = ?");
    $stmt->execute([$id]);
    $hasRedemptions = $stmt->fetchColumn() > 0;
    
    if ($hasRedemptions) {
        // Soft delete - just disable
        $loyalty->updateReward($id, ['is_active' => 0]);
        return ['success' => true, 'message' => 'ปิดใช้งานรางวัลแล้ว (มีประวัติการแลก)'];
    } else {
        // Hard delete
        $loyalty->deleteReward($id);
        return ['success' => true, 'message' => 'ลบรางวัลสำเร็จ'];
    }
}


/**
 * Approve redemption
 * Requirement 24.8: Update status and send LINE notification
 */
function approveRedemption($db, $loyalty, $redemptionId, $adminId, $notes, $lineAccountId) {
    if ($redemptionId <= 0) {
        return ['success' => false, 'message' => 'Invalid redemption ID'];
    }
    
    // Get redemption details for notification
    $stmt = $db->prepare("
        SELECT rr.*, r.name as reward_name, u.line_user_id, u.display_name
        FROM reward_redemptions rr
        JOIN rewards r ON rr.reward_id = r.id
        JOIN users u ON rr.user_id = u.id
        WHERE rr.id = ?
    ");
    $stmt->execute([$redemptionId]);
    $redemption = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$redemption) {
        return ['success' => false, 'message' => 'ไม่พบรายการแลกรางวัล'];
    }
    
    if ($redemption['status'] !== 'pending') {
        return ['success' => false, 'message' => 'รายการนี้ไม่อยู่ในสถานะรอดำเนินการ'];
    }
    
    // Update status
    error_log("approveRedemption: Before updateRedemptionStatus");
    $loyalty->updateRedemptionStatus($redemptionId, 'approved', $adminId, $notes);
    error_log("approveRedemption: After updateRedemptionStatus");

    // Send LINE notification
    error_log("approveRedemption: Before sendRedemptionNotification");
    sendRedemptionNotification($db, $lineAccountId, $redemption, 'approved');
    error_log("approveRedemption: After sendRedemptionNotification");

    return ['success' => true, 'message' => 'อนุมัติการแลกรางวัลสำเร็จ'];
}

/**
 * Mark redemption as delivered
 * Requirement 24.9: Record delivery timestamp and update status
 */
function deliverRedemption($db, $loyalty, $redemptionId, $adminId, $notes, $lineAccountId) {
    if ($redemptionId <= 0) {
        return ['success' => false, 'message' => 'Invalid redemption ID'];
    }
    
    // Get redemption details
    $stmt = $db->prepare("
        SELECT rr.*, r.name as reward_name, u.line_user_id, u.display_name
        FROM reward_redemptions rr
        JOIN rewards r ON rr.reward_id = r.id
        JOIN users u ON rr.user_id = u.id
        WHERE rr.id = ?
    ");
    $stmt->execute([$redemptionId]);
    $redemption = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$redemption) {
        return ['success' => false, 'message' => 'ไม่พบรายการแลกรางวัล'];
    }
    
    if (!in_array($redemption['status'], ['pending', 'approved'])) {
        return ['success' => false, 'message' => 'ไม่สามารถอัปเดตสถานะได้'];
    }
    
    // Update status with delivery timestamp
    $loyalty->updateRedemptionStatus($redemptionId, 'delivered', $adminId, $notes);
    
    // Send LINE notification
    sendRedemptionNotification($db, $lineAccountId, $redemption, 'delivered');
    
    return ['success' => true, 'message' => 'บันทึกการส่งมอบสำเร็จ'];
}

/**
 * Cancel redemption
 */
function cancelRedemption($db, $loyalty, $redemptionId, $adminId, $notes, $lineAccountId) {
    if ($redemptionId <= 0) {
        return ['success' => false, 'message' => 'Invalid redemption ID'];
    }
    
    // Get redemption details
    $stmt = $db->prepare("
        SELECT rr.*, r.name as reward_name, r.points_required, u.line_user_id, u.display_name, u.id as user_id
        FROM reward_redemptions rr
        JOIN rewards r ON rr.reward_id = r.id
        JOIN users u ON rr.user_id = u.id
        WHERE rr.id = ?
    ");
    $stmt->execute([$redemptionId]);
    $redemption = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$redemption) {
        return ['success' => false, 'message' => 'ไม่พบรายการแลกรางวัล'];
    }
    
    if ($redemption['status'] === 'delivered') {
        return ['success' => false, 'message' => 'ไม่สามารถยกเลิกรายการที่ส่งมอบแล้ว'];
    }
    
    // Refund points.
    // BATCH 2: keyed so a re-submitted cancel cannot refund twice, and typed
    // 'refund' rather than 'earn' so the ledger says what actually happened.
    $loyalty->addPoints(
        $redemption['user_id'],
        $redemption['points_used'],
        'refund',
        $redemptionId,
        "คืนแต้มจากการยกเลิก: {$redemption['reward_name']}",
        [
            'type' => LoyaltyLedgerService::TYPE_REFUND,
            'idempotency_key' => 'redemption:' . (int) $redemptionId . ':refund',
            'created_by' => 'admin:' . (int) ($adminId ?? 0),
        ]
    );
    
    // Update status
    $loyalty->updateRedemptionStatus($redemptionId, 'cancelled', $adminId, $notes);
    
    // Restore stock if applicable
    $stmt = $db->prepare("UPDATE rewards SET stock = stock + 1 WHERE id = ? AND stock >= 0");
    $stmt->execute([$redemption['reward_id']]);
    
    // Send LINE notification
    sendRedemptionNotification($db, $lineAccountId, $redemption, 'cancelled');
    
    return ['success' => true, 'message' => 'ยกเลิกและคืนแต้มสำเร็จ'];
}


/**
 * Send LINE notification for redemption status change
 * Requirement 24.8: Send LINE notification to user
 */
function sendRedemptionNotification($db, $lineAccountId, $redemption, $status) {
    try {
        error_log("sendRedemptionNotification called:");
        error_log("  - lineAccountId: $lineAccountId");
        error_log("  - status: $status");
        error_log("  - redemption: " . json_encode($redemption));

        // Get LINE account credentials
        $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        error_log("  - account found: " . ($account ? 'YES' : 'NO'));
        error_log("  - has token: " . (!empty($account['channel_access_token']) ? 'YES' : 'NO'));

        if (!$account || empty($account['channel_access_token'])) {
            error_log("  - FAILED: No account or token");
            return false;
        }

        $messages = [
            'approved' => [
                'title' => '✅ รางวัลได้รับการอนุมัติ',
                'body' => "รางวัล: {$redemption['reward_name']}\nรหัส: {$redemption['redemption_code']}\n\nกรุณาติดต่อรับรางวัลที่ร้าน"
            ],
            'delivered' => [
                'title' => '🎁 ส่งมอบรางวัลแล้ว',
                'body' => "รางวัล: {$redemption['reward_name']}\nรหัส: {$redemption['redemption_code']}\n\nขอบคุณที่ใช้บริการ"
            ],
            'cancelled' => [
                'title' => '❌ ยกเลิกการแลกรางวัล',
                'body' => "รางวัล: {$redemption['reward_name']}\n\nแต้มได้ถูกคืนเข้าบัญชีของคุณแล้ว"
            ]
        ];

        $msg = $messages[$status] ?? null;
        if (!$msg) {
            error_log("  - FAILED: Invalid status '$status'");
            return false;
        }

        // Send push message via LINE API
        $data = [
            'to' => $redemption['line_user_id'],
            'messages' => [[
                'type' => 'text',
                'text' => "{$msg['title']}\n\n{$msg['body']}"
            ]]
        ];

        error_log("  - Sending to user: " . $redemption['line_user_id']);
        error_log("  - Message: " . $msg['title']);

        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $account['channel_access_token']
            ],
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("  - HTTP Code: $httpCode");
        error_log("  - Response: " . $response);

        if ($httpCode == 200) {
            error_log("  - SUCCESS: Notification sent");
            return true;
        } else {
            error_log("  - FAILED: HTTP $httpCode - $response");
            return false;
        }
    } catch (Exception $e) {
        error_log("Failed to send redemption notification: " . $e->getMessage());
        return false;
    }
}


/**
 * Export redemptions as CSV
 * Requirement 24.10: Generate CSV with all redemption data for selected period
 */
function exportRedemptionsCSV($db, $lineAccountId) {
    // Get date range from query params
    $startDate = $_GET['start_date'] ?? date('Y-m-01'); // Default: first day of current month
    $endDate = $_GET['end_date'] ?? date('Y-m-d'); // Default: today
    $status = $_GET['status'] ?? null;
    
    // Fetch redemptions
    $sql = "
        SELECT 
            rr.id,
            rr.redemption_code,
            rr.created_at,
            rr.status,
            rr.points_used,
            rr.approved_at,
            rr.delivered_at,
            rr.notes,
            r.name as reward_name,
            r.reward_type,
            r.points_required,
            u.display_name as user_name,
            u.phone as user_phone,
            u.line_user_id,
            a.username as approved_by
        FROM reward_redemptions rr
        JOIN rewards r ON rr.reward_id = r.id
        JOIN users u ON rr.user_id = u.id
        LEFT JOIN admin_users a ON rr.approved_by = a.id
        WHERE (rr.line_account_id = ? OR rr.line_account_id IS NULL)
        AND DATE(rr.created_at) BETWEEN ? AND ?
    ";
    $params = [$lineAccountId, $startDate, $endDate];
    
    if ($status) {
        $sql .= " AND rr.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY rr.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $redemptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="redemptions_' . $startDate . '_to_' . $endDate . '.csv"');
    
    // Add BOM for Excel UTF-8 compatibility
    echo "\xEF\xBB\xBF";
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Write header row
    fputcsv($output, [
        'ID',
        'รหัสแลกรางวัล',
        'วันที่แลก',
        'สถานะ',
        'ชื่อรางวัล',
        'ประเภท',
        'แต้มที่ใช้',
        'ชื่อผู้แลก',
        'เบอร์โทร',
        'LINE User ID',
        'อนุมัติโดย',
        'วันที่อนุมัติ',
        'วันที่ส่งมอบ',
        'หมายเหตุ'
    ]);
    
    // Status labels
    $statusLabels = [
        'pending' => 'รอดำเนินการ',
        'approved' => 'อนุมัติแล้ว',
        'delivered' => 'ส่งมอบแล้ว',
        'cancelled' => 'ยกเลิก'
    ];
    
    // Type labels
    $typeLabels = [
        'discount' => 'ส่วนลด',
        'shipping' => 'ค่าส่งฟรี',
        'gift' => 'ของแถม',
        'product' => 'สินค้า',
        'coupon' => 'คูปอง',
        'voucher' => 'บัตรกำนัล'
    ];
    
    // Write data rows
    foreach ($redemptions as $row) {
        fputcsv($output, [
            $row['id'],
            $row['redemption_code'],
            $row['created_at'],
            $statusLabels[$row['status']] ?? $row['status'],
            $row['reward_name'],
            $typeLabels[$row['reward_type']] ?? $row['reward_type'],
            $row['points_used'],
            $row['user_name'],
            $row['user_phone'] ?? '-',
            $row['line_user_id'],
            $row['approved_by'] ?? '-',
            $row['approved_at'] ?? '-',
            $row['delivered_at'] ?? '-',
            $row['notes'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}
