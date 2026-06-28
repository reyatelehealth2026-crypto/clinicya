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
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
// Route root-domain (Mini App / LIFF) request to the tenant DB by line_account_id (split-brain fix).
require_once __DIR__ . '/../bootstrap/route_by_account.php';
require_once __DIR__ . '/../includes/ai-chat-context.php';
require_once __DIR__ . '/../includes/liff-auth.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isDelete = ($method === 'DELETE') || ($method === 'POST' && (($_GET['action'] ?? '') === 'clear'));

// Body params accepted for DELETE/POST clear
$body = [];
if ($method === 'POST' || $method === 'DELETE') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $body = $tmp;
    }
}

$lineUserId = isset($_GET['line_user_id']) ? trim((string) $_GET['line_user_id'])
            : trim((string) ($body['line_user_id'] ?? ''));
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

// Fail-closed: the caller must present a LIFF access token that resolves to the
// same LINE userId it is asking us to read/clear. Closes the PHI-leak + mass
// deletion IDOR (line_user_id alone is not a secret).
reya_require_liff_user($lineUserId);

try {
    $db = Database::getInstance()->getConnection();

    if ($isDelete) {
        // Resolve internal users.id from line_user_id
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $internalUserId = $row ? (int)$row['id'] : 0;

        $deleted = 0;
        if ($internalUserId > 0) {
            $del = $db->prepare("DELETE FROM ai_conversation_history WHERE user_id = ?");
            $del->execute([$internalUserId]);
            $deleted = $del->rowCount();
            // Also mark any active triage_sessions as cleared (don't hard-delete — keep audit trail for pharmacist)
            try {
                $upd = $db->prepare("UPDATE triage_sessions SET status='cancelled', updated_at=NOW() WHERE user_id = ? AND (status IS NULL OR status='active' OR status='')");
                $upd->execute([$internalUserId]);
            } catch (\Throwable $e2) { error_log('triage cancel on clear: ' . $e2->getMessage()); }
        }
        error_log("ai-chat-history clear: line_user={$lineUserId} user_id={$internalUserId} rows_deleted={$deleted}");
        echo json_encode([
            'success' => true,
            'deleted' => $deleted,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
