<?php
/**
 * Batches API - Inventory Batch/Lot Management
 * 
 * Endpoints for managing inventory batches:
 * - CRUD operations for batches (Requirements 8.1, 8.2)
 * - Expiry query endpoints (Requirements 8.3, 8.4, 8.5)
 * - FIFO/FEFO endpoints (Requirements 9.1, 9.2, 9.3)
 */
header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/BatchService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? 1;
$adminId = $_SESSION['admin_user']['id'] ?? null;

$batchService = new BatchService($db, $lineAccountId);

// Get action from REQUEST or JSON body
$action = $_REQUEST['action'] ?? '';
if (empty($action)) {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $action = $jsonData['action'] ?? '';
}

try {
    switch ($action) {
        // =============================================
        // CRUD OPERATIONS (Requirements 8.1, 8.2)
        // =============================================
        
        /**
         * Create a new batch
         * Requirements: 8.1, 8.2
         */
        case 'create':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            if (empty($data['product_id'])) {
                throw new Exception('Product ID is required');
            }
            if (empty($data['batch_number'])) {
                throw new Exception('Batch number is required');
            }
            if (!isset($data['quantity']) || $data['quantity'] < 0) {
                throw new Exception('Valid quantity is required');
            }
            
            $batchId = $batchService->createBatch([
                'product_id' => (int)$data['product_id'],
                'batch_number' => $data['batch_number'],
                'lot_number' => $data['lot_number'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'quantity' => (int)$data['quantity'],
                'quantity_available' => $data['quantity_available'] ?? $data['quantity'],
                'cost_price' => $data['cost_price'] ?? null,
                'manufacture_date' => $data['manufacture_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'received_at' => $data['received_at'] ?? date('Y-m-d H:i:s'),
                'received_by' => $data['received_by'] ?? $adminId,
                'location_id' => $data['location_id'] ?? null,
                'notes' => $data['notes'] ?? null
            ]);
            
            $batch = $batchService->getBatch($batchId);
            echo json_encode(['success' => true, 'batch' => $batch, 'id' => $batchId]);
            break;
        
        /**
         * Update an existing batch
         * Requirements: 8.1, 8.2
         */
        case 'update':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Batch ID is required');
            }
            
            $batchService->updateBatch($id, $data);
            $batch = $batchService->getBatch($id);
            echo json_encode(['success' => true, 'batch' => $batch]);
            break;
        
        /**
         * Get a single batch by ID
         * Requirements: 8.1
         */
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Batch ID is required');
            }
            
            $batch = $batchService->getBatch($id);
            if (!$batch) {
                throw new Exception('Batch not found');
            }
            
            echo json_encode(['success' => true, 'batch' => $batch]);
            break;

        
        /**
         * Get batch by batch number
         * Requirements: 8.1
         */
        case 'get_by_number':
            $batchNumber = trim($_GET['batch_number'] ?? '');
            $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
            
            if (empty($batchNumber)) {
                throw new Exception('Batch number is required');
            }
            
            $batch = $batchService->getBatchByNumber($batchNumber, $productId);
            if (!$batch) {
                throw new Exception('Batch not found');
            }
            
            echo json_encode(['success' => true, 'batch' => $batch]);
            break;
        
        /**
         * Get all batches with optional filters
         * Requirements: 8.1, 8.3
         */
        case 'list':
            $filters = [
                'product_id' => isset($_GET['product_id']) ? (int)$_GET['product_id'] : null,
                'status' => $_GET['status'] ?? null,
                'has_stock' => isset($_GET['has_stock']) ? (bool)$_GET['has_stock'] : false,
                'location_id' => isset($_GET['location_id']) ? (int)$_GET['location_id'] : null,
                'supplier_id' => isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null,
                'limit' => $_GET['limit'] ?? null
            ];
            
            // Remove null filters
            $filters = array_filter($filters, function($v) { return $v !== null; });
            
            $batches = $batchService->getBatches($filters);
            echo json_encode(['success' => true, 'batches' => $batches, 'count' => count($batches)]);
            break;
        
        /**
         * Get batches for a specific product
         * Requirements: 8.3
         */
        case 'get_for_product':
            $productId = (int)($_GET['product_id'] ?? 0);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            $filters = [
                'status' => $_GET['status'] ?? null,
                'has_stock' => isset($_GET['has_stock']) ? (bool)$_GET['has_stock'] : false,
                'location_id' => isset($_GET['location_id']) ? (int)$_GET['location_id'] : null,
                'limit' => $_GET['limit'] ?? null
            ];
            
            // Remove null filters
            $filters = array_filter($filters, function($v) { return $v !== null; });
            
            $batches = $batchService->getBatchesForProduct($productId, $filters);
            echo json_encode(['success' => true, 'product_id' => $productId, 'batches' => $batches, 'count' => count($batches)]);
            break;
        
        /**
         * Get batch statistics for a product
         * Requirements: 8.3
         */
        case 'get_statistics':
            $productId = (int)($_GET['product_id'] ?? 0);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            $stats = $batchService->getBatchStatistics($productId);
            echo json_encode(['success' => true, 'product_id' => $productId, 'statistics' => $stats]);
            break;
        
        /**
         * Reduce batch quantity (for picking)
         * Requirements: 8.3
         */
        case 'reduce_quantity':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            $quantity = (int)($data['quantity'] ?? 0);
            
            if (!$id) {
                throw new Exception('Batch ID is required');
            }
            if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than 0');
            }
            
            $batchService->reduceQuantity($id, $quantity);
            $batch = $batchService->getBatch($id);
            
            echo json_encode(['success' => true, 'batch' => $batch]);
            break;

        
        // =============================================
        // EXPIRY QUERY ENDPOINTS (Requirements 8.4, 8.5)
        // =============================================
        
        /**
         * Get batches expiring within specified days
         * Requirements: 8.4
         */
        case 'get_expiring':
            $daysAhead = (int)($_GET['days'] ?? 90);
            $filters = [
                'product_id' => isset($_GET['product_id']) ? (int)$_GET['product_id'] : null,
                'limit' => $_GET['limit'] ?? null
            ];
            
            // Remove null filters
            $filters = array_filter($filters, function($v) { return $v !== null; });
            
            $batches = $batchService->getExpiringBatches($daysAhead, $filters);
            echo json_encode([
                'success' => true,
                'days_ahead' => $daysAhead,
                'batches' => $batches,
                'count' => count($batches)
            ]);
            break;
        
        /**
         * Get near-expiry batches (within 30 days)
         * Requirements: 8.4, 10.1
         */
        case 'get_near_expiry':
            $filters = [
                'product_id' => isset($_GET['product_id']) ? (int)$_GET['product_id'] : null,
                'limit' => $_GET['limit'] ?? null
            ];
            
            // Remove null filters
            $filters = array_filter($filters, function($v) { return $v !== null; });
            
            $batches = $batchService->getExpiringBatches(30, $filters);
            echo json_encode([
                'success' => true,
                'alert_threshold_days' => 30,
                'batches' => $batches,
                'count' => count($batches)
            ]);
            break;
        
        /**
         * Get all expired batches
         * Requirements: 8.5
         */
        case 'get_expired':
            $filters = [
                'product_id' => isset($_GET['product_id']) ? (int)$_GET['product_id'] : null,
                'has_stock' => isset($_GET['has_stock']) ? (bool)$_GET['has_stock'] : false,
                'limit' => $_GET['limit'] ?? null
            ];
            
            // Remove null filters
            $filters = array_filter($filters, function($v) { return $v !== null; });
            
            $batches = $batchService->getExpiredBatches($filters);
            echo json_encode([
                'success' => true,
                'batches' => $batches,
                'count' => count($batches)
            ]);
            break;
        
        /**
         * Flag all expired batches (update status to 'expired')
         * Requirements: 8.5
         */
        case 'flag_expired':
            $count = $batchService->flagExpiredBatches();
            echo json_encode([
                'success' => true,
                'message' => "{$count} batches flagged as expired",
                'flagged_count' => $count
            ]);
            break;
        
        /**
         * Dispose a batch (requires pharmacist approval)
         * Requirements: 10.4
         */
        case 'dispose':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            $pharmacistId = (int)($data['pharmacist_id'] ?? $adminId ?? 0);
            $reason = trim($data['reason'] ?? '');
            
            if (!$id) {
                throw new Exception('Batch ID is required');
            }
            if (!$pharmacistId) {
                throw new Exception('Pharmacist ID is required for disposal approval');
            }
            if (empty($reason)) {
                throw new Exception('Disposal reason is required');
            }
            
            $batchService->disposeBatch($id, $pharmacistId, $reason);
            $batch = $batchService->getBatch($id);
            
            echo json_encode(['success' => true, 'message' => 'Batch disposed', 'batch' => $batch]);
            break;
        
        /**
         * Dispose a batch with stock update and expense creation
         * 
         * This action performs a complete disposal operation:
         * 1. Updates batch status to 'disposed' and sets quantity_available to 0
         * 2. Decreases stock in business_items
         * 3. Creates stock_movement with type 'disposal'
         * 4. Creates expense record for inventory write-off
         * 
         * Requirements: 2.1, 2.2, 5.1
         */
        case 'dispose_batch':
            require_once __DIR__ . '/../classes/InventoryService.php';
            require_once __DIR__ . '/../classes/ExpenseService.php';
            
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $batchId = (int)($data['batch_id'] ?? $data['id'] ?? $_GET['batch_id'] ?? 0);
            $pharmacistId = (int)($data['pharmacist_id'] ?? $adminId ?? 0);
            $reason = trim($data['reason'] ?? '');
            
            // Validate required fields
            if (!$batchId) {
                throw new Exception('Batch ID is required');
            }
            if (!$pharmacistId) {
                throw new Exception('Pharmacist ID is required for disposal approval');
            }
            if (empty($reason)) {
                throw new Exception('Disposal reason is required');
            }
            
            // Initialize services
            $inventoryService = new InventoryService($db, $lineAccountId);
            $expenseService = new ExpenseService($db, $lineAccountId);
            
            // Perform disposal with stock update and expense creation
            $result = $batchService->disposeBatchWithStock(
                $batchId,
                $pharmacistId,
                $reason,
                $inventoryService,
                $expenseService
            );
            
            // Get updated batch data
            $batch = $batchService->getBatch($batchId);
            
            echo json_encode([
                'success' => true,
                'message' => 'Batch disposed successfully',
                'batch' => $batch,
                'disposal_result' => [
                    'batch_id' => $result['batch_id'],
                    'product_id' => $result['product_id'],
                    'disposed_quantity' => $result['disposed_quantity'],
                    'cost_price' => $result['cost_price'],
                    'disposal_value' => $result['disposal_value'],
                    'reason' => $result['reason'],
                    'category' => $result['category'],
                    'expense_id' => $result['expense_id']
                ]
            ]);
            break;

        
        // =============================================
        // FIFO/FEFO ENDPOINTS (Requirements 9.1, 9.2, 9.3)
        // =============================================
        
        /**
         * Get next batch for picking (FEFO or FIFO)
         * Requirements: 9.1, 9.2
         */
        case 'get_next_for_picking':
            $productId = (int)($_GET['product_id'] ?? 0);
            $method = strtoupper($_GET['method'] ?? 'FEFO');
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            if (!in_array($method, ['FEFO', 'FIFO'])) {
                throw new Exception('Method must be FEFO or FIFO');
            }
            
            $batch = $batchService->getNextBatchForPicking($productId, $method);
            
            if (!$batch) {
                echo json_encode([
                    'success' => true,
                    'batch' => null,
                    'message' => 'No available batches for picking'
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'batch' => $batch,
                    'method' => $method
                ]);
            }
            break;
        
        /**
         * Get batches sorted by expiry date (FEFO order)
         * Requirements: 9.1, 9.3
         */
        case 'get_sorted_by_expiry':
            $productId = (int)($_GET['product_id'] ?? 0);
            $activeOnly = !isset($_GET['include_inactive']) || !$_GET['include_inactive'];
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            $batches = $batchService->getBatchesSortedByExpiry($productId, $activeOnly);
            echo json_encode([
                'success' => true,
                'product_id' => $productId,
                'sort_method' => 'FEFO',
                'batches' => $batches,
                'count' => count($batches)
            ]);
            break;
        
        /**
         * Get batches sorted by receive date (FIFO order)
         * Requirements: 9.2
         */
        case 'get_sorted_by_receive_date':
            $productId = (int)($_GET['product_id'] ?? 0);
            $activeOnly = !isset($_GET['include_inactive']) || !$_GET['include_inactive'];
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            $batches = $batchService->getBatchesSortedByReceiveDate($productId, $activeOnly);
            echo json_encode([
                'success' => true,
                'product_id' => $productId,
                'sort_method' => 'FIFO',
                'batches' => $batches,
                'count' => count($batches)
            ]);
            break;
        
        /**
         * Get picking recommendation for a product
         * Returns the recommended batch based on product type
         * Requirements: 9.1, 9.2, 9.3
         */
        case 'get_picking_recommendation':
            $productId = (int)($_GET['product_id'] ?? 0);
            
            if (!$productId) {
                throw new Exception('Product ID is required');
            }
            
            // Try FEFO first (for products with expiry)
            $fefoBatch = $batchService->getNextBatchForPicking($productId, 'FEFO');
            
            // Also get FIFO for comparison
            $fifoBatch = $batchService->getNextBatchForPicking($productId, 'FIFO');
            
            // Determine recommended method
            $recommendedMethod = 'FEFO';
            $recommendedBatch = $fefoBatch;
            
            // If FEFO batch has no expiry, use FIFO
            if ($fefoBatch && empty($fefoBatch['expiry_date'])) {
                $recommendedMethod = 'FIFO';
                $recommendedBatch = $fifoBatch;
            }
            
            echo json_encode([
                'success' => true,
                'product_id' => $productId,
                'recommended_method' => $recommendedMethod,
                'recommended_batch' => $recommendedBatch,
                'fefo_batch' => $fefoBatch,
                'fifo_batch' => $fifoBatch
            ]);
            break;
        
        // =============================================
        // PRODUCT SEARCH (for batch creation)
        // =============================================
        
        /**
         * Search products by name or SKU
         */
        case 'search_products':
            $query = trim($_GET['q'] ?? '');
            
            if (strlen($query) < 2) {
                echo json_encode(['success' => true, 'products' => []]);
                break;
            }
            
            $searchTerm = '%' . $query . '%';
            $stmt = $db->prepare("
                SELECT id, name, sku, barcode, stock, unit 
                FROM business_items 
                WHERE is_active = 1 
                AND (name LIKE ? OR sku LIKE ? OR barcode LIKE ?)
                ORDER BY name
                LIMIT 20
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'products' => $products]);
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
