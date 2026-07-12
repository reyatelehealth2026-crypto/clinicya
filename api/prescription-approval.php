<?php
/**
 * Prescription Approval API
 * Handles prescription approval creation, validation, and status checking
 * 
 * Requirements:
 * - 11.7: Create approval record on pharmacist approval
 * - 11.9: Set 24-hour expiry for prescription approval
 * - 11.10: Check approval validity before checkout
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Ensure prescription_approvals table exists
ensurePrescriptionApprovalsTable($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create':
        handleCreateApproval($db);
        break;
        
    case 'check_status':
        handleCheckStatus($db);
        break;
        
    case 'get_approval':
        handleGetApproval($db);
        break;
        
    case 'expire':
        handleExpireApproval($db);
        break;
        
    case 'list_pending':
        handleListPending($db);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

/**
 * Create prescription approval record
 * Requirements: 11.7, 11.9 - Create approval with 24-hour expiry
 */
function handleCreateApproval($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $lineUserId = $input['line_user_id'] ?? null;
    $lineAccountId = $input['line_account_id'] ?? null;
    $pharmacistId = $input['pharmacist_id'] ?? null;
    $approvedItems = $input['approved_items'] ?? [];
    $videoCallId = $input['video_call_id'] ?? null;
    $notes = $input['notes'] ?? '';
    
    if (!$lineUserId || empty($approvedItems)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    try {
        // Get user ID from line_user_id
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? AND line_account_id = ?");
        $stmt->execute([$lineUserId, $lineAccountId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            return;
        }
        
        $userId = $user['id'];
        
        // Calculate expiry time (24 hours from now)
        // Requirements: 11.9 - Set 24-hour expiry
        $createdAt = new DateTime();
        $expiresAt = clone $createdAt;
        $expiresAt->modify('+24 hours');
        
        // Create approval record
        $stmt = $db->prepare("
            INSERT INTO prescription_approvals 
            (user_id, pharmacist_id, approved_items, status, video_call_id, notes, line_account_id, created_at, expires_at)
            VALUES (?, ?, ?, 'approved', ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $pharmacistId,
            json_encode($approvedItems, JSON_UNESCAPED_UNICODE),
            $videoCallId,
            $notes,
            $lineAccountId,
            $createdAt->format('Y-m-d H:i:s'),
            $expiresAt->format('Y-m-d H:i:s')
        ]);
        
        $approvalId = $db->lastInsertId();

        // Log the approval
        error_log("Prescription approval created: ID={$approvalId}, User={$userId}, Pharmacist={$pharmacistId}");

        // Immutable audit trail (issue #15): บันทึกการอนุมัติของเภสัชกร (human gate)
        // ลง consultation_audit เพื่อพิสูจน์ต่อ อย. ว่าใครรีวิว/อนุมัติเมื่อใด.
        try {
            require_once __DIR__ . '/../modules/AIChat/Autoloader.php';
            if (class_exists(\Modules\AIChat\Services\ConsultationAudit::class)) {
                (new \Modules\AIChat\Services\ConsultationAudit(
                    $db,
                    $lineAccountId !== null ? (int) $lineAccountId : null
                ))->log(
                    'pharmacist_approve',
                    'pharmacist',
                    null,
                    (int) $userId,
                    [
                        'approval_id'    => (int) $approvalId,
                        'approved_items' => $approvedItems,
                        'notes'          => $notes,
                        'video_call_id'  => $videoCallId,
                    ],
                    ($pharmacistId !== null && is_numeric($pharmacistId)) ? (int) $pharmacistId : null
                );
            }
        } catch (\Throwable $auditErr) {
            error_log('[prescription-approval] audit log failed: ' . $auditErr->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Approval created successfully',
            'data' => [
                'approval_id' => (int)$approvalId,
                'user_id' => (int)$userId,
                'pharmacist_id' => $pharmacistId,
                'approved_items' => $approvedItems,
                'status' => 'approved',
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'valid' => true
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Prescription approval error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to create approval: ' . $e->getMessage()]);
    }
}

/**
 * Check prescription approval status
 * Requirements: 11.10 - Check approval validity before checkout
 */
function handleCheckStatus($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $approvalId = $input['approval_id'] ?? null;
    $lineUserId = $input['line_user_id'] ?? null;
    $lineAccountId = $input['line_account_id'] ?? null;
    
    if (!$approvalId) {
        echo json_encode(['success' => false, 'message' => 'Missing approval ID']);
        return;
    }
    
    try {
        // Get approval record
        $stmt = $db->prepare("
            SELECT pa.*, u.line_user_id
            FROM prescription_approvals pa
            JOIN users u ON pa.user_id = u.id
            WHERE pa.id = ?
        ");
        $stmt->execute([$approvalId]);
        $approval = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$approval) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'valid' => false,
                    'reason' => 'ไม่พบข้อมูลการอนุมัติ',
                    'expired' => false
                ]
            ]);
            return;
        }
        
        // Verify user matches
        if ($lineUserId && $approval['line_user_id'] !== $lineUserId) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'valid' => false,
                    'reason' => 'การอนุมัตินี้ไม่ใช่ของคุณ',
                    'expired' => false
                ]
            ]);
            return;
        }
        
        // Check if expired
        $now = new DateTime();
        $expiresAt = new DateTime($approval['expires_at']);
        $isExpired = $now > $expiresAt;
        
        // Check status
        $isValid = $approval['status'] === 'approved' && !$isExpired;
        
        $reason = null;
        if ($isExpired) {
            $reason = 'การอนุมัติหมดอายุแล้ว กรุณาปรึกษาเภสัชกรอีกครั้ง';
        } elseif ($approval['status'] === 'rejected') {
            $reason = 'การอนุมัติถูกปฏิเสธ';
        } elseif ($approval['status'] === 'used') {
            $reason = 'การอนุมัตินี้ถูกใช้งานแล้ว';
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'valid' => $isValid,
                'expired' => $isExpired,
                'reason' => $reason,
                'status' => $approval['status'],
                'expires_at' => $approval['expires_at'],
                'approved_items' => json_decode($approval['approved_items'], true),
                'pharmacist_id' => $approval['pharmacist_id'],
                'created_at' => $approval['created_at']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Check approval status error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to check status']);
    }
}

