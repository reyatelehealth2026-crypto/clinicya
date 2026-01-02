<?php
/**
 * Advanced Analytics Tab Content
 * วิเคราะห์ขั้นสูง - MVC Pattern
 * 
 * Variables expected from parent:
 * - $db: Database connection
 */

// Load and register Autoloader
require_once __DIR__ . '/../../app/Core/Autoloader.php';

$autoloader = new \App\Core\Autoloader();
$autoloader->addNamespace('App', __DIR__ . '/../../app');
$autoloader->register();

// Check admin access
if (!function_exists('isAdmin')) {
    require_once __DIR__ . '/../auth_check.php';
}

if (!isAdmin() && !isSuperAdmin()) {
    echo '<div class="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
        <div class="text-4xl mb-2">🔒</div>
        <p class="text-red-700">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</p>
    </div>';
    return;
}

// Route actions
$action = $_GET['action'] ?? 'dashboard';

// Initialize controller
$controller = new App\Controllers\AnalyticsController();

// Handle API requests
switch ($action) {
    case 'api_stats':
        $controller->apiStats();
        exit;
        
    case 'api_realtime':
        $controller->apiRealtime();
        exit;
        
    case 'api_funnel':
        $controller->apiFunnel();
        exit;
        
    case 'export':
        $controller->export();
        exit;
        
    case 'dashboard':
    default:
        // Render dashboard view
        $controller->dashboard();
        break;
}
