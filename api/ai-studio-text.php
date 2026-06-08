<?php
/**
 * AI Studio text endpoint — shared Gemini text/vision call for the studio's
 * Flex, Caption and Chat tabs. Uses the shop's stored key (ai_settings) so
 * staff never paste an API key.
 *
 * POST application/json (admin session):
 *   prompt   string  required (user content)
 *   system   string  optional system instruction
 *   image    string  optional data URL (vision — caption/chat)
 *   json     bool    optional — ask the model for JSON output (Flex)
 *   model    string  optional — defaults to gemini-flash-latest
 *   api_key  string  optional — fallback if the shop has no stored key
 *
 * Response: { success, text, error? }
 *
 * @spec ai-studio-image-upgrade (text companion)
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$allowedOrigins = ['https://re-ya.com'];
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function studio_text_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['admin_user'])) {
    studio_text_fail('กรุณาเข้าสู่ระบบ', 401);
}
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 0);

$db = Database::getInstance()->getConnection();

$input = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($input)) {
    studio_text_fail('รูปแบบคำขอไม่ถูกต้อง');
}
$prompt = trim((string) ($input['prompt'] ?? ''));
if ($prompt === '') {
    studio_text_fail('กรุณากรอกข้อความ');
}
$system = trim((string) ($input['system'] ?? ''));
$wantJson = !empty($input['json']);
$model = trim((string) ($input['model'] ?? '')) ?: 'gemini-flash-latest';
// Whitelist model prefix to avoid abuse.
if (!preg_match('/^gemini-[a-z0-9.\-]+$/i', $model)) {
    $model = 'gemini-flash-latest';
}

// --- Resolve API key: shop's stored key → user-provided fallback ---
$apiKey = '';
try {
    if ($lineAccountId > 0) {
        $st = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ? AND gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) <> '' LIMIT 1");
        $st->execute([$lineAccountId]);
        $apiKey = (string) ($st->fetchColumn() ?: '');
    }
    if ($apiKey === '') {
        $st = $db->query("SELECT gemini_api_key FROM ai_settings WHERE gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) <> '' LIMIT 1");
        $apiKey = (string) ($st->fetchColumn() ?: '');
    }
} catch (\Throwable $e) {
    // fall through
}
if ($apiKey === '') {
    $apiKey = trim((string) ($input['api_key'] ?? ''));
}
if ($apiKey === '') {
    studio_text_fail('ยังไม่ได้ตั้งค่า Google API key', 422);
}

// --- Build request ---
$parts = [['text' => $prompt]];
if (!empty($input['image']) && is_string($input['image'])
    && preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $input['image'], $m)) {
    $parts[] = ['inline_data' => ['mime_type' => $m[1], 'data' => $m[2]]];
}

$payload = ['contents' => [['parts' => $parts]]];
if ($system !== '') {
    $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
}
$genCfg = ['temperature' => 0.9];
if ($wantJson) {
    $genCfg['responseMimeType'] = 'application/json';
}
$payload['generationConfig'] = $genCfg;

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
    . ':generateContent?key=' . urlencode($apiKey);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
]);
$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($body === false) {
    studio_text_fail('เชื่อมต่อ Google ไม่ได้: ' . $curlErr, 200);
}
$json = json_decode($body, true);
if ($status !== 200) {
    $msg = is_array($json) ? ($json['error']['message'] ?? '') : '';
    studio_text_fail('Google HTTP ' . $status . ($msg !== '' ? ': ' . $msg : ''), 200);
}

$text = '';
foreach (($json['candidates'][0]['content']['parts'] ?? []) as $p) {
    if (isset($p['text'])) {
        $text .= $p['text'];
    }
}
$text = trim($text);
if ($text === '') {
    $block = $json['promptFeedback']['blockReason'] ?? null;
    studio_text_fail($block ? ('ถูกบล็อก: ' . $block) : 'ไม่ได้ผลลัพธ์จากโมเดล', 200);
}

echo json_encode(['success' => true, 'text' => $text], JSON_UNESCAPED_UNICODE);
