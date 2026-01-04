<?php
/**
 * WMS API - Warehouse Management System (Pick-Pack-Ship)
 * 
 * Endpoints for managing order fulfillment workflow:
 * - Pick operations (Requirements 1.1-1.6, 2.1)
 * - Pack operations (Requirements 3.1, 3.2, 3.5)
 * - Ship operations (Requirements 5.1, 5.2, 5.3)
 * - Dashboard and exceptions (Requirements 6.1-6.3, 9.4, 9.5)
 * - Print operations (Requirements 3.4, 4.1, 8.2, 8.3)
 */
header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/WMSService.php';
require_once __DIR__ . '/../classes/WMSPrintService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

$wmsService = new WMSService($db, $lineAccountId);
$printService = new WMSPrintService($db, $lineAccountId);

// Get action from REQUEST or JSON body
$action = $_REQUEST['action'] ?? '';
if (empty($action)) {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $action = $jsonData['action'] ?? '';
}

// Debug log
error_log("WMS API: action={$action}, method={$_SERVER['REQUEST_METHOD']}");

try {
    switch ($action) {
        // =============================================
        // PICK OPERATIONS (Requirements 1.1-1.6, 2.1)
        // =============================================
        
        /**
         * Get pick queue - orders pending pick
         * Requirements: 1.1, 1.2
         */
        case 'get_pick_queue':
            $filters = [
                'limit' => $_GET['limit'] ?? 50,
                'offset' => $_GET['offset'] ?? 0
            ];
            $orders = $wmsService->getPickQueue($filters);
            echo json_encode(['success' => true, 'orders' => $orders]);
            break;
        
        /**
         * Start picking an order
         * Requirements: 1.3
         */
        case 'start_pick':
            $orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            $pickerId = (int)($_POST['picker_id'] ?? $adminId ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            if (!$pickerId) {
                throw new Exception('Picker ID is required');
            }
            
            $result = $wmsService->startPicking($orderId, $pickerId);
            echo json_encode(['success' => true, 'message' => 'Picking started']);
            break;
        
        /**
         * Get pick list for an order
         * Requirements: 1.4
         */
        case 'get_pick_list':
            $orderId = (int)($_GET['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            $items = $wmsService->getPickList($orderId);
            echo json_encode(['success' => true, 'items' => $items]);
            break;
        
        /**
         * Confirm item picked
         * Requirements: 1.5
         */
        case 'confirm_item_picked':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderId = (int)($data['order_id'] ?? 0);
            $itemId = (int)($data['item_id'] ?? 0);
            $quantityPicked = isset($data['quantity_picked']) ? (int)$data['quantity_picked'] : null;
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            if (!$itemId) {
                throw new Exception('Item ID is required');
            }
            
            $result = $wmsService->confirmItemPicked($orderId, $itemId, $quantityPicked);
            echo json_encode(['success' => true, 'message' => 'Item picked']);
            break;
        
        /**
         * Complete picking for an order
         * Requirements: 1.6
         */
        case 'complete_pick':
            $orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            $result = $wmsService->completePicking($orderId);
            echo json_encode(['success' => true, 'message' => 'Picking completed']);
            break;
        
        /**
         * Create batch pick from multiple orders
         * Requirements: 2.1
         */
        case 'create_batch_pick':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderIds = $data['order_ids'] ?? [];
            
            if (empty($orderIds)) {
                throw new Exception('Order IDs are required');
            }
            
            // Ensure orderIds is an array of integers
            $orderIds = array_map('intval', (array)$orderIds);
            
            $batchId = $wmsService->createBatchPick($orderIds);
            echo json_encode(['success' => true, 'batch_id' => $batchId]);
            break;
        
        /**
         * Get batch pick list
         * Requirements: 2.2, 2.4
         */
        case 'get_batch_pick_list':
            $batchId = (int)($_GET['batch_id'] ?? 0);
            
            if (!$batchId) {
                throw new Exception('Batch ID is required');
            }
            
            $data = $wmsService->getBatchPickList($batchId);
            echo json_encode(['success' => true, 'data' => $data]);
            break;
        
        /**
         * Start batch picking
         */
        case 'start_batch_pick':
            $batchId = (int)($_POST['batch_id'] ?? $_GET['batch_id'] ?? 0);
            $pickerId = (int)($_POST['picker_id'] ?? $adminId ?? 0);
            
            if (!$batchId) {
                throw new Exception('Batch ID is required');
            }
            if (!$pickerId) {
                throw new Exception('Picker ID is required');
            }
            
            $result = $wmsService->startBatchPick($batchId, $pickerId);
            echo json_encode(['success' => true, 'message' => 'Batch picking started']);
            break;
        
        /**
         * Complete batch pick
         * Requirements: 2.3
         */
        case 'complete_batch_pick':
            $batchId = (int)($_POST['batch_id'] ?? $_GET['batch_id'] ?? 0);
            
            if (!$batchId) {
                throw new Exception('Batch ID is required');
            }
            
            $result = $wmsService->completeBatchPick($batchId);
            echo json_encode(['success' => true, 'message' => 'Batch pick completed']);
            break;
        
        /**
         * Cancel batch pick
         */
        case 'cancel_batch_pick':
            $batchId = (int)($_POST['batch_id'] ?? $_GET['batch_id'] ?? 0);
            
            if (!$batchId) {
                throw new Exception('Batch ID is required');
            }
            
            $result = $wmsService->cancelBatchPick($batchId);
            echo json_encode(['success' => true, 'message' => 'Batch pick cancelled']);
            break;
        
        /**
         * Get list of batches
         */
        case 'get_batches':
            $filters = [
                'status' => $_GET['status'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'limit' => $_GET['limit'] ?? 50
            ];
            $batches = $wmsService->getBatches($filters);
            echo json_encode(['success' => true, 'batches' => $batches]);
            break;

        
        // =============================================
        // PACK OPERATIONS (Requirements 3.1, 3.2, 3.5)
        // =============================================
        
        /**
         * Get pack queue - orders ready for packing
         * Requirements: 3.1
         */
        case 'get_pack_queue':
            $filters = [
                'limit' => $_GET['limit'] ?? 50,
                'offset' => $_GET['offset'] ?? 0
            ];
            $orders = $wmsService->getPackQueue($filters);
            echo json_encode(['success' => true, 'orders' => $orders]);
            break;
        
        /**
         * Start packing an order
         * Requirements: 3.2
         */
        case 'start_pack':
            $orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            $packerId = (int)($_POST['packer_id'] ?? $adminId ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            if (!$packerId) {
                throw new Exception('Packer ID is required');
            }
            
            $result = $wmsService->startPacking($orderId, $packerId);
            echo json_encode(['success' => true, 'message' => 'Packing started']);
            break;
        
        /**
         * Complete packing for an order
         * Requirements: 3.5
         */
        case 'complete_pack':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderId = (int)($data['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            // Optional package info
            $packageInfo = null;
            if (isset($data['weight']) || isset($data['dimensions'])) {
                $packageInfo = [
                    'weight' => $data['weight'] ?? null,
                    'dimensions' => $data['dimensions'] ?? null
                ];
            }
            
            $result = $wmsService->completePacking($orderId, $packageInfo);
            echo json_encode(['success' => true, 'message' => 'Packing completed']);
            break;

        
        // =============================================
        // SHIP OPERATIONS (Requirements 5.1, 5.2, 5.3)
        // =============================================
        
        /**
         * Get ship queue - orders ready for shipping
         * Requirements: 5.1
         */
        case 'get_ship_queue':
            $filters = [
                'limit' => $_GET['limit'] ?? 50,
                'offset' => $_GET['offset'] ?? 0
            ];
            $orders = $wmsService->getShipQueue($filters);
            echo json_encode(['success' => true, 'orders' => $orders]);
            break;
        
        /**
         * Assign carrier and tracking number
         * Requirements: 5.1, 5.2
         */
        case 'assign_tracking':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderId = (int)($data['order_id'] ?? 0);
            $carrier = trim($data['carrier'] ?? '');
            $trackingNumber = trim($data['tracking_number'] ?? '');
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            if (empty($carrier)) {
                throw new Exception('Carrier is required');
            }
            if (empty($trackingNumber)) {
                throw new Exception('Tracking number is required');
            }
            
            $result = $wmsService->assignCarrier($orderId, $carrier, $trackingNumber);
            echo json_encode(['success' => true, 'message' => 'Tracking assigned and order shipped']);
            break;
        
        /**
         * Confirm order shipped
         * Requirements: 5.3
         */
        case 'confirm_shipped':
            $orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            $result = $wmsService->confirmShipped($orderId);
            echo json_encode(['success' => true, 'message' => 'Shipment confirmed']);
            break;

        
        // =============================================
        // DASHBOARD OPERATIONS (Requirements 6.1, 6.2, 6.3)
        // =============================================
        
        /**
         * Get WMS dashboard statistics
         * Requirements: 6.1, 6.2
         */
        case 'get_dashboard':
            $stats = $wmsService->getDashboardStats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
        
        /**
         * Get overdue orders
         * Requirements: 6.3
         */
        case 'get_overdue':
            $slaHours = (int)($_GET['sla_hours'] ?? 24);
            $orders = $wmsService->getOverdueOrders($slaHours);
            echo json_encode(['success' => true, 'orders' => $orders]);
            break;
        
        // =============================================
        // EXCEPTION HANDLING (Requirements 9.4, 9.5)
        // =============================================
        
        /**
         * Get orders with exceptions
         * Requirements: 9.4
         */
        case 'get_exceptions':
            $orders = $wmsService->getExceptionOrders();
            echo json_encode(['success' => true, 'orders' => $orders]);
            break;
        
        /**
         * Mark item as short (out of stock)
         * Requirements: 9.1
         */
        case 'mark_item_short':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderId = (int)($data['order_id'] ?? 0);
            $itemId = (int)($data['item_id'] ?? 0);
            $reason = trim($data['reason'] ?? '');
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            if (!$itemId) {
                throw new Exception('Item ID is required');
            }
            if (empty($reason)) {
                throw new Exception('Reason is required');
            }
            
            $result = $wmsService->markItemShort($orderId, $itemId, $reason);
            echo json_encode(['success' => true, 'message' => 'Item marked as short']);
            break;
        
        /**
         * Mark item as damaged
         * Requirements: 9.1
         */
        case 'mark_item_damaged':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderId = (int)($data['order_id'] ?? 0);
            $itemId = (int)($data['item_id'] ?? 0);
            $reason = trim($data['reason'] ?? '');
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            if (!$itemId) {
                throw new Exception('Item ID is required');
            }
            if (empty($reason)) {
                throw new Exception('Reason is required');
            }
            
            $result = $wmsService->markItemDamaged($orderId, $itemId, $reason);
            echo json_encode(['success' => true, 'message' => 'Item marked as damaged']);
            break;
        
        /**
         * Put order on hold
         * Requirements: 9.2
         */
        case 'put_on_hold':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderId = (int)($data['order_id'] ?? 0);
            $reason = trim($data['reason'] ?? '');
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            if (empty($reason)) {
                throw new Exception('Reason is required');
            }
            
            $result = $wmsService->putOrderOnHold($orderId, $reason);
            echo json_encode(['success' => true, 'message' => 'Order put on hold']);
            break;
        
        /**
         * Resolve exception
         * Requirements: 9.5
         */
        case 'resolve_exception':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderId = (int)($data['order_id'] ?? 0);
            $resolution = trim($data['resolution'] ?? '');
            $staffId = (int)($data['staff_id'] ?? $adminId ?? 0);
            $newStatus = $data['new_status'] ?? null;
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            if (empty($resolution)) {
                throw new Exception('Resolution is required');
            }
            if (!$staffId) {
                throw new Exception('Staff ID is required');
            }
            
            $result = $wmsService->resolveException($orderId, $resolution, $staffId, $newStatus);
            echo json_encode(['success' => true, 'message' => 'Exception resolved']);
            break;

        
        // =============================================
        // PRINT OPERATIONS (Requirements 3.4, 4.1, 8.2, 8.3)
        // =============================================
        
        /**
         * Generate packing slip
         * Requirements: 3.4
         */
        case 'print_packing_slip':
            $orderId = (int)($_GET['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            $html = $printService->generatePackingSlip($orderId);
            
            // Return HTML directly for printing
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        
        /**
         * Generate shipping label
         * Requirements: 4.1
         */
        case 'print_shipping_label':
            $orderId = (int)($_GET['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            $html = $printService->generateShippingLabel($orderId);
            
            // Return HTML directly for printing
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        
        /**
         * Generate batch packing slips
         * Requirements: 8.2
         */
        case 'print_batch_packing_slips':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_GET;
            $orderIds = $data['order_ids'] ?? [];
            
            // Support comma-separated IDs from GET
            if (empty($orderIds) && isset($_GET['order_ids'])) {
                $orderIds = explode(',', $_GET['order_ids']);
            }
            
            if (empty($orderIds)) {
                throw new Exception('Order IDs are required');
            }
            
            // Ensure orderIds is an array of integers
            $orderIds = array_map('intval', (array)$orderIds);
            
            $html = $printService->generateBatchPackingSlips($orderIds);
            
            // Return HTML directly for printing
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        
        /**
         * Generate batch shipping labels
         * Requirements: 8.3
         */
        case 'print_batch_labels':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_GET;
            $orderIds = $data['order_ids'] ?? [];
            
            // Support comma-separated IDs from GET
            if (empty($orderIds) && isset($_GET['order_ids'])) {
                $orderIds = explode(',', $_GET['order_ids']);
            }
            
            if (empty($orderIds)) {
                throw new Exception('Order IDs are required');
            }
            
            // Ensure orderIds is an array of integers
            $orderIds = array_map('intval', (array)$orderIds);
            
            $html = $printService->generateBatchLabels($orderIds);
            
            // Return HTML directly for printing
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        
        /**
         * Get orders ready for printing
         */
        case 'get_orders_for_printing':
            $filters = [
                'unprinted_only' => isset($_GET['unprinted_only']) ? (bool)$_GET['unprinted_only'] : false,
                'limit' => $_GET['limit'] ?? 50
            ];
            $orders = $printService->getOrdersForPrinting($filters);
            echo json_encode(['success' => true, 'orders' => $orders]);
            break;
        
        /**
         * Mark labels as printed
         * Requirements: 8.4
         */
        case 'mark_labels_printed':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $orderIds = $data['order_ids'] ?? [];
            
            if (empty($orderIds)) {
                throw new Exception('Order IDs are required');
            }
            
            // Ensure orderIds is an array of integers
            $orderIds = array_map('intval', (array)$orderIds);
            
            $result = $printService->markLabelsPrinted($orderIds);
            echo json_encode(['success' => true, 'message' => 'Labels marked as printed']);
            break;
        
        /**
         * Validate shipping label fields
         */
        case 'validate_shipping_label':
            $orderId = (int)($_GET['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            $validation = $printService->validateShippingLabelFields($orderId);
            echo json_encode(['success' => true, 'validation' => $validation]);
            break;
        
        // =============================================
        // DATA EXPORT (Requirements 10.1, 10.4)
        // =============================================
        
        /**
         * Export fulfillment data to JSON
         * Requirements: 10.1
         */
        case 'export_json':
            $filters = [
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'status' => $_GET['status'] ?? null,
                'limit' => $_GET['limit'] ?? null
            ];
            
            // Handle multiple statuses
            if (!empty($filters['status']) && strpos($filters['status'], ',') !== false) {
                $filters['status'] = explode(',', $filters['status']);
            }
            
            // Handle order_ids if provided
            if (!empty($_GET['order_ids'])) {
                $filters['order_ids'] = array_map('intval', explode(',', $_GET['order_ids']));
            }
            
            $data = $wmsService->exportFulfillmentDataJson($filters);
            
            // Set download headers if requested
            if (isset($_GET['download']) && $_GET['download'] === '1') {
                $filename = 'wms_fulfillment_' . date('Y-m-d_His') . '.json';
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                echo json_encode(['success' => true, 'data' => $data]);
            }
            break;
        
        /**
         * Export shipping data to CSV for carriers
         * Requirements: 10.4
         */
        case 'export_csv':
            $filters = [
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'status' => $_GET['status'] ?? null,
                'carrier' => $_GET['carrier'] ?? null,
                'limit' => $_GET['limit'] ?? null
            ];
            
            // Handle multiple statuses
            if (!empty($filters['status']) && strpos($filters['status'], ',') !== false) {
                $filters['status'] = explode(',', $filters['status']);
            }
            
            // Handle order_ids if provided
            if (!empty($_GET['order_ids'])) {
                $filters['order_ids'] = array_map('intval', explode(',', $_GET['order_ids']));
            }
            
            $csv = $wmsService->exportCarrierCsv($filters);
            
            // Set download headers
            $filename = 'wms_shipping_' . date('Y-m-d_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $csv;
            exit;
        
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
