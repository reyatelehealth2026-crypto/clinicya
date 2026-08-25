<?php

namespace Tests\Loyalty;

use LoyaltyLedgerService;
use PDO;
use PDOException;

/**
 * Atomicity, transaction enlistment and idempotency.
 *
 * The Phase 0 audit found that of 26 point-writing paths only three were
 * idempotent and none kept the ledger and the `users` cache in one transaction.
 * These are the guarantees that replace that.
 *
 * @see docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §10
 */
class LedgerAtomicityAndIdempotencyTest extends LoyaltyLedgerTestCase
{
    // -----------------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------------

    public function testReplayingTheSameKeyAwardsOnlyOnce(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();
        $options = [
            'reference_type' => 'order',
            'reference_id' => 1182,
            'idempotency_key' => 'la:3:order:1182:earn',
        ];

        $first = $ledger->credit($userId, 120, $options);
        $second = $ledger->credit($userId, 120, $options);

        $this->assertTrue($first['success']);
        $this->assertFalse($first['duplicate']);

        $this->assertTrue($second['success'], 'a replay is a success, not an error');
        $this->assertTrue($second['duplicate']);
        $this->assertSame(
            $first['transaction_id'],
            $second['transaction_id'],
            'the replay must return the ORIGINAL transaction'
        );

        $this->assertSame(1, $this->ledgerRowCount($userId));
        $this->assertSame(120, $ledger->getBalance($userId)['available_points']);
        $this->assertLedgerMatchesCache($userId, 'after a replayed award');
    }

