<?php

namespace Tests\Loyalty;

use LoyaltyLedgerService;

/**
 * Balance semantics — the zero-ledger fallback bug and the invariant that
 * replaces it.
 *
 * THE BUG THIS SUITE PINS DOWN
 *   LoyaltyPoints::getUserPoints() used to fall back to the legacy `users`
 *   columns whenever the ledger SUM came out as zero. A ledger that nets to zero
 *   because +300 and -300 cancelled is not a ledger with nothing in it, and
 *   because `users.points` is written by the Odoo webhook, the welcome bonus and
 *   the shop order flow but is NEVER decremented by deductPoints(), the fallback
 *   handed already-spent points back as spendable currency — and redeeming them
 *   a second time drove `users.available_points` permanently negative.
 *
 * @see docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §8
 * @see docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md
 */
class LedgerBalanceSemanticsPropertyTest extends LoyaltyLedgerTestCase
{
    /** Deterministic seed so a red run is reproducible — house style uses bare rand(). */
    private const SEED = 20260826;

    public function testFixtureMatchesMigration(): void
    {
        $this->assertFixtureMatchesMigration();
    }

    /**
     * THE REGRESSION. Reproduces the exact production shape the audit traced
     * through api/odoo-webhook.php:297-313, which increments users.points AND
     * users.available_points AND writes a matching ledger row — so after the
     * member spends it all, the ledger correctly nets to zero while
     * users.points still reads 300.
     */
    public function testSpentDownToZeroDoesNotResurrectLegacyPoints(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        // Odoo pays out 300: ledger row + both legacy caches move together.
        $ledger->credit($userId, 300, ['reference_type' => 'invoice', 'reference_id' => 77]);
        $this->pdo->exec("UPDATE users SET points = 300 WHERE id = {$userId}");

        // The member spends every one of them.
        $spend = $ledger->debit($userId, 300, ['reference_type' => 'reward', 'reference_id' => 5]);
        $this->assertTrue($spend['success']);

        $balance = $ledger->getBalance($userId);

        $this->assertSame(0, $balance['available_points'], 'a fully spent balance must read as zero');
        $this->assertSame(2, $balance['ledger_rows'], 'the ledger still holds both movements');
        $this->assertSame(
            LoyaltyLedgerService::SOURCE_LEDGER,
            $balance['source'],
            'a ledger with rows is authoritative — the legacy users columns must not be consulted'
        );
        $this->assertSame(300, (int) $this->userRow($userId)['points'], 'the stale legacy column is still there…');

        // …and the facade every caller actually uses agrees.
        $this->assertSame(0, $this->loyalty()->getUserPoints($userId)['available_points']);
    }

    /**
     * The other half of the same bug: those phantom points used to be
     * SPENDABLE, and spending them drove the cache negative.
     */
    public function testPhantomPointsCannotBeSpentASecondTime(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $ledger->credit($userId, 300, ['reference_type' => 'invoice', 'reference_id' => 77]);
        $this->pdo->exec("UPDATE users SET points = 300 WHERE id = {$userId}");
        $ledger->debit($userId, 300, ['reference_type' => 'reward', 'reference_id' => 5]);

        $second = $ledger->debit($userId, 300, ['reference_type' => 'reward', 'reference_id' => 6]);

        $this->assertFalse($second['success'], 'already-spent points must not be spendable again');
        $this->assertSame(LoyaltyLedgerService::REASON_INSUFFICIENT_POINTS, $second['reason']);
        $this->assertGreaterThanOrEqual(
            0,
            (int) $this->userRow($userId)['available_points'],
            'the users cache must never be driven negative'
        );
        $this->assertLedgerMatchesCache($userId, 'after a declined second redemption');
    }

    /**
     * Case A of the plan's §8 split is still honoured: a member who predates the
     * ledger entirely keeps their legacy balance visible. Removing the fallback
     * outright would have made every pre-ledger welcome bonus vanish.
     */
    public function testMemberWithNoLedgerRowsStillSeesLegacyBalance(): void
    {
        $userId = $this->makeUser(['points' => 50]);

        $balance = $this->ledger()->getBalance($userId);

        $this->assertSame(50, $balance['available_points'], 'a pre-ledger welcome bonus must survive');
        $this->assertSame(0, $balance['ledger_rows']);
        $this->assertSame(LoyaltyLedgerService::SOURCE_LEGACY_CACHE, $balance['source']);
    }

    public function testLegacyAvailablePointsIsPreferredOverTheLegacyPointsColumn(): void
    {
        $userId = $this->makeUser(['points' => 50, 'available_points' => 120, 'total_points' => 200]);

        $balance = $this->ledger()->getBalance($userId);

        $this->assertSame(120, $balance['available_points']);
        $this->assertSame(200, $balance['total_points']);
        $this->assertSame(LoyaltyLedgerService::SOURCE_LEGACY_CACHE, $balance['source']);
    }

