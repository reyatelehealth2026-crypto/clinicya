<?php
/**
 * Cached API Wrappers for Odoo Dashboard
 * ครอบทุก API endpoint ด้วย Redis caching
 */

require_once __DIR__ . '/OdooRedisCache.php';

/**
 * Get Dashboard Overview (Cached)
 * API: /api/odoo-dashboard-fast.php?action=overview_fast
 */
function getOverviewCached($lineAccountId, $forceRefresh = false) {
    $cache = OdooRedisCache::getInstance();
    $cacheKey = cache_key('overview', $lineAccountId);
    
    // Return cached if available and not forced refresh
    if (!$forceRefresh) {
        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            header('X-Cache-Status: HIT');
            return $cached;
        }
    }
    
    header('X-Cache-Status: MISS');
    
    // Fetch from database
    $db = Database::getInstance()->getConnection();
    
    // ใช้ query ที่ optimized แล้ว
    $result = [
        'success' => true,
        'data' => [
            'orders_today' => getOrdersTodayCount($db, $lineAccountId),
            'sales_today' => getSalesToday($db, $lineAccountId),
            'slips_pending' => getSlipsPendingCount($db, $lineAccountId),
            'bdo_pending' => getBdoPendingCount($db, $lineAccountId),
            'paid_today' => getPaidToday($db, $lineAccountId),
            'generated_at' => date('c')
        ]
    ];
    
    // Cache for 1 minute
    $cache->set($cacheKey, $result, OdooRedisCache::TTL_OVERVIEW);
    
    return $result;
}

/**
 * Get Orders Today Count
 */
function getOrdersTodayCount($db, $lineAccountId) {
    return cache_remember(
        cache_key('orders:today:count', $lineAccountId),
        OdooRedisCache::TTL_STATS,
        function() use ($db, $lineAccountId) {
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM odoo_orders 
                WHERE line_account_id = ? 
                    AND date_order >= CURDATE()
                    AND state NOT IN ('cancel')
            ");
            $stmt->execute([$lineAccountId]);
            return (int) $stmt->fetchColumn();
        }
    );
}

/**
 * Get Sales Today
 */
function getSalesToday($db, $lineAccountId) {
    return cache_remember(
        cache_key('sales:today', $lineAccountId),
        OdooRedisCache::TTL_STATS,
        function() use ($db, $lineAccountId) {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount_total), 0) 
                FROM odoo_orders 
                WHERE line_account_id = ? 
                    AND date_order >= CURDATE()
                    AND state NOT IN ('cancel')
            ");
            $stmt->execute([$lineAccountId]);
            return (float) $stmt->fetchColumn();
        }
    );
}

/**
 * Get Slips Pending Count
 */
function getSlipsPendingCount($db, $lineAccountId) {
    return cache_remember(
        cache_key('slips:pending:count', $lineAccountId),
        OdooRedisCache::TTL_SLIPS,
        function() use ($db, $lineAccountId) {
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM odoo_slip_uploads 
                WHERE line_account_id = ? 
                    AND status IN ('new', 'pending', 'verification_pending')
            ");
            $stmt->execute([$lineAccountId]);
            return (int) $stmt->fetchColumn();
        }
    );
}

/**
 * Get BDO Pending Count
 */
function getBdoPendingCount($db, $lineAccountId) {
    return cache_remember(
        cache_key('bdo:pending:count', $lineAccountId),
        OdooRedisCache::TTL_BDO,
        function() use ($db, $lineAccountId) {
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM odoo_bdos 
                WHERE line_account_id = ? 
                    AND payment_state NOT IN ('paid', 'reversed')
                    AND state != 'cancel'
            ");
            $stmt->execute([$lineAccountId]);
            return (int) $stmt->fetchColumn();
        }
    );
}

/**
 * Get Paid Today
 */
function getPaidToday($db, $lineAccountId) {
    return cache_remember(
        cache_key('paid:today', $lineAccountId),
        OdooRedisCache::TTL_STATS,
        function() use ($db, $lineAccountId) {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) 
                FROM odoo_slip_uploads 
                WHERE line_account_id = ? 
                    AND status = 'matched'
                    AND matched_at >= CURDATE()
            ");
            $stmt->execute([$lineAccountId]);
            return (float) $stmt->fetchColumn();
        }
    );
}

/**
 * Get Webhook Statistics (Cached)
 */
