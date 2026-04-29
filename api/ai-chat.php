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
$mode = strtolower(trim((string) ($input['mode'] ?? '')));

/**
 * เลือก persona ตามแหล่งที่มา:
 * - mode=consult/customer/clinic/pharmacy หรือมาจากโดเมน clinicya / line-mini-app / liff → ผู้ช่วยเภสัชกร (ตอบอาการ)
 * - อย่างอื่น (dashboard B2B, AI Settings test, ai-chatbot.php) → REYA Intelligence (วิเคราะห์ออเดอร์/แอดมิน)
 */
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$consultModes = ['consult', 'customer', 'clinic', 'pharmacy'];
$isConsultMode = in_array($mode, $consultModes, true)
    || stripos($origin, 'clinicya') !== false
    || stripos($referer, 'clinicya') !== false
    || stripos($origin, 'line-mini-app') !== false
    || stripos($referer, '/shop') !== false
    || stripos($referer, '/ai-chat-page') !== false
    || stripos($referer, '/liff') !== false;

/**
 * ตัดข้อความแปลกปลอม (เช่น client แปะ JSON ทั้งก้อนต่อท้ายข้อความ) และจำกัดความยาว
 */
$cleanChatText = static function (string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    // แก้กรณีแปะ body ทั้งก้อนต่อท้าย เช่น ..."ปรึกษาเภสัชกร"{"message":"http...
    if (strpos($text, '{"message"') !== false && strpos($text, '"history"') !== false) {
        $cut = strpos($text, '{"message"');
        if ($cut !== false && $cut > 0) {
            $text = rtrim(substr($text, 0, $cut));
        } else {
            $text = '';
        }
    }
    if (mb_strlen($text) > 12000) {
        $text = mb_substr($text, 0, 12000) . '…';
    }
    return $text;
};

$userMessage = $cleanChatText($userMessage);

if (!$userMessage && empty($_SERVER['argv'])) { echo "data: " . json_encode(['error' => 'No message']) . "\n\n"; flush(); exit; }
if (empty($userMessage)) $userMessage = "test"; // for CLI testing

$db = Database::getInstance()->getConnection();

/**
 * ลำดับคีย์: ai_settings ก่อน (เหมือนที่ทดสอบในหน้า AI Settings) — ถ้าไม่มีค่อยใช้ GEMINI_API_KEY ใน config/env
 * เดิมโหลด env ก่อน ทำให้คีย์เก่าใน .env หมดอายุแต่ DB มีคีย์ใหม่ → ทดสอบในฟอร์มผ่าน แต่ /api/ai-chat.php ไปใช้ของเก่า
 */
