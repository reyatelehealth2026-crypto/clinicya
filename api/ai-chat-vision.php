<?php
/**
 * AI Chat Vision endpoint — Gemini Vision image describer for Mini App chat.
 *
 * POST multipart/form-data:
 *   image           (file)   — image/jpeg | image/png | image/webp, max 5MB
 *   line_user_id    (string) — required; validated against users.line_user_id
 *   line_account_id (int)    — optional; used to scope ai_settings lookup
 *
 * Response: { success: bool, description?: string, image_url?: string, error?: string }
 *
 * Notes:
 * - No admin session required; we authenticate the caller by checking that
 *   line_user_id belongs to a real row in `users`.
 * - File stored at: public_html/uploads/ai-chat/{YYYY-MM}/{hash}_{ts}.{ext}
 * - Gemini model: gemini-2.5-flash (vision-capable, same family as ai-chat.php).
 *
 * Phase 4 security hardening (2026-05-24):
 *  - CORS allowlist (re-ya.com + liff.line.me) — no wildcard origin.
 *  - Rate limit: 20/hr per line_user_id, 200/hr per IP.
 *  - Strict LINE userId regex (U + 32 hex).
 *  - Image dimension cap (decompression bomb mitigation).
 *  - Exception messages no longer leaked to clients.
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
    header('Access-Control-Allow-Headers: Content-Type');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
// Route root-domain (Mini App / LIFF) request to the tenant DB by line_account_id (split-brain fix).
require_once __DIR__ . '/../bootstrap/route_by_account.php';
require_once __DIR__ . '/../includes/ai-rate-limit.php';

function vision_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function vision_ok(array $extra = []): void
{
    echo json_encode(['success' => true] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Return client IP — prefers REMOTE_ADDR (only safe value here, we are not
 * behind a trusted proxy that sets X-Forwarded-For).
 */
function vision_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
        return $ip;
    }
    return '0.0.0.0';
}

/**
 * Load Gemini API key in the same precedence order as api/ai-chat.php.
 * Returns first non-empty key found, or null.
 *
 * @param \PDO $db
 * @return string|null
 */
