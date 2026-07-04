<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/../modules/Core/Database.php';

/**
 * PharmacyOutlook — cross-tenant anonymized aggregate ("Pharmacy Outlook").
 *
 * Phase 4 (Scale & Ecosystem Lock-in), increment 1: a READ-ONLY, PDPA-safe
 * rollup of pharmacy activity across every active tenant. Used by the
 * platform team to spot ecosystem-wide trends (order volume, OTC vs Rx mix)
 * WITHOUT ever touching or exposing per-customer data.
 *
 * Hard rules (PDPA):
 *   - Every per-tenant query is a pure aggregate (COUNT/SUM/AVG). No column
 *     that could identify a person (line_user_id, user_id, name, phone,
 *     address, order_number, note, ...) is ever selected.
 *   - Per-tenant results carry no tenant-identifying label in the merged
 *     output — only bucket-level counts/sums are combined.
 *   - Any bucket (e.g. a drug-category split) observed across fewer than
 *     MIN_COHORT tenants contributing to it is suppressed from the final
 *     report — this prevents re-identifying a single small tenant's mix.
 *
 * Usage:
 *   $outlook = new PharmacyOutlook(Database::platform()->getConnection());
 *   $report  = $outlook->buildReport('2026-06-01', '2026-06-30');
 */
class PharmacyOutlook
{
    /** Buckets observed across fewer than this many tenants are suppressed. */
    public const MIN_COHORT = 5;

    private \PDO $platformDb;

    public function __construct(\PDO $platformDb)
    {
        $this->platformDb = $platformDb;
    }

    /**
     * Builds the merged, anonymized cross-tenant report for a date range.
     *
     * @param string $fromDate 'Y-m-d' (inclusive)
     * @param string $toDate   'Y-m-d' (inclusive)
     */
    public function buildReport(string $fromDate, string $toDate): array
    {
        self::assertDate($fromDate);
        self::assertDate($toDate);

        $tenants = $this->getActiveTenants();

        $perTenantAggregates = [];
        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant['id'];
            try {
                $pdo = Database::forTenant($tenantId)->getConnection();
            } catch (\Throwable $e) {
                // Tenant DB unreachable (mid-provisioning, host hiccup, etc.)
                // — skip rather than fail the whole report.
                continue;
            }

            $perTenantAggregates[] = self::collectTenantAggregate($pdo, $fromDate, $toDate);
        }

        $merged = self::mergeAggregates($perTenantAggregates);

