<?php
/**
 * Points API - จัดการแต้มสะสม
 */

// CRITICAL: Error handling must be FIRST - before any includes
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any accidental output
ob_start();

// Set headers before any potential output
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Register shutdown function to clean output buffer on fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    echo json_encode(['success' => true]);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
// PHASE 5: this was the ONLY points endpoint without tenant routing, so a
// root-domain (mini app / LIFF) request fell through to the legacy fallback DB
// and read a different database than every sibling endpoint.
require_once __DIR__ . '/../bootstrap/route_by_account.php';

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
    $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
    }

    // PHASE 5: read the balance and the history from the canonical ledger.
    // `current_points` used to come from `users.points`, a store this endpoint
    // was the last writer of, and the history preferred `points_history` over
    // `points_transactions` — so this screen could disagree with every other
    // screen about both the balance and what produced it.
    require_once __DIR__ . '/../classes/LoyaltyPoints.php';
    $loyalty = new LoyaltyPoints($db, $lineAccountId);
    $balance = $loyalty->getUserPoints($user['id']);
    $history = $loyalty->ledger()->getHistory((int) $user['id'], $limit);

    // Members whose points predate the ledger have no rows there; show them
    // their legacy history rather than an empty screen. Transitional, and it
    // disappears once §34 carries those balances across.
    if (empty($history)) {
        try {
            $legacy = $db->prepare(
                "SELECT points, type, description, reference_type, reference_id, balance_after, created_at
                   FROM points_history
                  WHERE user_id = ?
                  ORDER BY created_at DESC
                  LIMIT " . (int) $limit
            );
            $legacy->execute([$user['id']]);
            $history = $legacy->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $history = [];
        }
    }

    jsonResponse(true, 'OK', [
        'current_points' => (int) $balance['available_points'],
        'total_earned' => (int) $balance['total_points'],
        'total_used' => (int) $balance['used_points'],
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
    $lineAccountId = (int) ($data['line_account_id'] ?? 1);
    $rewardId = (int) ($data['reward_id'] ?? 0);

    if (empty($lineUserId)) {
        jsonResponse(false, 'กรุณาเข้าสู่ระบบ');
    }

    if (empty($rewardId)) {
        jsonResponse(false, 'กรุณาเลือกของรางวัล');
    }

    // PHASE 5. This function used to be a SECOND, fully parallel redemption
    // stack: it checked sufficiency against `users.points` and settled by
    // overwriting `users.points` + `points_history`, touching neither
    // `points_transactions` nor `users.available_points`. The two balances were
    // independent, so the same reward could be redeemed once through here and
    // once through api/rewards.php with neither deduction visible to the other.
    //
    // Both endpoints now call RewardRedemptionService, so there is one balance,
    // one set of validations and one redemption record.
    $stmt = $db->prepare('SELECT id FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1');
    $stmt->execute([$lineUserId, $lineAccountId]);
    $userId = (int) ($stmt->fetchColumn() ?: 0);

    if ($userId === 0) {
        // Fall back to an unscoped lookup for rows created before OA scoping.
        $stmt = $db->prepare('SELECT id FROM users WHERE line_user_id = ? LIMIT 1');
        $stmt->execute([$lineUserId]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);
    }

    if ($userId === 0) {
        jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
    }

    require_once __DIR__ . '/../classes/RewardRedemptionService.php';
    $service = new RewardRedemptionService($db, $lineAccountId);

    $result = $service->redeem($userId, $rewardId, [
        'created_by' => 'miniapp:points-api',
    ]);

    if (!$result['ok']) {
        if ($result['reason'] === RewardRedemptionService::REASON_INSUFFICIENT) {
            $quote = $service->quote($userId, $rewardId);
            jsonResponse(false, $result['message'], [
                'current_points' => $quote['available_points'],
                'required_points' => $quote['points_required'],
            ]);
        }
        jsonResponse(false, $result['message']);
    }

    jsonResponse(true, 'แลกของรางวัลสำเร็จ! 🎉', [
        'reward' => $result['reward'],
        'coupon_code' => $result['redemption_code'],
        'redemption_code' => $result['redemption_code'],
        'new_balance' => $result['new_balance'],
    ]);
}

function jsonResponse($success, $message, $data = []) {
    ob_clean();
    echo json_encode([
        'success' => $success,
        'message' => $message,
        ...$data
    ], JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    exit;
}