function vision_load_gemini_key(\PDO $db): ?string
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
            // Ignore missing tables / columns; fall through to next source.
        }
    }
    $envKey = defined('GEMINI_API_KEY') ? (string) GEMINI_API_KEY : '';
    if ($envKey === '') {
        $envKey = (string) (getenv('GEMINI_API_KEY') ?: '');
    }
    $envKey = trim($envKey);
    return $envKey === '' ? null : $envKey;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        vision_fail('POST only', 405);
    }

    $lineUserId = trim((string) ($_POST['line_user_id'] ?? ''));
    if ($lineUserId === '') {
        vision_fail('line_user_id required');
    }
    // LINE user IDs are always "U" + 32 hex chars. Reject anything else.
    if (!preg_match('/^U[0-9a-f]{32}$/i', $lineUserId)) {
        vision_fail('invalid line_user_id');
    }

    $lineAccountIdRaw = $_POST['line_account_id'] ?? null;
    $lineAccountId = ($lineAccountIdRaw !== null && $lineAccountIdRaw !== '')
        ? (int) $lineAccountIdRaw
        : null;

    $db = Database::getInstance()->getConnection();

    // Validate the caller — they must exist in `users`.
    $userStmt = $db->prepare('SELECT id FROM users WHERE line_user_id = ? LIMIT 1');
    $userStmt->execute([$lineUserId]);
    $userId = (int) ($userStmt->fetchColumn() ?: 0);
    if ($userId <= 0) {
        vision_fail('unknown user', 403);
    }

    // Rate limit BEFORE touching the uploaded file or calling Gemini.
    $ip = vision_client_ip();
    if (!checkAndIncrementRateLimit($db, 'vision', $lineUserId, 'user', 20)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error'   => 'rate_limit_exceeded',
            'message' => 'ส่งรูปบ่อยเกินไป ลองใหม่ใน 1 ชั่วโมง',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!checkAndIncrementRateLimit($db, 'vision', $ip, 'ip', 200)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error'   => 'rate_limit_exceeded',
            'message' => 'มีการใช้งานจากที่อยู่ของคุณเยอะเกินไป ลองใหม่ภายหลัง',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // File presence / upload error checks.
    if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        $msg = [
            UPLOAD_ERR_INI_SIZE   => 'ไฟล์ใหญ่เกินกว่า php.ini upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'ไฟล์ใหญ่เกินขีดจำกัด',
            UPLOAD_ERR_PARTIAL    => 'อัพโหลดไม่สมบูรณ์',
            UPLOAD_ERR_NO_FILE    => 'ไม่ได้แนบไฟล์',
            UPLOAD_ERR_NO_TMP_DIR => 'Server: missing tmp dir',
            UPLOAD_ERR_CANT_WRITE => 'Server: write failed',
        ][$err] ?? ('upload error ' . $err);
        vision_fail($msg);
    }
    $file = $_FILES['image'];

    // Size cap: 5 MB.
    $sizeMax = 5 * 1024 * 1024;
    if ((int) $file['size'] > $sizeMax) {
        vision_fail('ไฟล์ใหญ่เกิน 5MB');
    }

    // Mime detection via finfo (fall back to client-provided type).
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
    $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? '');
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = [
        'image/jpeg'  => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'   => 'png',
        'image/webp'  => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        vision_fail('รองรับเฉพาะ JPG / PNG / WebP (mime: ' . htmlspecialchars((string) $mime) . ')');
    }
    $ext = $allowed[$mime];

    // Persist to public_html/uploads/ai-chat/{YYYY-MM}/{hash}_{ts}.{ext}
    // __DIR__ = public_html/api → ../uploads/ai-chat/...
    $baseDir = realpath(__DIR__ . '/..');
    if ($baseDir === false) {
        vision_fail('Server: base path error', 500);
    }
    $monthSegment = date('Y-m');
    $relDir = '/uploads/ai-chat/' . $monthSegment;
    $absDir = $baseDir . $relDir;
    if (!is_dir($absDir)) {
        if (!@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            vision_fail('Server: cannot create upload dir', 500);
        }
    }
    if (!is_writable($absDir)) {
        vision_fail('Server: upload dir not writable', 500);
    }

    $filename = bin2hex(random_bytes(8)) . '_' . substr((string) time(), -6) . '.' . $ext;
    $absPath = $absDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        vision_fail('Server: move_uploaded_file failed', 500);
    }
    @chmod($absPath, 0644);
    $publicUrl = $relDir . '/' . $filename;

    // Decompression-bomb / oversized-pixel-grid guard.
    // 8000x8000 RGBA ≈ 256MB decoded — refuse anything larger.
    $dim = @getimagesize($absPath);
    if ($dim === false || !is_array($dim)
        || ($dim[0] ?? 0) <= 0 || ($dim[1] ?? 0) <= 0
        || ($dim[0] ?? 0) > 8000 || ($dim[1] ?? 0) > 8000
    ) {
        @unlink($absPath);
        vision_fail('Image dimensions too large or unreadable', 400);
    }

    // Gemini Vision call.
    $apiKey = vision_load_gemini_key($db);
    if ($apiKey === null) {
        vision_fail('Gemini API key not configured', 500);
    }

    $rawBytes = @file_get_contents($absPath);
    if ($rawBytes === false || $rawBytes === '') {
        vision_fail('Server: cannot read saved image', 500);
    }
    $base64Image = base64_encode($rawBytes);

    $prompt = 'อธิบายภาพนี้สั้นๆ ภาษาไทย หากเป็นยา ให้บอกชื่อยา/ยี่ห้อ/ปริมาณที่เห็น หากเป็นอาการ ให้บอกอาการที่เห็น';
    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data'      => $base64Image,
                    ],
                ],
            ],
        ]],
        'generationConfig' => [
            'temperature'     => 0.2,
            'maxOutputTokens' => 512,
        ],
    ];

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($apiKey);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('[ai-chat-vision] Gemini HTTP ' . $httpCode . ' — ' . substr((string) $response, 0, 300) . ' — ' . $curlErr);
        vision_fail('Gemini Vision unavailable (HTTP ' . $httpCode . ')', 502);
    }

    $decoded = json_decode((string) $response, true);
    $description = '';
    if (is_array($decoded) && !empty($decoded['candidates'][0]['content']['parts'])) {
        foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $description .= $part['text'];
            }
        }
    }
    $description = trim($description);
    if ($description === '') {
        $description = 'ไม่สามารถอ่านภาพนี้ได้ ลองถ่ายใหม่ในแสงที่ดีกว่าค่ะ';
    }

    vision_ok([
        'description'     => $description,
        'image_url'       => $publicUrl,
        'mime'            => $mime,
        'size'            => (int) $file['size'],
        'line_account_id' => $lineAccountId,
    ]);
} catch (Throwable $e) {
    error_log('[ai-chat-vision] ' . $e->getMessage());
    vision_fail('Server error', 500);
}
