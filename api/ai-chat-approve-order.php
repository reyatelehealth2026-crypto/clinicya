<?php
/**
 * REYA AI Chat — customer-side "ส่งให้เภสัชกร" approval request
 *
 * POST /api/ai-chat-approve-order.php
 * Body: { line_user_id, last_ai_message?, summary? }
 *
 * Flow:
 *  1. Resolve internal users.id from line_user_id
 *  2. Find latest active triage_session for the user (or last completed one in 1h)
 *  3. UPDATE triage_sessions SET status='pending_approval', chief_complaint=<summary>
 *     and append `drug_recommendation` to triage_data
 *  4. Pharmacist dashboard already lists `pending_approval` sessions (existing query
 *     uses status NULL/active/'' — we extend it in dashboard.php to include
 *     pending_approval). For now mark the row so dispense page can find it.
 *
 * Security:
 *  - CORS allowlist re-ya.com / liff.line.me
 *  - line_user_id regex check (^U[0-9a-f]{32}$)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$allowedOrigins = ['https://re-ya.com', 'https://liff.line.me'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

$lineUserId  = trim((string) ($body['line_user_id'] ?? ''));
$summary     = trim((string) ($body['summary'] ?? ''));
$lastMessage = trim((string) ($body['last_ai_message'] ?? ''));

if ($lineUserId === '' || !preg_match('/^U[0-9a-f]{32}$/i', $lineUserId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid line_user_id']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    // Resolve users.id
    $stmt = $db->prepare("SELECT id, line_account_id, display_name FROM users WHERE line_user_id = ? LIMIT 1");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    $userId = (int)$user['id'];
    $lineAccountId = $user['line_account_id'] ?? null;

    // Find the most-recent active or recently-completed session (≤6h)
    $stmt = $db->prepare("
        SELECT * FROM triage_sessions
        WHERE user_id = ?
          AND (status IS NULL OR status = '' OR status IN ('active','pending_approval','self_care','otc_recommended'))
          AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        // Create a fresh session so pharmacist has a row to dispense from
        $stmt = $db->prepare("
            INSERT INTO triage_sessions
                (line_account_id, user_id, current_state, triage_data, status, chief_complaint, created_at, updated_at)
            VALUES (?, ?, 'pending_approval', ?, 'pending_approval', ?, NOW(), NOW())
        ");
        $newData = json_encode([
            'drug_recommendation' => $lastMessage,
            'created_by' => 'ai-chat-approve-order',
        ], JSON_UNESCAPED_UNICODE);
        $stmt->execute([$lineAccountId, $userId, $newData, mb_substr($summary !== '' ? $summary : $lastMessage, 0, 1000)]);
        $sessionId = (int)$db->lastInsertId();
    } else {
        $sessionId = (int)$session['id'];
        // Merge drug recommendation into triage_data
        $existing = json_decode((string)($session['triage_data'] ?? '{}'), true) ?: [];
        $existing['drug_recommendation'] = $lastMessage;
        $existing['approved_by_customer_at'] = date('Y-m-d H:i:s');
        $upd = $db->prepare("
            UPDATE triage_sessions
            SET status='pending_approval',
                triage_data = ?,
                chief_complaint = COALESCE(NULLIF(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci, ''), chief_complaint),
                updated_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([
            json_encode($existing, JSON_UNESCAPED_UNICODE),
            $summary !== '' ? mb_substr($summary, 0, 1000) : '',
            $sessionId,
        ]);
    }

    // Also save the customer's approval as a user-role conversation row so
    // dispense page transcript shows "ลูกค้า: โอเค ส่งให้เภสัชกร"
    try {
        $ins = $db->prepare("
            INSERT INTO ai_conversation_history
                (user_id, line_account_id, role, content, session_id, created_at)
            VALUES (?, ?, 'user', '✅ ลูกค้ายืนยันสั่งยา — ส่งให้เภสัชกรอนุมัติ', ?, NOW())
        ");
        $ins->execute([$userId, $lineAccountId, (string)$sessionId]);
    } catch (\Throwable $e) {
        error_log('ai-chat-approve-order: history append failed: ' . $e->getMessage());
    }

    error_log("ai-chat-approve-order: user={$userId} session={$sessionId} → pending_approval");

    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'message' => 'ส่งให้เภสัชกรเรียบร้อย — กรุณารอเภสัชกรอนุมัติและติดต่อกลับ',
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('ai-chat-approve-order error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error']);
}
