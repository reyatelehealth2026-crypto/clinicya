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
// Route root-domain (Mini App / LIFF) request to the tenant DB by line_account_id (split-brain fix).
require_once __DIR__ . '/../bootstrap/route_by_account.php';
require_once __DIR__ . '/../includes/ai-chat-context.php';
session_write_close();

/**
 * Emit a structured SSE event ({"structured": {...}}). Used by Phase 1
 * extensions (user_context, state, emergency, drug_interactions).
 */
$emitStructured = static function (array $payload): void {
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo 'data: ' . json_encode(['structured' => $payload], $flags) . "\n\n";
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
};

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$userMessage = trim((string) ($input['message'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];
$mode = strtolower(trim((string) ($input['mode'] ?? '')));

/**
 * เลือก persona — DEFAULT คือ consult (เภสัชกรผู้ช่วย) เพื่อให้ฝั่งลูกค้าได้ persona ที่ถูก
 * Admin/B2B mode ต้องมีสัญญาณ explicit เท่านั้น (ห้าม default ไป B2B)
 */
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$consultModes = ['consult', 'customer', 'clinic', 'pharmacy', 'pharmacist'];
$adminModes = ['admin', 'dashboard', 'b2b', 'ops'];

// ลำดับความสำคัญ: explicit mode=consult ชนะทุกอย่าง
if (in_array($mode, $consultModes, true)) {
    $isConsultMode = true;
} elseif (in_array($mode, $adminModes, true)) {
    $isConsultMode = false;
} else {
    // ไม่มี explicit mode → ดู referer (admin pages ต้องมีสัญญาณชัด)
    $isAdminMode = stripos($referer, 'ai-settings.php') !== false
        || stripos($referer, 'ai-chatbot.php') !== false
        || stripos($referer, 'ai-chat.php?tab=') !== false
        || stripos($referer, '/dashboard') !== false
        || stripos($referer, '/inbox') !== false
        || stripos($referer, '/admin') !== false;
    $isConsultMode = !$isAdminMode;
}

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
 * ลำดับคีย์: ai_settings ทุกแถว → ai_settings_global → ai_chat_settings → env/config/.env
 * รองรับชื่อคอลัมน์/ตารางเก่า เพื่อไม่ให้ schema ต่างเวอร์ชันทำให้คีย์หายไป
 */
$geminiKeys = [];
$openaiKey = '';
$diagnostics = [];

$loadKeysFromQuery = function (string $sql, string $col) use ($db, &$diagnostics): array {
    $out = [];
    try {
        $stmt = $db->query($sql);
        if ($stmt) {
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $diagnostics[] = $sql . ' → ' . count($rows) . ' rows';
            foreach ($rows as $r) {
                $v = isset($r[$col]) ? trim((string) $r[$col]) : '';
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }
    } catch (\Throwable $e) {
        $diagnostics[] = 'SQL fail: ' . substr($e->getMessage(), 0, 80);
    }
    return $out;
};

// 1) ai_settings — ลอง query แบบง่ายสุดก่อน
foreach ($loadKeysFromQuery(
    "SELECT gemini_api_key FROM ai_settings WHERE gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) <> ''",
    'gemini_api_key'
) as $k) {
    if (!in_array($k, $geminiKeys, true)) {
        $geminiKeys[] = $k;
    }
}

// 2) ตารางเก่า: ai_chat_settings (บางเวอร์ชันใช้ชื่อนี้)
foreach ($loadKeysFromQuery(
    "SELECT gemini_api_key FROM ai_chat_settings WHERE gemini_api_key IS NOT NULL AND TRIM(gemini_api_key) <> ''",
    'gemini_api_key'
) as $k) {
    if (!in_array($k, $geminiKeys, true)) {
        $geminiKeys[] = $k;
    }
}

// 3) settings table แบบ key-value
foreach ($loadKeysFromQuery(
    "SELECT setting_value AS gemini_api_key FROM settings WHERE setting_key IN ('gemini_api_key','GEMINI_API_KEY') AND setting_value IS NOT NULL AND TRIM(setting_value) <> ''",
    'gemini_api_key'
) as $k) {
    if (!in_array($k, $geminiKeys, true)) {
        $geminiKeys[] = $k;
    }
}

// 4) env/constant
$envGeminiKey = defined('GEMINI_API_KEY') ? (string) GEMINI_API_KEY : '';
if ($envGeminiKey === '') {
    $envGeminiKey = (string) (getenv('GEMINI_API_KEY') ?: '');
}
$envGeminiKey = trim($envGeminiKey);
if ($envGeminiKey !== '' && !in_array($envGeminiKey, $geminiKeys, true)) {
    $geminiKeys[] = $envGeminiKey;
    $diagnostics[] = 'env GEMINI_API_KEY: present';
} else {
    $diagnostics[] = 'env GEMINI_API_KEY: ' . ($envGeminiKey === '' ? 'empty' : 'duplicate');
}

// OpenAI: ai_settings → ai_chat_settings → settings → env
$openaiCandidates = array_merge(
    $loadKeysFromQuery(
        "SELECT openai_api_key FROM ai_settings WHERE openai_api_key IS NOT NULL AND TRIM(openai_api_key) <> ''",
        'openai_api_key'
    ),
    $loadKeysFromQuery(
        "SELECT openai_api_key FROM ai_chat_settings WHERE openai_api_key IS NOT NULL AND TRIM(openai_api_key) <> ''",
        'openai_api_key'
    ),
    $loadKeysFromQuery(
        "SELECT setting_value AS openai_api_key FROM settings WHERE setting_key IN ('openai_api_key','OPENAI_API_KEY') AND setting_value IS NOT NULL AND TRIM(setting_value) <> ''",
        'openai_api_key'
    )
);
$openaiKey = $openaiCandidates[0] ?? '';
if ($openaiKey === '') {
    $envOpenAI = defined('OPENAI_API_KEY') ? (string) OPENAI_API_KEY : '';
    if ($envOpenAI === '') {
        $envOpenAI = (string) (getenv('OPENAI_API_KEY') ?: '');
    }
    $openaiKey = trim($envOpenAI);
    $diagnostics[] = 'env OPENAI_API_KEY: ' . ($openaiKey === '' ? 'empty' : 'present');
}

if (empty($geminiKeys) && $openaiKey === '') {
    $diag = implode(' | ', $diagnostics);
    echo "data: " . json_encode([
        'error' => 'AI key not configured (gemini/openai) — ' . $diag,
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
    exit;
}

// --- TRIAGE ROUTER (telepharmacy Yes/No flow) ---------------------------------
// ถ้า consult mode + triage feature เปิด → ลอง route ผ่าน TriageRouter ก่อน
// ถ้า router คืน type=continue → fall through ไป LLM แบบเดิม
// ถ้า type=question/products/escalate → emit structured + exit ทันที
if ($isConsultMode) {
    try {
        require_once __DIR__ . '/../modules/AIChat/Autoloader.php';
        if (function_exists('loadAIChatModule')) {
            loadAIChatModule();
        }
        $lineAccountId = isset($input['line_account_id']) && is_numeric($input['line_account_id'])
            ? (int) $input['line_account_id'] : null;
        $userId = isset($input['user_id']) && is_numeric($input['user_id'])
            ? (int) $input['user_id'] : 0;
        // 🆕 ถ้าไม่มี user_id → lookup users.id จริงจาก line_user_id ก่อน fallback crc32
        // (สำคัญ: ทำให้ triage_sessions.user_id link กลับมา users ได้ → pharmacy dashboard
        //  + dispense page แสดง display_name + AI chat history ถูกจับคู่)
        if ($userId === 0 && !empty($input['line_user_id']) && is_string($input['line_user_id'])) {
            try {
                $lookupStmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
                $lookupStmt->execute([trim($input['line_user_id'])]);
                $userRow = $lookupStmt->fetch(\PDO::FETCH_ASSOC);
                if ($userRow && !empty($userRow['id'])) {
                    $userId = (int) $userRow['id'];
                }
            } catch (\Throwable $e) {
                error_log('ai-chat user lookup error: ' . $e->getMessage());
            }
        }
        // Fallback: anonymous LINE user ที่ยังไม่มี row ใน users → ใช้ crc32 ของ line_user_id
        if ($userId === 0 && !empty($input['line_user_id']) && is_string($input['line_user_id'])) {
            $userId = (int) (crc32(trim($input['line_user_id'])) & 0x7FFFFFFF);
        }
        // Fallback สำหรับ web (ไม่ใช่ LIFF) — derive จาก IP+UA เพื่อให้ triage ทำงานบน app.re-ya.com ด้วย
        // ID จะคงที่ต่อ browser/IP เดียวกันในวันเดียวกัน (เพิ่ม date salt กัน session ค้างข้ามวัน)
        if ($userId === 0) {
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($ip !== '' || $ua !== '') {
                $seed = $ip . '|' . $ua . '|' . date('Y-m-d');
                $userId = (int) (crc32($seed) & 0x7FFFFFFF);
            }
        }

        if ($userId > 0 && class_exists(\Modules\AIChat\Services\TriageRouter::class)) {
            $router = new \Modules\AIChat\Services\TriageRouter($db, $geminiKeys, $lineAccountId);
            $tr = $router->handleTurn($userMessage, $userId);
            if (is_array($tr) && isset($tr['type']) && $tr['type'] !== 'continue') {
                // emit สรุปข้อความเป็น token (สำหรับ client ที่ไม่ render structured)
                if (!empty($tr['question_th'])) {
                    echo 'data: ' . json_encode(['token' => (string) $tr['question_th']], JSON_UNESCAPED_UNICODE) . "\n\n";
                } elseif (!empty($tr['message'])) {
                    echo 'data: ' . json_encode(['token' => (string) $tr['message']], JSON_UNESCAPED_UNICODE) . "\n\n";
                }
                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                // emit structured สำหรับ client ใหม่
                $structuredFlags = JSON_UNESCAPED_UNICODE;
                if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                    $structuredFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
                }
                echo 'data: ' . json_encode(['structured' => $tr], $structuredFlags) . "\n\n";
                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                echo "data: [DONE]\n\n";
                flush();
                exit;
            }
        }
    } catch (\Throwable $e) {
        // ถ้า triage layer พัง → log แล้ว fall through ไป LLM ปกติ
        error_log('TriageRouter error: ' . $e->getMessage());
    }
}

// --- PHASE 1 SAFETY + PERSISTENCE (consult mode only) -----------------------
// Runs AFTER TriageRouter returned 'continue' (or failed silently), BEFORE we
// build the Gemini payload. Loads user context, screens for emergency red
// flags, persists the user turn, and stages the post-stream callback to save
// the assistant response + emit drug_interactions warnings.
$ctxLineUserId   = isset($input['line_user_id']) && is_string($input['line_user_id'])
    ? trim($input['line_user_id']) : '';
$ctxLineAccountId = isset($input['line_account_id']) && is_numeric($input['line_account_id'])
    ? (int) $input['line_account_id'] : null;
$ctxInternalUserId = 0;
if ($ctxLineUserId !== '') {
    $ctxInternalUserId = aiChatResolveInternalUserId($db, $ctxLineUserId);
}
$ctxUserProfile  = ['drug_allergies' => [], 'current_medications' => [], 'chronic_diseases' => null, 'display_name' => null];
$ctxSafetyXml    = '';
$ctxSafetyRule   = '';
$ctxSessionId    = null;

if ($isConsultMode) {
    try {
        // 1) Full user safety context — used to inject into system prompt
        //    AND emitted as user_context SSE event for the UI.
        if ($ctxLineUserId !== '') {
            $ctxUserProfile = getUserFullContextForChat($db, $ctxLineUserId);
            if (!empty($ctxUserProfile['id'])) {
                $ctxInternalUserId = (int) $ctxUserProfile['id'];
            }
        }

        // 2) Resolve active triage session id (read-only) so we can tag
        //    persisted messages + drive the state header indicator.
        $activeSession = null;
        if ($ctxInternalUserId > 0) {
            $activeSession = aiChatGetActiveTriageSession($db, $ctxInternalUserId, $ctxLineAccountId);
            if ($activeSession !== null) {
                $ctxSessionId = (string) ($activeSession['id'] ?? '');
                if ($ctxSessionId === '') {
                    $ctxSessionId = null;
                }
            }
        }

        // 3) Build inline <user_profile> XML for the Gemini system prompt.
        $ctxSafetyXml = aiChatBuildUserProfileXml($ctxUserProfile);
        if ($ctxSafetyXml !== '') {
            $ctxSafetyRule = "\n\nกฎความปลอดภัย (สำคัญที่สุด):\n"
                . "- ห้ามแนะนำยาที่ผู้ใช้แพ้เด็ดขาด — ดูใน <allergies> ของ <user_profile>\n"
                . "- ตรวจดู <current_medications> ของ user ก่อนแนะนำยาใหม่ — ระบุปฏิกิริยาที่อาจเกิดขึ้น\n"
                . "- คำนึงถึง <chronic_diseases> เมื่อเลือกยา (เช่น โรคไต/ตับ/หัวใจ)\n\n"
                . $ctxSafetyXml;
        }

        // 4) Emit state SSE event (Phase 2/3 will render as a chip).
        $stateName = $activeSession !== null
            ? (string) ($activeSession['current_state'] ?? 'greeting')
            : 'greeting';
        $stateLabels = [
            'greeting'         => 'พร้อมให้บริการ',
            'symptom'          => 'ซักประวัติอาการ',
            'duration'         => 'สอบถามระยะเวลา',
            'severity'         => 'ประเมินความรุนแรง',
            'associated'       => 'อาการร่วม',
            'allergy'          => 'ประวัติการแพ้ยา',
            'medical_history'  => 'โรคประจำตัว',
            'current_meds'     => 'ยาที่ทานอยู่',
            'recommend'        => 'แนะนำยา',
            'confirm'          => 'ยืนยันการรักษา',
            'complete'         => 'เสร็จสิ้นการประเมิน',
            'escalate'         => 'ส่งต่อเภสัชกร',
        ];
        $emitStructured([
            'type'     => 'state',
            'state'    => $stateName,
            'label_th' => $stateLabels[$stateName] ?? 'พร้อมให้บริการ',
        ]);

        // 5) Emit user_context SSE event ONLY if there is anything worth
        //    surfacing (allergies / chronic / current meds).
        $hasAllergies = !empty($ctxUserProfile['drug_allergies']);
        $hasMeds      = !empty($ctxUserProfile['current_medications']);
        $hasChronic   = !empty($ctxUserProfile['chronic_diseases']);
        if ($hasAllergies || $hasMeds || $hasChronic) {
            $emitStructured(aiChatBuildUserContextEvent($ctxUserProfile));
        }

        // 6) Early emergency screen — if RedFlagDetector finds CRITICAL
        //    flags we emit `emergency` and exit BEFORE the LLM round-trip.
        try {
            require_once __DIR__ . '/../modules/AIChat/Autoloader.php';
            if (function_exists('loadAIChatModule')) {
                loadAIChatModule();
            }
            if (class_exists(\Modules\AIChat\Services\RedFlagDetector::class)) {
                $detector = new \Modules\AIChat\Services\RedFlagDetector();
                $flags = $detector->detect($userMessage);
                $critical = array_values(array_filter($flags, static function ($f) {
                    return is_array($f) && ($f['severity'] ?? '') === 'critical';
                }));
                if (!empty($critical)) {
                    $symptoms = array_map(static function ($f) {
                        return (string) ($f['message'] ?? '');
                    }, $critical);
                    $actions = array_map(static function ($f) {
                        return (string) ($f['action'] ?? '');
                    }, $critical);

                    // Flip the active triage session to 'escalate' BEFORE we
                    // emit the emergency event so the pharmacist dashboard
                    // and the Mini-App state header agree on the new state
                    // even if the client races to re-poll.
                    if ($activeSession !== null && !empty($activeSession['id'])) {
                        try {
                            $stmt = $db->prepare(
                                "UPDATE triage_sessions
                                    SET current_state = 'escalate', updated_at = NOW()
                                  WHERE id = ?"
                            );
                            $stmt->execute([(int) $activeSession['id']]);
                        } catch (\Throwable $stateErr) {
                            error_log('triage_sessions escalate update: ' . $stateErr->getMessage());
                        }
                    }

                    // Emit the state transition first so any UI listening on
                    // 'state' switches the header chip before the emergency
                    // modal opens (avoids the stale 'greeting' flash).
                    $emitStructured(aiChatBuildStateEvent('escalate', 'ส่งต่อเภสัชกร'));

                    $emitStructured(aiChatBuildEmergencyEvent($symptoms, $actions, 'critical'));

                    // Persist what we have (user turn) so the conversation
                    // history still reflects the escalation event.
                    if ($ctxInternalUserId > 0) {
                        aiChatSaveConversationMessage(
                            $db,
                            $ctxInternalUserId,
                            $ctxLineAccountId,
                            $ctxSessionId,
                            'user',
                            $userMessage
                        );
                        $emergencyText = '🚨 ' . implode(' / ', $symptoms) . "\n" . implode("\n", $actions);
                        aiChatSaveConversationMessage(
                            $db,
                            $ctxInternalUserId,
                            $ctxLineAccountId,
                            $ctxSessionId,
                            'assistant',
                            $emergencyText
                        );
                    }

                    // Best-effort pharmacist notification for active sessions.
                    if ($activeSession !== null) {
                        aiChatEnsureTriageNotification(
                            $db,
                            (int) $activeSession['id'],
                            $ctxInternalUserId,
                            $ctxLineAccountId,
                            $ctxUserProfile,
                            ['red_flags' => $critical],
                            'escalate'
                        );
                    }

                    echo "data: [DONE]\n\n";
                    flush();
                    exit;
                }
            }
        } catch (\Throwable $e) {
            error_log('RedFlag early screen error: ' . $e->getMessage());
        }

        // 7) Persist the user's turn now (before LLM call) so the row exists
        //    even if Gemini times out.
        if ($ctxInternalUserId > 0) {
            aiChatSaveConversationMessage(
                $db,
                $ctxInternalUserId,
                $ctxLineAccountId,
                $ctxSessionId,
                'user',
                $userMessage
            );
        }
    } catch (\Throwable $e) {
        // Safety layer must not break the SSE stream.
        error_log('AIChat Phase1 safety pass error: ' . $e->getMessage());
    }
}

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
$prodCache = __DIR__ . '/../cache/inbox_products_7.json';
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

// RAG context — ดึงความรู้ที่เกี่ยวข้องจาก ai_knowledge_base ก่อน build prompt
$ragContext = '';
$ragDiag = ['chunks' => 0, 'codes' => [], 'kb_total' => 0];
try {
    require_once __DIR__ . '/../modules/AIChat/Autoloader.php';
    if (function_exists('loadAIChatModule')) {
        loadAIChatModule();
    }
    if (class_exists(\Modules\AIChat\Services\KnowledgeRetriever::class)
        && class_exists(\Modules\AIChat\Services\SymptomMapper::class)) {
        $kbAccId = isset($input['line_account_id']) && is_numeric($input['line_account_id'])
            ? (int) $input['line_account_id'] : null;
        $mapper = new \Modules\AIChat\Services\SymptomMapper();
        $codes = $mapper->mapAllConditions($userMessage);
        $retriever = new \Modules\AIChat\Services\KnowledgeRetriever($db);
        $chunks = $retriever->retrieve($kbAccId, $userMessage, $codes, 4);
        $ragContext = $retriever->buildPromptContext($chunks);
        $ragDiag['chunks'] = count($chunks);
        $ragDiag['codes']  = $codes;
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM ai_knowledge_base WHERE is_active=1");
            $ragDiag['kb_total'] = (int) ($stmt ? $stmt->fetchColumn() : 0);
        } catch (\Throwable $e) {}
    }
} catch (\Throwable $e) {
    error_log('KnowledgeRetriever skipped: ' . $e->getMessage());
}

// Emit RAG diagnostic เป็น SSE event แรก (เฉพาะ consult mode) — client ที่ไม่รู้จัก key นี้จะ ignore
if ($isConsultMode) {
    echo 'data: ' . json_encode(['rag_diag' => $ragDiag], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_flush(); }
    flush();
}

if ($isConsultMode) {
    $systemPrompt = "คุณคือ AI เภสัชกรผู้ช่วยของร้านยา Re-Ya สำหรับลูกค้า/ผู้ป่วยทั่วไป\n" .
        "บทบาท: ให้คำแนะนำอาการ + **แนะนำชื่อยาเฉพาะตัวอย่างชัดเจน** ที่เหมาะกับอาการ พร้อมขนาด/วิธีใช้/ข้อควรระวัง — เภสัชกรร้านจะเป็นคนอนุมัติก่อนจ่ายจริง คุณไม่ได้จ่ายยาเอง\n\n" .
        "กฎเด็ดขาด (ห้ามฝ่าฝืน):\n" .
        "1. ตอบภาษาไทย ครบถ้วนชัดเจน 3-8 ประโยค ปิดท้ายเรียบร้อย ไม่ตัดกลางคัน\n" .
        "2. **ต้องระบุชื่อยาที่แนะนำ** (เช่น Paracetamol 500mg, Loratadine 10mg, ORS, Bromhexine, ฯลฯ) พร้อมขนาดและวิธีใช้ — ห้ามตอบ 'ปรึกษาเภสัชกร' เป็นคำตอบหลัก เพราะระบบมีเภสัชกรอนุมัติอยู่แล้ว\n" .
        "3. ห้ามวินิจฉัยโรคชี้ชัด ใช้คำว่า 'มีแนวโน้ม/อาจเป็น' ได้ — แต่ต้องเสนอยาเบื้องต้นที่ปลอดภัย OTC ได้\n" .
        "4. ยา prescription (ยาควบคุม/Rx) → แนะนำชื่อตัวยาได้ แต่บอกว่า 'ต้องให้เภสัชกรอนุมัติก่อน'\n" .
        "5. อาการฉุกเฉิน (หายใจไม่ออก/เจ็บหน้าอกรุนแรง/หมดสติ/อัมพาต/แพ้รุนแรง) → แนะนำโทร 1669 ทันที + ห้ามแนะนำยา\n" .
        "6. ห้ามแนะนำตัว ไม่ทวนคำถาม ตอบตรงประเด็น emoji 1-2 ตัว\n" .
        "7. คำถามไม่ใช่เรื่องสุขภาพ/ยา → ตอบสุภาพว่าให้คำแนะนำเฉพาะเรื่องสุขภาพ\n" .
        "8. **ห้ามตอบเรื่อง stock/ยอดขาย/ออเดอร์/B2B/admin metrics** — ถ้าถูกถามให้ตอบ 'ส่วนนี้ต้องสอบถามแอดมินครับ/ค่ะ'\n" .
        "9. ใช้ RAG context (ถ้ามี) เป็นหลัก ห้ามแต่งสรรพคุณยา\n" .
        "10. ใช้ user_profile (ถ้ามี) — ตรวจ <allergies> ก่อนแนะนำยาทุกครั้ง ห้ามแนะนำยาที่ผู้ใช้แพ้\n" .
        "11. **เมื่อแนะนำยา** ปิดท้ายสั้นๆ ตอบคำถาม + ห้ามขอ confirm/นัด/พูดเรื่อง 'บอก โอเค' — ระบบจะแสดงปุ่ม 'ส่งให้เภสัชกร' ให้ผู้ใช้กดเอง อย่าพูดถึงปุ่มซ้ำ ไม่ต้อง prompt ทุกครั้ง\n" .
        "12. ห้ามทักทาย ห้ามทบทวนยาที่แนะนำไปแล้วใน turn ก่อน ถ้าผู้ใช้ไม่ได้ถามใหม่"
        . ($ctxSafetyRule !== '' ? $ctxSafetyRule : '')
        . ($ragContext !== '' ? "\n\n" . $ragContext : '');
} else {
    $systemPrompt = "คุณเป็น REYA Intelligence AI — ผู้ช่วยบริหารธุรกิจของ REYA ร้านยาส่ง B2B\n" .
        "คุณมีความรู้เชิง ontology: ลูกค้าเป็นร้านขายยา/เภสัชชุมชน, สินค้าหลักคือยาและอุปกรณ์การแพทย์, ช่องทางขายผ่าน LINE, admin ตอบลูกค้า\n" .
        "ตอบภาษาไทยเท่านั้น กระชับ ชัดเจน ใช้ข้อมูลจาก context ด้านล่าง\n\n" .
        "=== ข้อมูล real-time ===\n" .
        $ctxJson . "\n" .
        "สินค้าที่ถูกถามเยอะสุด 5 อันดับ (ใช้แทนสินค้าขายดี):\n" . $topProductsStr . "\n" .
        "===================\n\n" .
        "กฎเด็ดขาด:\n1. ตอบภาษาไทยเท่านั้น\n2. ตอบทีละคำถาม สั้น 1-4 ประโยค ตรงประเด็น\n3. ห้ามแนะนำตัว ไม่ต้องทวนคำถาม\n4. ถ้าวิเคราะห์ ให้เชื่อมโยงกับ pattern ธุรกิจ B2B (ontology)\n5. ถ้าถามสินค้าขายดี ให้ตอบตามรายชื่อที่ให้ไป\n6. ใช้ตัวเลขจริงจาก context ห้ามแต่งเอง\n7. emoji 1-2 ตัวสูงสุด"
        . ($ragContext !== '' ? "\n\n" . $ragContext : '');
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
    'generationConfig' => ['maxOutputTokens' => 2048, 'temperature' => 0.4],
], JSON_UNESCAPED_UNICODE);

if ($payload === false) {
    echo "data: " . json_encode(['error' => 'Invalid UTF-8 / JSON encode failed']) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
    exit;
}

// State sharing across attempts
$emittedAnyToken = false;
$capturedError = '';
$silentMode = true; // true = บัฟเฟอร์ error ไว้ลอง key/provider ถัดไป (ไม่ส่งให้ client ทันที)
$assistantBuffer = ''; // เก็บข้อความตอบกลับทั้งหมดเพื่อ persist หลังจบ stream

$emitToken = static function (string $t) use (&$emittedAnyToken, &$assistantBuffer): void {
    $emittedAnyToken = true;
    $assistantBuffer .= $t;
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

$emitErrorOrCapture = static function (string $msg) use (&$silentMode, &$capturedError): void {
    if ($silentMode) {
        $capturedError = $msg;
        return;
    }
    echo 'data: ' . json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE) . "\n\n";
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        ob_flush();
    }
    flush();
};

/**
 * ลอง Gemini ด้วยคีย์ที่ระบุ คืน true ถ้ามี token ส่งออกอย่างน้อย 1 ตัว
 */
$tryGemini = function (string $key) use ($payload, $emitToken, &$capturedError, &$emittedAnyToken, &$assistantBuffer): bool {
    // Reset the persistence buffer at the start of each attempt so a
    // partially-streamed response from a failed key does not get appended
    // to (and pollute) the response that the fallback key eventually
    // succeeds with. Mirrors the $emittedAnyToken accounting below.
    $assistantBuffer = '';
    $tokenCountBefore = $emittedAnyToken ? 1 : 0;
    $sseBuffer = '';
    $upstreamBody = '';
    $streamErrorMsg = '';

    $processLine = function (string $line) use (&$streamErrorMsg, $emitToken): void {
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
            $streamErrorMsg = is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'], JSON_UNESCAPED_UNICODE)) : (string) $json['error'];
            return;
        }
        if (!empty($json['promptFeedback']['blockReason'])) {
            $streamErrorMsg = 'Prompt blocked: ' . $json['promptFeedback']['blockReason'];
            return;
        }
        $candidateList = $json['candidates'] ?? null;
        if (!is_array($candidateList)) {
            return;
        }
        foreach ($candidateList as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $parts = $candidate['content']['parts'] ?? null;
            if (!is_array($parts)) {
                continue;
            }
            foreach ($parts as $part) {
                if (is_array($part) && !empty($part['text']) && is_string($part['text'])) {
                    $emitToken($part['text']);
                }
            }
        }
    };

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:streamGenerateContent?alt=sse&key=" . urlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$sseBuffer, &$upstreamBody, $processLine) {
            $upstreamBody .= $data;
            if (strlen($upstreamBody) > 98304) {
                $upstreamBody = substr($upstreamBody, -98304);
            }
            $sseBuffer .= $data;
            while (($pos = strpos($sseBuffer, "\n")) !== false) {
                $line = substr($sseBuffer, 0, $pos);
                $sseBuffer = substr($sseBuffer, $pos + 1);
                $processLine($line);
            }
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($sseBuffer !== '') {
        $processLine($sseBuffer);
    }

    $tokensAfter = $emittedAnyToken ? 1 : 0;
    if ($tokensAfter > $tokenCountBefore) {
        return true;
    }

    // ไม่ได้ token — เก็บ error ไว้ใน capturedError
    $detail = $streamErrorMsg;
    if ($detail === '' && $curlErr !== '') {
        $detail = $curlErr;
    }
    if ($detail === '' && $httpCode >= 400) {
        $parsed = json_decode($upstreamBody, true);
        if (is_array($parsed) && isset($parsed['error']['message'])) {
            $detail = (string) $parsed['error']['message'];
        } else {
            $detail = 'Gemini HTTP ' . $httpCode;
            if (trim($upstreamBody) !== '') {
                $snippet = preg_replace('/\s+/', ' ', substr($upstreamBody, 0, 280));
                if (is_string($snippet) && $snippet !== '') {
                    $detail .= ' | ' . $snippet;
                }
            }
        }
    }
    if ($detail === '') {
        $detail = 'ไม่ได้รับข้อความจากโมเดล';
    }
    $capturedError = $detail;
    return false;
};

