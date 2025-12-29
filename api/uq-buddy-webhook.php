<?php
/**
 * UQ Buddy - LINE Bot Webhook (PHP Version)
 * ระบบติดตามสถานะงาน MTK และ ARP ผ่าน LINE
 * 
 * ใช้ JSON file เก็บข้อมูลแทน Google Sheets
 */

/********************** CONFIG **********************/
define('LINE_CHANNEL_ACCESS_TOKEN', ''); // ใส่ token
define('LINE_GROUP_ID', ''); // ใส่ group ID
define('DATA_FILE', __DIR__ . '/uq-buddy-data.json');
define('PROPS_FILE', __DIR__ . '/uq-buddy-props.json');
define('MTK_LINK', ''); // link to MTK sheet
define('ARP_LINK', ''); // link to ARP sheet

$SYSTEM_ALIAS = [
    'mk' => 'mtk', 'mtk' => 'mtk',
    'aw' => 'arp', 'arp' => 'arp'
];

$SYSTEMS = [
    'mtk' => [
        'key' => 'mtk',
        'name' => 'Media Tracking (MK)',
        'statusCol' => 'mtk_status',
        'doneFlag' => 'MTK_DONE'
    ],
    'arp' => [
        'key' => 'arp',
        'name' => 'Asean Weekly Report (AW)',
        'statusCol' => 'arp_status',
        'doneFlag' => 'ARP_DONE'
    ]
];

/********************** PROPERTIES (แทน PropertiesService) **********************/
function getProperty($key) {
    $props = file_exists(PROPS_FILE) ? json_decode(file_get_contents(PROPS_FILE), true) : [];
    return $props[$key] ?? null;
}

