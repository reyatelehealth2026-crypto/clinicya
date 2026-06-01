<?php
/**
 * Onboarding / SaaS helpers.
 *
 * Small, pure, side-effect-free functions shared by the setup wizard, the
 * SetupStatusChecker, the (legacy) install wizard, and tenant provisioning.
 * Kept dependency-free so they are trivially unit-testable.
 */

if (!function_exists('reya_miniapp_endpoint')) {
    /**
     * The public URL where the deployed LINE Mini App is served.
     *
     * The Mini App is a Next.js static export with `basePath: '/miniapp'`, copied
     * to <docroot>/miniapp/ and served by Apache. Tenant admins paste THIS as the
     * LIFF Endpoint URL. It is NOT `/line-mini-app/` (that is the source folder).
     *
     * @param string $baseUrl Scheme+host (optionally with trailing slash), e.g.
     *                        "https://tenant-0001.re-ya.com". In SaaS this is the
     *                        tenant's own subdomain (from HTTP_HOST), so the
     *                        endpoint is automatically tenant-correct.
     */
    function reya_miniapp_endpoint(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/miniapp/';
    }
}

if (!function_exists('reya_onboarding_first_run_url')) {
    /** Canonical URL a not-yet-onboarded admin should be routed to. */
    function reya_onboarding_first_run_url(): string
    {
        return '/onboarding/wizard.php';
    }
}

if (!function_exists('reya_should_onboard')) {
    /**
     * Should this admin be sent through the setup wizard?
     * True unless they have completed OR explicitly skipped onboarding.
     *
     * @param array<string,mixed> $state Row from admin_users (onboarding_* cols).
     */
    function reya_should_onboard(array $state): bool
    {
        $completed = (int) ($state['onboarding_completed'] ?? 0) === 1;
        $skipped   = (int) ($state['onboarding_skipped'] ?? 0) === 1;
        return !$completed && !$skipped;
    }
}

if (!function_exists('reya_install_saas_notice')) {
    /**
     * Returns a notice (or null) for the legacy single-tenant install wizard.
     *
     * When the platform/master DB is configured the deployment is Wave-3
     * database-per-tenant, where the install wizard's single DB_NAME / LIFF_ID
     * write no longer models reality — tenants are created via the provisioning
     * flow instead. Return a message pointing the operator there.
     *
     * @param bool $platformConfigured Master (SaaS) DB is in play.
     * @param bool $installed          config/installed.lock already exists.
     */
    function reya_install_saas_notice(bool $platformConfigured, bool $installed): ?string
    {
        if (!$platformConfigured) {
            return null;
        }
        return 'ระบบนี้ทำงานแบบ multi-tenant (database-per-tenant) แล้ว — '
             . 'การสร้างร้านใหม่ให้ทำผ่านหน้า provisioning ที่ /admin/switch-tenant.php '
             . '(ไม่ใช่ตัวติดตั้งร้านเดียวนี้). ตัวติดตั้งนี้ใช้สำหรับ bootstrap แพลตฟอร์มครั้งแรกเท่านั้น.';
    }
}
