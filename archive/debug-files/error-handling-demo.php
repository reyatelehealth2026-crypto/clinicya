<?php
/**
 * Error Handling System Demo API
 * Demonstrates integration of new error handling system with existing PHP infrastructure
 * Implements BR-2.2, NFR-2.2 requirements
 */

require_once 'config/config.php';
require_once 'config/database.php';

// Load error handling bridge
if (file_exists('../classes/ErrorHandlingBridge.php')) {
    require_once '../classes/ErrorHandlingBridge.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Line-Account-ID');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $errorHandler = new ErrorHandlingBridge($db);
    $requestId = uniqid('req_', true);
    
    // Get request parameters
    $action = $_GET['action'] ?? 'test';
    $lineAccountId = $_SERVER['HTTP_X_LINE_ACCOUNT_ID'] ?? 'demo';
    
    switch ($action) {
        case 'test_error_logging':
            testErrorLogging($errorHandler, $requestId);
            break;
            
        case 'test_retry_mechanism':
            testRetryMechanism($errorHandler, $requestId);
            break;
            
        case 'test_graceful_degradation':
            testGracefulDegradation($errorHandler, $requestId);
            break;
            
        case 'test_dead_letter_queue':
            testDeadLetterQueue($errorHandler, $requestId);
            break;
            
        case 'get_error_statistics':
            getErrorStatistics($errorHandler);
            break;
            
        case 'get_service_health':
            getServiceHealth($errorHandler);
            break;
            
        case 'simulate_high_error_rate':
            simulateHighErrorRate($errorHandler, $requestId);
            break;
            
        default:
            demonstrateErrorHandling($errorHandler, $requestId);
            break;
    }

} catch (Exception $e) {
    // Demonstrate error handling for unhandled exceptions
    if (isset($errorHandler)) {
        $errorId = $errorHandler->logError(
            'UNHANDLED_EXCEPTION',
            $e->getMessage(),
            [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            $requestId ?? uniqid('req_', true)
        );
        
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'UNHANDLED_EXCEPTION',
                'message' => 'An internal error occurred',
                'error_id' => $errorId,
                'request_id' => $requestId ?? uniqid('req_', true)
            ],
            'degraded' => true,
            'degradation_reason' => 'System error - using fallback response'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'SYSTEM_ERROR',
                'message' => 'System temporarily unavailable'
            ]
        ]);
    }
}

/**
 * Test error logging functionality
 */
function testErrorLogging($errorHandler, $requestId)
{
    $errors = [
        ['DATABASE_ERROR', 'Connection timeout to MySQL server', ['host' => 'localhost', 'timeout' => 30]],
        ['EXTERNAL_SERVICE_ERROR', 'Odoo API returned 502 Bad Gateway', ['service' => 'odoo', 'endpoint' => '/api/orders']],
        ['CACHE_ERROR', 'Redis connection failed', ['host' => 'redis-server', 'port' => 6379]],
        ['VALIDATION_ERROR', 'Invalid email format provided', ['field' => 'email', 'value' => 'invalid-email']]
    ];
    
    $loggedErrors = [];
    
    foreach ($errors as [$code, $message, $details]) {
        $errorId = $errorHandler->logError($code, $message, $details, $requestId);
        $loggedErrors[] = [
            'error_id' => $errorId,
            'code' => $code,
            'message' => $message,
            'level' => determineLevelForDemo($code)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'action' => 'test_error_logging',
        'logged_errors' => $loggedErrors,
        'request_id' => $requestId,
        'message' => 'Successfully logged ' . count($loggedErrors) . ' test errors'
    ]);
}

/**
 * Test retry mechanism with different scenarios
 */
