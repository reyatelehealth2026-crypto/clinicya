<?php

namespace Tests\Loyalty;

use LoyaltyLedgerService;
use PDO;
use TierService;

require_once __DIR__ . '/../../classes/TierService.php';

/**
 * LoyaltyPoints as a compatibility facade.
 *
 * ~15 call sites across POS, the QR claim flow, the LINE webhook, BusinessBot,
 * the admin pages and the mini app still call this class. Batch 1 rewired its
 * internals onto LoyaltyLedgerService; this suite pins the contract those
 * callers depend on so the rewire stays invisible to them.
 *
 * @see docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md
 */
class LoyaltyPointsFacadeRegressionTest extends LoyaltyLedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The tier cache is static and would leak between tests.
        TierService::clearCache();
    }

    // -----------------------------------------------------------------------
    // The contract callers rely on
    // -----------------------------------------------------------------------

    public function testGetUserPointsKeepsItsHistoricalReturnShape(): void
    {
        $userId = $this->makeUser();
        $this->loyalty()->addPoints($userId, 300, 'claim', 7, 'ทดสอบ');

        $points = $this->loyalty()->getUserPoints($userId);

        $this->assertSame(
            ['total_points', 'available_points', 'used_points'],
            array_keys($points),
            'callers index these three keys directly'
        );
        foreach ($points as $key => $value) {
            $this->assertIsInt($value, "{$key} must be an int");
        }
        $this->assertSame(300, $points['available_points']);
    }

    public function testAddPointsReturnsTrueAndWritesAnEarnRow(): void
    {
        $userId = $this->makeUser();

        $this->assertTrue($this->loyalty()->addPoints($userId, 120, 'order', 55, 'จากออเดอร์'));

        $row = $this->pdo->query('SELECT * FROM points_transactions ORDER BY id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('earn', $row['type']);
        $this->assertSame(120, (int) $row['points']);
        $this->assertSame('order', $row['reference_type']);
        $this->assertSame(55, (int) $row['reference_id']);
        $this->assertSame('จากออเดอร์', $row['description']);
        $this->assertNotNull($row['expires_at'], 'points_expiry_days=365 in the fixture');
    }

    public function testAddPointsRejectsNonPositiveAmounts(): void
    {
        $userId = $this->makeUser();

        $this->assertFalse($this->loyalty()->addPoints($userId, 0));
        $this->assertFalse($this->loyalty()->addPoints($userId, -10));
        $this->assertSame(0, $this->ledgerRowCount($userId));
    }

    public function testDeductPointsReturnsFalseWhenTheBalanceIsShort(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();
        $loyalty->addPoints($userId, 100, 'claim');

        $this->assertFalse($loyalty->deductPoints($userId, 101, 'reward', 1));
        $this->assertSame(100, $loyalty->getUserPoints($userId)['available_points']);
        $this->assertLedgerMatchesCache($userId, 'after a declined deduct');
    }

    public function testDeductPointsWritesARedeemRow(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();
        $loyalty->addPoints($userId, 200, 'claim');

        $this->assertTrue($loyalty->deductPoints($userId, 75, 'reward', 3, 'แลกของ'));

        $row = $this->pdo->query('SELECT * FROM points_transactions ORDER BY id DESC LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('redeem', $row['type']);
        $this->assertSame(-75, (int) $row['points']);
        $this->assertSame(125, $loyalty->getUserPoints($userId)['available_points']);
    }

    public function testAwardPointsForOrderUsesTheConfiguredRate(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();

        // fixture rate is 0.1 points per baht
        $this->assertSame(45, $loyalty->calculatePoints(450));
        $this->assertTrue($loyalty->awardPointsForOrder($userId, 91, 450));
        $this->assertSame(45, $loyalty->getUserPoints($userId)['available_points']);
    }

    public function testTheTierIsStillRefreshedAfterAMovement(): void
    {
        $userId = $this->makeUser();

        $this->loyalty()->addPoints($userId, 1200, 'claim');

        $this->assertSame(
            'silver',
            $this->userRow($userId)['member_tier'],
            'default ladder puts 1200 points in Silver'
        );
    }

    public function testTheLedgerIsReachableForBatchTwoCallers(): void
    {
        $this->assertInstanceOf(LoyaltyLedgerService::class, $this->loyalty()->ledger());
    }

    /**
     * The Batch 2 seam: a caller can pass an idempotency key through the old
     * signature without any other change.
     */
    public function testAddPointsAcceptsAnIdempotencyKeyThroughTheFacade(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();
        $options = ['idempotency_key' => 'la:3:member-register:881:welcome-bonus'];

        $this->assertTrue($loyalty->addPoints($userId, 50, 'welcome', null, 'โบนัสต้อนรับ', $options));
        $this->assertTrue($loyalty->addPoints($userId, 50, 'welcome', null, 'โบนัสต้อนรับ', $options));

        $this->assertSame(1, $this->ledgerRowCount($userId), 'the welcome bonus is awarded exactly once');
        $this->assertSame(50, $loyalty->getUserPoints($userId)['available_points']);
    }

    // -----------------------------------------------------------------------
    // Reward redemption
    // -----------------------------------------------------------------------

    public function testRedeemRewardDebitsPointsDecrementsStockAndRecordsARedemption(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();
        $loyalty->addPoints($userId, 500, 'claim');
        $rewardId = $this->makeReward(['points_required' => 200, 'stock' => 5]);

        $result = $loyalty->redeemReward($userId, $rewardId);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['redemption_code']);
        $this->assertSame(300, $loyalty->getUserPoints($userId)['available_points']);
        $this->assertSame(4, $this->rewardStock($rewardId));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM reward_redemptions')->fetchColumn());
        $this->assertLedgerMatchesCache($userId, 'after a redemption');
    }

    /**
     * The oversell race. Stock is now claimed by a guarded UPDATE whose rowCount
     * decides the outcome, rather than a check-then-act read — so the second
     * redeemer is refused instead of both winning the last item.
     */
    public function testTheLastItemCanOnlyBeRedeemedOnce(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $loyalty = $this->loyalty();
        $loyalty->addPoints($first, 500, 'claim');
        $loyalty->addPoints($second, 500, 'claim');
        $rewardId = $this->makeReward(['points_required' => 100, 'stock' => 1]);

        $a = $loyalty->redeemReward($first, $rewardId);
        $b = $loyalty->redeemReward($second, $rewardId);

        $this->assertTrue($a['success']);
        $this->assertFalse($b['success']);
        $this->assertSame('รางวัลหมดแล้ว', $b['message']);
        $this->assertSame(0, $this->rewardStock($rewardId), 'stock must never go negative');
        $this->assertSame(500, $loyalty->getUserPoints($second)['available_points'], 'the loser was not charged');
    }

    /**
     * If the debit fails the stock claim must be given back — otherwise every
     * failed redemption would quietly destroy a unit of inventory.
     */
    public function testAFailedDebitReleasesTheClaimedStock(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();
        $loyalty->addPoints($userId, 50, 'claim');
        $rewardId = $this->makeReward(['points_required' => 200, 'stock' => 3]);

        $result = $loyalty->redeemReward($userId, $rewardId);

        $this->assertFalse($result['success']);
        $this->assertSame('แต้มไม่เพียงพอ', $result['message']);
        $this->assertSame(3, $this->rewardStock($rewardId), 'the stock claim was rolled back');
        $this->assertSame(50, $loyalty->getUserPoints($userId)['available_points']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM reward_redemptions')->fetchColumn());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testUnlimitedStockIsNotDecremented(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();
        $loyalty->addPoints($userId, 500, 'claim');
        $rewardId = $this->makeReward(['points_required' => 100, 'stock' => -1]);

        $this->assertTrue($loyalty->redeemReward($userId, $rewardId)['success']);
        $this->assertSame(-1, $this->rewardStock($rewardId), '-1 means unlimited and must stay -1');
    }

    public function testAnInactiveRewardCannotBeRedeemed(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();
        $loyalty->addPoints($userId, 500, 'claim');
        $rewardId = $this->makeReward(['points_required' => 100, 'is_active' => 0]);

        $result = $loyalty->redeemReward($userId, $rewardId);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $loyalty->getUserPoints($userId)['available_points']);
    }

    public function testAnOutOfStockRewardCannotBeRedeemed(): void
    {
        $userId = $this->makeUser();
        $loyalty = $this->loyalty();
        $loyalty->addPoints($userId, 500, 'claim');
        $rewardId = $this->makeReward(['points_required' => 100, 'stock' => 0]);

        $this->assertFalse($loyalty->redeemReward($userId, $rewardId)['success']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM reward_redemptions')->fetchColumn());
    }

    public function testAMissingRewardIsReportedNotFatal(): void
    {
        $result = $this->loyalty()->redeemReward($this->makeUser(), 4242);

        $this->assertFalse($result['success']);
        $this->assertSame('ไม่พบรางวัล', $result['message']);
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
        ], $overrides);

        $columns = array_keys($row);
        $stmt = $this->pdo->prepare(
            'INSERT INTO rewards (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    private function rewardStock(int $rewardId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock FROM rewards WHERE id = ?');
        $stmt->execute([$rewardId]);

        return (int) $stmt->fetchColumn();
    }
}
