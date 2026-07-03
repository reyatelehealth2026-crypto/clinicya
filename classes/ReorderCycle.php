<?php
/**
 * ReorderCycle — pure calculator for per-customer medication reorder timing.
 *
 * Phase 2 (Data-Driven Differentiation): instead of a fixed 90-day refill
 * assumption, this looks at a customer's actual purchase-date history and
 * predicts when they are due to reorder based on their own average
 * repurchase interval.
 *
 * No DB access here — pure functions only, so this is trivially unit-testable.
 * Callers (e.g. cron/reorder_reminder.php) are responsible for fetching
 * purchase dates and interpreting the prediction.
 */
class ReorderCycle
{
    /**
     * Minimum number of purchases required before a cycle can be computed.
     * A single purchase has no interval to measure — "not enough data".
     */
    public const MIN_PURCHASES = 2;

    /**
     * Compute the average repurchase interval (in days) and predicted next
     * due date from a list of past purchase dates.
     *
     * @param string[] $purchaseDates Purchase dates as 'Y-m-d' (or any
     *                                strtotime-parsable) strings, any order.
     * @return array{
     *   average_interval_days: float,
     *   next_due_date: string,
     *   purchase_count: int
     * }|null Null when there isn't enough data (fewer than 2 distinct
     *        purchase days) to compute an interval.
     */
    public static function predict(array $purchaseDates): ?array
    {
        // Normalise to unique calendar days, sorted ascending. Duplicate
        // same-day purchases (e.g. two line items bought together) collapse
        // to a single "visit" — they carry no interval information.
        $days = [];
        foreach ($purchaseDates as $raw) {
            if ($raw === null || $raw === '') {
                continue;
            }
            $ts = is_numeric($raw) ? (int) $raw : strtotime((string) $raw);
            if ($ts === false) {
                continue;
            }
            $days[date('Y-m-d', $ts)] = $ts;
        }

        ksort($days);
        $sortedTimestamps = array_values($days);
        $purchaseCount = count($sortedTimestamps);

        if ($purchaseCount < self::MIN_PURCHASES) {
            return null;
        }

        // Consecutive-day intervals, in days.
        $intervals = [];
        for ($i = 1; $i < $purchaseCount; $i++) {
            $intervals[] = ($sortedTimestamps[$i] - $sortedTimestamps[$i - 1]) / 86400;
        }

        $avgInterval = self::trimmedMean($intervals);
        $lastPurchaseTs = $sortedTimestamps[$purchaseCount - 1];
        $nextDueTs = $lastPurchaseTs + (int) round($avgInterval * 86400);

        return [
            'average_interval_days' => round($avgInterval, 2),
            'next_due_date' => date('Y-m-d', $nextDueTs),
            'purchase_count' => $purchaseCount,
        ];
    }

    /**
     * Mean of $intervals with simple outlier resistance: when there are
     * enough samples (5+), drop the single highest and single lowest value
     * before averaging so one unusually long gap (e.g. a customer who
     * skipped a cycle) or one unusually short gap (e.g. a same-week
     * exchange/reorder) doesn't dominate the prediction.
     *
     * @param float[] $intervals
     */
    private static function trimmedMean(array $intervals): float
    {
        $count = count($intervals);
        if ($count === 0) {
            return 0.0;
        }
        if ($count < 5) {
            return array_sum($intervals) / $count;
        }

        sort($intervals);
        $trimmed = array_slice($intervals, 1, $count - 2);
        if (empty($trimmed)) {
            return array_sum($intervals) / $count;
        }
        return array_sum($trimmed) / count($trimmed);
    }

    /**
     * Convenience: is the predicted next-due-date within $windowDays of
     * $today (inclusive), i.e. "due now"? Also true when already overdue.
     *
     * @param array{next_due_date: string} $prediction Result of predict().
     * @param string $today 'Y-m-d'. Defaults to today (Asia/Bangkok, per app convention).
     * @param int $windowDays How many days ahead of due-date still counts as "due now".
     */
    public static function isDueWithin(array $prediction, string $today, int $windowDays = 3): bool
    {
        $dueTs = strtotime($prediction['next_due_date'] . ' 00:00:00');
        $todayTs = strtotime($today . ' 00:00:00');
        if ($dueTs === false || $todayTs === false) {
            return false;
        }
        // Due now = due date has arrived (todayTs >= dueTs), and not so far
        // overdue that a reminder is stale (cap the backward window too).
        $daysUntilDue = ($dueTs - $todayTs) / 86400;
        return $daysUntilDue <= $windowDays && $daysUntilDue >= -$windowDays;
    }
}
