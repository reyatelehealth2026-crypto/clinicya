<?php
/**
 * Pharmacy AI API - Enhanced Version with AIChat Module Integration
 * API สำหรับ LIFF Pharmacy Consultation พร้อมข้อมูลธุรกิจครบถ้วน
 * 
 * Integrated Features from AIChat Module:
 * - MIMS Knowledge Base (disease/treatment information)
 * - PharmacyRAG (intelligent product search with symptom-drug mapping)
 * - TriageEngine (step-by-step symptom assessment state machine)
 * - DrugInteractionChecker (drug interactions, allergies, contraindications)
 * - Enhanced RedFlagDetector (comprehensive emergency detection)
 * - Gemini API with Function Calling
 * 
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

// Load AIChat modules if available
$aiChatModulesLoaded = false;
$modulesPath = __DIR__ . '/../modules/AIChat';
if (file_exists($modulesPath . '/Autoloader.php')) {
    require_once $modulesPath . '/Autoloader.php';
    if (function_exists('loadAIChatModule')) {
        loadAIChatModule();
        $aiChatModulesLoaded = true;
    }
}

$db = Database::getInstance()->getConnection();

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'chat';
$message = $input['message'] ?? '';
$userId = $input['user_id'] ?? null;
$sessionId = $input['session_id'] ?? null;
$state = $input['state'] ?? 'greeting';
$triageData = $input['triage_data'] ?? [];
$useTriage = $input['use_triage'] ?? false; // Enable triage mode
$useGemini = $input['use_gemini'] ?? false; // Enable Gemini AI
$clientConversationContext = $input['conversation_context'] ?? []; // Client-side conversation context (Requirement 6.5)

// Handle different actions
if ($action === 'log_emergency') {
    logEmergencyAlert($db, $input);
    exit;
}

if ($action === 'get_context') {
    // Return full business context for AI
    echo json_encode(getFullBusinessContext($db, $userId));
    exit;
}

if ($action === 'triage') {
    // Use TriageEngine for step-by-step assessment
    echo json_encode(processTriageMessage($db, $message, $userId, $triageData));
    exit;
}

if ($action === 'check_interactions') {
    // Check drug interactions
    $drugs = $input['drugs'] ?? [];
    $currentMeds = $input['current_medications'] ?? [];
    $allergies = $input['allergies'] ?? [];
    $conditions = $input['medical_conditions'] ?? [];
    echo json_encode(checkDrugInteractions($db, $drugs, $currentMeds, $allergies, $conditions));
    exit;
}

if ($action === 'search_products') {
    // Search products using RAG
    $query = $input['query'] ?? $message;
    $limit = $input['limit'] ?? 10;
    echo json_encode(searchProductsRAG($db, $query, $limit, $userId));
    exit;
}

if ($action === 'get_mims_info') {
    // Get MIMS knowledge base information
    $symptom = $input['symptom'] ?? $message;
    echo json_encode(getMIMSInfo($symptom));
    exit;
}

if ($action === 'get_history') {
    // Get conversation history for user
    echo json_encode(getConversationHistory($db, $userId));
    exit;
}

if ($action === 'clear_history') {
    // Clear conversation history for user
    echo json_encode(clearConversationHistory($db, $userId));
    exit;
}

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'No message']);
    exit;
}

try {
    // Get user data with full context
    $userContext = getUserFullContext($db, $userId);
    $lineAccountId = $userContext['line_account_id'] ?? null;
    $internalUserId = $userContext['id'] ?? null;
    
    // Get business context
    $businessContext = getBusinessContext($db, $lineAccountId);
    
    // ===== RED FLAG DETECTION - Requirements 3.1, 3.4 =====
    // Call RedFlagDetector for every message BEFORE AI processing
    $redFlagsFromDetector = [];
    $redFlagAlertId = null;
    
    if ($aiChatModulesLoaded) {
        // Use the comprehensive RedFlagDetector from AIChat module
        $redFlagDetector = new \Modules\AIChat\Services\RedFlagDetector();
        $redFlagsFromDetector = $redFlagDetector->detect($message);
        
        // Check if critical and log to database (Requirement 3.4)
        if (!empty($redFlagsFromDetector)) {
            $redFlagAlertId = logRedFlagsToDatabase($db, $message, $redFlagsFromDetector, $userId, $lineAccountId);
            error_log("RedFlagDetector: Found " . count($redFlagsFromDetector) . " red flags, alert_id={$redFlagAlertId}");
        }
    }
    
    // Also check for emergency symptoms using the enhanced detection function
    $emergencyCheck = checkEmergencySymptoms($message, $userContext);
    
    // Merge emergency check results with RedFlagDetector results
    if ($emergencyCheck['is_critical'] && empty($redFlagsFromDetector)) {
        // If checkEmergencySymptoms found critical but RedFlagDetector didn't, log it
        $emergencyRedFlags = array_map(function($symptom) {
            return ['message' => $symptom, 'severity' => 'critical'];
        }, $emergencyCheck['symptoms'] ?? []);
        
        if (!empty($emergencyRedFlags)) {
            $redFlagAlertId = logRedFlagsToDatabase($db, $message, $emergencyRedFlags, $userId, $lineAccountId);
        }
    }
    
    // Load conversation history for context (last 10 messages) - Requirement 6.5
    // Prefer server-side history, but use client context as fallback
    $conversationHistory = [];
    if ($userId) {
        $historyResult = getConversationHistoryForContext($db, $userId, 10);
        $conversationHistory = $historyResult['messages'] ?? [];
    }
    
    // If server history is empty but client sent context, use client context
    if (empty($conversationHistory) && !empty($clientConversationContext)) {
        $conversationHistory = array_slice($clientConversationContext, -10); // Limit to last 10
        error_log("Using client-side conversation context: " . count($conversationHistory) . " messages");
    }
    
    // Get or create triage session for session continuity
    $triageSession = null;
    if ($userId) {
        $triageSession = getOrCreateTriageSession($db, $userId, $sessionId, $lineAccountId);
        if ($triageSession) {
            $sessionId = $triageSession['id'];
            // Use session state if not explicitly provided
            if ($state === 'greeting' && $triageSession['current_state'] !== 'greeting') {
                $state = $triageSession['current_state'];
            }
            // Merge triage data from session
            $sessionTriageData = json_decode($triageSession['triage_data'] ?? '{}', true);
            $triageData = array_merge($sessionTriageData ?: [], $triageData);
        }
    }
    
    // Save user message to history with session_id (Requirement 6.1)
    // Now saved after session is retrieved so we have the correct session_id
    saveConversationMessage($db, $userId, 'user', $message, $sessionId);
    
    // Always use Gemini AI for conversation history support
    if ($aiChatModulesLoaded) {
        // Use Gemini AI with function calling and conversation history
        $result = processWithGeminiAI($db, $message, $state, $triageData, $lineAccountId, $userContext, $businessContext, $conversationHistory, $sessionId);
    } else {
        // Fallback to rule-based processing if AI modules not loaded
        $result = processPharmacyMessage($db, $message, $state, $triageData, $lineAccountId, $userContext, $businessContext);
    }
    
    // Save AI response to history with session_id (Requirement 6.1)
    saveConversationMessage($db, $userId, 'assistant', $result['response'], $sessionId);
    
    // Update triage session state if session exists
    $newState = $result['state'] ?? $state;
    $mergedTriageData = $result['data'] ?? $triageData;
    if ($sessionId && $newState) {
        updateTriageSessionState($db, $sessionId, $newState, $mergedTriageData);
        
        // Create or update pharmacist notification when triage has symptoms
        // This ensures all triage sessions appear in pharmacist dashboard
        if (!empty($mergedTriageData['symptoms']) || $newState !== 'greeting') {
            ensureTriageNotification($db, $sessionId, $internalUserId, $lineAccountId, $userContext, $mergedTriageData, $newState);
        }
    }
    
    // Get product recommendations if applicable
    $products = [];
    if (!empty($result['recommend_products'])) {
        $products = getProductRecommendations($db, $result['recommend_products'], $lineAccountId);
        
        // Filter out allergic medications from recommendations (Requirements 7.3)
        $products = filterAllergicMedications($products, $userContext);
    }
    
    // Check drug interactions if products recommended (Requirements 7.1, 7.2, 7.4)
    // Check against both current medications list and health profile text
    $drugInteractions = [];
    if (!empty($products)) {
        $drugInteractions = checkDrugInteractionsSimple($products, $userContext);
    }
    
    echo json_encode([
        'success' => true,
        'response' => $result['response'],
        'state' => $newState,
        'session_id' => $sessionId,
        'data' => $result['data'] ?? $triageData,
        'quick_replies' => $result['quick_replies'] ?? [],
        'is_critical' => $emergencyCheck['is_critical'] ?? false,
        'emergency_info' => $emergencyCheck['is_critical'] ? $emergencyCheck : null,
        'red_flags' => $redFlagsFromDetector,
        'red_flag_alert_id' => $redFlagAlertId,
        'products' => $products,
        'drug_interactions' => $drugInteractions,
        'suggest_pharmacist' => $result['suggest_pharmacist'] ?? false,
        'triage_mode' => $shouldUseTriage ?? false,
        'mims_info' => $result['mims_info'] ?? null,
        'user_context' => [
            'name' => $userContext['display_name'] ?? null,
            'points' => $userContext['points'] ?? 0,
            'tier' => $userContext['tier'] ?? 'bronze',
            'has_allergies' => !empty($userContext['drug_allergies']) || !empty($userContext['drug_allergies_list']),
            'allergies' => $userContext['drug_allergies'] ?? null,
            'allergies_list' => $userContext['drug_allergies_list'] ?? [],
            'conditions' => $userContext['chronic_diseases'] ?? null,
            'has_medications' => !empty($userContext['health_medications']) || !empty($userContext['current_medications_list']),
            'medications_list' => $userContext['current_medications_list'] ?? []
        ]
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
 * Get full user context including health profile, orders, points
 * Enhanced: Requirements 7.1, 7.3 - Load drug allergies and current medications from health profile
 */
