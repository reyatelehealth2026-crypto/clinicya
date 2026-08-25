<?php

namespace Tests\Loyalty;

use TierService;

require_once __DIR__ . '/../../classes/TierService.php';

/**
 * Phase 3 — tier semantics.
 *
 * Two defects the Phase 0 audit found, both of which reached customers:
 *
 * 1. The tier was recomputed from the POST-REDEMPTION SPENDABLE balance, so
 *    spending points demoted the member. A Gold member with 5,500 accumulated
 *    points who redeemed 5,000 fell back toward Bronze. Every redeem, POS points
 *    payment, POS void, POS return and account merge was a downgrade path.
 *
 * 2. `tier_settings.multiplier` — DB comment "Points earning multiplier", admin
 *    UI label "ตัวคูณแต้ม", help text "1.5x = ได้แต้มเพิ่ม 50%" — was read as
 *    `multiplier AS discount_percent`, so a tier configured as "earn 1.5x" was
 *    served to the mini app as "1.5% discount".
 *
 * @see docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §12, §13
 */
class TierSemanticsTest extends LoyaltyLedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TierService::clearCache();

        // tier_settings AFTER migration_2026-08-26_tier_semantics.sql
        $this->pdo->exec(
            'CREATE TABLE tier_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER DEFAULT NULL,
                name TEXT NOT NULL,
                min_points INTEGER NOT NULL DEFAULT 0,
                multiplier REAL DEFAULT 1.00,
                earn_multiplier REAL NOT NULL DEFAULT 1.00,
                discount_percent REAL NOT NULL DEFAULT 0.00,
                benefits TEXT DEFAULT NULL,
                badge_color TEXT DEFAULT NULL
            )'
        );
    }

    protected function tearDown(): void
    {
        TierService::clearCache();
        parent::tearDown();
    }

    private function seedTiers(): void
    {
        $rows = [
            // name,      min,    multiplier(legacy), earn, discount
            ['Bronze', 0, 1.00, 1.00, 0.0],
            ['Silver', 1000, 1.10, 1.10, 2.0],
            ['Gold', 5000, 1.25, 1.25, 5.0],
            ['Platinum', 15000, 1.50, 1.50, 10.0],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO tier_settings (line_account_id, name, min_points, multiplier, earn_multiplier, discount_percent, badge_color)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $stmt->execute([$this->lineAccountId, $row[0], $row[1], $row[2], $row[3], $row[4], '#000000']);
        }
        TierService::clearCache();
    }

    private function tiers(): TierService
    {
        return new TierService($this->pdo, $this->lineAccountId);
    }

    // -----------------------------------------------------------------------
    // Spending must not demote
    // -----------------------------------------------------------------------

    /** THE REGRESSION. Earn to Gold, spend it all, stay Gold. */
    public function testSpendingPointsDoesNotDemoteTheMember(): void
    {
        $this->seedTiers();
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();

        $loyalty->addPoints($userId, 5500, 'claim');
        $this->assertSame('gold', $this->tiers()->getUserTier($userId)['tier_code']);

        $this->assertTrue($loyalty->deductPoints($userId, 5000, 'reward', 1));

        $this->assertSame(500, $loyalty->getUserPoints($userId)['available_points'], 'spendable balance did drop');
        $this->assertSame(
            'gold',
            $this->tiers()->getUserTier($userId)['tier_code'],
            'status is earned, not rented — spending must not demote'
        );
        $this->assertSame(
            'gold',
            $this->userRow($userId)['member_tier'],
            'the persisted member_tier column must agree'
        );
    }

    public function testQualifyingPointsIsLifetimeEarnedNotTheBalance(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $ledger->credit($userId, 800, ['reference_type' => 'claim']);
        $ledger->credit($userId, 400, ['reference_type' => 'claim']);
        $ledger->debit($userId, 1000, ['reference_type' => 'reward']);

        $this->assertSame(200, $ledger->getBalance($userId)['available_points']);
        $this->assertSame(1200, $ledger->getQualifyingPoints($userId), 'lifetime earned is monotonic');
    }

    /**
     * A refund returns points the member already spent. Counting it toward
     * status would let a redeem-then-cancel cycle inflate a tier without bound.
     */
    public function testRefundsDoNotInflateQualifyingPoints(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $ledger->credit($userId, 1000, ['reference_type' => 'claim']);
        $ledger->debit($userId, 400, ['reference_type' => 'reward', 'reference_id' => 5]);
        $ledger->credit($userId, 400, [
            'type' => \LoyaltyLedgerService::TYPE_REFUND,
            'reference_type' => 'refund',
            'reference_id' => 5,
        ]);

        $this->assertSame(1000, $ledger->getBalance($userId)['available_points'], 'the refund did restore the balance');
        $this->assertSame(1000, $ledger->getQualifyingPoints($userId), 'but it must not count twice toward status');
    }

    /** A migrated opening balance IS real earned history and must count. */
    public function testMigratedOpeningBalancesCountTowardStatus(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $ledger->credit($userId, 6000, [
            'type' => \LoyaltyLedgerService::TYPE_MIGRATION,
            'reference_type' => 'legacy_opening',
        ]);

        $this->assertSame(6000, $ledger->getQualifyingPoints($userId));
    }

    public function testTierRisesAsLifetimeEarnedCrossesEachThreshold(): void
    {
        $this->seedTiers();
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();

        $expectations = [
            [999, 'bronze'],
            [1, 'silver'],     // 1000
            [3999, 'silver'],  // 4999
            [1, 'gold'],       // 5000
            [9999, 'gold'],    // 14999
            [1, 'platinum'],   // 15000
        ];

        foreach ($expectations as [$award, $expectedTier]) {
            $loyalty->addPoints($userId, $award, 'claim');
            $this->assertSame(
                $expectedTier,
                $this->tiers()->getUserTier($userId)['tier_code'],
                'after cumulative award of ' . $award
            );
        }
    }

    public function testAMemberWithNoLedgerRowsKeepsTheirLegacyTier(): void
    {
        $this->seedTiers();
        $userId = $this->makeUser(['total_points' => 5200, 'available_points' => 300]);

        $this->assertSame(
            'gold',
            $this->tiers()->getUserTier($userId)['tier_code'],
            'a pre-ledger member must not be demoted by the migration'
        );
    }

    // -----------------------------------------------------------------------
    // The multiplier / discount split
    // -----------------------------------------------------------------------

    public function testEarnMultiplierAndDiscountAreSeparateValues(): void
    {
        $this->seedTiers();

        $gold = $this->tiers()->calculateTier(5000);

        $this->assertSame('gold', $gold['tier_code']);
        $this->assertSame(1.25, $gold['earn_multiplier'], 'the configured earn multiplier');
        $this->assertSame(5.0, $gold['discount_percent'], 'the configured discount — a different number');
        $this->assertNotSame(
            $gold['earn_multiplier'],
            $gold['discount_percent'],
            'these were the same column read under two names'
        );
    }

    /**
     * On a tenant that has not run the migration, `multiplier` must be read as
     * the EARN multiplier — its documented meaning — and the discount must
     * report 0 rather than silently borrowing the multiplier's value.
     */
    public function testUnmigratedTenantReadsMultiplierAsEarnNotDiscount(): void
    {
        $this->pdo->exec('DROP TABLE tier_settings');
        $this->pdo->exec(
            'CREATE TABLE tier_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER DEFAULT NULL,
                name TEXT NOT NULL,
                min_points INTEGER NOT NULL DEFAULT 0,
                multiplier REAL DEFAULT 1.00,
                benefits TEXT DEFAULT NULL,
                badge_color TEXT DEFAULT NULL
            )'
        );
        $this->pdo->exec(
            "INSERT INTO tier_settings (line_account_id, name, min_points, multiplier, badge_color)
             VALUES ({$this->lineAccountId}, 'Bronze', 0, 1.00, '#000'),
                    ({$this->lineAccountId}, 'Gold', 5000, 1.50, '#FFD700')"
        );
        TierService::clearCache();

        $gold = $this->tiers()->calculateTier(5000);

        $this->assertSame(1.5, $gold['earn_multiplier'], '1.5x earn, as the column comment says');
        $this->assertSame(0.0, $gold['discount_percent'], 'NOT served to the customer as a 1.5% discount');
    }

    public function testDefaultTiersCarryANeutralEarnMultiplier(): void
    {
        // No tier_settings rows at all -> DEFAULT_TIERS.
        $this->pdo->exec('DELETE FROM tier_settings');
        TierService::clearCache();

        foreach ([0, 1000, 5000, 15000] as $points) {
            $tier = $this->tiers()->calculateTier($points);
            $this->assertSame(
                1.0,
                $tier['earn_multiplier'],
                'defaults must not invent an earn bonus nobody configured'
            );
        }
    }

    public function testTheFacadeExposesBothBenefits(): void
    {
        $this->seedTiers();
        $userId = $this->makeUser();
        $this->loyalty()->addPoints($userId, 5000, 'claim');

        $tier = $this->loyalty()->getUserTier($userId);

        $this->assertSame('gold', $tier['tier_code']);
        $this->assertSame(5.0, $tier['discount_percent']);
        $this->assertSame(1.25, $tier['earn_multiplier']);
    }

    /** The settings screen must offer the two benefits as two inputs. */
    public function testTheSettingsFormOffersBothFields(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/membership/settings.php');

        $this->assertStringContainsString('name="tier_multiplier[]"', $form);
        $this->assertStringContainsString('name="tier_discount[]"', $form, 'the discount needs its own input');
    }
}
