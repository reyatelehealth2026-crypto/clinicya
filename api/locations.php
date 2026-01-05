<?php
/**
 * Locations API - Warehouse Location Management
 * 
 * Endpoints for managing warehouse storage locations:
 * - CRUD operations for locations (Requirements 1.1, 1.2)
 * - Zone hierarchy endpoints (Requirement 1.3)
 * - Utilization endpoints (Requirements 1.4, 5.1)
 */
header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LocationService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? 1;

$locationService = new LocationService($db, $lineAccountId);

// Get action from REQUEST or JSON body
$action = $_REQUEST['action'] ?? '';
if (empty($action)) {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $action = $jsonData['action'] ?? '';
}

try {
    switch ($action) {
        // =============================================
        // CRUD OPERATIONS (Requirements 1.1, 1.2)
        // =============================================
        
        /**
         * Create a new location
         * Requirements: 1.1, 1.2
         */
        case 'create':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            if (empty($data['zone']) || !isset($data['shelf']) || !isset($data['bin'])) {
                throw new Exception('Zone, shelf, and bin are required');
            }
            
            $locationId = $locationService->createLocation([
                'zone' => $data['zone'],
                'shelf' => (int)$data['shelf'],
                'bin' => (int)$data['bin'],
                'location_code' => $data['location_code'] ?? null,
                'zone_type' => $data['zone_type'] ?? 'general',
                'ergonomic_level' => $data['ergonomic_level'] ?? 'golden',
                'capacity' => $data['capacity'] ?? 100,
                'description' => $data['description'] ?? null
            ]);
            
            $location = $locationService->getLocation($locationId);
            echo json_encode(['success' => true, 'location' => $location, 'id' => $locationId]);
            break;
        
        /**
         * Update an existing location
         * Requirements: 1.1, 1.2
         */
        case 'update':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Location ID is required');
            }
            
            $locationService->updateLocation($id, $data);
            $location = $locationService->getLocation($id);
            echo json_encode(['success' => true, 'location' => $location]);
            break;
        
        /**
         * Delete a location (soft delete)
         * Requirements: 1.1
         */
        case 'delete':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Location ID is required');
            }
            
            $locationService->deleteLocation($id);
            echo json_encode(['success' => true, 'message' => 'Location deleted']);
            break;

        
        /**
         * Get a single location by ID
         * Requirements: 1.1
         */
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Location ID is required');
            }
            
            $location = $locationService->getLocation($id);
            if (!$location) {
                throw new Exception('Location not found');
            }
            
            // Add capacity info
            $location['capacity_info'] = $locationService->getLocationCapacity($id);
            
            echo json_encode(['success' => true, 'location' => $location]);
            break;
        
        /**
         * Get location by code
         * Requirements: 1.1
         */
        case 'get_by_code':
            $code = trim($_GET['code'] ?? '');
            
            if (empty($code)) {
                throw new Exception('Location code is required');
            }
            
            $location = $locationService->getLocationByCode($code);
            if (!$location) {
                throw new Exception('Location not found');
            }
            
            // Add capacity info
            $location['capacity_info'] = $locationService->getLocationCapacity($location['id']);
            
            echo json_encode(['success' => true, 'location' => $location]);
            break;
        
        /**
         * Get all locations with optional filters
         * Requirements: 1.1, 1.3
         */
        case 'list':
            $filters = [
                'zone' => $_GET['zone'] ?? null,
                'zone_type' => $_GET['zone_type'] ?? null,
                'ergonomic_level' => $_GET['ergonomic_level'] ?? null,
                'is_active' => isset($_GET['is_active']) ? (int)$_GET['is_active'] : 1,
                'has_capacity' => isset($_GET['has_capacity']) ? (bool)$_GET['has_capacity'] : false,
                'limit' => $_GET['limit'] ?? null
            ];
            
            // Remove null filters
            $filters = array_filter($filters, function($v) { return $v !== null; });
            
            $locations = $locationService->getLocations($filters);
            echo json_encode(['success' => true, 'locations' => $locations, 'count' => count($locations)]);
            break;
        
        /**
         * Validate location code format
         * Requirements: 1.1
         */
        case 'validate_code':
            $code = trim($_GET['code'] ?? '');
            
            if (empty($code)) {
                throw new Exception('Location code is required');
            }
            
            $isValid = $locationService->validateLocationCode($code);
            $parsed = $locationService->parseLocationCode($code);
            $exists = $locationService->getLocationByCode($code) !== null;
            
            echo json_encode([
                'success' => true,
                'code' => $code,
                'is_valid_format' => $isValid,
                'parsed' => $parsed,
                'already_exists' => $exists
            ]);
            break;
        
        /**
         * Bulk create locations for a zone
         * Requirements: 1.1, 1.2
         */
        case 'bulk_create':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            if (empty($data['zone'])) {
                throw new Exception('Zone is required');
            }
            if (empty($data['shelves']) || $data['shelves'] < 1) {
                throw new Exception('Number of shelves is required');
            }
            if (empty($data['bins_per_shelf']) || $data['bins_per_shelf'] < 1) {
                throw new Exception('Number of bins per shelf is required');
            }
            
            $createdIds = $locationService->bulkCreateLocations(
                $data['zone'],
                $data['zone_type'] ?? 'general',
                (int)$data['shelves'],
                (int)$data['bins_per_shelf'],
                (int)($data['capacity'] ?? 100)
            );
            
            echo json_encode([
                'success' => true,
                'message' => count($createdIds) . ' locations created',
                'created_count' => count($createdIds),
                'created_ids' => $createdIds
            ]);
            break;

        
        // =============================================
        // ZONE HIERARCHY ENDPOINTS (Requirement 1.3)
        // =============================================
        
        /**
         * Get all zones with statistics
         * Requirements: 1.3
         */
        case 'get_zones':
            $zones = $locationService->getZones();
            echo json_encode(['success' => true, 'zones' => $zones]);
            break;
        
        /**
         * Get shelves in a specific zone
         * Requirements: 1.3
         */
        case 'get_shelves':
            $zone = trim($_GET['zone'] ?? '');
            
            if (empty($zone)) {
                throw new Exception('Zone is required');
            }
            
            $shelves = $locationService->getShelvesInZone($zone);
            echo json_encode(['success' => true, 'zone' => $zone, 'shelves' => $shelves]);
            break;
        
        /**
         * Get bins in a specific shelf
         * Requirements: 1.3
         */
        case 'get_bins':
            $zone = trim($_GET['zone'] ?? '');
            $shelf = (int)($_GET['shelf'] ?? 0);
            
            if (empty($zone)) {
                throw new Exception('Zone is required');
            }
            if (!$shelf) {
                throw new Exception('Shelf number is required');
            }
            
            $bins = $locationService->getBinsInShelf($zone, $shelf);
            echo json_encode(['success' => true, 'zone' => $zone, 'shelf' => $shelf, 'bins' => $bins]);
            break;
        
        /**
         * Get full zone hierarchy (zones -> shelves -> bins)
         * Requirements: 1.3
         */
        case 'get_hierarchy':
            $zones = $locationService->getZones();
            $hierarchy = [];
            
            foreach ($zones as $zone) {
                $zoneName = $zone['zone'];
                $shelves = $locationService->getShelvesInZone($zoneName);
                
                $shelfData = [];
                foreach ($shelves as $shelf) {
                    $bins = $locationService->getBinsInShelf($zoneName, $shelf['shelf']);
                    $shelfData[] = [
                        'shelf' => $shelf['shelf'],
                        'bin_count' => $shelf['bin_count'],
                        'total_capacity' => $shelf['total_capacity'],
                        'total_qty' => $shelf['total_qty'],
                        'bins' => $bins
                    ];
                }
                
                $hierarchy[] = [
                    'zone' => $zoneName,
                    'zone_type' => $zone['zone_type'],
                    'location_count' => $zone['location_count'],
                    'total_capacity' => $zone['total_capacity'],
                    'total_qty' => $zone['total_qty'],
                    'shelves' => $shelfData
                ];
            }
            
            echo json_encode(['success' => true, 'hierarchy' => $hierarchy]);
            break;
        
        // =============================================
        // UTILIZATION ENDPOINTS (Requirements 1.4, 5.1)
        // =============================================
        
        /**
         * Get location capacity info
         * Requirements: 1.4
         */
        case 'get_capacity':
            $id = (int)($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Location ID is required');
            }
            
            $capacity = $locationService->getLocationCapacity($id);
            echo json_encode(['success' => true, 'capacity' => $capacity]);
            break;
        
        /**
         * Check if location is available (has capacity)
         * Requirements: 1.4
         */
        case 'check_availability':
            $id = (int)($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Location ID is required');
            }
            
            $isAvailable = $locationService->isLocationAvailable($id);
            $capacity = $locationService->getLocationCapacity($id);
            
            echo json_encode([
                'success' => true,
                'is_available' => $isAvailable,
                'capacity' => $capacity
            ]);
            break;
        
        /**
         * Get zone utilization percentage
         * Requirements: 5.1
         */
        case 'get_zone_utilization':
            $zone = trim($_GET['zone'] ?? '');
            
            if (empty($zone)) {
                throw new Exception('Zone is required');
            }
            
            $utilization = $locationService->getZoneUtilization($zone);
            echo json_encode([
                'success' => true,
                'zone' => $zone,
                'utilization_percent' => $utilization
            ]);
            break;
        
        /**
         * Get warehouse-wide utilization statistics
         * Requirements: 5.1
         */
        case 'get_warehouse_utilization':
            $utilization = $locationService->getWarehouseUtilization();
            echo json_encode(['success' => true, 'utilization' => $utilization]);
            break;
        
        /**
         * Get underutilized locations
         * Requirements: 5.1
         */
        case 'get_underutilized':
            $threshold = (float)($_GET['threshold'] ?? 20.0);
            $locations = $locationService->getUnderutilizedLocations($threshold);
            echo json_encode([
                'success' => true,
                'threshold' => $threshold,
                'locations' => $locations,
                'count' => count($locations)
            ]);
            break;
        
        /**
         * Update location quantity
         * Requirements: 1.4
         */
        case 'update_quantity':
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            $quantityChange = (int)($data['quantity_change'] ?? 0);
            
            if (!$id) {
                throw new Exception('Location ID is required');
            }
            if ($quantityChange === 0) {
                throw new Exception('Quantity change is required');
            }
            
            $locationService->updateLocationQuantity($id, $quantityChange);
            $location = $locationService->getLocation($id);
            
            echo json_encode(['success' => true, 'location' => $location]);
            break;
        
        // =============================================
        // LABEL PRINTING ENDPOINTS (Requirements 6.1, 6.2)
        // =============================================
        
        /**
         * Generate location label for a single location
         * Requirements: 6.1, 6.2
         */
        case 'print_label':
            require_once __DIR__ . '/../classes/WMSPrintService.php';
            $printService = new WMSPrintService($db, $lineAccountId);
            
            $id = (int)($_GET['id'] ?? 0);
            $withQr = isset($_GET['with_qr']) && $_GET['with_qr'] === '1';
            
            if (!$id) {
                throw new Exception('Location ID is required');
            }
            
            $location = $locationService->getLocation($id);
            if (!$location) {
                throw new Exception('Location not found');
            }
            
            // Return HTML for printing
            header('Content-Type: text/html; charset=utf-8');
            if ($withQr) {
                echo $printService->generateLocationLabelWithQR($location);
            } else {
                echo $printService->generateLocationLabel($location);
            }
            exit;
        
        /**
         * Generate batch location labels
         * Requirements: 6.1, 6.2
         */
        case 'print_batch_labels':
            require_once __DIR__ . '/../classes/WMSPrintService.php';
            $printService = new WMSPrintService($db, $lineAccountId);
            
            $withQr = isset($_GET['with_qr']) && $_GET['with_qr'] === '1';
            
            // Get filters from request
            $filters = [];
            if (!empty($_GET['zone'])) {
                $filters['zone'] = $_GET['zone'];
            }
            if (!empty($_GET['zone_type'])) {
                $filters['zone_type'] = $_GET['zone_type'];
            }
            if (!empty($_GET['shelf'])) {
                $filters['shelf'] = (int)$_GET['shelf'];
            }
            if (!empty($_GET['ids'])) {
                $filters['location_ids'] = array_map('intval', explode(',', $_GET['ids']));
            }
            if (!empty($_GET['limit'])) {
                $filters['limit'] = (int)$_GET['limit'];
            }
            
            $locations = $printService->getLocationsForPrinting($filters);
            
            if (empty($locations)) {
                throw new Exception('No locations found for printing');
            }
            
            // Return HTML for printing
            header('Content-Type: text/html; charset=utf-8');
            if ($withQr) {
                echo $printService->generateBatchLocationLabelsWithQR($locations);
            } else {
                echo $printService->generateBatchLocationLabels($locations);
            }
            exit;
        
        /**
         * Format location code for display
         * Requirements: 6.2
         */
        case 'format_location':
            require_once __DIR__ . '/../classes/WMSPrintService.php';
            $printService = new WMSPrintService($db, $lineAccountId);
            
            $code = trim($_GET['code'] ?? '');
            
            if (empty($code)) {
                throw new Exception('Location code is required');
            }
            
            $formatted = $printService->formatLocationForDisplay($code);
            echo json_encode(['success' => true, 'formatted' => $formatted]);
            break;
        
        /**
         * Get locations ready for label printing
         * Requirements: 6.1
         */
        case 'get_for_printing':
            require_once __DIR__ . '/../classes/WMSPrintService.php';
            $printService = new WMSPrintService($db, $lineAccountId);
            
            $filters = [];
            if (!empty($_GET['zone'])) {
                $filters['zone'] = $_GET['zone'];
            }
            if (!empty($_GET['zone_type'])) {
                $filters['zone_type'] = $_GET['zone_type'];
            }
            if (!empty($_GET['shelf'])) {
                $filters['shelf'] = (int)$_GET['shelf'];
            }
            if (!empty($_GET['limit'])) {
                $filters['limit'] = (int)$_GET['limit'];
            }
            
            $locations = $printService->getLocationsForPrinting($filters);
            
            // Add formatted display for each location
            foreach ($locations as &$loc) {
                $loc['formatted'] = $printService->formatLocationForDisplay($loc['location_code']);
            }
            
            echo json_encode([
                'success' => true,
                'locations' => $locations,
                'count' => count($locations)
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
    error_log("Locations API Error: " . $e->getMessage() . " | Action: " . $action);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
