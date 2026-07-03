<?php
/**
 * RFM Customer Segmenter
 *
 * Scores a tenant's customers on Recency / Frequency / Monetary value using
 * quintile bucketing and labels each customer with a marketing segment
 * (Champions, Loyal, At-Risk, Hibernating, New, ...).
 *
 * RFM scoring is inherently RELATIVE: quintile boundaries are computed against
 * the distribution of the whole customer population being scored, not a fixed
 * absolute scale. The pure scoring core (scoreCustomers / scoreCustomer) takes
 * pre-computed per-customer aggregates and is fully unit-testable without a DB.
 * The thin DB loader (loadCustomerAggregates) is kept separate so the scoring
 * logic can be exercised in isolation.
 *
 * This class does segmentation ONLY — no campaign-sending logic.
 *
 * Phase 2, Task 2.4.
 */

class RfmSegmenter
{
    /** @var \PDO|null Database connection (only needed by the DB loader). */
    private $db;

    /** @var int|null LINE account / tenant scope. */
    private $lineAccountId;

    // Segment labels.
    const SEGMENT_CHAMPIONS       = 'Champions';
    const SEGMENT_LOYAL           = 'Loyal';
    const SEGMENT_AT_RISK         = 'At-Risk';
    const SEGMENT_HIBERNATING     = 'Hibernating';
    const SEGMENT_NEW             = 'New';
    const SEGMENT_NEEDS_ATTENTION = 'Needs Attention';

    // Order statuses that count as a real, completed purchase.
    const COUNTED_STATUSES = ['paid', 'confirmed', 'delivered'];

    /**
     * @param \PDO|null $db            Database connection (may be null when only
     *                                 the pure scoring methods are used).
     * @param int|null  $lineAccountId LINE account ID for multi-tenant scoping.
     */
    public function __construct($db = null, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }

    /**
     * Score a whole customer population and label each with a segment.
     *
     * @param array<int|string, array{recency_days:int, order_count:int, total_spend:float}> $aggregates
     *        Map of customerId => per-customer aggregate.
     *
     * @return array<int|string, array{
     *     r_score:int, f_score:int, m_score:int,
     *     rfm_score:string, segment:string,
     *     recency_days:int, order_count:int, total_spend:float
     * }>
     */
    public function scoreCustomers(array $aggregates): array
    {
        if (empty($aggregates)) {
            return [];
        }

        // Collect the distributions once so quintile boundaries are shared.
        $recencyValues = [];
        $frequencyValues = [];
        $monetaryValues = [];
        foreach ($aggregates as $id => $agg) {
            $recencyValues[$id]   = (int) ($agg['recency_days'] ?? 0);
            $frequencyValues[$id] = (int) ($agg['order_count'] ?? 0);
            $monetaryValues[$id]  = (float) ($agg['total_spend'] ?? 0.0);
        }

        // Recency: LOWER recency_days (= more recent) should get a HIGHER score,
        // so we invert (higherIsBetter = false).
        $rScores = $this->calculateQuintileScores($recencyValues, false);
        $fScores = $this->calculateQuintileScores($frequencyValues, true);
        $mScores = $this->calculateQuintileScores($monetaryValues, true);

        $result = [];
        foreach ($aggregates as $id => $agg) {
            $r = $rScores[$id];
            $f = $fScores[$id];
            $m = $mScores[$id];
            $result[$id] = [
                'r_score'      => $r,
                'f_score'      => $f,
                'm_score'      => $m,
                'rfm_score'    => (string) $r . (string) $f . (string) $m,
                'segment'      => $this->labelSegment($r, $f, $m),
                'recency_days' => (int) ($agg['recency_days'] ?? 0),
                'order_count'  => (int) ($agg['order_count'] ?? 0),
                'total_spend'  => (float) ($agg['total_spend'] ?? 0.0),
            ];
        }

        return $result;
    }

    /**
     * Convenience single-customer scorer.
     *
     * Because RFM is relative, scoring one customer in isolation cannot use a
     * population distribution — here the single customer is treated as its own
     * population, which trivially yields the top quintile (5/5/5). Prefer
     * scoreCustomers() for meaningful relative scores.
     *
     * @param array{recency_days:int, order_count:int, total_spend:float} $aggregate
     *
     * @return array{r_score:int, f_score:int, m_score:int, rfm_score:string, segment:string}
     */
    public function scoreCustomer(array $aggregate): array
    {
        $scored = $this->scoreCustomers(['_single' => $aggregate]);
        $row = $scored['_single'];

        return [
            'r_score'   => $row['r_score'],
            'f_score'   => $row['f_score'],
            'm_score'   => $row['m_score'],
            'rfm_score' => $row['rfm_score'],
            'segment'   => $row['segment'],
        ];
    }

