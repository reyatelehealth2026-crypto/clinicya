<?php
/**
 * API: Sidebar AI Assistant
 * Context-aware AI assistant for admin sidebar
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['admin_user']['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$db = Database::getInstance()->getConnection();

// Get request
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';
$pagePath = $input['page'] ?? '';
$pageTitle = $input['pageTitle'] ?? '';

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message is required']);
    exit;
}

// Page Knowledge Base - ข้อมูลทุกหน้าในระบบ
$pageKnowledge = [
    // === Insights & Overview ===
    'activity-logs' => [
        'name' => 'Activity Logs',
        'desc' => 'ดูประวัติการใช้งานระบบทั้งหมด',
        'features' => ['ดูว่าใครเข้าสู่ระบบเมื่อไหร่', 'ดูการแก้ไขข้อมูล', 'ตรวจสอบความปลอดภัย', 'กรองตามวันที่/ผู้ใช้'],
        'tips' => 'ใช้ตรวจสอบว่าใครทำอะไรในระบบ เหมาะสำหรับ audit และความปลอดภัย'
    ],
    'analytics' => [
        'name' => 'สถิติทั่วไป',
        'desc' => 'ดูรายงานและสถิติการใช้งานระบบ',
        'features' => ['ดูยอดขายรวม', 'จำนวนลูกค้า', 'ข้อความที่ได้รับ', 'กราฟแนวโน้ม'],
        'tips' => 'ดูภาพรวมธุรกิจได้ที่นี่'
    ],
    'dashboard' => [
        'name' => 'Dashboard',
        'desc' => 'แดชบอร์ดรวม Executive และ CRM',
        'features' => ['ภาพรวมยอดขาย', 'KPI สำคัญ', 'CRM Dashboard', 'Auto Tag Rules'],
        'tips' => 'เหมาะสำหรับดูภาพรวมธุรกิจ ใช้ tabs เพื่อสลับระหว่าง Executive และ CRM'
    ],
    
    // === Clinical Station ===
    'drug-interactions' => [
        'name' => 'ตรวจสอบยาตีกัน',
        'desc' => 'ตรวจสอบปฏิกิริยาระหว่างยา (Drug Interactions)',
        'features' => ['เพิ่มคู่ยาที่ตีกัน', 'ระดับความรุนแรง (Severe/Moderate)', 'คำแนะนำการใช้', 'ค้นหายา'],
        'tips' => 'เพิ่มข้อมูลยาตีกันเพื่อแจ้งเตือนเภสัชกรเมื่อจ่ายยา กดปุ่ม "+ เพิ่มข้อมูล" เพื่อเพิ่มคู่ยาใหม่'
    ],
    'pharmacist-dashboard' => [
        'name' => 'Dashboard เภสัชกร',
        'desc' => 'ภาพรวมงานเภสัชกรประจำวัน',
        'features' => ['นัดหมายวันนี้', 'Video Call ที่รอ', 'สถิติการให้คำปรึกษา', 'คิวรอรับยา'],
        'tips' => 'เริ่มต้นวันด้วยการดูนัดหมายและ Video Call ที่รอ'
    ],
    'appointments-admin' => [
        'name' => 'นัดหมาย',
        'desc' => 'จัดการนัดหมายกับลูกค้า',
        'features' => ['ดูนัดหมายทั้งหมด', 'สร้างนัดหมายใหม่', 'แก้ไข/ยกเลิก', 'ส่งแจ้งเตือน'],
        'tips' => 'สร้างนัดหมายแล้วระบบจะส่งแจ้งเตือนลูกค้าอัตโนมัติ'
    ],
    'video-call-pro' => [
        'name' => 'Video Call Pro',
        'desc' => 'ระบบ Video Call สำหรับให้คำปรึกษา',
        'features' => ['รับสาย Video Call', 'บันทึกประวัติ', 'แชร์หน้าจอ', 'ส่งไฟล์'],
        'tips' => 'ใช้ให้คำปรึกษาลูกค้าทางไกล'
    ],
    
    // === Patient & Journey ===
    'inbox' => [
        'name' => 'กล่องข้อความ',
        'desc' => 'ตอบแชทลูกค้าจาก LINE แบบ real-time',
        'features' => ['ดูข้อความใหม่', 'ตอบกลับลูกค้า', 'ดูประวัติแชท', 'ใช้ AI ช่วยตอบ'],
        'tips' => 'กดที่ชื่อลูกค้าเพื่อดูประวัติและข้อมูลเพิ่มเติม'
    ],
    'users' => [
        'name' => 'รายชื่อลูกค้า',
        'desc' => 'จัดการข้อมูลลูกค้าทั้งหมด',
        'features' => ['ค้นหาลูกค้า', 'ดูประวัติการซื้อ', 'เพิ่มแท็ก', 'ดูข้อมูลติดต่อ'],
        'tips' => 'คลิกที่ชื่อลูกค้าเพื่อดูรายละเอียดและประวัติ'
    ],
    'user-tags' => [
        'name' => 'แท็กลูกค้า',
        'desc' => 'จัดการแท็กสำหรับแบ่งกลุ่มลูกค้า',
        'features' => ['สร้างแท็กใหม่', 'ตั้งสี', 'ดูจำนวนลูกค้าในแท็ก', 'ลบแท็ก'],
        'tips' => 'ใช้แท็กแบ่งกลุ่มลูกค้า เช่น VIP, ลูกค้าใหม่, สนใจสินค้า X'
    ],
    'customer-segments' => [
        'name' => 'กลุ่มเป้าหมาย',
        'desc' => 'สร้างกลุ่มลูกค้าตามเงื่อนไข',
        'features' => ['สร้าง Segment', 'ตั้งเงื่อนไข', 'ใช้กับ Broadcast', 'อัพเดทอัตโนมัติ'],
        'tips' => 'สร้าง Segment เช่น "ซื้อเกิน 3 ครั้ง" แล้วใช้ส่ง Broadcast เฉพาะกลุ่ม'
    ],
    'broadcast' => [
        'name' => 'Broadcast',
        'desc' => 'ส่งข้อความหาลูกค้าหลายคนพร้อมกัน',
        'features' => ['เลือกกลุ่มเป้าหมาย', 'สร้างข้อความ', 'ตั้งเวลาส่ง', 'ดูสถิติ'],
        'tips' => 'เลือก Segment ก่อนส่งเพื่อส่งถึงกลุ่มที่ต้องการ'
    ],
    'drip-campaigns' => [
        'name' => 'Drip Campaign',
        'desc' => 'ส่งข้อความอัตโนมัติตามลำดับ',
        'features' => ['สร้างแคมเปญ', 'ตั้งเวลาส่งแต่ละข้อความ', 'Trigger อัตโนมัติ', 'ดูสถิติ'],
        'tips' => 'ใช้ส่งข้อความต้อนรับลูกค้าใหม่แบบอัตโนมัติ'
    ],
    'members' => [
        'name' => 'จัดการสมาชิก',
        'desc' => 'ระบบสมาชิกและแต้มสะสม',
        'features' => ['ดูข้อมูลสมาชิก', 'ปรับแต้ม', 'ดูประวัติแลกของรางวัล', 'ระดับสมาชิก'],
        'tips' => 'ดูแต้มสะสมและประวัติการแลกของรางวัลได้ที่นี่'
    ],
    'admin-rewards' => [
        'name' => 'ของรางวัล',
        'desc' => 'จัดการของรางวัลแลกแต้ม',
        'features' => ['เพิ่มของรางวัล', 'ตั้งแต้มที่ใช้แลก', 'จำกัดจำนวน', 'เปิด/ปิดใช้งาน'],
        'tips' => 'สร้างของรางวัลที่น่าสนใจเพื่อกระตุ้นให้ลูกค้าสะสมแต้ม'
    ],
    
    // === Supply & Revenue ===
    'orders' => [
        'name' => 'ออเดอร์',
        'desc' => 'จัดการคำสั่งซื้อจากลูกค้า',
        'features' => ['ดูออเดอร์ใหม่', 'ตรวจสลิป', 'อัพเดทสถานะ', 'พิมพ์ใบเสร็จ'],
        'tips' => 'ตรวจสอบออเดอร์ใหม่และอัพเดทสถานะเป็นประจำ'
    ],
    'products' => [
        'name' => 'สินค้า',
        'desc' => 'จัดการสินค้าในร้าน',
        'features' => ['เพิ่ม/แก้ไขสินค้า', 'ตั้งราคา', 'จัดการสต็อก', 'อัพโหลดรูป'],
        'tips' => 'เพิ่มรูปสินค้าที่สวยงามเพื่อดึงดูดลูกค้า'
    ],
    'categories' => [
        'name' => 'หมวดหมู่',
        'desc' => 'จัดการหมวดหมู่สินค้า',
        'features' => ['สร้างหมวดหมู่', 'จัดลำดับ', 'ตั้งรูปภาพ', 'เปิด/ปิดใช้งาน'],
        'tips' => 'จัดหมวดหมู่ให้ชัดเจนเพื่อให้ลูกค้าหาสินค้าง่าย'
    ],
    'stock-adjustment' => [
        'name' => 'ปรับสต็อก',
        'desc' => 'ปรับจำนวนสต็อกสินค้า',
        'features' => ['เพิ่ม/ลดสต็อก', 'บันทึกเหตุผล', 'ดูประวัติ'],
        'tips' => 'ใช้เมื่อต้องปรับสต็อกจากการนับจริงหรือสินค้าเสียหาย'
    ],
    'low-stock' => [
        'name' => 'สินค้าใกล้หมด',
        'desc' => 'ดูสินค้าที่สต็อกต่ำ',
        'features' => ['รายการสินค้าใกล้หมด', 'ตั้งค่าแจ้งเตือน', 'สั่งซื้อเพิ่ม'],
        'tips' => 'ตรวจสอบเป็นประจำเพื่อไม่ให้สินค้าขาดสต็อก'
    ],
    'promotions' => [
        'name' => 'โปรโมชั่น',
        'desc' => 'จัดการโปรโมชั่นและส่วนลด',
        'features' => ['สร้างโปรโมชั่น', 'ตั้งเงื่อนไข', 'กำหนดระยะเวลา', 'สร้างคูปอง'],
        'tips' => 'สร้างโปรโมชั่นเพื่อกระตุ้นยอดขาย'
    ],
    
    // === Facility Setup ===
    'line-accounts' => [
        'name' => 'บัญชี LINE',
        'desc' => 'เชื่อมต่อและจัดการบัญชี LINE OA',
        'features' => ['เพิ่ม LINE Account', 'ตั้งค่า Webhook', 'ดูสถานะการเชื่อมต่อ'],
        'tips' => 'ใส่ Channel Access Token และ Channel Secret จาก LINE Developers Console'
    ],
    'liff-settings' => [
        'name' => 'ตั้งค่า LIFF',
        'desc' => 'ตั้งค่า LIFF App สำหรับ LINE',
        'features' => ['ตั้งค่า LIFF ID', 'เลือกหน้าที่ใช้', 'ทดสอบ LIFF'],
        'tips' => 'สร้าง LIFF App ที่ LINE Developers แล้วนำ LIFF ID มาใส่'
    ],
    'rich-menu' => [
        'name' => 'Rich Menu',
        'desc' => 'สร้างและจัดการเมนู LINE',
        'features' => ['สร้าง Rich Menu', 'ออกแบบปุ่ม', 'ตั้งค่า Action', 'เปิด/ปิดใช้งาน'],
        'tips' => 'ใช้รูปขนาด 2500x1686 หรือ 2500x843 pixels'
    ],
    'ai-settings' => [
        'name' => 'ตั้งค่า API Key',
        'desc' => 'ตั้งค่า API Key สำหรับ AI',
        'features' => ['ใส่ Gemini API Key', 'ทดสอบการเชื่อมต่อ'],
        'tips' => 'สมัคร Gemini API Key ฟรีที่ Google AI Studio'
    ],
    'ai-chat-settings' => [
        'name' => 'AI ตอบแชท',
        'desc' => 'ตั้งค่า AI สำหรับตอบแชทลูกค้า',
        'features' => ['เปิด/ปิด AI', 'ตั้งค่า Prompt', 'กำหนดเงื่อนไข'],
        'tips' => 'ตั้ง Prompt ให้ AI รู้จักร้านและสินค้าของคุณ'
    ],
    'auto-reply' => [
        'name' => 'ตอบอัตโนมัติ',
        'desc' => 'ตั้งค่าข้อความตอบกลับอัตโนมัติ',
        'features' => ['สร้าง Keyword', 'ตั้งข้อความตอบกลับ', 'ใช้ Flex Message'],
        'tips' => 'ตั้ง Keyword ที่ลูกค้าถามบ่อย เช่น "ราคา", "โปรโมชั่น"'
    ],
    'flex-builder' => [
        'name' => 'Flex Builder',
        'desc' => 'สร้างข้อความ Flex Message',
        'features' => ['Drag & Drop', 'Template สำเร็จรูป', 'Preview', 'บันทึกใช้ซ้ำ'],
        'tips' => 'ใช้สร้างการ์ดสินค้า โปรโมชั่น หรือข้อความสวยๆ'
    ],
    'ai-studio' => [
        'name' => 'AI Studio',
        'desc' => 'สร้างและจัดการ AI Prompt',
        'features' => ['สร้าง Prompt', 'ทดสอบ', 'บันทึก Template'],
        'tips' => 'สร้าง Prompt สำหรับงานต่างๆ เช่น ตอบลูกค้า สร้างโปรโมชั่น'
    ],
];

// Get page key from path
function getPageKey($path) {
    $path = trim($path, '/');
    $path = preg_replace('/\.php$/', '', $path);
    $parts = explode('/', $path);
    return end($parts);
}

$pageKey = getPageKey($pagePath);
$pageInfo = $pageKnowledge[$pageKey] ?? null;

// Build context-aware response
$response = getContextAwareResponse($message, $pageInfo, $pageTitle, $pageKey, $db, $lineAccountId);

echo json_encode($response);

/**
 * Get context-aware response
 */
