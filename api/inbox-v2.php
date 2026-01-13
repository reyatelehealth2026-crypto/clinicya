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

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

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
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Get LINE account ID and admin ID from session or request
$lineAccountId = $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? $_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1;
$adminId = $_SESSION['admin_id'] ?? $_GET['admin_id'] ?? $_POST['admin_id'] ?? null;

/**
 * Load service class with error handling
 * @param string $className Service class name
 * @return object|null Service instance or null if not available
 */
function loadService(string $className, $db, $lineAccountId) {
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
function sendResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 * @param string $message Error message
 * @param int $statusCode HTTP status code
 */
function sendError(string $message, int $statusCode = 400): void {
    sendResponse(['success' => false, 'error' => $message], $statusCode);
}

/**
 * Get request body as JSON
 * @return array Parsed JSON body
 */
function getJsonBody(): array {
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
                sendError('AI API key not configured', 503);
            }
            
            $result = $imageAnalyzer->analyzeSymptom($imageUrl);
            
            sendResponse([
                'success' => $result['success'] ?? false,
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
                sendError('AI API key not configured', 503);
            }
            
            $result = $imageAnalyzer->identifyDrug($imageUrl);
            
            sendResponse([
                'success' => $result['success'] ?? false,
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
            $userId = (int)($_POST['user_id'] ?? $body['user_id'] ?? 0);
            
            if (empty($imageUrl)) {
                sendError('Image URL is required');
            }
            
            $imageAnalyzer = loadService('PharmacyImageAnalyzerService', $db, $lineAccountId);
            
            if (!$imageAnalyzer) {
                sendError('Image analyzer service not available', 503);
            }
            
            if (!$imageAnalyzer->isConfigured()) {
                sendError('AI API key not configured', 503);
            }
            
            $result = $imageAnalyzer->ocrPrescription($imageUrl, $userId ?: null);
            
            sendResponse([
                'success' => $result['success'] ?? false,
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            $minMessages = (int)($_GET['min_messages'] ?? 5);
            
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
            $userId = (int)($_POST['user_id'] ?? $body['user_id'] ?? 0);
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
            $userId = (int)($_POST['user_id'] ?? $body['user_id'] ?? 0);
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
            
            $drugId = (int)($_GET['drug_id'] ?? $_GET['id'] ?? 0);
            $drugName = $_GET['name'] ?? '';
            
            if (!$drugId && empty($drugName)) {
                sendError('Drug ID or name is required');
            }
            
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
                    WHERE bi.name LIKE ? OR bi.sku LIKE ?
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
                $pricing = $pricingEngine->calculateMargin((int)$drug['id']);
            }
            
            sendResponse([
                'success' => true,
                'data' => [
                    'id' => (int)$drug['id'],
                    'name' => $drug['name'],
                    'sku' => $drug['sku'],
                    'description' => $drug['description'] ?? null,
                    'price' => (float)($drug['price'] ?? 0),
                    'salePrice' => (float)($drug['sale_price'] ?? 0),
                    'category' => $drug['category_name'],
                    'imageUrl' => $drug['image_url'] ?? null,
                    'stock' => (int)($drug['stock_quantity'] ?? 0),
                    'isActive' => (bool)($drug['is_active'] ?? true),
                    'isPrescription' => (bool)($drug['is_prescription'] ?? false),
                    'pricing' => $pricing
                ]
            ]);
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
            
            $drugId = (int)($_GET['drug_id'] ?? $_GET['id'] ?? 0);
            $customPrice = isset($_GET['custom_price']) ? (float)$_GET['custom_price'] : null;
            
            if (!$drugId) {
                sendError('Drug ID is required');
            }
            
            $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);
            
            if (!$pricingEngine) {
                sendError('Pricing engine service not available', 503);
            }
            
            if ($customPrice !== null) {
                $result = $pricingEngine->calculateMarginImpact($drugId, $customPrice);
            } else {
                $result = $pricingEngine->calculateMargin($drugId);
            }
            
            sendResponse([
                'success' => !isset($result['error']),
                'data' => $result
            ]);
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
            
            $drugId = (int)($_GET['drug_id'] ?? $_GET['id'] ?? 0);
            $minMargin = isset($_GET['min_margin']) ? (float)$_GET['min_margin'] : 10.0;
            
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
            $drugId = (int)($_POST['drug_id'] ?? $body['drug_id'] ?? 0);
            $requestedDiscount = (float)($_POST['discount'] ?? $body['discount'] ?? 0);
            
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            
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
            $userId = (int)($_POST['user_id'] ?? $body['user_id'] ?? 0);
            
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            
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
            
            $productId = (int)($_GET['product_id'] ?? $_GET['drug_id'] ?? $_GET['id'] ?? 0);
            
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
            $limit = (int)($_GET['limit'] ?? 20);
            
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
            
            $productId = (int)($_GET['product_id'] ?? $_GET['drug_id'] ?? $_GET['id'] ?? 0);
            
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
            $userId = (int)($_POST['user_id'] ?? $body['user_id'] ?? 0);
            $productId = (int)($_POST['product_id'] ?? $body['product_id'] ?? $body['drug_id'] ?? 0);
            
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            $limit = (int)($_GET['limit'] ?? 20);
            
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
            
            $limit = (int)($_GET['limit'] ?? 50);
            
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            $symptoms = $_GET['symptoms'] ?? '';
            $type = $_GET['type'] ?? '';
            $limit = (int)($_GET['limit'] ?? 5);
            
            if (!$userId) {
                sendError('User ID is required');
            }
            
            // If type=context, get popular/recent drugs from business_items instead of requiring symptoms
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
                            'id' => (int)$drug['id'],
                            'drugId' => (int)$drug['id'],
                            'name' => $drug['name'],
                            'sku' => $drug['sku'],
                            'price' => (float)($drug['sale_price'] ?? $drug['price'] ?? 0),
                            'originalPrice' => (float)($drug['price'] ?? 0),
                            'stock' => (int)($drug['stock'] ?? 0),
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
            $userId = (int)($_POST['user_id'] ?? $body['user_id'] ?? 0);
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            
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
            
            $drugId = (int)($_GET['drug_id'] ?? $_GET['id'] ?? 0);
            
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
            
            $drugId = (int)($_GET['drug_id'] ?? $_GET['id'] ?? 0);
            $userId = (int)($_GET['user_id'] ?? 0);
            
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
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
            
            $userId = (int)($_GET['user_id'] ?? 0);
            
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
            
            $pharmacistId = (int)($_GET['pharmacist_id'] ?? $adminId ?? 0);
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
                $totalConsultations = (int)($summary['total_consultations'] ?? 0);
                $successfulConsultations = (int)($summary['successful_consultations'] ?? 0);
                $successRate = $totalConsultations > 0 
                    ? round(($successfulConsultations / $totalConsultations) * 100, 2) 
                    : 0;
                
                // Calculate AI acceptance rate
                $totalAiSuggestions = (int)($summary['total_ai_suggestions'] ?? 0);
                $acceptedAiSuggestions = (int)($summary['accepted_ai_suggestions'] ?? 0);
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
                            'avgResponseTime' => round((float)($summary['avg_response_time'] ?? 0), 2),
                            'avgMessagesPerConsultation' => round((float)($summary['avg_messages_per_consultation'] ?? 0), 1),
                            'totalRevenue' => (float)($summary['total_revenue'] ?? 0),
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
            $userId = (int)($_POST['user_id'] ?? $body['user_id'] ?? 0);
            
            if (!$userId) {
                sendError('User ID is required');
            }
            
            $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);
            
            if (!$consultationAnalyzer) {
                sendError('Consultation analyzer service not available', 503);
            }
            
            $analyticsData = [
                'pharmacistId' => (int)($_POST['pharmacist_id'] ?? $body['pharmacist_id'] ?? $adminId ?? null),
                'communicationType' => $_POST['communication_type'] ?? $body['communication_type'] ?? null,
                'stageAtClose' => $_POST['stage_at_close'] ?? $body['stage_at_close'] ?? null,
                'responseTimeAvg' => isset($_POST['response_time_avg']) ? (int)$_POST['response_time_avg'] : (isset($body['response_time_avg']) ? (int)$body['response_time_avg'] : null),
                'messageCount' => isset($_POST['message_count']) ? (int)$_POST['message_count'] : (isset($body['message_count']) ? (int)$body['message_count'] : null),
                'aiSuggestionsShown' => (int)($_POST['ai_suggestions_shown'] ?? $body['ai_suggestions_shown'] ?? 0),
                'aiSuggestionsAccepted' => (int)($_POST['ai_suggestions_accepted'] ?? $body['ai_suggestions_accepted'] ?? 0),
                'resultedInPurchase' => filter_var($_POST['resulted_in_purchase'] ?? $body['resulted_in_purchase'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'purchaseAmount' => isset($_POST['purchase_amount']) ? (float)$_POST['purchase_amount'] : (isset($body['purchase_amount']) ? (float)$body['purchase_amount'] : null),
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
        // Default - Unknown action
        // ============================================
        default:
            sendError('Unknown action: ' . $action, 400);
    }

} catch (Throwable $e) {
    error_log("Inbox V2 API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendError('Internal server error: ' . $e->getMessage(), 500);
}
