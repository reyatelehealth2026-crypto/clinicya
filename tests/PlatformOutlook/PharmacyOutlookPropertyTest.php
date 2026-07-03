<?php
/**
 * Property-Based Test: PharmacyOutlook aggregation + PDPA anonymization
 *
 * **Feature: pharmacy-outlook, Property 1: Merge correctness**
 * Property: Merging any set of per-tenant aggregates SHALL produce totals
 * equal to the sum of each tenant's individual contribution, and merging
 * SHALL be associative/order-independent (splitting the same tenants into
 * different groupings and merging the merges yields the same result).
 *
 * **Feature: pharmacy-outlook, Property 2: Min-cohort suppression**
 * Property: For any merged aggregate and any threshold N, every bucket kept
 * in the output SHALL have been contributed to by >= N distinct tenants,
 * and every bucket with < N contributing tenants SHALL be absent from the
 * output counts (moved to suppressed_buckets instead).
 *
 * Pure/DB-free: exercises only PharmacyOutlook::mergeAggregates() and
 * PharmacyOutlook::applyMinCohortSuppression(), both static and PDO-free.
 */

namespace Tests\PlatformOutlook;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/PharmacyOutlook.php';

class PharmacyOutlookPropertyTest extends TestCase
{
    private const DRUG_CATEGORIES = ['otc', 'dangerous', 'controlled', 'unclassified'];

