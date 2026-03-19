<?php
/**
 * Dashboard Cache Demo API
 * 
 * Demonstrates the usage of the new dashboard caching system.
 * This endpoint shows how to integrate caching with dashboard metrics.
 * 
 * @version 1.0.0
 * @created 2026-01-23
 * @spec odoo-dashboard-modernization
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/DashboardCacheService.php';

// Set JSON response headers
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
    // Get request parameters
    $action = $_GET['action'] ?? 'metrics';
    $lineAccountId = $_GET['line_account_id'] ?? $_SERVER['HTTP_X_LINE_ACCOUNT_ID'] ?? 1;
    $metricType = $_GET['metric_type'] ?? 'overview';
    $dateKey = $_GET['date_key'] ?? date('Y-m-d');
    $timeRange = $_GET['time_range'] ?? 'today';
    
    // Initialize cache service
    $cacheService = new DashboardCacheService();
    
    switch ($action) {
        case 'metrics':
            // Get dashboard metrics with caching
            $metrics = $cacheService->getDashboardMetrics(
                $lineAccountId,
                $metricType,
                $dateKey,
                $timeRange,
                function() use ($lineAccountId, $metricType, $dateKey, $timeRange) {
                    // This generator function would normally call your existing dashboard logic
                    return generateDashboardMetrics($lineAccountId, $metricType, $dateKey, $timeRange);
                }
            );
            
            echo json_encode([
                'success' => true,
                'data' => $metrics,
                'cache_info' => [
                    'metric_type' => $metricType,
                    'date_key' => $dateKey,
                    'time_range' => $timeRange,
                    'cached_at' => date('Y-m-d H:i:s')
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'cache_stats':
            // Get cache statistics
            $stats = $cacheService->getCacheStatistics($lineAccountId);
            
            echo json_encode([
                'success' => true,
                'data' => $stats,
                'meta' => [
                    'line_account_id' => $lineAccountId,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'invalidate':
            // Invalidate cache
            $type = $_GET['type'] ?? 'dashboard';
            
            if ($type === 'dashboard') {
                $cleared = $cacheService->invalidateDashboardCache($lineAccountId, $metricType);
            } else {
                $cleared = $cacheService->invalidateApiCache(null, $lineAccountId);
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'entries_cleared' => $cleared,
                    'cache_type' => $type
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'cleanup':
            // Manual cache cleanup
            $results = $cacheService->cleanupExpiredCache();
            
            echo json_encode([
                'success' => true,
                'data' => $results
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_ACTION',
                    'message' => 'Invalid action parameter'
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'An error occurred while processing the request',
            'details' => $e->getMessage()
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Generate dashboard metrics (placeholder implementation)
 * In a real implementation, this would call your existing dashboard logic
 */
function generateDashboardMetrics($lineAccountId, $metricType, $dateKey, $timeRange)
{
    $db = Database::getInstance()->getConnection();
    
    switch ($metricType) {
        case 'orders':
            return generateOrderMetrics($db, $lineAccountId, $dateKey, $timeRange);
            
        case 'payments':
            return generatePaymentMetrics($db, $lineAccountId, $dateKey, $timeRange);
            
        case 'webhooks':
            return generateWebhookMetrics($db, $lineAccountId, $dateKey, $timeRange);
            
        case 'customers':
            return generateCustomerMetrics($db, $lineAccountId, $dateKey, $timeRange);
            
        case 'overview':
        default:
            return [
                'orders' => generateOrderMetrics($db, $lineAccountId, $dateKey, $timeRange),
                'payments' => generatePaymentMetrics($db, $lineAccountId, $dateKey, $timeRange),
                'webhooks' => generateWebhookMetrics($db, $lineAccountId, $dateKey, $timeRange),
                'customers' => generateCustomerMetrics($db, $lineAccountId, $dateKey, $timeRange),
                'generated_at' => date('Y-m-d H:i:s'),
                'time_range' => $timeRange
            ];
    }
}

/**
 * Generate order metrics
 */
