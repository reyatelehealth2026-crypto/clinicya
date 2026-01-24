<?php
/**
 * Inbox Real-time API
 * 
 * Provides endpoints for real-time message updates:
 * - Check for new messages (polling)
 * - Get updated conversation list
 * - Get new messages for specific user
 * 
 * Usage: Poll this endpoint every 3-5 seconds for real-time updates
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? $_GET['line_account_id'] ?? 1;
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Debug log
error_log("[inbox-realtime] Session bot_id: " . ($_SESSION['current_bot_id'] ?? 'NOT SET') . ", GET line_account_id: " . ($_GET['line_account_id'] ?? 'NOT SET') . ", Using: $lineAccountId");

/**
 * Send JSON response
 */
function sendJson($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {

        /**
         * Check for new messages since last check
         * Returns: hasNew, newCount, conversations (updated list)
         */
        case 'check_new':
        case 'check_updates': // Alias for backward compatibility
            $lastCheck = $_GET['last_check'] ?? date('Y-m-d H:i:s', strtotime('-30 seconds'));
            $currentUserId = (int) ($_GET['current_user'] ?? 0);

            // Get count of UNREAD incoming messages (not just new since last check)
            // This ensures count is accurate after mark_all_read
            $stmt = $db->prepare("
                SELECT COUNT(*) as new_count 
                FROM messages m
                JOIN users u ON m.user_id = u.id
                WHERE u.line_account_id = ?
                AND m.line_account_id = ?
                AND m.direction = 'incoming'
                AND m.is_read = 0
            ");
            $stmt->execute([$lineAccountId, $lineAccountId]);
            $newCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['new_count'];

            // Get updated conversation list (sorted by latest message)
            // Use subqueries to get the actual latest message data
            // IMPORTANT: Filter by line_account_id in subqueries to get correct messages
            $stmt = $db->prepare("
                SELECT 
                    u.id,
                    u.display_name,
                    u.picture_url,
                    u.line_user_id,
                    (SELECT content FROM messages WHERE user_id = u.id AND line_account_id = ? ORDER BY created_at DESC LIMIT 1) as last_message,
                    (SELECT message_type FROM messages WHERE user_id = u.id AND line_account_id = ? ORDER BY created_at DESC LIMIT 1) as last_type,
                    (SELECT created_at FROM messages WHERE user_id = u.id AND line_account_id = ? ORDER BY created_at DESC LIMIT 1) as last_time,
                    (SELECT direction FROM messages WHERE user_id = u.id AND line_account_id = ? ORDER BY created_at DESC LIMIT 1) as last_direction,
                    (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND line_account_id = ? AND direction = 'incoming' AND is_read = 0) as unread_count
                FROM users u
                WHERE u.line_account_id = ?
                AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id AND line_account_id = ?)
                ORDER BY last_time DESC
                LIMIT 50
            ");
            $stmt->execute([$lineAccountId, $lineAccountId, $lineAccountId, $lineAccountId, $lineAccountId, $lineAccountId, $lineAccountId]);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format conversations
            $formattedConversations = [];
            foreach ($conversations as $conv) {
                $lastMsg = $conv['last_message'];
                if ($conv['last_type'] === 'image') {
                    $lastMsg = '📷 รูปภาพ';
                } elseif ($conv['last_type'] === 'sticker') {
                    $lastMsg = '😊 สติกเกอร์';
                } elseif ($conv['last_type'] === 'file') {
                    $lastMsg = '📎 ไฟล์';
                } elseif ($conv['last_type'] === 'location') {
                    $lastMsg = '📍 ตำแหน่ง';
                } elseif (strlen($lastMsg) > 50) {
                    $lastMsg = mb_substr($lastMsg, 0, 50) . '...';
                }

                $formattedConversations[] = [
                    'id' => (int) $conv['id'],
                    'display_name' => $conv['display_name'] ?: 'ไม่ระบุชื่อ',
                    'picture_url' => $conv['picture_url'] ?: '',
                    'last_message' => $lastMsg,
                    'last_type' => $conv['last_type'],
                    'last_time' => $conv['last_time'],
                    'last_time_formatted' => formatTimeAgo($conv['last_time']),
                    'last_direction' => $conv['last_direction'],
                    'unread_count' => (int) $conv['unread_count'],
                    'is_current' => ($conv['id'] == $currentUserId)
                ];
            }

            // Check if current user has new messages
            $hasNewForCurrent = false;
            if ($currentUserId > 0) {
                $stmt = $db->prepare("
                    SELECT COUNT(*) as cnt 
                    FROM messages 
                    WHERE user_id = ? 
                    AND created_at > ?
                ");
                $stmt->execute([$currentUserId, $lastCheck]);
                $hasNewForCurrent = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
            }

            sendJson([
                'success' => true,
                'has_new' => $newCount > 0,
                'new_count' => $newCount,
                'has_new_for_current' => $hasNewForCurrent,
                'conversations' => $formattedConversations,
                'server_time' => date('Y-m-d H:i:s'),
                'debug_line_account_id' => $lineAccountId
            ]);
            break;

        /**
         * Get new messages for specific user since timestamp
         */
        case 'get_new_messages':
            $userId = (int) ($_GET['user_id'] ?? 0);
            $since = $_GET['since'] ?? date('Y-m-d H:i:s', strtotime('-30 seconds'));

            if (!$userId) {
                sendJson(['success' => false, 'error' => 'User ID required'], 400);
            }

            // Mark messages as read
            $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND direction = 'incoming'")->execute([$userId]);

            // Get new messages
            $stmt = $db->prepare("
                SELECT 
                    id,
                    direction,
                    message_type,
                    content,
                    sent_by,
                    created_at,
                    DATE_FORMAT(created_at, '%H:%i') as time_formatted
                FROM messages 
                WHERE user_id = ? 
                AND created_at > ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$userId, $since]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format messages
            $formattedMessages = [];
            foreach ($messages as $msg) {
                $formattedMessages[] = [
                    'id' => (int) $msg['id'],
                    'direction' => $msg['direction'],
                    'type' => $msg['message_type'],
                    'content' => $msg['content'],
                    'sent_by' => $msg['sent_by'],
                    'time' => $msg['time_formatted'],
                    'created_at' => $msg['created_at']
                ];
            }

            sendJson([
                'success' => true,
                'messages' => $formattedMessages,
                'count' => count($formattedMessages),
                'server_time' => date('Y-m-d H:i:s')
            ]);
            break;

        /**
         * Get total unread count for badge
         */
        case 'unread_count':
            $stmt = $db->prepare("
                SELECT COUNT(*) as total
                FROM messages m
                JOIN users u ON m.user_id = u.id
                WHERE u.line_account_id = ?
                AND m.line_account_id = ?
                AND m.direction = 'incoming'
                AND m.is_read = 0
            ");
            $stmt->execute([$lineAccountId, $lineAccountId]);
            $total = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            sendJson([
                'success' => true,
                'unread_count' => $total
            ]);
            break;

        default:
            sendJson(['success' => false, 'error' => 'Invalid action'], 400);
    }

} catch (PDOException $e) {
    sendJson(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    sendJson(['success' => false, 'error' => $e->getMessage()], 500);
}

/**
 * Format time ago in Thai
 */
function formatTimeAgo($datetime)
{
    $now = new DateTime();
    $time = new DateTime($datetime);
    $diff = $now->diff($time);

    if ($diff->days > 0) {
        if ($diff->days == 1)
            return 'เมื่อวาน';
        if ($diff->days < 7)
            return $diff->days . ' วันที่แล้ว';
        return $time->format('d/m/Y');
    }

    if ($diff->h > 0) {
        return $diff->h . ' ชม.ที่แล้ว';
    }

    if ($diff->i > 0) {
        return $diff->i . ' นาทีที่แล้ว';
    }

    return 'เมื่อสักครู่';
}