/**
 * Fallback OpenAI (non-streaming) — emit เป็น chunk เดียว
 */
$tryOpenAI = function (string $key) use ($systemPrompt, $contents, $emitToken, &$capturedError, &$assistantBuffer): bool {
    // Same buffer reset as $tryGemini — if Gemini emitted partial tokens
    // then failed, OpenAI's fallback response should not be concatenated
    // onto that fragment when we persist.
    $assistantBuffer = '';
    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($contents as $turn) {
        $role = ($turn['role'] ?? 'user') === 'model' ? 'assistant' : 'user';
        $text = $turn['parts'][0]['text'] ?? '';
        if ($text === '') {
            continue;
        }
        $messages[] = ['role' => $role, 'content' => $text];
    }
    $body = json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 512,
        'temperature' => 0.3,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        $capturedError = 'OpenAI: ' . $curlErr;
        return false;
    }
    $parsed = is_string($resp) ? json_decode($resp, true) : null;
    if ($httpCode >= 400 || !is_array($parsed)) {
        $msg = is_array($parsed) && isset($parsed['error']['message']) ? (string) $parsed['error']['message'] : 'OpenAI HTTP ' . $httpCode;
        $capturedError = $msg;
        return false;
    }
    $text = $parsed['choices'][0]['message']['content'] ?? '';
    if (!is_string($text) || $text === '') {
        $capturedError = 'OpenAI: empty response';
        return false;
    }
    $emitToken($text);
    return true;
};

