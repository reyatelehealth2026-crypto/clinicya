<?php
/**
 * Odoo Dashboard Fast API - WITH REDIS CACHING
 * Cached version of odoo-dashboard-fast.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooRedisCache.php';
require_once __DIR__ . '/../classes/ApiCacheWrappers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$startTime = microtime(true);
$lineAccountId = (int) ($_GET['account'] ?? 1);
$action = $_GET['action'] ?? 'health';
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

try {
    $result = [];
    $cacheHit = false;
    
    switch ($action) {
        case 'health':
            $result = [
                'success' => true,
                'status' => 'healthy',
                'cache_enabled' => OdooRedisCache::getInstance()->isEnabled(),
                'timestamp' => time()
            ];
            break;
            
        case 'overview_fast':
            $result = getOverviewCached($lineAccountId, $forceRefresh);
            break;
            
        case 'stats':
            $cacheKey = cache_key('stats', $lineAccountId);
            
            if (!$forceRefresh) {
                $cached = cache_get($cacheKey);
                if ($cached) {
                    $result = $cached;
                    $cacheHit = true;
                    break;
                }
            }
            
            $result = [
                'success' => true,
                'data' => [
                    'orders_today' => getOrdersTodayCount(null, $lineAccountId),
                    'sales_today' => getSalesToday(null, $lineAccountId),
                    'slips_pending' => getSlipsPendingCount(null, $lineAccountId),
                    'webhook_stats' => getWebhookStatsCached($lineAccountId, 24),
                    'notification_stats' => getNotificationStatsCached($lineAccountId)
                ]
            ];
            
            cache_set($cacheKey, $result, OdooRedisCache::TTL_STATS);
            break;
            
        case 'webhooks_stats':
            $hours = (int) ($_GET['hours'] ?? 24);
            $result = [
                'success' => true,
                'data' => getWebhookStatsCached($lineAccountId, $hours)
            ];
            break;
            
        case 'notification_stats':
            $result = [
                'success' => true,
                'data' => getNotificationStatsCached($lineAccountId)
            ];
            break;
            
        case 'customers':
            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 50);
            $result = [
                'success' => true,
                'data' => getCustomerListCached($lineAccountId, $page, $limit)
            ];
            break;
            
        case 'orders_recent':
            $limit = (int) ($_GET['limit'] ?? 10);
            $result = [
                'success' => true,
                'data' => getRecentOrdersCached($lineAccountId, $limit)
            ];
            break;
            
        case 'slips_pending':
            $limit = (int) ($_GET['limit'] ?? 50);
            $result = [
                'success' => true,
                'data' => getPendingSlipsCached($lineAccountId, $limit)
            ];
            break;
            
        case 'cache_stats':
            $result = [
                'success' => true,
                'data' => OdooRedisCache::getInstance()->getStats()
            ];
            break;
            
        case 'clear_cache':
            // Admin only - clear cache for this account
            clearAccountCache($lineAccountId);
            $result = [
                'success' => true,
                'message' => 'Cache cleared for account ' . $lineAccountId
            ];
            break;
            
        default:
            $result = [
                'success' => false,
                'error' => 'Unknown action: ' . $action
            ];
    }
    
    $elapsed = round((microtime(true) - $startTime) * 1000);
    
    // Add metadata
    $result['meta'] = [
        'response_time_ms' => $elapsed,
        'cache_hit' => $cacheHit,
        'cached_at' => date('c')
    ];
    
    // Headers for debugging
    header('X-Response-Time: ' . $elapsed . 'ms');
    header('X-Cache-Hit: ' . ($cacheHit ? '1' : '0'));
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'meta' => [
            'response_time_ms' => round((microtime(true) - $startTime) * 1000)
        ]
    ]);
}
