<?php
/**
 * Messages API - Unified API for Chat System
 * Supports: get_messages, send_message, poll_new, mark_read
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_conversations':
            // Get list of conversations with last message
            $search = $_GET['search'] ?? '';
            $limit = min(intval($_GET['limit'] ?? 50), 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT u.id, u.line_user_id, u.display_name, u.picture_url, u.phone,
                    (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message,
                    (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
                    (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time,
                    (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread_count
                    FROM users u 
                    WHERE u.line_account_id = ?";
            
            $params = [$currentBotId];
            
            if ($search) {
                $sql .= " AND (u.display_name LIKE ? OR u.phone LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $sql .= " ORDER BY last_time DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total unread
            $stmt = $db->prepare("SELECT COUNT(*) FROM messages m 
                                  JOIN users u ON m.user_id = u.id 
                                  WHERE u.line_account_id = ? AND m.direction = 'incoming' AND m.is_read = 0");
            $stmt->execute([$currentBotId]);
            $totalUnread = $stmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'conversations' => $conversations,
                'total_unread' => $totalUnread
            ]);
            break;
            
        case 'get_messages':
            // Get messages for a user
            $userId = intval($_GET['user_id'] ?? 0);
            $lastId = intval($_GET['last_id'] ?? 0);
            $limit = min(intval($_GET['limit'] ?? 50), 200);
            
            if (!$userId) {
                throw new Exception('user_id required');
            }
            
            $sql = "SELECT * FROM messages WHERE user_id = ?";
            $params = [$userId];
            
            if ($lastId > 0) {
                $sql .= " AND id > ?";
                $params[] = $lastId;
            }
            
            $sql .= " ORDER BY created_at ASC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages)
            ]);
            break;
            
        case 'poll':
            // Real-time polling - get new messages since last_id
            $userId = intval($_GET['user_id'] ?? 0);
            $lastId = intval($_GET['last_id'] ?? 0);
            
            if (!$userId) {
                throw new Exception('user_id required');
            }
            
            // Get new messages
            $stmt = $db->prepare("SELECT * FROM messages WHERE user_id = ? AND id > ? ORDER BY created_at ASC");
            $stmt->execute([$userId, $lastId]);
            $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get unread count for sidebar update
            $stmt = $db->prepare("SELECT u.id, 
                                  (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread
                                  FROM users u WHERE u.line_account_id = ? AND u.id != ?
                                  HAVING unread > 0");
            $stmt->execute([$currentBotId, $userId]);
            $unreadUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Check for new conversations (users who sent first message)
            $stmt = $db->prepare("SELECT u.id, u.display_name, u.picture_url,
                                  (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message,
                                  (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time
                                  FROM users u 
                                  WHERE u.line_account_id = ? 
                                  AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id AND id > ?)
                                  ORDER BY last_time DESC LIMIT 10");
            $stmt->execute([$currentBotId, $lastId]);
            $updatedConversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'messages' => $newMessages,
                'unread_users' => $unreadUsers,
                'updated_conversations' => $updatedConversations,
                'timestamp' => time()
            ]);
            break;
            
        case 'send':
            // Send message
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            
            $userId = intval($_POST['user_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $messageType = $_POST['type'] ?? 'text';
            
            if (!$userId || !$message) {
                throw new Exception('user_id and message required');
            }
            
            // Get user info
            $stmt = $db->prepare("SELECT line_user_id, line_account_id, reply_token, reply_token_expires FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Send via LINE
            $lineManager = new LineAccountManager($db);
            $line = $lineManager->getLineAPI($user['line_account_id']);
            
            if (method_exists($line, 'sendMessage')) {
                $result = $line->sendMessage(
                    $user['line_user_id'], 
                    $message, 
                    $user['reply_token'] ?? null, 
                    $user['reply_token_expires'] ?? null, 
                    $db, 
                    $userId
                );
            } else {
                $result = $line->pushMessage($user['line_user_id'], [['type' => 'text', 'text' => $message]]);
                $result['method'] = 'push';
            }
            
            if ($result['code'] !== 200) {
                throw new Exception('LINE API Error: ' . ($result['error'] ?? 'Unknown'));
            }
            
            // Save to database
            $adminName = $_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Admin';
            $sentBy = 'admin:' . $adminName;
            
            // Check if sent_by column exists
            $hasSentBy = false;
            try {
                $checkCol = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
                $hasSentBy = $checkCol->rowCount() > 0;
            } catch (Exception $e) {}
            
            if ($hasSentBy) {
                $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) 
                                      VALUES (?, ?, 'outgoing', ?, ?, ?, NOW(), 1)");
                $stmt->execute([$user['line_account_id'], $userId, $messageType, $message, $sentBy]);
            } else {
                $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, created_at, is_read) 
                                      VALUES (?, ?, 'outgoing', ?, ?, NOW(), 1)");
                $stmt->execute([$user['line_account_id'], $userId, $messageType, $message]);
            }
            
            $messageId = $db->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message_id' => $messageId,
                'content' => $message,
                'time' => date('H:i'),
                'method' => $result['method'] ?? 'push',
                'sent_by' => $sentBy
            ]);
            break;
            
        case 'mark_read':
            // Mark messages as read
            $userId = intval($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
            
            if (!$userId) {
                throw new Exception('user_id required');
            }
            
            $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND direction = 'incoming' AND is_read = 0");
            $stmt->execute([$userId]);
            $affected = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'marked' => $affected
            ]);
            break;
            
        case 'get_user':
            // Get user details
            $userId = intval($_GET['user_id'] ?? 0);
            
            if (!$userId) {
                throw new Exception('user_id required');
            }
            
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Get tags
            $tags = [];
            try {
                $stmt = $db->prepare("SELECT t.* FROM user_tags t 
                                      JOIN user_tag_assignments uta ON t.id = uta.tag_id 
                                      WHERE uta.user_id = ?");
                $stmt->execute([$userId]);
                $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            
            // Get notes
            $notes = [];
            try {
                $stmt = $db->prepare("SELECT * FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
                $stmt->execute([$userId]);
                $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            
            // Get orders
            $orders = [];
            try {
                $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                $stmt->execute([$userId]);
                $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            
            echo json_encode([
                'success' => true,
                'user' => $user,
                'tags' => $tags,
                'notes' => $notes,
                'orders' => $orders
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
