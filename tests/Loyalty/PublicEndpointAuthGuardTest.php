<?php

namespace Tests\Loyalty;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/liff-auth.php';

/**
 * Phase 6 — a client-supplied `line_user_id` is not an identity.
 *
 * The Phase 0 audit's most severe finding: the public loyalty endpoints took the
 * caller's identity from a request parameter, so changing one field read or
 * mutated another member's points. (The audit also claimed no LINE token
 * verification existed anywhere in the repo — that was WRONG:
 * includes/liff-auth.php has verified bearer tokens against LINE all along, it
 * was simply only wired into two AI-chat endpoints. Corrected in §4.5 of the
 * matrix.)
 *
 * These tests assert the guard is applied at every entry point, because the
 * failure mode is a NEW endpoint shipping without it — which no runtime test
 * would catch until someone else's points were spent.
 *
 * @see docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §19 / Phase 6
 */
class PublicEndpointAuthGuardTest extends TestCase
{
    /**
     * Every public endpoint that accepts a `line_user_id` from request input,
     * and how many distinct entry points inside it must be guarded.
     */
    private const GUARDED_ENDPOINTS = [
        'api/member.php' => 4,          // register, check, get_card, update_profile
        'api/points.php' => 2,          // history, redeem
        'api/points-history.php' => 1,  // one top-level guard covers every action
        'api/rewards.php' => 2,         // redeem, my_redemptions
    ];

    /**
     * @dataProvider guardedEndpointProvider
     */
    public function testEndpointGuardsEveryEntryPoint(string $relative, int $expected): void
    {
        $source = $this->source($relative);

        $this->assertStringContainsString(
            'includes/liff-auth.php',
            $source,
            "{$relative} must load the LIFF identity guard"
        );
        $this->assertSame(
            $expected,
            substr_count($source, 'reya_liff_guard('),
            "{$relative} should guard {$expected} entry point(s) that take a line_user_id"
        );
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function guardedEndpointProvider(): array
    {
        $cases = [];
        foreach (self::GUARDED_ENDPOINTS as $relative => $count) {
            $cases[$relative] = [$relative, $count];
        }

        return $cases;
    }

    /**
     * The guard must be reached BEFORE the endpoint does anything with the
     * claimed identity — a guard placed after the first query is decoration.
     */
    public function testTheGuardRunsBeforeTheFirstUserLookup(): void
    {
        foreach (array_keys(self::GUARDED_ENDPOINTS) as $relative) {
            $source = $this->source($relative);

            $firstGuard = strpos($source, 'reya_liff_guard(');
            $firstLookup = strpos($source, 'FROM users WHERE line_user_id');

            if ($firstLookup === false) {
                continue;
            }

            $this->assertNotFalse($firstGuard, "{$relative} has a user lookup but no guard");
            $this->assertLessThan(
                $firstLookup,
                $firstGuard,
                "{$relative} must establish identity before it looks the member up"
            );
        }
    }

    // -----------------------------------------------------------------------
    // Guard semantics
    // -----------------------------------------------------------------------

    public function testStrictModeIsOffUnlessExplicitlyEnabled(): void
    {
        $this->assertFalse(
            reya_liff_strict_mode(),
            'strict mode must be opt-in — turning it on before clients send tokens '
            . 'would lock every existing customer out of their points'
        );
    }

    /**
     * @dataProvider strictValueProvider
     */
    public function testStrictModeReadsItsEnvironmentFlag(string $value, bool $expected): void
    {
        putenv('LIFF_STRICT_AUTH=' . $value);
        try {
            $this->assertSame($expected, reya_liff_strict_mode(), "LIFF_STRICT_AUTH={$value}");
        } finally {
            putenv('LIFF_STRICT_AUTH');
        }
    }

    /** @return array<string, array{0:string,1:bool}> */
    public static function strictValueProvider(): array
    {
        return [
            'on' => ['1', true],
            'true' => ['true', true],
            'yes' => ['yes', true],
            'ON uppercase' => ['ON', true],
            'off' => ['0', false],
            'false' => ['false', false],
            'empty' => ['', false],
            'garbage' => ['maybe', false],
        ];
    }

    /**
     * A WRONG token is an attack, never a stale client, so it is refused
     * regardless of strict mode. Asserted against the source because the guard
     * terminates the request.
     */
    public function testAMismatchedTokenIsAlwaysFailClosed(): void
    {
        $source = $this->source('includes/liff-auth.php');

        $this->assertStringContainsString(
            'reya_require_liff_user($claimedUserId);',
            $source,
            'a supplied token must be verified fail-closed'
        );
        $this->assertStringContainsString(
            'hash_equals',
            $source,
            'the verified id must be compared in constant time'
        );
        $this->assertStringContainsString(
            'http_response_code(401)',
            $source,
            'a mismatch must 401, not fall through'
        );
    }

    /** The mini app has to actually send the token, or there is nothing to verify. */
    public function testTheMiniAppAttachesItsLiffToken(): void
    {
        $bridge = $this->source('line-mini-app/src/lib/php-bridge.ts');

        $this->assertStringContainsString('liffAuthHeader', $bridge);
        $this->assertStringContainsString('getAccessToken', $bridge);
        $this->assertSame(
            2,
            substr_count($bridge, 'await liffAuthHeader()'),
            'both phpGet and phpPost must attach it'
        );
    }

    /** The guard never leaks the full LINE user id into logs. */
    public function testRejectionLogsAreTruncated(): void
    {
        $source = $this->source('includes/liff-auth.php');

        $this->assertStringContainsString(
            "substr(\$claimedUserId, 0, 8) . '…'",
            $source,
            'a LINE user id is personal data — log a prefix, not the whole thing'
        );
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
