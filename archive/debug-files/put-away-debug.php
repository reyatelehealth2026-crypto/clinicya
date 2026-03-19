<?php
/**
 * Put Away Debug - Test location suggestion
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

$debug = [];

try {
    // Test 1: Check warehouse_locations table exists
    $debug['table_check'] = 'checking...';
    $stmt = $db->query("SHOW TABLES LIKE 'warehouse_locations'");
    $debug['table_exists'] = $stmt->rowCount() > 0;
    
    // Test 2: Count all locations
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM warehouse_locations");
    $debug['total_locations'] = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    // Test 3: Count active locations
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM warehouse_locations WHERE is_active = 1");
    $debug['active_locations'] = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    // Test 4: Count locations by line_account_id
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM warehouse_locations WHERE line_account_id = ?");
    $stmt->execute([$lineAccountId]);
    $debug['locations_for_account'] = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    $debug['line_account_id'] = $lineAccountId;
    
    // Test 5: Get sample locations
    $stmt = $db->query("SELECT id, location_code, zone, zone_type, is_active, line_account_id FROM warehouse_locations LIMIT 5");
    $debug['sample_locations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Test 6: Test LocationService
    $locationService = new LocationService($db, $lineAccountId);
    $locations = $locationService->getLocations(['is_active' => 1]);
    $debug['service_locations_count'] = count($locations);
    
    // Test 7: Test getAllActiveLocations
    $allLocations = $locationService->getAllActiveLocations();
    $debug['all_active_locations_count'] = count($allLocations);
    
    // Test 8: Get a product to test
    $stmt = $db->query("SELECT id, name FROM business_items WHERE is_active = 1 LIMIT 1");
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    $debug['test_product'] = $product;
    
    // Test 9: Test PutAwayService if product exists
    if ($product) {
        $putAwayService = new PutAwayService($db, $lineAccountId);
        $suggestion = $putAwayService->suggestLocation($product['id']);
        $debug['suggestion_result'] = $suggestion;
    }
    
    $debug['success'] = true;
    
} catch (Exception $e) {
    $debug['error'] = $e->getMessage();
    $debug['trace'] = $e->getTraceAsString();
}

echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
