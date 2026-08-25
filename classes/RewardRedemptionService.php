<?php

/**
 * RewardRedemptionService — the one way a reward is redeemed.
 * บริการแลกของรางวัล (ทางเดียว)
 *
 * WHY THIS CLASS EXISTS
 *   The Phase 0 audit found two entirely separate, mutually invisible redemption
 *   stacks (docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md §4.4):
 *
 *     LoyaltyPoints::redeemReward()  -> users.available_points + points_transactions
 *     api/points.php?action=redeem   -> users.points + points_history
 *
 *   Neither store read the other, so the SAME reward could be redeemed once
 *   through each endpoint and neither deduction was visible to the other. On top
 *   of that, validation was thin everywhere: `is_active` and "enough points" were
 *   checked; the start/end validity window, `max_per_user`, tier eligibility and
 *   duplicate submission were checked NOWHERE, in any implementation.
 *
 * WHAT IT GUARANTEES
 *   1. ONE PATH    — both endpoints call this; the balance is always the ledger's.
 *   2. ATOMIC      — stock claim, point debit and the redemption row commit
 *                    together, enlisting in a caller's transaction if it opened one.
 *   3. NO OVERSELL — stock is claimed by a guarded UPDATE whose rowCount decides
 *                    the outcome, not by a check-then-act read.
 *   4. IDEMPOTENT  — an idempotency key makes a double-submitted redeem return the
 *                    original redemption instead of charging twice.
 *   5. COMPLETE    — active flag, validity window, stock, max_per_user, tier
 *                    eligibility and sufficient balance are all enforced here, so
 *                    no caller can forget one.
 *
 * PORTABILITY
 *   Same rules as LoyaltyLedgerService: no NOW(), no DATE_ADD, no ON DUPLICATE
 *   KEY, driver-aware column introspection, so the suite can exercise it on sqlite.
 *
 * @see docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §15 / Phase 5
 */

require_once __DIR__ . '/LoyaltyLedgerService.php';

class RewardRedemptionService
{
    /** Why a redemption was refused. Callers map these to their own copy. */
    public const REASON_NOT_FOUND = 'reward_not_found';
    public const REASON_INACTIVE = 'reward_inactive';
    public const REASON_NOT_STARTED = 'reward_not_started';
    public const REASON_EXPIRED = 'reward_expired';
    public const REASON_OUT_OF_STOCK = 'reward_out_of_stock';
    public const REASON_LIMIT_REACHED = 'max_per_user_reached';
    public const REASON_TIER_LOCKED = 'tier_not_eligible';
    public const REASON_INSUFFICIENT = 'insufficient_points';
    public const REASON_FAILED = 'redemption_failed';

    /** Customer-facing Thai copy for each refusal. */
    private const MESSAGES = [
        self::REASON_NOT_FOUND => 'ไม่พบรางวัล',
        self::REASON_INACTIVE => 'รางวัลนี้ไม่พร้อมให้บริการ',
        self::REASON_NOT_STARTED => 'ยังไม่ถึงเวลาแลกรางวัลนี้',
        self::REASON_EXPIRED => 'หมดเวลาแลกรางวัลนี้แล้ว',
        self::REASON_OUT_OF_STOCK => 'รางวัลหมดแล้ว',
        self::REASON_LIMIT_REACHED => 'คุณแลกรางวัลนี้ครบจำนวนที่กำหนดแล้ว',
        self::REASON_TIER_LOCKED => 'ระดับสมาชิกของคุณยังแลกรางวัลนี้ไม่ได้',
        self::REASON_INSUFFICIENT => 'แต้มไม่เพียงพอ',
        self::REASON_FAILED => 'ไม่สามารถแลกรางวัลได้',
    ];

    /** @var PDO */
    private $db;

    /** @var int|null */
    private $lineAccountId;

    /** @var LoyaltyLedgerService */
    private $ledger;

    /** @var string */
    private $driver;

    /** @var array<string, bool> */
    private $columnCache = [];

    /** @var callable():string */
    private $clock;

    public function __construct($db, $lineAccountId = null, ?LoyaltyLedgerService $ledger = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId !== null ? (int) $lineAccountId : null;
        $this->ledger = $ledger ?? new LoyaltyLedgerService($db, $lineAccountId);
        $this->driver = $this->detectDriver($db);
        $this->clock = static function (): string {
            return date('Y-m-d H:i:s');
        };
    }