function generateOrderMetrics($db, $lineAccountId, $dateKey, $timeRange)
{
    try {
        $dateCondition = getDateCondition($dateKey, $timeRange);
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN state = 'sale' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN state = 'draft' THEN 1 ELSE 0 END) as pending_orders,
                SUM(amount_total) as total_amount,
                AVG(amount_total) as average_order_value
            FROM odoo_orders 
            WHERE line_account_id = ? AND {$dateCondition}
        ");
        $stmt->execute([$lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_orders' => (int)$result['total_orders'],
            'completed_orders' => (int)$result['completed_orders'],
            'pending_orders' => (int)$result['pending_orders'],
            'total_amount' => (float)$result['total_amount'],
            'average_order_value' => (float)$result['average_order_value']
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Generate payment metrics
 */
function generatePaymentMetrics($db, $lineAccountId, $dateKey, $timeRange)
{
    try {
        $dateCondition = getDateCondition($dateKey, $timeRange, 'invoice_date');
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_invoices,
                SUM(CASE WHEN payment_state = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
                SUM(CASE WHEN payment_state = 'not_paid' THEN 1 ELSE 0 END) as unpaid_invoices,
                SUM(amount_total) as total_amount
            FROM odoo_invoices 
            WHERE line_account_id = ? AND {$dateCondition}
        ");
        $stmt->execute([$lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_invoices' => (int)$result['total_invoices'],
            'paid_invoices' => (int)$result['paid_invoices'],
            'unpaid_invoices' => (int)$result['unpaid_invoices'],
            'total_amount' => (float)$result['total_amount']
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Generate webhook metrics
 */
function generateWebhookMetrics($db, $lineAccountId, $dateKey, $timeRange)
{
    try {
        $dateCondition = getDateCondition($dateKey, $timeRange, 'processed_at');
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_webhooks,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_webhooks,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_webhooks,
                COUNT(DISTINCT event_type) as unique_event_types
            FROM odoo_webhooks_log 
            WHERE line_account_id = ? AND {$dateCondition}
        ");
        $stmt->execute([$lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $successRate = $result['total_webhooks'] > 0 
            ? ($result['successful_webhooks'] / $result['total_webhooks']) * 100 
            : 0;
        
        return [
            'total_webhooks' => (int)$result['total_webhooks'],
            'successful_webhooks' => (int)$result['successful_webhooks'],
            'failed_webhooks' => (int)$result['failed_webhooks'],
            'success_rate' => round($successRate, 2),
            'unique_event_types' => (int)$result['unique_event_types']
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Generate customer metrics
 */
function generateCustomerMetrics($db, $lineAccountId, $dateKey, $timeRange)
{
    try {
        $dateCondition = getDateCondition($dateKey, $timeRange, 'followed_at');
        
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_followers,
                SUM(CASE WHEN is_following = 1 THEN 1 ELSE 0 END) as active_followers,
                SUM(total_messages) as total_messages
            FROM account_followers 
            WHERE line_account_id = ? AND {$dateCondition}
        ");
        $stmt->execute([$lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_followers' => (int)$result['total_followers'],
            'active_followers' => (int)$result['active_followers'],
            'total_messages' => (int)$result['total_messages']
        ];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Get date condition for SQL queries based on time range
 */
function getDateCondition($dateKey, $timeRange, $dateColumn = 'date_order')
{
    switch ($timeRange) {
        case 'today':
            return "DATE({$dateColumn}) = '{$dateKey}'";
            
        case 'week':
            return "DATE({$dateColumn}) >= DATE_SUB('{$dateKey}', INTERVAL 7 DAY) AND DATE({$dateColumn}) <= '{$dateKey}'";
            
        case 'month':
            return "DATE({$dateColumn}) >= DATE_SUB('{$dateKey}', INTERVAL 1 MONTH) AND DATE({$dateColumn}) <= '{$dateKey}'";
            
        case 'quarter':
            return "DATE({$dateColumn}) >= DATE_SUB('{$dateKey}', INTERVAL 3 MONTH) AND DATE({$dateColumn}) <= '{$dateKey}'";
            
        case 'year':
            return "DATE({$dateColumn}) >= DATE_SUB('{$dateKey}', INTERVAL 1 YEAR) AND DATE({$dateColumn}) <= '{$dateKey}'";
            
        default:
            return "DATE({$dateColumn}) = '{$dateKey}'";
    }
}