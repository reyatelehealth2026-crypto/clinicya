<?php
/**
 * RedisCache — Lightweight Redis adapter for dashboard API caching
 *
 * Replaces file-based + APCu caching with Redis for sub-millisecond cache reads.
 * Falls back gracefully to file-based cache if Redis is unavailable.
 *
 * ติดตั้ง Redis บน aaPanel:
 *   1. aaPanel → App Store → Redis → Install
 *   2. PHP 8.3 → Extensions → Install redis extension
 *   3. composer require predis/predis (optional, ใช้ phpredis native ดีกว่า)
 *
 * @version 1.0.0
 * @created 2026-03-20
 */

class RedisCache
{
    private static ?RedisCache $instance = null;
    private ?object $redis = null;
    private bool $connected = false;
    private string $prefix;

    // Default connection settings — override via config/config.php constants
    private const DEFAULT_HOST    = '127.0.0.1';
    private const DEFAULT_PORT    = 6379;
    private const DEFAULT_TIMEOUT = 2.0;
    private const DEFAULT_PREFIX  = 'cny:dash:';

    private function __construct()
    {
        $this->prefix = defined('REDIS_PREFIX') ? REDIS_PREFIX : self::DEFAULT_PREFIX;
        $this->connect();
    }

    /**
     * Singleton accessor
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Attempt Redis connection — silent fail, sets $connected flag
     */
    private function connect(): void
    {
        $host    = defined('REDIS_HOST') ? REDIS_HOST : self::DEFAULT_HOST;
        $port    = defined('REDIS_PORT') ? (int) REDIS_PORT : self::DEFAULT_PORT;
        $timeout = defined('REDIS_TIMEOUT') ? (float) REDIS_TIMEOUT : self::DEFAULT_TIMEOUT;
        $pass    = defined('REDIS_PASSWORD') ? REDIS_PASSWORD : null;
        $db      = defined('REDIS_DB') ? (int) REDIS_DB : 0;

        // Prefer native phpredis extension (faster than Predis)
        if (extension_loaded('redis')) {
            try {
                $r = new \Redis();
                $r->connect($host, $port, $timeout);
                if ($pass) {
                    $r->auth($pass);
                }
                if ($db > 0) {
                    $r->select($db);
                }
                $r->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
                $r->setOption(\Redis::OPT_PREFIX, $this->prefix);
                $this->redis = $r;
                $this->connected = true;
                return;
            } catch (\Exception $e) {
                // Fall through to Predis or file-based
                $this->redis = null;
            }
        }

        // Fallback: Predis library (pure PHP, slower but no extension needed)
        if (class_exists('Predis\\Client')) {
            try {
                $params = [
                    'scheme'  => 'tcp',
                    'host'    => $host,
                    'port'    => $port,
                    'timeout' => $timeout,
                ];
                if ($pass) {
                    $params['password'] = $pass;
                }
                if ($db > 0) {
                    $params['database'] = $db;
                }
                $r = new \Predis\Client($params);
                $r->ping(); // Test connection
                $this->redis = $r;
                $this->connected = true;
                return;
            } catch (\Exception $e) {
                $this->redis = null;
            }
        }

        $this->connected = false;
    }

    /**
     * Check if Redis is available
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->redis !== null;
    }

    /**
     * Get cached value by key
     *
     * @param string $key
     * @return mixed|null Returns null on miss
     */
    public function get(string $key)
    {
        if (!$this->connected) {
            return null;
        }

        try {
            if ($this->redis instanceof \Redis) {
                $raw = $this->redis->get($key);
                if ($raw === false) {
                    return null;
                }
                // phpredis with JSON serializer returns decoded data
                return $raw;
            }

            // Predis
            $raw = $this->redis->get($this->prefix . $key);
            if ($raw === null) {
                return null;
            }
            $data = json_decode($raw, true);
            return $data;
        } catch (\Exception $e) {
            $this->handleError($e);
            return null;
        }
    }

    /**
     * Set cache value with TTL
     *
     * @param string $key
     * @param mixed  $data
     * @param int    $ttl  TTL in seconds
     * @return bool
     */
    public function set(string $key, $data, int $ttl = 60): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            if ($this->redis instanceof \Redis) {
                return $this->redis->setex($key, $ttl, $data);
            }

            // Predis
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
            $this->redis->setex($this->prefix . $key, $ttl, $encoded);
            return true;
        } catch (\Exception $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * Delete one or more keys
     *
     * @param string|array $keys
     * @return int Number of keys deleted
     */
    public function del($keys): int
    {
        if (!$this->connected) {
            return 0;
        }

        try {
            $keys = (array) $keys;
            if ($this->redis instanceof \Redis) {
                return $this->redis->del(...$keys);
            }

            // Predis — needs prefix
            $prefixed = array_map(fn($k) => $this->prefix . $k, $keys);
            return $this->redis->del($prefixed);
        } catch (\Exception $e) {
            $this->handleError($e);
            return 0;
        }
    }

    /**
     * Flush all dashboard cache keys (pattern: cny:dash:*)
     *
     * @return int Number of keys flushed
     */
    public function flush(): int
    {
        if (!$this->connected) {
            return 0;
        }

        try {
            $count = 0;
            $pattern = $this->prefix . '*';

            if ($this->redis instanceof \Redis) {
                $it = null;
                while ($keys = $this->redis->scan($it, '*', 100)) {
                    if (!empty($keys)) {
                        $count += $this->redis->del(...$keys);
                    }
                }
            } else {
                // Predis
                $keys = [];
                foreach (new \Predis\Collection\Iterator\Keyspace($this->redis, $pattern) as $key) {
                    $keys[] = $key;
                    if (count($keys) >= 100) {
                        $count += $this->redis->del($keys);
                        $keys = [];
                    }
                }
                if (!empty($keys)) {
                    $count += $this->redis->del($keys);
                }
            }

            return $count;
        } catch (\Exception $e) {
            $this->handleError($e);
            return 0;
        }
    }

    /**
     * Get Redis info for health check
     *
     * @return array
     */
    public function getInfo(): array
    {
        if (!$this->connected) {
            return ['connected' => false];
        }

        try {
            if ($this->redis instanceof \Redis) {
                $info = $this->redis->info('memory');
                return [
                    'connected'     => true,
                    'driver'        => 'phpredis',
                    'used_memory'   => $info['used_memory_human'] ?? 'N/A',
                    'uptime_days'   => ($info['uptime_in_seconds'] ?? 0) / 86400,
                    'keys'          => $this->redis->dbSize(),
                ];
            }

            // Predis
            $info = $this->redis->info('memory');
            return [
                'connected'     => true,
                'driver'        => 'predis',
                'used_memory'   => $info['Memory']['used_memory_human'] ?? 'N/A',
            ];
        } catch (\Exception $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Handle Redis error — mark as disconnected to prevent cascade failures
     */
    private function handleError(\Exception $e): void
    {
        // Log error silently
        error_log('[RedisCache] Error: ' . $e->getMessage());

        // After error, mark disconnected to avoid retry overhead
        $this->connected = false;
        $this->redis = null;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}
}
