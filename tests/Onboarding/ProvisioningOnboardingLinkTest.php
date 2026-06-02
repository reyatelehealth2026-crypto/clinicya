<?php
/**
 * Property-Based Test: Provisioning hands off to onboarding
 *
 * **Feature: onboarding-saas-alignment, Property 4: provision → onboarding bridge**
 *
 * A freshly provisioned tenant admin (onboarding_completed=0, skipped=0) must be
 * routed into the 7-step setup wizard. We assert (a) the canonical first-run URL,
 * (b) the should-onboard decision, and (c) that the provisioning success message
 * actually references the onboarding wizard so the new admin knows where to go.
 */

namespace Tests\Onboarding;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/onboarding/onboarding-helpers.php';

class ProvisioningOnboardingLinkTest extends TestCase
{
    public function testFirstRunUrlIsTheWizard(): void
    {
        $this->assertSame('/onboarding/wizard.php', reya_onboarding_first_run_url());
    }

    /** @return array<int,array{0:array<string,int>,1:bool}> */
    public static function onboardStateProvider(): array
    {
        return [
            [['onboarding_completed' => 0, 'onboarding_skipped' => 0], true],   // fresh tenant
            [['onboarding_completed' => 1, 'onboarding_skipped' => 0], false],  // done
            [['onboarding_completed' => 0, 'onboarding_skipped' => 1], false],  // skipped
            [[], true],                                                          // unknown → treat as fresh
        ];
    }

    /**
     * @dataProvider onboardStateProvider
     * @param array<string,int> $state
     */
    public function testShouldOnboard(array $state, bool $expected): void
    {
        $this->assertSame($expected, reya_should_onboard($state));
    }

    /**
     * Regression guard: provisioning success message must mention the onboarding
     * wizard so a new tenant admin is told to complete setup.
     */
    public function testProvisionSuccessMentionsOnboarding(): void
    {
        $switch = file_get_contents(__DIR__ . '/../../admin/switch-tenant.php');
        $this->assertStringContainsString('onboarding', $switch,
            'switch-tenant.php provisioning must reference onboarding for the new admin');
    }
}
