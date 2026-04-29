<?php
/**
 * REYA AI Chat API — context-aware chat using Google Gemini
 */
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$userMessage = trim((string) ($input['message'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if (!$userMessage && empty($_SERVER['argv'])) { echo "data: " . json_encode(['error' => 'No message']) . "\n\n"; flush(); exit; }
if (empty($userMessage)) $userMessage = "test"; // for CLI testing

$db = Database::getInstance()->getConnection();

$geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : (getenv('GEMINI_API_KEY') ?: '');
// Fallback: ใช้คีย์จาก ai_settings เหมือนหน้า /ai-settings เมื่อยังไม่ได้ใส่ใน config / env
if (!$geminiKey) {
    try {
        $stmt = $db->query(
            "SELECT gemini_api_key FROM ai_settings WHERE gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) != '' ORDER BY line_account_id IS NULL DESC LIMIT 1"
        );
        $geminiKey = $stmt ? (string) $stmt->fetchColumn() : '';
    } catch (Throwable $e) {
        $geminiKey = '';
    }
}
if (!$geminiKey) { echo "data: " . json_encode(['error' => 'GEMINI_API_KEY not configured']) . "\n\n"; flush(); exit; }

// --- FAST CONTEXT (queries must not fatal — missing tables / SQL errors → defaults) ---
$oy = ['total' => 0, 'amount' => 0, 'customers' => 0];
$ot = ['total' => 0, 'amount' => 0];
$bdoY = ['total' => 0, 'amount' => 0, 'done' => 0];
$admins = [];
try {
    $stmt = $db->query("SELECT COUNT(*) as total, COALESCE(SUM(amount_total),0) as amount, COUNT(DISTINCT partner_id) as customers FROM odoo_orders WHERE DATE(date_order) = DATE_SUB(CURDATE(),INTERVAL 1 DAY) AND state NOT IN ('cancel')");
    $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
    if ($row) {
        $oy = array_merge($oy, $row);
    }
} catch (\Throwable $e) {
}
try {
    $stmt = $db->query("SELECT COUNT(*) as total, COALESCE(SUM(amount_total),0) as amount FROM odoo_orders WHERE DATE(date_order) = CURDATE() AND state NOT IN ('cancel')");
    $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
    if ($row) {
        $ot = array_merge($ot, $row);
    }
} catch (\Throwable $e) {
}
try {
    $stmt = $db->query("SELECT COUNT(*) as total, COALESCE(SUM(amount_total),0) as amount, SUM(CASE WHEN state='done' THEN 1 ELSE 0 END) as done FROM odoo_bdos WHERE DATE(created_at)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
    $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
    if ($row) {
        $bdoY = array_merge($bdoY, $row);
    }
} catch (\Throwable $e) {
}
try {
    $stmt = $db->query("SELECT COALESCE(au.display_name, CONCAT('Admin ',ma.admin_id)) as name, COUNT(*) as replies, ROUND(AVG(ma.response_time_seconds)/60) as avg_min FROM message_analytics ma LEFT JOIN admin_users au ON au.id = ma.admin_id WHERE ma.admin_id IS NOT NULL AND ma.created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY ma.admin_id ORDER BY avg_min ASC LIMIT 5");
    $admins = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
} catch (\Throwable $e) {
}

// Top products - use the JSON that the dashboard uses if available, to avoid slow queries
$prodCache = '/www/wwwroot/cny.re-ya.com/cache/inbox_products_7.json';
$topProductsStr = "ยังไม่มีข้อมูลสินค้าขายดีในขณะนี้";
if (file_exists($prodCache)) {
    $jd = json_decode(file_get_contents($prodCache), true);
    if (!empty($jd['products'])) {
        $list = [];
        foreach (array_slice($jd['products'], 0, 5) as $i => $p) {
            $list[] = ($i + 1) . ". {$p['name']} (ลูกค้าถาม: {$p['mention_count']} ราย, stock: {$p['live_qty']})";
        }
        $topProductsStr = implode("
", $list);
    }
}

$ctxJson = json_encode([
    'report_date' => date('Y-m-d', strtotime('-1 day')),
    'orders_yesterday' => ['total' => (int)$oy['total'], 'amount_thb' => number_format((float)$oy['amount'], 0)],
    'orders_today_live' => ['total' => (int)$ot['total'], 'amount_thb' => number_format((float)$ot['amount'], 0)],
    'bdo_yesterday' => ['total' => (int)$bdoY['total'], 'amount_thb' => number_format((float)$bdoY['amount'], 0)],
    'top_admins_response_time' => $admins,
], JSON_UNESCAPED_UNICODE);

$systemPrompt = "คุณเป็น REYA Intelligence AI — ผู้ช่วยบริหารธุรกิจของ REYA ร้านยาส่ง B2B\n" .
    "คุณมีความรู้เชิง ontology: ลูกค้าเป็นร้านขายยา/เภสัชชุมชน, สินค้าหลักคือยาและอุปกรณ์การแพทย์, ช่องทางขายผ่าน LINE, admin ตอบลูกค้า\n" .
    "ตอบภาษาไทยเท่านั้น กระชับ ชัดเจน ใช้ข้อมูลจาก context ด้านล่าง\n\n" .
    "=== ข้อมูล real-time ===\n" .
    $ctxJson . "\n" .
    "สินค้าที่ถูกถามเยอะสุด 5 อันดับ (ใช้แทนสินค้าขายดี):\n" . $topProductsStr . "\n" .
    "===================\n\n" .
    "กฎเด็ดขาด:\n1. ตอบภาษาไทยเท่านั้น\n2. ตอบทีละคำถาม สั้น 1-4 ประโยค ตรงประเด็น\n3. ห้ามแนะนำตัว ไม่ต้องทวนคำถาม\n4. ถ้าวิเคราะห์ ให้เชื่อมโยงกับ pattern ธุรกิจ B2B (ontology)\n5. ถ้าถามสินค้าขายดี ให้ตอบตามรายชื่อที่ให้ไป\n6. ใช้ตัวเลขจริงจาก context ห้ามแต่งเอง\n7. emoji 1-2 ตัวสูงสุด";

$contents = [];
foreach (array_slice($history, -10) as $h) {
    if (!isset($h['role'], $h['content'])) continue;
    $contents[] = ['role' => $h['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $h['content']]]];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

$payload = json_encode([
    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
    'contents' => $contents,
    'generationConfig' => ['maxOutputTokens' => 512, 'temperature' => 0.3],
], JSON_UNESCAPED_UNICODE);

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:streamGenerateContent?alt=sse&key=" . urlencode($geminiKey);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_WRITEFUNCTION => function($ch, $data) {
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line || $line === 'data: [DONE]') continue;
            if (strpos($line, 'data: ') === 0) {
                $json = json_decode(substr($line, 6), true);
                if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                    $text = $json['candidates'][0]['content']['parts'][0]['text'];
                    echo "data: " . json_encode(['token' => $text], JSON_UNESCAPED_UNICODE) . "\n\n";
                    ob_flush(); flush();
                }
            }
        }
        return strlen($data);
    },
]);

curl_exec($ch);
if ($err = curl_error($ch)) echo "data: " . json_encode(['error' => $err]) . "\n\n";
echo "data: [DONE]\n\n";
flush();
