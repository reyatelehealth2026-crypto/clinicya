<?php

namespace Tests\Loyalty;

use PHPUnit\Framework\TestCase;

// Load the report's pure helpers without running it or touching config/DB.
if (!defined('REYA_RECONCILE_NO_MAIN')) {
    define('REYA_RECONCILE_NO_MAIN', true);
}
require_once __DIR__ . '/../../scripts/loyalty-reconcile.php';

/**
 * scripts/loyalty-reconcile.php — bucket classification and CLI parsing.
 *
 * The report is what an operator will use to size the §34 migration, so a
 * mis-bucketed member is a wrong migration decision. The classifier is a pure
 * function of five integers, which makes it exhaustively testable.
 */
class ReconcileClassificationTest extends TestCase
{
    /**
     * @dataProvider classificationProvider
     */
    public function testClassify(
        string $expected,
        int $ledgerRows,
        int $ledgerBalance,
        int $historyRows,
        int $usersAvailable,
        int $usersPoints,
        string $why
    ): void {
        $this->assertSame(
            $expected,
            reya_reconcile_classify($ledgerRows, $ledgerBalance, $historyRows, $usersAvailable, $usersPoints),
            $why
        );
    }

    /** @return array<string, array{0:string,1:int,2:int,3:int,4:int,5:int,6:string}> */
    public static function classificationProvider(): array
    {
        return [
            'brand new member' => [
                'EMPTY', 0, 0, 0, 0, 0,
                'nothing in any store',
            ],
            'healthy modern member' => [
                'MATCHED', 4, 350, 0, 350, 0,
                'ledger and cache agree',
            ],
            'healthy member who also has legacy history' => [
                'MATCHED', 4, 350, 2, 350, 0,
                'the ledger is authoritative once it has rows',
            ],
            'the phantom-points shape' => [
                'STALE_CACHE', 2, 0, 0, 0, 300,
                'ledger nets to zero while users.points still shows 300 — the pre-Batch-1 bug',
            ],
            'zero ledger with a stale available_points' => [
                'STALE_CACHE', 2, 0, 0, 300, 0,
                'the same shape via the other legacy column',
            ],
            'zero ledger with clean caches is simply matched' => [
                'MATCHED', 2, 0, 0, 0, 0,
                'spent down to zero with nothing stale left behind',
            ],
            'cache drifted above the ledger' => [
                'CONFLICT', 5, 200, 0, 260, 0,
                'the cache was incremented by a writer that skipped the ledger',
            ],
            'cache drifted negative' => [
                'CONFLICT', 5, 200, 0, -50, 0,
                'the old unguarded decrement drove the cache below zero',
            ],
            'legacy history only' => [
                'LEGACY_ONLY', 0, 0, 3, 50, 50,
                'welcome bonus written to points_history, never to the ledger',
            ],
            'legacy history only, no cache' => [
                'LEGACY_ONLY', 0, 0, 3, 0, 0,
                'history rows outrank an empty cache',
            ],
            'balance with no provenance at all' => [
                'CACHE_ONLY', 0, 0, 0, 0, 120,
                'a users.points value no history explains',
            ],
            'available_points with no provenance' => [
                'CACHE_ONLY', 0, 0, 0, 120, 0,
                'same, via available_points',
            ],
        ];
    }

    public function testEveryClassificationIsAKnownBucket(): void
    {
        foreach (self::classificationProvider() as $case) {
            $this->assertContains($case[0], RECONCILE_BUCKETS);
        }
    }

    public function testStaleCacheIsReportedRatherThanConflictWhenBothApply(): void
    {
        // ledger nets to zero AND the cache disagrees with it: both descriptions
        // are true, but STALE_CACHE is the actionable diagnosis.
        $this->assertSame('STALE_CACHE', reya_reconcile_classify(3, 0, 0, 500, 500));
    }

    // -----------------------------------------------------------------------
    // CLI parsing
    // -----------------------------------------------------------------------

    public function testDefaultsRequireAnExplicitTarget(): void
    {
        $options = reya_reconcile_parse_argv(['loyalty-reconcile.php']);

        $this->assertFalse($options['all']);
        $this->assertNull($options['tenant']);
        $this->assertFalse($options['legacy']);
        $this->assertSame(25, $options['limit']);
        $this->assertSame(['STALE_CACHE', 'CONFLICT', 'CACHE_ONLY'], $options['show']);
    }

    public function testParsesEveryOption(): void
    {
        $options = reya_reconcile_parse_argv([
            'loyalty-reconcile.php',
            '--tenant=7',
            '--limit=50',
            '--json',
            '--show=matched,conflict',
        ]);

        $this->assertSame(7, $options['tenant']);
        $this->assertSame(50, $options['limit']);
        $this->assertTrue($options['json']);
        $this->assertSame(['MATCHED', 'CONFLICT'], $options['show'], '--show is case-insensitive');
        $this->assertFalse($options['invalid']);
    }

    public function testUnknownBucketNamesAreDiscarded(): void
    {
        $options = reya_reconcile_parse_argv(['loyalty-reconcile.php', '--show=CONFLICT,NONSENSE']);

        $this->assertSame(['CONFLICT'], $options['show']);
    }

    public function testUnknownOptionIsFlaggedInvalid(): void
    {
        $options = reya_reconcile_parse_argv(['loyalty-reconcile.php', '--wat']);

        $this->assertTrue($options['invalid']);
        $this->assertFalse($options['help'], 'an unknown option is an error, not a help request');
        $this->assertSame(['unknown option: --wat'], $options['errors']);
    }

    /**
     * The report must never mutate anything. Asserted against the source so a
     * future edit that adds a write is caught, not just today's behaviour.
     */
    public function testTheReportContainsNoWriteStatements(): void
    {
        $source = file_get_contents(__DIR__ . '/../../scripts/loyalty-reconcile.php');
        // Strip the docblock/comments, which legitimately mention the words.
        $code = preg_replace('#/\*.*?\*/|^\s*//.*$#ms', '', $source);

        foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE'] as $verb) {
            $this->assertStringNotContainsStringIgnoringCase(
                $verb,
                (string) $code,
                "scripts/loyalty-reconcile.php must stay read-only, found: {$verb}"
            );
        }
    }
}
