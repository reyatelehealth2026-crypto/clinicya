<?php
/**
 * CNY Sync API
 * API endpoints สำหรับ sync dashboard
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CnyPharmacyAPI.php';

$db = Database::getInstance()->getConnection();
$cnyApi = new CnyPharmacyAPI($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'test':
            // Test API connection
            $result = $cnyApi->testConnection();
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'stats':
            // Get sync stats
            $stats = $cnyApi->getSyncStats();
            echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'update_categories':
            // Update all product categories from CNY data
            $result = $cnyApi->updateAllProductCategories();
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get_categories':
            // Get CNY categories
            $categories = $cnyApi->getCnyCategories();
            echo json_encode(['success' => true, 'data' => $categories], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
