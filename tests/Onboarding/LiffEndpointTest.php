<?php
/**
 * Property-Based Test: Onboarding LIFF / Mini App endpoint
 *
 * **Feature: onboarding-saas-alignment, Property 1: Mini App endpoint path**
 *
 * Property: the onboarding wizard must hand tenant admins the URL where the
 * deployed Mini App is actually served — `/miniapp/` (Next.js static export,
 * `next.config basePath: '/miniapp'`) — NOT the source folder `/line-mini-app/`.
 * A wrong path means the tenant pastes a dead Endpoint URL into LINE and the
 * Mini App never opens.
 */

namespace Tests\Onboarding;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/onboarding/onboarding-helpers.php';

class LiffEndpointTest extends TestCase
{
    /** @return array<int,array{0:string}> */
    public static function baseUrlProvider(): array
    {
        return [
            ['https://tenant-0001.re-ya.com'],
            ['https://tenant-0001.re-ya.com/'],   // trailing slash must not double up
            ['http://localhost'],
            ['https://demo.re-ya.com'],
            ['https://re-ya.com'],
        ];
    }

    /**
     * @dataProvider baseUrlProvider
     */
    public function testEndpointPointsToMiniappPath(string $baseUrl): void
    {
        $endpoint = reya_miniapp_endpoint($baseUrl);

        // Must end with exactly '/miniapp/'
        $this->assertStringEndsWith('/miniapp/', $endpoint,
            "Endpoint must serve the deployed Mini App at /miniapp/");

        // Must NEVER reference the source folder
        $this->assertStringNotContainsString('/line-mini-app/', $endpoint,
            "/line-mini-app/ is the source dir, not the served path");

        // No accidental double slashes in the path portion (after scheme)
        $afterScheme = preg_replace('#^https?://#', '', $endpoint);
        $this->assertStringNotContainsString('//', $afterScheme,
            "No double slashes in path: {$endpoint}");
    }

    /**
     * Regression guard on the production wizard itself: the old hard-coded
     * '/line-mini-app/' endpoint must be gone, and it must use the helper.
     */
    public function testWizardNoLongerHardcodesSourceFolder(): void
    {
        $wizard = file_get_contents(__DIR__ . '/../../onboarding/wizard.php');
        $this->assertStringNotContainsString("'/line-mini-app/'", $wizard,
            "wizard.php must not hard-code the /line-mini-app/ source path");
        $this->assertStringContainsString('reya_miniapp_endpoint', $wizard,
            "wizard.php must derive the endpoint via reya_miniapp_endpoint()");
    }
}
