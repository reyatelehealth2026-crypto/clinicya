<?php
/**
 * Universal Cache Manager - Redis, Native Redis, or File Cache
 * Auto-detects available caching method
 * Priority: Local Redis (127.0.0.1) > Redis Cloud > Predis > File Cache
 * Credentials read from config constants (REDIS_HOST, REDIS_PORT, REDIS_PASSWORD, etc.)
 */

// Try to load Predis from different locations
$predisLoaded = false;

// Option 1: Composer autoload
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $predisLoaded = class_exists('Predis\Client');
}

// Option 2: Manual Predis install
if (!$predisLoaded && file_exists(__DIR__ . '/../vendor/predis/predis/autoload.php')) {
    require_once __DIR__ . '/../vendor/predis/predis/autoload.php';
    $predisLoaded = class_exists('Predis\Client');
}

// Option 3: Include File Cache fallback
require_once __DIR__ . '/FileCache.php';

use Predis\Client as PredisClient;

class OdooRedisCache {
    private static $instance = null;
    private $cache = null;
    private $type = 'none';
    private $enabled = false;
    private $hitCount = 0;
    private $missCount = 0;
    private $keyPrefix = 'odoo:';

    // Cache TTL Constants
    const TTL_OVERVIEW = 60;
    const TTL_STATS = 30;
    const TTL_WEBHOOKS = 30;
    const TTL_SLIPS = 60;
    const TTL_CUSTOMERS = 300;
    const TTL_ORDERS = 120;
    const TTL_BDO = 180;
    const TTL_NOTIFICATIONS = 30;
    const TTL_STATIC = 3600;
    
