<?php
/**
 * Dashboard Real-time Updates Demo API
 * 
 * Demonstrates how to integrate real-time dashboard updates
 * with the existing PHP codebase.
 * 
 * Requirements: FR-1.4, BR-3.3
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth_check.php';

// Check if user has dashboard access
if (!isAdmin() && !isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get LINE account ID from session or request
$lineAccountId = $_SESSION['line_account_id'] ?? $_GET['line_account_id'] ?? null;

if (!$lineAccountId) {
    http_response_code(400);
    echo json_encode(['error' => 'LINE account ID required']);
    exit;
}

// Load the dashboard WebSocket notifier
if (file_exists('../classes/DashboardWebSocketNotifier.php')) {
    require_once '../classes/DashboardWebSocketNotifier.php';
    $dashboardNotifier = new DashboardWebSocketNotifier($lineAccountId);
} else {
    $dashboardNotifier = null;
}

$action = $_GET['action'] ?? 'test';

switch ($action) {
    case 'test':
        // Test WebSocket connection
        $result = [
            'websocket_available' => $dashboardNotifier !== null,
            'redis_connected' => $dashboardNotifier ? $dashboardNotifier->testConnection() : false,
            'connection_stats' => $dashboardNotifier ? $dashboardNotifier->getConnectionStats() : null
        ];
        break;

    case 'trigger_metrics_update':
        // Simulate metrics update
        if (!$dashboardNotifier) {
            $result = ['error' => 'Dashboard WebSocket notifier not available'];
            break;
        }

        // Get current dashboard metrics (simplified version)
        $db = Database::getInstance()->getConnection();
        
        $today = date('Y-m-d');
        
        // Get order metrics
        $orderQuery = $db->prepare("
            SELECT 
                COUNT(*) as today_count,
                COALESCE(SUM(amount_total), 0) as today_total,
                COUNT(CASE WHEN state IN ('draft', 'sent') THEN 1 END) as pending_count,
                COUNT(CASE WHEN state = 'sale' THEN 1 END) as completed_count
            FROM odoo_orders 
            WHERE line_account_id = ? 
            AND DATE(date_order) = ?
        ");
        $orderQuery->execute([$lineAccountId, $today]);
        $orderMetrics = $orderQuery->fetch(PDO::FETCH_ASSOC);

        // Get payment metrics
        $paymentQuery = $db->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_slips,
                COUNT(CASE WHEN status = 'matched' AND DATE(created_at) = ? THEN 1 END) as processed_today,
                COALESCE(SUM(CASE WHEN status = 'matched' AND DATE(created_at) = ? THEN amount END), 0) as total_amount
            FROM odoo_slip_uploads 
            WHERE line_account_id = ?
        ");
        $paymentQuery->execute([$today, $today, $lineAccountId]);
        $paymentMetrics = $paymentQuery->fetch(PDO::FETCH_ASSOC);

        // Get webhook metrics
        $webhookQuery = $db->prepare("
            SELECT 
                COUNT(*) as today_count,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                AVG(CASE WHEN response_time IS NOT NULL THEN response_time END) as avg_response_time
            FROM odoo_webhooks_log 
            WHERE line_account_id = ? 
            AND DATE(created_at) = ?
        ");
        $webhookQuery->execute([$lineAccountId, $today]);
        $webhookMetrics = $webhookQuery->fetch(PDO::FETCH_ASSOC);

        // Build metrics object
        $metrics = [
            'orders' => [
                'todayCount' => (int)($orderMetrics['today_count'] ?? 0),
                'todayTotal' => (float)($orderMetrics['today_total'] ?? 0),
                'pendingCount' => (int)($orderMetrics['pending_count'] ?? 0),
                'completedCount' => (int)($orderMetrics['completed_count'] ?? 0),
                'averageOrderValue' => $orderMetrics['today_count'] > 0 ? 
                    ($orderMetrics['today_total'] / $orderMetrics['today_count']) : 0
            ],
            'payments' => [
                'pendingSlips' => (int)($paymentMetrics['pending_slips'] ?? 0),
                'processedToday' => (int)($paymentMetrics['processed_today'] ?? 0),
                'matchingRate' => 95, // This would be calculated based on actual matching logic
                'totalAmount' => (float)($paymentMetrics['total_amount'] ?? 0),
                'averageProcessingTime' => 15 // This would be calculated from actual processing times
            ],
            'webhooks' => [
                'todayCount' => (int)($webhookMetrics['today_count'] ?? 0),
                'successRate' => $webhookMetrics['today_count'] > 0 ? 
                    round(($webhookMetrics['success_count'] / $webhookMetrics['today_count']) * 100) : 100,
                'failedCount' => (int)($webhookMetrics['failed_count'] ?? 0),
                'averageResponseTime' => round($webhookMetrics['avg_response_time'] ?? 0)
            ],
            'customers' => [
                'totalActive' => 150, // This would come from actual customer data
                'newToday' => 5,
                'lineConnected' => 120,
                'averageOrdersPerCustomer' => 2.3
            ],
            'updatedAt' => date('c')
        ];

        // Broadcast the update
        $broadcastResult = $dashboardNotifier->broadcastMetricsUpdate($metrics);
        
        $result = [
            'success' => $broadcastResult,
            'metrics' => $metrics,
            'broadcast_result' => $broadcastResult
        ];
        break;

    case 'trigger_order_update':
        // Simulate order status change
        if (!$dashboardNotifier) {
            $result = ['error' => 'Dashboard WebSocket notifier not available'];
            break;
        }

        $orderId = $_GET['order_id'] ?? 'TEST_ORDER_' . time();
        $oldStatus = $_GET['old_status'] ?? 'draft';
        $newStatus = $_GET['new_status'] ?? 'sale';
        $updatedBy = $_SESSION['username'] ?? 'system';

        $broadcastResult = $dashboardNotifier->broadcastOrderStatusChange(
            $orderId, 
            $oldStatus, 
            $newStatus, 
            $updatedBy,
            [
                'customerRef' => 'CUST_' . rand(1000, 9999),
                'totalAmount' => rand(500, 5000)
            ]
        );

        $result = [
            'success' => $broadcastResult,
            'order_update' => [
                'orderId' => $orderId,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
                'updatedBy' => $updatedBy
            ]
        ];
        break;

    case 'trigger_payment_update':
        // Simulate payment processing
        if (!$dashboardNotifier) {
            $result = ['error' => 'Dashboard WebSocket notifier not available'];
            break;
        }

        $paymentId = $_GET['payment_id'] ?? 'PAY_' . time();
        $orderId = $_GET['order_id'] ?? 'ORDER_' . time();
        $amount = (float)($_GET['amount'] ?? rand(500, 5000));
        $status = $_GET['status'] ?? 'matched';
        $processedBy = $_SESSION['username'] ?? 'system';

        $broadcastResult = $dashboardNotifier->broadcastPaymentProcessed(
            $paymentId,
            $orderId,
            $amount,
            $status,
            $processedBy,
            95 // matching rate
        );

        $result = [
            'success' => $broadcastResult,
            'payment_update' => [
                'paymentId' => $paymentId,
                'orderId' => $orderId,
                'amount' => $amount,
                'status' => $status,
                'processedBy' => $processedBy
            ]
        ];
        break;

    case 'trigger_webhook_update':
        // Simulate webhook received
        if (!$dashboardNotifier) {
            $result = ['error' => 'Dashboard WebSocket notifier not available'];
            break;
        }

        $webhookId = $_GET['webhook_id'] ?? 'WH_' . time();
        $type = $_GET['type'] ?? 'order.update';
        $status = $_GET['status'] ?? 'success';
        $responseTime = (int)($_GET['response_time'] ?? rand(100, 500));

        $broadcastResult = $dashboardNotifier->broadcastWebhookReceived(
            $webhookId,
            $type,
            $status,
            $responseTime,
            ['test' => true, 'timestamp' => time()]
        );

        $result = [
            'success' => $broadcastResult,
            'webhook_update' => [
                'webhookId' => $webhookId,
                'type' => $type,
                'status' => $status,
                'responseTime' => $responseTime
            ]
        ];
        break;

    default:
        $result = ['error' => 'Invalid action'];
        break;
}

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
?>