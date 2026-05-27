<?php
/**
 * config/database.php — preserved for backward-compat with files that
 * `require_once 'config/database.php'` (and there are ~100 such files).
 *
 * Body delegated to classes/Database.php as part of Phase 1 SaaS migration
 * (ADR-001: Database-per-Tenant Isolation Model).
 *
 * The global \Database class is now defined in classes/Database.php — which
 * resolves to per-tenant connections via TenantContext when available, and
 * falls back to the legacy DB_NAME connection otherwise (pre-migration safety
 * net described in modules/Core/Database.php::legacyFallback()).
 *
 * EMERGENCY ROLLBACK:
 *   The pre-Phase-1 version of this file (a self-contained singleton that
 *   ALWAYS used DB_NAME) is preserved at config/database.legacy.php. To roll
 *   back, replace the require_once below with `require_once __DIR__ . '/database.legacy.php';`
 *   and clear opcache.
 */
require_once __DIR__ . '/../classes/Database.php';

// Subdomain tenant resolution — Option A SaaS routing.
// Skips automatically when REYA_SKIP_SUBDOMAIN_RESOLUTION is defined (CLI/cron).
// Fails open: any error → log + fall through, app keeps working on legacy fallback.
if (!defined('REYA_SKIP_SUBDOMAIN_RESOLUTION')) {
    $__reya_bootstrap = __DIR__ . '/../bootstrap/resolve_subdomain.php';
    if (is_file($__reya_bootstrap)) {
        require_once $__reya_bootstrap;
    }
    unset($__reya_bootstrap);
}
