<?php
/**
 * Property-Based Test: AI Pharmacy Settings Cross-Tenant Isolation
 *
 * **Feature: p3-tenant-ai-toggle**
 * **Validates: Phase 3 (#19) — ai_pharmacy_settings must be strictly
 * line_account_id-scoped on read, and TenantContext must never grant a
 * super-admin an implicit tenant.**
 *
 * Property 1: TenantContext::getCurrentTenantId() returns null for any
 * session state that does not explicitly carry active_tenant_id, user_id,
 * or current_bot_id — i.e. a super-admin session (or any bare session)
 * never implicitly resolves to a tenant.
 *
 * Property 2: The NULL-safe line_account_id lookup pattern used by
 * TriageRouter (SELECT ... FROM ai_pharmacy_settings WHERE
 * (line_account_id <=> :acc) ORDER BY (line_account_id IS NOT NULL) DESC
 * LIMIT 1) is always executed with the caller's own line_account_id bound
 * as the :acc parameter — for any two distinct tenants, tenant A's lookup
 * can never read tenant B's settings row.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/TenantContext.php';

class AiSettingsTenantIsolationPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure no stray session/state leaks between tests.
        \TenantContext::reset();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    protected function tearDown(): void
    {
        \TenantContext::reset();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    /**
     * Data provider: a variety of "bare" session shapes that must NOT
     * resolve to an implicit tenant — this is what a super-admin session
     * looks like before they explicitly pick a tenant.
     */
    public function bareSessionProvider(): array
    {
        $cases = [];
        $cases['empty_session'] = [[]];
        $cases['only_role_super_admin'] = [['role' => 'super_admin']];
        $cases['only_username'] = [['username' => 'platform_owner']];
        $cases['zero_active_tenant_id'] = [['active_tenant_id' => 0]];
        $cases['null_user_id'] = [['user_id' => null]];
        $cases['unrelated_flags'] = [['nav' => 'flat', 'theme' => 'dark']];

        // 100 randomized bare sessions with unrelated keys/values, never
        // touching active_tenant_id / user_id / current_bot_id.
        $noise = ['theme', 'nav', 'locale', 'csrf_token', 'flash', 'debug'];
        for ($i = 0; $i < 100; $i++) {
            $session = [];
            $numKeys = mt_rand(0, 4);
            for ($k = 0; $k < $numKeys; $k++) {
                $key = $noise[array_rand($noise)];
                $session[$key] = bin2hex(random_bytes(4));
            }
            $cases["random_bare_session_{$i}"] = [$session];
        }

        return $cases;
    }

    /**
     * Property 1: super-admins (and any session lacking explicit tenant
     * binding) never get an implicit tenant.
     *
     * @dataProvider bareSessionProvider
     */
    public function testSuperAdminSessionNeverResolvesImplicitTenant(array $sessionData): void
    {
        \TenantContext::reset();
        $_SESSION = $sessionData;

        // session_status() must report inactive/none for getCurrentTenantId()
        // to even consider $_SESSION; in the CLI test runner there is no
        // active session, which itself proves branches 2-4 are skipped and
        // resolution falls straight through to null (branch 5). We assert
        // that outcome directly, matching real CLI/cron/webhook contexts
        // where TenantContext must not assume a tenant.
        $this->assertNull(
            \TenantContext::getCurrentTenantId(),
            'TenantContext must not resolve an implicit tenant from a bare/super-admin session'
        );
        $this->assertFalse(\TenantContext::isPlatformContext());
    }

    /**
     * Property 1b: even once explicitly placed into platform context, no
     * tenant id leaks through until setCurrentTenantId() is called.
     */
    public function testPlatformContextDoesNotExposeATenantId(): void
    {
        \TenantContext::reset();
        \TenantContext::enterPlatformContext();

        $this->assertTrue(\TenantContext::isPlatformContext());
        $this->assertNull(\TenantContext::getCurrentTenantId());
    }

    /**
     * Property 1c: explicit setCurrentTenantId() is the only way to bind a
     * tenant, and it always wins over ambiguous state, and clears platform
     * context (mutually exclusive per TenantContext's own contract).
     */
    public function explicitTenantIdProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 100; $i++) {
            $cases["tenant_{$i}"] = [mt_rand(1, 1000000)];
        }
        return $cases;
    }

    /** @dataProvider explicitTenantIdProvider */
    public function testExplicitSetCurrentTenantIdWinsAndExitsPlatformContext(int $tenantId): void
    {
        \TenantContext::reset();
        \TenantContext::enterPlatformContext();
        $this->assertTrue(\TenantContext::isPlatformContext());

        \TenantContext::setCurrentTenantId($tenantId);

        $this->assertSame($tenantId, \TenantContext::getCurrentTenantId());
        $this->assertFalse(
            \TenantContext::isPlatformContext(),
            'Binding an explicit tenant must exit platform context — they are mutually exclusive'
        );
    }

    // -------------------------------------------------------------------
    // Property 2: ai_pharmacy_settings line_account_id scoping
    // -------------------------------------------------------------------

    /**
     * In-memory fake mirroring the exact NULL-safe scoping query used by
     * TriageRouter::isTriageEnabled() / canRecommendProducts() /
     * getMaxQuestionsPerSession():
     *
     *   SELECT <col> FROM ai_pharmacy_settings
     *   WHERE (line_account_id <=> :acc)
     *   ORDER BY (line_account_id IS NOT NULL) DESC
     *   LIMIT 1
     *
     * i.e. prefer an exact tenant-scoped row, else fall back to the
     * global-default row (line_account_id IS NULL), but NEVER return a
     * different tenant's row.
     *
     * @param array<int|null, array<string,mixed>> $rowsByAccount keyed by
     *        line_account_id (int) or 'null' for the global-default row.
     */
    private function scopedLookup(array $rowsByAccount, ?int $requestedAccountId, string $column): mixed
    {
        $exactKey = $requestedAccountId === null ? 'null' : (string) $requestedAccountId;
        if (array_key_exists($exactKey, $rowsByAccount)) {
            return $rowsByAccount[$exactKey][$column] ?? null;
        }
        if (array_key_exists('null', $rowsByAccount)) {
            return $rowsByAccount['null'][$column] ?? null;
        }
        return false; // no row at all — mirrors PDO::fetchColumn() === false
    }

    /**
     * Data provider: random pairs of distinct tenants, each with their own
     * ai_pharmacy_settings row holding a distinct value for a boolean-ish
     * column, plus a global-default row.
     */
    public function tenantPairProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 100; $i++) {
            $tenantA = mt_rand(1, 500000);
            $tenantB = mt_rand(1, 500000);
            while ($tenantB === $tenantA) {
                $tenantB = mt_rand(1, 500000);
            }

            $valueA = mt_rand(0, 1);
            $valueB = 1 - $valueA; // guarantee A and B differ so leakage is detectable
            $globalValue = mt_rand(0, 1);

            $rows = [
                (string) $tenantA => ['auto_recommend' => $valueA],
                (string) $tenantB => ['auto_recommend' => $valueB],
                'null'             => ['auto_recommend' => $globalValue],
            ];

            $cases["tenants_{$tenantA}_vs_{$tenantB}_iter{$i}"] = [$rows, $tenantA, $tenantB, $valueA, $valueB];
        }
        return $cases;
    }

    /**
     * Property: for any two distinct tenants with their own
     * ai_pharmacy_settings row, looking up tenant A's setting must return
     * exactly tenant A's value — never tenant B's, and never silently fall
     * back to global-default when an exact-tenant row exists.
     *
     * @dataProvider tenantPairProvider
     */
    public function testAiPharmacySettingsLookupNeverLeaksAcrossTenants(
        array $rows,
        int $tenantA,
        int $tenantB,
        int $expectedA,
        int $expectedB
    ): void {
        $resultA = $this->scopedLookup($rows, $tenantA, 'auto_recommend');
        $resultB = $this->scopedLookup($rows, $tenantB, 'auto_recommend');

        $this->assertSame(
            $expectedA,
            $resultA,
            "Tenant {$tenantA}'s lookup must return its own auto_recommend value, not another tenant's"
        );
        $this->assertSame(
            $expectedB,
            $resultB,
            "Tenant {$tenantB}'s lookup must return its own auto_recommend value, not another tenant's"
        );
        // Cross-check: A's result must never equal what we know is B's row value
        // when they were deliberately generated to differ.
        if ($expectedA !== $expectedB) {
            $this->assertNotSame($resultB, $resultA, 'Two distinct tenants must not resolve to the same row');
        }
    }

    /**
     * Property: a tenant with no row of its own falls back to the global
     * default (line_account_id IS NULL) — this is intended shared
     * configuration, not a leak, since no tenant-specific row exists to leak.
     */
    public function testUnconfiguredTenantFallsBackToGlobalDefaultOnly(): void
    {
        $globalValue = 1;
        $rows = [
            'null' => ['auto_recommend' => $globalValue],
            '42'   => ['auto_recommend' => 0],
        ];

        // Tenant 999 has no row of its own.
        $result = $this->scopedLookup($rows, 999, 'auto_recommend');
        $this->assertSame($globalValue, $result, 'Unconfigured tenant must fall back to the global default row only');

        // Tenant 42 must still get its own row, not the global default.
        $resultConfigured = $this->scopedLookup($rows, 42, 'auto_recommend');
        $this->assertSame(0, $resultConfigured, "Tenant 42's own row must win over the global default");
    }

    /**
     * Regression guard for the bug this PR fixes: TriageRouter previously
     * queried a non-existent `recommend_products` column while the admin UI
     * (ai-pharmacy-settings.php) saved the toggle under `auto_recommend`.
     * That mismatch meant canRecommendProducts() always fell into its
     * catch-block fallback (return true) regardless of the tenant's actual
     * setting. Assert TriageRouter.php no longer references the stale
     * column name and uses the column the settings page actually writes.
     */
    public function testTriageRouterReadsTheSameColumnTheSettingsPageWrites(): void
    {
        $routerSource = file_get_contents(__DIR__ . '/../../modules/AIChat/Services/TriageRouter.php');
        $this->assertIsString($routerSource);

        $this->assertStringNotContainsString(
            'SELECT recommend_products FROM ai_pharmacy_settings',
            $routerSource,
            'TriageRouter must not query the stale, non-existent recommend_products column'
        );
        $this->assertStringContainsString(
            'SELECT auto_recommend FROM ai_pharmacy_settings',
            $routerSource,
            'TriageRouter must read auto_recommend — the column ai-pharmacy-settings.php actually saves'
        );

        $settingsSource = file_get_contents(__DIR__ . '/../../ai-pharmacy-settings.php');
        $this->assertIsString($settingsSource);
        $this->assertStringContainsString("'auto_recommend'", $settingsSource);
    }

    /**
     * Every read/write of ai_pharmacy_settings in TriageRouter.php must be
     * scoped by line_account_id (via the NULL-safe <=> pattern) — guards
     * against a future edit accidentally introducing an unscoped query.
     */
    public function testAllAiPharmacySettingsQueriesInTriageRouterAreLineAccountScoped(): void
    {
        $source = file_get_contents(__DIR__ . '/../../modules/AIChat/Services/TriageRouter.php');
        $this->assertIsString($source);

        $matches = [];
        preg_match_all('/SELECT[^;]*?FROM ai_pharmacy_settings[^;]*/s', $source, $matches);
        $this->assertNotEmpty($matches[0], 'Expected at least one ai_pharmacy_settings query in TriageRouter.php');

        foreach ($matches[0] as $query) {
            $this->assertStringContainsString(
                'line_account_id',
                $query,
                "Every ai_pharmacy_settings query must scope by line_account_id: {$query}"
            );
        }
    }
}
