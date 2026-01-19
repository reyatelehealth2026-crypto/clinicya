<?php
/**
 * Rewards API - จัดการรางวัลแลกแต้ม
 * ใช้ LoyaltyPoints class สำหรับ business logic
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
require_once __DIR__ . '/../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

try {
    switch ($action) {
        case 'list':
        case 'rewards':
            handleGetRewards($db);
            break;
        case 'redeem':
            handleRedeem($db, $input ?? $_POST);
            break;
        case 'my_redemptions':
            handleMyRedemptions($db);
            break;
        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Exception $e) {
    error_log("Rewards API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    jsonResponse(false, $e->getMessage(), [
        'error_details' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}

/**
 * ดึงรายการรางวัลที่แลกได้
 */
function handleGetRewards($db) {
    $lineAccountId = $_GET['line_account_id'] ?? 1;

    try {
        $loyalty = new LoyaltyPoints($db, $lineAccountId);
        $rewards = $loyalty->getActiveRewards();

        // If no rewards found, try with default account
        if (empty($rewards) && $lineAccountId != 1) {
            // Get default account
            $stmt = $db->prepare("SELECT id FROM line_accounts WHERE is_default = 1 LIMIT 1");
            $stmt->execute();
            $defaultAccount = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($defaultAccount) {
                $loyalty = new LoyaltyPoints($db, $defaultAccount['id']);
                $rewards = $loyalty->getActiveRewards();
            }
        }

        jsonResponse(true, 'OK', ['rewards' => $rewards]);
    } catch (Exception $e) {
        error_log("Error getting rewards: " . $e->getMessage());
        jsonResponse(false, 'ไม่สามารถโหลดรางวัลได้: ' . $e->getMessage());
    }
}

/**
 * แลกรางวัล
 */
function handleRedeem($db, $data) {
    $lineUserId = $data['line_user_id'] ?? '';
    $lineAccountId = $data['line_account_id'] ?? 1;
    $rewardId = (int)($data['reward_id'] ?? 0);
    
    // Validate input
    if (empty($lineUserId)) {
        jsonResponse(false, 'กรุณาเข้าสู่ระบบ');
    }
    
    if (empty($rewardId)) {
        jsonResponse(false, 'กรุณาเลือกของรางวัล');
    }
    
    try {
        // Get user
        $stmt = $db->prepare("SELECT id, display_name FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
        }
        
        // Redeem reward using LoyaltyPoints class
        $loyalty = new LoyaltyPoints($db, $lineAccountId);
        $result = $loyalty->redeemReward($user['id'], $rewardId);
        
        if ($result['success']) {
            // Get updated member data
            $member = $loyalty->getMemberByUserId($user['id']);
            
            jsonResponse(true, $result['message'], [
                'redemption_code' => $result['redemption_code'],
                'reward' => $result['reward'],
                'redemption_id' => $result['redemption_id'],
                'expires_at' => $result['expires_at'] ?? null,
                'new_balance' => $member['available_points'] ?? 0,
                'member' => $member
            ]);
        } else {
            jsonResponse(false, $result['message']);
        }
        
    } catch (Exception $e) {
        error_log("Redeem error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        jsonResponse(false, 'เกิดข้อผิดพลาด: ' . $e->getMessage(), [
            'error_details' => [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
    }
}

/**
 * ดึงประวัติการแลกรางวัลของผู้ใช้
 */
function handleMyRedemptions($db) {
    $lineUserId = $_GET['line_user_id'] ?? '';
    $lineAccountId = $_GET['line_account_id'] ?? 1;
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    
    if (empty($lineUserId)) {
        jsonResponse(false, 'Missing line_user_id');
    }
    
    try {
        // Get user
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            jsonResponse(false, 'ไม่พบข้อมูลผู้ใช้');
        }
        
        // Get redemptions
        $loyalty = new LoyaltyPoints($db, $lineAccountId);
        $redemptions = $loyalty->getUserRedemptions($user['id'], $limit);
        
        jsonResponse(true, 'OK', ['redemptions' => $redemptions]);
        
    } catch (Exception $e) {
        error_log("Error getting redemptions: " . $e->getMessage());
        jsonResponse(false, 'ไม่สามารถโหลดประวัติได้');
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
