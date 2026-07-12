<?php
/**
 * infra/php/session-redis-handler.php
 *
 * Loaded via php.ini `auto_prepend_file` (see infra/php/session-redis.ini)
 * so it runs before every request script — admin pages, api/*.php,
 * webhook.php, cron/*.php — touches $_SESSION. Registers a Predis-backed
 * SessionHandlerInterface so the container holds no session state on local
 * disk, per plan §2.1 ("PHP session → Redis (container stateless, จำเป็น
 * ต่อ session bridge)") — the future Next.js session bridge (plan §1.4)
 * needs a stateless PHP host to hand sessions off from.
 *
 * This is new infra config, not a change to any existing app file — no
 * application code calls this or needs to know it exists.
 *
 * Predis (not the PECL `redis` extension) is used deliberately: it's
 * already a composer.json dependency ("composer ต้องการ >=8.0 + predis" —
 * plan §2.1), needs no compiled extension, and needs no network access at
 * Docker build time (unlike `pecl install redis`).
 *
 * Fails open: if vendor/autoload.php isn't mounted yet, Predis isn't
 * autoloadable, or Redis is unreachable, this file does nothing and PHP
 * keeps its default file-based session handler — a misconfigured
 * container degrades instead of 500ing every request.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_ACTIVE) {
    return; // a caller already started a session before this ran — leave it alone
}

$autoload = getenv('COMPOSER_AUTOLOAD') ?: '/var/www/html/vendor/autoload.php';
if (!is_file($autoload)) {
    return; // vendor/ not mounted/installed yet — degrade to file sessions
}

require_once $autoload;

if (!class_exists(\Predis\Client::class)) {
    return; // predis/predis not present in vendor/ — degrade to file sessions
}

if (!class_exists('ClinicyaRedisSessionHandler')) {
    /**
     * Thin SessionHandlerInterface adapter over Predis\Client. Keeps every
     * session key on a single Redis logical DB with a TTL matching PHP's
     * gc_maxlifetime — Redis expiry does the garbage collection, so gc()
     * is a no-op.
     */
    final class ClinicyaRedisSessionHandler implements \SessionHandlerInterface
    {
        public function __construct(
            private readonly \Predis\Client $redis,
            private readonly string $prefix,
            private readonly int $ttlSeconds
        ) {
        }

        public function open(string $path, string $name): bool
        {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $id): string|false
        {
            $data = $this->redis->get($this->prefix . $id);

            return $data === null ? '' : $data;
        }

        public function write(string $id, string $data): bool
        {
            $this->redis->setex($this->prefix . $id, $this->ttlSeconds, $data);

            return true;
        }

        public function destroy(string $id): bool
        {
            $this->redis->del([$this->prefix . $id]);

            return true;
        }

        public function gc(int $max_lifetime): int|false
        {
            // Redis TTL (setex, refreshed on every write) already expires
            // idle sessions — nothing to sweep here.
            return 0;
        }
    }
}

try {
    $client = new \Predis\Client([
        'scheme'   => 'tcp',
        'host'     => getenv('REDIS_SESSION_HOST') ?: (getenv('REDIS_HOST') ?: 'redis'),
        'port'     => (int) (getenv('REDIS_SESSION_PORT') ?: (getenv('REDIS_PORT') ?: 6379)),
        'password' => getenv('REDIS_SESSION_PASSWORD') ?: (getenv('REDIS_PASSWORD') ?: null),
        'database' => (int) (getenv('REDIS_SESSION_DB') ?: 1), // distinct from app cache REDIS_DB=0
        'timeout'  => 3.0,
    ]);
    // Cheap round trip so a dead Redis falls back to file sessions instead
    // of failing later, mid-request, inside session_start().
    $client->connect();

    session_set_save_handler(
        new ClinicyaRedisSessionHandler(
            $client,
            getenv('REDIS_SESSION_PREFIX') ?: 'cny:sess:',
            (int) (getenv('SESSION_TTL') ?: 1440)
        ),
        true
    );
} catch (\Throwable $e) {
    error_log('[session-redis-handler] Redis unreachable, falling back to file sessions: ' . $e->getMessage());
}
