<?php
/**
 * Load Test Runner - Ultra Safe Version
 * ทดสอบแบบเบามากเพื่อไม่ให้ server ล่ม
 */

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Limit resources - very conservative
set_time_limit(30);
ini_set('memory_limit', '64M');

// Error handlers
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(['error' => true, 'message' => $errstr]);
    exit;
});

set_exception_handler(function($e) {
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
    exit;
});

try {
    require_once 'config/config.php';
    require_once 'config/database.php';
} catch (Exception $e) {
    echo json_encode(['error' => true, 'message' => 'Config error']);
    exit;
}

$type = $_GET['type'] ?? 'database';

// ULTRA SAFE LIMITS - ลดลงมากเพื่อป้องกัน server ล่ม
$concurrentUsers = min(5, max(1, (int)($_GET['users'] ?? 3)));
$requestsPerUser = min(3, max(1, (int)($_GET['requests'] ?? 2)));

$results = [
    'type' => $type,
    'concurrent_users' => $concurrentUsers,
    'requests_per_user' => $requestsPerUser,
    'total_requests' => 0,
    'successful' => 0,
    'failed' => 0,
    'success_rate' => 0,
    'avg_response_time' => 0,
    'min_response_time' => 0,
    'max_response_time' => 0,
    'requests_per_second' => 0,
    'errors' => [],
];

$startTime = microtime(true);
$responseTimes = [];

// Simple database test
function testDatabase($db) {
    $start = microtime(true);
    try {
        $stmt = $db->query("SELECT 1");
        $stmt->fetchColumn();
        
        return [
            'success' => true,
            'time' => (microtime(true) - $start) * 1000
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'time' => (microtime(true) - $start) * 1000,
            'error' => $e->getMessage()
        ];
    }
}

// Simple API test (no external calls)
function testAPI($db) {
    $start = microtime(true);
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM users");
        $count = $stmt->fetchColumn();
        
        return [
            'success' => true,
            'time' => (microtime(true) - $start) * 1000
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'time' => (microtime(true) - $start) * 1000,
            'error' => $e->getMessage()
        ];
    }
}

// Simple chat test
function testChat($db) {
    $start = microtime(true);
    try {
        $stmt = $db->query("SELECT * FROM messages ORDER BY id DESC LIMIT 5");
        $stmt->fetchAll();
        
        return [
            'success' => true,
            'time' => (microtime(true) - $start) * 1000
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'time' => (microtime(true) - $start) * 1000,
            'error' => $e->getMessage()
        ];
    }
}

// Run tests
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    echo json_encode(['error' => true, 'message' => 'Database connection failed']);
    exit;
}

$totalRequests = $concurrentUsers * $requestsPerUser;

// Run tests with delay to prevent overload
for ($user = 0; $user < $concurrentUsers; $user++) {
    for ($req = 0; $req < $requestsPerUser; $req++) {
        $result = null;
        
        switch ($type) {
            case 'database':
                $result = testDatabase($db);
                break;
            case 'api':
                $result = testAPI($db);
                break;
            case 'chat':
                $result = testChat($db);
                break;
            case 'webhook':
            case 'full':
                // Mix of tests
                $tests = ['database', 'api', 'chat'];
                $testType = $tests[array_rand($tests)];
                switch ($testType) {
                    case 'database': $result = testDatabase($db); break;
                    case 'api': $result = testAPI($db); break;
                    case 'chat': $result = testChat($db); break;
                }
                break;
            default:
                $result = testDatabase($db);
        }
        
        if ($result) {
            $results['total_requests']++;
            $responseTimes[] = $result['time'];
            
            if ($result['success']) {
                $results['successful']++;
            } else {
                $results['failed']++;
                if (isset($result['error'])) {
                    $results['errors'][] = $result['error'];
                }
            }
        }
        
        // Longer delay to prevent overload - 100ms
        usleep(100000);
    }
}

$endTime = microtime(true);
$totalTime = $endTime - $startTime;

// Calculate statistics
if (!empty($responseTimes)) {
    $results['avg_response_time'] = round(array_sum($responseTimes) / count($responseTimes), 2);
    $results['min_response_time'] = round(min($responseTimes), 2);
    $results['max_response_time'] = round(max($responseTimes), 2);
}

$results['success_rate'] = $results['total_requests'] > 0 
    ? round(($results['successful'] / $results['total_requests']) * 100, 1) 
    : 0;

$results['requests_per_second'] = $totalTime > 0 
    ? round($results['total_requests'] / $totalTime, 2) 
    : 0;

$results['total_time'] = round($totalTime, 2);

// Response time distribution
$distribution = [
    '0-100ms' => 0,
    '100-500ms' => 0,
    '500-1000ms' => 0,
    '1000ms+' => 0
];

foreach ($responseTimes as $time) {
    if ($time < 100) $distribution['0-100ms']++;
    elseif ($time < 500) $distribution['100-500ms']++;
    elseif ($time < 1000) $distribution['500-1000ms']++;
    else $distribution['1000ms+']++;
}

$results['response_times_distribution'] = [
    'labels' => array_keys($distribution),
    'data' => array_values($distribution)
];

// Limit errors
$results['errors'] = array_slice(array_unique($results['errors']), 0, 3);

// Estimate capacity
$results['estimated_capacity'] = round($results['requests_per_second'] * 60) . ' requests/min';

echo json_encode($results, JSON_PRETTY_PRINT);
