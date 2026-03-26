<?php
/**
 * User Profile API
 * สำหรับ LIFF - บันทึก/ดึงข้อมูล user profile
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

require_once '../config/config.php';
require_once '../config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Handle GET requests
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'get_data') {
            $lineUserId = $_GET['line_user_id'] ?? '';
            $accountId = $_GET['account_id'] ?? 1;

            if (empty($lineUserId)) {
                throw new Exception('Missing line_user_id');
            }

            // Get user
            $stmt = $db->prepare("SELECT id, display_name, picture_url FROM users WHERE line_user_id = ?");
            $stmt->execute([$lineUserId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'User not found']);
                ob_end_flush();
                exit;
            }

            $response = [
                'success' => true,
                'user_id' => $user['id'],
                'display_name' => $user['display_name'],
                'picture_url' => $user['picture_url'],
                'points' => 0,
                'cart_count' => 0,
                'tier' => ['name' => 'Bronze', 'min' => 0]
            ];

            // Get loyalty points
            try {
                $stmt = $db->prepare("SELECT points FROM loyalty_points WHERE user_id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$user['id'], $accountId]);
                $points = $stmt->fetchColumn();
                $response['points'] = (int) ($points ?: 0);

                // Calculate tier
                if ($response['points'] >= 5000) {
                    $response['tier'] = ['name' => 'Platinum', 'min' => 5000];
                } elseif ($response['points'] >= 2000) {
                    $response['tier'] = ['name' => 'Gold', 'min' => 2000];
                } elseif ($response['points'] >= 500) {
                    $response['tier'] = ['name' => 'Silver', 'min' => 500];
                }
            } catch (Exception $e) {
            }

            // Get cart count
            try {
                $stmt = $db->prepare("SELECT SUM(quantity) FROM cart WHERE user_id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$user['id'], $accountId]);
                $cartCount = $stmt->fetchColumn();
                $response['cart_count'] = (int) ($cartCount ?: 0);
            } catch (Exception $e) {
            }

            ob_clean();
            echo json_encode($response);
            ob_end_flush();
            exit;
        }

        // Get personal info for editing
        if ($action === 'get_personal_info') {
            $lineUserId = $_GET['line_user_id'] ?? '';

            if (empty($lineUserId)) {
                throw new Exception('Missing line_user_id');
            }

            $stmt = $db->prepare("SELECT id, display_name, real_name, phone, email, birthday, gender, address, province, postal_code FROM users WHERE line_user_id = ?");
            $stmt->execute([$lineUserId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'User not found']);
                ob_end_flush();
                exit;
            }

            ob_clean();
            echo json_encode([
                'success' => true,
                'data' => [
                    'user_id' => $user['id'],
                    'display_name' => $user['display_name'] ?? '',
                    'real_name' => $user['real_name'] ?? '',
                    'phone' => $user['phone'] ?? '',
                    'email' => $user['email'] ?? '',
                    'birthday' => $user['birthday'] ?? '',
                    'gender' => $user['gender'] ?? '',
                    'address' => $user['address'] ?? '',
                    'province' => $user['province'] ?? '',
                    'postal_code' => $user['postal_code'] ?? ''
                ]
            ]);
            ob_end_flush();
            exit;
        }

        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        ob_end_flush();
        exit;
    }

    // Handle POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        if ($action === 'update_profile') {
            $lineUserId = $input['line_user_id'] ?? '';
            $displayName = $input['display_name'] ?? '';
            $pictureUrl = $input['picture_url'] ?? '';
            $statusMessage = $input['status_message'] ?? '';
            $accountId = $input['line_account_id'] ?? null;

            if (empty($lineUserId)) {
                throw new Exception('Missing line_user_id');
            }

            // Check if user exists
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
            $stmt->execute([$lineUserId]);
            $userId = $stmt->fetchColumn();

            if ($userId) {
                // Update existing user
                $stmt = $db->prepare("UPDATE users SET display_name = ?, picture_url = ?, status_message = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$displayName, $pictureUrl, $statusMessage, $userId]);
            } else {
                // Create new user
                $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name, picture_url, status_message, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$accountId, $lineUserId, $displayName, $pictureUrl, $statusMessage]);
                $userId = $db->lastInsertId();
            }

            ob_clean();
            echo json_encode([
                'success' => true,
                'user_id' => $userId,
                'message' => 'Profile updated'
            ]);
            ob_end_flush();
            exit;
        }

        // Update personal info (from LIFF settings page)
        if ($action === 'update_personal_info') {
            $lineUserId = $input['line_user_id'] ?? '';

            if (empty($lineUserId)) {
                throw new Exception('Missing line_user_id');
            }

            // Get user ID
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
            $stmt->execute([$lineUserId]);
            $userId = $stmt->fetchColumn();

            if (!$userId) {
                throw new Exception('User not found');
            }

            // Sanitize input
            $realName = trim($input['real_name'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $email = trim($input['email'] ?? '');
            $birthday = $input['birthday'] ?: null;
            $gender = $input['gender'] ?: null;
            $address = trim($input['address'] ?? '');
            $province = trim($input['province'] ?? '');
            $postalCode = trim($input['postal_code'] ?? '');

            // Validate phone format (Thai mobile)
            if (!empty($phone) && !preg_match('/^0[0-9]{8,9}$/', $phone)) {
                throw new Exception('รูปแบบเบอร์โทรไม่ถูกต้อง');
            }

            // Validate email format
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
            }

            // Update user
            $stmt = $db->prepare("UPDATE users SET 
                real_name = ?, phone = ?, email = ?, birthday = ?, gender = ?,
                address = ?, province = ?, postal_code = ?, updated_at = NOW()
                WHERE id = ?");
            $stmt->execute([$realName, $phone, $email, $birthday, $gender, $address, $province, $postalCode, $userId]);

            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'บันทึกข้อมูลเรียบร้อยแล้ว'
            ]);
            ob_end_flush();
            exit;
        }

        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        ob_end_flush();
        exit;
    }

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    ob_end_flush();
}
