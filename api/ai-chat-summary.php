<?php
/**
 * AI Chat Auto-Summary endpoint — distil a triage conversation into a
 * one-line chief_complaint for pharmacists to scan in the inbox.
 *
 * POST application/json:
 *   { "session_id": 123 }
 *
 * Response: { success: bool, summary?: string, error?: string }
 *
 * Behaviour:
 * - Idempotent. Safe to call multiple times — UPDATE writes the latest summary.
 * - Reads all ai_conversation_history rows for the session, ordered by created_at.
 * - Calls Gemini with a tight Thai prompt and updates triage_sessions.chief_complaint.
 * - Never blocks the caller for long — 20s curl timeout.
 *
 * Triggered from:
 *  - modules/AIChat/Services/TriageRouter::fireAndForgetSummary() (async, on session end)
 *  - cron/ai_session_summarizer.php (sweeper for missed sessions)
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

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

/**
 * Load Gemini API key — same precedence as api/ai-chat.php.
 */
function summary_load_gemini_key(\PDO $db): ?string
{
    $queries = [
        "SELECT gemini_api_key AS k FROM ai_settings WHERE gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) <> '' LIMIT 1",
        "SELECT gemini_api_key AS k FROM ai_chat_settings WHERE gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) <> '' LIMIT 1",
        "SELECT setting_value AS k FROM settings WHERE setting_key IN ('gemini_api_key','GEMINI_API_KEY') AND setting_value IS NOT NULL AND TRIM(setting_value) <> '' LIMIT 1",
    ];
    foreach ($queries as $sql) {
        try {
            $stmt = $db->query($sql);
            if ($stmt !== false) {
                $val = $stmt->fetchColumn();
                if (is_string($val) && trim($val) !== '') {
                    return trim($val);
                }
            }
        } catch (\Throwable $e) {
            // Ignore missing tables / columns.
        }
    }
    $envKey = defined('GEMINI_API_KEY') ? (string) GEMINI_API_KEY : '';
    if ($envKey === '') {
        $envKey = (string) (getenv('GEMINI_API_KEY') ?: '');
    }
    $envKey = trim($envKey);
    return $envKey === '' ? null : $envKey;
}

/**
 * Generate a 1-2 sentence Thai chief-complaint summary for `$sessionId` and
 * persist it to triage_sessions.chief_complaint. Returns the summary string
 * (or null if there is nothing to summarise / Gemini was unreachable).
 *
 * This helper is also used by cron/ai_session_summarizer.php — keep it pure
 * (no `exit`, no `header`) and safe to call repeatedly.
 */
function summary_run_for_session(\PDO $db, int $sessionId): ?string
{
    if ($sessionId <= 0) {
        return null;
    }

    // Confirm session exists.
    $sessStmt = $db->prepare('SELECT id FROM triage_sessions WHERE id = ? LIMIT 1');
    $sessStmt->execute([$sessionId]);
    if (!$sessStmt->fetchColumn()) {
        return null;
    }

    // Pull conversation. Phase 1 writes session_id as the triage_sessions.id
    // (string-cast in the VARCHAR column).
    $histStmt = $db->prepare(
        'SELECT role, content FROM ai_conversation_history
         WHERE session_id = ?
         ORDER BY created_at ASC, id ASC
         LIMIT 200'
    );
    $histStmt->execute([(string) $sessionId]);
    $rows = $histStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (empty($rows)) {
        return null;
    }

    // Assemble transcript (truncate each turn to keep tokens reasonable).
    $transcript = '';
    foreach ($rows as $row) {
        $role = ($row['role'] ?? '') === 'assistant' ? 'AI' : 'ลูกค้า';
        $content = trim((string) ($row['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        if (mb_strlen($content) > 600) {
            $content = mb_substr($content, 0, 600) . '…';
        }
        $transcript .= $role . ': ' . $content . "\n";
    }
    if (trim($transcript) === '') {
        return null;
    }

    $apiKey = summary_load_gemini_key($db);
    if ($apiKey === null) {
        error_log('[ai-chat-summary] no Gemini key configured');
        return null;
    }

    $prompt = "สรุปการสนทนาเป็น 1-2 ประโยคภาษาไทยสำหรับเภสัชกรอ่านในแดชบอร์ด"
        . "ให้ครอบคลุม: อาการหลัก + duration + severity + แพ้ยา (ถ้ามี)."
        . "ตอบเป็นข้อความล้วน ไม่มี markdown ไม่มี bullet\n\n"
        . "บทสนทนา:\n" . $transcript;

    $payload = [
        'contents' => [[
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'temperature'     => 0.2,
            'maxOutputTokens' => 256,
        ],
    ];

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('[ai-chat-summary] Gemini HTTP ' . $httpCode . ' — ' . substr((string) $response, 0, 200));
        return null;
    }

    $decoded = json_decode((string) $response, true);
    $summary = '';
    if (is_array($decoded) && !empty($decoded['candidates'][0]['content']['parts'])) {
        foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $summary .= $part['text'];
            }
        }
    }
    $summary = trim(preg_replace('/\s+/u', ' ', $summary) ?? '');
    if ($summary === '') {
        return null;
    }
    if (mb_strlen($summary) > 500) {
        $summary = mb_substr($summary, 0, 500);
    }

    try {
        $upd = $db->prepare('UPDATE triage_sessions SET chief_complaint = ? WHERE id = ?');
        $upd->execute([$summary, $sessionId]);
    } catch (\Throwable $e) {
        error_log('[ai-chat-summary] UPDATE failed: ' . $e->getMessage());
        return null;
    }

    return $summary;
}

// HTTP entry point — only when invoked via web request (skip when included by cron).
if (PHP_SAPI !== 'cli' && !defined('AI_CHAT_SUMMARY_HELPER_ONLY')) {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            summary_fail('POST only', 405);
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
        summary_fail('Server error: ' . $e->getMessage(), 500);
    }
}
