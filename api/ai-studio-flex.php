<?php
/**
 * AI Studio Flex generation endpoint.
 *
 * Builds a LINE Flex Message (single bubble or N-bubble carousel) with Gemini.
 * Optional uploaded reference images are hosted under uploads/ai-studio/flex/
 * and used both as vision context and as the hero image of each bubble.
 *
 * POST application/json (admin session required):
 *   prompt           string  required (รายละเอียดที่ผู้ใช้กรอก)
 *   type             string  product|promo|menu|receipt|welcome|announce|booking|custom
 *   color            string  theme hex, e.g. "#06C755"
 *   bubble_count     int     1..10
 *   reference_images array   data URLs ("data:image/png;base64,...")
 *   api_key          string  optional — used only if the shop has no stored key
 *
 * Response: { success, flex:object, hero_urls:string[], error? }
 *
 * Key resolution: ai_settings.gemini_api_key (current tenant → any row) → api_key.
 *
 * @spec ai-studio-flex-upgrade
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
require_once __DIR__ . '/../classes/AiStudioFlex.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function studio_flex_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Resolve the Gemini key: shop's stored key (tenant → any row) → user-provided. */
function studio_flex_resolve_key(PDO $db, int $lineAccountId, array $input): string
{
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
        // fall through to user-provided key
    }
    if ($apiKey === '') {
        $apiKey = trim((string) ($input['api_key'] ?? ''));
    }
    return $apiKey;
}

if (empty($_SESSION['admin_user'])) {
    studio_flex_fail('กรุณาเข้าสู่ระบบ', 401);
}
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 0);
$adminUserId = (int) ($_SESSION['user_id'] ?? ($_SESSION['admin_user']['id'] ?? 0));

$db = Database::getInstance()->getConnection();

$input = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($input)) {
    studio_flex_fail('รูปแบบคำขอไม่ถูกต้อง');
}

// --- Copy mode: write ONLY the marketing wording for a product Flex (Hybrid). ---
// Prices/SKUs/images stay in the deterministic client builder; the model never
// sees or emits a number here.
if ((string) ($input['mode'] ?? '') === 'copy') {
    $names = [];
    foreach ((array) ($input['product_names'] ?? []) as $n) {
        $n = trim((string) $n);
        if ($n !== '') {
            $names[] = $n;
        }
        if (count($names) >= 30) {
            break;
        }
    }
    $hint = trim((string) ($input['hint'] ?? ''));
    if (!$names && $hint === '') {
        studio_flex_fail('ต้องมีรายชื่อสินค้าหรือบริบทอย่างน้อยหนึ่งอย่าง');
    }
    $apiKey = studio_flex_resolve_key($db, $lineAccountId, $input);
    if ($apiKey === '') {
        studio_flex_fail('ยังไม่ได้ตั้งค่า Google API key', 422);
    }
    $type = (string) ($input['type'] ?? 'product');
    $theme = (string) ($input['theme'] ?? 'promotion');
    $userPrompt = "รายชื่อสินค้า:\n" . ($names ? '- ' . implode("\n- ", $names) : '(ไม่ระบุ)')
        . ($hint !== '' ? "\n\nบริบท/ความต้องการเพิ่มเติม: " . $hint : '')
        . "\n\nเขียนคำโปรยการตลาดตามคีย์ที่กำหนด";
    $svc = new AiStudioFlex();
    $r = $svc->generateCopy($userPrompt, AiStudioFlex::buildCopySystemPrompt($type, $theme), $apiKey);
    if (!$r['ok']) {
        studio_flex_fail($r['error'] ?? 'เขียนคำโปรยไม่สำเร็จ', 200);
    }
    echo json_encode(['success' => true, 'copy' => $r['copy']], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Edit mode: refine an existing Flex from a natural-language instruction ---
if ((string) ($input['mode'] ?? '') === 'edit') {
    $instruction = trim((string) ($input['instruction'] ?? ''));
    $current = $input['flex'] ?? null;
    if ($instruction === '' || !is_array($current)) {
        studio_flex_fail('ต้องมีคำสั่งแก้ไขและ Flex ปัจจุบัน');
    }
    $apiKey = studio_flex_resolve_key($db, $lineAccountId, $input);
    if ($apiKey === '') {
        studio_flex_fail('ยังไม่ได้ตั้งค่า Google API key', 422);
    }
    $editPrompt = "Flex JSON ปัจจุบัน:\n" . json_encode($current, JSON_UNESCAPED_UNICODE)
        . "\n\nคำสั่งแก้ไข: " . $instruction
        . "\n\nคืน Flex JSON ฉบับแก้ไขทั้งก้อน";
    $svc = new AiStudioFlex();
    $r = $svc->generate($editPrompt, AiStudioFlex::buildEditSystemPrompt(), [], $apiKey);
    if (!$r['ok']) {
        studio_flex_fail($r['error'] ?? 'แก้ Flex ไม่สำเร็จ', 200);
    }
    echo json_encode(['success' => true, 'flex' => $r['flex']], JSON_UNESCAPED_UNICODE);
    exit;
}

$prompt = trim((string) ($input['prompt'] ?? ''));
if ($prompt === '') {
    studio_flex_fail('กรุณากรอกรายละเอียด');
}
$type = (string) ($input['type'] ?? 'custom');
$color = (string) ($input['color'] ?? '#06C755');
$bubbleCount = AiStudioFlex::clampBubbleCount($input['bubble_count'] ?? 1);

// --- Reference images: validate data URLs into {mime,data} ---
$refs = [];
foreach ((array) ($input['reference_images'] ?? []) as $dataUrl) {
    if (!is_string($dataUrl)) {
        continue;
    }
    if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $m)) {
        $refs[] = ['mime' => $m[1], 'data' => $m[2]];
    }
    if (count($refs) >= AiStudioFlex::MAX_REFS) {
        break;
    }
}

