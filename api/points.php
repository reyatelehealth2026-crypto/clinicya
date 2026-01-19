<?php
/**
 * Points API - จัดการแต้มสะสม
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

try {
    switch ($action) {
        case 'history':
            handleHistory($db);
            break;
        case 'rewards':
            handleGetRewards($db);
            break;
        case 'redeem':
            handleRedeem($db, $input ?? $_POST);
            break;
        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage());
}

/**
 * ดึงประวัติแต้ม
 */
function handleHistory($db) {
    $lineUserId = $_GET['line_user_id'] ?? '';
    $lineAccountId = $_GET['line_account_id'] ?? 1;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    
    if (empty($lineUserId)) {
        jsonResponse(false, 'Missing line_user_id');
    }
    
    // Get user
    $stmt = $db->prepare("SELECT id, points FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
    }
    
    // Get history - try points_history first, fallback to points_transactions
    $history = [];
    try {
        $stmt = $db->prepare("
            SELECT points, type, description, reference_type, reference_id, balance_after, created_at
            FROM points_history 
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$user['id'], $limit]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fallback to points_transactions (legacy table)
        try {
            $stmt = $db->prepare("
                SELECT points, type, description, reference_type, reference_id, balance_after, created_at
                FROM points_transactions 
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$user['id'], $limit]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {}
    }
    
    // Calculate totals - try points_history first, fallback to points_transactions
    $totals = ['total_earned' => 0, 'total_used' => 0];
    try {
        $stmt = $db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_earned,
                COALESCE(ABS(SUM(CASE WHEN points < 0 THEN points ELSE 0 END)), 0) as total_used
            FROM points_history 
            WHERE user_id = ?
        ");
        $stmt->execute([$user['id']]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        try {
            $stmt = $db->prepare("
                SELECT 
                    COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_earned,
                    COALESCE(ABS(SUM(CASE WHEN points < 0 THEN points ELSE 0 END)), 0) as total_used
                FROM points_transactions 
                WHERE user_id = ?
            ");
            $stmt->execute([$user['id']]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {}
    }
    
    jsonResponse(true, 'OK', [
        'current_points' => (int)$user['points'],
        'total_earned' => (int)$totals['total_earned'],
        'total_used' => (int)$totals['total_used'],
        'history' => $history
    ]);
}

/**
 * ดึงรายการของรางวัลที่แลกได้
 */
function handleGetRewards($db) {
    $lineAccountId = $_GET['line_account_id'] ?? 1;
    
    // Try new rewards table first, then fallback to point_rewards
    try {
        // Check if rewards table has line_account_id column
        $stmt = $db->query("SHOW COLUMNS FROM rewards LIKE 'line_account_id'");
        $hasLineAccountId = $stmt->fetch() !== false;
        
        $stmt = $db->query("SHOW COLUMNS FROM rewards LIKE 'is_active'");
        $hasIsActive = $stmt->fetch() !== false;
        
        $sql = "SELECT * FROM rewards WHERE 1=1";
        $params = [];
        
        if ($hasLineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $lineAccountId;
        }
        
        if ($hasIsActive) {
            $sql .= " AND is_active = 1";
        }
        
        $sql .= " AND (stock IS NULL OR stock < 0 OR stock > 0) ORDER BY points_required ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($rewards)) {
            // Try old table
            throw new Exception('No rewards in new table');
        }
    } catch (Exception $e) {
        // Fallback to point_rewards table
        try {
            $stmt = $db->prepare("
                SELECT * FROM point_rewards 
                WHERE (line_account_id = ? OR line_account_id IS NULL) 
                AND is_active = 1 
                AND (stock IS NULL OR stock > 0)
                ORDER BY points_required ASC
            ");
            $stmt->execute([$lineAccountId]);
            $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            $rewards = [];
        }
    }
    
    jsonResponse(true, 'OK', ['rewards' => $rewards]);
}

/**
 * แลกแต้ม
 */
function handleRedeem($db, $data) {
    $lineUserId = $data['line_user_id'] ?? '';
    $lineAccountId = $data['line_account_id'] ?? 1;
    $rewardId = $data['reward_id'] ?? 0;
    
    if (empty($lineUserId)) {
        jsonResponse(false, 'กรุณาเข้าสู่ระบบ');
    }
    
    if (empty($rewardId)) {
        jsonResponse(false, 'กรุณาเลือกของรางวัล');
    }
    
    // Get user
    $stmt = $db->prepare("SELECT id, points FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
    }
    
    // Get reward - Try rewards table first, then point_rewards
    $reward = null;

    try {
        // Try new rewards table
        $stmt = $db->query("SHOW COLUMNS FROM rewards LIKE 'is_active'");
        $hasIsActive = $stmt->fetch() !== false;

        $sql = "SELECT * FROM rewards WHERE id = ?";
        if ($hasIsActive) {
            $sql .= " AND is_active = 1";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute([$rewardId]);
        $reward = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fallback to point_rewards table
        try {
            $stmt = $db->prepare("SELECT * FROM point_rewards WHERE id = ? AND is_active = 1");
            $stmt->execute([$rewardId]);
            $reward = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            // No table found
        }
    }

    if (!$reward) {
        jsonResponse(false, 'ไม่พบของรางวัลนี้');
    }

    // Check stock
    if (isset($reward['stock']) && $reward['stock'] !== null && $reward['stock'] <= 0) {
        jsonResponse(false, 'ของรางวัลหมดแล้ว');
    }
    
    // Check points
    if ($user['points'] < $reward['points_required']) {
        jsonResponse(false, 'แต้มไม่เพียงพอ', [
            'current_points' => (int)$user['points'],
            'required_points' => (int)$reward['points_required']
        ]);
    }
    
    // Start transaction
    $db->beginTransaction();

    try {
        // Deduct points
        $newBalance = $user['points'] - $reward['points_required'];
        $stmt = $db->prepare("UPDATE users SET points = ? WHERE id = ?");
        $stmt->execute([$newBalance, $user['id']]);

        // Update stock if applicable
        if (isset($reward['stock']) && $reward['stock'] !== null && $reward['stock'] > 0) {
            // Try rewards table first
            try {
                $stmt = $db->prepare("UPDATE rewards SET stock = stock - 1 WHERE id = ?");
                $stmt->execute([$reward['id']]);
            } catch (Exception $e) {
                // Try point_rewards table
                $stmt = $db->prepare("UPDATE point_rewards SET stock = stock - 1 WHERE id = ?");
                $stmt->execute([$reward['id']]);
            }
        }

        // Generate coupon code
        $couponCode = 'RW' . date('ymd') . strtoupper(substr(md5(uniqid()), 0, 6));

        // Log redemption
        $stmt = $db->prepare("
            INSERT INTO points_history (line_account_id, user_id, points, type, description, reference_type, reference_id, balance_after)
            VALUES (?, ?, ?, 'redeem', ?, 'reward', ?, ?)
        ");
        $stmt->execute([
            $lineAccountId,
            $user['id'],
            -$reward['points_required'],
            'แลก: ' . $reward['name'],
            $reward['id'],
            $newBalance
        ]);

        // Save redemption record (if table exists)
        try {
            $stmt = $db->prepare("
                INSERT INTO point_redemptions (line_account_id, user_id, reward_id, points_used, coupon_code, status, redeemed_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $lineAccountId,
                $user['id'],
                $reward['id'],
                $reward['points_required'],
                $couponCode
            ]);
        } catch (Exception $e) {
            // Table doesn't exist, skip
        }

        // Commit transaction
        $db->commit();

        jsonResponse(true, 'แลกของรางวัลสำเร็จ! 🎉', [
            'reward' => $reward,
            'coupon_code' => $couponCode,
            'new_balance' => $newBalance
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(false, 'เกิดข้อผิดพลาด: ' . $e->getMessage());
    }
}

function jsonResponse($success, $message, $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        ...$data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
