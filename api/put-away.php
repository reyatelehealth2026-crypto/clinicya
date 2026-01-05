<?php
/**
 * Put Away API - Location Suggestion and Assignment
 * 
 * Endpoints for put away operations:
 * - Location suggestion endpoints (Requirements 4.1, 4.2)
 * - Assignment endpoints (Requirements 3.1, 3.2)
 * - ABC analysis endpoint (Requirements 2.1, 2.2, 2.3, 2.4)
 */
header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LocationService.php';
require_once __DIR__ . '/../classes/BatchService.php';
require_once __DIR__ . '/../classes/PutAwayService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? 1;
$adminId = $_SESSION['admin_user']['id'] ?? null;

$putAwayService = new PutAwayService($db, $lineAccountId);

// Get action from REQUEST or JSON body
$action = $_REQUEST['action'] ?? '';
if (empty($action)) {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $action = $jsonData['action'] ?? '';
}

try {
    switch ($action) {
        // =============================================
        // LOCATION SUGGESTION ENDPOINTS (Requirements 4.1, 4.2)
        // =============================================
        
        /**
         * Suggest optimal location for a product
         * Requirements: 4.1, 4.2
         */
        case 'suggest':
        case 'suggest_location':
            $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            $result = $putAwayService->suggestLocation($productId);
            echo json_encode($result);
            break;
        
        /**
         * Suggest optimal location for a batch
         * Requirements: 4.1, 4.2
         */
        case 'suggest_batch':
        case 'suggest_location_for_batch':
            $batchId = (int)($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0);
            
            if (!$batchId) {
                throw new Exception('Batch ID is required');
            }
            
            $result = $putAwayService->suggestLocationForBatch($batchId);
            echo json_encode($result);
            break;
        
        /**
         * Validate if a product can be assigned to a location
         * Requirements: 3.3, 3.4, 7.1
         */
        case 'validate_zone':
            $productId = (int)($_GET['product_id'] ?? 0);
            $locationId = (int)($_GET['location_id'] ?? 0);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            if (!$locationId) {
                throw new Exception('Location ID is required');
            }
            
            $result = $putAwayService->validateZoneForProduct($productId, $locationId);
            echo json_encode(['success' => true, 'validation' => $result]);
            break;

        
        // =============================================
        // ASSIGNMENT ENDPOINTS (Requirements 3.1, 3.2)
        // =============================================
        
        /**
         * Assign a product to a location
         * Requirements: 3.1, 3.2
         */
        case 'assign_product':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $productId = (int)($data['product_id'] ?? 0);
            $locationId = (int)($data['location_id'] ?? 0);
            $quantity = (int)($data['quantity'] ?? 1);
            $staffId = (int)($data['staff_id'] ?? $adminId ?? 0);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            if (!$locationId) {
                throw new Exception('Location ID is required');
            }
            
            $result = $putAwayService->assignProductToLocation($productId, $locationId, $quantity, $staffId);
            echo json_encode($result);
            break;
        
        /**
         * Assign a batch to a location
         * Requirements: 3.1, 3.2
         */
        case 'assign_batch':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $batchId = (int)($data['batch_id'] ?? 0);
            $locationId = (int)($data['location_id'] ?? 0);
            $staffId = (int)($data['staff_id'] ?? $adminId ?? 0);
            
            if (!$batchId) {
                throw new Exception('Batch ID is required');
            }
            if (!$locationId) {
                throw new Exception('Location ID is required');
            }
            
            $result = $putAwayService->assignBatchToLocation($batchId, $locationId, $staffId);
            echo json_encode($result);
            break;
        
        /**
         * Move a product from one location to another
         * Requirements: 3.1, 3.2, 6.5
         */
        case 'move_product':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $productId = (int)($data['product_id'] ?? 0);
            $fromLocationId = (int)($data['from_location_id'] ?? 0);
            $toLocationId = (int)($data['to_location_id'] ?? 0);
            $quantity = (int)($data['quantity'] ?? 1);
            $staffId = (int)($data['staff_id'] ?? $adminId ?? 0);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            if (!$fromLocationId) {
                throw new Exception('Source location ID is required');
            }
            if (!$toLocationId) {
                throw new Exception('Destination location ID is required');
            }
            
            $result = $putAwayService->moveProduct($productId, $fromLocationId, $toLocationId, $quantity, $staffId);
            echo json_encode($result);
            break;

        
        // =============================================
        // ABC ANALYSIS ENDPOINTS (Requirements 2.1, 2.2, 2.3, 2.4)
        // =============================================
        
        /**
         * Run ABC Analysis on all products
         * Requirements: 2.1, 2.4
         */
        case 'run_abc_analysis':
            $daysBack = (int)($_GET['days'] ?? $_POST['days'] ?? 90);
            
            $result = $putAwayService->runABCAnalysis($daysBack);
            echo json_encode($result);
            break;
        
        /**
         * Get ABC class for a specific product
         * Requirements: 2.1
         */
        case 'get_abc_class':
            $productId = (int)($_GET['product_id'] ?? 0);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            $class = $putAwayService->getProductABCClass($productId);
            echo json_encode([
                'success' => true,
                'product_id' => $productId,
                'abc_class' => $class
            ]);
            break;
        
        /**
         * Update ABC class for a product
         * Requirements: 2.4
         */
        case 'update_abc_class':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $productId = (int)($data['product_id'] ?? 0);
            $class = strtoupper(trim($data['class'] ?? ''));
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            if (!in_array($class, ['A', 'B', 'C'])) {
                throw new Exception('Class must be A, B, or C');
            }
            
            $success = $putAwayService->updateProductABCClass($productId, $class);
            echo json_encode([
                'success' => $success,
                'product_id' => $productId,
                'abc_class' => $class
            ]);
            break;
        
        /**
         * Get products by ABC class
         * Requirements: 2.1, 2.5
         */
        case 'get_products_by_class':
            $class = strtoupper(trim($_GET['class'] ?? ''));
            $limit = (int)($_GET['limit'] ?? 100);
            
            if (!in_array($class, ['A', 'B', 'C'])) {
                throw new Exception('Class must be A, B, or C');
            }
            
            $products = $putAwayService->getProductsByABCClass($class, $limit);
            echo json_encode([
                'success' => true,
                'abc_class' => $class,
                'products' => $products,
                'count' => count($products)
            ]);
            break;
        
        /**
         * Get ABC analysis summary
         * Requirements: 2.1, 2.5
         */
        case 'get_abc_summary':
            $summary = $putAwayService->getABCAnalysisSummary();
            echo json_encode([
                'success' => true,
                'summary' => $summary
            ]);
            break;

        
        // =============================================
        // MOVEMENT HISTORY ENDPOINTS (Requirement 6.5)
        // =============================================
        
        /**
         * Get movement history for a product
         * Requirements: 6.5
         */
        case 'get_movement_history':
            $productId = (int)($_GET['product_id'] ?? 0);
            $limit = (int)($_GET['limit'] ?? 50);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            $movements = $putAwayService->getMovementHistory($productId, $limit);
            echo json_encode([
                'success' => true,
                'product_id' => $productId,
                'movements' => $movements,
                'count' => count($movements)
            ]);
            break;
        
        /**
         * Get movement history for a batch
         * Requirements: 6.5, 8.6
         */
        case 'get_batch_movement_history':
            $batchId = (int)($_GET['batch_id'] ?? 0);
            
            if (!$batchId) {
                throw new Exception('Batch ID is required');
            }
            
            $movements = $putAwayService->getBatchMovementHistory($batchId);
            echo json_encode([
                'success' => true,
                'batch_id' => $batchId,
                'movements' => $movements,
                'count' => count($movements)
            ]);
            break;
        
        // =============================================
        // QUICK ASSIGN FROM PLANOGRAM
        // =============================================
        
        /**
         * Quick assign product to location (from Planogram view)
         */
        case 'assign_to_location':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $locationId = (int)($data['location_id'] ?? 0);
            $productId = (int)($data['product_id'] ?? 0);
            $quantity = (int)($data['quantity'] ?? 0);
            $batchNumber = $data['batch_number'] ?? null;
            $notes = $data['notes'] ?? null;
            
            if (!$locationId) {
                throw new Exception('Location ID is required');
            }
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than 0');
            }
            
            // Get location info
            $locationService = new LocationService($db, $lineAccountId);
            $location = $locationService->getLocation($locationId);
            if (!$location) {
                // Try without line_account_id filter
                $stmt = $db->prepare("SELECT * FROM warehouse_locations WHERE id = ?");
                $stmt->execute([$locationId]);
                $location = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$location) {
                throw new Exception('Location not found');
            }
            
            // Check capacity
            $availableCapacity = $location['capacity'] - $location['current_qty'];
            if ($quantity > $availableCapacity) {
                throw new Exception("ความจุไม่เพียงพอ (ว่าง: {$availableCapacity}, ต้องการ: {$quantity})");
            }
            
            // Create batch if batch_number provided
            $batchId = null;
            if ($batchNumber) {
                $batchService = new BatchService($db, $lineAccountId);
                $batchId = $batchService->createBatch([
                    'product_id' => $productId,
                    'batch_number' => $batchNumber,
                    'quantity' => $quantity,
                    'quantity_available' => $quantity,
                    'location_id' => $locationId,
                    'status' => 'active'
                ]);
            }
            
            // Update location quantity
            $stmt = $db->prepare("
                UPDATE warehouse_locations 
                SET current_qty = current_qty + ? 
                WHERE id = ?
            ");
            $stmt->execute([$quantity, $locationId]);
            
            // Log movement
            try {
                $stmt = $db->prepare("
                    INSERT INTO location_movements 
                    (line_account_id, product_id, batch_id, to_location_id, quantity, movement_type, notes, created_at)
                    VALUES (?, ?, ?, ?, ?, 'put_away', ?, NOW())
                ");
                $stmt->execute([$lineAccountId, $productId, $batchId, $locationId, $quantity, $notes]);
            } catch (Exception $e) {
                // Table might not exist, continue anyway
            }
            
            echo json_encode([
                'success' => true,
                'message' => "จัดเก็บสินค้า {$quantity} ชิ้น ที่ตำแหน่ง {$location['location_code']} สำเร็จ",
                'location_id' => $locationId,
                'batch_id' => $batchId
            ]);
            break;
        
        // =============================================
        // GET PRODUCTS IN LOCATION (with actual stock)
        // =============================================
        
        /**
         * Get all products in a specific location with actual stock
         */
        case 'get_location_products':
            $locationId = (int)($_GET['location_id'] ?? 0);
            
            if (!$locationId) {
                throw new Exception('Location ID is required');
            }
            
            $stmt = $db->prepare("
                SELECT ib.*, 
                       bi.name as product_name, bi.sku, bi.stock as actual_stock,
                       DATEDIFF(ib.expiry_date, CURDATE()) as days_until_expiry
                FROM inventory_batches ib
                JOIN business_items bi ON ib.product_id = bi.id
                WHERE ib.location_id = ? AND ib.status = 'active' AND ib.quantity_available > 0
                ORDER BY ib.expiry_date ASC, bi.name ASC
            ");
            $stmt->execute([$locationId]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'products' => $products,
                'count' => count($products)
            ]);
            break;
        
        // =============================================
        // DEFAULT
        // =============================================
        
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
