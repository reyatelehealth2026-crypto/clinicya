<?php
/**
 * Tests for the LIFF-or-OA fallback helper (includes/liff-helper.php).
 *
 * **Feature: line-mini-app LIFF fallback**
 *
 * Core property: a Mini-App link must only be emitted for tenants whose
 * line_account has a REAL liff_id. Tenants with an empty or 'PENDING…'
 * placeholder fall back to the OA chat URL (or '' → no button), so customers
 * are never sent into the shared Mini App without a working LIFF context.
 */

namespace Tests\LandingPage;

use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../../includes/liff-helper.php';

class LiffFallbackHelperTest extends TestCase
{
    protected function setUp(): void
    {
        // Each test builds a fresh in-memory DB; clear the per-request cache so
        // a row from a previous case can't leak into this one.
        reya_liff_helper_reset_cache();
    }

    // ---- reya_is_real_liff_id ------------------------------------------------

    /** @return array<string,array{0:?string,1:bool}> */
    public static function liffIdProvider(): array
    {
        return [
            'real numeric-dash'   => ['2008477880-wmRN2Aln', true],
            'real numeric'        => ['1234567890', true],
            'null'                => [null, false],
            'empty'               => ['', false],
            'whitespace only'     => ['   ', false],
            'PENDING upper'       => ['PENDING-0001', false],
            'PENDING lower'       => ['pending-setup', false],
            'PENDING mixed'       => ['Pending', false],
            'PENDING bare'        => ['PENDING', false],
            // "PENDING" must only match as a prefix, not mid-string.
            'contains pending mid'=> ['liff-PENDING-x', true],
        ];
    }

    /**
     * @dataProvider liffIdProvider
     */
    public function testIsRealLiffId(?string $liffId, bool $expected): void
    {
        $this->assertSame($expected, reya_is_real_liff_id($liffId));
    }

    // ---- reya_oa_chat_url ----------------------------------------------------

    public function testOaChatUrlWithBasicId(): void
    {
        $url = reya_oa_chat_url(['basic_id' => '@abc1234']);
        $this->assertStringStartsWith('https://line.me/R/ti/p/', $url);
        $this->assertStringContainsString('abc1234', $url);
    }

    public function testOaChatUrlEmptyWhenNoBasicId(): void
    {
        $this->assertSame('', reya_oa_chat_url([]));
        $this->assertSame('', reya_oa_chat_url(['basic_id' => '']));
        $this->assertSame('', reya_oa_chat_url(['basic_id' => '   ']));
    }

    // ---- reya_liff_url_or_oa (integration with an in-memory SQLite PDO) ------

    private function makeDb(string $liffId, string $basicId): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE line_accounts (
            id INTEGER PRIMARY KEY,
            liff_id TEXT,
            basic_id TEXT,
            name TEXT,
            is_active INTEGER DEFAULT 1,
            is_default INTEGER DEFAULT 1
        )');
        $stmt = $pdo->prepare('INSERT INTO line_accounts (id, liff_id, basic_id, name) VALUES (1, ?, ?, ?)');
        $stmt->execute([$liffId, $basicId, 'Test Shop']);
        return $pdo;
    }

    public function testRealLiffIdReturnsLiffDeepLinkTaggedWithAccount(): void
    {
        $db = $this->makeDb('2008477880-wmRN2Aln', '@abc1234');
        $url = reya_liff_url_or_oa($db, 1, '/shop');
        $this->assertStringStartsWith('https://liff.line.me/2008477880-wmRN2Aln/shop', $url);
        // Deep link must carry both signals so the shared Mini App resolves the
        // tenant and LIFF id before falling back to build-time defaults.
        $this->assertStringContainsString('la=1', $url);
        $this->assertStringContainsString('liff_id=2008477880-wmRN2Aln', $url);
    }

    public function testRealLiffIdNoDeepLinkPath(): void
    {
        $db = $this->makeDb('2008477880-wmRN2Aln', '@abc1234');
        $url = reya_liff_url_or_oa($db, 1, '');
        $this->assertSame('https://liff.line.me/2008477880-wmRN2Aln?la=1&liff_id=2008477880-wmRN2Aln', $url);
    }

    public function testDeepLinkWithExistingQueryUsesAmpersand(): void
    {
        $db = $this->makeDb('2008477880-wmRN2Aln', '@abc1234');
        $url = reya_liff_url_or_oa($db, 1, '/order?id=99');
        $this->assertStringContainsString('/order?id=99&la=1&liff_id=2008477880-wmRN2Aln', $url);
    }

    public function testDeepLinkContextPreservesFragment(): void
    {
        $db = $this->makeDb('2008477880-wmRN2Aln', '@abc1234');
        $url = reya_liff_url_or_oa($db, 1, '/shop#detail');
        $this->assertStringEndsWith('?la=1&liff_id=2008477880-wmRN2Aln#detail', $url);
    }

    public function testPendingLiffFallsBackToOaChat(): void
    {
        $db = $this->makeDb('PENDING-0001', '@abc1234');
        $url = reya_liff_url_or_oa($db, 1, '/shop');
        $this->assertStringStartsWith('https://line.me/R/ti/p/', $url);
        $this->assertStringNotContainsString('liff.line.me', $url);
    }

    public function testEmptyLiffFallsBackToOaChat(): void
    {
        $db = $this->makeDb('', '@abc1234');
        $url = reya_liff_url_or_oa($db, 1, '/shop');
        $this->assertStringStartsWith('https://line.me/R/ti/p/', $url);
    }

    public function testNoLiffAndNoBasicIdReturnsEmpty(): void
    {
        $db = $this->makeDb('', '');
        $url = reya_liff_url_or_oa($db, 1, '/shop');
        $this->assertSame('', $url, 'No LIFF and no basic_id → render no button');
    }

    public function testUnknownAccountReturnsEmpty(): void
    {
        $db = $this->makeDb('2008477880-wmRN2Aln', '@abc1234');
        // Account 999 does not exist → no row → ''.
        $url = reya_liff_url_or_oa($db, 999, '/shop');
        $this->assertSame('', $url);
    }
}
