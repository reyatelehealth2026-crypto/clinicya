<?php
/**
 * Load Test Runner - Realistic Version
 * ทดสอบแบบ concurrent จริงๆ ด้วย curl_multi
 */

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

set_time_limit(300);
ini_set('memory_limit', '512M');

// Error handlers
set_error_handler(function($errno, $errstr) {
    echo json_encode(['error' => true, 'message' => $errstr]);
    exit;
});

set_exception_handler(function($e) {
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
    exit;
});

$type = $_GET['type'] ?? 'database';
$concurrentUsers = min(50, max(1, (int)($_GET['users'] ?? 10)));
$requestsPerUser = min(10, max(1, (int)($_GET['requests'] ?? 3)));

// Get base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'];

// Define realistic test endpoints - ใช้ endpoints ที่ทำงานได้จริง
$endpoints = [
    'database' => [
        '/api/shop-products.php?limit=10',
    ],
    'api' => [
        '/api/shop-products.php?limit=10',
        '/api/shop-products.php?limit=5&page=2',
    ],
    'chat' => [
        '/api/messages.php?action=get_conversations&limit=10',
    ],
    'webhook' => [
        '/api/shop-products.php?limit=5',
    ],
    'full' => [
        '/api/shop-products.php?limit=10',
        '/api/shop-products.php?limit=5&page=2',
        '/api/messages.php?action=get_conversations&limit=5',
    ]
];

$testEndpoints = $endpoints[$type] ?? $endpoints['database'];

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
    'test_mode' => 'concurrent_curl',
];

$responseTimes = [];
$startTime = microtime(true);

// Function to run concurrent requests using curl_multi
function runConcurrentRequests($urls, $timeout = 30) {
    $results = [];
    $multiHandle = curl_multi_init();
    $handles = [];
    
    foreach ($urls as $i => $url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'LoadTest/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$i] = [
            'handle' => $ch,
            'url' => $url,
            'start_time' => microtime(true)
        ];
    }
    
    // Execute all requests
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running > 0);
    
    // Collect results
    foreach ($handles as $i => $data) {
        $ch = $data['handle'];
        $endTime = microtime(true);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $responseTime = ($endTime - $data['start_time']) * 1000;
        
        $results[] = [
            'url' => $data['url'],
            'success' => ($httpCode >= 200 && $httpCode < 400 && empty($error)),
            'http_code' => $httpCode,
            'time' => $responseTime,
            'error' => $error ?: null
        ];
        
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($multiHandle);
    return $results;
}

// Build URL list for testing
$urlsToTest = [];
$totalRequests = $concurrentUsers * $requestsPerUser;

for ($i = 0; $i < $totalRequests; $i++) {
    $endpoint = $testEndpoints[$i % count($testEndpoints)];
    $urlsToTest[] = $baseUrl . $endpoint;
}

// Run in batches to avoid overwhelming server
$batchSize = min(20, $concurrentUsers); // Max 20 concurrent
$allResults = [];

for ($i = 0; $i < count($urlsToTest); $i += $batchSize) {
    $batch = array_slice($urlsToTest, $i, $batchSize);
    $batchResults = runConcurrentRequests($batch);
    $allResults = array_merge($allResults, $batchResults);
    
    // Small delay between batches
    if ($i + $batchSize < count($urlsToTest)) {
        usleep(100000); // 100ms
    }
}

$endTime = microtime(true);
$totalTime = $endTime - $startTime;

// Process results
foreach ($allResults as $result) {
    $results['total_requests']++;
    $responseTimes[] = $result['time'];
    
    if ($result['success']) {
        $results['successful']++;
    } else {
        $results['failed']++;
        if ($result['error']) {
            $results['errors'][] = $result['error'];
        } elseif ($result['http_code'] >= 400) {
            $results['errors'][] = "HTTP {$result['http_code']}";
        }
    }
}

// Calculate statistics
if (!empty($responseTimes)) {
    sort($responseTimes);
    $results['avg_response_time'] = round(array_sum($responseTimes) / count($responseTimes), 2);
    $results['min_response_time'] = round(min($responseTimes), 2);
    $results['max_response_time'] = round(max($responseTimes), 2);
    
    // Percentiles
    $p50Index = (int)(count($responseTimes) * 0.5);
    $p95Index = (int)(count($responseTimes) * 0.95);
    $p99Index = (int)(count($responseTimes) * 0.99);
    
    $results['p50_response_time'] = round($responseTimes[$p50Index] ?? 0, 2);
    $results['p95_response_time'] = round($responseTimes[$p95Index] ?? 0, 2);
    $results['p99_response_time'] = round($responseTimes[$p99Index] ?? 0, 2);
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
    '1-3s' => 0,
    '3s+' => 0
];

foreach ($responseTimes as $time) {
    if ($time < 100) $distribution['0-100ms']++;
    elseif ($time < 500) $distribution['100-500ms']++;
    elseif ($time < 1000) $distribution['500-1000ms']++;
    elseif ($time < 3000) $distribution['1-3s']++;
    else $distribution['3s+']++;
}

$results['response_times_distribution'] = [
    'labels' => array_keys($distribution),
    'data' => array_values($distribution)
];

// Limit errors
$results['errors'] = array_slice(array_unique($results['errors']), 0, 5);

// Capacity estimation
$results['estimated_capacity'] = [
    'requests_per_minute' => round($results['requests_per_second'] * 60),
    'concurrent_users_supported' => $results['success_rate'] >= 95 ? $concurrentUsers : round($concurrentUsers * ($results['success_rate'] / 100))
];

echo json_encode($results, JSON_PRETTY_PRINT);
