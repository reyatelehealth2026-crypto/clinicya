<?php
/**
 * AI Chat Auto-Summary endpoint — distil a triage conversation into a
 * one-line chief_complaint for pharmacists to scan in the inbox.
 *
 * POST application/json:
 *   { "session_id": 123 }
 *
 * Headers (required):
 *   X-Internal-Token: <INTERNAL_API_TOKEN>
 *
 * Response: { success: bool, summary?: string, error?: string }
 *
 * Behaviour:
 * - Idempotent. Safe to call multiple times — UPDATE writes the latest summary.
 * - Reads all ai_conversation_history rows for the session, ordered by created_at.
 * - Calls Gemini with a tight Thai prompt (with prompt-injection sandboxing)
 *   and updates triage_sessions.chief_complaint.
 *
 * Phase 4 security hardening (2026-05-24):
 *  - CORS allowlist (re-ya.com + liff.line.me) — no wildcard origin.
 *  - Requires X-Internal-Token header matching INTERNAL_API_TOKEN.
 *  - All summary logic lives in includes/ai-chat-summary-helper.php
 *    so TriageRouter + cron can call it directly without HTTP.
 *  - Exception messages no longer leaked to clients.
 *
 * Triggered from:
 *  - modules/AIChat/Services/TriageRouter::fireAndForgetSummary() (in-process,
 *    via fastcgi_finish_request shutdown handler — NO HTTP)
 *  - cron/ai_session_summarizer.php (sweeper for missed sessions, in-process)
 *  - HTTP POST with valid X-Internal-Token (debug / manual trigger)
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// --- CORS allowlist (no wildcard) ---------------------------------------------
$allowedOrigins = ['https://re-ya.com', 'https://liff.line.me'];
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Internal-Token');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai-chat-summary-helper.php';

function summary_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function summary_ok(array $extra = []): void
{
    echo json_encode(['success' => true] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

// HTTP entry point — internal-token gated.
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        summary_fail('POST only', 405);
    }

    // Internal-token guard — constant-time compare.
    $providedToken = (string) ($_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '');
    $expectedToken = defined('INTERNAL_API_TOKEN') ? (string) INTERNAL_API_TOKEN : '';
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents('php://input');
    $input = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($input)) {
        $input = $_POST;
    }
    $sessionId = (int) ($input['session_id'] ?? 0);
    if ($sessionId <= 0) {
        summary_fail('session_id required');
    }

    $db = Database::getInstance()->getConnection();
    $summary = summary_run_for_session($db, $sessionId);
    if ($summary === null) {
        summary_fail('ไม่สามารถสรุปการสนทนาได้', 422);
    }
    summary_ok(['summary' => $summary]);
} catch (Throwable $e) {
    error_log('[ai-chat-summary] ' . $e->getMessage());
    summary_fail('Server error', 500);
}
