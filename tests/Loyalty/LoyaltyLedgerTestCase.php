<?php

namespace Tests\Loyalty;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/LoyaltyLedgerService.php';
require_once __DIR__ . '/../../classes/LoyaltyPoints.php';

/**
 * Shared in-memory fixture for the loyalty ledger suite.
 *
 * The schema below mirrors `points_transactions` and the point-bearing columns of
 * `users` AFTER database/migration_2026-08-26_loyalty_ledger_idempotency.sql has
 * been applied. assertFixtureMatchesMigration() pins that correspondence, because
 * the repo's hand-rolled sqlite fixtures have drifted from production schema
 * before — every one of the 500 pre-existing errors in `composer test` is a
 * fixture that fell behind an ALTER TABLE nobody mirrored here.
 */
abstract class LoyaltyLedgerTestCase extends TestCase
{
    /** @var PDO */
    protected $pdo;

    /** @var int */
    protected $lineAccountId = 3;

    /** Columns migration_2026-08-26 guarantees on points_transactions. */
    protected const LEDGER_COLUMNS = [
        'id',
        'user_id',
        'line_account_id',
        'type',
        'points',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'expires_at',
        'idempotency_key',
        'metadata',
        'created_by',
        'created_at',
    ];

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->buildSchema($this->pdo);
    }

    /**
     * Build the loyalty fixture on any handle, so a test that needs a
     * specialised connection (see ThrowingPdo) gets the identical schema.
     */
    protected function buildSchema(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER NOT NULL DEFAULT 1,
                line_user_id TEXT DEFAULT NULL,
                display_name TEXT DEFAULT NULL,
                phone TEXT DEFAULT NULL,
                is_registered INTEGER NOT NULL DEFAULT 0,
                points INTEGER DEFAULT 0,
                total_points INTEGER DEFAULT 0,
                available_points INTEGER DEFAULT 0,
                used_points INTEGER DEFAULT 0,
                member_tier TEXT DEFAULT NULL
            )'
        );

        $pdo->exec(
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
                idempotency_key TEXT DEFAULT NULL,
                metadata TEXT DEFAULT NULL,
                created_by TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        // Mirrors UNIQUE KEY uniq_points_tx_idempotency. Both sqlite and
        // MySQL permit unlimited NULLs in a unique index, so unkeyed
        // movements are unaffected.
        $pdo->exec(
            'CREATE UNIQUE INDEX uniq_points_tx_idempotency
                ON points_transactions (idempotency_key)'
        );

        $pdo->exec(
            'CREATE TABLE points_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER DEFAULT NULL,
                points_per_baht REAL DEFAULT 0.001,
                min_order_for_points REAL DEFAULT 0,
                points_expiry_days INTEGER DEFAULT 365,
                is_active INTEGER DEFAULT 1
            )'
        );

        $pdo->exec(
            'CREATE TABLE rewards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER DEFAULT NULL,
                name TEXT NOT NULL,
                description TEXT DEFAULT NULL,
                image_url TEXT DEFAULT NULL,
                points_required INTEGER NOT NULL DEFAULT 0,
                reward_type TEXT DEFAULT "discount",
                reward_value TEXT DEFAULT NULL,
                stock INTEGER DEFAULT -1,
                max_per_user INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                start_date TEXT DEFAULT NULL,
                end_date TEXT DEFAULT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE reward_redemptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                reward_id INTEGER NOT NULL,
                line_account_id INTEGER DEFAULT NULL,
                points_used INTEGER NOT NULL DEFAULT 0,
                redemption_code TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                expires_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $pdo->exec(
            "INSERT INTO points_settings (line_account_id, points_per_baht, min_order_for_points, points_expiry_days, is_active)
             VALUES ({$this->lineAccountId}, 0.1, 0, 365, 1)"
        );
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    /** A brand-new member with no ledger history and no legacy balance. */
    protected function makeUser(array $overrides = []): int
    {
        $row = array_merge([
            'line_account_id' => $this->lineAccountId,
            'line_user_id' => 'U' . bin2hex(random_bytes(8)),
            'display_name' => 'คุณทดสอบ',
            'is_registered' => 1,
            'points' => 0,
            'total_points' => 0,
            'available_points' => 0,
            'used_points' => 0,
        ], $overrides);

        $columns = array_keys($row);
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    protected function ledger(): \LoyaltyLedgerService
    {
        return new \LoyaltyLedgerService($this->pdo, $this->lineAccountId);
    }

    protected function loyalty(): \LoyaltyPoints
    {
        return new \LoyaltyPoints($this->pdo, $this->lineAccountId);
    }

    /** @return array<string, int|string|null> the raw users row */
    protected function userRow(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    protected function ledgerRowCount(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM points_transactions WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /** The raw signed ledger sum — the number the cache must equal. */
    protected function ledgerSum(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM points_transactions WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The Phase 1 acceptance invariant, asserted after every mutation:
     * "for every tested user, ledger sum == available_points cache".
     */
    protected function assertLedgerMatchesCache(int $userId, string $context = ''): void
    {
        $row = $this->userRow($userId);
        $this->assertSame(
            $this->ledgerSum($userId),
            (int) $row['available_points'],
            'ledger sum must equal the users.available_points cache' . ($context !== '' ? " ({$context})" : '')
        );
    }

    /**
     * Guards against the fixture drifting away from the migration, which is how
     * the repo's other sqlite suites rotted.
     */
    protected function assertFixtureMatchesMigration(): void
    {
        $columns = [];
        foreach ($this->pdo->query('PRAGMA table_info(points_transactions)')->fetchAll(PDO::FETCH_ASSOC) as $info) {
            $columns[] = $info['name'];
        }

        sort($columns);
        $expected = self::LEDGER_COLUMNS;
        sort($expected);

        $this->assertSame(
            $expected,
            $columns,
            'the sqlite fixture has drifted from database/migration_2026-08-26_loyalty_ledger_idempotency.sql'
        );
    }
}