// --- Resolve API key: shop's stored key first, else the one the user typed ---
$apiKey = studio_flex_resolve_key($db, $lineAccountId, $input);
if ($apiKey === '') {
    studio_flex_fail('ยังไม่ได้ตั้งค่า Google API key', 422);
}

// --- Host reference images → public hero URLs ---
$heroUrls = [];
if ($refs) {
    $monthDir = date('Y-m');
    $uploadDir = __DIR__ . '/../uploads/ai-studio/flex/' . $monthDir;
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    // Build the absolute URL from the ACTUAL request host so the hero image is
    // same-origin as the admin page (re-ya.com root or a tenant subdomain).
    // BASE_URL may point at a different host (e.g. clinicya.re-ya.com) that does
    // not serve /uploads/, which would 404 the hero image inside LINE/preview.
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $baseUrl = $host !== '' ? $scheme . '://' . $host : (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '');
    foreach ($refs as $i => $ref) {
        $raw = base64_decode($ref['data'], true);
        if ($raw === false) {
            continue;
        }
        $ext = $ref['mime'] === 'image/jpeg' ? 'jpg' : ($ref['mime'] === 'image/webp' ? 'webp' : 'png');
        $fname = 'flexref_' . substr(md5($adminUserId . microtime(true) . $i . random_int(0, 99999)), 0, 16) . '.' . $ext;
        if (is_dir($uploadDir) && @file_put_contents($uploadDir . '/' . $fname, $raw) !== false) {
            $heroUrls[] = $baseUrl . '/uploads/ai-studio/flex/' . $monthDir . '/' . $fname;
        }
    }
}

// --- Generate ---
$system = AiStudioFlex::buildSystemPrompt($type, $color, $bubbleCount, $heroUrls);
$svc = new AiStudioFlex();
$r = $svc->generate($prompt, $system, $refs, $apiKey);
if (!$r['ok']) {
    studio_flex_fail($r['error'] ?? 'สร้าง Flex ไม่สำเร็จ', 200);
}

// --- Normalize to exact bubble count + inject the hosted hero images ---
$flex = AiStudioFlex::normalizeToBubbleCount($r['flex'], $bubbleCount);
$flex = AiStudioFlex::injectHeroImages($flex, $heroUrls);

echo json_encode([
    'success' => true,
    'flex' => $flex,
    'hero_urls' => $heroUrls,
], JSON_UNESCAPED_UNICODE);