function getContextAwareResponse($message, $pageInfo, $pageTitle, $pageKey, $db, $lineAccountId) {
    $message = mb_strtolower($message);
    
    // If we have page info, answer about this specific page
    if ($pageInfo) {
        // Check what user is asking
        if (strpos($message, 'ทำอะไร') !== false || strpos($message, 'ใช้') !== false || strpos($message, 'คือ') !== false) {
            // Asking what this page does
            $features = implode("\n• ", $pageInfo['features']);
            return [
                'success' => true,
                'message' => "**{$pageInfo['name']}** 📋\n\n{$pageInfo['desc']}\n\n**สิ่งที่ทำได้:**\n• {$features}\n\n💡 {$pageInfo['tips']}",
                'ai_source' => 'knowledge'
            ];
        }
        
        if (strpos($message, 'วิธี') !== false || strpos($message, 'แนะนำ') !== false || strpos($message, 'ยังไง') !== false) {
            // Asking for tips
            return [
                'success' => true,
                'message' => "**วิธีใช้ {$pageInfo['name']}** 💡\n\n{$pageInfo['tips']}\n\n**ฟีเจอร์หลัก:**\n• " . implode("\n• ", $pageInfo['features']),
                'ai_source' => 'knowledge'
            ];
        }
        
        if (strpos($message, 'ฟีเจอร์') !== false || strpos($message, 'feature') !== false) {
            // Asking about features
            return [
                'success' => true,
                'message' => "**ฟีเจอร์ของ {$pageInfo['name']}** ✨\n\n• " . implode("\n• ", $pageInfo['features']),
                'ai_source' => 'knowledge'
            ];
        }
        
        // Default: give overview of current page
        return [
            'success' => true,
            'message' => "**{$pageInfo['name']}** 📋\n\n{$pageInfo['desc']}\n\n💡 {$pageInfo['tips']}",
            'ai_source' => 'knowledge'
        ];
    }
    
    // No page info - try Gemini AI
    return callGeminiForHelp($message, $pageTitle, $pageKey, $db, $lineAccountId);
}