$geminiKey = '';
try {
    $stmt = $db->query(
        "SELECT gemini_api_key FROM ai_settings WHERE gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) != '' ORDER BY line_account_id IS NULL DESC LIMIT 1"
    );
    $geminiKey = $stmt ? (string) $stmt->fetchColumn() : '';
} catch (Throwable $e) {
    $geminiKey = '';
}
if ($geminiKey === '') {
    $geminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : (getenv('GEMINI_API_KEY') ?: '');
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

if ($isConsultMode) {
    $systemPrompt = "คุณเป็น AI เภสัชกรผู้ช่วยของร้านยา Re-Ya ให้คำแนะนำเบื้องต้นด้านสุขภาพและยาแก่ลูกค้าทั่วไป\n" .
        "บทบาท: ให้คำแนะนำอาการเบื้องต้น ข้อมูลยาที่ไม่ต้องใช้ใบสั่งแพทย์ วิธีดูแลตัวเองเบื้องต้น และแนะนำให้พบแพทย์เมื่อจำเป็น\n" .
        "กฎเด็ดขาด:\n" .
        "1. ตอบภาษาไทยเท่านั้น กระชับ 1-4 ประโยค\n" .
        "2. ห้ามวินิจฉัยโรคหรือสั่งยา prescription — ให้คำแนะนำเบื้องต้นเท่านั้น\n" .
        "3. ถ้าอาการรุนแรง/ฉุกเฉิน แนะนำพบแพทย์หรือโทร 1669\n" .
        "4. ห้ามแนะนำตัว ไม่ต้องทวนคำถาม ตอบตรงประเด็น\n" .
        "5. emoji 1-2 ตัวสูงสุด\n" .
        "6. ถ้าถามเรื่องที่ไม่เกี่ยวกับสุขภาพ/ยา ให้บอกอย่างสุภาพว่าตอบได้เฉพาะเรื่องสุขภาพและยา";
} else {
    $systemPrompt = "คุณเป็น REYA Intelligence AI — ผู้ช่วยบริหารธุรกิจของ REYA ร้านยาส่ง B2B\n" .
        "คุณมีความรู้เชิง ontology: ลูกค้าเป็นร้านขายยา/เภสัชชุมชน, สินค้าหลักคือยาและอุปกรณ์การแพทย์, ช่องทางขายผ่าน LINE, admin ตอบลูกค้า\n" .
        "ตอบภาษาไทยเท่านั้น กระชับ ชัดเจน ใช้ข้อมูลจาก context ด้านล่าง\n\n" .
        "=== ข้อมูล real-time ===\n" .
        $ctxJson . "\n" .
        "สินค้าที่ถูกถามเยอะสุด 5 อันดับ (ใช้แทนสินค้าขายดี):\n" . $topProductsStr . "\n" .
        "===================\n\n" .
        "กฎเด็ดขาด:\n1. ตอบภาษาไทยเท่านั้น\n2. ตอบทีละคำถาม สั้น 1-4 ประโยค ตรงประเด็น\n3. ห้ามแนะนำตัว ไม่ต้องทวนคำถาม\n4. ถ้าวิเคราะห์ ให้เชื่อมโยงกับ pattern ธุรกิจ B2B (ontology)\n5. ถ้าถามสินค้าขายดี ให้ตอบตามรายชื่อที่ให้ไป\n6. ใช้ตัวเลขจริงจาก context ห้ามแต่งเอง\n7. emoji 1-2 ตัวสูงสุด";
}

$contents = [];
foreach (array_slice($history, -10) as $h) {
    if (!isset($h['role'], $h['content'])) {
        continue;
    }
    $turn = $cleanChatText((string) $h['content']);
    if ($turn === '') {
        continue;
    }
    $role = ($h['role'] === 'assistant') ? 'model' : 'user';
    if ($role !== 'user' && $role !== 'model') {
        $role = 'user';
    }
    $contents[] = ['role' => $role, 'parts' => [['text' => $turn]]];
}

// Gemini: ห้ามขึ้นต้นด้วย role model — ตัดข้อความต้อนรับของ assistant ที่หัวคิว
while (!empty($contents) && ($contents[0]['role'] ?? '') === 'model') {
    array_shift($contents);
}

// ห้าม user ต่อ user (เช่นรอบก่อน AI ไม่ตอบ แต่ client ส่ง history ลงท้ายด้วย user แล้วส่ง message ใหม่)
if (!empty($contents) && ($contents[count($contents) - 1]['role'] ?? '') === 'user') {
    $lastIdx = count($contents) - 1;
    $prev = $contents[$lastIdx]['parts'][0]['text'] ?? '';
    $contents[$lastIdx]['parts'][0]['text'] = rtrim((string) $prev) . "\n\n" . $userMessage;
} else {
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];
}

$payload = json_encode([
    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
    'contents' => $contents,
    'generationConfig' => ['maxOutputTokens' => 512, 'temperature' => 0.3],
], JSON_UNESCAPED_UNICODE);