    public function testReplayingADebitSpendsOnlyOnce(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();
        $ledger->credit($userId, 500, ['reference_type' => 'claim']);

        $options = ['reference_type' => 'reward', 'reference_id' => 9001, 'idempotency_key' => 'la:3:redemption:9001:redeem'];
        $first = $ledger->debit($userId, 200, $options);
        $second = $ledger->debit($userId, 200, $options);

        $this->assertTrue($first['success']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame(300, $ledger->getBalance($userId)['available_points'], 'only one 200-point debit applied');
        $this->assertSame(2, $this->ledgerRowCount($userId), 'one credit + one debit');
    }

    public function testDistinctKeysAwardSeparately(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $ledger->credit($userId, 50, ['idempotency_key' => 'la:3:order:1:earn']);
        $ledger->credit($userId, 50, ['idempotency_key' => 'la:3:order:2:earn']);

        $this->assertSame(2, $this->ledgerRowCount($userId));
        $this->assertSame(100, $ledger->getBalance($userId)['available_points']);
    }

    /**
     * Without a key the service is at-least-once, exactly as every caller is
     * today. Pinned so nobody assumes idempotency they did not ask for — Batch 2
     * is what supplies keys at each call site.
     */
    public function testMovementsWithoutAKeyAreNotDeduplicated(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $ledger->credit($userId, 50, ['reference_type' => 'order', 'reference_id' => 1]);
        $ledger->credit($userId, 50, ['reference_type' => 'order', 'reference_id' => 1]);

        $this->assertSame(2, $this->ledgerRowCount($userId));
        $this->assertSame(100, $ledger->getBalance($userId)['available_points']);
    }

    /**
     * The DB-level backstop: if another request inserted the row between our
     * lookup and our insert, the UNIQUE index rejects us and we must adopt the
     * winner rather than surface an error.
     */
    public function testLosingTheUniqueIndexRaceAdoptsTheWinningTransaction(): void
    {
        $userId = $this->makeUser();
        $key = 'la:3:claim:abc123:earn';

        // Simulate the concurrent winner having already committed its row.
        $stmt = $this->pdo->prepare(
            'INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, idempotency_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $this->lineAccountId, 'earn', 75, 75, $key, '2026-08-26 10:00:00']);
        $winnerId = (int) $this->pdo->lastInsertId();

        $result = $this->ledger()->credit($userId, 75, ['idempotency_key' => $key]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertSame($winnerId, $result['transaction_id']);
        $this->assertSame(1, $this->ledgerRowCount($userId), 'no second row was written');
    }

    public function testFindByIdempotencyKeyReturnsNullForAnUnusedKey(): void
    {
        $this->assertNull($this->ledger()->findByIdempotencyKey('la:3:never:used'));
    }

    public function testSupportsIdempotencyReflectsTheSchema(): void
    {
        $this->assertTrue($this->ledger()->supportsIdempotency(), 'the migrated fixture supports it');
    }

    /**
     * A tenant that has not yet run migration_2026-08-26 must keep working —
     * degraded to at-least-once, not broken.
     */
    public function testWorksOnADatabaseWithoutTheIdempotencyColumn(): void
    {
        $this->pdo->exec('DROP TABLE points_transactions');
        $this->pdo->exec(
            'CREATE TABLE points_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                line_account_id INTEGER DEFAULT NULL,
                type TEXT NOT NULL,
                points INTEGER NOT NULL,
                balance_after INTEGER NOT NULL,
                reference_type TEXT DEFAULT NULL,
                reference_id INTEGER DEFAULT NULL,
                description TEXT DEFAULT NULL,
                expires_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $this->assertFalse($ledger->supportsIdempotency());

        $result = $ledger->credit($userId, 90, ['idempotency_key' => 'la:3:order:5:earn']);

        $this->assertTrue($result['success'], 'the award still lands on an unmigrated tenant');
        $this->assertSame(90, $ledger->getBalance($userId)['available_points']);
        $this->assertLedgerMatchesCache($userId, 'unmigrated tenant');
    }

    // -----------------------------------------------------------------------
    // Atomicity
    // -----------------------------------------------------------------------

    /**
     * Break the cache recompute and the ledger row must not survive. Before
     * Batch 1 these were two unwrapped statements and the row DID survive,
     * leaving the ledger and the cache permanently disagreeing.
     */
    public function testAFailedCacheUpdateRollsBackTheLedgerRow(): void
    {
        $pdo = $this->throwingPdo();
        $userId = $this->makeUser();

        $ledger = new LoyaltyLedgerService($pdo, $this->lineAccountId);
        $pdo->failOnStatementContaining('UPDATE users SET');

        try {
            $ledger->credit($userId, 250, ['reference_type' => 'order', 'reference_id' => 7]);
            $this->fail('the induced failure should have propagated');
        } catch (PDOException $e) {
            $this->assertStringContainsString('induced failure', $e->getMessage());
        }

        $pdo->failOnStatementContaining(null);

        $this->assertSame(0, $this->ledgerRowCount($userId), 'the ledger insert must have been rolled back');
        $this->assertSame(0, (int) $this->userRow($userId)['available_points']);
        $this->assertFalse($this->pdo->inTransaction(), 'no transaction may be left open');
    }

    /**
     * The service must ENLIST in a caller's transaction, never commit inside it.
     * POSService::completeTransaction() and api/points-claim.php both open their
     * own transaction around a wider unit of work; if the ledger committed
     * independently, their rollback would leave orphaned points behind.
     */
    public function testCreditEnlistsInTheCallersTransactionAndRollsBackWithIt(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $this->pdo->beginTransaction();
        $result = $ledger->credit($userId, 400, ['reference_type' => 'pos_sale', 'reference_id' => 12]);
        $this->assertTrue($result['success']);
        $this->assertTrue($this->pdo->inTransaction(), 'the service must not have committed the caller out from under them');
        $this->pdo->rollBack();

        $this->assertSame(0, $this->ledgerRowCount($userId), 'the award rolled back with its caller');
        $this->assertSame(0, (int) $this->userRow($userId)['available_points']);
    }

    public function testCreditCommitsWithTheCallersTransaction(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $this->pdo->beginTransaction();
        $ledger->credit($userId, 400, ['reference_type' => 'pos_sale', 'reference_id' => 12]);
        $this->pdo->commit();

        $this->assertSame(1, $this->ledgerRowCount($userId));
        $this->assertSame(400, $ledger->getBalance($userId)['available_points']);
        $this->assertLedgerMatchesCache($userId, 'after the caller committed');
    }

    // -----------------------------------------------------------------------
    // Declines
    // -----------------------------------------------------------------------

    public function testDebitBeyondTheBalanceIsDeclinedAndWritesNothing(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();
        $ledger->credit($userId, 100, ['reference_type' => 'claim']);

        $result = $ledger->debit($userId, 101, ['reference_type' => 'reward']);

        $this->assertFalse($result['success']);
        $this->assertSame(LoyaltyLedgerService::REASON_INSUFFICIENT_POINTS, $result['reason']);
        $this->assertSame(1, $this->ledgerRowCount($userId), 'no debit row was written');
        $this->assertSame(100, $ledger->getBalance($userId)['available_points']);
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testTwoFullBalanceDebitsCannotBothSucceed(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();
        $ledger->credit($userId, 250, ['reference_type' => 'claim']);

        $first = $ledger->debit($userId, 250, ['reference_type' => 'reward', 'reference_id' => 1]);
        $second = $ledger->debit($userId, 250, ['reference_type' => 'reward', 'reference_id' => 2]);

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame(0, $ledger->getBalance($userId)['available_points']);
        $this->assertGreaterThanOrEqual(0, (int) $this->userRow($userId)['available_points']);
    }

    /**
     * A movement for a member who does not exist is declined rather than
     * written. The old addPoints() inserted the ledger row anyway and updated
     * zero `users` rows, producing an orphan the reconciliation report can never
     * explain.
     */
    public function testMovementForAnUnknownMemberIsDeclined(): void
    {
        $result = $this->ledger()->credit(999999, 100, ['reference_type' => 'order']);

        $this->assertFalse($result['success']);
        $this->assertSame(LoyaltyLedgerService::REASON_USER_NOT_FOUND, $result['reason']);
        $this->assertSame(0, $this->ledgerRowCount(999999), 'no orphan ledger row');
    }

    public function testZeroPointMovementsAreRejected(): void
    {
        $userId = $this->makeUser();

        $this->assertFalse($this->ledger()->credit($userId, 0)['success']);
        $this->assertSame(0, $this->ledgerRowCount($userId));
    }

    public function testCreditRejectsADebitType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger()->credit($this->makeUser(), 10, ['type' => LoyaltyLedgerService::TYPE_REDEEM]);
    }

    public function testDebitRejectsACreditType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger()->debit($this->makeUser(), 10, ['type' => LoyaltyLedgerService::TYPE_EARN]);
    }

    // -----------------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------------

    public function testMetadataAndActorArePersisted(): void
    {
        $userId = $this->makeUser();

        $this->ledger()->credit($userId, 250, [
            'reference_type' => 'order',
            'reference_id' => 1182,
            'created_by' => 'admin:12',
            'metadata' => [
                'base_points' => 100,
                'campaign_multiplier' => 2.0,
                'tier_multiplier' => 1.25,
                'final_points' => 250,
            ],
        ]);

        $row = $this->pdo->query('SELECT * FROM points_transactions ORDER BY id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('admin:12', $row['created_by']);
        $decoded = json_decode((string) $row['metadata'], true);
        $this->assertSame(250, $decoded['final_points']);
        $this->assertSame(100, $decoded['base_points']);
    }

    public function testRowsCarryTheLineAccountScopeAndReference(): void
    {
        $userId = $this->makeUser();

        $this->ledger()->credit($userId, 10, ['reference_type' => 'claim', 'reference_id' => 42]);

        $row = $this->pdo->query('SELECT * FROM points_transactions ORDER BY id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($this->lineAccountId, (int) $row['line_account_id']);
        $this->assertSame('claim', $row['reference_type']);
        $this->assertSame(42, (int) $row['reference_id']);
        $this->assertSame('earn', $row['type']);
    }

    public function testBalanceAfterOnEachRowReplaysTheHistory(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();

        $ledger->credit($userId, 100, ['reference_type' => 'claim']);
        $ledger->credit($userId, 50, ['reference_type' => 'claim']);
        $ledger->debit($userId, 30, ['reference_type' => 'reward']);

        $rows = $this->pdo->query('SELECT points, balance_after FROM points_transactions ORDER BY id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        $running = 0;
        foreach ($rows as $row) {
            $running += (int) $row['points'];
            $this->assertSame(
                $running,
                (int) $row['balance_after'],
                'balance_after must be a faithful running total'
            );
        }
        $this->assertSame(120, $running);
    }

    public function testHistoryIsReturnedNewestFirst(): void
    {
        $userId = $this->makeUser();
        $ledger = $this->ledger();
        $ledger->credit($userId, 10, ['description' => 'first']);
        $ledger->credit($userId, 20, ['description' => 'second']);

        $history = $ledger->getHistory($userId, 10);

        $this->assertCount(2, $history);
        $this->assertSame('second', $history[0]['description']);
    }

    /**
     * Swap the suite onto a PDO that can be told to fail one statement, carrying
     * the identical fixture schema. Assertions read through the same handle the
     * service writes to, so they observe exactly what survived.
     */
    private function throwingPdo(): ThrowingPdo
    {
        $pdo = new ThrowingPdo('sqlite::memory:');
        $this->buildSchema($pdo);
        $this->pdo = $pdo;

        return $pdo;
    }
}
