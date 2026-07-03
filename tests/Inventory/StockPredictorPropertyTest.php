<?php
/**
 * Property-Based Tests: StockPredictor
 *
 * Feature: Phase 2 (Data-Driven Differentiation) — stock-runout forecasting
 * from recent sales velocity. Validates the pure prediction logic in
 * classes/StockPredictor.php with 100+ randomised cases per property, per
 * repo testing convention.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/StockPredictor.php';

class StockPredictorPropertyTest extends TestCase
{
    /**
     * Property: empty sales history → not enough data (null).
     */
    public function testEmptySalesHistoryReturnsNull(): void
    {
        $this->assertNull(StockPredictor::forecast(0, []));

        for ($i = 0; $i < 100; $i++) {
            $stock = rand(0, 1000);
            $this->assertNull(StockPredictor::forecast($stock, []));
        }
    }

    /**
     * Property: zero (or negative) stock is always "out-soon" with
     * days_to_runout == 0, regardless of velocity.
     */
    public function testZeroStockIsAlwaysOutSoon(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $stock = rand(0, 1) === 0 ? 0 : -rand(1, 50); // zero or negative
            $days = rand(1, 30);
            $dailySales = [];
            for ($j = 0; $j < $days; $j++) {
                $dailySales[] = rand(0, 20);
            }

            $result = StockPredictor::forecast($stock, $dailySales);

            $this->assertNotNull($result);
            $this->assertSame(0.0, $result['days_to_runout']);
            $this->assertSame(StockPredictor::RISK_OUT_SOON, $result['risk_level']);
        }
    }

    /**
     * Property: zero sales velocity (stock present, nothing selling) means
     * no projectable runout — days_to_runout is null and risk is "ok".
     */
    public function testZeroVelocityWithStockIsOk(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $stock = rand(1, 1000);
            $days = rand(1, 30);
            $dailySales = array_fill(0, $days, 0);

            $result = StockPredictor::forecast($stock, $dailySales);

            $this->assertNotNull($result);
            $this->assertSame(0.0, $result['daily_velocity']);
            $this->assertNull($result['days_to_runout']);
            $this->assertSame(StockPredictor::RISK_OK, $result['risk_level']);
        }
    }

    /**
     * Property: for a constant daily velocity, days_to_runout == stock / velocity
     * exactly (within rounding), and average velocity equals that constant.
     */
    public function testConstantVelocityGivesExactDaysToRunout(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $velocity = rand(1, 50);
            $days = rand(1, 30);
            $stock = rand(1, 2000);
            $dailySales = array_fill(0, $days, $velocity);

            $result = StockPredictor::forecast($stock, $dailySales);

            $this->assertNotNull($result);
            $this->assertEqualsWithDelta((float) $velocity, $result['daily_velocity'], 0.0001);
            $this->assertEqualsWithDelta($stock / $velocity, $result['days_to_runout'], 0.01);
        }
    }

    /**
     * Monotonic property: holding stock fixed, higher velocity never yields
     * MORE days-to-runout (it strictly decreases as velocity increases, when
     * both velocities are positive).
     */
    public function testHigherVelocityYieldsFewerOrEqualDays(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $stock = rand(10, 5000);
            $days = rand(1, 20);
            $lowVelocity = rand(1, 20);
            $highVelocity = $lowVelocity + rand(1, 50);

            $lowResult = StockPredictor::forecast($stock, array_fill(0, $days, $lowVelocity));
            $highResult = StockPredictor::forecast($stock, array_fill(0, $days, $highVelocity));

            $this->assertNotNull($lowResult);
            $this->assertNotNull($highResult);
            $this->assertLessThan(
                $lowResult['days_to_runout'],
                $highResult['days_to_runout'],
                "velocity {$highVelocity} > {$lowVelocity} should mean fewer days to runout"
            );
        }
    }

    /**
     * Monotonic property: holding velocity fixed (and positive), more stock
     * never yields FEWER days-to-runout (it strictly increases as stock
     * increases).
     */
    public function testMoreStockYieldsMoreOrEqualDays(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $velocity = rand(1, 30);
            $days = rand(1, 20);
            $lowStock = rand(1, 500);
            $highStock = $lowStock + rand(1, 1000);

            $lowResult = StockPredictor::forecast($lowStock, array_fill(0, $days, $velocity));
            $highResult = StockPredictor::forecast($highStock, array_fill(0, $days, $velocity));

            $this->assertNotNull($lowResult);
            $this->assertNotNull($highResult);
            $this->assertGreaterThan(
                $lowResult['days_to_runout'],
                $highResult['days_to_runout'],
                "stock {$highStock} > {$lowStock} should mean more days to runout"
            );
        }
    }

    /**
     * Property: risk_level classification boundaries — out-soon when
     * days_to_runout <= OUT_SOON_DAYS, watch when <= WATCH_DAYS, else ok.
     */
    public function testRiskLevelClassificationBoundaries(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $velocity = rand(1, 10);
            $stock = rand(1, 2000);
            $daysToRunout = round($stock / $velocity, 2);

            $result = StockPredictor::forecast($stock, [$velocity]);
            $this->assertNotNull($result);

            if ($daysToRunout <= StockPredictor::OUT_SOON_DAYS) {
                $this->assertSame(StockPredictor::RISK_OUT_SOON, $result['risk_level']);
            } elseif ($daysToRunout <= StockPredictor::WATCH_DAYS) {
                $this->assertSame(StockPredictor::RISK_WATCH, $result['risk_level']);
            } else {
                $this->assertSame(StockPredictor::RISK_OK, $result['risk_level']);
            }
        }
    }

    /**
     * Property: negative entries in the daily sales history are treated as 0
     * (don't let a data glitch produce a negative or inflated velocity).
     */
    public function testNegativeSalesEntriesAreTreatedAsZero(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $stock = rand(1, 1000);
            $positive = rand(0, 30);
            $withNegative = StockPredictor::forecast($stock, [$positive, -rand(1, 100)]);
            $withoutNegative = StockPredictor::forecast($stock, [$positive, 0]);

            $this->assertNotNull($withNegative);
            $this->assertNotNull($withoutNegative);
            $this->assertEqualsWithDelta(
                $withoutNegative['daily_velocity'],
                $withNegative['daily_velocity'],
                0.0001
            );
        }
    }

    /**
     * Property: forecastFromTotal() with a flat rate matches forecast() with
     * a single-entry daily-sales array of the same rate (both reduce to the
     * same average velocity).
     */
    public function testForecastFromTotalMatchesEquivalentDailyForecast(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $stock = rand(0, 1000);
            $lookbackDays = rand(1, 60);
            $totalUnitsSold = (float) rand(0, 500);

            $fromTotal = StockPredictor::forecastFromTotal($stock, $totalUnitsSold, $lookbackDays);
            $expectedVelocity = round($totalUnitsSold / $lookbackDays, 4);
            $fromDaily = StockPredictor::forecast($stock, [$expectedVelocity]);

            $this->assertNotNull($fromTotal);
            $this->assertNotNull($fromDaily);
            $this->assertSame($fromDaily, $fromTotal);
        }
    }

    /**
     * Property: forecastFromTotal() returns null for an invalid (< 1)
     * lookback window.
     */
    public function testForecastFromTotalRejectsInvalidLookbackWindow(): void
    {
        $this->assertNull(StockPredictor::forecastFromTotal(100, 50.0, 0));
        $this->assertNull(StockPredictor::forecastFromTotal(100, 50.0, -5));

        for ($i = 0; $i < 100; $i++) {
            $invalidDays = -rand(0, 100);
            $this->assertNull(StockPredictor::forecastFromTotal(rand(0, 500), (float) rand(0, 200), $invalidDays));
        }
    }

    /**
     * Property: days_to_runout is always non-negative when it is not null.
     */
    public function testDaysToRunoutIsNeverNegative(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $stock = rand(0, 2000);
            $days = rand(1, 20);
            $dailySales = [];
            for ($j = 0; $j < $days; $j++) {
                $dailySales[] = rand(0, 100);
            }

            $result = StockPredictor::forecast($stock, $dailySales);
            $this->assertNotNull($result);
            if ($result['days_to_runout'] !== null) {
                $this->assertGreaterThanOrEqual(0.0, $result['days_to_runout']);
            }
        }
    }
}