if ($payload === false) {
    echo "data: " . json_encode(['error' => 'Invalid UTF-8 / JSON encode failed']) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
    exit;
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:streamGenerateContent?alt=sse&key=" . urlencode($geminiKey);
$ch = curl_init($url);

$sseBuffer = '';
$upstreamBody = '';
$emittedAnyToken = false;
$streamHadError = false;

$emitSseDataLine = static function (string $line) use (&$emittedAnyToken, &$streamHadError): void {
    $line = rtrim($line, "\r\n");
    if ($line === '' || $line === 'data: [DONE]') {
        return;
    }
    if (strncmp($line, 'data:', 5) !== 0) {
        return;
    }
    $raw = trim(substr($line, 5));
    if ($raw === '' || $raw === '[DONE]') {
        return;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return;
    }
    if (isset($json['error'])) {
        $streamHadError = true;
        $msg = is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'], JSON_UNESCAPED_UNICODE)) : (string) $json['error'];
        echo 'data: ' . json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        return;
    }
    if (!empty($json['promptFeedback']['blockReason'])) {
        $streamHadError = true;
        echo 'data: ' . json_encode([
            'error' => 'Prompt blocked: ' . $json['promptFeedback']['blockReason'],
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        return;
    }
    // ครบทุก candidate และทุก part (บางโมเดล/การส่งอาร์กิวเมนต์ใช้ index > 0 หรือหลาย part ต่อ chunk)
    $candidateList = $json['candidates'] ?? null;
    if (!is_array($candidateList)) {
        return;
    }
    $emitToken = static function (string $t) use (&$emittedAnyToken): void {
        $emittedAnyToken = true;
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        echo 'data: ' . json_encode(['token' => $t], $flags) . "\n\n";
        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    };
    foreach ($candidateList as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $parts = $candidate['content']['parts'] ?? null;
        if (!is_array($parts)) {
            continue;
        }
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (!empty($part['text']) && is_string($part['text'])) {
                $emitToken($part['text']);
            }
        }
    }
};

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$sseBuffer, &$upstreamBody, $emitSseDataLine) {
        $upstreamBody .= $data;
        if (strlen($upstreamBody) > 98304) {
            $upstreamBody = substr($upstreamBody, -98304);
        }
        $sseBuffer .= $data;
        while (($pos = strpos($sseBuffer, "\n")) !== false) {
            $line = substr($sseBuffer, 0, $pos);
            $sseBuffer = substr($sseBuffer, $pos + 1);
            $emitSseDataLine($line);
        }

        return strlen($data);
    },
]);

curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
if ($curlErr !== '') {
    echo 'data: ' . json_encode(['error' => $curlErr], JSON_UNESCAPED_UNICODE) . "\n\n";
} elseif ($httpCode >= 400 && !$emittedAnyToken && !$streamHadError) {
    $detail = 'Gemini HTTP ' . $httpCode . ' — ตรวจสอบ API Key / โควต้า / รูปแบบคำขอ';
    $parsed = json_decode($upstreamBody, true);
    if (is_array($parsed) && isset($parsed['error']['message'])) {
        $detail = (string) $parsed['error']['message'];
    } elseif (trim($upstreamBody) !== '') {
        $snippet = preg_replace('/\s+/', ' ', $upstreamBody);
        if (is_string($snippet) && strlen($snippet) > 280) {
            $snippet = substr($snippet, 0, 280) . '…';
        }
        if (is_string($snippet) && $snippet !== '') {
            $detail .= ' | ' . $snippet;
        }
    }
    echo 'data: ' . json_encode(['error' => $detail], JSON_UNESCAPED_UNICODE) . "\n\n";
}

// เหลือ buffer สุดท้ายที่ไม่มี newline (chunk สุดท้ายจาก curl)
if ($sseBuffer !== '') {
    $emitSseDataLine($sseBuffer);
    $sseBuffer = '';
}

curl_close($ch);

if (!$emittedAnyToken && !$streamHadError && ($httpCode === 200 || $httpCode === 0) && $curlErr === '') {
    echo 'data: ' . json_encode([
        'error' => 'ไม่ได้รับข้อความจากโมเดล (สตรีมว่างหรือถูกบล็อก) — ลองข้อความสั้นลงหรือล้างประวัติแชท',
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
}

echo "data: [DONE]\n\n";
flush();
