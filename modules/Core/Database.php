<?php
declare(strict_types=1);

namespace Modules\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database — per-tenant connection factory + backward-compat singleton.
 *
 * Connection pooling: one PDO per (db_name, host) tuple, reused within a request.
 * No cross-request pooling — PHP-FPM doesn't preserve state.
 *
 * Usage:
 *   $db = Database::getInstance()->getConnection();   // BC: current tenant via TenantContext
 *   $db = Database::forTenant(42)->getConnection();   // explicit per-tenant
 *   $db = Database::platform()->getConnection();      // master DB (reya_platform)
 *
 * Transition behaviour:
 *   - If TenantContext::getCurrentTenantId() is null AND reya_platform doesn't
 *     exist yet → fall back to the legacy DB (DB_NAME from config/config.php).
 *     This is the safety net while we run pre-migration. After migration, the
 *     legacy DB will be archived and this fallback will start throwing.
 *   - If reya_platform exists but TenantContext has no tenant → throw. At that
 *     point every caller MUST be aware of tenant context.
 *
 * Helper methods (query/fetchOne/fetchAll/insert/update/execute/lastInsertId/exec)
 * are preserved verbatim from the pre-refactor singleton — call sites depend on them.
 */
class Database
{
    /** @var array<string, self> instance pool keyed by db_name */
    private static array $instances = [];

    /** Cached check for whether reya_platform exists this request. */
    private static ?bool $platformDbExists = null;

    private PDO $connection;
    private string $dbName;

    private function __construct(string $dbName, string $host, string $user, string $pass)
    {
        $this->dbName = $dbName;
        try {
            $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
            $this->connection = new PDO(
                $dsn,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]
            );
            $this->connection->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Cannot connect to database '{$dbName}' on '{$host}': " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Backward-compat entry point. Resolves DB target via TenantContext:
     *   - Platform context  → reya_platform
     *   - Tenant resolvable → reya_tenant_XXXX (from master.tenants.db_name)
     *   - Nothing resolvable + no master DB → legacy fallback (pre-migration)
     *   - Nothing resolvable + master DB exists → throw (forces caller to be explicit)
     */
    public static function getInstance(): self
    {
        self::ensureTenantContextLoaded();

        if (\TenantContext::isPlatformContext()) {
            return self::platform();
        }

        $tenantId = \TenantContext::getCurrentTenantId();
        if ($tenantId !== null) {
            return self::forTenant($tenantId);
        }

        // Transition safety net — fallback to legacy shared DB whenever no tenant
        // context is set. This keeps ~700 unrefactored call sites working while we
        // migrate them one-by-one. Once every entry point sets TenantContext,
        // change this to throw (uncomment block below).
        //
        // throw new RuntimeException(
        //     'TenantContext not set — call TenantContext::setCurrentTenantId() '
        //     . 'or Database::forTenant($id) / Database::platform() before using '
        //     . 'Database::getInstance().'
        // );
        // 2026-05-26: silence noisy cold-fallback log on the public landing
        // (index.php / health checks / robots hits flood the log). The breadcrumb
        // only triggers when explicitly enabled via env REYA_LOG_COLD_FALLBACK=1.
        if (getenv('REYA_LOG_COLD_FALLBACK') === '1' && function_exists('error_log')) {
            static $lastWarn = 0;
            if (time() - $lastWarn >= 30) { // throttle to once per 30s
                $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
                $caller = isset($bt[1]) ? ($bt[1]['file'] ?? '?') . ':' . ($bt[1]['line'] ?? '?') : '?';
                error_log('[Database::getInstance] cold fallback to legacy DB — caller=' . $caller);
                $lastWarn = time();
            }
        }
        return self::legacyFallback();
    }

    /**
     * Explicit per-tenant connection. Looks up db_name from master.tenants.
     */
    public static function forTenant(int $tenantId): self
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException("Invalid tenant id: {$tenantId}");
        }

        // Use a temporary key so we can rewrite to db_name once resolved.
        $cacheKey = "tenant:{$tenantId}";
        if (isset(self::$instances[$cacheKey])) {
            return self::$instances[$cacheKey];
        }

