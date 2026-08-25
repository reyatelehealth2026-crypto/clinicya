<?php

namespace Tests\Loyalty;

use PHPUnit\Framework\TestCase;

/**
 * Batch 2 acceptance: there is only ONE production write path for point balances.
 *
 * The Phase 0 audit found 27 writers across three incompatible storage styles.
 * Batch 2 routed them all through LoyaltyLedgerService. This suite asserts that
 * against the source tree rather than against behaviour, because the failure mode
 * is a *new* caller quietly reintroducing a legacy write — which no runtime test
 * would catch until the balances had already diverged.
 *
 * @see docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md §2
 */
class SingleWriterGuardTest extends TestCase
{
    /** Directories that contain live request-serving code. */
    private const SCANNED_DIRS = ['api', 'classes', 'shop', 'includes', 'cron', 'modules'];

    /**
     * Files still permitted to write the legacy stores, each with the phase that
     * retires it. Shrinking this list is the point; growing it needs a reason.
     */
    private const KNOWN_LEGACY_WRITERS = [
        // The second, parallel redemption stack. Settles against users.points and
        // points_history and is invisible to the ledger. Retired in Phase 5.
        'api/points.php' => 'Phase 5 — RewardRedemptionService consolidation',
    ];

    /** Statements that mutate a legacy point store. */
    private const LEGACY_WRITE_PATTERNS = [
        '/INSERT\s+INTO\s+`?points_history`?/i' => 'INSERT INTO points_history',
        '/UPDATE\s+`?users`?\s+SET[^;"\']*\bpoints\s*=/i' => 'UPDATE users SET points =',
    ];

    public function testNoLivePhpWritesTheLegacyPointStores(): void
    {
        $offenders = [];

        foreach ($this->livePhpFiles() as $relative => $absolute) {
            if (isset(self::KNOWN_LEGACY_WRITERS[$relative])) {
                continue;
            }

            $code = $this->stripComments((string) file_get_contents($absolute));
            foreach (self::LEGACY_WRITE_PATTERNS as $pattern => $label) {
                if (preg_match($pattern, $code)) {
                    $offenders[] = "{$relative}: {$label}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Legacy point writes found. Route them through LoyaltyLedgerService '
            . "(via LoyaltyPoints::addPoints/deductPoints) instead:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The exemption list must stay honest: an entry for a file that no longer
     * writes legacy stores is stale and should be deleted, so that the list keeps
     * measuring real remaining work.
     */
    public function testEveryExemptionIsStillNeeded(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (self::KNOWN_LEGACY_WRITERS as $relative => $phase) {
            $path = $root . '/' . $relative;
            $this->assertFileExists($path, "exempted file no longer exists: {$relative}");

            $code = $this->stripComments((string) file_get_contents($path));
            $stillWrites = false;
            foreach (array_keys(self::LEGACY_WRITE_PATTERNS) as $pattern) {
                if (preg_match($pattern, $code)) {
                    $stillWrites = true;
                    break;
                }
            }

            $this->assertTrue(
                $stillWrites,
                "{$relative} no longer writes a legacy point store — remove it from "
                . "KNOWN_LEGACY_WRITERS ({$phase})."
            );
        }
    }

    /**
     * The welcome bonus is the single most-replayed award in the system (every
     * `action=check` from the mini app can reach it). Pin that it goes through
     * the ledger with a key rather than back to users.points.
     */
    public function testWelcomeBonusGoesThroughTheLedgerWithAnIdempotencyKey(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/api/member.php');

        $this->assertStringContainsString(
            'memberAwardWelcomeBonus',
            $source,
            'all three registration paths should share one welcome-bonus helper'
        );
        $this->assertStringContainsString(
            "'idempotency_key' => 'member:' . \$userId . ':welcome-bonus'",
            $source,
            'the welcome bonus must be keyed so retries cannot re-award it'
        );
        $this->assertStringContainsString(
            'LoyaltyLedgerService::TYPE_BONUS',
            $source,
            "the welcome bonus should be typed 'bonus', not 'earn'"
        );
    }

    /**
     * Both refund paths guard only against 'delivered', so an already-cancelled
     * redemption could be refunded again without bound. The key is what actually
     * stops it.
     */
    public function testBothRefundPathsAreKeyedOnTheRedemption(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['membership.php', 'api/admin/rewards.php'] as $relative) {
            $source = (string) file_get_contents($root . '/' . $relative);
            $this->assertStringContainsString(
                "'redemption:' . (int) \$redemptionId . ':refund'",
                $source,
                "{$relative} must key its refund so a re-submitted cancel cannot pay twice"
            );
        }
    }

    /** @return array<string, string> relative path => absolute path */
    private function livePhpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (self::SCANNED_DIRS as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace($root . '/', '', $file->getPathname());
                // Archived and vendored trees are not live code.
                if (preg_match('#(^|/)(_archive|archive|vendor|node_modules)/#', $relative)) {
                    continue;
                }

                $files[$relative] = $file->getPathname();
            }
        }

        // Root-level admin pages are live too.
        foreach (glob($root . '/*.php') ?: [] as $path) {
            $files[basename($path)] = $path;
        }

        return $files;
    }

    /** Comments legitimately describe the old statements; only real code counts. */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
