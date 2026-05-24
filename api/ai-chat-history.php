<?php
/**
 * REYA AI Chat — conversation history fetch
 *
 * GET /api/ai-chat-history.php?line_user_id=Uxxx&limit=20
 *
 * Returns the last N rows of `ai_conversation_history` for the given LINE
 * user so the Mini App can resume the conversation on mount (cross-device
 * continuity). Phase 1 of AI Chat Option D (2026-05-24).
 *
 * Security notes:
 *  - CORS is locked down to known callers (re-ya.com, liff.line.me). A
 *    wildcard ACAO would let any origin pull a user's chat history (PHI).
 *  - `line_user_id` must match LINE's real format (^U[0-9a-f]{32}$) — we
 *    reject anything else with 400 + audit log entry.
 *  - TODO(security): verify LIFF id_token from the Authorization header
 *    once a verifier helper lands in the codebase. Until then we trust the
 *    LINE user id format check + tenant scoping inside the helper.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$allowedOrigins = ['https://re-ya.com', 'https://liff.line.me'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
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

// LINE user IDs are always "U" + 32 lowercase hex chars. Anything else is
// either a typo or a probe attempt — reject with audit log.
if ($lineUserId === '' || !preg_match('/^U[0-9a-f]{32}$/i', $lineUserId)) {
    error_log(sprintf(
        'ai-chat-history.php: reject invalid line_user_id (len=%d, origin=%s, ip=%s)',
        strlen($lineUserId),
        $origin !== '' ? $origin : '-',
        $_SERVER['REMOTE_ADDR'] ?? '-'
    ));
    http_response_code(400);
    echo json_encode([
        'success'  => false,
        'error'    => 'Invalid line_user_id',
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