/**
 * Call Gemini AI for help
 */
function callGeminiForHelp($message, $pageTitle, $pageKey, $db, $lineAccountId) {
    // Get API key
    $apiKey = null;
    try {
        if ($lineAccountId) {
            $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ?");
            $stmt->execute([$lineAccountId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($result['gemini_api_key'])) {
                $apiKey = $result['gemini_api_key'];
            }
        }
        if (!$apiKey && defined('GEMINI_API_KEY')) {
            $apiKey = GEMINI_API_KEY;
        }
    } catch (Exception $e) {}
    
    if (!$apiKey) {
        return [
            'success' => true,
            'message' => "ผมช่วยคุณได้ครับ! 🚀\n\nคุณอยู่ที่หน้า **{$pageTitle}**\n\nบอกได้เลยว่าต้องการทำอะไร หรือถามคำถามเกี่ยวกับหน้านี้ได้เลยครับ",
            'ai_source' => 'fallback'
        ];
    }
    
    // Call Gemini
    $systemPrompt = "คุณเป็น AI Assistant ระบบ LINE CRM Pro
ผู้ใช้อยู่ที่หน้า: {$pageTitle} ({$pageKey})

กฎการตอบ:
- ตอบสั้นๆ 2-3 ประโยค
- ใช้ภาษาไทย ใช้ emoji
- ตอบเฉพาะเจาะจงกับหน้าที่ผู้ใช้อยู่
- ถ้าไม่รู้ให้บอกตรงๆ";
    
    try {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $apiKey;
        
        $data = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $systemPrompt . "\n\nUser: " . $message]]]
            ],
            'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 512]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $aiMessage = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($aiMessage) {
                return [
                    'success' => true,
                    'message' => $aiMessage,
                    'ai_source' => 'gemini'
                ];
            }
        }
    } catch (Exception $e) {}
    
    return [
        'success' => true,
        'message' => "ผมช่วยคุณได้ครับ! 🚀\n\nคุณอยู่ที่หน้า **{$pageTitle}**\n\nบอกได้เลยว่าต้องการทำอะไรครับ",
        'ai_source' => 'fallback'
    ];
}