function setProperty($key, $value) {
    $props = file_exists(PROPS_FILE) ? json_decode(file_get_contents(PROPS_FILE), true) : [];
    $props[$key] = $value;
    file_put_contents(PROPS_FILE, json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function deleteProperty($key) {
    $props = file_exists(PROPS_FILE) ? json_decode(file_get_contents(PROPS_FILE), true) : [];
    unset($props[$key]);
    file_put_contents(PROPS_FILE, json_encode($props, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/********************** DATA (แทน Google Sheets) **********************/
function getCampaigns() {
    if (!file_exists(DATA_FILE)) {
        // สร้างข้อมูลตัวอย่าง
        $sample = [
            ['name' => 'Campaign A', 'mtk_status' => 'Pending', 'arp_status' => 'Pending'],
            ['name' => 'Campaign B', 'mtk_status' => 'Pending', 'arp_status' => 'Pending'],
        ];
        file_put_contents(DATA_FILE, json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $sample;
    }
    return json_decode(file_get_contents(DATA_FILE), true) ?: [];
}

function saveCampaigns($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/********************** LINE API **********************/
function getUserProfile($userId) {
    $ch = curl_init("https://api.line.me/v2/bot/profile/{$userId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN]
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200 ? json_decode($res, true) : null;
}

function pushLine($text) {
    $userId = getProperty('LAST_USER_ID');
    $userName = getProperty('LAST_USER_NAME');
    
    if ($userId && $userName) {
        $header = "@{$userName}\n";
        $message = [
            'type' => 'text',
            'text' => $header . $text,
            'mention' => [
                'mentionees' => [[
                    'index' => 0,
                    'length' => mb_strlen($userName) + 1,
                    'userId' => $userId
                ]]
            ]
        ];
    } else {
        $message = ['type' => 'text', 'text' => $text];
    }
    
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'to' => LINE_GROUP_ID,
            'messages' => [$message]
        ])
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/********************** STATUS UTILS **********************/
function getStatus($systemKey) {
    global $SYSTEMS;
    $col = $SYSTEMS[$systemKey]['statusCol'];
    $data = getCampaigns();
    return array_map(fn($r) => ['name' => $r['name'], 'status' => $r[$col]], $data);
}

function buildStatusText($systemKey) {
    $statuses = getStatus($systemKey);
    $lines = [];
    foreach ($statuses as $c) {
        $icon = $c['status'] === 'Done' ? '🟢' : '🟠';
        $lines[] = "{$icon} {$c['name']} : {$c['status']}";
    }
    return implode("\n", $lines);
}

function getPending($systemKey) {
    return array_filter(getStatus($systemKey), fn($c) => $c['status'] !== 'Done');
}

function getProgress($systemKey) {
    $all = getStatus($systemKey);
    $done = count(array_filter($all, fn($c) => $c['status'] === 'Done'));
    return ['done' => $done, 'total' => count($all)];
}

function buildStatusBlockBoth() {
    global $SYSTEMS;
    $msg = '';
    foreach (['mtk', 'arp'] as $k) {
        $p = getProgress($k);
        $msg .= "📊 {$SYSTEMS[$k]['name']} ({$p['done']}/{$p['total']})\n";
        $msg .= buildStatusText($k) . "\n\n";
    }
    return trim($msg);
}

/********************** FUZZY MATCH **********************/
function levenshtein_utf8($a, $b) {
    return levenshtein(mb_strtolower($a), mb_strtolower($b));
}

function findClosest($input, $list) {
    $best = null;
    $bestScore = PHP_INT_MAX;
    foreach ($list as $name) {
        $score = levenshtein_utf8($input, $name);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $name;
        }
    }
    return ['name' => $best, 'score' => $bestScore];
}


/********************** UPDATE STATUS **********************/
function updateStatus($systemKey, $input) {
    global $SYSTEMS;
    $sys = $SYSTEMS[$systemKey];
    $otherKey = $systemKey === 'mtk' ? 'arp' : 'mtk';
    $otherSys = $SYSTEMS[$otherKey];
    
    // ❌ งานปิดแล้ว
    if (getProperty('ALL_DONE') === 'true') {
        pushLine("🎉 วันนี้ปิดงานเรียบร้อยแล้วครับ 🙌\nขอบคุณทุกคนมาก ๆ เลย\nถ้าต้องแก้ไขเพิ่มเติม ทัก PIC ได้เลยนะครับ 🙂");
        return;
    }
    
    $data = getCampaigns();
    $col = $sys['statusCol'];
    $inputs = array_filter(array_map('trim', explode(',', mb_strtolower($input))));
    $campaigns = array_column($data, 'name');
    
    $exactMatches = [];
    $suggestions = [];
    $notFound = [];
    
    foreach ($inputs as $name) {
        $exact = null;
        foreach ($campaigns as $c) {
            if (mb_strtolower($c) === $name) {
                $exact = $c;
                break;
            }
        }
        if ($exact) {
            $exactMatches[] = $exact;
        } else {
            $closest = findClosest($name, $campaigns);
            if ($closest['name'] && $closest['score'] <= 2) {
                $suggestions[] = ['input' => $name, 'suggest' => $closest['name']];
            } else {
                $notFound[] = $name;
            }
        }
    }
    
    // Update exact matches
    $updated = [];
    foreach ($data as &$row) {
        if (in_array(mb_strtolower($row['name']), array_map('mb_strtolower', $exactMatches))) {
            $row[$col] = 'Done';
            $updated[] = $row['name'];
        }
    }
    saveCampaigns($data);
    
    // ❌ fuzzy confirm
    if (!empty($suggestions)) {
        setProperty('PENDING_CONFIRM', json_encode([
            'type' => 'FUZZY',
            'systemKey' => $systemKey,
            'names' => array_column($suggestions, 'suggest')
        ]));
        $updatedText = !empty($updated) 
            ? "✅ อัปเดต {$sys['name']} แล้ว:\n" . implode("\n", array_map(fn($n) => "• {$n}", $updated)) . "\n\n"
            : '';
        $suggestText = implode("\n", array_map(fn($s) => "• {$s['input']} → {$s['suggest']}", $suggestions));
        pushLine("{$updatedText}ℹ️ พบชื่อที่อาจจะพิมพ์ผิด:\n{$suggestText}\n\nถ้าถูกต้อง พิมพ์ \"yes\" หรือ \"confirm\" ได้เลยครับ 🙂");
        return;
    }
    
    if (!empty($notFound)) {
        $notFoundText = implode("\n", array_map(fn($n) => "• {$n}", $notFound));
        pushLine("🤔 หาแคมเปญนี้ไม่เจอครับ:\n{$notFoundText}");
        return;
    }
    
    // ✅ update success
    if (count(getPending($systemKey)) !== 0) {
        $mtkP = getProgress('mtk');
        $arpP = getProgress('arp');
        $updatedList = implode("\n", array_map(fn($n) => "• {$n}", $updated));
        pushLine("✅ อัปเดต {$sys['name']} แล้ว:\n{$updatedList}\n\n📊 ภาพรวมตอนนี้:\n\n" . buildStatusBlockBoth());
    }
    
    // ✅ DONE CHECK
    if (count(getPending($systemKey)) === 0) {
        setProperty($sys['doneFlag'], 'true');
        $today = (int)date('w'); // 5 = Friday
        
        // 🟡 FRIDAY → ARP only
        if ($today === 5 && $systemKey === 'arp') {
            pushLine("✅ Asean Weekly Report (AW) เรียบร้อยแล้วครับ\nUQ Buddy สรุปสถานะให้เรียบร้อย\nขอบคุณทุกคนมากครับ 🙂\n\n" . buildStatusText('arp'));
            return;
        }
        
        // 🔵 วันอื่น
        if (getProperty($otherSys['doneFlag']) !== 'true') {
            $p = getProgress($otherKey);
            pushLine("🎉 เก่งมากครับ {$sys['name']} เสร็จแล้ว\n\n➡️ ขั้นตอนถัดไป: {$otherSys['name']}\nUQ Buddy จะคอยดูให้ครับ 👕\nอัปเดตเมื่อพร้อมได้เลยนะครับ\n\nตอนนี้ความคืบหน้าอยู่ที่ ({$p['done']}/{$p['total']})\n" . buildStatusText($otherKey));
        }
    }
    
    // 🎉 ครบทั้ง MTK + ARP
    if (getProperty($SYSTEMS['mtk']['doneFlag']) === 'true' &&
        getProperty($SYSTEMS['arp']['doneFlag']) === 'true' &&
        !getProperty('ALL_DONE')) {
        pushLine("🎉 วันนี้เก่งกันมากครับทุกคน!\n\nMedia Tracking (MK) และ\nAsean Weekly Report (AW)\nเรียบร้อยครบแล้ว 🙌\n\nUQ Buddy ขอพักก่อนนะครับ\nเจอกันใหม่สัปดาห์หน้า 👕🙂");
        setProperty('ALL_DONE', 'true');
    }
}

/********************** REMOVE STATUS **********************/
function removeStatus($systemKey, $input) {
    global $SYSTEMS;
    $sys = $SYSTEMS[$systemKey];
    $col = $sys['statusCol'];
    
    $data = getCampaigns();
    $inputs = array_filter(array_map('trim', explode(',', mb_strtolower($input))));
    $campaigns = array_column($data, 'name');
    
    $exactMatches = [];
    $notFound = [];
    
    foreach ($inputs as $name) {
        $found = false;
        foreach ($campaigns as $c) {
            if (mb_strtolower($c) === $name) {
                $exactMatches[] = $c;
                $found = true;
                break;
            }
        }
        if (!$found) $notFound[] = $name;
    }
    
    if (empty($exactMatches)) {
        $notFoundText = implode("\n", array_map(fn($n) => "• {$n}", $notFound));
        pushLine("🤔 ไม่พบแคมเปญที่ต้องการถอนสถานะ:\n{$notFoundText}");
        return;
    }
    
    $reverted = [];
    foreach ($data as &$row) {
        if (in_array($row['name'], $exactMatches) && $row[$col] === 'Done') {
            $row[$col] = 'Pending';
            $reverted[] = $row['name'];
        }
    }
    saveCampaigns($data);
    
    if (empty($reverted)) {
        pushLine("ℹ️ แคมเปญที่เลือกยังอยู่ในสถานะ Pending อยู่แล้วครับ 🙂");
        return;
    }
    
    // 🔓 ปลด flags
    deleteProperty($sys['doneFlag']);
    deleteProperty('ALL_DONE');
    
    $revertedText = implode("\n", array_map(fn($n) => "• {$n} : 🟢 Done → 🟠 Pending", $reverted));
    pushLine("🔄 ถอนสถานะ {$sys['name']} เรียบร้อยแล้ว:\n{$revertedText}\n\n📊 สถานะปัจจุบัน:\n" . buildStatusText($systemKey));
}

/********************** REPORT **********************/
function reportAllStatus() {
    pushLine("สถานะงานล่าสุดครับ 👇\n\n" . buildStatusBlockBoth());
}

/********************** RESET **********************/
function resetWeeklyFlags() {
    $keysToDelete = ['MTK_DONE', 'ARP_DONE', 'ALL_DONE', 'PENDING_CONFIRM', 'REMIND_OPEN', 
                     'REMIND_DEADLINE', 'LAST_REMIND_HOUR', 'REMIND_ARP_FRI', 'BREAK_TODAY', 'REMIND_ARP_WED'];
    foreach ($keysToDelete as $key) {
        deleteProperty($key);
    }
    
    // Reset sheet status
    $data = getCampaigns();
    foreach ($data as &$row) {
        $row['mtk_status'] = 'Pending';
        $row['arp_status'] = 'Pending';
    }
    saveCampaigns($data);
    
    pushLine("🔄 รีเซ็ตระบบให้เรียบร้อย ✨\nพบกันทุกวันศุกร์และวันจันทร์นะครับ 🙂");
}


/********************** WEBHOOK HANDLER (doPost) **********************/
// รับ webhook จาก LINE
$input = file_get_contents('php://input');
$body = json_decode($input, true);

// ต้องตอบ 200 เสมอ
http_response_code(200);
header('Content-Type: text/plain');

if (!isset($body['events'][0])) {
    echo 'ok';
    exit;
}

$event = $body['events'][0];

// รองรับเฉพาะข้อความ text
if ($event['type'] !== 'message' || $event['message']['type'] !== 'text') {
    echo 'ok';
    exit;
}

$text = mb_strtolower(trim($event['message']['text']));

// 🔒 กัน LINE retry (idempotency)
$eventId = $event['webhookEventId'] ?? $event['replyToken'] ?? '';
if (getProperty('LAST_EVENT_ID') === $eventId) {
    echo 'ok';
    exit;
}
setProperty('LAST_EVENT_ID', $eventId);

// เก็บ userId / userName สำหรับ mention
if (isset($event['source']['userId'])) {
    $profile = getUserProfile($event['source']['userId']);
    if ($profile) {
        setProperty('LAST_USER_ID', $event['source']['userId']);
        setProperty('LAST_USER_NAME', $profile['displayName']);
    }
}

// ⏱ REPORT = STATUS (กันรัว)
if (preg_match('/^(report\s*=\s*status|status\s*=\s*report)$/', $text)) {
    $now = time() * 1000;
    $last = (int)(getProperty('LAST_REPORT_STATUS') ?: 0);
    if ($now - $last < 10000) {
        echo 'ok';
        exit;
    }
    setProperty('LAST_REPORT_STATUS', (string)$now);
    reportAllStatus();
    echo 'ok';
    exit;
}

// REPORT = BREAK
if (preg_match('/^report\s*=\s*break$/', $text)) {
    setProperty('PENDING_CONFIRM', json_encode(['type' => 'BREAK']));
    pushLine("⚠️ ต้องการหยุดการแจ้งเตือนใช่ไหมครับ?\n• UQ Buddy จะไม่ส่งการแจ้งเตือนในวันนี้\n\nถ้ายืนยัน พิมพ์ \"yes\" หรือ \"confirm\"");
    echo 'ok';
    exit;
}

// REPORT = RESUME
if (preg_match('/^report\s*=\s*resume$/', $text)) {
    if (getProperty('BREAK_TODAY') !== 'true') {
        pushLine("ℹ️ ตอนนี้ UQ Buddy อยู่ในโหมดปกติอยู่แล้วครับ 🙂\nยังไม่ได้อยู่ในโหมดพัก (Break)");
        echo 'ok';
        exit;
    }
    setProperty('PENDING_CONFIRM', json_encode(['type' => 'RESUME']));
    pushLine("▶️ ต้องการยกเลิกโหมดพัก (Break) ใช่ไหมครับ?\n✅ UQ Buddy จะกลับมาส่งการแจ้งเตือนตามปกติ\n\nถ้ายืนยัน พิมพ์ \"yes\" หรือ \"confirm\"");
    echo 'ok';
    exit;
}

// REPORT = MODE
if (preg_match('/^report\s*=\s*mode$/', $text)) {
    $isBreak = getProperty('BREAK_TODAY') === 'true';
    pushLine($isBreak 
        ? "🛑 โหมดการทำงานปัจจุบัน: พักรอบ (Break)\nวันนี้ UQ Buddy จะไม่ส่งการแจ้งเตือนใด ๆ"
        : "🟢 โหมดการทำงานปัจจุบัน: ปกติ\nUQ Buddy จะส่งการแจ้งเตือนตามรอบปกติครับ 🙂");
    echo 'ok';
    exit;
}

// REPORT = RESET
if (preg_match('/^report\s*=\s*reset$/', $text)) {
    setProperty('PENDING_CONFIRM', json_encode(['type' => 'RESET']));
    pushLine("⚠️ ต้องการรีเซ็ตระบบประจำสัปดาห์ใช่ไหมครับ?\n\nถ้ายืนยัน พิมพ์ \"yes\" หรือ \"confirm\"");
    echo 'ok';
    exit;
}

// REPORT = COMMAND (HELP)
if (preg_match('/^report\s*=\s*command$/', $text)) {
    pushLine("📘 คำสั่งที่ใช้ได้กับ UQ Buddy

📊 ดูสถานะ
• report = status   → ดูสถานะงานทั้งหมด
• report = mode     → ดูโหมดการทำงาน

⏸ ควบคุมการแจ้งเตือน
• report = break    → พักการแจ้งเตือนวันนี้
• report = resume   → กลับมาแจ้งเตือน

🔄 จัดการรอบงาน
• report = reset    → รีเซ็ตสถานะรายสัปดาห์

📝 อัปเดตงาน
• mk / aw campaign = done
(หลายแคมเปญคั่นด้วย ,)

🔄 แก้ไขสถานะ
• mk / aw campaign = remove
(เปลี่ยน Done → Pending)");
    echo 'ok';
    exit;
}

// CONFIRM (yes / confirm)
if ($text === 'yes' || $text === 'confirm') {
    $pending = getProperty('PENDING_CONFIRM');
    if (!$pending) {
        echo 'ok';
        exit;
    }
    $data = json_decode($pending, true);
    deleteProperty('PENDING_CONFIRM');
    
    if ($data['type'] === 'BREAK') {
        setProperty('BREAK_TODAY', 'true');
        pushLine("🛑 หยุดการแจ้งเตือนในวันนี้แล้ว\nUQ Buddy จะกลับมาดูแลตามปกติในรอบถัดไปนะครับ 🙂");
        echo 'ok';
        exit;
    }
    if ($data['type'] === 'RESET') {
        resetWeeklyFlags();
        echo 'ok';
        exit;
    }
    if ($data['type'] === 'RESUME') {
        deleteProperty('BREAK_TODAY');
        pushLine("🟢 กลับมาโหมดปกติแล้วครับ 🙂\nUQ Buddy จะกลับมาส่งการแจ้งเตือนตามรอบปกติครับ 👕");
        echo 'ok';
        exit;
    }
    if ($data['type'] === 'FUZZY') {
        updateStatus($data['systemKey'], implode(',', $data['names']));
        echo 'ok';
        exit;
    }
}

// REMOVE STATUS (mk / aw = remove | undo | pending)
if (preg_match('/(mk|mtk|aw|arp)\s+(.+?)\s*=\s*(remove|undo|pending)/', $text, $match)) {
    $systemKey = $SYSTEM_ALIAS[$match[1]];
    removeStatus($systemKey, $match[2]);
    echo 'ok';
    exit;
}

// UPDATE STATUS (mk / aw = done)
if (preg_match('/(mk|mtk|aw|arp)\s+(.+?)\s*=\s*done/', $text, $match)) {
    $systemKey = $SYSTEM_ALIAS[$match[1]];
    updateStatus($systemKey, $match[2]);
}

echo 'ok';
