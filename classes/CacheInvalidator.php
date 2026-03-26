<?php
/**
 * Cache Invalidation Manager
 * จัดการการล้าง cache เมื่อข้อมูลเปลี่ยน
 */

require_once __DIR__ . '/../classes/OdooRedisCache.php';

class CacheInvalidator {
    private $cache;
    
    public function __construct() {
        $this->cache = OdooRedisCache::getInstance();
    }
    
    /**
     * Invalidate when order is created/updated
     */
    public function onOrderChange($lineAccountId, $orderId = null) {
        $keys = [
            cache_key('overview', $lineAccountId),
            cache_key('stats', $lineAccountId),
            cache_key('orders:today:count', $lineAccountId),
            cache_key('sales:today', $lineAccountId),
            cache_key('orders:recent', $lineAccountId, '*'),
        ];
        
        foreach ($keys as $key) {
            if (strpos($key, '*') !== false) {
                $this->cache->deletePattern($key);
            } else {
                $this->cache->delete($key);
            }
        }
        
        if ($orderId) {
            $this->cache->delete(cache_key('order', 0, $orderId));
        }
        
        return true;
    }
    
    /**
     * Invalidate when slip is uploaded/matched
     */
    public function onSlipChange($lineAccountId, $slipId = null) {
        $keys = [
            cache_key('overview', $lineAccountId),
            cache_key('stats', $lineAccountId),
            cache_key('slips:pending:count', $lineAccountId),
            cache_key('slips:pending:list', $lineAccountId, '*'),
            cache_key('paid:today', $lineAccountId),
        ];
        
        foreach ($keys as $key) {
            if (strpos($key, '*') !== false) {
                $this->cache->deletePattern($key);
            } else {
                $this->cache->delete($key);
            }
        }
        
        return true;
    }
    
    /**
     * Invalidate when webhook is received
     */
    public function onWebhookReceived($lineAccountId) {
        $keys = [
            cache_key('webhooks:stats', $lineAccountId, '*'),
            cache_key('system:health', $lineAccountId),
        ];
        
        foreach ($keys as $key) {
            $this->cache->deletePattern($key);
        }
        
        return true;
    }
    
    /**
     * Invalidate when notification is sent
     */
    public function onNotificationSent($lineAccountId) {
        $keys = [
            cache_key('notifications:stats', $lineAccountId),
            cache_key('stats', $lineAccountId),
        ];
        
        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
        
        return true;
    }
    
    /**
     * Invalidate when customer is updated
     */
    public function onCustomerChange($lineAccountId, $customerId = null) {
        $keys = [
            cache_key('customers:list', $lineAccountId, '*'),
            cache_key('stats', $lineAccountId),
        ];
        
        foreach ($keys as $key) {
            $this->cache->deletePattern($key);
        }
        
        return true;
    }
    
    /**
     * Invalidate when BDO is updated
     */
    public function onBdoChange($lineAccountId, $bdoId = null) {
        $keys = [
            cache_key('overview', $lineAccountId),
            cache_key('stats', $lineAccountId),
            cache_key('bdo:pending:count', $lineAccountId),
        ];
        
        foreach ($keys as $key) {
            $this->cache->delete($key);
        }
        
        return true;
    }
    
    /**
     * Clear all cache (use with caution!)
     */
    public function clearAll() {
        return $this->cache->flush();
    }
    
    /**
     * Clear cache for specific account
     */
    public function clearAccount($lineAccountId) {
        return clearAccountCache($lineAccountId);
    }
}

// Global helper function
function getCacheInvalidator() {
    return new CacheInvalidator();
}
