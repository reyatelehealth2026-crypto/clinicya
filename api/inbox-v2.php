<?php
/**
 * Inbox V2 API - Vibe Selling OS v2 (Pharmacy Edition)
 * 
 * AI-Powered Pharmacy Assistant API endpoints for:
 * - Multi-modal image analysis (symptoms, drugs, prescriptions)
 * - Customer health profiling
 * - Drug pricing and margin calculation
 * - Ghost draft generation
 * - Drug recommendations and interaction checking
 * - Context-aware widgets and consultation stages
 * - Pharmacy consultation analytics
 * 
 * Requirements: 1.1-1.6, 2.1-2.6, 3.1-3.5, 4.1-4.6, 6.1-6.6, 7.1-7.6, 8.1-8.5, 9.1-9.5
 */

// Suppress warnings and notices - only log them
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load dependencies
try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to load config: ' . $e->getMessage()]);
    exit;
}

// Database connection
try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Request parameters
$method = $_SERVER['REQUEST_METHOD'];

// Get action from POST, GET, or JSON body
$jsonBody = [];
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $jsonBody = json_decode($rawInput, true) ?: [];
}

$action = $_POST['action'] ?? $_GET['action'] ?? $jsonBody['action'] ?? '';

// Get LINE account ID and admin ID from session or request
$lineAccountId = $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? $_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1;
$adminId = $_SESSION['admin_id'] ?? $_GET['admin_id'] ?? $_POST['admin_id'] ?? null;

/**
 * Load service class with error handling
 * @param string $className Service class name
 * @return object|null Service instance or null if not available
 */
function loadService(string $className, $db, $lineAccountId)
{
    $classFile = __DIR__ . '/../classes/' . $className . '.php';

    if (!file_exists($classFile)) {
        return null;
    }

    require_once $classFile;

    if (!class_exists($className)) {
        return null;
    }

    return new $className($db, $lineAccountId);
}

/**
 * Send JSON response
 * @param array $data Response data
 * @param int $statusCode HTTP status code
 */
function sendResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $statusCode HTTP status code
 */
function sendError(string $message, int $statusCode = 400): void
{
    sendResponse(['success' => false, 'error' => $message], $statusCode);
}

/**
 * Get request body as JSON
 * @return array Parsed JSON body
 */