        return self::applyMinCohortSuppression($merged, self::MIN_COHORT);
    }

    /**
     * Active tenants from the master registry. Only 'active' tenants are
     * considered — pending/suspended/terminated tenants have no meaningful
     * or trustworthy operational data.
     */
    private function getActiveTenants(): array
    {
        $stmt = $this->platformDb->query(
            "SELECT id FROM tenants WHERE status = 'active'"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Collects ONE tenant's anonymized aggregate for the period. Every query
     * here is a pure aggregate — no row-level / per-customer data is ever
     * selected. Returns a shape compatible with mergeAggregates().
     *
     * Public + static so it is independently unit-testable against a fake
     * PDO/stub without needing a real tenant database.
     */
    public static function collectTenantAggregate(\PDO $tenantPdo, string $fromDate, string $toDate): array
    {
        $orderCount = 0;
        $revenueTotal = 0.0;
        try {
            $stmt = $tenantPdo->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS revenue
                   FROM transactions
                  WHERE payment_status = 'paid'
                    AND DATE(created_at) BETWEEN :from AND :to"
            );
            $stmt->execute(['from' => $fromDate, 'to' => $toDate]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $orderCount = (int) ($row['n'] ?? 0);
            $revenueTotal = (float) ($row['revenue'] ?? 0);
        } catch (\Throwable $e) {
            // transactions table missing/unreadable on this tenant — degrade to zero.
        }

        $drugCategoryCounts = [];
        try {
            $stmt = $tenantPdo->query(
                "SELECT COALESCE(NULLIF(drug_category, ''), 'unclassified') AS bucket, COUNT(*) AS n
                   FROM business_items
                  WHERE is_active = 1
                  GROUP BY bucket"
            );
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $drugCategoryCounts[(string) $r['bucket']] = (int) $r['n'];
            }
        } catch (\Throwable $e) {
            // business_items table missing/unreadable — degrade to empty.
        }

        return [
            'tenant_count'          => 1,
            'order_count'           => $orderCount,
            'revenue_total'         => $revenueTotal,
            'drug_category_counts'  => $drugCategoryCounts,
            // Fresh per-tenant leaf: every bucket it reports is backed by
            // exactly this one tenant.
            'drug_category_tenants' => array_fill_keys(array_keys($drugCategoryCounts), 1),
        ];
    }

    /**
     * Merges any number of aggregates into one combined total. Accepts both
     * fresh per-tenant leaves (from collectTenantAggregate()) and already-
     * merged aggregates (the output of a previous mergeAggregates() call)
     * interchangeably — every input's 'drug_category_tenants' map (how many
     * distinct tenants back each bucket) is summed rather than re-derived,
     * so the result is truly associative: merging in any order, or merging
     * partial merges of arbitrary sub-groupings, yields the same totals.
     * Pure function — no I/O.
     */
    public static function mergeAggregates(array $perTenantAggregates): array
    {
        $totalTenants = 0;
        $orderCount = 0;
        $revenueTotal = 0.0;
        $bucketCounts = [];
        $bucketTenantCounts = [];

        foreach ($perTenantAggregates as $agg) {
            $totalTenants += (int) ($agg['tenant_count'] ?? 0);
            $orderCount   += (int) ($agg['order_count'] ?? 0);
            $revenueTotal += (float) ($agg['revenue_total'] ?? 0);

            $counts = (array) ($agg['drug_category_counts'] ?? []);
            // Fall back to "1 tenant per reported bucket" only when the
            // input doesn't already carry tenant-cohort data (i.e. it's a
            // raw leaf that predates this field, or a hand-built test
            // fixture) — keeps the function tolerant of either input shape.
            $tenantsForAgg = $agg['drug_category_tenants'] ?? array_fill_keys(array_keys($counts), 1);

            foreach ($counts as $bucket => $n) {
                $n = (int) $n;
                if ($n <= 0) {
                    continue;
                }
                $bucketCounts[$bucket] = ($bucketCounts[$bucket] ?? 0) + $n;
                $bucketTenantCounts[$bucket] = ($bucketTenantCounts[$bucket] ?? 0)
                    + (int) ($tenantsForAgg[$bucket] ?? 1);
            }
        }

        return [
            'tenant_count'          => $totalTenants,
            'order_count'           => $orderCount,
            'revenue_total'         => round($revenueTotal, 2),
            'drug_category_counts'  => $bucketCounts,
            'drug_category_tenants' => $bucketTenantCounts,
        ];
    }

    /**
     * Suppresses any drug-category bucket contributed to by fewer than
     * $minCohort distinct tenants. This is the PDPA re-identification guard:
     * a bucket backed by only 1-4 tenants could let someone infer a specific
     * small tenant's product mix, so it is dropped from the public report
     * entirely (not just hidden — removed from both counts and tenant maps).
     *
     * Pure function — no I/O.
     */
    public static function applyMinCohortSuppression(array $merged, int $minCohort): array
    {
        $tenantCounts = (array) ($merged['drug_category_tenants'] ?? []);
        $bucketCounts = (array) ($merged['drug_category_counts'] ?? []);

        $suppressed = [];
        $keptCounts = [];
        $keptTenantCounts = [];

        foreach ($bucketCounts as $bucket => $n) {
            $contributingTenants = (int) ($tenantCounts[$bucket] ?? 0);
            if ($contributingTenants < $minCohort) {
                $suppressed[] = $bucket;
                continue;
            }
            $keptCounts[$bucket] = $n;
            $keptTenantCounts[$bucket] = $contributingTenants;
        }

        $merged['drug_category_counts']  = $keptCounts;
        $merged['drug_category_tenants'] = $keptTenantCounts;
        $merged['suppressed_buckets']    = $suppressed;

        return $merged;
    }

    private static function assertDate(string $date): void
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date (expected Y-m-d): {$date}");
        }
    }

    /**
     * Shapes a buildReport()/applyMinCohortSuppression() output into the
     * clean, stable envelope used by machine-readable exports (e.g.
     * api/pharmacy-outlook-export.php). Pure function — no I/O.
     *
     * Deliberately exposes only the suppressed bucket COUNT, never the
     * bucket names themselves — the names of near-identifiable (small
     * cohort) categories are exactly the thing MIN_COHORT suppression
     * exists to hide, so they must not leak into any export either.
     */
    public static function toExportArray(array $report): array
    {
        return [
            'tenant_count'            => (int) ($report['tenant_count'] ?? 0),
            'order_count'             => (int) ($report['order_count'] ?? 0),
            'revenue_total'           => round((float) ($report['revenue_total'] ?? 0), 2),
            'drug_category_counts'    => (array) ($report['drug_category_counts'] ?? []),
            'drug_category_tenants'   => (array) ($report['drug_category_tenants'] ?? []),
            'suppressed_bucket_count' => count((array) ($report['suppressed_buckets'] ?? [])),
        ];
    }
}