        $dbName = self::resolveTenantDbName($tenantId);
        if ($dbName === null) {
            throw new RuntimeException(
                "Tenant id {$tenantId} not found in master.tenants — cannot route connection."
            );
        }

        // Reuse pool entry keyed by db_name if another path already created it.
        if (isset(self::$instances[$dbName])) {
            self::$instances[$cacheKey] = self::$instances[$dbName];
            return self::$instances[$dbName];
        }

        $instance = new self($dbName, \DB_HOST, \DB_USER, \DB_PASS);
        self::$instances[$dbName]   = $instance;
        self::$instances[$cacheKey] = $instance;
        return $instance;
    }

    /**
     * Master DB connection (reya_platform).
     */
    public static function platform(): self
    {
        $dbName = \TenantContext::PLATFORM_DB_NAME;
        if (isset(self::$instances[$dbName])) {
            return self::$instances[$dbName];
        }
        $instance = new self($dbName, \DB_HOST, \DB_USER, \DB_PASS);
        self::$instances[$dbName] = $instance;
        return $instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function getDbName(): string
    {
        return $this->dbName;
    }

    /**
     * Reset the entire pool. Test hook + useful for cron loops that need to
     * release per-tenant connections between iterations.
     */
    public static function resetAll(): void
    {
        self::$instances        = [];
        self::$platformDbExists = null;
    }

    // ---------------------------------------------------------------------
    // Preserved helper API — call sites depend on these.
    // ---------------------------------------------------------------------

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt   = $this->query($sql, $params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(string $table, array $data): int
    {
        $columns      = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql          = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        return (int) $this->connection->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set  = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql  = "UPDATE {$table} SET {$set} WHERE {$where}";
        $stmt = $this->query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->connection->prepare($sql);
        return $stmt->execute($params);
    }

    public function lastInsertId(): int
    {
        return (int) $this->connection->lastInsertId();
    }

    public function exec(string $sql): int
    {
        return (int) $this->connection->exec($sql);
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    /**
     * Make sure TenantContext is loadable before we ask it anything.
     * In legacy load orders, classes/Database.php may not have been required yet.
     */
    private static function ensureTenantContextLoaded(): void
    {
        if (!class_exists('TenantContext', false)) {
            $path = __DIR__ . '/../../classes/TenantContext.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Probe whether reya_platform exists. Cached per request.
     */
    private static function platformDbExists(): bool
    {
        if (self::$platformDbExists !== null) {
            return self::$platformDbExists;
        }

        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS')) {
            self::$platformDbExists = false;
            return false;
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . \DB_HOST . ';charset=utf8mb4',
                \DB_USER,
                \DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->prepare(
                'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1'
            );
            $stmt->execute([\TenantContext::PLATFORM_DB_NAME]);
            self::$platformDbExists = (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            self::$platformDbExists = false;
        }
        return self::$platformDbExists;
    }

    /**
     * Look up db_name for tenant in master.tenants.
     */
    private static function resolveTenantDbName(int $tenantId): ?string
    {
        try {
            $masterPdo = self::platform()->getConnection();
            $stmt = $masterPdo->prepare('SELECT db_name FROM tenants WHERE id = ? LIMIT 1');
            $stmt->execute([$tenantId]);
            $name = $stmt->fetchColumn();
            return $name !== false ? (string) $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Pre-migration fallback to the legacy shared DB.
     * Once reya_platform exists this path is never taken.
     */
    private static function legacyFallback(): self
    {
        if (!defined('DB_NAME')) {
            throw new RuntimeException(
                'Legacy fallback requested but DB_NAME constant is not defined. '
                . 'config/config.php must be loaded before Database::getInstance().'
            );
        }
        $dbName = \DB_NAME;
        if (isset(self::$instances[$dbName])) {
            return self::$instances[$dbName];
        }
        $instance = new self($dbName, \DB_HOST, \DB_USER, \DB_PASS);
        self::$instances[$dbName] = $instance;
        return $instance;
    }
}
