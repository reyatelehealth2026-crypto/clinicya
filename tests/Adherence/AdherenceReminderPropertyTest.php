<?php
/**
 * Property-Based Tests: AdherenceReminder
 *
 * Feature: Phase 2 (Data-Driven Differentiation) — medication-adherence
 * (days-supply runout) reminder. Validates the pure prediction logic in
 * classes/AdherenceReminder.php with 100+ randomised cases per property, per
 * repo testing convention. DB-free.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/AdherenceReminder.php';

class AdherenceReminderPropertyTest extends TestCase
{
    /**
     * Property: non-numeric, zero, or negative quantity/dosesPerDay always
     * returns null — no runout can be computed without valid supply data.
     */
    public function testInvalidQuantityOrDosageReturnsNull(): void
    {
        $this->assertNull(AdherenceReminder::computeRunout(null, 2, '2026-01-01'));
        $this->assertNull(AdherenceReminder::computeRunout(30, null, '2026-01-01'));
        $this->assertNull(AdherenceReminder::computeRunout('not-a-number', 2, '2026-01-01'));
        $this->assertNull(AdherenceReminder::computeRunout(30, 'nope', '2026-01-01'));
        $this->assertNull(AdherenceReminder::computeRunout(0, 2, '2026-01-01'));
        $this->assertNull(AdherenceReminder::computeRunout(30, 0, '2026-01-01'));
        $this->assertNull(AdherenceReminder::computeRunout(-5, 2, '2026-01-01'));
        $this->assertNull(AdherenceReminder::computeRunout(30, -1, '2026-01-01'));

        for ($i = 0; $i < 100; $i++) {
            $badQty = [0, -1 * rand(1, 100), null, 'abc'][array_rand([0, 1, 2, 3])];
            $this->assertNull(AdherenceReminder::computeRunout($badQty, rand(1, 5), '2026-01-01'));
        }
    }

    /**
     * Property: an unparsable dispensed-on date returns null.
     */
    public function testInvalidDispenseDateReturnsNull(): void
    {
        $this->assertNull(AdherenceReminder::computeRunout(30, 2, 'not-a-date'));
        $this->assertNull(AdherenceReminder::computeRunout(30, 2, ''));
    }

    /**
     * Property: days_supply = ceil(quantity / dosesPerDay), always >= 1.
     */
    public function testDaysSupplyIsCeilingOfQuantityOverDosage(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $quantity = rand(1, 500);
            $dosesPerDay = rand(1, 10);
            $dispensedOn = date('Y-m-d', rand(1000000000, 1700000000));

            $runout = AdherenceReminder::computeRunout($quantity, $dosesPerDay, $dispensedOn);

            $this->assertNotNull($runout);
            $expectedDaysSupply = max(1, (int) ceil($quantity / $dosesPerDay));
            $this->assertSame($expectedDaysSupply, $runout['days_supply']);
        }
    }

    /**
     * Property: runout_date = dispensedOn + days_supply days, exactly.
     */
    public function testRunoutDateIsDispenseDatePlusDaysSupply(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $quantity = rand(1, 300);
            $dosesPerDay = rand(1, 8);
            $dispensedTs = rand(1000000000, 1700000000);
            $dispensedOn = date('Y-m-d', $dispensedTs);

            $runout = AdherenceReminder::computeRunout($quantity, $dosesPerDay, $dispensedOn);
            $this->assertNotNull($runout);

            $expectedRunout = date('Y-m-d', strtotime($dispensedOn . " +{$runout['days_supply']} days"));
            $this->assertSame($expectedRunout, $runout['runout_date']);

            // Invariant: runout is never before the dispense date.
            $this->assertGreaterThanOrEqual(strtotime($dispensedOn), strtotime($runout['runout_date']));
        }
    }

    /**
     * Property: exact division (quantity is a multiple of dosesPerDay) gives
     * days_supply = quantity / dosesPerDay with no rounding up.
     */
    public function testExactDivisionHasNoRoundingUp(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $dosesPerDay = rand(1, 6);
            $daysSupply = rand(1, 60);
            $quantity = $dosesPerDay * $daysSupply; // exact multiple

            $runout = AdherenceReminder::computeRunout($quantity, $dosesPerDay, '2026-01-01');
            $this->assertNotNull($runout);
            $this->assertSame($daysSupply, $runout['days_supply']);
        }
    }

    /**
     * Property: shouldRemindNow() is true exactly when 0 <= (runout - today) <= leadDays.
     */
    public function testShouldRemindNowWindowBoundary(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $leadDays = rand(1, 10);
            $offset = rand(-15, 15); // days from today to runout
            $today = '2026-06-15';
            $runoutDate = date('Y-m-d', strtotime($today . " {$offset} days"));

            $runout = ['runout_date' => $runoutDate];
            $result = AdherenceReminder::shouldRemindNow($runout, $today, $leadDays);

            $expected = ($offset >= 0 && $offset <= $leadDays);
            $this->assertSame($expected, $result, "offset={$offset}, leadDays={$leadDays}");
        }
    }

    /**
     * Property: once the customer is already out (today >= runout_date and
     * offset < 0, i.e. runout was in the past), shouldRemindNow() is false —
     * this is an "already out" state, not a lead-time reminder state.
     */
    public function testAlreadyPastRunoutIsNotALeadTimeReminder(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $leadDays = rand(1, 10);
            $daysAgo = rand(1, 30);
            $today = '2026-06-15';
            $runoutDate = date('Y-m-d', strtotime($today . " -{$daysAgo} days"));

            $runout = ['runout_date' => $runoutDate];
            $this->assertFalse(AdherenceReminder::shouldRemindNow($runout, $today, $leadDays));
        }
    }

    /**
     * Property: missing/empty runout_date never remind.
     */
    public function testMissingRunoutDateNeverReminds(): void
    {
        $this->assertFalse(AdherenceReminder::shouldRemindNow([], '2026-06-15'));
        $this->assertFalse(AdherenceReminder::shouldRemindNow(['runout_date' => ''], '2026-06-15'));
    }

    /**
     * Property: default lead time is DEFAULT_LEAD_DAYS (3) when not specified.
     */
    public function testDefaultLeadDaysIsThree(): void
    {
        $this->assertSame(3, AdherenceReminder::DEFAULT_LEAD_DAYS);

        $today = '2026-06-15';
        $runout = ['runout_date' => date('Y-m-d', strtotime($today . ' +3 days'))];
        $this->assertTrue(AdherenceReminder::shouldRemindNow($runout, $today));

        $tooFar = ['runout_date' => date('Y-m-d', strtotime($today . ' +4 days'))];
        $this->assertFalse(AdherenceReminder::shouldRemindNow($tooFar, $today));
    }
}