    /**
     * Bucket a set of values into quintile scores 1..5.
     *
     * Values are sorted ascending and their rank is mapped onto the 1..5 scale.
     * The mapping is endpoint-anchored: the lowest-ranked value always gets the
     * bottom of the scale and the highest-ranked value always gets the top, so a
     * strict maximum reliably scores 5 (and a strict minimum 1) even for small
     * populations. When $higherIsBetter is true the largest values get score 5;
     * when false (recency) the smallest values get score 5.
     *
     * Ties: equal values always receive the SAME score (every occurrence takes
     * the score assigned to the first rank at which that value appears), so
     * monotonicity holds. A single-customer population trivially yields 5.
     *
     * @param array<int|string, int|float> $values map of id => value
     * @param bool $higherIsBetter true = higher value gets higher score
     *
     * @return array<int|string, int> map of id => quintile score (1..5)
     */
    private function calculateQuintileScores(array $values, bool $higherIsBetter): array
    {
        $count = count($values);
        if ($count === 0) {
            return [];
        }

        // Sort the values ascending, keeping ids. Ascending rank position maps
        // to a quintile 1..5; for recency we flip afterwards.
        $pairs = [];
        foreach ($values as $id => $value) {
            $pairs[] = ['id' => $id, 'value' => $value];
        }
        usort($pairs, static function ($a, $b) {
            return $a['value'] <=> $b['value'];
        });

        // Assign an ascending quintile (1..5) to each rank position, anchoring
        // the endpoints: rank 0 -> 1, last rank -> 5, evenly spaced between. A
        // single-value population ($count === 1) maps to the top score (5).
        $ascScoreByRank = [];
        for ($rank = 0; $rank < $count; $rank++) {
            if ($count === 1) {
                $ascScoreByRank[$rank] = 5;
                continue;
            }
            $bucket = (int) round(($rank / ($count - 1)) * 4); // 0..4
            $ascScoreByRank[$rank] = $bucket + 1; // 1..5
        }

        // Resolve ties: every occurrence of a value takes the ascending score
        // assigned to the FIRST rank at which that value appears.
        $scoreByValue = [];
        for ($rank = 0; $rank < $count; $rank++) {
            $key = (string) $pairs[$rank]['value'];
            if (!array_key_exists($key, $scoreByValue)) {
                $scoreByValue[$key] = $ascScoreByRank[$rank];
            }
        }

        $result = [];
        foreach ($pairs as $pair) {
            $ascScore = $scoreByValue[(string) $pair['value']];
            // Flip for "lower is better" (recency): 1<->5, 2<->4, 3<->3.
            $result[$pair['id']] = $higherIsBetter ? $ascScore : (6 - $ascScore);
        }

        return $result;
    }

    /**
     * Map an (R, F, M) score triple to a marketing segment label.
     *
     * Rule table (evaluated top-to-bottom; first match wins). Scores are 1..5.
     *
     *   Champions       R>=4 AND F>=4 AND M>=4  — recent, frequent, high value.
     *   Loyal           F>=4 AND R>=3           — buy often, still reasonably recent.
     *   At-Risk         R<=2 AND F>=3 AND M>=3  — used to be good, gone quiet.
     *   New             R>=4 AND F<=2           — recent first-timers / few orders.
     *   Hibernating     R<=2 AND F<=2 AND M<=2  — stale, infrequent, low value.
     *   Needs Attention (fallback)              — everything else.
     *
     * @return string one of the SEGMENT_* constants
     */
    private function labelSegment(int $r, int $f, int $m): string
    {
        if ($r >= 4 && $f >= 4 && $m >= 4) {
            return self::SEGMENT_CHAMPIONS;
        }
        if ($f >= 4 && $r >= 3) {
            return self::SEGMENT_LOYAL;
        }
        if ($r <= 2 && $f >= 3 && $m >= 3) {
            return self::SEGMENT_AT_RISK;
        }
        if ($r >= 4 && $f <= 2) {
            return self::SEGMENT_NEW;
        }
        if ($r <= 2 && $f <= 2 && $m <= 2) {
            return self::SEGMENT_HIBERNATING;
        }

        return self::SEGMENT_NEEDS_ATTENTION;
    }

    /**
     * Load per-customer purchase aggregates from the transactions table.
     *
     * Runs ONE grouped query scoped to the given LINE account, counting only
     * completed purchases (COUNTED_STATUSES). Recency is measured in whole days
     * from the most recent order to $asOfDate (default: now).
     *
     * @param int         $lineAccountId LINE account / tenant scope.
     * @param string|null $asOfDate      Reference date (Y-m-d[ H:i:s]); null = NOW().
     *
     * @return array<int, array{recency_days:int, order_count:int, total_spend:float}>
     *         Map of user_id => aggregate, in the shape scoreCustomers() expects.
     */
    public function loadCustomerAggregates(int $lineAccountId, ?string $asOfDate = null): array
    {
        if ($this->db === null) {
            throw new \RuntimeException('RfmSegmenter::loadCustomerAggregates requires a database connection.');
        }

        $placeholders = implode(',', array_fill(0, count(self::COUNTED_STATUSES), '?'));
        $reference = $asOfDate !== null ? '?' : 'NOW()';

        $sql = "SELECT
                    user_id,
                    DATEDIFF({$reference}, MAX(created_at)) AS recency_days,
                    COUNT(*)                                AS order_count,
                    SUM(grand_total)                        AS total_spend
                FROM transactions
                WHERE line_account_id = ?
                  AND status IN ({$placeholders})
                GROUP BY user_id";

        $params = [];
        if ($asOfDate !== null) {
            $params[] = $asOfDate;
        }
        $params[] = $lineAccountId;
        foreach (self::COUNTED_STATUSES as $status) {
            $params[] = $status;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $aggregates = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $aggregates[(int) $row['user_id']] = [
                'recency_days' => (int) $row['recency_days'],
                'order_count'  => (int) $row['order_count'],
                'total_spend'  => (float) $row['total_spend'],
            ];
        }

        return $aggregates;
    }

    /**
     * Orchestration helper: load a tenant's customer aggregates and score them.
     *
     * Not unit-tested directly (needs a DB); it simply wires loadCustomerAggregates()
     * into scoreCustomers().
     *
     * @param int         $lineAccountId
     * @param string|null $asOfDate
     *
     * @return array<int, array<string, mixed>> Map of user_id => scored+segmented row.
     */
    public function segmentTenantCustomers(int $lineAccountId, ?string $asOfDate = null): array
    {
        $aggregates = $this->loadCustomerAggregates($lineAccountId, $asOfDate);

        return $this->scoreCustomers($aggregates);
    }
}
