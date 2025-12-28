<?php
/**
 * Test AI Conversation History
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$input = json_decode(file_get_contents('php://input'), true);
$lineUserId = $input['user_id'] ?? $_GET['user_id'] ?? null;

$result = [
    'line_user_id' => $lineUserId,
    'table_exists' => false,
    'user_found' => false,
    'internal_user_id' => null,
    'history_count' => 0,
    'recent_messages' => []
];

// Check if table exists
try {
    $db->query("SELECT 1 FROM ai_conversation_history LIMIT 1");
    $result['table_exists'] = true;
} catch (Exception $e) {
    $result['table_error'] = $e->getMessage();
}

// Get user
if ($lineUserId) {
    try {
        $stmt = $db->prepare("SELECT id, display_name, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $result['user_found'] = true;
            $result['internal_user_id'] = $user['id'];
            $result['display_name'] = $user['display_name'];
            $result['line_account_id'] = $user['line_account_id'];
            
            // Get history count
            if ($result['table_exists']) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM ai_conversation_history WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $result['history_count'] = (int)$stmt->fetchColumn();
                
                // Get recent messages
                $stmt = $db->prepare("SELECT role, content, created_at FROM ai_conversation_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
                $stmt->execute([$user['id']]);
                $result['recent_messages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Exception $e) {
        $result['user_error'] = $e->getMessage();
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
