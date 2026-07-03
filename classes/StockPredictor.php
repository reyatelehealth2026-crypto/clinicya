<?php
/**
 * StockPredictor — pure calculator for stock-runout forecasting.
 *
 * Phase 2 (Data-Driven Differentiation): given a product's current stock and
 * its recent sales history, estimate the average daily sales velocity and
 * predict how many days remain until the product runs out, plus a simple
 * risk classification (out-soon / watch / ok) a shop can act on to reorder
 * in time.
 *
 * No DB access here — pure functions only, so this is trivially unit-testable.
 * Callers (e.g. an admin inventory view) are responsible for fetching recent
 * sales quantities (from `transaction_items` joined to `transactions`) and
 * current stock (from `business_items.stock`).
 */
class StockPredictor
{
    /** Risk level: predicted to run out within the "soon" window (or already out). */
    public const RISK_OUT_SOON = 'out-soon';

    /** Risk level: selling, but runout is further out — worth watching. */
    public const RISK_WATCH = 'watch';

    /** Risk level: no meaningful runout risk (no velocity, or plenty of runway). */
    public const RISK_OK = 'ok';

    /** Days-to-runout at/under this threshold is classified "out-soon". */
    public const OUT_SOON_DAYS = 7;

    /** Days-to-runout at/under this threshold (and above OUT_SOON_DAYS) is "watch". */
    public const WATCH_DAYS = 30;

    /**
     * Forecast runout for a single product from its recent daily sales.
     *
     * @param int   $currentStock Current stock on hand (business_items.stock).
     *                            Negative values are treated as 0.
     * @param array<int|float> $dailyUnitsSold Units sold per day over the
     *                            lookback window, one entry per day (missing
     *                            days should be passed as 0, not omitted, so
     *                            the average reflects true velocity). Order
     *                            does not matter — only the values are used.
     * @return array{
     *   daily_velocity: float,
     *   days_to_runout: float|null,
     *   risk_level: string
     * }|null Null when there isn't enough data (empty $dailyUnitsSold) to
     *        compute a velocity at all.
     */
    public static function forecast(int $currentStock, array $dailyUnitsSold): ?array
    {
        if (empty($dailyUnitsSold)) {
            return null;
        }

        $stock = max(0, $currentStock);
        $velocity = self::averageVelocity($dailyUnitsSold);

        // Zero stock: already out, regardless of velocity (unless velocity is
        // also zero, in which case there's nothing selling — still flag it
        // as out-soon since there's literally no stock to sell).
        if ($stock === 0) {
            return [
                'daily_velocity' => $velocity,
                'days_to_runout' => 0.0,
                'risk_level' => self::RISK_OUT_SOON,
            ];
        }

        // No sales velocity: can't project a runout date. Stock is present
        // and not moving, so there's no imminent risk.
        if ($velocity <= 0.0) {
            return [
                'daily_velocity' => 0.0,
                'days_to_runout' => null,
                'risk_level' => self::RISK_OK,
            ];
        }

        $daysToRunout = round($stock / $velocity, 2);

        return [
            'daily_velocity' => $velocity,
            'days_to_runout' => $daysToRunout,
            'risk_level' => self::classifyRisk($daysToRunout),
        ];
    }

    /**
     * Convenience: forecast from a total-units-sold-over-N-days figure
     * instead of a per-day breakdown, for callers that only have an
     * aggregate SUM(quantity) over a date range.
     *
     * @param int $currentStock Current stock on hand.
     * @param float $totalUnitsSold Total units sold over the lookback window.
     * @param int $lookbackDays Number of days the total covers. Must be >= 1.
     * @return array{
     *   daily_velocity: float,
     *   days_to_runout: float|null,
     *   risk_level: string
     * }|null Null when $lookbackDays < 1 (insufficient/invalid window).
     */
    public static function forecastFromTotal(int $currentStock, float $totalUnitsSold, int $lookbackDays): ?array
    {
        if ($lookbackDays < 1) {
            return null;
        }

        // Reuse forecast()'s logic by expressing the total as a flat daily
        // rate rather than materialising $lookbackDays entries.
        $velocity = max(0.0, $totalUnitsSold) / $lookbackDays;
        return self::forecast($currentStock, [$velocity]);
    }

    /**
     * Classify days-to-runout into a risk level a shop can act on.
     */
    private static function classifyRisk(float $daysToRunout): string
    {
        if ($daysToRunout <= self::OUT_SOON_DAYS) {
            return self::RISK_OUT_SOON;
        }
        if ($daysToRunout <= self::WATCH_DAYS) {
            return self::RISK_WATCH;
        }
        return self::RISK_OK;
    }

    /**
     * Average daily sales velocity from a list of per-day unit counts.
     * Negative entries are treated as 0 (can't sell a negative quantity —
     * likely a data glitch, don't let it depress the average).
     *
     * @param array<int|float> $dailyUnitsSold
     */
    private static function averageVelocity(array $dailyUnitsSold): float
    {
        $count = count($dailyUnitsSold);
        if ($count === 0) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($dailyUnitsSold as $units) {
            $sum += max(0.0, (float) $units);
        }

        return round($sum / $count, 4);
    }
}
