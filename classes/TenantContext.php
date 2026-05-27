<?php
declare(strict_types=1);

/**
 * TenantContext — resolves the current tenant for the active request.
 *
 * Resolution priority:
 *  1. Explicit override via TenantContext::setCurrentTenantId($id) — used by cron loops
 *     and the super-admin "switch tenant" UI. Wins over everything else.
 *  2. $_SESSION['active_tenant_id'] — set by the auth flow on login or by the
 *     explicit switch endpoint.
 *  3. $_SESSION['user_id'] → SELECT tenant_id FROM platform_users WHERE id = ?
 *     (cached in $_SESSION['active_tenant_id'] for subsequent requests).
 *  4. $_SESSION['current_bot_id'] → master.tenants WHERE legacy_line_account_id = ?
 *     (transition aid — lets pre-refactor sessions keep working until everyone
 *     re-logs in under the new model).
 *  5. null — unauthenticated request OR no tenant resolvable. Caller decides
 *     whether to fall back to the legacy DB, throw, or enter platform context.
 *
 * IMPORTANT: super-admins do NOT get an implicit tenant via this resolver.
 * They MUST call setCurrentTenantId($id) explicitly to enter a tenant scope,
 * or enterPlatformContext() to query reya_platform. This is intentional — it
 * prevents accidental cross-tenant reads driven by whoever last logged in.
 *
 * Notes on the master DB:
 *   - Master DB name is hardcoded to 'reya_platform' here. When the platform
 *     config is formalised this will move to a constant in config/config.php.
 *   - Credentials reuse the existing DB_HOST/DB_USER/DB_PASS constants — the
 *     same MariaDB user is granted access to both reya_platform and every
 *     reya_tenant_* schema (see ADR-001).
 */
class TenantContext
{
    /**
     * Hardcoded master DB name. cPanel shared-hosting requires the account prefix
     * (`zrismpsz_`) — see ADR-001 "Hosting constraint". Move to config later.
     */
    public const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

    private static ?int $currentTenantId = null;
    private static bool $isPlatformContext = false;

    /** Cached PDO to master DB for tenant lookups within one request. */
    private static ?\PDO $masterPdo = null;

    /**
     * Explicit override. Pass null to clear.
     * Used by:
     *   - Cron loops iterating tenants
     *   - Super-admin "switch tenant" endpoint
     *   - Tests
     */
    public static function setCurrentTenantId(?int $tenantId): void
    {
        self::$currentTenantId = $tenantId;
        // Setting a tenant exits platform context — they are mutually exclusive.
        if ($tenantId !== null) {
            self::$isPlatformContext = false;
        }
    }

    /**
     * Resolves the current tenant by walking the priority list above.
     * Returns null if nothing matches — caller must handle that case.
     */
    public static function getCurrentTenantId(): ?int
    {
        // 1. Explicit override always wins.
        if (self::$currentTenantId !== null) {
            return self::$currentTenantId;
        }

        // Session may not be active (CLI / webhook / cron contexts).
        $hasSession = session_status() === PHP_SESSION_ACTIVE;

        // 2. Session-cached active_tenant_id.
        if ($hasSession && !empty($_SESSION['active_tenant_id'])) {
            $tid = (int) $_SESSION['active_tenant_id'];
            if ($tid > 0) {
                self::$currentTenantId = $tid;
                return $tid;
            }
        }

        // 3. Look up by user_id in platform_users.
        if ($hasSession && !empty($_SESSION['user_id'])) {
            $tid = self::lookupTenantByUserId((int) $_SESSION['user_id']);
            if ($tid !== null) {
                self::$currentTenantId = $tid;
                $_SESSION['active_tenant_id'] = $tid;
                return $tid;
            }
        }

        // 4. Transition aid — resolve from legacy LINE account binding.
        if ($hasSession && !empty($_SESSION['current_bot_id'])) {
            $tid = self::lookupTenantByLegacyLineAccount((int) $_SESSION['current_bot_id']);
            if ($tid !== null) {
                self::$currentTenantId = $tid;
                $_SESSION['active_tenant_id'] = $tid;
                return $tid;
            }
        }

        // 5. Nothing resolvable.
        return null;
    }

    /**
     * Strict variant — throws if no tenant context is available.
     * Use in code paths that must not silently fall through to legacy DB.
     */
    public static function requireTenantId(): int
    {
        $tid = self::getCurrentTenantId();
        if ($tid === null) {
            throw new \RuntimeException(
                'TenantContext::requireTenantId() called with no active tenant. '
                . 'Call TenantContext::setCurrentTenantId(int) first, or ensure the '
                . 'session has user_id / active_tenant_id set.'
            );
        }
        return $tid;
    }

    /**
     * Enter platform (master DB) context. Super-admin only.
     * Caller is responsible for verifying isSuperAdmin() before calling this.
     */
    public static function enterPlatformContext(): void
    {
        self::$isPlatformContext = true;
        self::$currentTenantId = null;
    }

    public static function exitPlatformContext(): void
    {
        self::$isPlatformContext = false;
    }

    public static function isPlatformContext(): bool
    {
        return self::$isPlatformContext;
    }

