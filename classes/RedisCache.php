<?php
/**
 * RedisCache — Optimized Redis adapter with retry mechanism and file fallback
 *
 * @version 2.1.0 - HOTFIX: Reduced timeouts for faster failure detection
 * @updated 2026-03-25 - Fixed 37s connection delay issue
 */

class RedisCache
{
    private static ?RedisCache $instance = null;
    private ?object $redis = null;
    private bool $connected = false;
    private string $prefix;
    private string $fileCacheDir;
    private int $connectAttempts = 0;
    
    // HOTFIX: Reduced for faster failure detection (was causing 37s delays)
    private const MAX_CONNECT_ATTEMPTS = 2;
    private const DEFAULT_TIMEOUT = 1.0;          // Reduced from 5.0
    private const DEFAULT_READ_TIMEOUT = 3.0;     // Reduced from 10.0
    private const DEFAULT_RETRY_INTERVAL = 50000; // 50ms (was 100ms)
    
    private const DEFAULT_HOST = '127.0.0.1';     // Avoid DNS lookup
    private const DEFAULT_PORT = 6379;
    private const DEFAULT_PREFIX = 'cny:dash:';
    
    // Socket paths to try (faster than TCP)
    private const SOCKET_PATHS = [
        '/run/redis/redis.sock',
        '/var/run/redis/redis.sock',
        '/tmp/redis.sock',
    ];

