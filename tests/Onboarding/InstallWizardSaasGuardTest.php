<?php
/**
 * Property-Based Test: Install wizard is SaaS-aware
 *
 * **Feature: onboarding-saas-alignment, Property 3: installer guards multi-tenant**
 *
 * The legacy install wizard writes a single DB_NAME / LIFF_ID into config.php —
 * a single-tenant assumption that conflicts with Wave-3 database-per-tenant.
 * When the platform (master) DB is configured, the installer must surface a
 * notice telling the operator to create tenants via the provisioning flow
 * (admin/switch-tenant.php) instead of re-running the single-tenant installer.
 */

namespace Tests\Onboarding;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/onboarding/onboarding-helpers.php';

class InstallWizardSaasGuardTest extends TestCase
{
    public function testNoticeShownWhenPlatformConfigured(): void
    {
        $notice = reya_install_saas_notice(true, true);
        $this->assertIsString($notice, 'A SaaS notice must be returned when platform DB is configured');
        $this->assertStringContainsString('switch-tenant', $notice,
            'The notice must point operators at the tenant provisioning flow');
    }

    public function testNoNoticeForFreshSingleTenantInstall(): void
    {
        // Not yet installed and no platform DB → legacy single-tenant install is fine.
        $this->assertNull(reya_install_saas_notice(false, false));
    }

    public function testNoticeRegardlessOfInstalledFlagWhenSaaS(): void
    {
        // Once SaaS is in play, the notice stands whether or not installed.lock exists.
        $this->assertIsString(reya_install_saas_notice(true, false));
        $this->assertIsString(reya_install_saas_notice(true, true));
    }

    /**
     * Regression guard: the installer page must actually render the notice.
     */
    public function testInstallWizardRendersNotice(): void
    {
        $wizard = file_get_contents(__DIR__ . '/../../install/wizard.php');
        $this->assertStringContainsString('reya_install_saas_notice', $wizard,
            'install/wizard.php must consult reya_install_saas_notice()');
    }
}
