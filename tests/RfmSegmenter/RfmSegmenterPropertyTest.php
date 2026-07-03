<?php
/**
 * Property-Based Tests: RFM Customer Segmenter
 *
 * **Feature: analytics/rfm-segmentation, Phase 2 Task 2.4**
 *
 * Exercises the pure scoring core of RfmSegmenter (no DB) with randomized
 * customer populations, 100+ trials per property:
 *
 *  1. Range      — every r/f/m score is an int in [1,5].
 *  2. Top-segment — a dominant customer (most recent, highest freq, highest
 *                   spend) always scores 5/5/5 and is labelled "Champions".
 *  3. Monotonicity — more recent -> R not lower; higher freq -> F not lower;
 *                    higher spend -> M not lower.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/RfmSegmenter.php';

class RfmSegmenterPropertyTest extends TestCase
{
    private const TRIALS = 150;

    private function segmenter(): RfmSegmenter
    {
        // Pure methods only — no DB needed, pass null.
        return new RfmSegmenter(null, null);
    }

    /**
     * Build a random population of customer aggregates.
     *
     * @return array<int, array{recency_days:int, order_count:int, total_spend:float}>
     */
    private function randomPopulation(int $size): array
    {
        $pop = [];
        for ($i = 1; $i <= $size; $i++) {
            $pop[$i] = [
                'recency_days' => random_int(0, 720),
                'order_count'  => random_int(1, 60),
                'total_spend'  => (float) random_int(0, 500000) / 100.0,
            ];
        }
        return $pop;
    }

    /**
     * Property 1: all r/f/m scores are integers in [1,5].
     */
    public function testAllScoresAreWithinOneToFive(): void
    {
        $seg = $this->segmenter();

        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $size = random_int(1, 200);
            $scored = $seg->scoreCustomers($this->randomPopulation($size));

            $this->assertCount($size, $scored);

            foreach ($scored as $id => $row) {
                foreach (['r_score', 'f_score', 'm_score'] as $key) {
                    $this->assertIsInt($row[$key], "trial {$trial} id {$id} {$key} not int");
                    $this->assertGreaterThanOrEqual(1, $row[$key], "trial {$trial} id {$id} {$key} < 1");
                    $this->assertLessThanOrEqual(5, $row[$key], "trial {$trial} id {$id} {$key} > 5");
                }
                // rfm_score is the concatenation of the three digits.
                $this->assertSame(
                    (string) $row['r_score'] . $row['f_score'] . $row['m_score'],
                    $row['rfm_score']
                );
            }
        }
    }

    /**
     * Property 2: a dominant customer (min recency, max frequency, max spend)
     * always scores 5/5/5 and is labelled Champions.
     */
    public function testDominantCustomerIsChampion(): void
    {
        $seg = $this->segmenter();

        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $size = random_int(1, 200);
            $pop = $this->randomPopulation($size);

            // Find the current extremes so the injected customer strictly dominates.
            $maxFreq = 0;
            $maxSpend = 0.0;
            foreach ($pop as $agg) {
                $maxFreq = max($maxFreq, $agg['order_count']);
                $maxSpend = max($maxSpend, $agg['total_spend']);
            }

            $dominantId = 999999;
            $pop[$dominantId] = [
                'recency_days' => 0,                    // most recent possible
                'order_count'  => $maxFreq + random_int(1, 50),
                'total_spend'  => $maxSpend + (float) random_int(1, 100000),
            ];

            $scored = $seg->scoreCustomers($pop);
            $row = $scored[$dominantId];

            $this->assertSame(5, $row['r_score'], "trial {$trial} dominant r_score");
            $this->assertSame(5, $row['f_score'], "trial {$trial} dominant f_score");
            $this->assertSame(5, $row['m_score'], "trial {$trial} dominant m_score");
            $this->assertSame(
                RfmSegmenter::SEGMENT_CHAMPIONS,
                $row['segment'],
                "trial {$trial} dominant segment"
            );
        }
    }

    /**
     * Property 3: monotonicity of each dimension.
     *
     * Uses clearly-separated bands so no ties occur, letting us assert the
     * ordering directly. For each dimension we build a population where every
     * customer has a strictly distinct value on that dimension (others fixed),
     * then check the score ordering matches the value ordering.
     */
    public function testMonotonicityPerDimension(): void
    {
        $seg = $this->segmenter();

        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $size = random_int(2, 60);

            // --- Recency: distinct recency values, freq/spend fixed. ---
            $recencyValues = $this->distinctValues($size, 0, 900);
            $pop = [];
            foreach ($recencyValues as $i => $v) {
                $pop[$i] = ['recency_days' => $v, 'order_count' => 5, 'total_spend' => 100.0];
            }
            $scored = $seg->scoreCustomers($pop);
            $this->assertMonotone(
                $scored,
                'recency_days',
                'r_score',
                false, // lower recency -> higher score
                "trial {$trial} recency"
            );

            // --- Frequency: distinct order_count values, others fixed. ---
            $freqValues = $this->distinctValues($size, 1, 300);
            $pop = [];
            foreach ($freqValues as $i => $v) {
                $pop[$i] = ['recency_days' => 30, 'order_count' => $v, 'total_spend' => 100.0];
            }
            $scored = $seg->scoreCustomers($pop);
            $this->assertMonotone(
                $scored,
                'order_count',
                'f_score',
                true, // higher freq -> higher score
                "trial {$trial} frequency"
            );

            // --- Monetary: distinct total_spend values, others fixed. ---
            $spendValues = $this->distinctValues($size, 1, 900);
            $pop = [];
            foreach ($spendValues as $i => $v) {
                $pop[$i] = ['recency_days' => 30, 'order_count' => 5, 'total_spend' => (float) $v * 3.5];
            }
            $scored = $seg->scoreCustomers($pop);
            $this->assertMonotone(
                $scored,
                'total_spend',
                'm_score',
                true, // higher spend -> higher score
                "trial {$trial} monetary"
            );
        }
    }

    /**
     * Produce $size distinct integer values within [$min,$max], returned as a
     * map keyed 1..$size in random insertion order.
     *
     * @return array<int, int>
     */
    private function distinctValues(int $size, int $min, int $max): array
    {
        $range = $max - $min + 1;
        // Guarantee enough headroom for distinct values.
        if ($range < $size) {
            $max = $min + $size * 2;
        }
        $chosen = [];
        while (count($chosen) < $size) {
            $chosen[random_int($min, $max)] = true;
        }
        $values = array_keys($chosen);
        shuffle($values);

        $out = [];
        $i = 1;
        foreach ($values as $v) {
            $out[$i++] = $v;
        }
        return $out;
    }

    /**
     * Assert that, sorted by $valueKey, $scoreKey is monotone.
     *
     * @param array<int|string, array<string, mixed>> $scored
     * @param bool $higherValueHigherScore
     */
    private function assertMonotone(
        array $scored,
        string $valueKey,
        string $scoreKey,
        bool $higherValueHigherScore,
        string $context
    ): void {
        $rows = array_values($scored);
        usort($rows, static function ($a, $b) use ($valueKey) {
            return $a[$valueKey] <=> $b[$valueKey];
        });

        // Ascending by value: for "higher value -> higher score", score must be
        // non-decreasing; for recency (lower value -> higher score) it must be
        // non-increasing.
        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $prev = $rows[$i - 1][$scoreKey];
            $curr = $rows[$i][$scoreKey];
            if ($higherValueHigherScore) {
                $this->assertGreaterThanOrEqual(
                    $prev,
                    $curr,
                    "{$context}: {$scoreKey} decreased as {$valueKey} increased"
                );
            } else {
                $this->assertLessThanOrEqual(
                    $prev,
                    $curr,
                    "{$context}: {$scoreKey} increased as {$valueKey} increased"
                );
            }
        }
    }
}
