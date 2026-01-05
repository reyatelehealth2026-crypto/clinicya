<?php
/**
 * Sync Worker Runner
 * รัน worker ผ่าน browser หรือ CLI
 * 
 * URL Parameters:
 * - mode: batch (default), continuous, direct
 * - batch_size: จำนวนต่อ batch (default: 10)
 * - max_jobs: จำกัดจำนวน jobs (0 = unlimited)
 * - api: 1 = return JSON (สำหรับ AJAX)
 * - reset: 1 = reset progress (สำหรับ mode=direct)
 */

ini_set('memory_limit', '1024M');
set_time_limit(600); // 10 minutes
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/CnyPharmacyAPI.php';

$isCli = php_sapi_name() === 'cli';
$isApi = isset($_GET['api']) && $_GET['api'] === '1';
$batchSize = isset($_GET['batch_size']) ? intval($_GET['batch_size']) : 10;
$batchSize = max(1, min(100, $batchSize));
$maxJobs = isset($_GET['max_jobs']) ? intval($_GET['max_jobs']) : 0;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'batch'; // batch, continuous, direct
$reset = isset($_GET['reset']) && $_GET['reset'] === '1';

// Progress file for direct mode
$progressFile = sys_get_temp_dir() . '/cny_sync_progress.json';

function getProgress() {
    global $progressFile;
    if (file_exists($progressFile)) {
        $data = json_decode(file_get_contents($progressFile), true);
        return $data['offset'] ?? 0;
    }
    return 0;
}

function saveProgress($offset) {
    global $progressFile;
    file_put_contents($progressFile, json_encode(['offset' => $offset, 'updated' => date('Y-m-d H:i:s')]));
}

// API Mode - Return JSON
if ($isApi) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $db = Database::getInstance()->getConnection();
        $cnyApi = new CnyPharmacyAPI($db);
        
        if ($mode === 'direct') {
            // Direct sync from CNY API (no queue)
            $offset = $reset ? 0 : getProgress();
            
            $cacheResult = $cnyApi->getAllProductsCached();
            if (!$cacheResult['success']) {
                throw new Exception('Cannot get products: ' . ($cacheResult['error'] ?? 'Unknown'));
            }
            
            $allProducts = $cacheResult['data'];
            $totalAvailable = count($allProducts);
            
            if ($offset >= $totalAvailable) {
                saveProgress(0);
                echo json_encode([
                    'success' => true,
                    'stats' => ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0],
                    'progress' => ['offset' => 0, 'total' => $totalAvailable, 'complete' => true]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $batchProducts = array_slice($allProducts, $offset, $batchSize);
            unset($allProducts);
            
            $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
            
            foreach ($batchProducts as $product) {
                try {
                    $result = $cnyApi->syncProduct($product, ['update_existing' => true, 'auto_category' => true]);
                    $stats['processed']++;
                    if ($result['action'] === 'created') $stats['created']++;
                    elseif ($result['action'] === 'updated') $stats['updated']++;
                    else $stats['skipped']++;
                } catch (Exception $e) {
                    $stats['failed']++;
                }
            }
            
            $newOffset = $offset + $stats['processed'];
            $isComplete = $newOffset >= $totalAvailable;
            
            if ($isComplete) {
                saveProgress(0);
            } else {
                saveProgress($newOffset);
            }
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'progress' => [
                    'offset' => $offset,
                    'total' => $totalAvailable,
                    'complete' => $isComplete
                ]
            ], JSON_UNESCAPED_UNICODE);
            
        } else {
            // Queue-based sync
            require_once __DIR__ . '/classes/SyncWorker.php';
            $worker = new SyncWorker($db, $cnyApi);
            
            if ($mode === 'continuous') {
                $stats = $worker->processAll($batchSize, $maxJobs);
            } else {
                $stats = $worker->processBatch($batchSize);
            }
            
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ], JSON_UNESCAPED_UNICODE);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// HTML Mode
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Sync Worker</title>';
    echo '<style>body{font-family:monospace;background:#1a202c;color:#e2e8f0;padding:20px;}</style>';
    echo '</head><body><pre>';
}

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║          CNY Pharmacy Sync Worker                        ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

echo "📋 Configuration:\n";
echo "   Mode: {$mode}\n";
echo "   Batch Size: {$batchSize}\n";
echo "   Max Jobs: " . ($maxJobs > 0 ? $maxJobs : 'unlimited') . "\n\n";

try {
    $db = Database::getInstance()->getConnection();
    $cnyApi = new CnyPharmacyAPI($db);
    
    // Test API connection
    echo "🔌 Testing API connection...\n";
    $testResult = $cnyApi->testConnection();
    
    if (!$testResult['success']) {
        throw new Exception("API connection failed: " . $testResult['message']);
    }
    
    echo "✓ API connection successful\n\n";
    
    if ($mode === 'direct') {
        // Direct sync mode
        echo "🚀 Starting direct sync from CNY API...\n\n";
        
        $result = $cnyApi->syncAllProducts([
            'limit' => $batchSize,
            'offset' => 0,
            'update_existing' => true,
            'auto_category' => true
        ]);
        
        $stats = $result['stats'] ?? [];
        
    } else {
        // Queue-based sync
        require_once __DIR__ . '/classes/SyncWorker.php';
        $worker = new SyncWorker($db, $cnyApi);
        
        echo "🚀 Starting worker...\n\n";
        
        if ($mode === 'continuous') {
            $stats = $worker->processAll($batchSize, $maxJobs);
        } else {
            $stats = $worker->processBatch($batchSize);
        }
    }
    
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════╗\n";
    echo "║                    SYNC COMPLETED                        ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n\n";
    
    echo "📊 Statistics:\n";
    echo "   Processed: " . ($stats['processed'] ?? $stats['total'] ?? 0) . "\n";
    echo "   Created: " . ($stats['created'] ?? 0) . "\n";
    echo "   Updated: " . ($stats['updated'] ?? 0) . "\n";
    echo "   Skipped: " . ($stats['skipped'] ?? 0) . "\n";
    echo "   Failed: " . ($stats['failed'] ?? 0) . "\n";
    
    if (isset($stats['duration_seconds'])) {
        echo "   Duration: {$stats['duration_seconds']} seconds\n";
        echo "   Speed: {$stats['jobs_per_second']} jobs/sec\n";
    }
    
    echo "\n✓ Worker finished successfully\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
}

if (!$isCli) {
    echo '</pre>';
    echo '<p><a href="sync-dashboard.php" style="color:#68d391;">← Back to Dashboard</a></p>';
    echo '</body></html>';
}
