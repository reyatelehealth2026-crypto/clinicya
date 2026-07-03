<?php
/**
 * AdherenceReminder — pure calculator for per-dispense medication-adherence
 * (days-supply runout) reminders.
 *
 * Phase 2 (Data-Driven Differentiation) — distinct from classes/ReorderCycle.php,
 * which predicts a customer's *next reorder visit* from their average
 * repurchase interval across many past purchases. AdherenceReminder instead
 * looks at a *single dispense* (drug + quantity + dosage/frequency) and works
 * out the day the customer's on-hand supply will actually run out, so a
 * "ใกล้ยาหมด" reminder can go out a few days BEFORE that happens.
 *
 * Days-supply source: the dispense flow (inbox-v2.php / messages.php) already
 * feeds RefillTrackingHelper::trackFromDispense() with quantity + dosage +
 * times-per-day, which writes `medication_refill_tracking.daily_dosage` and
 * `quantity_purchased` (see database/migration_2026-05-17_dispense_refill_tracking.sql).
 * This class re-derives the same days-supply math as a pure, unit-testable
 * function so cron/adherence_reminder.php doesn't have to inline it, and adds
 * an explicit lead-time "should remind now" window check.
 *
 * No DB access here — pure functions only.
 */
class AdherenceReminder
{
    /** Default lead time (days before runout) to start reminding, when the
     *  caller doesn't have a per-drug/per-tenant override. */
    public const DEFAULT_LEAD_DAYS = 3;

    /**
     * Compute days-supply and the runout date from a dispensed quantity and
     * daily dosage.
     *
     * @param float $quantity      Total units dispensed (tablets/doses/ml — whatever
     *                              unit dosesPerDay is expressed in). Must be > 0.
     * @param float $dosesPerDay   Units consumed per day (dosage per time × times/day).
     *                              Must be > 0.
     * @param string $dispensedOn  Dispense date, 'Y-m-d' (or any strtotime-parsable
     *                              string). Defaults to today.
     * @return array{days_supply: int, runout_date: string}|null
     *   Null when inputs are missing/invalid (non-numeric, <= 0, or an
     *   unparsable date) — callers should skip adherence tracking for that
     *   item rather than guess.
     */
    public static function computeRunout(
        $quantity,
        $dosesPerDay,
        string $dispensedOn = 'today'
    ): ?array {
        if (!is_numeric($quantity) || !is_numeric($dosesPerDay)) {
            return null;
        }

        $quantity = (float) $quantity;
        $dosesPerDay = (float) $dosesPerDay;

        if ($quantity <= 0 || $dosesPerDay <= 0) {
            return null;
        }

        $dispensedTs = strtotime($dispensedOn);
        if ($dispensedTs === false) {
            return null;
        }

        // Whole days of supply — round up: partial last-day coverage still
        // means the customer has medicine that day.
        $daysSupply = (int) ceil($quantity / $dosesPerDay);
        if ($daysSupply < 1) {
            $daysSupply = 1;
        }

        $runoutTs = strtotime(date('Y-m-d', $dispensedTs) . " +{$daysSupply} days");

        return [
            'days_supply' => $daysSupply,
            'runout_date' => date('Y-m-d', $runoutTs),
        ];
    }

    /**
     * Should a reminder go out today? True only during the lead-time window
     * BEFORE runout — i.e. today is on/after (runout_date - $leadDays) and
     * strictly before runout_date. Once the customer is actually out (today
     * >= runout_date) this returns false; that's a "already out" state, not
     * a "remind ahead of time" state, and callers may want a separate,
     * differently-worded message for it.
     *
     * @param array{runout_date: string} $runout Result of computeRunout().
     * @param string $today 'Y-m-d'. Defaults to today (Asia/Bangkok, per app convention).
     * @param int $leadDays How many days before runout to start reminding.
     */
    public static function shouldRemindNow(
        array $runout,
        string $today,
        int $leadDays = self::DEFAULT_LEAD_DAYS
    ): bool {
        if (empty($runout['runout_date'])) {
            return false;
        }

        $runoutTs = strtotime($runout['runout_date'] . ' 00:00:00');
        $todayTs = strtotime($today . ' 00:00:00');
        if ($runoutTs === false || $todayTs === false) {
            return false;
        }

        $daysUntilRunout = (int) round(($runoutTs - $todayTs) / 86400);

        return $daysUntilRunout >= 0 && $daysUntilRunout <= $leadDays;
    }
}
