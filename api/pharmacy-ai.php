<?php
/**
 * Pharmacy AI API
 * API สำหรับ LIFF Pharmacy Consultation
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'chat';
$message = $input['message'] ?? '';
$userId = $input['user_id'] ?? null;
$state = $input['state'] ?? 'greeting';
$triageData = $input['triage_data'] ?? [];

// Handle different actions
if ($action === 'log_emergency') {
    logEmergencyAlert($db, $input);
    exit;
}

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'No message']);
    exit;
}

try {
    // Get line_account_id
    $lineAccountId = null;
    $internalUserId = null;
    
    if ($userId) {
        $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $lineAccountId = $user['line_account_id'] ?? null;
        $internalUserId = $user['id'] ?? null;
    }
    
    // Check for emergency symptoms first
    $emergencyCheck = checkEmergencySymptoms($message);
    
    // Process message with built-in AI logic
    $result = processPharmacyMessage($db, $message, $state, $triageData, $lineAccountId);
    
    // Get product recommendations if applicable
    $products = [];
    if (!empty($result['recommend_products'])) {
        $products = getProductRecommendations($db, $result['recommend_products'], $lineAccountId);
    }
    
    echo json_encode([
        'success' => true,
        'response' => $result['response'],
        'state' => $result['state'],
        'data' => $result['data'],
        'quick_replies' => $result['quick_replies'] ?? [],
        'is_critical' => $emergencyCheck['is_critical'] ?? false,
        'emergency_info' => $emergencyCheck['is_critical'] ? $emergencyCheck : null,
        'products' => $products,
        'suggest_pharmacist' => $result['suggest_pharmacist'] ?? false
    ]);
    
} catch (Exception $e) {
    error_log("Pharmacy AI API error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'response' => 'ขออภัยค่ะ เกิดข้อผิดพลาด กรุณาลองใหม่'
    ]);
}

/**
 * Process pharmacy message with built-in logic
 */