function getUserFullContext($db, $lineUserId) {
    if (!$lineUserId) return [];
    
    try {
        // Get user basic info + health data
        $stmt = $db->prepare("
            SELECT u.*, 
                   uhp.allergies as health_allergies,
                   uhp.medical_conditions as health_conditions,
                   uhp.current_medications as health_medications
            FROM users u
            LEFT JOIN user_health_profiles uhp ON u.line_user_id = uhp.line_user_id
            WHERE u.line_user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return [];
        
        // Get drug allergies from user_drug_allergies table (Requirements 7.1, 7.3)
        $user['drug_allergies_list'] = getUserDrugAllergies($db, $lineUserId);
        
        // Get current medications from user_current_medications table (Requirements 7.1)
        $user['current_medications_list'] = getUserCurrentMedications($db, $lineUserId);
        
        // Get recent orders
        $stmt = $db->prepare("
            SELECT t.id, t.order_number, t.total_amount, t.status, t.created_at,
                   GROUP_CONCAT(ti.product_name SEPARATOR ', ') as products
            FROM transactions t
            LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
            WHERE t.user_id = ?
            GROUP BY t.id
            ORDER BY t.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$user['id']]);
        $user['recent_orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get frequently purchased products
        $stmt = $db->prepare("
            SELECT ti.product_name, COUNT(*) as purchase_count
            FROM transaction_items ti
            JOIN transactions t ON ti.transaction_id = t.id
            WHERE t.user_id = ?
            GROUP BY ti.product_name
            ORDER BY purchase_count DESC
            LIMIT 5
        ");
        $stmt->execute([$user['id']]);
        $user['frequent_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get available rewards
        $stmt = $db->prepare("
            SELECT r.name, r.points_required, r.reward_type
            FROM rewards r
            WHERE r.is_active = 1 
            AND r.points_required <= ?
            AND (r.end_date IS NULL OR r.end_date >= CURDATE())
            ORDER BY r.points_required ASC
            LIMIT 3
        ");
        $stmt->execute([$user['points'] ?? 0]);
        $user['available_rewards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $user;
        
    } catch (Exception $e) {
        error_log("getUserFullContext error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user's drug allergies from user_drug_allergies table
 * Requirements: 7.1, 7.3 - Load user's drug allergies for filtering recommendations
 * 
 * @param PDO $db Database connection
 * @param string $lineUserId LINE user ID
 * @return array Array of drug allergy records
 */
function getUserDrugAllergies($db, $lineUserId) {
    try {
        $stmt = $db->prepare("
            SELECT id, drug_name, drug_id, reaction_type, reaction_notes, severity
            FROM user_drug_allergies 
            WHERE line_user_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$lineUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("getUserDrugAllergies error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user's current medications from user_current_medications table
 * Requirements: 7.1 - Load user's current medications for interaction checking
 * 
 * @param PDO $db Database connection
 * @param string $lineUserId LINE user ID
 * @return array Array of current medication records
 */
function getUserCurrentMedications($db, $lineUserId) {
    try {
        $stmt = $db->prepare("
            SELECT id, medication_name, product_id, dosage, frequency, start_date, notes
            FROM user_current_medications 
            WHERE line_user_id = ? AND is_active = 1
            ORDER BY created_at DESC
        ");
        $stmt->execute([$lineUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("getUserCurrentMedications error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get business context - shop info, pharmacists, promotions
 */
function getBusinessContext($db, $lineAccountId) {
    $context = [];
    
    try {
        // Get shop settings
        $stmt = $db->prepare("
            SELECT shop_name, shop_description, shipping_fee, free_shipping_min, 
                   contact_phone, is_open
            FROM shop_settings 
            WHERE line_account_id = ? OR line_account_id IS NULL
            LIMIT 1
        ");
        $stmt->execute([$lineAccountId]);
        $context['shop'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        // Get available pharmacists
        $stmt = $db->prepare("
            SELECT id, name, specialty, rating, is_available
            FROM pharmacists
            WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)
            ORDER BY is_available DESC, rating DESC
            LIMIT 5
        ");
        $stmt->execute([$lineAccountId]);
        $context['pharmacists'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get active promotions/featured products
        $stmt = $db->prepare("
            SELECT id, name, price, sale_price, image_url
            FROM business_items
            WHERE is_active = 1 AND is_featured = 1
            AND (line_account_id = ? OR line_account_id IS NULL)
            ORDER BY sold_count DESC
            LIMIT 6
        ");
        $stmt->execute([$lineAccountId]);
        $context['featured_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get product categories
        $stmt = $db->prepare("
            SELECT id, name, description
            FROM item_categories
            WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL)
            ORDER BY sort_order ASC
            LIMIT 10
        ");
        $stmt->execute([$lineAccountId]);
        $context['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get points settings
        $stmt = $db->prepare("
            SELECT points_per_baht, min_order_for_points
            FROM points_settings
            WHERE line_account_id = ? OR line_account_id IS NULL
            LIMIT 1
        ");
        $stmt->execute([$lineAccountId]);
        $context['points_settings'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['points_per_baht' => 1];
        
        // Get low stock alerts (for pharmacist)
        $stmt = $db->prepare("
            SELECT COUNT(*) as low_stock_count
            FROM business_items
            WHERE is_active = 1 AND stock <= min_stock AND stock > 0
            AND (line_account_id = ? OR line_account_id IS NULL)
        ");
        $stmt->execute([$lineAccountId]);
        $context['low_stock_count'] = $stmt->fetchColumn();
        
        return $context;
        
    } catch (Exception $e) {
        error_log("getBusinessContext error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get full business context for external AI integration
 */
function getFullBusinessContext($db, $lineUserId) {
    $lineAccountId = null;
    
    if ($lineUserId) {
        $stmt = $db->prepare("SELECT line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $lineAccountId = $stmt->fetchColumn();
    }
    
    return [
        'success' => true,
        'user' => getUserFullContext($db, $lineUserId),
        'business' => getBusinessContext($db, $lineAccountId),
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Process pharmacy message with enhanced AI logic
 * Now includes user health profile and business context
 */
function processPharmacyMessage($db, $message, $state, $triageData, $lineAccountId, $userContext = [], $businessContext = []) {
    $lowerMessage = mb_strtolower($message, 'UTF-8');
    
    // Personalized greeting with user data
    $userName = $userContext['display_name'] ?? $userContext['first_name'] ?? '';
    $userPoints = $userContext['points'] ?? 0;
    $userTier = $userContext['tier'] ?? 'bronze';
    $userAllergies = $userContext['drug_allergies'] ?? $userContext['health_allergies'] ?? '';
    $userConditions = $userContext['chronic_diseases'] ?? $userContext['health_conditions'] ?? '';
    $shopName = $businessContext['shop']['shop_name'] ?? 'ร้านยา';
    
    // Check for allergy warnings in product recommendations
    $allergyWarning = '';
    if (!empty($userAllergies)) {
        $allergyWarning = "\n\n⚠️ หมายเหตุ: คุณมีประวัติแพ้ยา: {$userAllergies}\nกรุณาตรวจสอบส่วนประกอบก่อนใช้ยาทุกครั้งค่ะ";
    }
    
    // Check for condition warnings
    $conditionWarning = '';
    if (!empty($userConditions)) {
        $conditionWarning = "\n\n💊 โรคประจำตัว: {$userConditions}\nบางยาอาจไม่เหมาะกับโรคประจำตัวของคุณ กรุณาปรึกษาเภสัชกรก่อนใช้ค่ะ";
    }
    
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
    
    // User account related keywords
    $accountKeywords = [
        'แต้ม' => true, 'แต้มสะสม' => true, 'คะแนน' => true, 'point' => true,
        'ออเดอร์' => true, 'คำสั่งซื้อ' => true, 'order' => true,
        'ยาที่เคยซื้อ' => true, 'ประวัติซื้อ' => true, 'สั่งซ้ำ' => true
    ];
    
    // Check for points inquiry
    if (mb_strpos($lowerMessage, 'แต้ม') !== false || mb_strpos($lowerMessage, 'point') !== false || mb_strpos($lowerMessage, 'คะแนน') !== false) {
        $availableRewards = $userContext['available_rewards'] ?? [];
        $rewardsText = count($availableRewards) > 0 
            ? "\n\n🎁 รางวัลที่แลกได้:\n" . implode("\n", array_map(fn($r) => "• {$r['name']} ({$r['points_required']} แต้ม)", $availableRewards))
            : "\n\nยังไม่มีรางวัลที่แลกได้ในขณะนี้";
        
        return [
            'response' => "🎁 ข้อมูลแต้มสะสมของคุณ" . ($userName ? " คุณ{$userName}" : "") . "\n\n💰 แต้มคงเหลือ: {$userPoints} แต้ม\n⭐ ระดับสมาชิก: {$userTier}{$rewardsText}",
            'state' => 'points_info',
            'data' => $triageData,
            'quick_replies' => [
                ['label' => '🎁 แลกรางวัล', 'text' => 'แลกรางวัล'],
                ['label' => '📜 ประวัติแต้ม', 'text' => 'ประวัติแต้ม'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ]
        ];
    }
    
    // Check for order inquiry
    if (mb_strpos($lowerMessage, 'ออเดอร์') !== false || mb_strpos($lowerMessage, 'คำสั่งซื้อ') !== false || mb_strpos($lowerMessage, 'order') !== false) {
        $recentOrders = $userContext['recent_orders'] ?? [];
        $ordersText = count($recentOrders) > 0 
            ? implode("\n\n", array_map(fn($o) => "🛒 #{$o['order_number']}\n   สถานะ: {$o['status']}\n   ยอด: ฿" . number_format($o['total_amount'], 0), array_slice($recentOrders, 0, 3)))
            : "ยังไม่มีประวัติการสั่งซื้อ";
        
        return [
            'response' => "📦 ออเดอร์ล่าสุดของคุณ\n\n{$ordersText}",
            'state' => 'order_info',
            'data' => $triageData,
            'quick_replies' => [
                ['label' => '🛒 ดูทั้งหมด', 'text' => 'ดูออเดอร์ทั้งหมด'],
                ['label' => '🏪 ไปร้านค้า', 'text' => 'ไปร้านค้า'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ]
        ];
    }
    
    // Check for reorder / purchase history
    if (mb_strpos($lowerMessage, 'ยาที่เคยซื้อ') !== false || mb_strpos($lowerMessage, 'สั่งซ้ำ') !== false || mb_strpos($lowerMessage, 'ประวัติซื้อ') !== false) {
        $frequentProducts = $userContext['frequent_products'] ?? [];
        $productsText = count($frequentProducts) > 0 
            ? implode("\n", array_map(fn($p) => "• {$p['product_name']} (ซื้อ {$p['purchase_count']} ครั้ง)", $frequentProducts))
            : "ยังไม่มีประวัติการซื้อยา";
        
        return [
            'response' => "💊 ยาที่คุณเคยซื้อบ่อย\n\n{$productsText}\n\nต้องการสั่งซื้อซ้ำไหมคะ?",
            'state' => 'reorder',
            'data' => $triageData,
            'quick_replies' => [
                ['label' => '🔄 สั่งซื้อซ้ำ', 'text' => 'สั่งซื้อยาเดิม'],
                ['label' => '🏪 ไปร้านค้า', 'text' => 'ไปร้านค้า'],
                ['label' => '🏠 กลับหน้าหลัก', 'text' => 'กลับหน้าหลัก']
            ]
        ];
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
    
    // Default response - personalized with user data
    $greeting = $userName ? "สวัสดีค่ะ คุณ{$userName} 👋" : "สวัสดีค่ะ 👋";
    $pointsInfo = $userPoints > 0 ? "\n\n🎁 คุณมี {$userPoints} แต้มสะสม" : "";
    $allergyInfo = !empty($userAllergies) ? "\n⚠️ ยาที่แพ้: {$userAllergies}" : "";
    
    return [
        'response' => "{$greeting}\n\nยินดีต้อนรับสู่ {$shopName} ค่ะ\nดิฉันพร้อมช่วยเหลือเรื่อง:\n• ประเมินอาการเบื้องต้น\n• แนะนำยาที่เหมาะสม\n• นัดปรึกษาเภสัชกร{$pointsInfo}{$allergyInfo}\n\nบอกอาการหรือเลือกจากเมนูด้านล่างได้เลยค่ะ",
        'state' => 'greeting',
        'data' => $triageData,
        'quick_replies' => [
            ['label' => '🤒 มีอาการป่วย', 'text' => 'มีอาการป่วย'],
            ['label' => '💊 ถามเรื่องยา', 'text' => 'ถามเรื่องยา'],
            ['label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร'],
            ['label' => '🎁 ดูแต้มสะสม', 'text' => 'ดูแต้มสะสม'],
            ['label' => '🏪 ไปร้านค้า', 'text' => 'ไปร้านค้า']
        ]
    ];
}

/**
 * Check for emergency symptoms - Enhanced version with age-specific flags
 */
function checkEmergencySymptoms($message, $userContext = []) {
    $emergencyKeywords = [
        ['keywords' => ['หายใจไม่ออก', 'หายใจลำบาก', 'หอบ', 'แน่นหน้าอก'], 'symptom' => 'หายใจลำบาก/แน่นหน้าอก', 'severity' => 'critical'],
        ['keywords' => ['เจ็บหน้าอก', 'แน่นหน้าอก', 'เจ็บอก'], 'symptom' => 'เจ็บหน้าอก', 'severity' => 'critical'],
        ['keywords' => ['ชัก', 'หมดสติ', 'เป็นลม', 'ไม่รู้สึกตัว'], 'symptom' => 'หมดสติ/ชัก', 'severity' => 'critical'],
        ['keywords' => ['เลือดออกมาก', 'เลือดไหลไม่หยุด', 'ตกเลือด'], 'symptom' => 'เลือดออกมาก', 'severity' => 'critical'],
        ['keywords' => ['อัมพาต', 'แขนขาอ่อนแรง', 'พูดไม่ชัด', 'หน้าเบี้ยว'], 'symptom' => 'อาการคล้ายโรคหลอดเลือดสมอง', 'severity' => 'critical'],
        ['keywords' => ['แพ้ยารุนแรง', 'บวมทั้งตัว', 'ผื่นขึ้นทั้งตัว', 'หายใจไม่ออกหลังกินยา'], 'symptom' => 'แพ้ยารุนแรง (Anaphylaxis)', 'severity' => 'critical'],
        ['keywords' => ['กินยาเกินขนาด', 'กินยาผิด', 'overdose'], 'symptom' => 'กินยาเกินขนาด', 'severity' => 'critical'],
        ['keywords' => ['ฆ่าตัวตาย', 'ทำร้ายตัวเอง', 'ไม่อยากมีชีวิต'], 'symptom' => 'ความคิดทำร้ายตัวเอง', 'severity' => 'critical'],
        // Additional red flags
        ['keywords' => ['ไข้สูงมาก', 'ไข้ 40', 'ไข้ 41'], 'symptom' => 'ไข้สูงมาก', 'severity' => 'high'],
        ['keywords' => ['ปวดหัวรุนแรง', 'ปวดหัวมากที่สุด', 'คอแข็ง'], 'symptom' => 'ปวดศีรษะรุนแรง (อาจเป็นเยื่อหุ้มสมองอักเสบ)', 'severity' => 'high'],
        ['keywords' => ['อาเจียนเป็นเลือด', 'ถ่ายเป็นเลือด', 'อุจจาระดำ'], 'symptom' => 'เลือดออกในทางเดินอาหาร', 'severity' => 'high'],
        ['keywords' => ['ปัสสาวะไม่ออก', 'ปัสสาวะเป็นเลือด'], 'symptom' => 'ปัญหาระบบทางเดินปัสสาวะรุนแรง', 'severity' => 'high'],
        ['keywords' => ['ตาพร่ามัว', 'มองไม่เห็น', 'เห็นภาพซ้อน'], 'symptom' => 'ปัญหาการมองเห็นเฉียบพลัน', 'severity' => 'high'],
    ];
    
    $lowerMessage = mb_strtolower($message, 'UTF-8');
    $detectedSymptoms = [];
    $maxSeverity = 'low';
    
    foreach ($emergencyKeywords as $emergency) {
        foreach ($emergency['keywords'] as $keyword) {
            if (mb_strpos($lowerMessage, $keyword) !== false) {
                $detectedSymptoms[] = $emergency['symptom'];
                if ($emergency['severity'] === 'critical') {
                    $maxSeverity = 'critical';
                } elseif ($emergency['severity'] === 'high' && $maxSeverity !== 'critical') {
                    $maxSeverity = 'high';
                }
                break;
            }
        }
    }
    
    // Age-specific red flags
    $userAge = $userContext['age'] ?? null;
    if ($userAge !== null) {
        // Elderly (65+) - lower threshold for concern
        if ($userAge >= 65) {
            if (mb_strpos($lowerMessage, 'เวียนหัว') !== false || mb_strpos($lowerMessage, 'หกล้ม') !== false) {
                $detectedSymptoms[] = 'เวียนศีรษะ/หกล้มในผู้สูงอายุ';
                if ($maxSeverity === 'low') $maxSeverity = 'high';
            }
        }
        // Children (<5) - fever is more concerning
        if ($userAge < 5) {
            if (mb_strpos($lowerMessage, 'ไข้') !== false) {
                $detectedSymptoms[] = 'ไข้ในเด็กเล็ก';
                if ($maxSeverity === 'low') $maxSeverity = 'high';
            }
        }
    }
    
    if (!empty($detectedSymptoms)) {
        $isCritical = $maxSeverity === 'critical';
        return [
            'is_critical' => $isCritical,
            'severity' => $maxSeverity,
            'symptoms' => array_unique($detectedSymptoms),
            'recommendation' => $isCritical 
                ? '🚨 พบอาการฉุกเฉิน! กรุณาโทร 1669 หรือไปโรงพยาบาลทันที'
                : '⚠️ พบอาการที่ควรระวัง กรุณาปรึกษาเภสัชกรหรือแพทย์',
            'emergency_contacts' => [
                ['name' => 'สายด่วนฉุกเฉิน', 'number' => '1669'],
                ['name' => 'สายด่วนสุขภาพจิต', 'number' => '1323'],
                ['name' => 'ศูนย์พิษวิทยา', 'number' => '1367']
            ]
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
 * Log emergency alert to emergency_alerts table
 * Requirements: 3.4 - Log red flags to database for pharmacist review
 * 
 * @param PDO $db Database connection
 * @param array $input Input data containing emergency info
 * @return array Result of logging operation
 */
function logEmergencyAlert($db, $input) {
    try {
        // Ensure emergency_alerts table exists
        ensureEmergencyAlertsTable($db);
        
        $userId = $input['user_id'] ?? null;
        $lineAccountId = $input['line_account_id'] ?? null;
        $message = $input['message'] ?? '';
        $redFlags = $input['red_flags'] ?? [];
        $severity = $input['severity'] ?? 'warning';
        $emergencyInfo = $input['emergency_info'] ?? [];
        
        // Get internal user ID if line_user_id provided
        $internalUserId = null;
        if ($userId) {
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $internalUserId = $user['id'] ?? null;
        }
        
        // Insert into emergency_alerts table
        $stmt = $db->prepare("
            INSERT INTO emergency_alerts 
            (user_id, line_account_id, message, red_flags, severity, emergency_info, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $internalUserId,
            $lineAccountId,
            $message,
            json_encode($redFlags),
            $severity,
            json_encode($emergencyInfo)
        ]);
        
        $alertId = $db->lastInsertId();
        
        // Also update triage_analytics for statistics
        try {
            $stmt = $db->prepare("INSERT INTO triage_analytics 
                (date, line_account_id, urgent_sessions, top_symptoms) 
                VALUES (CURDATE(), ?, 1, ?)
                ON DUPLICATE KEY UPDATE urgent_sessions = urgent_sessions + 1");
            
            $stmt->execute([
                $lineAccountId,
                json_encode($emergencyInfo)
            ]);
        } catch (Exception $e) {
            // Ignore triage_analytics errors - not critical
            error_log("logEmergencyAlert triage_analytics error: " . $e->getMessage());
        }
        
        // Create pharmacist notification for critical alerts
        if ($severity === 'critical') {
            createPharmacistNotificationForEmergency($db, $alertId, $internalUserId, $lineAccountId, $redFlags);
        }
        
        error_log("logEmergencyAlert: Created alert #{$alertId}, severity={$severity}");
        
        echo json_encode([
            'success' => true,
            'alert_id' => $alertId,
            'severity' => $severity
        ]);
    } catch (Exception $e) {
        error_log("logEmergencyAlert error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

/**
 * Ensure emergency_alerts table exists
 * Requirements: 3.4 - Create emergency_alerts table for logging red flags
 */
function ensureEmergencyAlertsTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS emergency_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                line_account_id INT NULL,
                message TEXT COMMENT 'Original message that triggered the alert',
                red_flags JSON COMMENT 'Detected red flags array',
                severity ENUM('warning', 'high', 'critical') DEFAULT 'warning',
                emergency_info JSON COMMENT 'Additional emergency information',
                status ENUM('pending', 'reviewed', 'handled', 'dismissed') DEFAULT 'pending',
                reviewed_by INT NULL COMMENT 'Admin user who reviewed',
                reviewed_at TIMESTAMP NULL,
                notes TEXT COMMENT 'Pharmacist notes',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_line_account (line_account_id),
                INDEX idx_severity (severity),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        // Table might already exist
        error_log("ensureEmergencyAlertsTable: " . $e->getMessage());
    }
}

/**
 * Create pharmacist notification for emergency alert
 * Requirements: 3.4 - Notify pharmacist for critical red flags
 */
function createPharmacistNotificationForEmergency($db, $alertId, $userId, $lineAccountId, $redFlags) {
    try {
        // Get user info if available
        $userName = 'ไม่ระบุชื่อ';
        if ($userId) {
            $stmt = $db->prepare("SELECT display_name FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $userName = $user['display_name'] ?? 'ไม่ระบุชื่อ';
        }
        
        // Build notification message
        $flagMessages = [];
        foreach ($redFlags as $flag) {
            if (is_array($flag)) {
                $flagMessages[] = $flag['message'] ?? $flag['symptom'] ?? 'อาการฉุกเฉิน';
            } else {
                $flagMessages[] = $flag;
            }
        }
        
        $title = '🚨 พบอาการฉุกเฉิน - ต้องตรวจสอบด่วน';
        $message = "ลูกค้า: {$userName}\n";
        $message .= "อาการที่ตรวจพบ:\n• " . implode("\n• ", $flagMessages);
        
        // Ensure pharmacist_notifications table exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS pharmacist_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT NULL,
                type VARCHAR(50) DEFAULT 'emergency_alert',
                title VARCHAR(255),
                message TEXT,
                notification_data JSON,
                reference_id INT,
                reference_type VARCHAR(50),
                user_id INT,
                triage_session_id INT NULL,
                priority ENUM('normal', 'urgent') DEFAULT 'normal',
                status ENUM('pending', 'handled', 'dismissed') DEFAULT 'pending',
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_line_account (line_account_id),
                INDEX idx_status (status),
                INDEX idx_priority (priority)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        $stmt = $db->prepare("
            INSERT INTO pharmacist_notifications 
            (line_account_id, type, title, message, notification_data, reference_id, reference_type, user_id, priority)
            VALUES (?, 'emergency_alert', ?, ?, ?, ?, 'emergency_alert', ?, 'urgent')
        ");
        
        $stmt->execute([
            $lineAccountId,
            $title,
            $message,
            json_encode(['red_flags' => $redFlags]),
            $alertId,
            $userId
        ]);
        
        error_log("createPharmacistNotificationForEmergency: Created notification for alert #{$alertId}");
        
    } catch (Exception $e) {
        error_log("createPharmacistNotificationForEmergency error: " . $e->getMessage());
    }
}

/**
 * Log red flags detected by RedFlagDetector
 * Requirements: 3.1, 3.4 - Call detect() before AI processing and log to database
 * 
 * @param PDO $db Database connection
 * @param string $message User message
 * @param array $redFlags Detected red flags from RedFlagDetector
 * @param string|null $lineUserId LINE user ID
 * @param int|null $lineAccountId LINE account ID
 * @return int|null Alert ID if logged, null otherwise
 */
function logRedFlagsToDatabase($db, $message, $redFlags, $lineUserId = null, $lineAccountId = null) {
    if (empty($redFlags)) {
        return null;
    }
    
    try {
        // Ensure emergency_alerts table exists
        ensureEmergencyAlertsTable($db);
        
        // Get internal user ID
        $internalUserId = null;
        if ($lineUserId) {
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$lineUserId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $internalUserId = $user['id'] ?? null;
        }
        
        // Determine severity from red flags
        $severity = 'warning';
        foreach ($redFlags as $flag) {
            $flagSeverity = is_array($flag) ? ($flag['severity'] ?? 'warning') : 'warning';
            if ($flagSeverity === 'critical') {
                $severity = 'critical';
                break;
            } elseif ($flagSeverity === 'high' && $severity !== 'critical') {
                $severity = 'high';
            }
        }
        
        // Build emergency info
        $emergencyInfo = [
            'symptoms' => array_map(function($flag) {
                return is_array($flag) ? ($flag['message'] ?? $flag['symptom'] ?? 'Unknown') : $flag;
            }, $redFlags),
            'detected_at' => date('Y-m-d H:i:s'),
            'is_critical' => $severity === 'critical'
        ];
        
        // Insert into emergency_alerts table
        $stmt = $db->prepare("
            INSERT INTO emergency_alerts 
            (user_id, line_account_id, message, red_flags, severity, emergency_info, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $internalUserId,
            $lineAccountId,
            $message,
            json_encode($redFlags),
            $severity,
            json_encode($emergencyInfo)
        ]);
        
        $alertId = $db->lastInsertId();
        
        // Create pharmacist notification for critical/high severity
        if (in_array($severity, ['critical', 'high'])) {
            createPharmacistNotificationForEmergency($db, $alertId, $internalUserId, $lineAccountId, $redFlags);
        }
        
        error_log("logRedFlagsToDatabase: Logged {$severity} alert #{$alertId} for message: " . substr($message, 0, 50));
        
        return $alertId;
        
    } catch (Exception $e) {
        error_log("logRedFlagsToDatabase error: " . $e->getMessage());
        return null;
    }
}


// ============================================================================
// INTEGRATED FUNCTIONS FROM AICHAT MODULE
// ============================================================================

/**
 * Check if triage mode should be activated based on message content
 */
function shouldActivateTriage($message, $currentState) {
    $triageKeywords = [
        'ซักประวัติ', 'เริ่มซักประวัติ', 'ประเมินอาการ', 'เริ่มประเมิน',
        'triage', 'assessment', 'ปรึกษาอาการ', 'ไม่สบาย'
    ];
    
    $lowerMessage = mb_strtolower($message, 'UTF-8');
    
    foreach ($triageKeywords as $keyword) {
        if (mb_strpos($lowerMessage, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Process message using TriageEngine (step-by-step assessment)
 */
function processTriageMessage($db, $message, $userId, $triageData) {
    global $aiChatModulesLoaded;
    
    if (!$aiChatModulesLoaded) {
        return [
            'success' => false,
            'response' => 'ระบบซักประวัติยังไม่พร้อมใช้งาน กรุณาใช้โหมดปกติ',
            'state' => 'greeting',
            'data' => $triageData
        ];
    }
    
    try {
        $triage = new \Modules\AIChat\Services\TriageEngine(null, $userId, $db);
        $result = $triage->process($message);
        
        return [
            'success' => true,
            'response' => $result['text'] ?? $result['message'] ?? 'ขออภัยค่ะ เกิดข้อผิดพลาด',
            'state' => $result['state'] ?? 'triage',
            'data' => $result['data'] ?? $triageData,
            'quick_replies' => formatQuickReplies($result['quickReplies'] ?? []),
            'recommend_products' => $result['recommend_products'] ?? [],
            'suggest_pharmacist' => $result['suggest_pharmacist'] ?? false,
            'mims_info' => $result['mims_info'] ?? null
        ];
    } catch (Exception $e) {
        error_log("processTriageMessage error: " . $e->getMessage());
        return [
            'success' => false,
            'response' => 'ขออภัยค่ะ ระบบซักประวัติขัดข้อง กรุณาลองใหม่',
            'state' => 'greeting',
            'data' => $triageData
        ];
    }
}

/**
 * Process message using Gemini AI with function calling
 */
function processWithGeminiAI($db, $message, $state, $triageData, $lineAccountId, $userContext, $businessContext, $conversationHistory = [], $sessionId = null) {
    global $aiChatModulesLoaded;
    
    if (!$aiChatModulesLoaded) {
        error_log("processWithGeminiAI: AI modules not loaded, falling back to rule-based");
        return processPharmacyMessage($db, $message, $state, $triageData, $lineAccountId, $userContext, $businessContext);
    }
    
    try {
        $adapter = new \Modules\AIChat\Adapters\PharmacyAIAdapter($db, $lineAccountId);
        
        $internalUserId = $userContext['id'] ?? null;
        if ($internalUserId) {
            $adapter->setUserId($internalUserId);
            error_log("processWithGeminiAI: Set userId to {$internalUserId}");
        } else {
            error_log("processWithGeminiAI: No internal userId found");
        }
        
        // Set conversation history for context
        if (!empty($conversationHistory)) {
            $adapter->setConversationHistory($conversationHistory);
            error_log("processWithGeminiAI: Set conversation history with " . count($conversationHistory) . " messages");
        }
        
        // Set session ID for state tracking
        if ($sessionId) {
            $adapter->setSessionId($sessionId);
            error_log("processWithGeminiAI: Set sessionId to {$sessionId}");
        }
        
        // Set current triage state
        if ($state && $state !== 'greeting') {
            $adapter->setTriageState($state);
            error_log("processWithGeminiAI: Set triage state to {$state}");
        }
        
        if (!$adapter->isEnabled()) {
            error_log("processWithGeminiAI: Gemini not enabled (no API key?), falling back to rule-based");
            return processPharmacyMessage($db, $message, $state, $triageData, $lineAccountId, $userContext, $businessContext);
        }
        
        error_log("processWithGeminiAI: Calling Gemini AI with message: " . substr($message, 0, 50));
        $result = $adapter->processMessage($message);
        error_log("processWithGeminiAI: Got response: " . substr($result['response'] ?? 'no response', 0, 100));
        
        return [
            'response' => $result['response'] ?? 'ขออภัยค่ะ ไม่สามารถประมวลผลได้',
            'state' => $result['state'] ?? $state,
            'data' => array_merge($triageData, $result['data'] ?? []),
            'quick_replies' => formatQuickReplies($result['quick_replies'] ?? []),
            'recommend_products' => extractProductKeywords($result['products'] ?? []),
            'suggest_pharmacist' => $result['suggest_pharmacist'] ?? false,
            'mims_info' => $result['mims_info'] ?? null,
            'session_id' => $sessionId
        ];
    } catch (Exception $e) {
        error_log("processWithGeminiAI error: " . $e->getMessage());
        return processPharmacyMessage($db, $message, $state, $triageData, $lineAccountId, $userContext, $businessContext);
    }
}

/**
 * Search products using PharmacyRAG
 */
function searchProductsRAG($db, $query, $limit, $userId) {
    global $aiChatModulesLoaded;
    
    $result = [
        'success' => true,
        'products' => [],
        'knowledge' => [],
        'suggestions' => []
    ];
    
    if ($aiChatModulesLoaded) {
        try {
            $rag = new \Modules\AIChat\Services\PharmacyRAG($db, null);
            $ragResult = $rag->search($query, $limit);
            
            $result['products'] = $ragResult['products'] ?? [];
            $result['knowledge'] = $ragResult['knowledge'] ?? [];
            $result['suggestions'] = $ragResult['suggestions'] ?? [];
            
            return $result;
        } catch (Exception $e) {
            error_log("searchProductsRAG error: " . $e->getMessage());
        }
    }
    
    // Fallback to basic search
    try {
        $searchTerm = '%' . $query . '%';
        $stmt = $db->prepare("
            SELECT id, name, price, sale_price, image_url, generic_name, usage_instructions, stock
            FROM business_items 
            WHERE is_active = 1 AND (name LIKE ? OR generic_name LIKE ? OR description LIKE ?)
            ORDER BY stock DESC, name
            LIMIT ?
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit]);
        $result['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("searchProductsRAG fallback error: " . $e->getMessage());
    }
    
    return $result;
}

/**
 * Get MIMS Knowledge Base information for a symptom
 */
function getMIMSInfo($symptom) {
    global $aiChatModulesLoaded;
    
    if (!$aiChatModulesLoaded) {
        return ['success' => false, 'error' => 'MIMS module not available'];
    }
    
    try {
        $mims = new \Modules\AIChat\Services\MIMSKnowledgeBase();
        $results = $mims->searchBySymptom($symptom);
        
        return [
            'success' => true,
            'symptom' => $symptom,
            'diseases' => $results,
            'red_flags' => $mims->checkRedFlags($symptom)
        ];
    } catch (Exception $e) {
        error_log("getMIMSInfo error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Check drug interactions using DrugInteractionChecker
 */
function checkDrugInteractions($db, $drugs, $currentMeds, $allergies, $conditions) {
    global $aiChatModulesLoaded;
    
    $result = [
        'success' => true,
        'safe' => true,
        'interactions' => [],
        'allergies' => [],
        'contraindications' => [],
        'warnings' => []
    ];
    
    if ($aiChatModulesLoaded) {
        try {
            $checker = new \Modules\AIChat\Services\DrugInteractionChecker();
            
            // Format drugs for checker
            $drugArray = array_map(function($d) {
                return is_array($d) ? $d : ['name' => $d, 'generic_name' => $d];
            }, $drugs);
            
            $report = $checker->generateSafetyReport($drugArray, [
                'current_medications' => $currentMeds,
                'allergies' => $allergies,
                'medical_conditions' => $conditions
            ]);
            
            return array_merge($result, $report);
        } catch (Exception $e) {
            error_log("checkDrugInteractions error: " . $e->getMessage());
        }
    }
    
    // Basic fallback check
    $result['interactions'] = checkDrugInteractionsBasic($drugs, $currentMeds);
    $result['allergies'] = checkAllergiesBasic($drugs, $allergies);
    
    if (!empty($result['interactions']) || !empty($result['allergies'])) {
        $result['safe'] = false;
    }
    
    return $result;
}

/**
 * Simple drug interaction check for products
 * Enhanced: Requirements 7.1, 7.2, 7.3, 7.4 - Check interactions with user's current medications and filter allergic medications
 * 
 * @param array $products Array of recommended products
 * @param array $userContext User context including allergies and current medications
 * @return array Array of interaction warnings
 */
function checkDrugInteractionsSimple($products, $userContext) {
    $interactions = [];
    
    // Get current medications from health profile (text field) and detailed list
    $currentMeds = $userContext['health_medications'] ?? '';
    $currentMedsList = $userContext['current_medications_list'] ?? [];
    
    // Get allergies from health profile (text field) and detailed list
    $allergies = $userContext['drug_allergies'] ?? $userContext['health_allergies'] ?? '';
    $allergiesList = $userContext['drug_allergies_list'] ?? [];
    
    // Build allergy names array for matching
    $allergyNames = [];
    foreach ($allergiesList as $allergy) {
        $allergyNames[] = mb_strtolower($allergy['drug_name'], 'UTF-8');
    }
    // Also add from text field if present
    if (!empty($allergies)) {
        $allergyNames[] = mb_strtolower($allergies, 'UTF-8');
    }
    
    // Build current medication names array for matching
    $currentMedNames = [];
    foreach ($currentMedsList as $med) {
        $currentMedNames[] = mb_strtolower($med['medication_name'], 'UTF-8');
    }
    // Also add from text field if present
    if (!empty($currentMeds)) {
        $currentMedNames[] = mb_strtolower($currentMeds, 'UTF-8');
    }
    
    if (empty($allergyNames) && empty($currentMedNames)) {
        return $interactions;
    }
    
    // Basic interaction database
    $interactionDb = [
        'warfarin' => ['aspirin', 'ibuprofen', 'naproxen', 'แอสไพริน', 'ไอบูโพรเฟน'],
        'metformin' => ['alcohol', 'แอลกอฮอล์'],
        'aspirin' => ['ibuprofen', 'ไอบูโพรเฟน', 'naproxen'],
        'lisinopril' => ['potassium', 'โพแทสเซียม'],
        'simvastatin' => ['grapefruit', 'เกรปฟรุต'],
        'methotrexate' => ['nsaid', 'ibuprofen', 'aspirin'],
        'digoxin' => ['amiodarone', 'verapamil'],
        'clopidogrel' => ['omeprazole', 'โอเมพราโซล'],
    ];
    
    foreach ($products as $product) {
        $productName = mb_strtolower($product['name'] ?? '', 'UTF-8');
        $genericName = mb_strtolower($product['generic_name'] ?? '', 'UTF-8');
        $productId = $product['id'] ?? null;
        
        // Check allergies - Requirements 7.3
        foreach ($allergiesList as $allergy) {
            $allergyName = mb_strtolower($allergy['drug_name'], 'UTF-8');
            $allergyId = $allergy['drug_id'] ?? null;
            
            // Match by ID if available
            if ($allergyId && $productId && $allergyId == $productId) {
                $interactions[] = [
                    'type' => 'allergy',
                    'severity' => 'high',
                    'product' => $product['name'],
                    'product_id' => $productId,
                    'allergy' => $allergy['drug_name'],
                    'reaction_type' => $allergy['reaction_type'] ?? 'unknown',
                    'message' => "⛔ ห้ามใช้! คุณแพ้ยา {$allergy['drug_name']}"
                ];
                continue 2; // Skip to next product
            }
            
            // Match by name
            if (mb_strpos($productName, $allergyName) !== false || 
                mb_strpos($genericName, $allergyName) !== false ||
                mb_strpos($allergyName, $productName) !== false ||
                mb_strpos($allergyName, $genericName) !== false) {
                $interactions[] = [
                    'type' => 'allergy',
                    'severity' => 'high',
                    'product' => $product['name'],
                    'product_id' => $productId,
                    'allergy' => $allergy['drug_name'],
                    'reaction_type' => $allergy['reaction_type'] ?? 'unknown',
                    'message' => "⛔ ห้ามใช้! คุณแพ้ยา {$allergy['drug_name']}"
                ];
                continue 2; // Skip to next product
            }
        }
        
        // Also check text-based allergies
        if (!empty($allergies)) {
            $allergiesLower = mb_strtolower($allergies, 'UTF-8');
            if (mb_strpos($allergiesLower, $productName) !== false || 
                mb_strpos($allergiesLower, $genericName) !== false ||
                mb_strpos($productName, $allergiesLower) !== false) {
                $interactions[] = [
                    'type' => 'allergy',
                    'severity' => 'high',
                    'product' => $product['name'],
                    'product_id' => $productId,
                    'allergy' => $allergies,
                    'message' => "⛔ ห้ามใช้! คุณแพ้ยานี้"
                ];
                continue; // Skip to next product
            }
        }
        
        // Check interactions with current medications - Requirements 7.1, 7.2
        foreach ($currentMedsList as $med) {
            $medName = mb_strtolower($med['medication_name'], 'UTF-8');
            
            // Check against interaction database
            foreach ($interactionDb as $drug => $interactsWith) {
                if (mb_strpos($medName, $drug) !== false) {
                    foreach ($interactsWith as $interactDrug) {
                        if (mb_strpos($productName, $interactDrug) !== false || 
                            mb_strpos($genericName, $interactDrug) !== false) {
                            $interactions[] = [
                                'type' => 'interaction',
                                'severity' => 'medium',
                                'product' => $product['name'],
                                'product_id' => $productId,
                                'interacts_with' => $med['medication_name'],
                                'message' => "⚠️ {$product['name']} อาจตีกับยา {$med['medication_name']} ที่คุณทานอยู่"
                            ];
                        }
                    }
                }
            }
        }
        
        // Also check text-based current medications
        if (!empty($currentMeds)) {
            $currentMedsLower = mb_strtolower($currentMeds, 'UTF-8');
            foreach ($interactionDb as $drug => $interactsWith) {
                if (mb_strpos($currentMedsLower, $drug) !== false) {
                    foreach ($interactsWith as $interactDrug) {
                        if (mb_strpos($productName, $interactDrug) !== false || 
                            mb_strpos($genericName, $interactDrug) !== false) {
                            $interactions[] = [
                                'type' => 'interaction',
                                'severity' => 'medium',
                                'product' => $product['name'],
                                'product_id' => $productId,
                                'interacts_with' => $drug,
                                'message' => "⚠️ {$product['name']} อาจตีกับยา {$drug} ที่คุณทานอยู่"
                            ];
                        }
                    }
                }
            }
        }
    }
    
    return $interactions;
}

/**
 * Filter out allergic medications from product recommendations
 * Requirements: 7.3 - Filter out allergic medications from recommendations
 * 
 * @param array $products Array of recommended products
 * @param array $userContext User context including allergies
 * @return array Filtered array of products (without allergic medications)
 */
function filterAllergicMedications($products, $userContext) {
    if (empty($products)) return $products;
    
    // Get allergies from detailed list and text field
    $allergiesList = $userContext['drug_allergies_list'] ?? [];
    $allergiesText = $userContext['drug_allergies'] ?? $userContext['health_allergies'] ?? '';
    
    if (empty($allergiesList) && empty($allergiesText)) {
        return $products;
    }
    
    // Build allergy names array for matching
    $allergyNames = [];
    $allergyIds = [];
    foreach ($allergiesList as $allergy) {
        $allergyNames[] = mb_strtolower($allergy['drug_name'], 'UTF-8');
        if (!empty($allergy['drug_id'])) {
            $allergyIds[] = $allergy['drug_id'];
        }
    }
    
    $allergiesTextLower = mb_strtolower($allergiesText, 'UTF-8');
    
    // Filter products
    $filteredProducts = [];
    foreach ($products as $product) {
        $productId = $product['id'] ?? null;
        $productName = mb_strtolower($product['name'] ?? '', 'UTF-8');
        $genericName = mb_strtolower($product['generic_name'] ?? '', 'UTF-8');
        
        $isAllergic = false;
        
        // Check by ID
        if ($productId && in_array($productId, $allergyIds)) {
            $isAllergic = true;
        }
        
        // Check by name against allergy list
        if (!$isAllergic) {
            foreach ($allergyNames as $allergyName) {
                if (mb_strpos($productName, $allergyName) !== false || 
                    mb_strpos($genericName, $allergyName) !== false ||
                    mb_strpos($allergyName, $productName) !== false ||
                    mb_strpos($allergyName, $genericName) !== false) {
                    $isAllergic = true;
                    break;
                }
            }
        }
        
        // Check against text-based allergies
        if (!$isAllergic && !empty($allergiesTextLower)) {
            if (mb_strpos($allergiesTextLower, $productName) !== false || 
                mb_strpos($allergiesTextLower, $genericName) !== false ||
                mb_strpos($productName, $allergiesTextLower) !== false) {
                $isAllergic = true;
            }
        }
        
        if (!$isAllergic) {
            $filteredProducts[] = $product;
        } else {
            error_log("filterAllergicMedications: Filtered out allergic product: " . ($product['name'] ?? 'unknown'));
        }
    }
    
    return $filteredProducts;
}

/**
 * Basic drug interaction check (fallback)
 */
function checkDrugInteractionsBasic($drugs, $currentMeds) {
    $interactions = [];
    
    $interactionDb = [
        'warfarin' => ['aspirin', 'ibuprofen', 'naproxen', 'แอสไพริน', 'ไอบูโพรเฟน'],
        'metformin' => ['alcohol', 'แอลกอฮอล์'],
        'aspirin' => ['ibuprofen', 'ไอบูโพรเฟน'],
    ];
    
    foreach ($drugs as $drug) {
        $drugName = is_array($drug) ? mb_strtolower($drug['name'] ?? '', 'UTF-8') : mb_strtolower($drug, 'UTF-8');
        
        foreach ($currentMeds as $currentMed) {
            $currentMedLower = mb_strtolower($currentMed, 'UTF-8');
            
            foreach ($interactionDb as $med => $interactsWith) {
                if (mb_strpos($currentMedLower, $med) !== false) {
                    foreach ($interactsWith as $interactDrug) {
                        if (mb_strpos($drugName, $interactDrug) !== false) {
                            $interactions[] = [
                                'drug' => is_array($drug) ? $drug['name'] : $drug,
                                'interacts_with' => $currentMed,
                                'severity' => 'medium',
                                'message' => "อาจมีปฏิกิริยาระหว่างยา"
                            ];
                        }
                    }
                }
            }
        }
    }
    
    return $interactions;
}

/**
 * Basic allergy check (fallback)
 */
function checkAllergiesBasic($drugs, $allergies) {
    $warnings = [];
    
    foreach ($drugs as $drug) {
        $drugName = is_array($drug) ? mb_strtolower($drug['name'] ?? '', 'UTF-8') : mb_strtolower($drug, 'UTF-8');
        $genericName = is_array($drug) ? mb_strtolower($drug['generic_name'] ?? '', 'UTF-8') : '';
        
        foreach ($allergies as $allergy) {
            $allergyLower = mb_strtolower($allergy, 'UTF-8');
            
            if (mb_strpos($drugName, $allergyLower) !== false || 
                mb_strpos($genericName, $allergyLower) !== false) {
                $warnings[] = [
                    'drug' => is_array($drug) ? $drug['name'] : $drug,
                    'allergy' => $allergy,
                    'severity' => 'high',
                    'message' => "⛔ ห้ามใช้! คุณแพ้ยา {$allergy}"
                ];
            }
        }
    }
    
    return $warnings;
}

/**
 * Format quick replies from various formats
 */
function formatQuickReplies($quickReplies) {
    if (empty($quickReplies)) return [];
    
    $formatted = [];
    foreach ($quickReplies as $qr) {
        if (isset($qr['action'])) {
            // LINE format
            $formatted[] = [
                'label' => $qr['action']['label'] ?? $qr['label'] ?? '',
                'text' => $qr['action']['text'] ?? $qr['text'] ?? ''
            ];
        } else {
            // Simple format
            $formatted[] = [
                'label' => $qr['label'] ?? $qr['text'] ?? '',
                'text' => $qr['text'] ?? $qr['label'] ?? ''
            ];
        }
    }
    
    return $formatted;
}

/**
 * Extract product keywords from product array
 */
function extractProductKeywords($products) {
    if (empty($products)) return [];
    
    $keywords = [];
    foreach ($products as $product) {
        if (is_array($product)) {
            if (!empty($product['name'])) $keywords[] = $product['name'];
            if (!empty($product['generic_name'])) $keywords[] = $product['generic_name'];
        } else {
            $keywords[] = $product;
        }
    }
    
    return array_unique($keywords);
}


// ============================================================================
// CONVERSATION HISTORY FUNCTIONS
// ============================================================================

/**
 * Get conversation history for user with session_id support
 * Requirements: 6.1, 6.2, 6.3, 9.5 - Load previous conversation history with user isolation and session state
 * 
 * User Isolation (Requirement 6.3):
 * - History is strictly filtered by user_id derived from line_user_id
 * - Each user can only access their own conversation history
 * - No cross-user data leakage is possible
 */
function getConversationHistory($db, $lineUserId) {
    if (!$lineUserId) {
        return ['success' => false, 'error' => 'No user ID', 'messages' => []];
    }
    
    try {
        // Get user's internal ID - this ensures user isolation (Requirement 6.3)
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => true, 'messages' => []];
        }
        
        $userId = $user['id'];
        
        // Ensure session_id column exists in ai_conversation_history table
        ensureConversationHistorySessionColumn($db);
        
        // Get recent conversation history (last 50 messages) with session_id
        // User isolation: WHERE user_id = ? ensures only this user's messages are returned
        $stmt = $db->prepare("
            SELECT role, content, session_id, created_at 
            FROM ai_conversation_history 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Reverse to get chronological order
        $messages = array_reverse($messages);
        
        // Format for frontend with session_id included (Requirement 6.1)
        $formatted = [];
        foreach ($messages as $msg) {
            $formatted[] = [
                'type' => $msg['role'] === 'user' ? 'user' : 'ai',
                'content' => $msg['content'],
                'session_id' => $msg['session_id'],
                'timestamp' => $msg['created_at']
            ];
        }
        
        // Get active triage session state for session continuity (Requirement 9.5)
        $sessionId = null;
        $currentState = 'greeting';
        $triageData = [];
        
        $stmt = $db->prepare("
            SELECT id, current_state, triage_data 
            FROM triage_sessions 
            WHERE user_id = ? AND status = 'active'
            ORDER BY updated_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session) {
            $sessionId = $session['id'];
            $currentState = $session['current_state'] ?? 'greeting';
            $triageData = json_decode($session['triage_data'] ?? '{}', true) ?: [];
        }
        
        return [
            'success' => true, 
            'messages' => $formatted,
            'session_id' => $sessionId,
            'current_state' => $currentState,
            'triage_data' => $triageData,
            'user_id' => $userId // Include for verification
        ];
        
    } catch (Exception $e) {
        error_log("getConversationHistory error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage(), 'messages' => []];
    }
}

/**
 * Ensure ai_conversation_history table has session_id column
 * Requirement 6.1 - Add session_id to history records
 */
function ensureConversationHistorySessionColumn($db) {
    try {
        $db->query("SELECT session_id FROM ai_conversation_history LIMIT 1");
    } catch (Exception $e) {
        // Add session_id column if it doesn't exist
        try {
            $db->exec("ALTER TABLE ai_conversation_history ADD COLUMN session_id VARCHAR(50) NULL AFTER line_account_id");
            $db->exec("CREATE INDEX idx_session_id ON ai_conversation_history (session_id)");
        } catch (Exception $alterError) {
            // Column might already exist or table doesn't exist yet
            error_log("ensureConversationHistorySessionColumn: " . $alterError->getMessage());
        }
    }
}

/**
 * Save conversation message with session_id support
 * Requirement 6.1 - Save message to ai_conversation_history with user_id, role, and session_id
 */
function saveConversationMessage($db, $lineUserId, $role, $content, $sessionId = null) {
    if (!$lineUserId) return false;
    
    try {
        // Get user's internal ID
        $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return false;
        
        // Check if table exists, create if not
        try {
            $db->query("SELECT 1 FROM ai_conversation_history LIMIT 1");
        } catch (Exception $e) {
            // Create table with session_id column
            $db->exec("
                CREATE TABLE IF NOT EXISTS ai_conversation_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    line_account_id INT NULL,
                    session_id VARCHAR(50) NULL,
                    role ENUM('user', 'assistant') NOT NULL,
                    content TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_session_id (session_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        // Ensure session_id column exists
        ensureConversationHistorySessionColumn($db);
        
        // Insert message with session_id (Requirement 6.1)
        $stmt = $db->prepare("
            INSERT INTO ai_conversation_history (user_id, line_account_id, session_id, role, content) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $user['line_account_id'], $sessionId, $role, $content]);
        
        // Clean up old messages (keep last 100 per user)
        $stmt = $db->prepare("
            DELETE FROM ai_conversation_history 
            WHERE user_id = ? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM ai_conversation_history 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 100
                ) as recent
            )
        ");
        $stmt->execute([$user['id'], $user['id']]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("saveConversationMessage error: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear conversation history for specific user only
 * Requirement 6.3 - Delete conversation history for that user only from database
 * 
 * User Isolation:
 * - Only deletes messages belonging to the specified user
 * - Other users' histories remain completely unchanged
 * - Uses user_id derived from line_user_id for strict isolation
 */
function clearConversationHistory($db, $lineUserId) {
    if (!$lineUserId) {
        return ['success' => false, 'error' => 'No user ID'];
    }
    
    try {
        // Get user's internal ID - this ensures user isolation (Requirement 6.3)
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => true, 'message' => 'No history to clear'];
        }
        
        $userId = $user['id'];
        
        // Count messages before deletion for verification
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM ai_conversation_history WHERE user_id = ?");
        $stmt->execute([$userId]);
        $beforeCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Delete all messages for this specific user only (Requirement 6.3)
        // The WHERE user_id = ? clause ensures only this user's messages are deleted
        $stmt = $db->prepare("DELETE FROM ai_conversation_history WHERE user_id = ?");
        $stmt->execute([$userId]);
        $deletedCount = $stmt->rowCount();
        
        // Also clear any active triage sessions for this user
        $stmt = $db->prepare("
            UPDATE triage_sessions 
            SET status = 'cancelled', updated_at = NOW() 
            WHERE user_id = ? AND status = 'active'
        ");
        $stmt->execute([$userId]);
        
        return [
            'success' => true, 
            'message' => 'History cleared',
            'deleted_count' => $deletedCount,
            'user_id' => $userId
        ];
        
    } catch (Exception $e) {
        error_log("clearConversationHistory error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


// ============================================================================
// SESSION MANAGEMENT FUNCTIONS
// ============================================================================

/**
 * Get conversation history for context (last N messages) with session_id
 * Used to provide context to AI for session continuity
 * Requirement 6.5 - Include last 10 conversation messages as context
 */
function getConversationHistoryForContext($db, $lineUserId, $limit = 10) {
    if (!$lineUserId) {
        return ['success' => false, 'messages' => []];
    }
    
    try {
        // Get user's internal ID - ensures user isolation
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => true, 'messages' => []];
        }
        
        // Ensure session_id column exists
        ensureConversationHistorySessionColumn($db);
        
        // Get recent conversation history (last N messages) with session_id
        // User isolation: WHERE user_id = ? ensures only this user's messages
        $stmt = $db->prepare("
            SELECT role, content, session_id, created_at 
            FROM ai_conversation_history 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$user['id'], $limit]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Reverse to get chronological order
        $messages = array_reverse($messages);
        
        return ['success' => true, 'messages' => $messages];
        
    } catch (Exception $e) {
        error_log("getConversationHistoryForContext error: " . $e->getMessage());
        return ['success' => false, 'messages' => []];
    }
}

/**
 * Get or create triage session for session continuity
 */
function getOrCreateTriageSession($db, $lineUserId, $sessionId = null, $lineAccountId = null) {
    if (!$lineUserId) {
        return null;
    }
    
    try {
        // Get user's internal ID
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return null;
        }
        
        $userId = $user['id'];
        
        // Ensure triage_sessions table exists
        ensureTriageSessionsTable($db);
        
        // If session_id provided, try to load it
        if ($sessionId) {
            $stmt = $db->prepare("
                SELECT * FROM triage_sessions 
                WHERE id = ? AND user_id = ? AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$sessionId, $userId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                return $session;
            }
        }
        
        // Try to find existing active session for user
        $stmt = $db->prepare("
            SELECT * FROM triage_sessions 
            WHERE user_id = ? AND status = 'active'
            ORDER BY updated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session) {
            return $session;
        }
        
        // Create new session
        $stmt = $db->prepare("
            INSERT INTO triage_sessions (user_id, line_account_id, current_state, triage_data, status)
            VALUES (?, ?, 'greeting', '{}', 'active')
        ");
        $stmt->execute([$userId, $lineAccountId]);
        
        $newSessionId = $db->lastInsertId();
        
        // Return the new session
        $stmt = $db->prepare("SELECT * FROM triage_sessions WHERE id = ?");
        $stmt->execute([$newSessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("getOrCreateTriageSession error: " . $e->getMessage());
        return null;
    }
}

/**
 * Update triage session state
 */
function updateTriageSessionState($db, $sessionId, $state, $triageData = null) {
    if (!$sessionId) {
        return false;
    }
    
    try {
        if ($triageData !== null) {
            $stmt = $db->prepare("
                UPDATE triage_sessions 
                SET current_state = ?, triage_data = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$state, json_encode($triageData), $sessionId]);
        } else {
            $stmt = $db->prepare("
                UPDATE triage_sessions 
                SET current_state = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$state, $sessionId]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("updateTriageSessionState error: " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure triage_sessions table exists
 */
function ensureTriageSessionsTable($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS triage_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                line_account_id INT NULL,
                current_state VARCHAR(50) DEFAULT 'greeting',
                triage_data JSON,
                status ENUM('active', 'completed', 'escalated', 'cancelled') DEFAULT 'active',
                priority ENUM('normal', 'urgent') DEFAULT 'normal',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        // Table might already exist, ignore error
        error_log("ensureTriageSessionsTable: " . $e->getMessage());
    }
}

/**
 * Save conversation message with session_id
 */
function saveConversationMessageWithSession($db, $lineUserId, $role, $content, $sessionId = null) {
    if (!$lineUserId) return false;
    
    try {
        // Get user's internal ID
        $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return false;
        
        // Check if table has session_id column, add if not
        try {
            $db->query("SELECT session_id FROM ai_conversation_history LIMIT 1");
        } catch (Exception $e) {
            // Add session_id column
            $db->exec("ALTER TABLE ai_conversation_history ADD COLUMN session_id VARCHAR(50) NULL AFTER line_account_id");
            $db->exec("CREATE INDEX idx_session_id ON ai_conversation_history (session_id)");
        }
        
        // Insert message with session_id
        $stmt = $db->prepare("
            INSERT INTO ai_conversation_history (user_id, line_account_id, session_id, role, content) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $user['line_account_id'], $sessionId, $role, $content]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("saveConversationMessageWithSession error: " . $e->getMessage());
        return false;
    }
}


/**
 * Ensure a pharmacist notification exists for a triage session
 * Creates or updates notification so all triage sessions appear in pharmacist dashboard
 * 
 * @param PDO $db Database connection
 * @param int $sessionId Triage session ID
 * @param int $userId Internal user ID
 * @param int $lineAccountId LINE account ID
 * @param array $userContext User context data
 * @param array $triageData Triage data (symptoms, duration, severity, etc.)
 * @param string $state Current triage state
 * @return bool Success status
 */
function ensureTriageNotification($db, $sessionId, $userId, $lineAccountId, $userContext, $triageData, $state) {
    if (!$sessionId || !$userId) {
        return false;
    }
    
    try {
        // Check if notification already exists for this session
        $stmt = $db->prepare("
            SELECT id, notification_data FROM pharmacist_notifications 
            WHERE triage_session_id = ? AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        $existingNotif = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Build notification data
        $symptoms = $triageData['symptoms'] ?? '';
        if (is_array($symptoms)) {
            $symptoms = implode(', ', $symptoms);
        }
        
        $severity = $triageData['severity'] ?? null;
        $severityLevel = 'normal';
        $priority = 'normal';
        
        if ($severity !== null) {
            if ($severity >= 8) {
                $severityLevel = 'critical';
                $priority = 'urgent';
            } elseif ($severity >= 6) {
                $severityLevel = 'high';
                $priority = 'urgent';
            } elseif ($severity >= 4) {
                $severityLevel = 'medium';
            } else {
                $severityLevel = 'low';
            }
        }
        
        $userName = $userContext['display_name'] ?? 'ไม่ระบุชื่อ';
        
        $notificationData = json_encode([
            'symptoms' => $triageData['symptoms'] ?? '',
            'duration' => $triageData['duration'] ?? '',
            'severity' => $severity,
            'severity_level' => $severityLevel,
            'associated_symptoms' => $triageData['associated_symptoms'] ?? '',
            'allergies' => $triageData['allergies'] ?? '',
            'medical_history' => $triageData['medical_history'] ?? '',
            'current_medications' => $triageData['current_medications'] ?? '',
            'current_state' => $state,
            'user_name' => $userName
        ], JSON_UNESCAPED_UNICODE);
        
        if ($existingNotif) {
            // Update existing notification with latest data
            $stmt = $db->prepare("
                UPDATE pharmacist_notifications 
                SET notification_data = ?, priority = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$notificationData, $priority, $existingNotif['id']]);
            
            error_log("ensureTriageNotification: Updated notification #{$existingNotif['id']} for session #{$sessionId}");
        } else {
            // Create new notification
            $title = "🩺 การซักประวัติใหม่";
            if ($priority === 'urgent') {
                $title = "⚠️ การซักประวัติ - ต้องตรวจสอบ";
            }
            
            $message = "ลูกค้า: {$userName}\n";
            if (!empty($symptoms)) {
                $message .= "อาการ: {$symptoms}\n";
            }
            if (!empty($triageData['duration'])) {
                $message .= "ระยะเวลา: {$triageData['duration']}\n";
            }
            if ($severity !== null) {
                $message .= "ความรุนแรง: {$severity}/10\n";
            }
            $message .= "สถานะ: {$state}";
            
            // Ensure table exists with all columns
            $db->exec("
                CREATE TABLE IF NOT EXISTS pharmacist_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT NULL,
                    type VARCHAR(50) DEFAULT 'triage_alert',
                    title VARCHAR(255),
                    message TEXT,
                    notification_data JSON,
                    reference_id INT,
                    reference_type VARCHAR(50),
                    user_id INT,
                    triage_session_id INT NULL,
                    priority ENUM('normal', 'urgent') DEFAULT 'normal',
                    status ENUM('pending', 'handled', 'dismissed') DEFAULT 'pending',
                    is_read TINYINT(1) DEFAULT 0,
                    handled_by INT NULL,
                    handled_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_line_account (line_account_id),
                    INDEX idx_status (status),
                    INDEX idx_priority (priority),
                    INDEX idx_triage_session (triage_session_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $stmt = $db->prepare("
                INSERT INTO pharmacist_notifications 
                (line_account_id, type, title, message, notification_data, user_id, triage_session_id, priority, status)
                VALUES (?, 'triage_session', ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $lineAccountId,
                $title,
                $message,
                $notificationData,
                $userId,
                $sessionId,
                $priority
            ]);
            
            $notifId = $db->lastInsertId();
            error_log("ensureTriageNotification: Created notification #{$notifId} for session #{$sessionId}, priority={$priority}");
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("ensureTriageNotification error: " . $e->getMessage());
        return false;
    }
}
