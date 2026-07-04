<?php
/**
 * Property-Based Test: PharmacyOutlook export shaping (api/pharmacy-outlook-export.php)
 *
 * **Feature: pharmacy-outlook-export, Property 1: PDPA-safety carries through**
 * Property: For any merged+suppressed aggregate, PharmacyOutlook::toExportArray()
 * SHALL NEVER include the names of suppressed buckets — only a count — and
 * every bucket present in the exported drug_category_counts SHALL be a
 * bucket that survived min-cohort suppression (i.e. was NOT suppressed).
 *
 * **Feature: pharmacy-outlook-export, Property 2: stable JSON shape**
 * Property: toExportArray() output SHALL always contain exactly the fixed
 * key set (tenant_count, order_count, revenue_total, drug_category_counts,
 * drug_category_tenants, suppressed_bucket_count) with the expected types,
 * regardless of the shape/completeness of the input report, and it SHALL
 * be JSON-encodable without error (mirrors what the real endpoint does).
 *
 * **Feature: pharmacy-outlook-export, Property 3: no raw tenant identifiers**
 * Property: the export endpoint source never selects/echoes a per-tenant
 * or per-customer identifying field (tenant id/name/slug, line_user_id, ...).
 *
 * Pure/DB-free: exercises only PharmacyOutlook::toExportArray() (static,
 * no I/O) plus a static-source scan of api/pharmacy-outlook-export.php.
 */

namespace Tests\PlatformOutlook;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/PharmacyOutlook.php';

class PharmacyOutlookExportPropertyTest extends TestCase
{
    private const DRUG_CATEGORIES = ['otc', 'dangerous', 'controlled', 'unclassified', 'antibiotic', 'psychotropic'];

