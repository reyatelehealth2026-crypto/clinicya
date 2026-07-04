<?php
/**
 * Property-Based Test: OnboardingWizard step state machine
 *
 * Feature: tenant-onboard-wizard (Phase 3, Task 3.2)
 *
 * classes/OnboardingWizard.php is a pure, DB-free step state machine used by
 * admin/tenant-onboard.php (the platform-admin guided onboarding page for a
 * freshly-provisioned tenant). These properties pin down step order,
 * validation, completion tracking, and resumability across random progress
 * states — no DB required, since the class only operates on a plain
 * step=>bool progress map.
 */

declare(strict_types=1);

namespace Tests\Onboarding;

use PHPUnit\Framework\TestCase;
use OnboardingWizard;

require_once __DIR__ . '/../../classes/OnboardingWizard.php';

class OnboardingWizardStepMachinePropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    // -------------------------------------------------------------------
    // Property 1: step order is fixed and canEnterStep respects it
    // -------------------------------------------------------------------
    public function testCanonicalStepOrderIsStable(): void
    {
        $wizard = new OnboardingWizard();
        $this->assertSame(['shop', 'line', 'ai', 'done'], $wizard->steps());
    }

    public function testCanEnterStepRequiresAllPriorStepsCompleted(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $steps = (new OnboardingWizard())->steps();
            // Random subset of prior steps completed, in random order — the
            // machine must still enforce canonical order for reachability.
            $completedCount = random_int(0, count($steps));
            $progress = [];
            for ($j = 0; $j < $completedCount; $j++) {
                $progress[$steps[$j]] = true;
            }
            $wizard = new OnboardingWizard($progress);

            foreach ($steps as $idx => $step) {
                $allPriorDone = true;
                for ($k = 0; $k < $idx; $k++) {
                    if (!($progress[$steps[$k]] ?? false)) {
                        $allPriorDone = false;
                        break;
                    }
                }
                $this->assertSame(
                    $allPriorDone,
                    $wizard->canEnterStep($step),
                    "Step '{$step}' reachability mismatch for progress " . json_encode($progress)
                );
            }
        }
    }

    public function testFirstStepAlwaysReachable(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $wizard = new OnboardingWizard($this->randomProgress());
            $this->assertTrue($wizard->canEnterStep('shop'));
        }
    }

    public function testUnknownStepNeverReachable(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $wizard = new OnboardingWizard($this->randomProgress());
            $randomKey = 'bogus_step_' . random_int(1, 1000000);
            $this->assertFalse($wizard->canEnterStep($randomKey));
            $this->assertFalse($wizard->isValidStep($randomKey));
        }
    }

    // -------------------------------------------------------------------
    // Property 2: validate() flags exactly the missing required fields
    // -------------------------------------------------------------------
    public function testValidateFlagsOnlyMissingRequiredFields(): void
    {
        $requiredByStep = [
            'shop' => ['shop_name'],
            'line' => ['channel_id', 'channel_secret', 'channel_access_token'],
            'ai'   => ['gemini_api_key'],
            'done' => [],
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $wizard = new OnboardingWizard();
            $step   = array_rand($requiredByStep);
            $fields = $requiredByStep[$step];

            // Randomly populate a subset of the required fields with non-blank values.
            $data = [];
            $expectedMissing = [];
            foreach ($fields as $field) {
                $populate = random_int(0, 1) === 1;
                if ($populate) {
                    $data[$field] = 'value_' . random_int(1, 999);
                } else {
                    $expectedMissing[] = $field;
                }
            }
            // Extra unrelated noise field must never affect the result.
            $data['unrelated_noise_field'] = 'x';

            $missing = $wizard->validate($step, $data);
            sort($missing);
            sort($expectedMissing);
            $this->assertSame(
                $expectedMissing,
                $missing,
                "validate() mismatch for step '{$step}' with data " . json_encode($data)
            );
        }
    }

    public function testValidateTreatsWhitespaceOnlyAsMissing(): void
    {
        $wizard = new OnboardingWizard();
        $whitespaceSamples = ["", " ", "\t", "\n", "   \n\t "];
        foreach ($whitespaceSamples as $ws) {
            $missing = $wizard->validate('shop', ['shop_name' => $ws]);
            $this->assertContains('shop_name', $missing, "Whitespace-only value '{$ws}' must count as missing");
        }
    }

    public function testDoneStepHasNoRequiredFields(): void
    {
        $wizard = new OnboardingWizard();
        $this->assertSame([], $wizard->requiredFields('done'));
        $this->assertSame([], $wizard->validate('done', []));
    }

    // -------------------------------------------------------------------
    // Property 3: markCompleted / markSkipped monotonically advance progress
    // -------------------------------------------------------------------
    public function testMarkCompletedIsIdempotentAndMonotonic(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $wizard = new OnboardingWizard($this->randomProgress());
            $step = $wizard->steps()[array_rand($wizard->steps())];

            $before = $wizard->completionPercent();
            $after1 = $wizard->markCompleted($step);
            $after2 = $after1->markCompleted($step); // idempotent re-application

            $this->assertTrue($after1->isStepCompleted($step));
            $this->assertTrue($after2->isStepCompleted($step));
            $this->assertSame($after1->toArray(), $after2->toArray(), 'Re-marking the same step must be a no-op');
            $this->assertGreaterThanOrEqual($before, $after1->completionPercent());
        }
    }

    public function testMarkCompletedRejectsUnknownStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OnboardingWizard())->markCompleted('not_a_real_step');
    }

    public function testOnlySkippableStepsCanBeSkipped(): void
    {
        $wizard = new OnboardingWizard();
        foreach ($wizard->steps() as $step) {
            if ($wizard->isSkippable($step)) {
                $skipped = $wizard->markSkipped($step);
                $this->assertTrue($skipped->isStepCompleted($step));
                $this->assertTrue($skipped->wasSkipped($step));
            } else {
                try {
                    $wizard->markSkipped($step);
                    $this->fail("Step '{$step}' should not be skippable");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString($step, $e->getMessage());
                }
            }
        }
    }

    // -------------------------------------------------------------------
    // Property 4: currentStep() / isFinished() / completionPercent() agree
    // -------------------------------------------------------------------
    public function testCurrentStepIsFirstIncompleteInOrder(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $progress = $this->randomProgress();
            $wizard = new OnboardingWizard($progress);
            $steps = $wizard->steps();

            $expected = null;
            foreach ($steps as $step) {
                if (empty($progress[$step])) {
                    $expected = $step;
                    break;
                }
            }
            $this->assertSame($expected, $wizard->currentStep());
        }
    }

    public function testIsFinishedOnlyWhenEveryStepCompleted(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $progress = $this->randomProgress();
            $wizard = new OnboardingWizard($progress);
            $allDone = true;
            foreach ($wizard->steps() as $step) {
                if (empty($progress[$step])) {
                    $allDone = false;
                    break;
                }
            }
            $this->assertSame($allDone, $wizard->isFinished());
            $this->assertSame($allDone, $wizard->currentStep() === null);
        }
    }

    public function testCompletionPercentBoundedAndMonotonicWithMoreCompletedSteps(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $progress = $this->randomProgress();
            $wizard = new OnboardingWizard($progress);
            $pct = $wizard->completionPercent();
            $this->assertGreaterThanOrEqual(0, $pct);
            $this->assertLessThanOrEqual(100, $pct);

            // Completing one more step (if any incomplete) must not decrease %.
            $incomplete = array_values(array_filter($wizard->steps(), fn ($s) => !$wizard->isStepCompleted($s)));
            if (!empty($incomplete)) {
                $stepToComplete = $incomplete[array_rand($incomplete)];
                $advanced = $wizard->markCompleted($stepToComplete);
                $this->assertGreaterThanOrEqual($pct, $advanced->completionPercent());
            }
        }
    }

    // -------------------------------------------------------------------
    // Property 5: JSON round-trip preserves the progress map
    // -------------------------------------------------------------------
    public function testJsonRoundTripPreservesProgress(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $progress = $this->randomProgress();
            $wizard = new OnboardingWizard($progress);
            $restored = OnboardingWizard::fromJson($wizard->toJson());
            $this->assertSame($wizard->toArray(), $restored->toArray());
        }
    }

    public function testFromJsonToleratesNullAndGarbage(): void
    {
        $this->assertSame([], OnboardingWizard::fromJson(null)->toArray());
        $this->assertSame([], OnboardingWizard::fromJson('')->toArray());
        $this->assertSame([], OnboardingWizard::fromJson('{not valid json')->toArray());
        $this->assertSame([], OnboardingWizard::fromJson('"just a string"')->toArray());
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------
    /** @return array<string,bool> */
    private function randomProgress(): array
    {
        $steps = ['shop', 'line', 'ai', 'done'];
        $progress = [];
        foreach ($steps as $step) {
            if (random_int(0, 1) === 1) {
                $progress[$step] = true;
            }
        }
        return $progress;
    }
}