    public function testEmptyMemberReportsGenuineZero(): void
    {
        $balance = $this->ledger()->getBalance($this->makeUser());

        $this->assertSame(0, $balance['available_points']);
        $this->assertSame(0, $balance['ledger_rows']);
        $this->assertSame(LoyaltyLedgerService::SOURCE_EMPTY, $balance['source']);
    }

    /**
     * A member's first modern award opens their ledger at the awarded amount,
     * NOT at legacy-plus-awarded.
     *
     * This is unchanged from the old addPoints() in every observable way — it too
     * left the cache and the ledger on 100 — but it is now internally consistent:
     * the old code wrote balance_after = 150 while the cache and ledger both said
     * 100, which is why historical balance_after values cannot be trusted.
     *
     * The legacy 50 is not lost silently. scripts/loyalty-reconcile.php reports
     * this member as LEGACY_ONLY before the first award and as a CONFLICT after
     * it, and §34 of the plan carries such balances over as explicit `migration`
     * rows once an operator has reviewed the report. Doing it implicitly here
     * would mean inventing points from a column five other writers can inflate.
     */
    public function testFirstLedgerMovementOpensAtTheAwardedAmount(): void
    {
        $userId = $this->makeUser(['points' => 50]);

        $result = $this->ledger()->credit($userId, 100, ['reference_type' => 'claim']);

        $this->assertTrue($result['success']);
        $this->assertSame(100, $result['balance_after']);
        $this->assertSame(100, $this->ledger()->getBalance($userId)['available_points']);
        $this->assertLedgerMatchesCache($userId, 'first movement on a legacy member');
    }

    /**
     * PROPERTY: over any sequence of credits and debits, the ledger sum and the
     * users cache stay equal, the reported balance never goes negative, and the
     * balance always equals the clamped ledger sum. This is the Phase 1
     * acceptance criterion, checked against random histories.
     *
     * @dataProvider movementSequenceProvider
     */
    public function testLedgerAndCacheNeverDiverge(array $movements): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();
        $expected = 0;

        foreach ($movements as $i => $delta) {
            if ($delta > 0) {
                $result = $ledger->credit($userId, $delta, ['reference_type' => 'test', 'reference_id' => $i]);
                $expected += $delta;
                $this->assertTrue($result['success'], "credit #{$i} should succeed (seed " . self::SEED . ')');
            } else {
                $wanted = abs($delta);
                $result = $ledger->debit($userId, $wanted, ['reference_type' => 'test', 'reference_id' => $i]);
                if ($wanted <= $expected) {
                    $this->assertTrue($result['success'], "debit #{$i} should succeed (seed " . self::SEED . ')');
                    $expected -= $wanted;
                } else {
                    $this->assertFalse($result['success'], "debit #{$i} should be declined (seed " . self::SEED . ')');
                    $this->assertSame(LoyaltyLedgerService::REASON_INSUFFICIENT_POINTS, $result['reason']);
                }
            }

            $this->assertLedgerMatchesCache($userId, "after movement #{$i}");
            $balance = $ledger->getBalance($userId);
            $this->assertSame($expected, $balance['available_points'], "balance after movement #{$i}");
            $this->assertGreaterThanOrEqual(0, $balance['available_points']);
        }
    }

    /** @return array<string, array{0: array<int, int>}> */
    public static function movementSequenceProvider(): array
    {
        mt_srand(self::SEED);
        $cases = [];

        for ($case = 0; $case < 100; $case++) {
            $movements = [];
            $length = mt_rand(1, 12);
            for ($step = 0; $step < $length; $step++) {
                // Bias towards credits so debits sometimes succeed and
                // sometimes hit the insufficient-balance branch.
                $movements[] = mt_rand(0, 2) === 0
                    ? -mt_rand(1, 400)
                    : mt_rand(1, 300);
            }
            $cases["sequence_{$case}"] = [$movements];
        }

        return $cases;
    }

    /**
     * PROPERTY: lifetime earned and lifetime used are each monotonic, and
     * available = earned - used, for any history.
     *
     * @dataProvider movementSequenceProvider
     */
    public function testLifetimeTotalsAreConsistent(array $movements): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();
        $earned = 0;
        $used = 0;

        foreach ($movements as $i => $delta) {
            if ($delta > 0) {
                $ledger->credit($userId, $delta, ['reference_type' => 'test', 'reference_id' => $i]);
                $earned += $delta;
            } elseif ($ledger->debit($userId, abs($delta), ['reference_type' => 'test', 'reference_id' => $i])['success']) {
                $used += abs($delta);
            }

            $balance = $ledger->getBalance($userId);
            $this->assertSame($earned, $balance['total_points'], 'lifetime earned');
            $this->assertSame($used, $balance['used_points'], 'lifetime used');
            $this->assertSame($earned - $used, $balance['available_points'], 'available = earned - used');
        }
    }
}