    /**
     * Generates 100 random "already suppressed" report shapes — i.e. the
     * exact output shape of PharmacyOutlook::applyMinCohortSuppression(),
     * which is what buildReport() always returns before an export sees it.
     *
     * @return array<string, array{0: array}>
     */
    public function suppressedReportProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 100; $i++) {
            $cases["case_{$i}"] = [$this->generateRandomSuppressedReport()];
        }
        return $cases;
    }

    private function generateRandomSuppressedReport(): array
    {
        $keptCounts = [];
        $keptTenants = [];
        $suppressed = [];

        foreach (self::DRUG_CATEGORIES as $cat) {
            $roll = random_int(0, 100);
            if ($roll < 20) {
                continue; // category not present at all this period
            }
            if ($roll < 55) {
                // survives suppression: >= MIN_COHORT tenants contributed
                $keptCounts[$cat] = random_int(1, 5000);
                $keptTenants[$cat] = random_int(\PharmacyOutlook::MIN_COHORT, 40);
            } else {
                // below cohort threshold -> suppressed, must NOT appear in counts
                $suppressed[] = $cat;
            }
        }

        return [
            'tenant_count'          => random_int(0, 40),
            'order_count'           => random_int(0, 100000),
            'revenue_total'         => round(random_int(0, 50000000) / 100, 2),
            'drug_category_counts'  => $keptCounts,
            'drug_category_tenants' => $keptTenants,
            'suppressed_buckets'    => $suppressed,
        ];
    }

    // -------------------------------------------------------------------
    // Property 1: suppressed bucket NAMES never leak into the export.
    // -------------------------------------------------------------------

    /**
     * @dataProvider suppressedReportProvider
     */
    public function testSuppressedBucketNamesNeverAppearInExport(array $report): void
    {
        $export = \PharmacyOutlook::toExportArray($report);

        // Only a count is exposed, never the list of names.
        $this->assertArrayHasKey('suppressed_bucket_count', $export);
        $this->assertArrayNotHasKey('suppressed_buckets', $export);
        $this->assertIsInt($export['suppressed_bucket_count']);
        $this->assertSame(count($report['suppressed_buckets']), $export['suppressed_bucket_count']);

        foreach ($report['suppressed_buckets'] as $suppressedBucket) {
            $this->assertArrayNotHasKey(
                $suppressedBucket,
                $export['drug_category_counts'],
                "Suppressed bucket '{$suppressedBucket}' leaked into exported drug_category_counts"
            );
            $this->assertArrayNotHasKey(
                $suppressedBucket,
                $export['drug_category_tenants'],
                "Suppressed bucket '{$suppressedBucket}' leaked into exported drug_category_tenants"
            );
        }

        // Every bucket that DID survive into the export must be backed by
        // at least MIN_COHORT tenants (the PDPA guarantee, checked again
        // at the export boundary, not just inside applyMinCohortSuppression()).
        foreach ($export['drug_category_tenants'] as $bucket => $tenantCount) {
            $this->assertGreaterThanOrEqual(
                \PharmacyOutlook::MIN_COHORT,
                $tenantCount,
                "Exported bucket '{$bucket}' has only {$tenantCount} contributing tenants, below MIN_COHORT"
            );
        }
    }

    // -------------------------------------------------------------------
    // Property 2: stable, fully-typed, JSON-encodable shape.
    // -------------------------------------------------------------------

    /**
     * @dataProvider suppressedReportProvider
     */
    public function testExportShapeIsStableAndJsonEncodable(array $report): void
    {
        $export = \PharmacyOutlook::toExportArray($report);

        $this->assertSame(
            ['tenant_count', 'order_count', 'revenue_total', 'drug_category_counts', 'drug_category_tenants', 'suppressed_bucket_count'],
            array_keys($export)
        );

        $this->assertIsInt($export['tenant_count']);
        $this->assertIsInt($export['order_count']);
        $this->assertIsFloat($export['revenue_total']);
        $this->assertIsArray($export['drug_category_counts']);
        $this->assertIsArray($export['drug_category_tenants']);
        $this->assertIsInt($export['suppressed_bucket_count']);

        $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($json, 'toExportArray() output must always be JSON-encodable');

        $decoded = json_decode($json, true);
        $this->assertSame($export['tenant_count'], $decoded['tenant_count']);
        $this->assertEqualsWithDelta($export['revenue_total'], $decoded['revenue_total'], 0.01);
    }

    public function testExportToleratesMissingOptionalKeys(): void
    {
        // Even a minimal/partial report (e.g. hand-built fixture) must not
        // throw and must still produce the full stable key set.
        $export = \PharmacyOutlook::toExportArray([]);

        $this->assertSame(0, $export['tenant_count']);
        $this->assertSame(0, $export['order_count']);
        $this->assertEqualsWithDelta(0.0, $export['revenue_total'], 0.01);
        $this->assertSame([], $export['drug_category_counts']);
        $this->assertSame([], $export['drug_category_tenants']);
        $this->assertSame(0, $export['suppressed_bucket_count']);
    }

    public function testKnownSuppressedBucketExampleIsOmitted(): void
    {
        // Concrete example mirroring the task's ask: a mock aggregate with
        // a small-cohort bucket ('controlled', 4 tenants -- below MIN_COHORT
        // of 5) must be entirely absent from the export, while a
        // sufficiently-covered bucket ('otc', 10 tenants) survives.
        $report = [
            'tenant_count' => 12,
            'order_count' => 999,
            'revenue_total' => 12345.67,
            'drug_category_counts' => ['otc' => 500],
            'drug_category_tenants' => ['otc' => 10],
            'suppressed_buckets' => ['controlled'],
        ];

        $export = \PharmacyOutlook::toExportArray($report);

        $this->assertArrayHasKey('otc', $export['drug_category_counts']);
        $this->assertArrayNotHasKey('controlled', $export['drug_category_counts']);
        $this->assertArrayNotHasKey('controlled', $export['drug_category_tenants']);
        $this->assertSame(1, $export['suppressed_bucket_count']);
    }

    // -------------------------------------------------------------------
    // Property 3: the export endpoint source never selects/echoes raw
    // tenant or customer identifiers.
    // -------------------------------------------------------------------

    public function testExportEndpointNeverExposesRawIdentifiers(): void
    {
        $source = file_get_contents(__DIR__ . '/../../api/pharmacy-outlook-export.php');

        $this->assertNotFalse($source, 'export endpoint file must exist');

        // Gate: must require platform-admin session, same pattern as the HTML page.
        $this->assertStringContainsString("_SESSION['platform_user_id']", $source);

        // Must reuse PharmacyOutlook, never re-implement per-tenant SQL here.
        $this->assertStringContainsString('PharmacyOutlook', $source);

        $forbiddenIdentifiers = [
            'line_user_id', 'shipping_name', 'shipping_phone', 'shipping_address',
            'order_number', 'owner_name', 'owner_email', 'owner_phone', 'tenant_name',
            'tenants.name', 'tenants.slug',
        ];
        foreach ($forbiddenIdentifiers as $field) {
            $this->assertStringNotContainsString(
                $field,
                $source,
                "Export endpoint must never reference identifying field '{$field}'"
            );
        }

        // Never a raw SQL SELECT in this file — all data must come through
        // PharmacyOutlook's own aggregate queries, not a fresh query here.
        $this->assertDoesNotMatchRegularExpression('/\bSELECT\b/i', $source);
    }
}
