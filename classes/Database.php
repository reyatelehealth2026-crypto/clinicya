<?php
/**
 * Backward-compat shim for the global \Database class.
 *
 * History: this file used to be a stub that just required the namespaced
 * Modules\Core\Database. The actual global \Database class lived in
 * config/database.php. With the Database-per-Tenant refactor (ADR-001),
 * we want a single global Database that proxies to the tenant-aware factory.
 *
 * ============================================================================
 * WARNING — class collision with config/database.php
 * ============================================================================
 * config/database.php STILL defines its own `class Database { ... }`.
 * If config/database.php is required first, IT wins (different connection,
 * no tenant routing). If THIS file is required first, OUR shim wins.
 *
 * Both are guarded with class_exists(), so neither will fatal — but the
 * effective behaviour depends on load order. Most admin pages load
 * config/database.php directly, so they keep the old behaviour until
 * Phase 1 swaps that file's body to `require_once classes/Database.php`.
 *
 * For now, this is intentional: it preserves prod behaviour while letting
 * new code that explicitly requires classes/Database.php get the tenant-aware
 * version. Once config/database.php is consolidated (Phase 1) this warning
 * goes away.
 * ============================================================================
 */

require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/../modules/Core/Database.php';

if (!class_exists('Database', false)) {

    /**
     * Global \Database — thin proxy over \Modules\Core\Database.
     *
     * Every legacy call site like:
     *     $db = Database::getInstance()->getConnection();
     * keeps working unchanged. New code can opt into:
     *     $db = Database::forTenant($tenantId)->getConnection();
     *     $db = Database::platform()->getConnection();
     */
    class Database
    {
        /** @return \Modules\Core\Database */
        public static function getInstance()
        {
            return \Modules\Core\Database::getInstance();
        }

        /** @return \Modules\Core\Database */
        public static function forTenant(int $tenantId)
        {
            return \Modules\Core\Database::forTenant($tenantId);
        }

        /** @return \Modules\Core\Database */
        public static function platform()
        {
            return \Modules\Core\Database::platform();
        }

        /** Release one tenant's pooled connection (for CLI sweep loops). */
        public static function releaseTenant(int $tenantId): void
        {
            \Modules\Core\Database::releaseTenant($tenantId);
        }
    }
}