function getJsonBody(): array
{
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

// Route based on action
try {
    switch ($action) {

        // ============================================
        // POST /analyze-symptom - Analyze symptom images
        // Requirements: 1.1, 1.5
        // ============================================
        case 'analyze_symptom':
        case 'analyze-symptom':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $imageUrl = $_POST['image_url'] ?? getJsonBody()['image_url'] ?? '';

            if (empty($imageUrl)) {
                sendError('Image URL is required');
            }

            $imageAnalyzer = loadService('PharmacyImageAnalyzerService', $db, $lineAccountId);

            if (!$imageAnalyzer) {
                sendError('Image analyzer service not available', 503);
            }

            if (!$imageAnalyzer->isConfigured()) {
                sendError('AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings', 503);
            }

            $result = $imageAnalyzer->analyzeSymptom($imageUrl);

            if (!($result['success'] ?? false)) {
                sendError($result['error'] ?? 'การวิเคราะห์อาการล้มเหลว');
            }

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // POST /analyze-drug - Identify drug from photo
        // Requirements: 1.2
        // ============================================
        case 'analyze_drug':
        case 'analyze-drug':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $imageUrl = $_POST['image_url'] ?? getJsonBody()['image_url'] ?? '';

            if (empty($imageUrl)) {
                sendError('Image URL is required');
            }

            $imageAnalyzer = loadService('PharmacyImageAnalyzerService', $db, $lineAccountId);

            if (!$imageAnalyzer) {
                sendError('Image analyzer service not available', 503);
            }

            if (!$imageAnalyzer->isConfigured()) {
                sendError('AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings', 503);
            }

            $result = $imageAnalyzer->identifyDrug($imageUrl);

            if (!($result['success'] ?? false)) {
                sendError($result['error'] ?? 'การวิเคราะห์รูปภาพล้มเหลว');
            }

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // POST /analyze-prescription - OCR prescription
        // Requirements: 1.3, 1.4
        // ============================================
        case 'analyze_prescription':
        case 'analyze-prescription':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $imageUrl = $_POST['image_url'] ?? $body['image_url'] ?? '';
            $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);

            if (empty($imageUrl)) {
                sendError('Image URL is required');
            }

            $imageAnalyzer = loadService('PharmacyImageAnalyzerService', $db, $lineAccountId);

            if (!$imageAnalyzer) {
                sendError('Image analyzer service not available', 503);
            }

            if (!$imageAnalyzer->isConfigured()) {
                sendError('AI API key not configured - กรุณาตั้งค่า Gemini API Key ในหน้า AI Settings', 503);
            }

            $result = $imageAnalyzer->ocrPrescription($imageUrl, $userId ?: null);

            if (!($result['success'] ?? false)) {
                sendError($result['error'] ?? 'การอ่านใบสั่งยาล้มเหลว');
            }

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /customer-health - Get customer health profile
        // Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6
        // ============================================
        case 'customer_health':
        case 'customer-health':
        case 'get_customer_health':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);

            if (!$healthEngine) {
                sendError('Health engine service not available', 503);
            }

            $profile = $healthEngine->getHealthProfile($userId);

            sendResponse([
                'success' => true,
                'data' => $profile
            ]);
            break;

        // ============================================
        // GET /classify-customer - Classify customer communication style
        // Requirements: 2.1
        // ============================================
        case 'classify_customer':
        case 'classify-customer':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);
            $minMessages = (int) ($_GET['min_messages'] ?? 5);

            if (!$userId) {
                sendError('User ID is required');
            }

            $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);

            if (!$healthEngine) {
                sendError('Health engine service not available', 503);
            }

            $classification = $healthEngine->classifyCustomer($userId, $minMessages);

            sendResponse([
                'success' => true,
                'data' => $classification
            ]);
            break;

        // ============================================
        // GET /draft-style - Get draft style for communication type
        // Requirements: 2.2, 2.3, 2.4
        // ============================================
        case 'draft_style':
        case 'draft-style':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $type = $_GET['type'] ?? 'A';

            if (!in_array($type, ['A', 'B', 'C'])) {
                sendError('Invalid communication type. Must be A, B, or C');
            }

            $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);

            if (!$healthEngine) {
                sendError('Health engine service not available', 503);
            }

            $style = $healthEngine->getDraftStyle($type);

            sendResponse([
                'success' => true,
                'data' => $style
            ]);
            break;


        // ============================================
        // POST /ghost-draft - Generate ghost draft response
        // Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6
        // ============================================
        case 'ghost_draft':
        case 'ghost-draft':
        case 'generate_draft':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
            $lastMessage = $_POST['message'] ?? $body['message'] ?? '';
            $context = $_POST['context'] ?? $body['context'] ?? [];

            if (!$userId) {
                sendError('User ID is required');
            }

            if (empty($lastMessage)) {
                sendError('Message is required');
            }

            $ghostDraft = loadService('PharmacyGhostDraftService', $db, $lineAccountId);

            if (!$ghostDraft) {
                sendError('Ghost draft service not available', 503);
            }

            if (!$ghostDraft->isConfigured()) {
                sendError('AI API key not configured', 503);
            }

            // Parse context if it's a string
            if (is_string($context)) {
                $context = json_decode($context, true) ?? [];
            }

            $result = $ghostDraft->generateDraft($userId, $lastMessage, $context);

            sendResponse([
                'success' => $result['success'] ?? false,
                'data' => $result
            ]);
            break;

        // ============================================
        // POST /learn-draft - Learn from pharmacist edit
        // Requirements: 6.5
        // ============================================
        case 'learn_draft':
        case 'learn-draft':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
            $originalDraft = $_POST['original_draft'] ?? $body['original_draft'] ?? '';
            $finalMessage = $_POST['final_message'] ?? $body['final_message'] ?? '';
            $context = $_POST['context'] ?? $body['context'] ?? [];

            if (!$userId) {
                sendError('User ID is required');
            }

            if (empty($originalDraft) || empty($finalMessage)) {
                sendError('Original draft and final message are required');
            }

            $ghostDraft = loadService('PharmacyGhostDraftService', $db, $lineAccountId);

            if (!$ghostDraft) {
                sendError('Ghost draft service not available', 503);
            }

            // Parse context if it's a string
            if (is_string($context)) {
                $context = json_decode($context, true) ?? [];
            }

            $success = $ghostDraft->learnFromEdit($userId, $originalDraft, $finalMessage, $context);

            sendResponse([
                'success' => $success,
                'message' => $success ? 'Learning data saved successfully' : 'Failed to save learning data'
            ]);
            break;

        // ============================================
        // GET /drug-info - Get drug information
        // Requirements: 4.2
        // ============================================
        case 'drug_info':
        case 'drug-info':
        case 'get_drug_info':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);
            $drugName = $_GET['name'] ?? '';

            if (!$drugId && empty($drugName)) {
                sendError('Drug ID or name is required');
            }

            try {
                // Get drug from business_items
                if ($drugId) {
                    $stmt = $db->prepare("
                        SELECT bi.*, ic.name as category_name
                        FROM business_items bi
                        LEFT JOIN item_categories ic ON bi.category_id = ic.id
                        WHERE bi.id = ?
                    ");
                    $stmt->execute([$drugId]);
                } else {
                    $stmt = $db->prepare("
                        SELECT bi.*, ic.name as category_name
                        FROM business_items bi
                        LEFT JOIN item_categories ic ON bi.category_id = ic.id
                        WHERE (bi.name LIKE ? OR bi.sku LIKE ?)
                        AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)
                        LIMIT 1
                    ");
                    $searchTerm = '%' . $drugName . '%';
                    $stmt->execute([$searchTerm, $searchTerm, $lineAccountId]);
                }

                $drug = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$drug) {
                    sendError('Drug not found', 404);
                }

                // Get pricing info
                $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);
                $pricing = null;

                if ($pricingEngine) {
                    try {
                        $pricing = $pricingEngine->calculateMargin((int) $drug['id']);
                    } catch (Exception $e) {
                        // Pricing calculation failed, continue without it
                        $pricing = null;
                    }
                }

                // Get effective price (sale_price if available, otherwise price)
                $effectivePrice = (float) ($drug['sale_price'] ?? 0) > 0
                    ? (float) $drug['sale_price']
                    : (float) ($drug['price'] ?? 0);

                sendResponse([
                    'success' => true,
                    'data' => [
                        'id' => (int) $drug['id'],
                        'name' => $drug['name'],
                        'nameEn' => $drug['name_en'] ?? null,
                        'genericName' => $drug['generic_name'] ?? null,
                        'manufacturer' => $drug['manufacturer'] ?? null,
                        'unit' => $drug['unit'] ?? $drug['base_unit'] ?? null,
                        'sku' => $drug['sku'] ?? null,
                        'description' => $drug['description'] ?? null,
                        'price' => (float) ($drug['price'] ?? 0),
                        'salePrice' => (float) ($drug['sale_price'] ?? 0),
                        'effectivePrice' => $effectivePrice,
                        'category' => $drug['category_name'] ?? null,
                        'imageUrl' => $drug['image_url'] ?? null,
                        'stock' => (int) ($drug['stock'] ?? 0),
                        'isActive' => (bool) ($drug['is_active'] ?? true),
                        'isPrescription' => (bool) ($drug['is_prescription'] ?? false),
                        'contraindications' => $drug['contraindications'] ?? null,
                        'dosage' => $drug['dosage'] ?? null,
                        'usageInstructions' => $drug['usage_instructions'] ?? null,
                        'activeIngredient' => $drug['active_ingredient'] ?? null,
                        'dosageForm' => $drug['dosage_form'] ?? null,
                        'barcode' => $drug['barcode'] ?? null,
                        'pricing' => $pricing
                    ]
                ]);
            } catch (PDOException $e) {
                sendError('Database error: ' . $e->getMessage(), 500);
            }
            break;

        // ============================================
        // GET /search_drugs - Search drugs by name/sku/generic
        // ============================================
        case 'search_drugs':
        case 'search-drugs':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $query = trim($_GET['query'] ?? '');
            if (mb_strlen($query) < 2) {
                sendError('Query must be at least 2 characters');
            }

            try {
                // Check available columns
                $columnsStmt = $db->query("SHOW COLUMNS FROM business_items");
                $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
                $hasGenericName = in_array('generic_name', $columns);
                $hasNameEn = in_array('name_en', $columns);

                $selectCols = "id, name, sku, price, sale_price, stock, description";
                if ($hasGenericName)
                    $selectCols .= ", generic_name";
                if ($hasNameEn)
                    $selectCols .= ", name_en";

                // Build search query
                $searchConditions = ["name LIKE ?", "sku LIKE ?"];
                $params = ["%{$query}%", "%{$query}%"];

                if ($hasGenericName) {
                    $searchConditions[] = "generic_name LIKE ?";
                    $params[] = "%{$query}%";
                }
                if ($hasNameEn) {
                    $searchConditions[] = "name_en LIKE ?";
                    $params[] = "%{$query}%";
                }

                $sql = "SELECT {$selectCols} FROM business_items 
                        WHERE is_active = 1 
                        AND (" . implode(' OR ', $searchConditions) . ")";

                if ($lineAccountId) {
                    $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                    $params[] = $lineAccountId;
                }

                $sql .= " ORDER BY stock DESC, name ASC LIMIT 10";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $results = [];
                foreach ($drugs as $drug) {
                    $results[] = [
                        'id' => (int) $drug['id'],
                        'name' => $drug['name'],
                        'name_en' => $drug['name_en'] ?? '',
                        'generic_name' => $drug['generic_name'] ?? '',
                        'sku' => $drug['sku'],
                        'price' => (float) ($drug['sale_price'] ?? $drug['price'] ?? 0),
                        'stock' => (int) ($drug['stock'] ?? 0)
                    ];
                }

                sendResponse([
                    'success' => true,
                    'data' => $results,
                    'count' => count($results),
                    'query' => $query
                ]);

            } catch (PDOException $e) {
                sendError('Database error: ' . $e->getMessage(), 500);
            }
            break;

        // ============================================
        // GET /drug-pricing - Get drug pricing and margin
        // Requirements: 3.1, 3.4
        // ============================================
        case 'drug_pricing':
        case 'drug-pricing':
        case 'calculate_margin':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);

            if (!$drugId) {
                sendError('Drug ID is required');
            }

            // Direct database query - simple and reliable
            try {
                // First check what columns exist
                $columnsStmt = $db->query("SHOW COLUMNS FROM business_items");
                $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
                $hasCostPrice = in_array('cost_price', $columns);

                // Build query based on available columns
                if ($hasCostPrice) {
                    $stmt = $db->prepare("SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id = ?");
                } else {
                    $stmt = $db->prepare("SELECT id, name, price, sale_price FROM business_items WHERE id = ?");
                }

                $stmt->execute([$drugId]);
                $drug = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$drug) {
                    sendError('Drug not found', 404);
                }

                // Calculate pricing
                $price = (float) ($drug['sale_price'] ?? $drug['price'] ?? 0);
                $cost = $hasCostPrice ? (float) ($drug['cost_price'] ?? 0) : 0;

                // Estimate cost if not available (assume 30% margin)
                $estimated = false;
                if ($cost <= 0 && $price > 0) {
                    $cost = $price * 0.7;
                    $estimated = true;
                }

                $margin = $price - $cost;
                $marginPercent = $price > 0 ? (($price - $cost) / $price) * 100 : 0;

                sendResponse([
                    'success' => true,
                    'data' => [
                        'drugId' => (int) $drug['id'],
                        'drugName' => $drug['name'],
                        'cost' => round($cost, 2),
                        'price' => round($price, 2),
                        'margin' => round($margin, 2),
                        'marginPercent' => round($marginPercent, 2),
                        'estimated' => $estimated
                    ]
                ]);

            } catch (PDOException $e) {
                sendError('Database error: ' . $e->getMessage(), 500);
            }
            break;

        // ============================================
        // GET /max-discount - Get maximum allowable discount
        // Requirements: 3.2
        // ============================================
        case 'max_discount':
        case 'max-discount':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);
            $minMargin = isset($_GET['min_margin']) ? (float) $_GET['min_margin'] : 10.0;

            if (!$drugId) {
                sendError('Drug ID is required');
            }

            $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);

            if (!$pricingEngine) {
                sendError('Pricing engine service not available', 503);
            }

            $result = $pricingEngine->getMaxDiscount($drugId, $minMargin);

            sendResponse([
                'success' => !isset($result['error']),
                'data' => $result
            ]);
            break;

        // ============================================
        // POST /suggest-alternatives - Suggest alternatives for excessive discount
        // Requirements: 3.3
        // ============================================
        case 'suggest_alternatives':
        case 'suggest-alternatives':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $drugId = (int) ($_POST['drug_id'] ?? $body['drug_id'] ?? 0);
            $requestedDiscount = (float) ($_POST['discount'] ?? $body['discount'] ?? 0);

            if (!$drugId) {
                sendError('Drug ID is required');
            }

            if ($requestedDiscount <= 0) {
                sendError('Discount amount must be greater than 0');
            }

            $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);

            if (!$pricingEngine) {
                sendError('Pricing engine service not available', 503);
            }

            $result = $pricingEngine->suggestAlternatives($drugId, $requestedDiscount);

            sendResponse([
                'success' => !isset($result['error']),
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /customer-loyalty - Get customer loyalty status
        // Requirements: 3.5
        // ============================================
        case 'customer_loyalty':
        case 'customer-loyalty':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);

            if (!$pricingEngine) {
                sendError('Pricing engine service not available', 503);
            }

            $result = $pricingEngine->getCustomerLoyalty($userId);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // PHARMACY INTEGRATION ENDPOINTS
        // Requirements: 10.1, 10.2, 10.3, 10.4, 10.5
        // ============================================

        // ============================================
        // POST /check-interactions - Check drug interactions
        // Requirements: 10.5
        // ============================================
        case 'check_interactions':
        case 'check-interactions':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $drugNames = $_POST['drugs'] ?? $body['drugs'] ?? [];
            $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);

            if (empty($drugNames)) {
                sendError('Drug names array is required');
            }

            // Parse drugs if string
            if (is_string($drugNames)) {
                $drugNames = json_decode($drugNames, true) ?? explode(',', $drugNames);
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->checkDrugInteractions($drugNames, $userId ?: null);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /medical-history - Get user medical history
        // Requirements: 10.1, 10.4
        // ============================================
        case 'medical_history':
        case 'medical-history':
        case 'get_medical_history':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->getUserMedicalHistory($userId);

            sendResponse([
                'success' => $result['found'] ?? false,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /patient-profile - Get comprehensive patient profile
        // Requirements: 10.1, 10.4
        // ============================================
        case 'patient_profile':
        case 'patient-profile':
        case 'get_patient_profile':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->getComprehensivePatientProfile($userId);

            sendResponse([
                'success' => $result['found'] ?? false,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /drug-inventory - Get drug inventory status
        // Requirements: 10.2
        // ============================================
        case 'drug_inventory':
        case 'drug-inventory':
        case 'get_drug_inventory':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $productId = (int) ($_GET['product_id'] ?? $_GET['drug_id'] ?? $_GET['id'] ?? 0);

            if (!$productId) {
                sendError('Product ID is required');
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->getDrugInventory($productId);

            sendResponse([
                'success' => $result['found'] ?? false,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /search-drugs - Search drug inventory
        // Requirements: 10.2
        // ============================================
        case 'search_drugs':
        case 'search-drugs':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $query = $_GET['q'] ?? $_GET['query'] ?? '';
            $inStockOnly = filter_var($_GET['in_stock'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
            $limit = (int) ($_GET['limit'] ?? 20);

            if (empty($query)) {
                sendError('Search query is required');
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->searchDrugInventory($query, $inStockOnly, $limit);

            sendResponse([
                'success' => true,
                'data' => $result,
                'count' => count($result)
            ]);
            break;

        // ============================================
        // GET /drug-pricing-data - Get drug pricing from business_items
        // Requirements: 10.3
        // ============================================
        case 'drug_pricing_data':
        case 'drug-pricing-data':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $productId = (int) ($_GET['product_id'] ?? $_GET['drug_id'] ?? $_GET['id'] ?? 0);

            if (!$productId) {
                sendError('Product ID is required');
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->getDrugPricing($productId);

            sendResponse([
                'success' => $result['found'] ?? false,
                'data' => $result
            ]);
            break;

        // ============================================
        // POST /validate-recommendation - Validate drug recommendation
        // Requirements: 10.2, 10.5
        // ============================================
        case 'validate_recommendation':
        case 'validate-recommendation':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? $body['product_id'] ?? $body['drug_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            if (!$productId) {
                sendError('Product ID is required');
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->validateDrugRecommendation($userId, $productId);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /check-allergy - Check user allergy to drug
        // Requirements: 10.1
        // ============================================
        case 'check_allergy':
        case 'check-allergy':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);
            $drugName = $_GET['drug_name'] ?? $_GET['drug'] ?? '';

            if (!$userId) {
                sendError('User ID is required');
            }

            if (empty($drugName)) {
                sendError('Drug name is required');
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->checkUserAllergy($userId, $drugName);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /prescription-history - Get user prescription history
        // Requirements: 10.4
        // ============================================
        case 'prescription_history':
        case 'prescription-history':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);
            $limit = (int) ($_GET['limit'] ?? 20);

            if (!$userId) {
                sendError('User ID is required');
            }

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->getUserPrescriptionHistory($userId, $limit);

            sendResponse([
                'success' => true,
                'data' => $result,
                'count' => count($result)
            ]);
            break;

        // ============================================
        // GET /low-stock-drugs - Get low stock drugs
        // Requirements: 10.2
        // ============================================
        case 'low_stock_drugs':
        case 'low-stock-drugs':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $limit = (int) ($_GET['limit'] ?? 50);

            $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);

            if (!$integration) {
                sendError('Integration service not available', 503);
            }

            $result = $integration->getLowStockDrugs($limit);

            sendResponse([
                'success' => true,
                'data' => $result,
                'count' => count($result)
            ]);
            break;

        // ============================================
        // GET /recommendations - Get drug recommendations for symptoms
        // Requirements: 7.1, 7.2, 7.4, 7.6
        // ============================================
        case 'recommendations':
        case 'get_recommendations':
        case 'drug_recommendations':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);
            $symptoms = $_GET['symptoms'] ?? '';
            $type = $_GET['type'] ?? '';
            $message = $_GET['message'] ?? '';
            $limit = (int) ($_GET['limit'] ?? 10); // Increased default limit

            if (!$userId) {
                sendError('User ID is required');
            }

            $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);

            // Priority 1: Search from chat history (most accurate)
            if ($consultationAnalyzer && $type === 'context') {
                try {
                    $matchedDrugs = $consultationAnalyzer->searchDrugsFromChatHistory($userId, $limit);

                    if (!empty($matchedDrugs)) {
                        sendResponse([
                            'success' => true,
                            'data' => [
                                'recommendations' => $matchedDrugs,
                                'type' => 'chat_history',
                                'userId' => $userId,
                                'count' => count($matchedDrugs)
                            ]
                        ]);
                        break;
                    }
                } catch (Throwable $e) {
                    error_log("Chat history search error: " . $e->getMessage());
                }
            }

            // Priority 2: Search from current message
            if (!empty($message) && $consultationAnalyzer) {
                try {
                    $matchedDrugs = $consultationAnalyzer->searchDrugsFromMessage($message);

                    // Extract search terms from message for customer request summary
                    $searchTerms = $consultationAnalyzer->extractSearchTerms($message);

                    if (!empty($matchedDrugs)) {
                        sendResponse([
                            'success' => true,
                            'data' => [
                                'recommendations' => $matchedDrugs,
                                'type' => 'message_search',
                                'userId' => $userId,
                                'message' => $message,
                                'searchTerms' => $searchTerms,
                                'originalMessage' => $message
                            ]
                        ]);
                        break;
                    }
                } catch (Throwable $e) {
                    error_log("Message search error: " . $e->getMessage());
                }
            }

            // Priority 3: Get popular drugs as fallback
            if ($type === 'context' || empty($symptoms)) {
                // Return popular drugs from business_items table
                try {
                    $sql = "
                        SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, 
                               bi.stock, bi.description, bi.image_url,
                               ic.name as category
                        FROM business_items bi
                        LEFT JOIN item_categories ic ON bi.category_id = ic.id
                        WHERE bi.is_active = 1 
                        AND bi.stock > 0
                    ";

                    if ($lineAccountId) {
                        $sql .= " AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)";
                    }

                    $sql .= " ORDER BY bi.stock DESC, bi.name ASC LIMIT ?";

                    $stmt = $db->prepare($sql);
                    if ($lineAccountId) {
                        $stmt->execute([$lineAccountId, $limit]);
                    } else {
                        $stmt->execute([$limit]);
                    }

                    $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $recommendations = [];
                    foreach ($drugs as $drug) {
                        $recommendations[] = [
                            'id' => (int) $drug['id'],
                            'drugId' => (int) $drug['id'],
                            'name' => $drug['name'],
                            'sku' => $drug['sku'],
                            'price' => (float) ($drug['sale_price'] ?? $drug['price'] ?? 0),
                            'originalPrice' => (float) ($drug['price'] ?? 0),
                            'stock' => (int) ($drug['stock'] ?? 0),
                            'category' => $drug['category'] ?? 'ยาทั่วไป',
                            'description' => $drug['description'],
                            'imageUrl' => $drug['image_url']
                        ];
                    }

                    sendResponse([
                        'success' => true,
                        'data' => [
                            'recommendations' => $recommendations,
                            'type' => 'popular',
                            'userId' => $userId
                        ]
                    ]);
                } catch (PDOException $e) {
                    sendResponse([
                        'success' => true,
                        'data' => [
                            'recommendations' => [],
                            'type' => 'popular',
                            'userId' => $userId,
                            'error' => $e->getMessage()
                        ]
                    ]);
                }
                break;
            }

            // Parse symptoms (comma-separated or JSON array)
            if (is_string($symptoms)) {
                $symptomsArray = json_decode($symptoms, true);
                if (!is_array($symptomsArray)) {
                    $symptomsArray = array_map('trim', explode(',', $symptoms));
                }
            } else {
                $symptomsArray = $symptoms;
            }

            $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);

            if (!$recommendEngine) {
                sendError('Recommendation engine service not available', 503);
            }

            // Optionally set health engine for better allergy checking
            $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
            if ($healthEngine) {
                $recommendEngine->setHealthEngine($healthEngine);
            }

            $result = $recommendEngine->getForSymptoms($symptomsArray, $userId, $limit);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // POST /check-drug-interactions - Check drug interactions with user medications
        // Requirements: 7.2
        // ============================================
        case 'check_drug_interactions':
        case 'check-drug-interactions':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
            $drugIds = $_POST['drug_ids'] ?? $body['drug_ids'] ?? [];

            if (!$userId) {
                sendError('User ID is required');
            }

            if (empty($drugIds)) {
                sendError('Drug IDs array is required');
            }

            // Parse drug IDs if string
            if (is_string($drugIds)) {
                $drugIds = json_decode($drugIds, true) ?? array_map('intval', explode(',', $drugIds));
            }

            $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);

            if (!$recommendEngine) {
                sendError('Recommendation engine service not available', 503);
            }

            // Optionally set health engine
            $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
            if ($healthEngine) {
                $recommendEngine->setHealthEngine($healthEngine);
            }

            $result = $recommendEngine->checkInteractions($drugIds, $userId);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /refill-reminders - Get refill reminders for user
        // Requirements: 7.3
        // ============================================
        case 'refill_reminders':
        case 'refill-reminders':
        case 'get_refill_reminders':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);

            if (!$recommendEngine) {
                sendError('Recommendation engine service not available', 503);
            }

            $result = $recommendEngine->getRefillReminders($userId);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /drug-card - Generate drug card for LINE message
        // Requirements: 7.5
        // ============================================
        case 'drug_card':
        case 'drug-card':
        case 'generate_drug_card':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);

            if (!$drugId) {
                sendError('Drug ID is required');
            }

            $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);

            if (!$recommendEngine) {
                sendError('Recommendation engine service not available', 503);
            }

            $result = $recommendEngine->generateDrugCard($drugId);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /safe-alternatives - Get safe drug alternatives
        // Requirements: 7.6
        // ============================================
        case 'safe_alternatives':
        case 'safe-alternatives':
        case 'get_safe_alternatives':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);
            $userId = (int) ($_GET['user_id'] ?? 0);

            if (!$drugId) {
                sendError('Drug ID is required');
            }

            if (!$userId) {
                sendError('User ID is required');
            }

            $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);

            if (!$recommendEngine) {
                sendError('Recommendation engine service not available', 503);
            }

            // Optionally set health engine
            $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
            if ($healthEngine) {
                $recommendEngine->setHealthEngine($healthEngine);
            }

            $result = $recommendEngine->getSafeAlternatives($drugId, $userId);

            sendResponse([
                'success' => true,
                'data' => $result
            ]);
            break;

        // ============================================
        // GET /context-widgets - Get context-aware widgets
        // Requirements: 4.1, 4.2, 4.3, 4.4, 4.5
        // ============================================
        case 'context_widgets':
        case 'context-widgets':
        case 'get_context_widgets':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);
            $message = $_GET['message'] ?? '';

            if (!$userId) {
                sendError('User ID is required');
            }

            // Message is optional - return empty widgets if no message
            if (empty($message)) {
                sendResponse([
                    'success' => true,
                    'data' => [
                        'widgets' => [],
                        'count' => 0
                    ]
                ]);
            }

            $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);

            if (!$consultationAnalyzer) {
                sendError('Consultation analyzer service not available', 503);
            }

            $widgets = $consultationAnalyzer->getContextWidgets($message, $userId);

            sendResponse([
                'success' => true,
                'data' => [
                    'widgets' => $widgets,
                    'count' => count($widgets)
                ]
            ]);
            break;

        // ============================================
        // GET /consultation-stage - Detect consultation stage
        // Requirements: 9.1, 9.2, 9.3
        // ============================================
        case 'consultation_stage':
        case 'consultation-stage':
        case 'detect_stage':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);

            if (!$consultationAnalyzer) {
                sendError('Consultation analyzer service not available', 503);
            }

            $stage = $consultationAnalyzer->detectStage($userId);

            sendResponse([
                'success' => true,
                'data' => $stage
            ]);
            break;

        // ============================================
        // GET /quick-actions - Get quick actions for stage
        // Requirements: 9.1, 9.2, 9.3, 9.4, 9.5
        // ============================================
        case 'quick_actions':
        case 'quick-actions':
        case 'get_quick_actions':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);
            $stage = $_GET['stage'] ?? '';
            $hasUrgent = filter_var($_GET['has_urgent'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

            $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);

            if (!$consultationAnalyzer) {
                sendError('Consultation analyzer service not available', 503);
            }

            // If no stage provided, detect it from user messages
            if (empty($stage) && $userId) {
                $stageResult = $consultationAnalyzer->detectStage($userId);
                $stage = $stageResult['stage'];
                $hasUrgent = $stageResult['hasUrgentSymptoms'] ?? $hasUrgent;
            }

            // Default to symptom assessment if still no stage
            if (empty($stage)) {
                $stage = 'symptom_assessment';
            }

            $actions = $consultationAnalyzer->getQuickActions($stage, $hasUrgent);

            sendResponse([
                'success' => true,
                'data' => $actions
            ]);
            break;

        // ============================================
        // GET /detect-urgency - Detect if symptoms require hospital referral
        // Requirements: 9.4
        // ============================================
        case 'detect_urgency':
        case 'detect-urgency':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);

            if (!$consultationAnalyzer) {
                sendError('Consultation analyzer service not available', 503);
            }

            $urgency = $consultationAnalyzer->detectUrgency($userId);

            sendResponse([
                'success' => true,
                'data' => $urgency
            ]);
            break;

        // ============================================
        // GET /analytics - Get consultation analytics
        // Requirements: 8.1, 8.2, 8.3, 8.4, 8.5
        // ============================================
        case 'analytics':
        case 'get_analytics':
        case 'consultation_analytics':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $pharmacistId = (int) ($_GET['pharmacist_id'] ?? $adminId ?? 0);
            $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            // Get analytics from consultation_analytics table
            try {
                $sql = "
                    SELECT 
                        COUNT(*) as total_consultations,
                        SUM(resulted_in_purchase) as successful_consultations,
                        AVG(response_time_avg) as avg_response_time,
                        SUM(ai_suggestions_shown) as total_ai_suggestions,
                        SUM(ai_suggestions_accepted) as accepted_ai_suggestions,
                        SUM(purchase_amount) as total_revenue,
                        AVG(message_count) as avg_messages_per_consultation
                    FROM consultation_analytics
                    WHERE created_at BETWEEN ? AND ?
                ";
                $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];

                if ($pharmacistId) {
                    $sql .= " AND pharmacist_id = ?";
                    $params[] = $pharmacistId;
                }

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $summary = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get breakdown by communication type
                $sql2 = "
                    SELECT 
                        communication_type,
                        COUNT(*) as count,
                        SUM(resulted_in_purchase) as purchases,
                        AVG(response_time_avg) as avg_response_time
                    FROM consultation_analytics
                    WHERE created_at BETWEEN ? AND ?
                ";
                $params2 = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];

                if ($pharmacistId) {
                    $sql2 .= " AND pharmacist_id = ?";
                    $params2[] = $pharmacistId;
                }

                $sql2 .= " GROUP BY communication_type";

                $stmt2 = $db->prepare($sql2);
                $stmt2->execute($params2);
                $byType = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                // Calculate success rate
                $totalConsultations = (int) ($summary['total_consultations'] ?? 0);
                $successfulConsultations = (int) ($summary['successful_consultations'] ?? 0);
                $successRate = $totalConsultations > 0
                    ? round(($successfulConsultations / $totalConsultations) * 100, 2)
                    : 0;

                // Calculate AI acceptance rate
                $totalAiSuggestions = (int) ($summary['total_ai_suggestions'] ?? 0);
                $acceptedAiSuggestions = (int) ($summary['accepted_ai_suggestions'] ?? 0);
                $aiAcceptanceRate = $totalAiSuggestions > 0
                    ? round(($acceptedAiSuggestions / $totalAiSuggestions) * 100, 2)
                    : 0;

                sendResponse([
                    'success' => true,
                    'data' => [
                        'period' => [
                            'startDate' => $startDate,
                            'endDate' => $endDate
                        ],
                        'summary' => [
                            'totalConsultations' => $totalConsultations,
                            'successfulConsultations' => $successfulConsultations,
                            'successRate' => $successRate,
                            'avgResponseTime' => round((float) ($summary['avg_response_time'] ?? 0), 2),
                            'avgMessagesPerConsultation' => round((float) ($summary['avg_messages_per_consultation'] ?? 0), 1),
                            'totalRevenue' => (float) ($summary['total_revenue'] ?? 0),
                            'aiAcceptanceRate' => $aiAcceptanceRate
                        ],
                        'byType' => $byType
                    ]
                ]);
            } catch (PDOException $e) {
                error_log("Analytics query error: " . $e->getMessage());
                sendResponse([
                    'success' => true,
                    'data' => [
                        'period' => ['startDate' => $startDate, 'endDate' => $endDate],
                        'summary' => [],
                        'byType' => [],
                        'message' => 'No analytics data available yet'
                    ]
                ]);
            }
            break;

        // ============================================
        // POST /record-analytics - Record consultation analytics
        // Requirements: 8.4
        // ============================================
        case 'record_analytics':
        case 'record-analytics':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);

            if (!$consultationAnalyzer) {
                sendError('Consultation analyzer service not available', 503);
            }

            $analyticsData = [
                'pharmacistId' => (int) ($_POST['pharmacist_id'] ?? $body['pharmacist_id'] ?? $adminId ?? null),
                'communicationType' => $_POST['communication_type'] ?? $body['communication_type'] ?? null,
                'stageAtClose' => $_POST['stage_at_close'] ?? $body['stage_at_close'] ?? null,
                'responseTimeAvg' => isset($_POST['response_time_avg']) ? (int) $_POST['response_time_avg'] : (isset($body['response_time_avg']) ? (int) $body['response_time_avg'] : null),
                'messageCount' => isset($_POST['message_count']) ? (int) $_POST['message_count'] : (isset($body['message_count']) ? (int) $body['message_count'] : null),
                'aiSuggestionsShown' => (int) ($_POST['ai_suggestions_shown'] ?? $body['ai_suggestions_shown'] ?? 0),
                'aiSuggestionsAccepted' => (int) ($_POST['ai_suggestions_accepted'] ?? $body['ai_suggestions_accepted'] ?? 0),
                'resultedInPurchase' => filter_var($_POST['resulted_in_purchase'] ?? $body['resulted_in_purchase'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'purchaseAmount' => isset($_POST['purchase_amount']) ? (float) $_POST['purchase_amount'] : (isset($body['purchase_amount']) ? (float) $body['purchase_amount'] : null),
                'symptomCategories' => $_POST['symptom_categories'] ?? $body['symptom_categories'] ?? [],
                'drugsRecommended' => $_POST['drugs_recommended'] ?? $body['drugs_recommended'] ?? [],
                'successfulPatterns' => $_POST['successful_patterns'] ?? $body['successful_patterns'] ?? []
            ];

            $success = $consultationAnalyzer->recordAnalytics($userId, $analyticsData);

            sendResponse([
                'success' => $success,
                'message' => $success ? 'Analytics recorded successfully' : 'Failed to record analytics'
            ]);
            break;

        // ============================================
        // POST /save_pending_order - Save pending order to user state
        // ============================================
        case 'save_pending_order':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = (int) ($body['user_id'] ?? 0);
            $items = $body['items'] ?? [];
            $subtotal = (float) ($body['subtotal'] ?? 0);
            $discount = (float) ($body['discount'] ?? 0);
            $total = (float) ($body['total'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            if (empty($items)) {
                sendError('Items are required');
            }

            // Save pending order to user_states table
            $pendingOrderData = [
                'items' => $items,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'created_at' => date('Y-m-d H:i:s'),
                'line_account_id' => $lineAccountId
            ];

            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

            try {
                // Check if user_states has user_id as PRIMARY KEY
                $stmt = $db->query("SHOW KEYS FROM user_states WHERE Key_name = 'PRIMARY'");
                $primaryKey = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($primaryKey && $primaryKey['Column_name'] === 'user_id') {
                    $stmt = $db->prepare("INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?) 
                                        ON DUPLICATE KEY UPDATE state = ?, state_data = ?, expires_at = ?");
                    $stmt->execute([
                        $userId,
                        'pending_order',
                        json_encode($pendingOrderData),
                        $expiresAt,
                        'pending_order',
                        json_encode($pendingOrderData),
                        $expiresAt
                    ]);
                } else {
                    $stmt = $db->prepare("DELETE FROM user_states WHERE user_id = ?");
                    $stmt->execute([$userId]);

                    $stmt = $db->prepare("INSERT INTO user_states (user_id, state, state_data, expires_at) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$userId, 'pending_order', json_encode($pendingOrderData), $expiresAt]);
                }

                sendResponse([
                    'success' => true,
                    'message' => 'Pending order saved',
                    'expires_at' => $expiresAt
                ]);
            } catch (Exception $e) {
                sendError('Failed to save pending order: ' . $e->getMessage());
            }
            break;

        // ============================================
        // GET /customer_crm - Get CRM data for HUD panel
        // ============================================
        case 'customer_crm':
            $userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            try {
                // Get user info
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    sendError('User not found', 404);
                }

                // Get loyalty points
                $points = ['available_points' => 0, 'total_points' => 0, 'used_points' => 0];
                $tier = ['name' => 'Member', 'icon' => '🥉', 'color' => '#9CA3AF'];

                try {
                    require_once __DIR__ . '/../classes/LoyaltyPoints.php';
                    $loyalty = new LoyaltyPoints($db, $lineAccountId);
                    $points = $loyalty->getUserPoints($userId);

                    $totalPts = $points['total_points'] ?? 0;
                    if ($totalPts >= 10000) {
                        $tier = ['name' => 'Platinum', 'icon' => '💎', 'color' => '#6366F1'];
                    } elseif ($totalPts >= 5000) {
                        $tier = ['name' => 'Gold', 'icon' => '🥇', 'color' => '#F59E0B'];
                    } elseif ($totalPts >= 1000) {
                        $tier = ['name' => 'Silver', 'icon' => '🥈', 'color' => '#6B7280'];
                    }
                } catch (Exception $e) {
                }

                // Get stats
                $stats = ['order_count' => 0, 'total_spent' => 0, 'message_count' => 0];
                try {
                    $stmt = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total FROM transactions WHERE user_id = ? AND status NOT IN ('cancelled', 'pending')");
                    $stmt->execute([$userId]);
                    $txStats = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stats['order_count'] = $txStats['cnt'] ?? 0;
                    $stats['total_spent'] = $txStats['total'] ?? 0;

                    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    $stats['message_count'] = $stmt->fetchColumn();
                } catch (Exception $e) {
                }

                // Get tags
                $tags = [];
                try {
                    $stmt = $db->prepare("SELECT ut.id, ut.name, ut.color FROM user_tags ut 
                                          JOIN user_tag_assignments uta ON ut.id = uta.tag_id 
                                          WHERE uta.user_id = ?");
                    $stmt->execute([$userId]);
                    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                }

                // Get all available tags for selector
                $allTags = [];
                try {
                    $stmt = $db->prepare("SELECT id, name, color FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name");
                    $stmt->execute([$lineAccountId]);
                    $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                }

                // Get notes - try customer_notes first, fallback to user_notes
                $notes = [];
                try {
                    // Try customer_notes table first
                    $stmt = $db->prepare("SELECT id, user_id, content, created_by, created_at FROM customer_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
                    $stmt->execute([$userId]);
                    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    // Fallback to user_notes table
                    try {
                        $stmt = $db->prepare("SELECT id, user_id, note as content, created_by, created_at FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
                        $stmt->execute([$userId]);
                        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e2) {
                    }
                }

                // Get recent transactions
                $transactions = [];
                try {
                    $stmt = $db->prepare("SELECT id, grand_total, status, created_at FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                    $stmt->execute([$userId]);
                    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                }

                sendResponse([
                    'success' => true,
                    'data' => [
                        'user' => $user,
                        'points' => $points,
                        'tier' => $tier,
                        'stats' => $stats,
                        'tags' => $tags,
                        'all_tags' => $allTags,
                        'notes' => $notes,
                        'transactions' => $transactions
                    ]
                ]);
            } catch (Exception $e) {
                sendError('Failed to load CRM data: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /add_customer_note - Add note to customer
        // ============================================
        case 'add_customer_note':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_POST['user_id'] ?? 0);
            $content = trim($_POST['content'] ?? '');

            if (!$userId || empty($content)) {
                sendError('User ID and content are required');
            }

            try {
                // Try customer_notes first, fallback to user_notes
                try {
                    $stmt = $db->prepare("INSERT INTO customer_notes (user_id, content, created_by, created_at) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$userId, $content, $adminId ?? 'Admin']);
                } catch (Exception $e) {
                    // Fallback to user_notes table
                    $stmt = $db->prepare("INSERT INTO user_notes (user_id, note, created_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$userId, $content]);
                }

                sendResponse([
                    'success' => true,
                    'message' => 'Note added successfully',
                    'note_id' => $db->lastInsertId()
                ]);
            } catch (Exception $e) {
                sendError('Failed to add note: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /add_customer_tag - Add tag to customer
        // ============================================
        case 'add_customer_tag':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_POST['user_id'] ?? 0);
            $tagName = trim($_POST['tag_name'] ?? '');

            if (!$userId || empty($tagName)) {
                sendError('User ID and tag name are required');
            }

            try {
                // Find or create tag
                $stmt = $db->prepare("SELECT id FROM user_tags WHERE name = ? AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$tagName, $lineAccountId]);
                $tag = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$tag) {
                    // Create new tag with random color
                    $colors = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899', '#6366F1'];
                    $color = $colors[array_rand($colors)];

                    $stmt = $db->prepare("INSERT INTO user_tags (name, color, line_account_id, created_at) VALUES (?, ?, ?, NOW())");
                    $stmt->execute([$tagName, $color, $lineAccountId]);
                    $tagId = $db->lastInsertId();
                } else {
                    $tagId = $tag['id'];
                }

                // Assign tag to user
                $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$userId, $tagId, $adminId ?? 'Admin']);

                sendResponse([
                    'success' => true,
                    'message' => 'Tag added successfully',
                    'tag_id' => $tagId
                ]);
            } catch (Exception $e) {
                sendError('Failed to add tag: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /remove_customer_tag - Remove tag from customer
        // ============================================
        case 'remove_customer_tag':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_POST['user_id'] ?? 0);
            $tagId = (int) ($_POST['tag_id'] ?? 0);

            if (!$userId || !$tagId) {
                sendError('User ID and tag ID are required');
            }

            try {
                $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
                $stmt->execute([$userId, $tagId]);

                sendResponse([
                    'success' => true,
                    'message' => 'Tag removed successfully'
                ]);
            } catch (Exception $e) {
                sendError('Failed to remove tag: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /assign_tag - Assign existing tag to customer
        // ============================================
        case 'assign_tag':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_POST['user_id'] ?? 0);
            $tagId = (int) ($_POST['tag_id'] ?? 0);

            if (!$userId || !$tagId) {
                sendError('User ID and tag ID are required');
            }

            try {
                $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$userId, $tagId, $adminId ?? 'Admin']);

                sendResponse([
                    'success' => true,
                    'message' => 'Tag assigned successfully'
                ]);
            } catch (Exception $e) {
                sendError('Failed to assign tag: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /update_customer_info - Update customer field
        // ============================================
        case 'update_customer_info':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_POST['user_id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = trim($_POST['value'] ?? '');

            // Whitelist allowed fields
            $allowedFields = ['display_name', 'phone', 'address', 'email', 'real_name', 'birthday', 'province', 'postal_code'];

            if (!$userId || !in_array($field, $allowedFields)) {
                sendError('Invalid user ID or field');
            }

            try {
                $stmt = $db->prepare("UPDATE users SET {$field} = ? WHERE id = ?");
                $stmt->execute([$value ?: null, $userId]);

                sendResponse([
                    'success' => true,
                    'message' => 'Customer info updated successfully'
                ]);
            } catch (Exception $e) {
                sendError('Failed to update customer info: ' . $e->getMessage());
            }
            break;

        // ============================================
        // TEMPLATE MANAGEMENT ENDPOINTS
        // ============================================

        // ============================================
        // GET /get_templates - Get all templates
        // ============================================
        case 'get_templates':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $search = $_GET['search'] ?? '';

            $templateService = loadService('TemplateService', $db, $lineAccountId);
            if (!$templateService) {
                sendError('Template service not available', 503);
            }

            try {
                $templates = $templateService->getTemplates($search);
                sendResponse([
                    'success' => true,
                    'data' => $templates
                ]);
            } catch (Exception $e) {
                sendError('Failed to get templates: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /create_template - Create a new template
        // ============================================
        case 'create_template':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $name = $_POST['name'] ?? $body['name'] ?? '';
            $content = $_POST['content'] ?? $body['content'] ?? '';
            $category = $_POST['category'] ?? $body['category'] ?? '';
            $quickReply = $_POST['quick_reply'] ?? $body['quick_reply'] ?? null;

            if (empty($name) || empty($content)) {
                sendError('Name and content are required');
            }

            $templateService = loadService('TemplateService', $db, $lineAccountId);
            if (!$templateService) {
                sendError('Template service not available', 503);
            }

            try {
                // If quickReply is empty string, set to null
                if ($quickReply === '')
                    $quickReply = null;

                $templateId = $templateService->createTemplate($name, $content, $category, $adminId, $quickReply);
                sendResponse([
                    'success' => true,
                    'message' => 'Template created successfully',
                    'id' => $templateId
                ]);
            } catch (Exception $e) {
                sendError('Failed to create template: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /update_template - Update an existing template
        // ============================================
        case 'update_template':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $id = (int) ($_POST['id'] ?? $body['id'] ?? 0);

            if (!$id) {
                sendError('Template ID is required');
            }

            $data = [];
            if (isset($_POST['name']) || isset($body['name']))
                $data['name'] = $_POST['name'] ?? $body['name'];
            if (isset($_POST['content']) || isset($body['content']))
                $data['content'] = $_POST['content'] ?? $body['content'];
            if (isset($_POST['category']) || isset($body['category']))
                $data['category'] = $_POST['category'] ?? $body['category'];
            if (isset($_POST['quick_reply']) || isset($body['quick_reply'])) {
                $val = $_POST['quick_reply'] ?? $body['quick_reply'];
                $data['quick_reply'] = ($val === '') ? null : $val;
            }

            if (empty($data)) {
                sendError('No data to update');
            }

            $templateService = loadService('TemplateService', $db, $lineAccountId);
            if (!$templateService) {
                sendError('Template service not available', 503);
            }

            try {
                $success = $templateService->updateTemplate($id, $data);
                if ($success) {
                    sendResponse([
                        'success' => true,
                        'message' => 'Template updated successfully'
                    ]);
                } else {
                    sendError('Failed to update template');
                }
            } catch (Exception $e) {
                sendError('Failed to update template: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /delete_template - Delete a template
        // ============================================
        case 'delete_template':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $id = (int) ($_POST['id'] ?? $body['id'] ?? 0);

            if (!$id) {
                sendError('Template ID is required');
            }

            $templateService = loadService('TemplateService', $db, $lineAccountId);
            if (!$templateService) {
                sendError('Template service not available', 503);
            }

            try {
                $success = $templateService->deleteTemplate($id);
                if ($success) {
                    sendResponse([
                        'success' => true,
                        'message' => 'Template deleted successfully'
                    ]);
                } else {
                    sendError('Failed to delete template');
                }
            } catch (Exception $e) {
                sendError('Failed to delete template: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /update_chat_status - Update chat status
        // ============================================
        case 'update_chat_status':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_POST['user_id'] ?? 0);
            $status = trim($_POST['status'] ?? '');

            // Whitelist allowed statuses
            $allowedStatuses = ['', 'pending', 'completed', 'shipping', 'tracking', 'billing'];

            if (!$userId) {
                sendError('User ID is required');
            }

            if (!in_array($status, $allowedStatuses)) {
                sendError('Invalid status');
            }

            try {
                // Get old status for history
                $stmt = $db->prepare("SELECT chat_status FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $oldStatus = $stmt->fetchColumn();

                // Update status
                $stmt = $db->prepare("UPDATE users SET chat_status = ? WHERE id = ?");
                $stmt->execute([$status ?: null, $userId]);

                // Log to history
                try {
                    $adminId = $_SESSION['admin_user']['id'] ?? null;
                    $stmt = $db->prepare("INSERT INTO chat_status_history (user_id, line_account_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$userId, $lineAccountId, $oldStatus, $status ?: null, $adminId]);
                } catch (Exception $e) {
                    // History table might not exist yet, ignore
                }

                sendResponse([
                    'success' => true,
                    'message' => 'Chat status updated successfully'
                ]);
            } catch (Exception $e) {
                sendError('Failed to update chat status: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /mark_all_read - Mark all messages as read
        // ============================================
        case 'mark_all_read':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            try {
                $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE line_account_id = ? AND direction = 'incoming' AND is_read = 0");
                $stmt->execute([$lineAccountId]);
                $affected = $stmt->rowCount();

                sendResponse([
                    'success' => true,
                    'message' => "Marked {$affected} messages as read"
                ]);
            } catch (Exception $e) {
                sendError('Failed to mark messages as read: ' . $e->getMessage());
            }
            break;

        // ============================================
        // GET /get_admins - Get list of admin users for assignment
        // ============================================
        case 'get_admins':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            try {
                $stmt = $db->prepare("
                    SELECT id, username, display_name, role 
                    FROM admin_users 
                    WHERE (line_account_id = ? OR line_account_id IS NULL)
                    AND is_active = 1
                    ORDER BY display_name ASC
                ");
                $stmt->execute([$lineAccountId]);
                $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

                sendResponse([
                    'success' => true,
                    'data' => $admins
                ]);
            } catch (Exception $e) {
                sendError('Failed to get admin list: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /assign_conversation - Assign conversation to admin(s)
        // Supports multiple assignees
        // ============================================
        case 'assign_conversation':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = intval($_POST['user_id'] ?? $body['user_id'] ?? 0);
            $assignTo = $_POST['assign_to'] ?? $body['assign_to'] ?? null;

            if (!$userId) {
                sendError('User ID is required');
            }
            if (empty($assignTo)) {
                sendError('Admin ID(s) to assign is required');
            }

            // Parse JSON string if needed
            if (is_string($assignTo)) {
                $decoded = json_decode($assignTo, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $assignTo = $decoded;
                }
            }

            // Support both single ID and array of IDs
            $adminIds = is_array($assignTo) ? $assignTo : [$assignTo];
            $adminIds = array_map('intval', $adminIds);
            $adminIds = array_filter($adminIds); // Remove zeros

            if (empty($adminIds)) {
                sendError('Valid admin ID(s) required');
            }

            try {
                require_once __DIR__ . '/../classes/InboxService.php';
                $inboxService = new InboxService($db, $lineAccountId);

                $assignedBy = $_SESSION['admin_id'] ?? null;
                $success = $inboxService->assignConversation($userId, $adminIds, $assignedBy);

                if ($success) {
                    sendResponse([
                        'success' => true,
                        'message' => count($adminIds) > 1
                            ? 'มอบหมายงานให้ ' . count($adminIds) . ' คนสำเร็จ'
                            : 'มอบหมายงานสำเร็จ',
                        'assigned_count' => count($adminIds)
                    ]);
                } else {
                    sendError('Failed to assign conversation');
                }
            } catch (Exception $e) {
                sendError('Failed to assign conversation: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /unassign_conversation - Remove assignment (all or specific admin)
        // ============================================
        case 'unassign_conversation':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = intval($_POST['user_id'] ?? $body['user_id'] ?? 0);
            $adminId = intval($_POST['admin_id'] ?? $body['admin_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            try {
                require_once __DIR__ . '/../classes/InboxService.php';
                $inboxService = new InboxService($db, $lineAccountId);

                if ($adminId > 0) {
                    // Remove specific admin
                    $success = $inboxService->removeAssignee($userId, $adminId);
                    $message = 'ยกเลิกการมอบหมายสำเร็จ';
                } else {
                    // Remove all assignments
                    $success = $inboxService->unassignConversation($userId);
                    $message = 'ยกเลิกการมอบหมายทั้งหมดสำเร็จ';
                }

                sendResponse([
                    'success' => $success,
                    'message' => $message
                ]);
            } catch (Exception $e) {
                sendError('Failed to unassign conversation: ' . $e->getMessage());
            }
            break;

        // ============================================
        // GET /get_assignment - Get current assignment for user (multi-assignee)
        // ============================================
        case 'get_assignment':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = intval($_GET['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            try {
                require_once __DIR__ . '/../classes/InboxService.php';
                $inboxService = new InboxService($db, $lineAccountId);

                $assignment = $inboxService->getAssignment($userId);

                sendResponse([
                    'success' => true,
                    'data' => $assignment
                ]);
            } catch (Exception $e) {
                sendError('Failed to get assignment: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /mark_as_read_on_line - Mark messages as read on LINE
        // Uses LINE Messaging API to show "Read" status to user
        // ============================================
        case 'mark_as_read_on_line':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $userId = intval($_POST['user_id'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            try {
                // Get LINE account credentials
                $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
                $stmt->execute([$lineAccountId]);
                $account = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$account || empty($account['channel_access_token'])) {
                    sendError('LINE account not configured');
                }

                // Get messages with mark_as_read_token that haven't been marked on LINE yet
                // Note: Don't check is_read because local read status is updated before this API runs
                $stmt = $db->prepare("
                    SELECT id, mark_as_read_token 
                    FROM messages 
                    WHERE user_id = ? 
                    AND line_account_id = ? 
                    AND direction = 'incoming' 
                    AND mark_as_read_token IS NOT NULL 
                    AND (is_read_on_line = 0 OR is_read_on_line IS NULL)
                    ORDER BY created_at DESC
                    LIMIT 10
                ");
                $stmt->execute([$userId, $lineAccountId]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($messages)) {
                    // No messages to mark, just update is_read
                    $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND line_account_id = ? AND direction = 'incoming' AND is_read = 0");
                    $stmt->execute([$userId, $lineAccountId]);

                    sendResponse([
                        'success' => true,
                        'message' => 'No messages with markAsReadToken to process',
                        'marked_count' => 0
                    ]);
                }

                // Load LineAPI
                require_once __DIR__ . '/../classes/LineAPI.php';
                $lineApi = new LineAPI($account['channel_access_token']);

                $markedCount = 0;
                $errors = [];

                // Mark each message as read on LINE (only need to mark the latest one)
                // According to LINE API, marking one message marks all previous messages as read
                $latestMessage = $messages[0];

                // Debug logging
                error_log("[mark_as_read_on_line] User ID: {$userId}, Token: " . substr($latestMessage['mark_as_read_token'] ?? 'NULL', 0, 20) . "...");

                $result = $lineApi->markAsRead($latestMessage['mark_as_read_token']);

                error_log("[mark_as_read_on_line] Result: " . json_encode($result));

                if ($result['success']) {
                    // Update all messages as read on LINE
                    $messageIds = array_column($messages, 'id');
                    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                    $stmt = $db->prepare("UPDATE messages SET is_read = 1, is_read_on_line = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($messageIds);
                    $markedCount = count($messageIds);
                } else {
                    $errors[] = $result['error'] ?? 'Unknown error';
                    // Still mark as read locally even if LINE API fails
                    $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND line_account_id = ? AND direction = 'incoming' AND is_read = 0");
                    $stmt->execute([$userId, $lineAccountId]);
                }

                sendResponse([
                    'success' => true,
                    'message' => 'Messages marked as read',
                    'marked_count' => $markedCount,
                    'line_api_success' => empty($errors),
                    'errors' => $errors
                ]);

            } catch (Exception $e) {
                sendError('Failed to mark as read: ' . $e->getMessage());
            }
            break;

        // ============================================
        // PERFORMANCE UPGRADE ENDPOINTS
        // Requirements: Inbox V2 Performance Upgrade
        // ============================================

        // ============================================
        // GET /getConversations - Get conversations with cursor pagination
        // Supports delta updates (only since timestamp)
        // Requirements: 7.1, 7.2
        // ============================================
        case 'getConversations':
        case 'get_conversations':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $since = (int) ($_GET['since'] ?? 0);
            $cursor = $_GET['cursor'] ?? null;
            $limit = (int) ($_GET['limit'] ?? 50);

            // Search and filter parameters
            $search = isset($_GET['search']) ? trim($_GET['search']) : null;
            $filters = [];

            // Parse filter parameters
            if (!empty($_GET['chatStatus'])) {
                $filters['chatStatus'] = $_GET['chatStatus'];
            }
            if (!empty($_GET['unreadOnly']) && $_GET['unreadOnly'] === 'true') {
                $filters['unreadOnly'] = true;
            }
            if (!empty($_GET['tagId'])) {
                $filters['tagId'] = (int) $_GET['tagId'];
            }
            if (!empty($_GET['assigneeId'])) {
                $filters['assigneeId'] = $_GET['assigneeId']; // Can be 'unassigned' or admin ID
            }

            // Validate limit
            if ($limit < 1 || $limit > 100) {
                $limit = 50;
            }

            try {
                require_once __DIR__ . '/../classes/InboxService.php';
                $inboxService = new InboxService($db, $lineAccountId);

                $result = $inboxService->getConversationsDelta($lineAccountId, $since, $cursor, $limit, $search, $filters);

                // Don't cache search results (they change frequently)
                if ($search || !empty($filters)) {
                    header("Cache-Control: no-cache, no-store, must-revalidate");
                } else {
                    // Add ETag for HTTP caching
                    $etag = md5(json_encode($result));
                    header("ETag: \"{$etag}\"");
                    header("Cache-Control: private, max-age=30");

                    // Check If-None-Match header
                    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
                    if ($ifNoneMatch === "\"{$etag}\"") {
                        http_response_code(304);
                        exit;
                    }
                }

                sendResponse([
                    'success' => true,
                    'data' => $result,
                    'search' => $search,
                    'filters' => $filters
                ]);
            } catch (Exception $e) {
                sendError('Failed to get conversations: ' . $e->getMessage());
            }
            break;

        // ============================================
        // GET /getMessages - Get messages with cursor pagination
        // Requirements: 7.2
        // ============================================
        case 'getMessages':
        case 'get_messages':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);
            $cursor = $_GET['cursor'] ?? null;
            $limit = (int) ($_GET['limit'] ?? 50);

            if (!$userId) {
                sendError('User ID is required');
            }

            // Validate limit
            if ($limit < 1 || $limit > 100) {
                $limit = 50;
            }

            try {
                require_once __DIR__ . '/../classes/InboxService.php';
                $inboxService = new InboxService($db, $lineAccountId);

                $result = $inboxService->getMessagesCursor($userId, $cursor, $limit);

                // Add ETag for HTTP caching
                $etag = md5(json_encode($result));
                header("ETag: \"{$etag}\"");
                header("Cache-Control: private, max-age=30");

                // Check If-None-Match header
                $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
                if ($ifNoneMatch === "\"{$etag}\"") {
                    http_response_code(304);
                    exit;
                }

                sendResponse([
                    'success' => true,
                    'data' => $result
                ]);
            } catch (Exception $e) {
                sendError('Failed to get messages: ' . $e->getMessage());
            }
            break;

        // ============================================
        // GET /poll - Poll for delta updates
        // Returns only new messages since last check
        // Requirements: 4.3
        // ============================================
        case 'poll':
        case 'poll_updates':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $since = (int) ($_GET['since'] ?? 0);

            if (!$since) {
                sendError('Since timestamp is required');
            }

            try {
                require_once __DIR__ . '/../classes/InboxService.php';
                $inboxService = new InboxService($db, $lineAccountId);

                $result = $inboxService->pollUpdates($lineAccountId, $since);

                // Add Last-Modified header for HTTP caching
                $lastModified = gmdate('D, d M Y H:i:s', time()) . ' GMT';
                header("Last-Modified: {$lastModified}");
                header("Cache-Control: no-cache, must-revalidate");

                // Check If-Modified-Since header
                $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
                if (!empty($result['new_messages']) && $ifModifiedSince === $lastModified) {
                    http_response_code(304);
                    exit;
                }

                sendResponse([
                    'success' => true,
                    'data' => $result,
                    'timestamp' => time()
                ]);
            } catch (Exception $e) {
                sendError('Failed to poll updates: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /logPerformanceMetric - Log performance metrics
        // Requirements: 12.1, 12.2, 12.3, 12.4
        // ============================================
        case 'logPerformanceMetric':
        case 'log_performance_metric':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            try {
                // Get JSON input
                $input = json_decode(file_get_contents('php://input'), true);

                if (!$input) {
                    sendError('Invalid JSON input');
                }

                // Support both single metric and batch of metrics
                $metrics = isset($input['metrics']) ? $input['metrics'] : [$input];

                require_once __DIR__ . '/../classes/PerformanceMetricsService.php';
                $perfService = new PerformanceMetricsService($db, $lineAccountId);

                $successCount = 0;
                $failCount = 0;

                foreach ($metrics as $metric) {
                    $metricType = $metric['metric_type'] ?? null;
                    $durationMs = $metric['duration_ms'] ?? null;
                    $userAgent = $metric['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null;
                    $operationDetails = $metric['operation_details'] ?? null;

                    if (!$metricType || $durationMs === null) {
                        $failCount++;
                        continue;
                    }

                    $result = $perfService->logMetric(
                        $metricType,
                        $durationMs,
                        $userAgent,
                        $operationDetails
                    );

                    if ($result) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }

                sendResponse([
                    'success' => true,
                    'message' => "Logged {$successCount} metrics" . ($failCount > 0 ? ", {$failCount} failed" : ''),
                    'logged' => $successCount,
                    'failed' => $failCount
                ]);

            } catch (Exception $e) {
                sendError('Failed to log performance metrics: ' . $e->getMessage());
            }
            break;

        // ============================================
        // GET /getPerformanceMetrics - Get performance statistics
        // Requirements: 12.1
        // ============================================
        case 'getPerformanceMetrics':
        case 'get_performance_metrics':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            try {
                $startDate = $_GET['start_date'] ?? null;
                $endDate = $_GET['end_date'] ?? null;

                require_once __DIR__ . '/../classes/PerformanceMetricsService.php';
                $perfService = new PerformanceMetricsService($db, $lineAccountId);

                // Get statistics for all metric types
                $stats = $perfService->getAllMetricStats($startDate, $endDate);

                // Calculate error rates for each type
                $thresholds = [
                    'page_load' => 2000,
                    'conversation_switch' => 1000,
                    'message_render' => 200,
                    'api_call' => 500
                ];

                foreach ($stats as $type => $data) {
                    if (isset($thresholds[$type])) {
                        $stats[$type]['error_rate'] = $perfService->getErrorRate(
                            $type,
                            $thresholds[$type],
                            $startDate,
                            $endDate
                        );
                    }
                }

                sendResponse([
                    'success' => true,
                    'data' => $stats
                ]);

            } catch (Exception $e) {
                sendError('Failed to get performance metrics: ' . $e->getMessage());
            }
            break;

        // ============================================
        // GET /search_conversations - Search conversations by name, message, or tag
        // ============================================
        case 'search_conversations':
        case 'search-conversations':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $query = trim($_GET['query'] ?? '');
            $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));

            if (empty($query)) {
                sendError('Search query is required');
            }

            try {
                $searchTerm = '%' . $query . '%';

                // Search in users table (name, phone) and messages table (content)
                // Also include tag matches
                $sql = "
                    SELECT DISTINCT
                        u.id,
                        u.user_id,
                        u.display_name,
                        u.picture_url,
                        u.phone,
                        (SELECT content FROM messages 
                         WHERE user_id = u.id 
                         AND line_account_id = ? 
                         ORDER BY timestamp DESC LIMIT 1) as last_message_preview,
                        (SELECT timestamp FROM messages 
                         WHERE user_id = u.id 
                         AND line_account_id = ? 
                         ORDER BY timestamp DESC LIMIT 1) as last_message_time,
                        (SELECT COUNT(*) FROM messages 
                         WHERE user_id = u.id 
                         AND line_account_id = ? 
                         AND is_read = 0 
                         AND direction = 'incoming') as unread_count
                    FROM users u
                    WHERE u.line_account_id = ?
                    AND (
                        u.display_name LIKE ?
                        OR u.phone LIKE ?
                        OR u.id IN (
                            SELECT DISTINCT user_id 
                            FROM messages 
                            WHERE line_account_id = ? 
                            AND content LIKE ?
                        )
                        OR u.id IN (
                            SELECT DISTINCT uta.user_id
                            FROM user_tag_assignments uta
                            JOIN user_tags ut ON uta.tag_id = ut.id
                            WHERE ut.line_account_id = ?
                            AND ut.name LIKE ?
                        )
                    )
                    ORDER BY last_message_time DESC
                    LIMIT ?
                ";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $lineAccountId, // last_message_preview
                    $lineAccountId, // last_message_time
                    $lineAccountId, // unread_count
                    $lineAccountId, // main WHERE
                    $searchTerm,    // display_name
                    $searchTerm,    // phone
                    $lineAccountId, // messages subquery
                    $searchTerm,    // message content
                    $lineAccountId, // tags subquery
                    $searchTerm,    // tag_name
                    $limit
                ]);

                $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

                sendResponse([
                    'success' => true,
                    'data' => [
                        'conversations' => $conversations,
                        'count' => count($conversations),
                        'query' => $query
                    ]
                ]);

            } catch (Exception $e) {
                sendError('Failed to search conversations: ' . $e->getMessage());
            }
            break;

        // ============================================
        // GET /get_chat_content - Get chat messages and user info for AJAX switching
        // ============================================
        case 'get_chat_content':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $userId = (int) ($_GET['user_id'] ?? 0);
            $limit = min((int) ($_GET['limit'] ?? 50), 100);
            $offset = (int) ($_GET['offset'] ?? 0);

            if (!$userId) {
                sendError('User ID is required');
            }

            try {
                // Get user info
                $userStmt = $db->prepare("
                    SELECT u.*, 
                           (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread_count
                    FROM users u
                    WHERE u.id = ? AND u.line_account_id = ?
                ");
                $userStmt->execute([$userId, $lineAccountId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    sendError('User not found', 404);
                }

                // Get messages
                $msgStmt = $db->prepare("
                    SELECT id, direction, message_type, content, created_at, is_read, sent_by
                    FROM messages 
                    WHERE user_id = ? AND line_account_id = ?
                    ORDER BY created_at ASC
                    LIMIT ? OFFSET ?
                ");
                $msgStmt->execute([$userId, $lineAccountId, $limit, $offset]);
                $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

                // Get total message count
                $countStmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND line_account_id = ?");
                $countStmt->execute([$userId, $lineAccountId]);
                $totalMessages = $countStmt->fetchColumn();

                // Get user tags
                $tagsStmt = $db->prepare("
                    SELECT ut.id, ut.name, ut.color
                    FROM user_tag_assignments uta
                    JOIN user_tags ut ON uta.tag_id = ut.id
                    WHERE uta.user_id = ?
                ");
                $tagsStmt->execute([$userId]);
                $tags = $tagsStmt->fetchAll(PDO::FETCH_ASSOC);

                // Get assignees
                $assignStmt = $db->prepare("
                    SELECT cma.admin_id, au.username, au.display_name
                    FROM conversation_multi_assignees cma
                    LEFT JOIN admin_users au ON cma.admin_id = au.id
                    WHERE cma.user_id = ? AND cma.status = 'active'
                ");
                $assignStmt->execute([$userId]);
                $assignees = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

                // Mark messages as read
                $updateStmt = $db->prepare("
                    UPDATE messages SET is_read = 1 
                    WHERE user_id = ? AND line_account_id = ? AND direction = 'incoming' AND is_read = 0
                ");
                $updateStmt->execute([$userId, $lineAccountId]);

                sendResponse([
                    'success' => true,
                    'data' => [
                        'user' => [
                            'id' => (int) $user['id'],
                            'display_name' => $user['display_name'],
                            'picture_url' => $user['picture_url'] ?? null,
                            'phone' => $user['phone'] ?? null,
                            'chat_status' => $user['chat_status'] ?? null,
                            'unread_count' => (int) $user['unread_count']
                        ],
                        'messages' => $messages,
                        'total_messages' => (int) $totalMessages,
                        'tags' => $tags,
                        'assignees' => $assignees,
                        'has_more' => ($offset + count($messages)) < $totalMessages
                    ]
                ]);

            } catch (Exception $e) {
                sendError('Failed to get chat content: ' . $e->getMessage());
            }
            break;

        // ============================================
        // POST /send_batch_messages - Send multiple messages at once
        // LINE API supports up to 5 messages per push
        // ============================================
        case 'send_batch_messages':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $body = getJsonBody();
            $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
            $messages = $_POST['messages'] ?? $body['messages'] ?? [];
            $lineUserId = $_POST['line_user_id'] ?? $body['line_user_id'] ?? '';

            if (!$userId) {
                sendError('User ID is required');
            }

            // Parse messages if string
            if (is_string($messages)) {
                $messages = json_decode($messages, true) ?? [];
            }

            if (empty($messages)) {
                sendError('Messages array is required');
            }

            // LINE API limit: max 5 messages per push
            if (count($messages) > 5) {
                sendError('Maximum 5 messages allowed per batch');
            }

            // Get line_user_id if not provided
            if (empty($lineUserId)) {
                $stmt = $db->prepare("SELECT line_user_id FROM users WHERE id = ? AND line_account_id = ?");
                $stmt->execute([$userId, $lineAccountId]);
                $lineUserId = $stmt->fetchColumn();
            }

            if (empty($lineUserId)) {
                sendError('LINE User ID not found for user');
            }

            try {
                // Load LineAPI
                require_once __DIR__ . '/../classes/LineAPI.php';
                $lineAPI = new LineAPI($db, $lineAccountId);

                // Build LINE message array & Prepare DB records
                $lineMessages = [];
                $dbRecords = [];

                foreach ($messages as $msg) {
                    $type = $msg['type'] ?? 'text';
                    $content = $msg['content'] ?? $msg['text'] ?? '';

                    if ($type === 'text') {
                        $content = trim($content);
                        if (!empty($content)) {
                            $lineMessages[] = ['type' => 'text', 'text' => $content];
                            $dbRecords[] = ['type' => 'text', 'content' => $content];
                        }
                    } elseif ($type === 'image') {
                        if (!empty($msg['originalContentUrl']) && !empty($msg['previewImageUrl'])) {
                            $lineMessages[] = [
                                'type' => 'image',
                                'originalContentUrl' => $msg['originalContentUrl'],
                                'previewImageUrl' => $msg['previewImageUrl']
                            ];
                            $dbRecords[] = ['type' => 'image', 'content' => $msg['originalContentUrl']];
                        }
                    } elseif ($type === 'file') {
                        // LINE doesn't support file message type in Push API directly except as text link or Imagemap
                        // But typically we send as text link for simple files
                        if (!empty($msg['originalContentUrl'])) {
                            $fileName = $msg['fileName'] ?? 'File';
                            // Send as text message with link
                            $text = "📄 {$fileName}\n🔗 {$msg['originalContentUrl']}";
                            $lineMessages[] = ['type' => 'text', 'text' => $text];
                            // Store as file in DB if we want, or text. Let's store as file type for history
                            $fileContent = json_encode(['url' => $msg['originalContentUrl'], 'name' => $fileName], JSON_UNESCAPED_UNICODE);
                            $dbRecords[] = ['type' => 'file', 'content' => $fileContent];
                        }
                    }
                }

                if (empty($lineMessages)) {
                    sendError('No valid messages to send');
                }

                // Send batch via pushMessage (supports array of messages)
                $result = $lineAPI->pushMessage($lineUserId, $lineMessages);

                if ($result['code'] !== 200) {
                    sendError('Failed to send messages via LINE: ' . ($result['body']['message'] ?? 'Unknown error'));
                }

                // Save all messages to database
                $insertStmt = $db->prepare("
                    INSERT INTO messages (user_id, line_account_id, direction, message_type, content, is_read, sent_by, created_at)
                    VALUES (?, ?, 'outgoing', ?, ?, 1, ?, NOW())
                ");

                $savedCount = 0;
                foreach ($dbRecords as $record) {
                    $insertStmt->execute([
                        $userId,
                        $lineAccountId,
                        $record['type'],
                        $record['content'],
                        $adminId
                    ]);
                    $savedCount++;
                }

                // Update user last_message_at
                $updateStmt = $db->prepare("UPDATE users SET last_message_at = NOW() WHERE id = ?");
                $updateStmt->execute([$userId]);

                sendResponse([
                    'success' => true,
                    'message' => "Sent {$savedCount} messages successfully",
                    'count' => $savedCount
                ]);

            } catch (Exception $e) {
                sendError('Error sending batch messages: ' . $e->getMessage());
            }
            break;

        case 'upload_batch_file':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                sendError('No file uploaded or upload error');
            }

            $file = $_FILES['file'];
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $maxSize) {
                sendError('File too large (Max 10MB)');
            }

            $allowedTypes = [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'application/pdf'
            ];
            if (!in_array($file['type'], $allowedTypes)) {
                sendError('Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF');
            }

            // Determine directory
            $isImage = strpos($file['type'], 'image/') === 0;
            $uploadDir = __DIR__ . '/../uploads/' . ($isImage ? 'chat_images' : 'chat_files') . '/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = ($isImage ? 'img_' : 'file_') . time() . '_' . uniqid() . '.' . $ext;
            $filepath = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                sendError('Failed to save file');
            }

            // Construct URL
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];
            $baseUrl = $protocol . $host . '/uploads/' . ($isImage ? 'chat_images' : 'chat_files') . '/' . $filename;

            sendResponse([
                'success' => true,
                'type' => $isImage ? 'image' : 'file',
                'url' => $baseUrl,
                'previewUrl' => $baseUrl, // For images, typically same. For videos/files, might differ.
                'fileName' => $file['name']
            ]);
            break;

        // ============================================
        // Default - Unknown action
        // ============================================
        default:
            sendError('Unknown action: ' . $action, 400);

    }

} catch (Throwable $e) {
    error_log("Inbox V2 API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendError('Internal server error: ' . $e->getMessage(), 500);
}