    private function __construct()
    {
        $this->prefix = defined('REDIS_PREFIX') ? REDIS_PREFIX : self::DEFAULT_PREFIX;
        $this->fileCacheDir = sys_get_temp_dir() . '/cny_cache/' . $this->prefix;
        
        if (!is_dir($this->fileCacheDir)) {
            @mkdir($this->fileCacheDir, 0755, true);
        }
        
        $this->connectWithRetry();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * HOTFIX: Connection with retry - faster timeout
     */
    private function connectWithRetry(): void
    {
        while ($this->connectAttempts < self::MAX_CONNECT_ATTEMPTS) {
            if ($this->attemptConnect()) {
                $this->connected = true;
                return;
            }
            
            $this->connectAttempts++;
            if ($this->connectAttempts < self::MAX_CONNECT_ATTEMPTS) {
                usleep(self::DEFAULT_RETRY_INTERVAL);
            }
        }
        
        $this->connected = false;
        $this->redis = null;
        error_log('[RedisCache] Connection failed after ' . self::MAX_CONNECT_ATTEMPTS . ' attempts, using file fallback');
    }

    /**
     * HOTFIX: Try socket first, then TCP with short timeout
     */
    private function attemptConnect(): bool
    {
        $timeout = defined('REDIS_TIMEOUT') ? (float) REDIS_TIMEOUT : self::DEFAULT_TIMEOUT;
        $readTimeout = defined('REDIS_READ_TIMEOUT') ? (float) REDIS_READ_TIMEOUT : self::DEFAULT_READ_TIMEOUT;
        $user = defined('REDIS_USERNAME') ? REDIS_USERNAME : null;
        $pass = defined('REDIS_PASSWORD') ? REDIS_PASSWORD : null;
        $db = defined('REDIS_DB') ? (int) REDIS_DB : 0;

        if (!extension_loaded('redis')) {
            return $this->attemptPredisConnect($timeout);
        }

        try {
            $r = new \Redis();
            $connected = false;
            
            // HOTFIX: Try Unix socket first (fastest)
            $socketPath = $this->findSocketPath();
            if ($socketPath) {
                $connected = @$r->connect($socketPath, 0, $timeout);
            }
            
            // Fallback to TCP
            if (!$connected) {
                $host = defined('REDIS_HOST') ? REDIS_HOST : self::DEFAULT_HOST;
                $port = defined('REDIS_PORT') ? (int) REDIS_PORT : self::DEFAULT_PORT;
                
                // HOTFIX: Use non-persistent connection by default (pconnect can cause issues)
                $persistent = defined('REDIS_PERSISTENT') ? REDIS_PERSISTENT : false;
                
                if ($persistent) {
                    $connected = @$r->pconnect($host, $port, $timeout);
                } else {
                    $connected = @$r->connect($host, $port, $timeout);
                }
            }
            
            if (!$connected) {
                return false;
            }
            
            $r->setOption(\Redis::OPT_READ_TIMEOUT, $readTimeout);
            
            if ($user && $pass) {
                $r->auth([$user, $pass]);
            } elseif ($pass) {
                $r->auth($pass);
            }
            
            if ($db > 0) {
                $r->select($db);
            }
            
            $r->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
            $r->setOption(\Redis::OPT_PREFIX, $this->prefix);
            
            $pingResult = $r->ping();
            if ($pingResult !== true && $pingResult !== "+PONG") {
                return false;
            }
            
            $this->redis = $r;
            return true;
            
        } catch (\Exception $e) {
            error_log('[RedisCache] Connection error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Find available Redis socket
     */
    private function findSocketPath(): ?string
    {
        foreach (self::SOCKET_PATHS as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Predis fallback
     */
    private function attemptPredisConnect(float $timeout): bool
    {
        if (!class_exists('Predis\\Client')) {
            return false;
        }
        
        try {
            $host = defined('REDIS_HOST') ? REDIS_HOST : self::DEFAULT_HOST;
            $port = defined('REDIS_PORT') ? (int) REDIS_PORT : self::DEFAULT_PORT;
            $user = defined('REDIS_USERNAME') ? REDIS_USERNAME : null;
            $pass = defined('REDIS_PASSWORD') ? REDIS_PASSWORD : null;
            $db = defined('REDIS_DB') ? (int) REDIS_DB : 0;
            
            $params = [
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
                'timeout' => $timeout,
            ];
            if ($user) $params['username'] = $user;
            if ($pass) $params['password'] = $pass;
            if ($db > 0) $params['database'] = $db;
            
            $r = new \Predis\Client($params);
            $r->ping();
            $this->redis = $r;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isConnected(): bool
    {
        if (!$this->connected || $this->redis === null) {
            return false;
        }
        
        try {
            if ($this->redis instanceof \Redis) {
                $pingResult = $this->redis->ping();
                return $pingResult === true || $pingResult === "+PONG";
            }
            $this->redis->ping();
            return true;
        } catch (\Exception $e) {
            $this->connected = false;
            return false;
        }
    }

    public function get(string $key)
    {
        if ($this->isConnected()) {
            try {
                if ($this->redis instanceof \Redis) {
                    $raw = $this->redis->get($key);
                    if ($raw !== false) {
                        return $raw;
                    }
                } else {
                    $raw = $this->redis->get($this->prefix . $key);
                    if ($raw !== null) {
                        return json_decode($raw, true);
                    }
                }
            } catch (\Exception $e) {
                $this->handleError($e);
            }
        }
        
        return $this->fileGet($key);
    }

    public function set(string $key, $data, int $ttl = 60): bool
    {
        $redisSuccess = false;
        
        if ($this->isConnected()) {
            try {
                if ($this->redis instanceof \Redis) {
                    $redisSuccess = $this->redis->setex($key, $ttl, $data);
                } else {
                    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
                    $this->redis->setex($this->prefix . $key, $ttl, $encoded);
                    $redisSuccess = true;
                }
            } catch (\Exception $e) {
                $this->handleError($e);
            }
        }
        
        $fileSuccess = $this->fileSet($key, $data, $ttl);
        
        return $redisSuccess || $fileSuccess;
    }

    private function fileGet(string $key): ?array
    {
        $file = $this->fileCacheDir . '/' . $this->sanitizeKey($key) . '.cache';
        
        if (!file_exists($file)) {
            return null;
        }
        
        $data = @unserialize(@file_get_contents($file));
        if ($data === false || !is_array($data)) {
            @unlink($file);
            return null;
        }
        
        if (isset($data['exp']) && $data['exp'] < time()) {
            @unlink($file);
            return null;
        }
        
        return $data['val'] ?? null;
    }

    private function fileSet(string $key, $value, int $ttl): bool
    {
        $file = $this->fileCacheDir . '/' . $this->sanitizeKey($key) . '.cache';
        $data = [
            'exp' => time() + $ttl,
            'val' => $value
        ];
        
        return @file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
    }

    public function del($keys): int
    {
        if (!$this->isConnected()) {
            return 0;
        }

        try {
            $keys = (array) $keys;
            if ($this->redis instanceof \Redis) {
                return $this->redis->del(...$keys);
            }
            $prefixed = array_map(fn($k) => $this->prefix . $k, $keys);
            return $this->redis->del($prefixed);
        } catch (\Exception $e) {
            $this->handleError($e);
            return 0;
        }
    }

    public function flush(): int
    {
        $count = 0;
        
        if ($this->isConnected()) {
            try {
                $pattern = $this->prefix . '*';
                if ($this->redis instanceof \Redis) {
                    $it = null;
                    while ($keys = $this->redis->scan($it, $pattern, 100)) {
                        if (!empty($keys)) {
                            $count += $this->redis->del(...$keys);
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->handleError($e);
            }
        }
        
        foreach (glob($this->fileCacheDir . '/*.cache') as $file) {
            @unlink($file);
            $count++;
        }
        
        return $count;
    }

    public function getInfo(): array
    {
        if (!$this->isConnected()) {
            return ['connected' => false, 'fallback' => 'file_cache'];
        }

        try {
            if ($this->redis instanceof \Redis) {
                $info = $this->redis->info('memory');
                return [
                    'connected' => true,
                    'driver' => 'phpredis',
                    'timeout' => defined('REDIS_TIMEOUT') ? REDIS_TIMEOUT : self::DEFAULT_TIMEOUT,
                    'used_memory' => $info['used_memory_human'] ?? 'N/A',
                    'uptime_days' => ($info['uptime_in_seconds'] ?? 0) / 86400,
                    'keys' => $this->redis->dbSize(),
                    'fallback' => 'file_cache',
                ];
            }
            return ['connected' => true, 'driver' => 'predis', 'fallback' => 'file_cache'];
        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage(), 'fallback' => 'file_cache'];
        }
    }

    private function handleError(\Exception $e): void
    {
        error_log('[RedisCache] Error: ' . $e->getMessage());
        $this->connected = false;
    }

    private function __clone() {}
}
