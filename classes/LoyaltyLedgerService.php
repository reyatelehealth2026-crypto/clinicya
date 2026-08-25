<?php

/**
 * LoyaltyLedgerService — the canonical loyalty point ledger.
 * บริการบัญชีแต้มสะสม (แหล่งข้อมูลจริงหนึ่งเดียว)
 *
 * INVARIANT
 *   Every point movement is exactly one immutable row in `points_transactions`.
 *   `users.total_points / available_points / used_points` are DERIVED CACHES,
 *   recomputed from the ledger inside the same database transaction as the row
 *   that changed them. After any successful call here:
 *
 *       SUM(points_transactions.points) WHERE user_id = N  ==  users.available_points
 *
 * WHY THIS CLASS EXISTS
 *   The Phase 0 audit (docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md)
 *   found 26 point-writing code paths across three incompatible storage styles,
 *   none of them transactional and only three of them idempotent. This service is
 *   the single writer they are being migrated onto, one caller at a time
 *   (Batch 2). It deliberately does NOT decide HOW MANY points to award — that is
 *   LoyaltyRuleEngine's job in Phase 4 — only that the movement is recorded once,
 *   atomically, and explicably.
 *
 * WHAT IT GUARANTEES
 *   1. ATOMIC    — lock, validate, insert, recompute all happen in one DB
 *                  transaction. It enlists in a caller's open transaction rather
 *                  than opening a nested one (POSService and api/points-claim.php
 *                  already open their own).
 *   2. IDEMPOTENT — an `idempotency_key` makes a replayed webhook, a retried cron
 *                  job or a double-clicked button a no-op that returns the
 *                  ORIGINAL transaction instead of awarding twice.
 *   3. HONEST    — getBalance() distinguishes "the ledger has no rows for this
 *                  member" from "the ledger nets to zero". Only the first is
 *                  allowed to fall back to the legacy `users` columns. Conflating
 *                  the two is the bug described in the plan at §8.
 *   4. EXPLICABLE — every row can carry a JSON `metadata` rule breakdown and a
 *                  `created_by` actor so support can answer "ทำไมได้เท่านี้".
 *
 * PORTABILITY
 *   Runs on MySQL/MariaDB in production and on sqlite in tests. It therefore
 *   avoids MySQL-only syntax: no NOW(), no DATE_ADD(), no ON DUPLICATE KEY, no
 *   INSERT IGNORE, and `FOR UPDATE` / column introspection go through
 *   driver-aware helpers. Timestamps and expiry dates are computed in PHP
 *   (Asia/Bangkok) and bound as parameters.
 *
 * @see docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md — Phase 1 / Batch 1
 * @see database/migration_2026-08-26_loyalty_ledger_idempotency.sql
 */
class LoyaltyLedgerService
{
    /** Ledger movement kinds. Mirror the `points_transactions`.`type` ENUM. */
    public const TYPE_EARN = 'earn';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUST = 'adjust';
    public const TYPE_EXPIRE = 'expire';
    public const TYPE_REVERSE = 'reverse';
    public const TYPE_MIGRATION = 'migration';

    /** Credit kinds — must carry a positive `points` value. */
    public const CREDIT_TYPES = [
        self::TYPE_EARN,
        self::TYPE_BONUS,
        self::TYPE_REFUND,
        self::TYPE_ADJUST,
        self::TYPE_MIGRATION,
    ];

    /** Debit kinds — stored with a negative `points` value. */
    public const DEBIT_TYPES = [
        self::TYPE_REDEEM,
        self::TYPE_EXPIRE,
        self::TYPE_REVERSE,
        self::TYPE_ADJUST,
    ];

    /**
     * Where the balance returned by getBalance() actually came from.
     *
     * LEDGER       — points_transactions has rows; it is authoritative.
     * LEGACY_CACHE — points_transactions has NO rows for this member, so the
     *                pre-ledger `users` columns were used. Transitional only:
     *                this disappears once Batch 2 routes every writer here.
     * EMPTY        — no ledger rows and no legacy balance. A genuine zero.
     */
    public const SOURCE_LEDGER = 'ledger';
    public const SOURCE_LEGACY_CACHE = 'legacy_cache';
    public const SOURCE_EMPTY = 'empty';

    /** Reasons a credit/debit can decline without throwing. */
    public const REASON_INVALID_POINTS = 'invalid_points';
    public const REASON_USER_NOT_FOUND = 'user_not_found';
    public const REASON_INSUFFICIENT_POINTS = 'insufficient_points';

