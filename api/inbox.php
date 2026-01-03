<?php
/**
 * Inbox API - Handle inbox actions
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'toggle_notifications':
        case 'toggle_notification':
            $userId = intval($_POST['user_id'] ?? 0);
            $enabled = filter_var($_POST['enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            
            if (!$userId) throw new Exception('User ID required');
            
            // Check if column exists, if not create it
            try {
                $db->query("SELECT notifications_enabled FROM users LIMIT 1");
            } catch (PDOException $e) {
                $db->exec("ALTER TABLE users ADD COLUMN notifications_enabled TINYINT(1) DEFAULT 1");
            }
            
            $stmt = $db->prepare("UPDATE users SET notifications_enabled = ? WHERE id = ?");
            $stmt->execute([$enabled, $userId]);
            
            echo json_encode(['success' => true, 'enabled' => (bool)$enabled]);
            break;
            
        case 'toggle_mute':
            $userId = intval($_POST['user_id'] ?? 0);
            $muted = filter_var($_POST['muted'] ?? '0', FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            
            if (!$userId) throw new Exception('User ID required');
            
            // Check if column exists, if not create it
            try {
                $db->query("SELECT is_muted FROM users LIMIT 1");
            } catch (PDOException $e) {
                $db->exec("ALTER TABLE users ADD COLUMN is_muted TINYINT(1) DEFAULT 0");
            }
            
            $stmt = $db->prepare("UPDATE users SET is_muted = ? WHERE id = ?");
            $stmt->execute([$muted, $userId]);
            
            echo json_encode(['success' => true, 'muted' => (bool)$muted]);
            break;
            
        case 'block_user':
            $userId = intval($_POST['user_id'] ?? 0);
            
            if (!$userId) throw new Exception('User ID required');
            
            // Check if column exists, if not create it
            try {
                $db->query("SELECT is_blocked FROM users LIMIT 1");
            } catch (PDOException $e) {
                $db->exec("ALTER TABLE users ADD COLUMN is_blocked TINYINT(1) DEFAULT 0");
            }
            
            $stmt = $db->prepare("UPDATE users SET is_blocked = 1 WHERE id = ?");
            $stmt->execute([$userId]);
            
            echo json_encode(['success' => true, 'message' => 'User blocked']);
            break;
            
        case 'unblock_user':
            $userId = intval($_POST['user_id'] ?? 0);
            
            if (!$userId) throw new Exception('User ID required');
            
            $stmt = $db->prepare("UPDATE users SET is_blocked = 0 WHERE id = ?");
            $stmt->execute([$userId]);
            
            echo json_encode(['success' => true, 'message' => 'User unblocked']);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