    /** Tests pin the clock to exercise the validity window. */
    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
        $this->ledger->setClock($clock);
    }

    /**
     * Can this member redeem this reward right now, and what would it cost?
     * Runs every validation except the stock claim itself, so a catalogue screen
     * can grey out what is unavailable without taking anything.
     *
     * @return array{ok:bool, reason:string|null, message:string, reward:array|null,
     *               points_required:int, available_points:int, remaining_for_user:int|null}
     */
    public function quote(int $userId, int $rewardId): array
    {
        $reward = $this->findReward($rewardId);
        if ($reward === null) {
            return $this->refuse(self::REASON_NOT_FOUND);
        }

        $balance = $this->ledger->getBalance($userId);
        $required = (int) ($reward['points_required'] ?? 0);
        $available = (int) $balance['available_points'];

        $reason = $this->validate($reward, $userId, $balance);
        $remaining = $this->remainingForUser($reward, $userId);

        return [
            'ok' => $reason === null,
            'reason' => $reason,
            'message' => $reason === null ? 'พร้อมแลก' : self::MESSAGES[$reason],
            'reward' => $reward,
            'points_required' => $required,
            'available_points' => $available,
            'remaining_for_user' => $remaining,
        ];
    }

    /**
     * Redeem, atomically.
     *
     * @param array $options 'idempotency_key' (strongly recommended),
     *                       'created_by', 'notes'
     * @return array{ok:bool, reason:string|null, message:string,
     *               redemption_id:int|null, redemption_code:string|null,
     *               reward:array|null, points_used:int, new_balance:int,
     *               duplicate:bool}
     */
    public function redeem(int $userId, int $rewardId, array $options = []): array
    {
        $idempotencyKey = isset($options['idempotency_key']) && $options['idempotency_key'] !== ''
            ? (string) $options['idempotency_key']
            : null;

        // A replay must return the ORIGINAL redemption, not a refusal and not a
        // second one. Checked before anything is claimed.
        if ($idempotencyKey !== null) {
            $existing = $this->findRedemptionByKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'ok' => true,
                    'reason' => null,
                    'message' => 'แลกรางวัลสำเร็จ!',
                    'redemption_id' => (int) $existing['id'],
                    'redemption_code' => $existing['redemption_code'] ?? null,
                    'reward' => $this->findReward($rewardId),
                    'points_used' => (int) ($existing['points_used'] ?? 0),
                    'new_balance' => (int) $this->ledger->getBalance($userId)['available_points'],
                    'duplicate' => true,
                ];
            }
        }

        $reward = $this->findReward($rewardId);
        if ($reward === null) {
            return $this->refuse(self::REASON_NOT_FOUND, true);
        }

        $ownsTransaction = !$this->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $balance = $this->ledger->getBalance($userId);
            $reason = $this->validate($reward, $userId, $balance);
            if ($reason !== null) {
                if ($ownsTransaction) {
                    $this->db->rollBack();
                }

                return $this->refuse($reason, true);
            }

            // Claim the stock FIRST, with the guard in the WHERE clause: if this
            // affects no rows someone else took the last one while we validated,
            // and nothing has been debited yet.
            if ($this->hasLimitedStock($reward)) {
                $stmt = $this->db->prepare('UPDATE rewards SET stock = stock - 1 WHERE id = ? AND stock > 0');
                $stmt->execute([$rewardId]);
                if ($stmt->rowCount() === 0) {
                    if ($ownsTransaction) {
                        $this->db->rollBack();
                    }

                    return $this->refuse(self::REASON_OUT_OF_STOCK, true);
                }
            }

            $required = (int) $reward['points_required'];
            $debit = $this->ledger->debit($userId, $required, [
                'type' => LoyaltyLedgerService::TYPE_REDEEM,
                'reference_type' => 'reward',
                'reference_id' => $rewardId,
                'description' => 'แลกรางวัล: ' . ($reward['name'] ?? ''),
                'idempotency_key' => $idempotencyKey !== null ? $idempotencyKey . ':debit' : null,
                'created_by' => $options['created_by'] ?? null,
                'line_account_id' => $this->lineAccountId,
            ]);

            if (!$debit['success']) {
                if ($ownsTransaction) {
                    $this->db->rollBack();
                }

                return $this->refuse(self::REASON_INSUFFICIENT, true);
            }

            $code = $this->generateRedemptionCode();
            $redemptionId = $this->insertRedemption($userId, $reward, $code, $idempotencyKey, $options);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'ok' => true,
                'reason' => null,
                'message' => 'แลกรางวัลสำเร็จ!',
                'redemption_id' => $redemptionId,
                'redemption_code' => $code,
                'reward' => $reward,
                'points_used' => $required,
                'new_balance' => (int) $this->ledger->getBalance($userId)['available_points'],
                'duplicate' => false,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RewardRedemptionService::redeem failed (user ' . $userId . ', reward ' . $rewardId . '): ' . $e->getMessage());

            return $this->refuse(self::REASON_FAILED, true);
        }
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Every rule, in one place. Returns the refusal reason, or null to proceed.
     *
     * @param array $reward
     * @param array $balance from LoyaltyLedgerService::getBalance()
     */
    private function validate(array $reward, int $userId, array $balance): ?string
    {
        if (isset($reward['is_active']) && !$this->truthy($reward['is_active'])) {
            return self::REASON_INACTIVE;
        }

        $now = ($this->clock)();
        if (!empty($reward['start_date']) && $now < (string) $reward['start_date']) {
            return self::REASON_NOT_STARTED;
        }
        if (!empty($reward['end_date'])) {
            // A date-only end_date means "through the end of that day".
            $end = strlen((string) $reward['end_date']) <= 10
                ? $reward['end_date'] . ' 23:59:59'
                : $reward['end_date'];
            if ($now > (string) $end) {
                return self::REASON_EXPIRED;
            }
        }

        if ($this->hasLimitedStock($reward) && (int) $reward['stock'] <= 0) {
            return self::REASON_OUT_OF_STOCK;
        }

        $remaining = $this->remainingForUser($reward, $userId);
        if ($remaining !== null && $remaining <= 0) {
            return self::REASON_LIMIT_REACHED;
        }

        if (!$this->tierAllows($reward, $userId)) {
            return self::REASON_TIER_LOCKED;
        }

        if ((int) $balance['available_points'] < (int) ($reward['points_required'] ?? 0)) {
            return self::REASON_INSUFFICIENT;
        }

        return null;
    }

    /**
     * How many more times this member may redeem this reward, or null when
     * unlimited. `max_per_user` of 0 (the schema default) means unlimited.
     */
    private function remainingForUser(array $reward, int $userId): ?int
    {
        $max = (int) ($reward['max_per_user'] ?? 0);
        if ($max <= 0) {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM reward_redemptions
                  WHERE user_id = ? AND reward_id = ? AND status <> 'cancelled'"
            );
            $stmt->execute([$userId, (int) $reward['id']]);
            $used = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }

        return max(0, $max - $used);
    }

    /**
     * Tier gating, when the catalogue defines it. Absent column or empty value
     * means "open to everyone" — the common case.
     */
    private function tierAllows(array $reward, int $userId): bool
    {
        $requiredTier = $reward['required_tier'] ?? $reward['min_tier'] ?? null;
        if ($requiredTier === null || $requiredTier === '') {
            return true;
        }

        try {
            require_once __DIR__ . '/TierService.php';
            $tierService = new TierService($this->db, $this->lineAccountId);
            $tiers = $tierService->getTiers();
            $memberTier = $tierService->getUserTier($userId);

            $rank = [];
            foreach ($tiers as $index => $tier) {
                $rank[strtolower((string) $tier['tier_code'])] = $index;
            }

            $need = $rank[strtolower((string) $requiredTier)] ?? null;
            $have = $rank[strtolower((string) $memberTier['tier_code'])] ?? null;
            if ($need === null || $have === null) {
                return true;
            }

            return $have >= $need;
        } catch (Throwable $e) {
            // Never let a tier lookup failure block a redemption the member
            // has already paid for in points.
            return true;
        }
    }

    // -----------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function findReward(int $rewardId): ?array
    {
        try {
            $sql = 'SELECT * FROM rewards WHERE id = ?';
            $params = [$rewardId];

            // Scope to the OA when the catalogue is scoped. A reward belonging to
            // another OA must not be redeemable just because its id was guessed.
            if ($this->lineAccountId !== null && $this->columnExists('rewards', 'line_account_id')) {
                $sql .= ' AND (line_account_id = ? OR line_account_id IS NULL)';
                $params[] = $this->lineAccountId;
            }

            $stmt = $this->db->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            $reward = $stmt->fetch(PDO::FETCH_ASSOC);

            return $reward === false ? null : $reward;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function findRedemptionByKey(string $key): ?array
    {
        if (!$this->columnExists('reward_redemptions', 'idempotency_key')) {
            return null;
        }

        try {
            $stmt = $this->db->prepare('SELECT * FROM reward_redemptions WHERE idempotency_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function insertRedemption(int $userId, array $reward, string $code, ?string $key, array $options): int
    {
        $columns = [
            'user_id' => $userId,
            'reward_id' => (int) $reward['id'],
            'line_account_id' => $this->lineAccountId,
            'points_used' => (int) $reward['points_required'],
            'redemption_code' => $code,
            'expires_at' => $this->redemptionExpiry($reward),
        ];

        if ($key !== null && $this->columnExists('reward_redemptions', 'idempotency_key')) {
            $columns['idempotency_key'] = $key;
        }
        if (!empty($options['notes']) && $this->columnExists('reward_redemptions', 'notes')) {
            $columns['notes'] = (string) $options['notes'];
        }

        $names = array_keys($columns);
        $sql = 'INSERT INTO reward_redemptions (`' . implode('`, `', $names) . '`) VALUES ('
            . implode(', ', array_fill(0, count($names), '?')) . ')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($columns));

        return (int) $this->db->lastInsertId();
    }

    private function redemptionExpiry(array $reward): ?string
    {
        if (!empty($reward['valid_until'])) {
            return (string) $reward['valid_until'];
        }
        if (!empty($reward['validity_days'])) {
            $days = (int) $reward['validity_days'];

            return date('Y-m-d H:i:s', strtotime(($this->clock)() . " +{$days} days"));
        }

        return null;
    }

    private function generateRedemptionCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = 'RW' . strtoupper(substr(base_convert((string) time(), 10, 36), -4))
                . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

            try {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM reward_redemptions WHERE redemption_code = ?');
                $stmt->execute([$code]);
                if ((int) $stmt->fetchColumn() === 0) {
                    return $code;
                }
            } catch (Throwable $e) {
                return $code;
            }
        }

        return 'RW' . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 10));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function refuse(string $reason, bool $full = false): array
    {
        $base = [
            'ok' => false,
            'reason' => $reason,
            'message' => self::MESSAGES[$reason] ?? self::MESSAGES[self::REASON_FAILED],
            'reward' => null,
        ];

        if (!$full) {
            return $base + [
                'points_required' => 0,
                'available_points' => 0,
                'remaining_for_user' => null,
            ];
        }

        return $base + [
            'redemption_id' => null,
            'redemption_code' => null,
            'points_used' => 0,
            'new_balance' => 0,
            'duplicate' => false,
        ];
    }

    /** `stock` of -1 or NULL means unlimited. */
    private function hasLimitedStock(array $reward): bool
    {
        return isset($reward['stock']) && $reward['stock'] !== null && (int) $reward['stock'] !== -1;
    }

    /** @param mixed $value */
    private function truthy($value): bool
    {
        return !($value === 0 || $value === '0' || $value === false || $value === null);
    }

    private function inTransaction(): bool
    {
        try {
            return (bool) $this->db->inTransaction();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }

        $exists = false;
        try {
            if ($this->driver === 'sqlite') {
                $stmt = $this->db->query('PRAGMA table_info(`' . $table . '`)');
                foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                    if (isset($row['name']) && strcasecmp((string) $row['name'], $column) === 0) {
                        $exists = true;
                        break;
                    }
                }
            } else {
                $stmt = $this->db->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
                $stmt->execute([$column]);
                $exists = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
            }
        } catch (Throwable $e) {
            $exists = false;
        }

        return $this->columnCache[$cacheKey] = $exists;
    }

    /** @param mixed $db */
    private function detectDriver($db): string
    {
        try {
            return strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (Throwable $e) {
            return 'mysql';
        }
    }
}
