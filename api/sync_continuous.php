<?php
/**
 * Continuous Sync API
 * API สำหรับ sync ต่อเนื่องผ่าน AJAX
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CnyPharmacyAPI.php';
require_once __DIR__ . '/../classes/SyncWorker.php';

try {
    $db = Database::getInstance()->getConnection();
    $batchSize = isset($_GET['batch_size']) ? intval($_GET['batch_size']) : 10;
    $batchSize = max(1, min(100, $batchSize)); // Limit 1-100
    
    $cnyApi = new CnyPharmacyAPI($db);
    $worker = new SyncWorker($db, $cnyApi);
    
    // Process one batch
    $stats = $worker->processBatch($batchSize);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