function getWebhookStatsCached($lineAccountId, $hours = 24) {
    return cache_remember(
        cache_key('webhooks:stats', $lineAccountId, "{$hours}h"),
        OdooRedisCache::TTL_WEBHOOKS,
        function() use ($lineAccountId, $hours) {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM odoo_webhooks_log 
                WHERE line_account_id = ? 
                    AND received_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ");
            $stmt->execute([$lineAccountId, $hours]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
    );
}

/**
 * Get Notification Stats (Cached)
 */
function getNotificationStatsCached($lineAccountId) {
    return cache_remember(
        cache_key('notifications:stats', $lineAccountId),
        OdooRedisCache::TTL_NOTIFICATIONS,
        function() use ($lineAccountId) {
            $db = Database::getInstance()->getConnection();
            
            // รวม 5 queries เป็น 1 query (จาก optimization ล่าสุด)
            $stmt = $db->prepare("
                SELECT 
                    SUM(CASE WHEN status = 'sent' AND sent_at >= CURDATE() THEN 1 ELSE 0 END) as today_sent,
                    SUM(CASE WHEN status = 'failed' AND sent_at >= CURDATE() THEN 1 ELSE 0 END) as today_failed,
                    SUM(CASE WHEN sent_at >= CURDATE() THEN 1 ELSE 0 END) as today_total,
                    COUNT(DISTINCT CASE WHEN status = 'sent' THEN line_user_id END) as unique_users,
                    COUNT(DISTINCT CASE WHEN status = 'sent' AND sent_at >= CURDATE() THEN line_user_id END) as unique_users_today
                FROM odoo_notification_log 
                WHERE line_account_id = ?
            ");
            $stmt->execute([$lineAccountId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
    );
}

/**
 * Get Customer List (Cached)
 */
function getCustomerListCached($lineAccountId, $page = 1, $limit = 50) {
    return cache_remember(
        cache_key('customers:list', $lineAccountId, "p{$page}l{$limit}"),
        OdooRedisCache::TTL_CUSTOMERS,
        function() use ($lineAccountId, $page, $limit) {
            $db = Database::getInstance()->getConnection();
            $offset = ($page - 1) * $limit;
            
            $stmt = $db->prepare("
                SELECT 
                    customer_id, customer_name, customer_ref, phone,
                    total_due, overdue_amount, latest_order_at, orders_count_total
                FROM odoo_customers_cache
                WHERE line_account_id = ?
                ORDER BY latest_order_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$lineAccountId, $limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    );
}

/**
 * Get Order Details (Cached)
 */
function getOrderDetailsCached($orderId) {
    return cache_remember(
        cache_key('order', 0, $orderId), // global cache
        OdooRedisCache::TTL_ORDERS,
        function() use ($orderId) {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT * FROM odoo_orders_summary 
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
    );
}

/**
 * Get Recent Orders (Cached)
 */
function getRecentOrdersCached($lineAccountId, $limit = 10) {
    return cache_remember(
        cache_key('orders:recent', $lineAccountId, $limit),
        OdooRedisCache::TTL_ORDERS,
        function() use ($lineAccountId, $limit) {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT 
                    order_key, customer_name, customer_ref, amount_total,
                    state, payment_status, date_order, last_event_at
                FROM odoo_orders_summary
                WHERE line_account_id = ?
                ORDER BY last_event_at DESC
                LIMIT ?
            ");
            $stmt->execute([$lineAccountId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    );
}

/**
 * Get Pending Slips List (Cached)
 */
function getPendingSlipsCached($lineAccountId, $limit = 50) {
    return cache_remember(
        cache_key('slips:pending:list', $lineAccountId, $limit),
        OdooRedisCache::TTL_SLIPS,
        function() use ($lineAccountId, $limit) {
            $db = Database::getInstance()->getConnection();
            
            $stmt = $db->prepare("
                SELECT 
                    id, line_user_id, amount, image_path, uploaded_at, status
                FROM odoo_slip_uploads
                WHERE line_account_id = ? 
                    AND status IN ('new', 'pending')
                ORDER BY uploaded_at DESC
                LIMIT ?
            ");
            $stmt->execute([$lineAccountId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    );
}

/**
 * Get System Health Status (Cached - shorter TTL)
 */
function getSystemHealthCached($lineAccountId) {
    return cache_remember(
        cache_key('system:health', $lineAccountId),
        10, // 10 seconds only - critical data
        function() use ($lineAccountId) {
            $db = Database::getInstance()->getConnection();
            
            return [
                'webhook_stats' => getWebhookStatsCached($lineAccountId, 1),
                'notification_stats' => getNotificationStatsCached($lineAccountId),
                'queue_size' => getQueueSize($db, $lineAccountId),
                'checked_at' => date('c')
            ];
        }
    );
}

/**
 * Get Queue Size (helper)
 */
function getQueueSize($db, $lineAccountId) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM odoo_webhook_dlq 
        WHERE status = 'pending'
    ");
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/**
 * Clear all cache for an account
 */
function clearAccountCache($lineAccountId) {
    $pattern = "odoo:*:{$lineAccountId}*";
    return cache_delete_pattern($pattern);
}

/**
 * Clear specific cache types
 */
function clearOverviewCache($lineAccountId) {
    return cache_delete(cache_key('overview', $lineAccountId));
}

function clearStatsCache($lineAccountId) {
    cache_delete(cache_key('stats', $lineAccountId));
    cache_delete(cache_key('orders:today:count', $lineAccountId));
    cache_delete(cache_key('sales:today', $lineAccountId));
    cache_delete(cache_key('slips:pending:count', $lineAccountId));
    return true;
}
