<?php
/**
 * REYA AI Chat — conversation history fetch
 *
 * GET /api/ai-chat-history.php?line_user_id=Uxxx&limit=20
 *
 * Returns the last N rows of `ai_conversation_history` for the given LINE
 * user so the Mini App can resume the conversation on mount (cross-device
 * continuity). Phase 1 of AI Chat Option D (2026-05-24).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai-chat-context.php';

$lineUserId = isset($_GET['line_user_id']) ? trim((string) $_GET['line_user_id']) : '';
$limitParam = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
$limit      = max(1, min(100, $limitParam));

if ($lineUserId === '') {
    http_response_code(400);
    echo json_encode([
        'success'  => false,
        'error'    => 'Missing line_user_id',
        'messages' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $messages = aiChatGetConversationHistory($db, $lineUserId, $limit);

    echo json_encode([
        'success'  => true,
        'messages' => $messages,
        'count'    => count($messages),
        'limit'    => $limit,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('ai-chat-history.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'  => false,
        'error'    => 'Internal error',
        'messages' => [],
    ], JSON_UNESCAPED_UNICODE);
}