    /**
     * Reset all state. Test hook — also useful for long-running CLI scripts that
     * process multiple tenants in sequence.
     */
    public static function reset(): void
    {
        self::$currentTenantId = null;
        self::$isPlatformContext = false;
        self::$masterPdo = null;
    }

    // ---------------------------------------------------------------------
    // Internal: master DB lookups
    // ---------------------------------------------------------------------

    /**
     * Lazy-open a PDO to reya_platform. Returns null if master DB doesn't
     * exist yet (pre-migration state) — caller treats that as "no tenant
     * resolvable" and falls through to legacy mode.
     */
    private static function getMasterPdo(): ?\PDO
    {
        if (self::$masterPdo !== null) {
            return self::$masterPdo;
        }

        if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASS')) {
            return null;
        }

        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . self::PLATFORM_DB_NAME . ';charset=utf8mb4';
            self::$masterPdo = new \PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]
            );
            self::$masterPdo->exec("SET time_zone = '+07:00'");
            return self::$masterPdo;
        } catch (\PDOException $e) {
            // Master DB doesn't exist yet — pre-migration. Caller falls back.
            return null;
        }
    }

    private static function lookupTenantByUserId(int $userId): ?int
    {
        $pdo = self::getMasterPdo();
        if ($pdo === null) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT tenant_id FROM platform_users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['tenant_id'])) {
                return (int) $row['tenant_id'];
            }
        } catch (\PDOException $e) {
            // platform_users not yet provisioned — pre-migration.
        }
        return null;
    }

    private static function lookupTenantByLegacyLineAccount(int $lineAccountId): ?int
    {
        // Prefer the explicit routing table (added 2026-05-27 for webhook + LIFF
        // root-domain requests). Falls back to the historical column probe so we
        // keep working in mixed-deploy states.
        $viaRoute = self::resolveTenantByLineAccount($lineAccountId);
        if ($viaRoute !== null) {
            return $viaRoute;
        }
        $pdo = self::getMasterPdo();
        if ($pdo === null) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT id FROM tenants WHERE legacy_line_account_id = ? LIMIT 1');
            $stmt->execute([$lineAccountId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['id'])) {
                return (int) $row['id'];
            }
        } catch (\PDOException $e) {
            // tenants table not yet provisioned — pre-migration.
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // 2026-05-27 — Explicit LINE-account routing
    //
    // Used by request entry points that come in via the ROOT domain
    // (webhook.php, api/checkout.php, api/member.php, api/orders.php, ...)
    // where no subdomain hint is available. The client always passes the
    // line_account_id; we look it up in the platform routing table and pin
    // TenantContext so subsequent Database::getInstance() calls hit the right
    // tenant DB instead of the legacy fallback.
    // ---------------------------------------------------------------------

    /** Process-wide cache (one PHP request = one lookup per line_account_id). */
    private static array $lineAccountRouteCache = [];

    /**
     * Look up tenant_id by line_account_id via the platform routing table.
     * Returns null when the route is missing — caller decides whether to
     * fall through to legacy or 4xx.
     */
    public static function resolveTenantByLineAccount(int $lineAccountId): ?int
    {
        if ($lineAccountId <= 0) {
            return null;
        }
        if (array_key_exists($lineAccountId, self::$lineAccountRouteCache)) {
            return self::$lineAccountRouteCache[$lineAccountId];
        }
        $pdo = self::getMasterPdo();
        if ($pdo === null) {
            return (self::$lineAccountRouteCache[$lineAccountId] = null);
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT tenant_id FROM tenant_line_account_routes
                  WHERE line_account_id = ? AND is_active = 1
                  ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute([$lineAccountId]);
            $tid = (int) ($stmt->fetchColumn() ?: 0);
            return (self::$lineAccountRouteCache[$lineAccountId] = $tid > 0 ? $tid : null);
        } catch (\PDOException $e) {
            // Table not yet provisioned — silently fall through.
            return (self::$lineAccountRouteCache[$lineAccountId] = null);
        }
    }

    /**
     * One-shot helper for request bootstraps: if no tenant is set yet AND we
     * can resolve from line_account_id → pin TenantContext to that tenant.
     * Returns true when routing was applied, false otherwise.
     *
     * Safe to call multiple times: a tenant already set by subdomain wins.
     */
    public static function routeByLineAccount(int $lineAccountId): bool
    {
        // Already pinned by subdomain or earlier hop? Respect it.
        if (self::$currentTenantId !== null) {
            return false;
        }
        $tid = self::resolveTenantByLineAccount($lineAccountId);
        if ($tid === null) {
            return false;
        }
        self::setCurrentTenantId($tid);
        // Best-effort: update last_seen_at without blocking on it.
        try {
            $pdo = self::getMasterPdo();
            if ($pdo !== null) {
                $pdo->prepare('UPDATE tenant_line_account_routes SET last_seen_at = NOW() WHERE line_account_id = ? AND tenant_id = ?')
                    ->execute([$lineAccountId, $tid]);
            }
        } catch (\Throwable $e) {
            // ignore — telemetry only
        }
        return true;
    }
}