function testRetryMechanism($errorHandler, $requestId)
{
    $results = [];
    
    // Test 1: Operation that succeeds after 2 retries
    try {
        $result1 = $errorHandler->executeWithRetry(function() {
            static $attempts = 0;
            $attempts++;
            if ($attempts < 3) {
                throw new Exception("Temporary failure (attempt $attempts)");
            }
            return "Success after $attempts attempts";
        }, 'test_operation_success', 3, 100);
        
        $results['success_after_retries'] = [
            'success' => true,
            'result' => $result1
        ];
    } catch (Exception $e) {
        $results['success_after_retries'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    // Test 2: Operation that always fails
    try {
        $result2 = $errorHandler->executeWithRetry(function() {
            throw new Exception("Persistent failure - always fails");
        }, 'test_operation_failure', 2, 50);
        
        $results['persistent_failure'] = [
            'success' => true,
            'result' => $result2
        ];
    } catch (Exception $e) {
        $results['persistent_failure'] = [
            'success' => false,
            'error' => $e->getMessage(),
            'expected' => true
        ];
    }
    
    // Test 3: Non-retryable error
    try {
        $result3 = $errorHandler->executeWithRetry(function() {
            throw new InvalidArgumentException("Invalid input - not retryable");
        }, 'test_validation_error', 3, 100);
        
        $results['non_retryable'] = [
            'success' => true,
            'result' => $result3
        ];
    } catch (Exception $e) {
        $results['non_retryable'] = [
            'success' => false,
            'error' => $e->getMessage(),
            'retryable' => false
        ];
    }
    
    echo json_encode([
        'success' => true,
        'action' => 'test_retry_mechanism',
        'results' => $results,
        'request_id' => $requestId
    ]);
}

/**
 * Test graceful degradation
 */
function testGracefulDegradation($errorHandler, $requestId)
{
    $scenarios = [
        '/api/dashboard-overview.php' => 'Dashboard service unavailable',
        '/api/orders.php' => 'Order service unavailable',
        '/api/payments.php' => 'Payment service unavailable'
    ];
    
    $degradationResults = [];
    
    foreach ($scenarios as $endpoint => $reason) {
        $fallbackData = $errorHandler->getGracefulFallback($endpoint);
        $degradationResults[$endpoint] = [
            'fallback_data' => $fallbackData,
            'degraded' => $fallbackData['_degraded'] ?? false,
            'reason' => $fallbackData['_degradationReason'] ?? $reason
        ];
    }
    
    echo json_encode([
        'success' => true,
        'action' => 'test_graceful_degradation',
        'degradation_results' => $degradationResults,
        'request_id' => $requestId,
        'message' => 'Graceful degradation fallbacks generated successfully'
    ]);
}

/**
 * Test dead letter queue functionality
 */
function testDeadLetterQueue($errorHandler, $requestId)
{
    $operations = [
        [
            'type' => 'webhook_delivery',
            'payload' => ['url' => 'https://example.com/webhook', 'data' => ['order_id' => 12345]],
            'error' => 'Connection timeout after 30 seconds',
            'priority' => 'high'
        ],
        [
            'type' => 'payment_processing',
            'payload' => ['amount' => 1500.00, 'currency' => 'THB', 'method' => 'bank_transfer'],
            'error' => 'Payment gateway returned error 502',
            'priority' => 'critical'
        ],
        [
            'type' => 'notification_send',
            'payload' => ['user_id' => 'user123', 'message' => 'Order confirmed', 'channel' => 'line'],
            'error' => 'LINE API rate limit exceeded',
            'priority' => 'medium'
        ]
    ];
    
    $queuedMessages = [];
    
    foreach ($operations as $operation) {
        $messageId = $errorHandler->addToDeadLetterQueue(
            $operation['type'],
            $operation['payload'],
            $operation['error'],
            1, // attempts
            5, // max attempts
            $operation['priority']
        );
        
        $queuedMessages[] = [
            'message_id' => $messageId,
            'operation_type' => $operation['type'],
            'priority' => $operation['priority']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'action' => 'test_dead_letter_queue',
        'queued_messages' => $queuedMessages,
        'request_id' => $requestId,
        'message' => 'Successfully added ' . count($queuedMessages) . ' messages to dead letter queue'
    ]);
}

/**
 * Get error statistics
 */
function getErrorStatistics($errorHandler)
{
    $stats = $errorHandler->getErrorStatistics();
    
    echo json_encode([
        'success' => true,
        'action' => 'get_error_statistics',
        'statistics' => $stats,
        'summary' => [
            'total_errors' => array_sum(array_column($stats, 'count')),
            'unique_error_types' => count($stats),
            'critical_errors' => count(array_filter($stats, fn($s) => $s['level'] === 'critical')),
            'high_errors' => count(array_filter($stats, fn($s) => $s['level'] === 'high'))
        ]
    ]);
}

/**
 * Get service health status
 */
function getServiceHealth($errorHandler)
{
    $health = $errorHandler->getServiceHealthSummary();
    
    $healthSummary = [
        'total_services' => count($health),
        'healthy_services' => count(array_filter($health, fn($s) => $s['healthy'])),
        'degraded_services' => count(array_filter($health, fn($s) => $s['degradation_level'] !== 'none')),
        'critical_services' => count(array_filter($health, fn($s) => $s['degradation_level'] === 'full'))
    ];
    
    echo json_encode([
        'success' => true,
        'action' => 'get_service_health',
        'services' => $health,
        'summary' => $healthSummary
    ]);
}

/**
 * Simulate high error rate for testing alerts
 */
function simulateHighErrorRate($errorHandler, $requestId)
{
    $errorCodes = ['DATABASE_ERROR', 'EXTERNAL_SERVICE_ERROR', 'CACHE_ERROR'];
    $generatedErrors = [];
    
    // Generate multiple errors to trigger thresholds
    for ($i = 0; $i < 15; $i++) {
        $code = $errorCodes[array_rand($errorCodes)];
        $message = "Simulated error #" . ($i + 1) . " for testing";
        
        $errorId = $errorHandler->logError(
            $code,
            $message,
            ['simulation' => true, 'iteration' => $i + 1],
            $requestId . '_' . $i
        );
        
        $generatedErrors[] = [
            'error_id' => $errorId,
            'code' => $code,
            'iteration' => $i + 1
        ];
        
        // Update service health to show degradation
        $serviceName = strtolower(str_replace('_ERROR', '', $code));
        $errorHandler->updateServiceHealth($serviceName, false, $message);
    }
    
    echo json_encode([
        'success' => true,
        'action' => 'simulate_high_error_rate',
        'generated_errors' => $generatedErrors,
        'request_id' => $requestId,
        'message' => 'Generated ' . count($generatedErrors) . ' errors for testing'
    ]);
}

/**
 * Demonstrate comprehensive error handling
 */
function demonstrateErrorHandling($errorHandler, $requestId)
{
    echo json_encode([
        'success' => true,
        'message' => 'Error Handling System Demo API',
        'available_actions' => [
            'test_error_logging' => 'Test error logging with different severity levels',
            'test_retry_mechanism' => 'Test retry logic with exponential backoff',
            'test_graceful_degradation' => 'Test graceful degradation fallbacks',
            'test_dead_letter_queue' => 'Test dead letter queue for failed operations',
            'get_error_statistics' => 'Get error statistics and metrics',
            'get_service_health' => 'Get service health status',
            'simulate_high_error_rate' => 'Simulate high error rate for testing alerts'
        ],
        'usage' => 'Add ?action=<action_name> to test specific functionality',
        'request_id' => $requestId,
        'system_info' => [
            'error_handling_bridge' => class_exists('ErrorHandlingBridge'),
            'database_connection' => true,
            'timezone' => date_default_timezone_get()
        ]
    ]);
}

/**
 * Helper function to determine error level for demo
 */
function determineLevelForDemo($code)
{
    $levels = [
        'DATABASE_ERROR' => 'critical',
        'CIRCUIT_BREAKER_OPEN' => 'critical',
        'EXTERNAL_SERVICE_ERROR' => 'high',
        'CACHE_ERROR' => 'high',
        'WEBHOOK_PROCESSING_FAILED' => 'medium',
        'SERVICE_UNAVAILABLE' => 'medium',
        'VALIDATION_ERROR' => 'low',
        'RATE_LIMIT_EXCEEDED' => 'low'
    ];
    
    return $levels[$code] ?? 'medium';
}
?>