    /** @var PDO */
    private $db;

    /** @var int|null LINE OA scope stamped onto rows this service writes. */
    private $lineAccountId;

    /** @var string PDO driver name, lowercased ('mysql' | 'sqlite' | ...). */
    private $driver;

    /** @var array<string, bool> memoised column-existence probes, keyed "table.column". */
    private $columnCache = [];

    /** @var callable():string returns 'Y-m-d H:i:s' in Asia/Bangkok. Injectable for tests. */
    private $clock;

    /**
     * @param PDO      $db            tenant connection (Database::getInstance()->getConnection())
     * @param int|null $lineAccountId LINE OA scope for rows written by this instance
     */
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId !== null ? (int) $lineAccountId : null;
        $this->driver = $this->detectDriver($db);
        $this->clock = static function (): string {
            return date('Y-m-d H:i:s');
        };
    }

    /**
     * Override the wall clock. Tests use this to pin expiry maths; production
     * never calls it.
     *
     * @param callable():string $clock returns a 'Y-m-d H:i:s' string
     */
    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * The member's balance, and — crucially — where it came from.
     *
     * The ledger is queried by `user_id` alone, deliberately WITHOUT a
     * `line_account_id` predicate. Under database-per-tenant a `users` row
     * belongs to exactly one OA, so `user_id` already implies the OA; adding the
     * predicate would only DROP rows that earlier writers stamped with a wrong or
     * NULL account (user-detail.php hard-codes 1, the Odoo handler defaults to 3),
     * silently destroying real balances. Scoping is a Phase 6 concern and needs
     * the mis-stamped rows repaired first.
     *
     * @param int $userId
     * @return array{
     *     total_points:int, available_points:int, used_points:int,
     *     ledger_balance:int, ledger_rows:int, source:string
     * } `available_points` is clamped at 0 for spending decisions;
     *   `ledger_balance` is the raw signed sum, for reconciliation.
     */
    public function getBalance(int $userId): array
    {
        $ledger = $this->readLedgerTotals($userId);

        // THE FIX. A ledger that nets to zero because +500 and -500 cancelled is
        // NOT the same as a ledger with nothing in it. Only the second may fall
        // back to the legacy `users` columns. Conflating them lets a stale
        // users.points be resurrected as spendable currency every time a member
        // happens to spend down to exactly zero.
        if ($ledger['ledger_rows'] > 0) {
            return [
                'total_points' => $ledger['total_points'],
                'available_points' => max(0, $ledger['ledger_balance']),
                'used_points' => $ledger['used_points'],
                'ledger_balance' => $ledger['ledger_balance'],
                'ledger_rows' => $ledger['ledger_rows'],
                'source' => self::SOURCE_LEDGER,
            ];
        }

        $legacy = $this->readLegacyCache($userId);
        if ($legacy !== null && $legacy['available_points'] > 0) {
            return [
                'total_points' => $legacy['total_points'],
                'available_points' => $legacy['available_points'],
                'used_points' => $legacy['used_points'],
                'ledger_balance' => $legacy['available_points'],
                'ledger_rows' => 0,
                'source' => self::SOURCE_LEGACY_CACHE,
            ];
        }

        return [
            'total_points' => 0,
            'available_points' => 0,
            'used_points' => 0,
            'ledger_balance' => 0,
            'ledger_rows' => 0,
            'source' => self::SOURCE_EMPTY,
        ];
    }

    /**
     * Ledger rows for a member, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->prepare(
            'SELECT * FROM points_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * The transaction previously written under this idempotency key, if any.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        if ($key === '' || !$this->supportsIdempotency()) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM points_transactions WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Whether this database has had migration_2026-08-26 applied. When false the
     * service still works, but keys passed to credit()/debit() cannot be enforced
     * — callers get at-least-once instead of exactly-once semantics.
     */
    public function supportsIdempotency(): bool
    {
        return $this->columnExists('points_transactions', 'idempotency_key');
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Add points. Atomic, and idempotent when an `idempotency_key` is supplied.
     *
     * @param int   $userId
     * @param int   $points  must be > 0
     * Recognised $options keys:
     *   type            string  one of CREDIT_TYPES; default 'earn'
     *   reference_type  string  'order' | 'reward' | 'claim' | ...
     *   reference_id    int     the referenced row's id
     *   description     string  shown to staff and to the member
     *   idempotency_key string  e.g. 'la:3:order:1182:earn'
     *   expires_at      string  'Y-m-d H:i:s'; null = never expires
     *   metadata        array   rule breakdown, stored as JSON
     *   created_by      string  'admin:12' | 'system:webhook' | 'cron:expire'
     *   line_account_id int     overrides the constructor scope
     *
     * @param array<string, mixed> $options
     * @return array{
     *     success:bool, duplicate:bool, reason:string|null,
     *     transaction_id:int|null, points:int, balance_after:int, balance:array
     * }
     */
    public function credit(int $userId, int $points, array $options = []): array
    {
        $type = $options['type'] ?? self::TYPE_EARN;
        if (!in_array($type, self::CREDIT_TYPES, true)) {
            throw new InvalidArgumentException(
                'LoyaltyLedgerService::credit() got a non-credit type: ' . $type
            );
        }

        return $this->record($userId, abs($points), $type, $options);
    }

    /**
     * Spend points. Declines (without throwing) when the balance is short.
     *
     * The sufficiency check happens AFTER the member row is locked, so two
     * concurrent redemptions cannot both pass it — the previous implementation
     * read the balance outside any lock and could drive
     * `users.available_points` negative.
     *
     * @param array $options same shape as credit(), but `type` must be a DEBIT_TYPE
     * @return array same shape as credit(); `reason` is 'insufficient_points' on decline
     */
    public function debit(int $userId, int $points, array $options = []): array
    {
        $type = $options['type'] ?? self::TYPE_REDEEM;
        if (!in_array($type, self::DEBIT_TYPES, true)) {
            throw new InvalidArgumentException(
                'LoyaltyLedgerService::debit() got a non-debit type: ' . $type
            );
        }

        return $this->record($userId, -abs($points), $type, $options);
    }

    /**
     * The one place a point movement is written.
     *
     * @param int   $signedPoints positive credits, negative debits
     * @param array $options
     * @return array
     */
    private function record(int $userId, int $signedPoints, string $type, array $options): array
    {
        if ($userId <= 0 || $signedPoints === 0) {
            return $this->declined(self::REASON_INVALID_POINTS, $userId);
        }

        $idempotencyKey = isset($options['idempotency_key']) && $options['idempotency_key'] !== ''
            ? (string) $options['idempotency_key']
            : null;

        // Enlist in the caller's transaction when there is one. POSService and
        // api/points-claim.php open their own around a wider unit of work, and
        // PDO has no nested transactions — beginTransaction() there would throw.
        $ownsTransaction = !$this->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // Serialise every movement for this member behind one row lock.
            if (!$this->lockUser($userId)) {
                if ($ownsTransaction) {
                    $this->db->rollBack();
                }

                return $this->declined(self::REASON_USER_NOT_FOUND, $userId);
            }

            // Replay check, inside the lock so a concurrent duplicate waits here.
            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    if ($ownsTransaction) {
                        $this->db->commit();
                    }

                    return [
                        'success' => true,
                        'duplicate' => true,
                        'reason' => null,
                        'transaction_id' => (int) $existing['id'],
                        'points' => (int) $existing['points'],
                        'balance_after' => (int) $existing['balance_after'],
                        'balance' => $this->getBalance($userId),
                    ];
                }
            }

            $totals = $this->readLedgerTotals($userId);
            $opening = $this->openingBalance($totals);
            $balanceAfter = $opening + $signedPoints;

            if ($signedPoints < 0 && $opening < abs($signedPoints)) {
                if ($ownsTransaction) {
                    $this->db->rollBack();
                }

                return $this->declined(self::REASON_INSUFFICIENT_POINTS, $userId);
            }

            $transactionId = $this->insertMovement(
                $userId,
                $signedPoints,
                $type,
                $balanceAfter,
                $idempotencyKey,
                $options
            );

            $this->syncCache($userId);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'success' => true,
                'duplicate' => false,
                'reason' => null,
                'transaction_id' => $transactionId,
                'points' => $signedPoints,
                'balance_after' => $balanceAfter,
                'balance' => $this->getBalance($userId),
            ];
        } catch (Throwable $e) {
            // A UNIQUE violation on idempotency_key means a concurrent request
            // won the race. That is success, not failure: re-read its row.
            if ($idempotencyKey !== null && $this->isDuplicateKeyError($e)) {
                if ($ownsTransaction && $this->inTransaction()) {
                    $this->db->rollBack();
                }

                $winner = $this->findByIdempotencyKey($idempotencyKey);
                if ($winner !== null) {
                    return [
                        'success' => true,
                        'duplicate' => true,
                        'reason' => null,
                        'transaction_id' => (int) $winner['id'],
                        'points' => (int) $winner['points'],
                        'balance_after' => (int) $winner['balance_after'],
                        'balance' => $this->getBalance($userId),
                    ];
                }
            }

            if ($ownsTransaction && $this->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * The balance a movement is applied to: always the ledger sum, never the
     * legacy `users` columns.
     *
     * It is tempting to carry a pre-ledger balance in here so a member's first
     * modern award does not appear to drop their legacy welcome bonus. Doing so
     * would break the invariant this whole class exists to establish — the
     * written `balance_after` would exceed SUM(points), and the recomputed cache
     * would immediately contradict it. (The old addPoints() did exactly that:
     * it wrote balance_after = legacy + new while the cache and the ledger both
     * landed on `new`, which is why balance_after is untrustworthy in historical
     * rows.)
     *
     * The legacy balance is not silently dropped, it is *reported*:
     * scripts/loyalty-reconcile.php classifies these members as LEGACY_ONLY /
     * CACHE_ONLY, and §34 of the plan carries them across as explicit
     * `migration` opening-balance rows once an operator has reviewed the report.
     * Until then getBalance() keeps showing them their legacy balance, exactly
     * as today.
     *
     * @param array{ledger_balance:int, ledger_rows:int} $totals
     */
    private function openingBalance(array $totals): int
    {
        return $totals['ledger_balance'];
    }

    /**
     * Insert the immutable ledger row, binding only the columns this database
     * actually has — a tenant that has not yet run migration_2026-08-26 still
     * works, minus the idempotency guarantee.
     *
     * @return int new points_transactions.id
     */
    private function insertMovement(
        int $userId,
        int $signedPoints,
        string $type,
        int $balanceAfter,
        ?string $idempotencyKey,
        array $options
    ): int {
        $referenceId = isset($options['reference_id']) && $options['reference_id'] !== ''
            ? (int) $options['reference_id']
            : null;

        $columns = [
            'user_id' => $userId,
            'line_account_id' => $options['line_account_id'] ?? $this->lineAccountId,
            'type' => $type,
            'points' => $signedPoints,
            'balance_after' => $balanceAfter,
            'reference_type' => $options['reference_type'] ?? null,
            'reference_id' => $referenceId,
            'description' => $options['description'] ?? null,
            'expires_at' => $options['expires_at'] ?? null,
            'created_at' => ($this->clock)(),
        ];

        if ($idempotencyKey !== null && $this->columnExists('points_transactions', 'idempotency_key')) {
            $columns['idempotency_key'] = $idempotencyKey;
        }

        if (!empty($options['metadata']) && $this->columnExists('points_transactions', 'metadata')) {
            $columns['metadata'] = is_string($options['metadata'])
                ? $options['metadata']
                : json_encode($options['metadata'], JSON_UNESCAPED_UNICODE);
        }

        if (!empty($options['created_by']) && $this->columnExists('points_transactions', 'created_by')) {
            $columns['created_by'] = (string) $options['created_by'];
        }

        $names = array_keys($columns);
        $sql = 'INSERT INTO points_transactions (`' . implode('`, `', $names) . '`) VALUES ('
            . implode(', ', array_fill(0, count($names), '?')) . ')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($columns));

        return (int) $this->db->lastInsertId();
    }

    /**
     * Recompute the `users` caches from the ledger and write them absolutely.
     *
     * Absolutely, not incrementally: `available_points = available_points - N`
     * perpetuates any drift it starts with (and is how the column went negative
     * in the first place). Recomputing makes the cache self-healing, so the
     * invariant `SUM(points) == users.available_points` holds again after every
     * write even on a member whose history predates this service.
     *
     * `users.points` is deliberately NOT written. It is a separate legacy store
     * still owned by api/member.php and api/points.php; Batch 2 retires it.
     */
    private function syncCache(int $userId): void
    {
        $totals = $this->readLedgerTotals($userId);

        $sets = [];
        $params = [];
        foreach (
            [
                'total_points' => $totals['total_points'],
                'available_points' => $totals['ledger_balance'],
                'used_points' => $totals['used_points'],
            ] as $column => $value
        ) {
            if ($this->columnExists('users', $column)) {
                $sets[] = '`' . $column . '` = ?';
                $params[] = $value;
            }
        }

        if ($sets === []) {
            return;
        }

        $params[] = $userId;
        $stmt = $this->db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    // -----------------------------------------------------------------------
    // Low-level helpers
    // -----------------------------------------------------------------------

    /**
     * Ledger aggregates for one member.
     *
     * `ledger_rows` is the whole point of this query: it is what lets callers
     * tell "nothing recorded" apart from "recorded, and it nets to zero".
     *
     * @return array{total_points:int, used_points:int, ledger_balance:int, ledger_rows:int}
     */
    private function readLedgerTotals(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS ledger_rows,
                COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS total_points,
                COALESCE(SUM(points), 0) AS ledger_balance,
                COALESCE(SUM(CASE WHEN points < 0 THEN -points ELSE 0 END), 0) AS used_points
             FROM points_transactions
             WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'ledger_rows' => (int) ($row['ledger_rows'] ?? 0),
            'total_points' => (int) ($row['total_points'] ?? 0),
            'ledger_balance' => (int) ($row['ledger_balance'] ?? 0),
            'used_points' => (int) ($row['used_points'] ?? 0),
        ];
    }

    /**
     * The pre-ledger balance held on the `users` row, or null if there is no such
     * row. `users.points` stands in for `available_points` when the latter is
     * empty, mirroring what every legacy reader does today.
     *
     * @return array{total_points:int, available_points:int, used_points:int}|null
     */
    private function readLegacyCache(int $userId): ?array
    {
        $selectable = [];
        foreach (['total_points', 'available_points', 'used_points', 'points'] as $column) {
            if ($this->columnExists('users', $column)) {
                $selectable[] = $column;
            }
        }

        if ($selectable === []) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT `' . implode('`, `', $selectable) . '` FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $available = (int) ($row['available_points'] ?? 0);
        $total = (int) ($row['total_points'] ?? 0);
        $legacyPoints = (int) ($row['points'] ?? 0);

        if ($available === 0 && $legacyPoints > 0) {
            $available = $legacyPoints;
            $total = max($total, $legacyPoints);
        }

        return [
            'total_points' => $total,
            'available_points' => $available,
            'used_points' => (int) ($row['used_points'] ?? 0),
        ];
    }

    /**
     * Take a row lock on the member for the duration of the transaction.
     *
     * @return bool false when the member does not exist — the caller declines
     *              rather than writing an orphan ledger row.
     */
    private function lockUser(int $userId): bool
    {
        $sql = 'SELECT id FROM users WHERE id = ? LIMIT 1';
        if ($this->driver === 'mysql') {
            // sqlite has no row locks; its whole-database write lock already
            // serialises us, which is enough for the test harness.
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /** @return array<string, mixed> a uniform decline payload */
    private function declined(string $reason, int $userId): array
    {
        return [
            'success' => false,
            'duplicate' => false,
            'reason' => $reason,
            'transaction_id' => null,
            'points' => 0,
            'balance_after' => 0,
            'balance' => $userId > 0 ? $this->getBalance($userId) : [
                'total_points' => 0,
                'available_points' => 0,
                'used_points' => 0,
                'ledger_balance' => 0,
                'ledger_rows' => 0,
                'source' => self::SOURCE_EMPTY,
            ],
        ];
    }

    private function inTransaction(): bool
    {
        try {
            return (bool) $this->db->inTransaction();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Does this look like a UNIQUE-constraint violation? SQLSTATE 23000 covers
     * MySQL's 1062 and sqlite's SQLITE_CONSTRAINT_UNIQUE alike.
     */
    private function isDuplicateKeyError(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            $sqlState = $e->errorInfo[0] ?? $e->getCode();
            if ((string) $sqlState === '23000') {
                return true;
            }
        }

        $message = $e->getMessage();

        return stripos($message, 'duplicate entry') !== false
            || stripos($message, 'unique constraint') !== false;
    }

    /**
     * Column introspection that tells the truth on both drivers.
     *
     * LoyaltyPoints::columnExists() runs `SHOW COLUMNS` and swallows the failure,
     * so under sqlite it reports every column as missing — silently exercising a
     * different branch than production. This one asks each driver in its own
     * language.
     */
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
