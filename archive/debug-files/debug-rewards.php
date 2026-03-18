<?php
/**
 * Debug Rewards API - For testing without authentication
 * This endpoint allows testing rewards functionality without LIFF login
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../debug_rewards_errors.log');

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Log all requests
error_log("=== Debug Rewards API Request ===");
error_log("Method: " . $_SERVER['REQUEST_METHOD']);
error_log("GET: " . json_encode($_GET));
error_log("POST: " . json_encode($_POST));

try {
    // Include required files
    if (!file_exists('../config/config.php')) {
        throw new Exception('Config file not found');
    }
    require_once '../config/config.php';
    
    if (!file_exists('../config/database.php')) {
        throw new Exception('Database config file not found');
    }
    require_once '../config/database.php';
    
    if (!file_exists('../classes/LoyaltyPoints.php')) {
        throw new Exception('LoyaltyPoints class file not found');
    }
    require_once '../classes/LoyaltyPoints.php';
    
    // Get database connection
    $db = Database::getInstance()->getConnection();
    
    if (!$db) {
        throw new Exception('Failed to get database connection');
    }
    
    error_log("✅ All files loaded successfully");
    
} catch (Exception $e) {
    error_log("❌ Fatal error during initialization: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Initialization failed: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $lineAccountId = (int)($_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1);
    
    error_log("Action: $action, Line Account ID: $lineAccountId");
    
    if (empty($action)) {
        throw new Exception('No action specified');
    }
    
    switch ($action) {
        case 'get_config':
            // Get LIFF configuration
            $stmt = $db->prepare("SELECT id, liff_id, name FROM line_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$lineAccountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                // Get default account
                $stmt = $db->prepare("SELECT id, liff_id, name FROM line_accounts ORDER BY id LIMIT 1");
                $stmt->execute();
                $account = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'success' => true,
                'config' => [
                    'account_id' => $account['id'] ?? 1,
                    'liff_id' => $account['liff_id'] ?? null,
                    'shop_name' => $account['name'] ?? 'ร้านค้า'
                ]
            ]);
            break;
            
        case 'rewards':
            // Get all active rewards (no authentication required for debug)
            error_log("Getting rewards for account ID: $lineAccountId");
            
            $loyalty = new LoyaltyPoints($db, $lineAccountId);
            
            // Check if getActiveRewards method exists, otherwise use getRewards
            if (method_exists($loyalty, 'getActiveRewards')) {
                $rewards = $loyalty->getActiveRewards();
            } else {
                $rewards = $loyalty->getRewards(true); // true = active only
            }
            
            error_log("Found " . count($rewards) . " rewards");
            
            echo json_encode([
                'success' => true,
                'rewards' => $rewards,
                'count' => count($rewards)
            ]);
            break;
            
        case 'test_user':
            // Get or create a test user for debugging
            $testUserId = 'U' . str_pad($lineAccountId, 32, '0', STR_PAD_LEFT);
            
            // Check if test user exists
            $stmt = $db->prepare("SELECT id, display_name FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1");
            $stmt->execute([$testUserId, $lineAccountId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                // Create test user
                $stmt = $db->prepare("INSERT INTO users (line_user_id, line_account_id, display_name, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$testUserId, $lineAccountId, 'Test User (Debug)']);
                $userId = $db->lastInsertId();
                
                $user = [
                    'id' => $userId,
                    'display_name' => 'Test User (Debug)'
                ];
            }
            
            // Get member data
            $loyalty = new LoyaltyPoints($db, $lineAccountId);
            $member = $loyalty->getMemberByUserId($user['id']);
            
            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'line_user_id' => $testUserId,
                    'display_name' => $user['display_name']
                ],
                'member' => $member
            ]);
            break;
            
        case 'redeem':
            // Test redemption with test user
            $rewardId = (int)($_POST['reward_id'] ?? 0);
            $testUserId = 'U' . str_pad($lineAccountId, 32, '0', STR_PAD_LEFT);
            
            if (!$rewardId) {
                echo json_encode(['success' => false, 'error' => 'Missing reward_id']);
                exit;
            }
            
            // Get or create test user
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1");
            $stmt->execute([$testUserId, $lineAccountId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $stmt = $db->prepare("INSERT INTO users (line_user_id, line_account_id, display_name, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$testUserId, $lineAccountId, 'Test User (Debug)']);
                $userId = $db->lastInsertId();
            } else {
                $userId = $user['id'];
            }
            
            // Redeem reward
            $loyalty = new LoyaltyPoints($db, $lineAccountId);
            $result = $loyalty->redeemReward($userId, $rewardId);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'แลกรางวัลสำเร็จ!',
                    'redemption_code' => $result['redemption_code'],
                    'reward_name' => $result['reward']['name'] ?? ''
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $result['message'] ?? 'ไม่สามารถแลกรางวัลได้'
                ]);
            }
            break;
            
        case 'add_points':
            // Add test points to test user
            $points = (int)($_POST['points'] ?? 1000);
            $testUserId = 'U' . str_pad($lineAccountId, 32, '0', STR_PAD_LEFT);
            
            // Get or create test user
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1");
            $stmt->execute([$testUserId, $lineAccountId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $stmt = $db->prepare("INSERT INTO users (line_user_id, line_account_id, display_name, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$testUserId, $lineAccountId, 'Test User (Debug)']);
                $userId = $db->lastInsertId();
            } else {
                $userId = $user['id'];
            }
            
            // Add points
            $loyalty = new LoyaltyPoints($db, $lineAccountId);
            $loyalty->addPoints($userId, $points, 'debug_test', 'Debug test points');
            
            $member = $loyalty->getMemberByUserId($userId);
            
            echo json_encode([
                'success' => true,
                'message' => "เพิ่ม {$points} แต้มสำเร็จ",
                'member' => $member
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("❌ Debug Rewards API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ]);
} catch (Error $e) {
    error_log("❌ Debug Rewards API fatal error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ]);
}
