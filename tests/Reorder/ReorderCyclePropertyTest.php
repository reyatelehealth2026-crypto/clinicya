<?php
/**
 * Property-Based Tests: ReorderCycle
 *
 * Feature: Phase 2 (Data-Driven Differentiation) — individual reorder-cycle
 * refill reminder. Validates the pure prediction logic in classes/ReorderCycle.php
 * with 100+ randomised cases per property, per repo testing convention.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/ReorderCycle.php';

class ReorderCyclePropertyTest extends TestCase
{
    /**
     * Property: fewer than 2 distinct purchase dates → not enough data (null).
     */
    public function testFewerThanTwoPurchasesReturnsNull(): void
    {
        $this->assertNull(ReorderCycle::predict([]));
        $this->assertNull(ReorderCycle::predict(['2026-01-01']));

        // Random single-date cases.
        for ($i = 0; $i < 100; $i++) {
            $date = date('Y-m-d', strtotime('-' . rand(0, 3650) . ' days'));
            $this->assertNull(ReorderCycle::predict([$date]));
        }
    }

    /**
     * Property: same-day duplicate purchases collapse to a single day and
     * still count as "not enough data" if that's the only distinct day.
     */
    public function testSameDayDuplicatesCollapseToNotEnoughData(): void
    {
        $this->assertNull(ReorderCycle::predict(['2026-06-01', '2026-06-01', '2026-06-01']));

        for ($i = 0; $i < 100; $i++) {
            $date = date('Y-m-d', strtotime('-' . rand(0, 3650) . ' days'));
            $copies = array_fill(0, rand(2, 8), $date);
            $this->assertNull(ReorderCycle::predict($copies), "Duplicates of a single day ({$date}) should be 'not enough data'");
        }
    }

    /**
     * Property: exactly 2 distinct purchase dates → average interval equals
     * the gap between them exactly, and next_due_date = last + that gap.
     */
    public function testTwoPurchasesGiveExactInterval(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $gapDays = rand(1, 200);
            $first = rand(1000000000, 1700000000); // arbitrary unix ts anchor
            $second = $first + $gapDays * 86400;

            $dates = [date('Y-m-d', $first), date('Y-m-d', $second)];
            $prediction = ReorderCycle::predict($dates);

            $this->assertNotNull($prediction);
            $this->assertEqualsWithDelta((float) $gapDays, $prediction['average_interval_days'], 0.01);
            $this->assertSame(2, $prediction['purchase_count']);

            $expectedDue = date('Y-m-d', $second + $gapDays * 86400);
            $this->assertSame($expectedDue, $prediction['next_due_date']);
        }
    }

    /**
     * Property: predict() is order-independent — shuffling the input dates
     * yields the same prediction, since purchases are sorted internally.
     */
    public function testPredictionIsOrderIndependent(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $count = rand(2, 8);
            $dates = [];
            $ts = rand(1000000000, 1700000000);
            for ($j = 0; $j < $count; $j++) {
                $ts += rand(5, 120) * 86400;
                $dates[] = date('Y-m-d', $ts);
            }

            $sortedPrediction = ReorderCycle::predict($dates);

            $shuffled = $dates;
            shuffle($shuffled);
            $shuffledPrediction = ReorderCycle::predict($shuffled);

            $this->assertSame($sortedPrediction, $shuffledPrediction);
        }
    }

    /**
     * Property: for a perfectly regular purchase cadence (constant interval),
     * the average interval equals that constant and the next due date is
     * exactly one cycle after the last purchase.
     */
    public function testRegularCadenceYieldsExactAverage(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $intervalDays = rand(7, 90);
            $purchaseCount = rand(2, 12);

            $ts = rand(1000000000, 1600000000);
            $dates = [];
            for ($j = 0; $j < $purchaseCount; $j++) {
                $dates[] = date('Y-m-d', $ts);
                $ts += $intervalDays * 86400;
            }
            $lastPurchaseTs = $ts - $intervalDays * 86400;

            $prediction = ReorderCycle::predict($dates);

            $this->assertNotNull($prediction);
            $this->assertEqualsWithDelta((float) $intervalDays, $prediction['average_interval_days'], 0.01);

            $expectedDue = date('Y-m-d', $lastPurchaseTs + $intervalDays * 86400);
            $this->assertSame($expectedDue, $prediction['next_due_date']);
        }
    }

    /**
     * Property: a single large outlier gap (5+ samples) is down-weighted by
     * the trimmed mean — the computed average should stay closer to the
     * typical interval than a naive mean would.
     */
    public function testOutlierGapIsDampened(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $typicalInterval = rand(10, 40);
            $outlierInterval = $typicalInterval + rand(300, 1000); // one big skip

            // 5 regular intervals + 1 outlier = 6 intervals -> 7 purchase dates.
            $ts = rand(1000000000, 1500000000);
            $dates = [date('Y-m-d', $ts)];
            $intervals = array_fill(0, 5, $typicalInterval);
            $intervals[] = $outlierInterval;
            shuffle($intervals); // outlier position shouldn't matter
            foreach ($intervals as $gap) {
                $ts += $gap * 86400;
                $dates[] = date('Y-m-d', $ts);
            }

            $prediction = ReorderCycle::predict($dates);
            $this->assertNotNull($prediction);

            $naiveMean = array_sum($intervals) / count($intervals);

            // Trimmed mean must be less than or equal to the naive mean when
            // there's one high outlier, since the outlier gets dropped.
            $this->assertLessThanOrEqual(
                $naiveMean + 0.01,
                $prediction['average_interval_days'],
                'Trimmed mean should not exceed naive mean when a high outlier is present'
            );
            // And it should be meaningfully closer to the typical interval
            // than the naive (outlier-inflated) mean is.
            $distTrimmed = abs($prediction['average_interval_days'] - $typicalInterval);
            $distNaive = abs($naiveMean - $typicalInterval);
            $this->assertLessThanOrEqual($distNaive, $distTrimmed);
        }
    }

    /**
     * Property: average_interval_days is always non-negative and
     * next_due_date is always on/after the last purchase date.
     */
    public function testPredictionInvariants(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $count = rand(2, 10);
            $ts = rand(1000000000, 1600000000);
            $dates = [];
            for ($j = 0; $j < $count; $j++) {
                $dates[] = date('Y-m-d', $ts);
                $ts += rand(1, 200) * 86400;
            }
            $lastDate = $dates[count($dates) - 1];

            $prediction = ReorderCycle::predict($dates);

            $this->assertNotNull($prediction);
            $this->assertGreaterThanOrEqual(0, $prediction['average_interval_days']);
            $this->assertGreaterThanOrEqual(
                strtotime($lastDate),
                strtotime($prediction['next_due_date']),
                'next_due_date should never be before the last purchase'
            );
            $this->assertSame(count(array_unique($dates)), $prediction['purchase_count']);
        }
    }

    /**
     * Property: isDueWithin() returns true exactly when |days until due| <= window.
     */
    public function testIsDueWithinWindowBoundary(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $window = rand(1, 10);
            $offset = rand(-15, 15); // days from today to due date
            $today = '2026-06-15';
            $dueDate = date('Y-m-d', strtotime($today . " {$offset} days"));

            $prediction = ['next_due_date' => $dueDate];
            $result = ReorderCycle::isDueWithin($prediction, $today, $window);

            $expected = abs($offset) <= $window;
            $this->assertSame($expected, $result, "offset={$offset}, window={$window}");
        }
    }

    /**
     * Property: predict() ignores null/empty entries in the input array
     * without throwing, and unparsable strings are simply skipped.
     */
    public function testInvalidEntriesAreIgnoredGracefully(): void
    {
        $prediction = ReorderCycle::predict(['2026-01-01', '', null, 'not-a-date', '2026-01-11']);
        $this->assertNotNull($prediction);
        $this->assertSame(2, $prediction['purchase_count']);
        $this->assertEqualsWithDelta(10.0, $prediction['average_interval_days'], 0.01);
    }
}