function processPharmacyMessage($db, $message, $state, $triageData, $lineAccountId) {
    $lowerMessage = mb_strtolower($message, 'UTF-8');
    
    // Specific follow-up responses (check these FIRST before general symptoms)
    $followUpResponses = [
        // ไอแห้ง
        'ไอแห้ง' => [
            'response' => "ไอแห้งนั้นมักเกิดจากการระคายเคืองหรือภูมิแพ้ค่ะ\n\n💊 ยาที่แนะนำ:\n• Dextromethorphan (ยาระงับไอ)\n• ยาอมแก้ไอ\n\n⚠️ ข้อควรระวัง:\n• หากไอนานเกิน 2 สัปดาห์ ควรพบแพทย์\n• หากมีไข้สูงร่วมด้วย ควรพบแพทย์\n\nต้องการดูยาแก้ไอไหมคะ?",
            'quick_replies' => [
                ['label' => '💊 ดูยาแก้ไอ', 'text' => 'ดูยาแก้ไอแห้ง'],
                ['label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ],
            'state' => 'recommendation',
            'recommend_products' => ['dextromethorphan', 'ยาแก้ไอ', 'ยาอมแก้ไอ']
        ],
        // ไอมีเสมหะ
        'ไอมีเสมหะ' => [
            'response' => "ไอมีเสมหะนั้นร่างกายกำลังขับเสมหะออกค่ะ\n\n💊 ยาที่แนะนำ:\n• Bromhexine หรือ Ambroxol (ยาละลายเสมหะ)\n• N-Acetylcysteine (NAC)\n\n⚠️ ข้อควรระวัง:\n• ไม่ควรใช้ยาระงับไอ เพราะจะทำให้เสมหะคั่งค้าง\n• หากเสมหะเป็นสีเขียว/เหลืองข้น อาจมีการติดเชื้อ\n\nต้องการดูยาละลายเสมหะไหมคะ?",
            'quick_replies' => [
                ['label' => '💊 ดูยาละลายเสมหะ', 'text' => 'ดูยาละลายเสมหะ'],
                ['label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ],
            'state' => 'recommendation',
            'recommend_products' => ['bromhexine', 'ambroxol', 'ยาละลายเสมหะ']
        ],
        // ไอเรื้อรัง
        'ไอเรื้อรัง' => [
            'response' => "⚠️ ไอนานเกิน 2 สัปดาห์ถือว่าเป็นไอเรื้อรังค่ะ\n\nสาเหตุที่พบบ่อย:\n• โรคกรดไหลย้อน (GERD)\n• โรคภูมิแพ้\n• โรคหอบหืด\n• การติดเชื้อ\n\n🏥 แนะนำให้พบแพทย์เพื่อตรวจหาสาเหตุค่ะ\n\nต้องการปรึกษาเภสัชกรก่อนไหมคะ?",
            'quick_replies' => [
                ['label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
                ['label' => '📹 Video Call', 'text' => 'เริ่ม Video Call'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ],
            'state' => 'referral',
            'suggest_pharmacist' => true
        ],
        // เจ็บคอเล็กน้อย
        'เจ็บคอเล็กน้อย' => [
            'response' => "เจ็บคอเล็กน้อยสามารถดูแลเบื้องต้นได้ค่ะ\n\n💊 ยาที่แนะนำ:\n• ยาอมแก้เจ็บคอ (Strepsils, Difflam)\n• ยาพ่นคอ\n\n🏠 การดูแลตัวเอง:\n• ดื่มน้ำอุ่นมากๆ\n• จิบน้ำผึ้งผสมมะนาว\n• พักผ่อนให้เพียงพอ",
            'quick_replies' => [
                ['label' => '💊 ดูยาอมแก้เจ็บคอ', 'text' => 'ดูยาอมแก้เจ็บคอ'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ],
            'state' => 'recommendation',
            'recommend_products' => ['strepsils', 'difflam', 'ยาอมแก้เจ็บคอ']
        ],
        // เจ็บคอมาก
        'เจ็บคอมาก' => [
            'response' => "⚠️ เจ็บคอมากและกลืนลำบากอาจเป็นสัญญาณของการติดเชื้อค่ะ\n\nอาการที่ควรพบแพทย์:\n• กลืนน้ำลายไม่ได้\n• มีไข้สูงร่วมด้วย\n• ต่อมทอนซิลบวมมาก\n\n🏥 แนะนำให้ปรึกษาเภสัชกรหรือพบแพทย์ค่ะ",
            'quick_replies' => [
                ['label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
                ['label' => '📹 Video Call', 'text' => 'เริ่ม Video Call'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ],
            'state' => 'referral',
            'suggest_pharmacist' => true
        ],
        // ปวดหน้าผาก
        'ปวดหน้าผาก' => [
            'response' => "ปวดหน้าผากมักเกิดจากความเครียดหรือไซนัสค่ะ\n\n💊 ยาที่แนะนำ:\n• Paracetamol 500mg ทุก 4-6 ชม.\n• Ibuprofen (ถ้าไม่มีปัญหากระเพาะ)\n\n🏠 การดูแลตัวเอง:\n• พักผ่อน ลดแสงจ้า\n• ประคบเย็นบริเวณหน้าผาก\n• ดื่มน้ำให้เพียงพอ",
            'quick_replies' => [
                ['label' => '💊 ดูยาแก้ปวด', 'text' => 'ดูยาแก้ปวดหัว'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ],
            'state' => 'recommendation',
            'recommend_products' => ['paracetamol', 'ยาแก้ปวด']
        ],
        // ท้องเสีย
        'ถ่าย 3-5 ครั้ง' => [
            'response' => "ท้องเสียระดับปานกลางค่ะ สิ่งสำคัญคือป้องกันการขาดน้ำ\n\n💊 ยาที่แนะนำ:\n• เกลือแร่ ORS (สำคัญมาก!)\n• ยาหยุดถ่าย Loperamide (ถ้าไม่มีไข้)\n\n🏠 การดูแลตัวเอง:\n• ดื่มเกลือแร่ทดแทน\n• กินอาหารอ่อนๆ ย่อยง่าย\n• หลีกเลี่ยงนม ของมัน",
            'quick_replies' => [
                ['label' => '💊 ดูเกลือแร่', 'text' => 'ดูเกลือแร่'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ],
            'state' => 'recommendation',
            'recommend_products' => ['เกลือแร่', 'ORS', 'ยาหยุดถ่าย']
        ],
        // ท้องเสียมาก
        'ถ่ายมากกว่า 5 ครั้ง' => [
            'response' => "⚠️ ท้องเสียมากกว่า 5 ครั้งต้องระวังการขาดน้ำค่ะ\n\n🚨 อาการที่ต้องพบแพทย์ทันที:\n• ปากแห้ง กระหายน้ำมาก\n• ปัสสาวะน้อยลง\n• เวียนศีรษะ อ่อนเพลียมาก\n• มีเลือดปนในอุจจาระ\n\n💊 ระหว่างนี้ให้ดื่มเกลือแร่ทดแทนค่ะ",
            'quick_replies' => [
                ['label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
                ['label' => '💊 ดูเกลือแร่', 'text' => 'ดูเกลือแร่'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ],
            'state' => 'referral',
            'recommend_products' => ['เกลือแร่', 'ORS'],
            'suggest_pharmacist' => true
        ],
        // กลับหน้าหลัก
        'กลับหน้าหลัก' => [
            'response' => "ยินดีให้บริการค่ะ 👋\n\nดิฉันพร้อมช่วยเหลือเรื่อง:\n• ประเมินอาการเบื้องต้น\n• แนะนำยาที่เหมาะสม\n• นัดปรึกษาเภสัชกร\n\nบอกอาการหรือเลือกจากเมนูด้านล่างได้เลยค่ะ",
            'quick_replies' => [
                ['label' => '🤒 มีอาการป่วย', 'text' => 'มีอาการป่วย'],
                ['label' => '💊 ถามเรื่องยา', 'text' => 'ถามเรื่องยา'],
                ['label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
                ['label' => '🏪 ไปร้านค้า', 'text' => 'ไปร้านค้า']
            ],
            'state' => 'greeting'
        ],
        // มีอาการป่วย
        'มีอาการป่วย' => [
            'response' => "บอกอาการที่เป็นได้เลยค่ะ หรือเลือกจากอาการด้านล่าง\n\nอาการที่พบบ่อย:",
            'quick_replies' => [
                ['label' => '🤒 ไข้หวัด', 'text' => 'ไข้หวัด'],
                ['label' => '😷 ไอ เจ็บคอ', 'text' => 'ไอ'],
                ['label' => '🤕 ปวดหัว', 'text' => 'ปวดหัว'],
                ['label' => '🤢 ปวดท้อง', 'text' => 'ปวดท้อง'],
                ['label' => '🤧 แพ้อากาศ', 'text' => 'แพ้อากาศ'],
                ['label' => '😴 นอนไม่หลับ', 'text' => 'นอนไม่หลับ']
            ],
            'state' => 'symptom_selection'
        ]
    ];
    
    // Check follow-up responses FIRST (more specific matches)
    foreach ($followUpResponses as $keyword => $data) {
        if (mb_strpos($lowerMessage, mb_strtolower($keyword, 'UTF-8')) !== false) {
            return [
                'response' => $data['response'],
                'state' => $data['state'],
                'data' => array_merge($triageData, ['follow_up' => $keyword]),
                'quick_replies' => $data['quick_replies'] ?? [],
                'recommend_products' => $data['recommend_products'] ?? [],
                'suggest_pharmacist' => $data['suggest_pharmacist'] ?? false
            ];
        }
    }
    
    // Symptom keywords mapping (general symptoms)
    $symptomResponses = [
        'ปวดหัว' => [
            'response' => "เข้าใจค่ะ อาการปวดหัวนั้นมีหลายสาเหตุ\n\nขอถามเพิ่มเติมนะคะ:\n• ปวดบริเวณไหน? (หน้าผาก, ขมับ, ท้ายทอย)\n• ปวดมานานแค่ไหน?\n• มีอาการอื่นร่วมด้วยไหม? (คลื่นไส้, ตาพร่า)",
            'quick_replies' => [
                ['label' => 'ปวดหน้าผาก', 'text' => 'ปวดหน้าผาก'],
                ['label' => 'ปวดขมับ', 'text' => 'ปวดขมับ'],
                ['label' => 'ปวดท้ายทอย', 'text' => 'ปวดท้ายทอย'],
                ['label' => 'ปวดทั้งศีรษะ', 'text' => 'ปวดทั้งศีรษะ']
            ],
            'state' => 'headache_assessment',
            'recommend_products' => ['paracetamol', 'ยาแก้ปวด']
        ],
        'ไข้หวัด' => [
            'response' => "อาการไข้หวัดนั้นพบได้บ่อยค่ะ\n\nขอถามเพิ่มเติมนะคะ:\n• มีไข้ไหม? ถ้ามี วัดได้กี่องศา?\n• มีน้ำมูกไหม? สีอะไร?\n• ไอไหม? ไอแห้งหรือมีเสมหะ?",
            'quick_replies' => [
                ['label' => 'มีไข้', 'text' => 'มีไข้'],
                ['label' => 'มีน้ำมูก', 'text' => 'มีน้ำมูก'],
                ['label' => 'ไอ', 'text' => 'ไอ'],
                ['label' => 'เจ็บคอ', 'text' => 'เจ็บคอ']
            ],
            'state' => 'cold_assessment',
            'recommend_products' => ['ยาแก้หวัด', 'ยาลดไข้']
        ],
        'ปวดท้อง' => [
            'response' => "อาการปวดท้องมีหลายสาเหตุค่ะ\n\nขอถามเพิ่มเติมนะคะ:\n• ปวดบริเวณไหน? (ท้องบน, ท้องล่าง, รอบสะดือ)\n• ปวดแบบไหน? (จุก, บีบ, แสบ)\n• มีอาการอื่นร่วมด้วยไหม? (ท้องเสีย, คลื่นไส้)",
            'quick_replies' => [
                ['label' => 'ปวดท้องบน', 'text' => 'ปวดท้องบน'],
                ['label' => 'ปวดท้องล่าง', 'text' => 'ปวดท้องล่าง'],
                ['label' => 'ท้องเสีย', 'text' => 'ท้องเสีย'],
                ['label' => 'คลื่นไส้', 'text' => 'คลื่นไส้']
            ],
            'state' => 'stomach_assessment',
            'recommend_products' => ['ยาธาตุน้ำขาว', 'ยาลดกรด']
        ],
        'แพ้อากาศ' => [
            'response' => "อาการแพ้อากาศนั้นน่ารำคาญมากค่ะ\n\nขอถามเพิ่มเติมนะคะ:\n• มีอาการอะไรบ้าง? (จาม, คัดจมูก, คันตา)\n• เป็นบ่อยไหม?\n• มียาที่เคยใช้แล้วได้ผลไหม?",
            'quick_replies' => [
                ['label' => 'จามบ่อย', 'text' => 'จามบ่อย'],
                ['label' => 'คัดจมูก', 'text' => 'คัดจมูก'],
                ['label' => 'คันตา', 'text' => 'คันตา น้ำตาไหล'],
                ['label' => 'ผื่นคัน', 'text' => 'ผื่นคัน']
            ],
            'state' => 'allergy_assessment',
            'recommend_products' => ['ยาแก้แพ้', 'loratadine', 'cetirizine']
        ],
        'ไอ' => [
            'response' => "อาการไอนั้นมีหลายแบบค่ะ\n\nขอถามเพิ่มเติมนะคะ:\n• ไอแห้งหรือมีเสมหะ?\n• ไอมานานแค่ไหน?\n• มีอาการอื่นร่วมด้วยไหม?",
            'quick_replies' => [
                ['label' => 'ไอแห้ง', 'text' => 'ไอแห้ง'],
                ['label' => 'ไอมีเสมหะ', 'text' => 'ไอมีเสมหะ'],
                ['label' => 'ไอเรื้อรัง', 'text' => 'ไอมานานกว่า 2 สัปดาห์']
            ],
            'state' => 'cough_assessment',
            'recommend_products' => ['ยาแก้ไอ', 'ยาละลายเสมหะ']
        ],
        'เจ็บคอ' => [
            'response' => "อาการเจ็บคอนั้นพบได้บ่อยค่ะ\n\nขอถามเพิ่มเติมนะคะ:\n• เจ็บมากแค่ไหน? (เล็กน้อย, ปานกลาง, มาก)\n• กลืนลำบากไหม?\n• มีไข้ร่วมด้วยไหม?",
            'quick_replies' => [
                ['label' => 'เจ็บเล็กน้อย', 'text' => 'เจ็บคอเล็กน้อย'],
                ['label' => 'เจ็บมาก', 'text' => 'เจ็บคอมาก กลืนลำบาก'],
                ['label' => 'มีไข้ด้วย', 'text' => 'เจ็บคอและมีไข้']
            ],
            'state' => 'sore_throat_assessment',
            'recommend_products' => ['ยาอมแก้เจ็บคอ', 'strepsils']
        ],
        'ท้องเสีย' => [
            'response' => "อาการท้องเสียต้องระวังเรื่องการขาดน้ำค่ะ\n\nขอถามเพิ่มเติมนะคะ:\n• ถ่ายกี่ครั้งแล้ว?\n• มีไข้ร่วมด้วยไหม?\n• มีเลือดปนไหม?",
            'quick_replies' => [
                ['label' => 'ถ่าย 3-5 ครั้ง', 'text' => 'ถ่าย 3-5 ครั้ง'],
                ['label' => 'ถ่ายมากกว่า 5 ครั้ง', 'text' => 'ถ่ายมากกว่า 5 ครั้ง'],
                ['label' => 'มีไข้ด้วย', 'text' => 'ท้องเสียและมีไข้']
            ],
            'state' => 'diarrhea_assessment',
            'recommend_products' => ['เกลือแร่', 'ยาหยุดถ่าย']
        ],
        'นอนไม่หลับ' => [
            'response' => "ปัญหาการนอนไม่หลับส่งผลต่อสุขภาพมากค่ะ\n\nขอถามเพิ่มเติมนะคะ:\n• นอนไม่หลับแบบไหน? (หลับยาก, ตื่นกลางดึก)\n• เป็นมานานแค่ไหน?\n• มีความเครียดหรือกังวลอะไรไหม?",
            'quick_replies' => [
                ['label' => 'หลับยาก', 'text' => 'หลับยาก'],
                ['label' => 'ตื่นกลางดึก', 'text' => 'ตื่นกลางดึกบ่อย'],
                ['label' => 'นอนไม่พอ', 'text' => 'นอนไม่พอ ง่วงกลางวัน']
            ],
            'state' => 'sleep_assessment',
            'recommend_products' => ['melatonin', 'ยานอนหลับ']
        ]
    ];
    
    // Check for symptom keywords
    foreach ($symptomResponses as $keyword => $data) {
        if (mb_strpos($lowerMessage, $keyword) !== false) {
            return [
                'response' => $data['response'],
                'state' => $data['state'],
                'data' => array_merge($triageData, ['symptom' => $keyword]),
                'quick_replies' => $data['quick_replies'],
                'recommend_products' => $data['recommend_products'],
                'suggest_pharmacist' => false
            ];
        }
    }
    
    // Check for pharmacist request
    if (mb_strpos($lowerMessage, 'เภสัชกร') !== false || 
        mb_strpos($lowerMessage, 'ปรึกษา') !== false ||
        mb_strpos($lowerMessage, 'video call') !== false) {
        return [
            'response' => "ได้เลยค่ะ เภสัชกรพร้อมให้คำปรึกษาผ่าน Video Call\n\nกดปุ่มด้านล่างเพื่อเริ่มการปรึกษาได้เลยค่ะ",
            'state' => 'pharmacist_request',
            'data' => $triageData,
            'quick_replies' => [
                ['label' => '📹 เริ่ม Video Call', 'text' => 'เริ่ม Video Call'],
                ['label' => '📞 โทรหาเภสัชกร', 'text' => 'โทรหาเภสัชกร']
            ],
            'suggest_pharmacist' => true
        ];
    }
    
    // Check for product inquiry
    if (mb_strpos($lowerMessage, 'ยา') !== false || 
        mb_strpos($lowerMessage, 'สินค้า') !== false ||
        mb_strpos($lowerMessage, 'ราคา') !== false) {
        return [
            'response' => "ต้องการสอบถามเกี่ยวกับยาหรือสินค้าใช่ไหมคะ?\n\nบอกชื่อยาหรืออาการที่ต้องการหายาได้เลยค่ะ หรือจะไปดูที่ร้านค้าก็ได้นะคะ",
            'state' => 'product_inquiry',
            'data' => $triageData,
            'quick_replies' => [
                ['label' => '🏪 ไปร้านค้า', 'text' => 'ไปร้านค้า'],
                ['label' => '💊 ถามเรื่องยา', 'text' => 'ถามเรื่องยา']
            ]
        ];
    }
    
    // Default response
    return [
        'response' => "ขอบคุณที่ติดต่อมาค่ะ\n\nดิฉันพร้อมช่วยเหลือเรื่อง:\n• ประเมินอาการเบื้องต้น\n• แนะนำยาที่เหมาะสม\n• นัดปรึกษาเภสัชกร\n\nบอกอาการหรือเลือกจากเมนูด้านล่างได้เลยค่ะ",
        'state' => 'greeting',
        'data' => $triageData,
        'quick_replies' => [
            ['label' => '🤒 มีอาการป่วย', 'text' => 'มีอาการป่วย'],
            ['label' => '💊 ถามเรื่องยา', 'text' => 'ถามเรื่องยา'],
            ['label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
            ['label' => '🏪 ไปร้านค้า', 'text' => 'ไปร้านค้า']
        ]
    ];
}

/**
 * Check for emergency symptoms
 */
function checkEmergencySymptoms($message) {
    $emergencyKeywords = [
        ['keywords' => ['หายใจไม่ออก', 'หายใจลำบาก', 'หอบ', 'แน่นหน้าอก'], 'symptom' => 'หายใจลำบาก/แน่นหน้าอก'],
        ['keywords' => ['เจ็บหน้าอก', 'แน่นหน้าอก', 'เจ็บอก'], 'symptom' => 'เจ็บหน้าอก'],
        ['keywords' => ['ชัก', 'หมดสติ', 'เป็นลม', 'ไม่รู้สึกตัว'], 'symptom' => 'หมดสติ/ชัก'],
        ['keywords' => ['เลือดออกมาก', 'เลือดไหลไม่หยุด', 'ตกเลือด'], 'symptom' => 'เลือดออกมาก'],
        ['keywords' => ['อัมพาต', 'แขนขาอ่อนแรง', 'พูดไม่ชัด', 'หน้าเบี้ยว'], 'symptom' => 'อาการคล้ายโรคหลอดเลือดสมอง'],
        ['keywords' => ['แพ้ยารุนแรง', 'บวมทั้งตัว', 'ผื่นขึ้นทั้งตัว'], 'symptom' => 'แพ้ยารุนแรง'],
        ['keywords' => ['กินยาเกินขนาด', 'กินยาผิด', 'overdose'], 'symptom' => 'กินยาเกินขนาด']
    ];
    
    $lowerMessage = mb_strtolower($message, 'UTF-8');
    $detectedSymptoms = [];
    
    foreach ($emergencyKeywords as $emergency) {
        foreach ($emergency['keywords'] as $keyword) {
            if (mb_strpos($lowerMessage, $keyword) !== false) {
                $detectedSymptoms[] = $emergency['symptom'];
                break;
            }
        }
    }
    
    if (!empty($detectedSymptoms)) {
        return [
            'is_critical' => true,
            'symptoms' => array_unique($detectedSymptoms),
            'recommendation' => 'พบอาการที่อาจเป็นอันตราย กรุณาติดต่อแพทย์หรือโทร 1669 ทันที'
        ];
    }
    
    return ['is_critical' => false];
}

/**
 * Get product recommendations
 */
function getProductRecommendations($db, $keywords, $lineAccountId) {
    $products = [];
    
    try {
        // Build search query
        $searchTerms = is_array($keywords) ? $keywords : [$keywords];
        $placeholders = [];
        $params = [];
        
        foreach ($searchTerms as $term) {
            $placeholders[] = "name LIKE ? OR description LIKE ? OR generic_name LIKE ?";
            $searchTerm = '%' . $term . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = '(' . implode(' OR ', $placeholders) . ')';
        
        // Try products table first
        $sql = "SELECT id, name, price, sale_price, image_url, 0 as is_prescription 
                FROM products 
                WHERE $whereClause AND is_active = 1 
                ORDER BY sale_price ASC 
                LIMIT 4";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no products found, try business_items
        if (empty($products)) {
            $sql = "SELECT id, name, price, sale_price, image_url, 0 as is_prescription 
                    FROM business_items 
                    WHERE $whereClause AND is_active = 1 
                    ORDER BY sale_price ASC 
                    LIMIT 4";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (Exception $e) {
        error_log("Product recommendation error: " . $e->getMessage());
    }
    
    return $products;
}

/**
 * Log emergency alert
 */
function logEmergencyAlert($db, $input) {
    try {
        $stmt = $db->prepare("INSERT INTO triage_analytics 
            (date, line_account_id, urgent_sessions, top_symptoms) 
            VALUES (CURDATE(), ?, 1, ?)
            ON DUPLICATE KEY UPDATE urgent_sessions = urgent_sessions + 1");
        
        $stmt->execute([
            $input['line_account_id'] ?? null,
            json_encode($input['emergency_info'] ?? [])
        ]);
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
