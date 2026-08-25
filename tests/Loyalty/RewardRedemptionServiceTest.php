<?php

namespace Tests\Loyalty;

use RewardRedemptionService;
use TierService;

require_once __DIR__ . '/../../classes/RewardRedemptionService.php';
require_once __DIR__ . '/../../classes/TierService.php';

/**
 * Phase 5 — one redemption path, with the validations nobody was doing.
 *
 * The Phase 0 audit found two mutually invisible redemption stacks, and that
 * `max_per_user`, `start_date`/`end_date` and tier eligibility were "checked
 * nowhere in any implementation". This suite pins each of them.
 *
 * @see docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §15
 */
class RewardRedemptionServiceTest extends LoyaltyLedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TierService::clearCache();

        // reward_redemptions AFTER migration_2026-08-26_reward_redemption_integrity.sql
        $this->pdo->exec('DROP TABLE reward_redemptions');
        $this->pdo->exec(
            'CREATE TABLE reward_redemptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                reward_id INTEGER NOT NULL,
                line_account_id INTEGER DEFAULT NULL,
                points_used INTEGER NOT NULL DEFAULT 0,
                redemption_code TEXT DEFAULT NULL,
                idempotency_key TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                expires_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec('CREATE UNIQUE INDEX uniq_redemption_idempotency ON reward_redemptions (idempotency_key)');
        $this->pdo->exec('CREATE UNIQUE INDEX uniq_redemption_code ON reward_redemptions (redemption_code)');

        // The catalogue gains the columns the validations need.
        $this->pdo->exec('ALTER TABLE rewards ADD COLUMN required_tier TEXT DEFAULT NULL');
    }

    protected function tearDown(): void
    {
        TierService::clearCache();
        parent::tearDown();
    }

    private function service(?callable $clock = null): RewardRedemptionService
    {
        $service = new RewardRedemptionService($this->pdo, $this->lineAccountId);
        if ($clock !== null) {
            $service->setClock($clock);
        }

        return $service;
    }

    private function makeReward(array $overrides = []): int
    {
        $row = array_merge([
            'line_account_id' => $this->lineAccountId,
            'name' => 'ส่วนลด 50 บาท',
            'points_required' => 100,
            'stock' => -1,
            'max_per_user' => 0,
            'is_active' => 1,
            'start_date' => null,
            'end_date' => null,
            'required_tier' => null,
        ], $overrides);

        $columns = array_keys($row);
        $stmt = $this->pdo->prepare(
            'INSERT INTO rewards (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    private function fundedUser(int $points = 500): int
    {
        $userId = $this->makeUser();
        $this->loyalty()->addPoints($userId, $points, 'claim');

        return $userId;
    }

    private function redemptionCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM reward_redemptions')->fetchColumn();
    }

    private function rewardStock(int $rewardId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock FROM rewards WHERE id = ?');
        $stmt->execute([$rewardId]);

        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function testRedeemDebitsClaimsStockAndRecordsTheRedemption(): void
    {
        $userId = $this->fundedUser(500);
        $rewardId = $this->makeReward(['points_required' => 200, 'stock' => 5]);

        $result = $this->service()->redeem($userId, $rewardId);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['points_used']);
        $this->assertSame(300, $result['new_balance']);
        $this->assertNotEmpty($result['redemption_code']);
        $this->assertSame(4, $this->rewardStock($rewardId));
        $this->assertSame(1, $this->redemptionCount());
        $this->assertLedgerMatchesCache($userId, 'after redemption');
    }

    // -----------------------------------------------------------------------
    // The validations no implementation was doing
    // -----------------------------------------------------------------------

    public function testRewardIsNotRedeemableBeforeItsStartDate(): void
    {
        $userId = $this->fundedUser();
        $rewardId = $this->makeReward(['start_date' => '2026-09-01 00:00:00']);

        $result = $this->service(static fn (): string => '2026-08-26 10:00:00')->redeem($userId, $rewardId);

        $this->assertFalse($result['ok']);
        $this->assertSame(RewardRedemptionService::REASON_NOT_STARTED, $result['reason']);
        $this->assertSame(0, $this->redemptionCount());
    }

    public function testRewardIsNotRedeemableAfterItsEndDate(): void
    {
        $userId = $this->fundedUser();
        $rewardId = $this->makeReward(['end_date' => '2026-08-20']);

        $result = $this->service(static fn (): string => '2026-08-26 10:00:00')->redeem($userId, $rewardId);

        $this->assertFalse($result['ok']);
        $this->assertSame(RewardRedemptionService::REASON_EXPIRED, $result['reason']);
    }

    /** A date-only end_date means "through the end of that day". */
    public function testADateOnlyEndDateIsInclusiveOfThatWholeDay(): void
    {
        $userId = $this->fundedUser();
        $rewardId = $this->makeReward(['end_date' => '2026-08-26']);

        $result = $this->service(static fn (): string => '2026-08-26 23:00:00')->redeem($userId, $rewardId);

        $this->assertTrue($result['ok'], 'the last day of a campaign must still work');
    }

    public function testMaxPerUserIsEnforced(): void
    {
        $userId = $this->fundedUser(1000);
        $rewardId = $this->makeReward(['points_required' => 100, 'max_per_user' => 2]);
        $service = $this->service();

        $this->assertTrue($service->redeem($userId, $rewardId)['ok']);
        $this->assertTrue($service->redeem($userId, $rewardId)['ok']);

        $third = $service->redeem($userId, $rewardId);

        $this->assertFalse($third['ok']);
        $this->assertSame(RewardRedemptionService::REASON_LIMIT_REACHED, $third['reason']);
        $this->assertSame(2, $this->redemptionCount());
        $this->assertSame(800, $this->loyalty()->getUserPoints($userId)['available_points']);
    }

    public function testMaxPerUserOfZeroMeansUnlimited(): void
    {
        $userId = $this->fundedUser(1000);
        $rewardId = $this->makeReward(['points_required' => 100, 'max_per_user' => 0]);
        $service = $this->service();

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($service->redeem($userId, $rewardId)['ok'], "redemption #{$i}");
        }

        $this->assertSame(5, $this->redemptionCount());
    }

    /** A cancelled redemption must not count against the per-user limit. */
    public function testCancelledRedemptionsDoNotCountTowardTheLimit(): void
    {
        $userId = $this->fundedUser(1000);
        $rewardId = $this->makeReward(['points_required' => 100, 'max_per_user' => 1]);
        $service = $this->service();

        $first = $service->redeem($userId, $rewardId);
        $this->assertTrue($first['ok']);

        $this->pdo->prepare("UPDATE reward_redemptions SET status = 'cancelled' WHERE id = ?")
            ->execute([$first['redemption_id']]);

        $this->assertTrue($service->redeem($userId, $rewardId)['ok'], 'the slot was freed by the cancellation');
    }

    public function testTierGatedRewardRefusesALowerTier(): void
    {
        $this->pdo->exec(
            'CREATE TABLE tier_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER DEFAULT NULL,
                name TEXT NOT NULL,
                min_points INTEGER NOT NULL DEFAULT 0,
                multiplier REAL DEFAULT 1.00,
                earn_multiplier REAL NOT NULL DEFAULT 1.00,
                discount_percent REAL NOT NULL DEFAULT 0.00,
                badge_color TEXT DEFAULT NULL
            )'
        );
        $this->pdo->exec(
            "INSERT INTO tier_settings (line_account_id, name, min_points, badge_color)
             VALUES ({$this->lineAccountId}, 'Bronze', 0, '#000'),
                    ({$this->lineAccountId}, 'Gold', 5000, '#FFD700')"
        );
        TierService::clearCache();

        $bronze = $this->fundedUser(500);
        $rewardId = $this->makeReward(['points_required' => 100, 'required_tier' => 'gold']);

        $refused = $this->service()->redeem($bronze, $rewardId);
        $this->assertFalse($refused['ok']);
        $this->assertSame(RewardRedemptionService::REASON_TIER_LOCKED, $refused['reason']);

        $gold = $this->fundedUser(6000);
        $this->assertTrue($this->service()->redeem($gold, $rewardId)['ok'], 'Gold may redeem a Gold reward');
    }

    public function testRewardBelongingToAnotherOaIsNotRedeemable(): void
    {
        $userId = $this->fundedUser();
        $foreignReward = $this->makeReward(['line_account_id' => $this->lineAccountId + 99]);

        $result = $this->service()->redeem($userId, $foreignReward);

        $this->assertFalse($result['ok']);
        $this->assertSame(RewardRedemptionService::REASON_NOT_FOUND, $result['reason']);
    }

    // -----------------------------------------------------------------------
    // Concurrency and replay
    // -----------------------------------------------------------------------

    public function testTheLastItemCanOnlyBeRedeemedOnce(): void
    {
        $first = $this->fundedUser();
        $second = $this->fundedUser();
        $rewardId = $this->makeReward(['points_required' => 100, 'stock' => 1]);
        $service = $this->service();

        $this->assertTrue($service->redeem($first, $rewardId)['ok']);

        $loser = $service->redeem($second, $rewardId);
        $this->assertFalse($loser['ok']);
        $this->assertSame(RewardRedemptionService::REASON_OUT_OF_STOCK, $loser['reason']);
        $this->assertSame(0, $this->rewardStock($rewardId), 'stock must never go negative');
        $this->assertSame(500, $this->loyalty()->getUserPoints($second)['available_points'], 'the loser was not charged');
    }

    public function testAReplayedRedeemReturnsTheOriginalRedemption(): void
    {
        $userId = $this->fundedUser(1000);
        $rewardId = $this->makeReward(['points_required' => 100, 'stock' => 5]);
        $service = $this->service();
        $options = ['idempotency_key' => 'redeem:la3:u1:r1:1756200000'];

        $first = $service->redeem($userId, $rewardId, $options);
        $second = $service->redeem($userId, $rewardId, $options);

        $this->assertTrue($first['ok']);
        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['redemption_id'], $second['redemption_id']);

        $this->assertSame(1, $this->redemptionCount(), 'one redemption, not two');
        $this->assertSame(900, $this->loyalty()->getUserPoints($userId)['available_points'], 'charged once');
        $this->assertSame(4, $this->rewardStock($rewardId), 'one unit of stock, not two');
    }

    public function testAFailedDebitReleasesTheClaimedStock(): void
    {
        $userId = $this->fundedUser(50);
        $rewardId = $this->makeReward(['points_required' => 200, 'stock' => 3]);

        $result = $this->service()->redeem($userId, $rewardId);

        $this->assertFalse($result['ok']);
        $this->assertSame(RewardRedemptionService::REASON_INSUFFICIENT, $result['reason']);
        $this->assertSame(3, $this->rewardStock($rewardId), 'the stock claim was rolled back');
        $this->assertSame(0, $this->redemptionCount());
        $this->assertFalse($this->pdo->inTransaction());
    }

    // -----------------------------------------------------------------------
    // quote()
    // -----------------------------------------------------------------------

    public function testQuoteReportsEligibilityWithoutTakingAnything(): void
    {
        $userId = $this->fundedUser(150);
        $rewardId = $this->makeReward(['points_required' => 100, 'stock' => 2, 'max_per_user' => 3]);

        $quote = $this->service()->quote($userId, $rewardId);

        $this->assertTrue($quote['ok']);
        $this->assertSame(100, $quote['points_required']);
        $this->assertSame(150, $quote['available_points']);
        $this->assertSame(3, $quote['remaining_for_user']);
        $this->assertSame(2, $this->rewardStock($rewardId), 'quote must not claim stock');
        $this->assertSame(0, $this->redemptionCount());
    }

    public function testQuoteExplainsWhyARewardIsUnavailable(): void
    {
        $userId = $this->fundedUser(10);
        $rewardId = $this->makeReward(['points_required' => 100]);

        $quote = $this->service()->quote($userId, $rewardId);

        $this->assertFalse($quote['ok']);
        $this->assertSame(RewardRedemptionService::REASON_INSUFFICIENT, $quote['reason']);
        $this->assertSame('แต้มไม่เพียงพอ', $quote['message']);
    }

    // -----------------------------------------------------------------------
    // Both endpoints share this implementation
    // -----------------------------------------------------------------------

    public function testTheFacadeDelegatesToThisService(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/classes/LoyaltyPoints.php');
        $this->assertStringContainsString('new RewardRedemptionService(', $source);
    }

    public function testTheLegacyPointsApiDelegatesToThisService(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/api/points.php');

        $this->assertStringContainsString('new RewardRedemptionService(', $source);
        $this->assertStringContainsString(
            'bootstrap/route_by_account.php',
            $source,
            'this endpoint was the only points API without tenant routing'
        );
    }

    /** The facade's contract is unchanged for its existing callers. */
    public function testTheFacadeStillReturnsItsHistoricalShape(): void
    {
        $userId = $this->fundedUser(500);
        $rewardId = $this->makeReward(['points_required' => 100]);

        $result = $this->loyalty()->redeemReward($userId, $rewardId);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('redemption_code', $result);
        $this->assertArrayHasKey('redemption_id', $result);
        $this->assertArrayHasKey('reward', $result);
        $this->assertSame('แลกรางวัลสำเร็จ!', $result['message']);
    }
}