/**
 * Get approval details
 */
function handleGetApproval($db) {
    $approvalId = $_GET['approval_id'] ?? null;
    
    if (!$approvalId) {
        echo json_encode(['success' => false, 'message' => 'Missing approval ID']);
        return;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT pa.*, 
                   u.display_name as user_name,
                   u.line_user_id,
                   p.name as pharmacist_name
            FROM prescription_approvals pa
            JOIN users u ON pa.user_id = u.id
            LEFT JOIN pharmacists p ON pa.pharmacist_id = p.id
            WHERE pa.id = ?
        ");
        $stmt->execute([$approvalId]);
        $approval = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$approval) {
            echo json_encode(['success' => false, 'message' => 'Approval not found']);
            return;
        }
        
        // Check if expired
        $now = new DateTime();
        $expiresAt = new DateTime($approval['expires_at']);
        $isExpired = $now > $expiresAt;
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => (int)$approval['id'],
                'user_id' => (int)$approval['user_id'],
                'user_name' => $approval['user_name'],
                'pharmacist_id' => $approval['pharmacist_id'],
                'pharmacist_name' => $approval['pharmacist_name'],
                'approved_items' => json_decode($approval['approved_items'], true),
                'status' => $approval['status'],
                'notes' => $approval['notes'],
                'created_at' => $approval['created_at'],
                'expires_at' => $approval['expires_at'],
                'expired' => $isExpired,
                'valid' => $approval['status'] === 'approved' && !$isExpired
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Get approval error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to get approval']);
    }
}

/**
 * Manually expire an approval (for admin use)
 */
function handleExpireApproval($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    $approvalId = $input['approval_id'] ?? null;
    
    if (!$approvalId) {
        echo json_encode(['success' => false, 'message' => 'Missing approval ID']);
        return;
    }
    
    try {
        $stmt = $db->prepare("
            UPDATE prescription_approvals 
            SET status = 'expired', expires_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$approvalId]);
        
        echo json_encode(['success' => true, 'message' => 'Approval expired']);
        
    } catch (Exception $e) {
        error_log("Expire approval error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to expire approval']);
    }
}

/**
 * List pending approvals for pharmacist dashboard
 */
function handleListPending($db) {
    $lineAccountId = $_GET['line_account_id'] ?? null;
    $pharmacistId = $_GET['pharmacist_id'] ?? null;
    
    try {
        $sql = "
            SELECT pa.*, 
                   u.display_name as user_name,
                   u.phone as user_phone,
                   u.picture_url as user_picture
            FROM prescription_approvals pa
            JOIN users u ON pa.user_id = u.id
            WHERE pa.status = 'pending'
        ";
        
        $params = [];
        
        if ($lineAccountId) {
            $sql .= " AND pa.line_account_id = ?";
            $params[] = $lineAccountId;
        }
        
        $sql .= " ORDER BY pa.created_at DESC LIMIT 50";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = array_map(function($a) {
            return [
                'id' => (int)$a['id'],
                'user_id' => (int)$a['user_id'],
                'user_name' => $a['user_name'],
                'user_phone' => $a['user_phone'],
                'user_picture' => $a['user_picture'],
                'approved_items' => json_decode($a['approved_items'], true),
                'status' => $a['status'],
                'created_at' => $a['created_at']
            ];
        }, $approvals);
        
        echo json_encode([
            'success' => true,
            'data' => $result,
            'count' => count($result)
        ]);
        
    } catch (Exception $e) {
        error_log("List pending approvals error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to list approvals']);
    }
}

/**
 * Ensure prescription_approvals table exists
 */
function ensurePrescriptionApprovalsTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS prescription_approvals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                pharmacist_id INT NULL,
                approved_items JSON NOT NULL,
                status ENUM('pending', 'approved', 'rejected', 'expired', 'used') DEFAULT 'pending',
                video_call_id INT NULL,
                notes TEXT NULL,
                line_account_id INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_expires_at (expires_at),
                INDEX idx_line_account_id (line_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        // Table might already exist, ignore error
        error_log("Create prescription_approvals table: " . $e->getMessage());
    }
}
