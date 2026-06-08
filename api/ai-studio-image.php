<?php
/**
 * AI Studio image generation endpoint (Nano Banana via Gemini generateContent).
 *
 * POST application/json (admin session required):
 *   model            string  one of AiStudioImage::MODELS (resolved/defaulted)
 *   prompt           string  required
 *   mode             string  generate|product_scene|edit|poster (label only)
 *   aspect_ratio     string  e.g. "1:1","4:5","16:9"
 *   image_size       string  "1K"|"2K"|"4K"
 *   variations       int     1..4
 *   reference_images array   data URLs ("data:image/jpeg;base64,...")
 *   api_key          string  optional — used only if the shop has no stored key
 *
 * Response: { success, images:[{data_url,url,id}], error?, model }
 *
 * Key resolution: ai_settings.gemini_api_key (current tenant → any row) → api_key.
 * Files: uploads/ai-studio/{YYYY-MM}/{hash}.png · history: ai_studio_creations.
 *
 * @spec ai-studio-image-upgrade
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
require_once __DIR__ . '/../classes/AiStudioImage.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function studio_img_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Admin session required (studio is an admin page) ---
if (empty($_SESSION['admin_user'])) {
    studio_img_fail('กรุณาเข้าสู่ระบบ', 401);
}
$lineAccountId = (int) ($_SESSION['current_bot_id'] ?? 0);
$adminUserId = (int) ($_SESSION['user_id'] ?? ($_SESSION['admin_user']['id'] ?? 0));

$db = Database::getInstance()->getConnection();

// --- Parse JSON body ---
$input = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($input)) {
    studio_img_fail('รูปแบบคำขอไม่ถูกต้อง');
}
$prompt = trim((string) ($input['prompt'] ?? ''));
if ($prompt === '') {
    studio_img_fail('กรุณากรอก prompt');
}
$model = AiStudioImage::resolveModel($input['model'] ?? null);
$mode = (string) ($input['mode'] ?? 'generate');
$aspectRatio = (string) ($input['aspect_ratio'] ?? '1:1');
$imageSize = (string) ($input['image_size'] ?? '2K');
$variations = max(1, min(4, (int) ($input['variations'] ?? 1)));

// --- Reference images (data URLs → {mime,data}) ---
$refs = [];
foreach ((array) ($input['reference_images'] ?? []) as $dataUrl) {
    if (!is_string($dataUrl)) {
        continue;
    }
    if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $dataUrl, $m)) {
        $refs[] = ['mime' => $m[1], 'data' => $m[2]];
    }
    if (count($refs) >= 10) {
        break;
    }
}

// --- Resolve API key: shop's stored key first, else the one the user typed ---
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
    // ai_settings may not exist for this tenant — fall through to user key
}
if ($apiKey === '') {
    $apiKey = trim((string) ($input['api_key'] ?? ''));
}
if ($apiKey === '') {
    studio_img_fail('ยังไม่ได้ตั้งค่า Google API key (ตั้งค่าในระบบ หรือกรอกในช่อง API key)', 422);
}

// --- History table (auto-create per tenant) ---
$historyOk = false;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ai_studio_creations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT DEFAULT NULL,
        user_id INT DEFAULT NULL,
        model VARCHAR(50) DEFAULT NULL,
        mode VARCHAR(30) DEFAULT NULL,
        prompt TEXT,
        aspect_ratio VARCHAR(10) DEFAULT NULL,
        image_size VARCHAR(10) DEFAULT NULL,
        image_url VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_acct (line_account_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $historyOk = true;
} catch (\Throwable $e) {
    error_log('ai_studio_creations create error: ' . $e->getMessage());
}

// --- Upload dir ---
$monthDir = date('Y-m');
$uploadDir = __DIR__ . '/../uploads/ai-studio/' . $monthDir;
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}
$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

// --- Generate ---
$svc = new AiStudioImage();
$results = [];
$lastError = null;
$opts = ['aspectRatio' => $aspectRatio, 'imageSize' => $imageSize];

for ($i = 0; $i < $variations; $i++) {
    $r = $svc->generate($model, $prompt, $refs, $opts, $apiKey);
    if (!$r['ok']) {
        $lastError = $r['error'];
        break; // same prompt would fail again — stop early
    }
    foreach ($r['images'] as $img) {
        $raw = base64_decode($img['data'], true);
        if ($raw === false) {
            continue;
        }
        $fname = 'aistudio_' . substr(md5($adminUserId . microtime(true) . $i . random_int(0, 99999)), 0, 16) . '.png';
        $savedUrl = null;
        if (is_dir($uploadDir) && @file_put_contents($uploadDir . '/' . $fname, $raw) !== false) {
            $savedUrl = $baseUrl . '/uploads/ai-studio/' . $monthDir . '/' . $fname;
        }
        $id = null;
        if ($historyOk) {
            try {
                $ins = $db->prepare("INSERT INTO ai_studio_creations (line_account_id, user_id, model, mode, prompt, aspect_ratio, image_size, image_url) VALUES (?,?,?,?,?,?,?,?)");
                $ins->execute([$lineAccountId ?: null, $adminUserId ?: null, $model, $mode, $prompt, $aspectRatio, $imageSize, $savedUrl]);
                $id = (int) $db->lastInsertId();
            } catch (\Throwable $e) {
                error_log('ai_studio_creations insert error: ' . $e->getMessage());
            }
        }
        $results[] = [
            'id' => $id,
            'url' => $savedUrl,
            'data_url' => 'data:' . $img['mime'] . ';base64,' . $img['data'],
        ];
    }
}

if (!$results) {
    studio_img_fail($lastError ?? 'สร้างรูปไม่สำเร็จ', 200);
}

echo json_encode([
    'success' => true,
    'model' => $model,
    'images' => $results,
], JSON_UNESCAPED_UNICODE);