// 1) ลอง Gemini ทุกคีย์
$success = false;
foreach ($geminiKeys as $key) {
    if ($tryGemini($key)) {
        $success = true;
        break;
    }
}

// 2) ถ้าทุกคีย์ Gemini พัง → ลอง OpenAI
if (!$success && $openaiKey !== '') {
    if ($tryOpenAI($openaiKey)) {
        $success = true;
    }
}

// 3) ถ้ายังพังอีก → ส่ง error สุดท้ายที่เก็บไว้
$silentMode = false;
if (!$success) {
    $emitErrorOrCapture($capturedError !== '' ? $capturedError : 'AI ไม่ตอบสนอง — ตรวจสอบคีย์ Gemini/OpenAI');
}

// --- PHASE 1 POST-STREAM: persistence + drug-interaction warnings ----------
if ($isConsultMode && $success && $assistantBuffer !== '') {
    try {
        // Persist assistant response (mirrors user-turn save earlier).
        if ($ctxInternalUserId > 0) {
            aiChatSaveConversationMessage(
                $db,
                $ctxInternalUserId,
                $ctxLineAccountId,
                $ctxSessionId,
                'assistant',
                $assistantBuffer
            );
        }

        // If the AI mentioned any catalog products AND the user has a known
        // allergy/medication, emit a drug_interactions structured event so
        // the UI can render a warning card.
        $hasSafetySignal = !empty($ctxUserProfile['drug_allergies'])
            || !empty($ctxUserProfile['current_medications']);
        if ($hasSafetySignal) {
            $mentions = aiChatExtractProductMentions($db, $ctxLineAccountId, $assistantBuffer);
            if (!empty($mentions)) {
                $warnings = aiChatCheckDrugInteractionsSimple($mentions, $ctxUserProfile);
                if (!empty($warnings)) {
                    $emitStructured(aiChatBuildDrugInteractionsEvent($warnings));
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('AIChat Phase1 post-stream error: ' . $e->getMessage());
    }
}

echo "data: [DONE]\n\n";
flush();