    /**
     * Generates 100 random sets of per-tenant aggregates for property testing.
     *
     * @return array<string, array{0: array<int, array>}>
     */
    public function perTenantAggregateSetProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 100; $i++) {
            $cases["case_{$i}"] = [$this->generateRandomAggregateSet()];
        }
        return $cases;
    }

    private function generateRandomAggregateSet(): array
    {
        $tenantCount = random_int(1, 40);
        $aggregates = [];
        for ($t = 0; $t < $tenantCount; $t++) {
            $categories = [];
            // Random subset of categories present for this tenant.
            foreach (self::DRUG_CATEGORIES as $cat) {
                if (random_int(0, 100) < 60) {
                    $categories[$cat] = random_int(1, 500);
                }
            }
            $aggregates[] = [
                'tenant_count'   => 1,
                'order_count'    => random_int(0, 10000),
                'revenue_total'  => round(random_int(0, 5000000) / 100, 2),
                'drug_category_counts' => $categories,
            ];
        }
        return $aggregates;
    }

    // -------------------------------------------------------------------
    // Property 1: Merge correctness (sum equals total, order-independent)
    // -------------------------------------------------------------------

    /**
     * @dataProvider perTenantAggregateSetProvider
     */
    public function testMergeSumsMatchManualTotals(array $perTenantAggregates): void
    {
        $merged = \PharmacyOutlook::mergeAggregates($perTenantAggregates);

        $expectedOrderCount = array_sum(array_column($perTenantAggregates, 'order_count'));
        $expectedRevenue = round(array_sum(array_column($perTenantAggregates, 'revenue_total')), 2);
        $expectedTenantCount = array_sum(array_column($perTenantAggregates, 'tenant_count'));

        $this->assertSame($expectedOrderCount, $merged['order_count']);
        $this->assertEqualsWithDelta($expectedRevenue, $merged['revenue_total'], 0.01);
        $this->assertSame($expectedTenantCount, $merged['tenant_count']);

        // Every category bucket total must equal the sum across all tenants
        // that reported it.
        $expectedBucketTotals = [];
        $expectedBucketTenantCounts = [];
        foreach ($perTenantAggregates as $agg) {
            foreach ($agg['drug_category_counts'] as $cat => $n) {
                if ($n <= 0) {
                    continue;
                }
                $expectedBucketTotals[$cat] = ($expectedBucketTotals[$cat] ?? 0) + $n;
                $expectedBucketTenantCounts[$cat] = ($expectedBucketTenantCounts[$cat] ?? 0) + 1;
            }
        }

        $this->assertEquals($expectedBucketTotals, $merged['drug_category_counts']);
        $this->assertEquals($expectedBucketTenantCounts, $merged['drug_category_tenants']);
    }

    /**
     * @dataProvider perTenantAggregateSetProvider
     */
    public function testMergeIsOrderAndGroupingIndependent(array $perTenantAggregates): void
    {
        // Merge all at once.
        $mergedAll = \PharmacyOutlook::mergeAggregates($perTenantAggregates);

        // Merge in reverse order — must be identical.
        $mergedReversed = \PharmacyOutlook::mergeAggregates(array_reverse($perTenantAggregates));
        $this->assertEquals($mergedAll, $mergedReversed);

        // Split into two random groups, merge each group, then merge the
        // two partial merges together — must equal merging everything at
        // once (associativity).
        $shuffled = $perTenantAggregates;
        // Deterministic-but-varied split point derived from the data itself
        // (no reliance on external randomness state between calls).
        $splitAt = count($shuffled) > 1 ? intdiv(count($shuffled), 2) : 0;
        $groupA = array_slice($shuffled, 0, $splitAt);
        $groupB = array_slice($shuffled, $splitAt);

        $partialA = \PharmacyOutlook::mergeAggregates($groupA);
        $partialB = \PharmacyOutlook::mergeAggregates($groupB);
        $mergedFromPartials = \PharmacyOutlook::mergeAggregates([$partialA, $partialB]);

        $this->assertSame($mergedAll['order_count'], $mergedFromPartials['order_count']);
        $this->assertEqualsWithDelta($mergedAll['revenue_total'], $mergedFromPartials['revenue_total'], 0.01);
        $this->assertSame($mergedAll['tenant_count'], $mergedFromPartials['tenant_count']);
        $this->assertEquals($mergedAll['drug_category_counts'], $mergedFromPartials['drug_category_counts']);
        $this->assertEquals($mergedAll['drug_category_tenants'], $mergedFromPartials['drug_category_tenants']);
    }

    public function testMergeOfEmptySetIsAllZero(): void
    {
        $merged = \PharmacyOutlook::mergeAggregates([]);

        $this->assertSame(0, $merged['tenant_count']);
        $this->assertSame(0, $merged['order_count']);
        $this->assertEqualsWithDelta(0.0, $merged['revenue_total'], 0.01);
        $this->assertSame([], $merged['drug_category_counts']);
        $this->assertSame([], $merged['drug_category_tenants']);
    }

    // -------------------------------------------------------------------
    // Property 2: Min-cohort suppression
    // -------------------------------------------------------------------

    /**
     * @dataProvider perTenantAggregateSetProvider
     */
    public function testSuppressionDropsOnlyBucketsBelowThreshold(array $perTenantAggregates): void
    {
        $merged = \PharmacyOutlook::mergeAggregates($perTenantAggregates);
        $threshold = random_int(2, 10);

        $result = \PharmacyOutlook::applyMinCohortSuppression($merged, $threshold);

        // Every bucket that survived must have >= threshold contributing tenants.
        foreach ($result['drug_category_tenants'] as $bucket => $tenantCount) {
            $this->assertGreaterThanOrEqual(
                $threshold,
                $tenantCount,
                "Bucket '{$bucket}' survived suppression with only {$tenantCount} tenants (threshold {$threshold})"
            );
        }

        // Every bucket that was suppressed must actually have had < threshold tenants.
        foreach ($result['suppressed_buckets'] as $bucket) {
            $originalTenantCount = $merged['drug_category_tenants'][$bucket] ?? 0;
            $this->assertLessThan(
                $threshold,
                $originalTenantCount,
                "Bucket '{$bucket}' was suppressed despite having {$originalTenantCount} tenants (threshold {$threshold})"
            );
        }

        // No bucket is silently dropped without being accounted for.
        $survivedKeys = array_keys($result['drug_category_counts']);
        $suppressedKeys = $result['suppressed_buckets'];
        $originalKeys = array_keys($merged['drug_category_counts']);
        sort($survivedKeys);
        sort($suppressedKeys);
        $reconstructed = array_merge($survivedKeys, $suppressedKeys);
        sort($reconstructed);
        $sortedOriginal = $originalKeys;
        sort($sortedOriginal);
        $this->assertSame($sortedOriginal, $reconstructed);

        // Counts and tenant-count maps stay in lockstep — no orphan keys.
        $this->assertSame(
            array_keys($result['drug_category_counts']),
            array_keys($result['drug_category_tenants'])
        );
    }

    public function testMinCohortOfOneSuppressesNothing(): void
    {
        $merged = \PharmacyOutlook::mergeAggregates([
            ['tenant_count' => 1, 'order_count' => 5, 'revenue_total' => 100.0,
             'drug_category_counts' => ['otc' => 3]],
        ]);

        $result = \PharmacyOutlook::applyMinCohortSuppression($merged, 1);

        $this->assertSame(['otc' => 3], $result['drug_category_counts']);
        $this->assertSame([], $result['suppressed_buckets']);
    }

    public function testSmallCohortIsSuppressedByDefaultThreshold(): void
    {
        // 4 tenants report 'controlled' — below PharmacyOutlook::MIN_COHORT (5).
        $perTenantAggregates = [];
        for ($i = 0; $i < 4; $i++) {
            $perTenantAggregates[] = [
                'tenant_count' => 1, 'order_count' => 10, 'revenue_total' => 500.0,
                'drug_category_counts' => ['controlled' => 2],
            ];
        }
        // 5 tenants report 'otc' — meets the threshold.
        for ($i = 0; $i < 5; $i++) {
            $perTenantAggregates[] = [
                'tenant_count' => 1, 'order_count' => 10, 'revenue_total' => 500.0,
                'drug_category_counts' => ['otc' => 20],
            ];
        }

        $merged = \PharmacyOutlook::mergeAggregates($perTenantAggregates);
        $result = \PharmacyOutlook::applyMinCohortSuppression($merged, \PharmacyOutlook::MIN_COHORT);

        $this->assertArrayNotHasKey('controlled', $result['drug_category_counts']);
        $this->assertArrayHasKey('otc', $result['drug_category_counts']);
        $this->assertSame(100, $result['drug_category_counts']['otc']);
        $this->assertContains('controlled', $result['suppressed_buckets']);
    }

    // -------------------------------------------------------------------
    // collectTenantAggregate() — pure per-row shaping, exercised via a
    // stub PDO so no real database is touched (DB-free).
    // -------------------------------------------------------------------

    public function testCollectTenantAggregateNeverExposesPersonalColumns(): void
    {
        // A minimal fake PDO/PDOStatement pair is overkill to hand-roll for
        // every SQL flavor; instead we assert on the SQL text itself, which
        // is the actual PDPA-relevant surface: the query must not select
        // any personally-identifying column.
        $source = file_get_contents(__DIR__ . '/../../classes/PharmacyOutlook.php');

        $forbiddenColumns = [
            'line_user_id', 'user_id', 'shipping_name', 'shipping_phone',
            'shipping_address', 'order_number', 'note', 'admin_note',
            'owner_name', 'owner_email', 'owner_phone',
        ];

        foreach ($forbiddenColumns as $col) {
            // Allow the column to appear in comments/docblocks (explaining
            // what NOT to select) but never inside an actual SQL string
            // fed to prepare()/query(). We conservatively check it doesn't
            // appear directly after SELECT on the same line.
            $this->assertDoesNotMatchRegularExpression(
                '/SELECT[^;]*\b' . preg_quote($col, '/') . '\b/i',
                $source,
                "PharmacyOutlook must never SELECT the personal column '{$col}'"
            );
        }
    }
}
