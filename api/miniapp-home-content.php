<?php
/**
 * Mini App Home Content API
 * 
 * Actions:
 *   GET ?action=home_all                     — ดึงทุกอย่าง (banners + sections + products) ใน 1 call
 *   GET ?action=banners&position=home_top    — ดึง active banners
 *   GET ?action=sections                     — ดึง active sections พร้อม products
 *   GET ?action=section_products&section_id=X — ดึงสินค้าตาม section
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/MiniAppContentService.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? 'home_all';
$lineAccountId = isset($_GET['line_account_id']) ? (int) $_GET['line_account_id'] : null;
$surface = $_GET['surface'] ?? 'home';

$service = new MiniAppContentService($db, $lineAccountId);

try {
    switch ($action) {
        case 'home_all':
            $data = $service->getHomeAll($surface);
            echo json_encode([
                'success' => true,
                'data' => $data,
                'timestamp' => date('c')
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'banners':
            $position = $_GET['position'] ?? 'home_top';
            $limit = min(20, max(1, (int) ($_GET['limit'] ?? 10)));
            $banners = $service->getActiveBanners($position, $limit, $surface);
            echo json_encode([
                'success' => true,
                'data' => ['banners' => $banners],
                'count' => count($banners),
                'timestamp' => date('c')
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'sections':
            $limit = min(20, max(1, (int) ($_GET['limit'] ?? 10)));
            $sections = $service->getActiveSections($limit, $surface);
            echo json_encode([
                'success' => true,
                'data' => ['sections' => $sections],
                'count' => count($sections),
                'timestamp' => date('c')
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'section_products':
            $sectionId = (int) ($_GET['section_id'] ?? 0);
            if ($sectionId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing section_id']);
                exit;
            }
            $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
            $products = $service->getProductsBySection($sectionId, $limit);
            echo json_encode([
                'success' => true,
                'data' => ['products' => $products],
                'count' => count($products),
                'timestamp' => date('c')
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    error_log("MiniApp Home Content API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