    private function __construct() {
        $this->connect();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect() {
        // Read credentials from config constants (set via .env or config.php)
        $cloudHost = defined('REDIS_HOST')     ? REDIS_HOST     : null;
        $cloudPort = defined('REDIS_PORT')     ? (int) REDIS_PORT : 6379;
        $cloudUser = defined('REDIS_USERNAME') ? REDIS_USERNAME : 'default';
        $cloudPass = defined('REDIS_PASSWORD') ? REDIS_PASSWORD : null;
        $cloudDb   = defined('REDIS_DB')       ? (int) REDIS_DB  : 0;
        $cloudTimeout = defined('REDIS_TIMEOUT') ? (float) REDIS_TIMEOUT : 3.0;

        // Try 1: Local Redis (127.0.0.1:6379 - fastest, no network latency)
        if (extension_loaded('redis')) {
            try {
                $redis = new Redis();
                // Try localhost first with short timeout
                if (@$redis->connect('127.0.0.1', 6379, 1)) {
                    // Local Redis - try with password if configured, else no auth
                    $localPass = ($cloudHost === '127.0.0.1' || $cloudHost === 'localhost') ? $cloudPass : null;
                    if ($localPass) {
                        $redis->auth($localPass);
                    }
                    if ($redis->ping()) {
                        $this->cache = $redis;
                        $this->type = 'redis-local';
                        $this->enabled = true;
                        error_log("[Cache] Connected to local Redis (127.0.0.1:6379)");
                        return;
                    }
                }
            } catch (Exception $e) {
                error_log("[Cache] Local Redis failed: " . $e->getMessage());
            }
        }

        // Try 2: Redis Cloud from config (if local not available)
        if ($cloudHost && $cloudHost !== '127.0.0.1' && $cloudHost !== 'localhost' && extension_loaded('redis')) {
            try {
                $redis = new Redis();
                $redis->connect($cloudHost, $cloudPort, $cloudTimeout);
                if ($cloudUser && $cloudPass) {
                    $redis->auth([$cloudUser, $cloudPass]);
                } elseif ($cloudPass) {
                    $redis->auth($cloudPass);
                }
                if ($cloudDb > 0) {
                    $redis->select($cloudDb);
                }
                if ($redis->ping()) {
                    $this->cache = $redis;
                    $this->type = 'redis-cloud';
                    $this->enabled = true;
                    error_log("[Cache] Connected to Redis Cloud ({$cloudHost}:{$cloudPort})");
                    return;
                }
            } catch (Exception $e) {
                error_log("[Cache] Redis Cloud failed: " . $e->getMessage());
            }
        }

        // Try 3: Predis for local Redis
        if (class_exists('Predis\Client')) {
            try {
                // Try localhost first
                $localParams = [
                    'host'    => '127.0.0.1',
                    'port'    => 6379,
                    'timeout' => 2.0,
                ];
                $localPass = ($cloudHost === '127.0.0.1' || $cloudHost === 'localhost') ? $cloudPass : null;
                if ($localPass) {
                    $localParams['password'] = $localPass;
                }
                $client = new PredisClient($localParams);
                $client->ping();
                $this->cache = $client;
                $this->type = 'predis-local';
                $this->enabled = true;
                error_log("[Cache] Connected to local Redis via Predis");
                return;
            } catch (Exception $e) {
                error_log("[Cache] Predis local failed: " . $e->getMessage());

                // Try Redis Cloud via Predis
                if ($cloudHost && $cloudHost !== '127.0.0.1' && $cloudHost !== 'localhost') {
                    try {
                        $cloudParams = [
                            'scheme'  => 'tcp',
                            'host'    => $cloudHost,
                            'port'    => $cloudPort,
                            'timeout' => $cloudTimeout,
                        ];
                        if ($cloudUser)  { $cloudParams['username'] = $cloudUser; }
                        if ($cloudPass)  { $cloudParams['password'] = $cloudPass; }
                        if ($cloudDb > 0){ $cloudParams['database'] = $cloudDb; }
                        $client = new PredisClient($cloudParams);
                        $client->ping();
                        $this->cache = $client;
                        $this->type = 'predis-cloud';
                        $this->enabled = true;
                        error_log("[Cache] Connected to Redis Cloud via Predis ({$cloudHost}:{$cloudPort})");
                        return;
                    } catch (Exception $e2) {
                        error_log("[Cache] Predis cloud failed: " . $e2->getMessage());
                    }
                }
            }
        }
        
        // Try 4: File Cache (fallback)
        $fileCache = FileCache::getInstance();
        if ($fileCache->isEnabled()) {
            $this->cache = $fileCache;
            $this->type = 'file';
            $this->enabled = true;
            error_log("[Cache] Using file-based cache as fallback");
            return;
        }
        
        // No caching available
        $this->type = 'none';
        $this->enabled = false;
        error_log("[Cache] No caching available - using database directly");
    }
    
    public function get($key) {
        if (!$this->enabled) return null;
        
        try {
            switch ($this->type) {
                case 'redis-local':
                case 'redis-cloud':
                    $value = $this->cache->get($key);
                    break;
                case 'predis-local':
                case 'predis-cloud':
                    $value = $this->cache->get($key);
                    break;
                case 'file':
                    return $this->cache->get($key);
                default:
                    return null;
            }
            
            if ($value !== false && $value !== null) {
                $this->hitCount++;
                return json_decode($value, true);
            }
            $this->missCount++;
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    public function set($key, $value, $ttl = 300) {
        if (!$this->enabled) return false;
        
        try {
            $encoded = json_encode($value);
            
            switch ($this->type) {
                case 'redis-local':
                case 'redis-cloud':
                    return $this->cache->setex($key, $ttl, $encoded);
                case 'predis-local':
                case 'predis-cloud':
                    return $this->cache->setex($key, $ttl, $encoded);
                case 'file':
                    return $this->cache->set($key, $value, $ttl);
                default:
                    return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function delete($key) {
        if (!$this->enabled) return false;
        
        try {
            switch ($this->type) {
                case 'redis-local':
                case 'redis-cloud':
                    return $this->cache->del($key) > 0;
                case 'predis-local':
                case 'predis-cloud':
                    return $this->cache->del($key) > 0;
                case 'file':
                    return $this->cache->delete($key);
                default:
                    return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function deletePattern($pattern) {
        if (!$this->enabled) return 0;

        try {
            switch ($this->type) {
                case 'redis-local':
                case 'redis-cloud':
                    // Use SCAN instead of KEYS to avoid blocking Redis on large keyspaces
                    $deleted = 0;
                    $it = null;
                    $this->cache->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
                    while ($keys = $this->cache->scan($it, $pattern, 100)) {
                        if (!empty($keys)) {
                            $deleted += $this->cache->del($keys);
                        }
                    }
                    return $deleted;
                case 'predis-local':
                case 'predis-cloud':
                    // Predis: use Keyspace iterator (non-blocking)
                    $deleted = 0;
                    $batch = [];
                    foreach (new \Predis\Collection\Iterator\Keyspace($this->cache, $pattern, 100) as $key) {
                        $batch[] = $key;
                        if (count($batch) >= 100) {
                            $deleted += $this->cache->del($batch);
                            $batch = [];
                        }
                    }
                    if (!empty($batch)) {
                        $deleted += $this->cache->del($batch);
                    }
                    return $deleted;
                case 'file':
                    return $this->cache->deletePattern($pattern);
                default:
                    return 0;
            }
        } catch (Exception $e) {
            error_log("[Cache] deletePattern error: " . $e->getMessage());
            return 0;
        }
    }
    
    public function remember($key, $ttl, callable $callback) {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
    
    public function getStats() {
        return [
            'enabled' => $this->enabled,
            'type' => $this->type,
            'hits' => $this->hitCount,
            'misses' => $this->missCount,
            'hit_rate' => $this->hitCount + $this->missCount > 0 
                ? round($this->hitCount / ($this->hitCount + $this->missCount) * 100, 2) 
                : 0
        ];
    }
    
    public function isEnabled() {
        return $this->enabled;
    }
    
    public function getType() {
        return $this->type;
    }
    
    public static function key($type, $accountId, $suffix = '') {
        $key = "odoo:{$type}:{$accountId}";
        if ($suffix) {
            $key .= ":{$suffix}";
        }
        return $key;
    }

    public function flush() {
        if (!$this->enabled) return 0;
        try {
            switch ($this->type) {
                case 'redis-local':
                case 'redis-cloud':
                    $deleted = 0;
                    $it = null;
                    $this->cache->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
                    while ($keys = $this->cache->scan($it, 'odoo:*', 100)) {
                        if (!empty($keys)) {
                            $deleted += $this->cache->del($keys);
                        }
                    }
                    return $deleted;
                case 'predis-local':
                case 'predis-cloud':
                    $deleted = 0;
                    $batch = [];
                    foreach (new \Predis\Collection\Iterator\Keyspace($this->cache, 'odoo:*', 100) as $key) {
                        $batch[] = $key;
                        if (count($batch) >= 100) {
                            $deleted += $this->cache->del($batch);
                            $batch = [];
                        }
                    }
                    if (!empty($batch)) {
                        $deleted += $this->cache->del($batch);
                    }
                    return $deleted;
                case 'file':
                    return FileCache::getInstance()->flush();
                default:
                    return 0;
            }
        } catch (Exception $e) {
            error_log("[Cache] flush error: " . $e->getMessage());
            return 0;
        }
    }
}

// Helper functions
function cache_get($key) {
    return OdooRedisCache::getInstance()->get($key);
}

function cache_set($key, $value, $ttl = 300) {
    return OdooRedisCache::getInstance()->set($key, $value, $ttl);
}

function cache_delete($key) {
    return OdooRedisCache::getInstance()->delete($key);
}

function cache_delete_pattern($pattern) {
    return OdooRedisCache::getInstance()->deletePattern($pattern);
}

function cache_key($type, $accountId, $suffix = '') {
    return OdooRedisCache::key($type, $accountId, $suffix);
}

function cache_remember($key, $ttl, $callback) {
    return OdooRedisCache::getInstance()->remember($key, $ttl, $callback);
}
