<?php
/**
 * Property-Based Test: BrandingResolver::normalize() fallback chain
 *
 * **Feature: white-label-branding, Property: brand fallback determinism**
 *
 * Property: For any raw shop_settings / line_accounts inputs, normalize()
 * SHALL resolve each field through its tier chain
 * (shop -> LINE OA -> REYA default) and never throw, never emit an empty
 * value, and only accept strict #RRGGBB theme colours.
 *
 * Pure & DB-free — exercises the static method directly with plain arrays.
 */

namespace Tests\Branding;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/BrandingResolver.php';

class BrandingResolverNormalizePropertyTest extends TestCase
{
    // ---- 1. Empty / missing everything -> all REYA defaults ----------------

    public function testEmptyInputsReturnAllReyaDefaults(): void
    {
        $brand = \BrandingResolver::normalize([], [], null);

        $this->assertSame(\BrandingResolver::DEFAULT_SHOP_NAME, $brand['shop_name']);
        $this->assertSame(\BrandingResolver::DEFAULT_LOGO_URL, $brand['logo_url']);
        $this->assertSame(\BrandingResolver::DEFAULT_THEME_COLOR, $brand['theme_color']);
        $this->assertSame('default', $brand['sources']['shop_name']);
        $this->assertSame('default', $brand['sources']['logo_url']);
        $this->assertSame('default', $brand['sources']['theme_color']);
    }

    // ---- 2. Fully provided valid data -> those exact values, no fallback ---

    public function testFullyProvidedValidDataIsUsedVerbatim(): void
    {
        $brand = \BrandingResolver::normalize(
            ['shop_name' => 'ร้านยาสมใจ', 'shop_logo' => '/uploads/shop/logo_42.png'],
            ['name' => 'OA Name', 'picture_url' => '/oa/pic.png'],
            '#F85606'
        );

        $this->assertSame('ร้านยาสมใจ', $brand['shop_name']);
        $this->assertSame('/uploads/shop/logo_42.png', $brand['logo_url']);
        $this->assertSame('#F85606', $brand['theme_color']);
        $this->assertSame('shop_settings', $brand['sources']['shop_name']);
        $this->assertSame('shop_settings', $brand['sources']['logo_url']);
        $this->assertSame('provided', $brand['sources']['theme_color']);
    }

    // ---- 3. Partial data -> correct per-field fallback (mix & match) -------

    public function testShopNameProvidedButLogoFallsBackToOaPicture(): void
    {
        $brand = \BrandingResolver::normalize(
            ['shop_name' => 'My Shop', 'shop_logo' => ''],
            ['name' => 'OA Name', 'picture_url' => '/oa/avatar.png'],
            null
        );

        $this->assertSame('My Shop', $brand['shop_name']);       // shop tier
        $this->assertSame('/oa/avatar.png', $brand['logo_url']); // OA tier
        $this->assertSame(\BrandingResolver::DEFAULT_THEME_COLOR, $brand['theme_color']); // default
        $this->assertSame('shop_settings', $brand['sources']['shop_name']);
        $this->assertSame('line_account', $brand['sources']['logo_url']);
    }

    public function testLogoProvidedButNameFallsBackToOaName(): void
    {
        $brand = \BrandingResolver::normalize(
            ['shop_logo' => '/uploads/shop/only-logo.png'],
            ['name' => 'OA Display Name'],
            null
        );

        $this->assertSame('OA Display Name', $brand['shop_name']);        // OA tier
        $this->assertSame('/uploads/shop/only-logo.png', $brand['logo_url']); // shop tier
        $this->assertSame('line_account', $brand['sources']['shop_name']);
        $this->assertSame('shop_settings', $brand['sources']['logo_url']);
    }

    // ---- 5. OA picture used as logo fallback (tier from commit 9496981) ----

    public function testOaPictureIsLogoFallbackWhenShopLogoAbsent(): void
    {
        $brand = \BrandingResolver::normalize(
            [],                                   // no shop settings at all
            ['name' => 'ร้าน OA', 'picture_url' => 'https://line.example/pic.jpg'],
            null
        );

        $this->assertSame('ร้าน OA', $brand['shop_name']);
        $this->assertSame('https://line.example/pic.jpg', $brand['logo_url']);
        $this->assertSame('line_account', $brand['sources']['logo_url']);
    }

    // ---- 6. Whitespace-only / null-ish treated as missing -----------------

    /**
     * @dataProvider blankValueProvider
     */
    public function testWhitespaceOrNullValuesTreatedAsMissing($blank): void
    {
        $brand = \BrandingResolver::normalize(
            ['shop_name' => $blank, 'shop_logo' => $blank],
            ['name' => $blank, 'picture_url' => $blank],
            $blank
        );

        // Everything blank at every tier => all REYA defaults, no crash.
        $this->assertSame(\BrandingResolver::DEFAULT_SHOP_NAME, $brand['shop_name']);
        $this->assertSame(\BrandingResolver::DEFAULT_LOGO_URL, $brand['logo_url']);
        $this->assertSame(\BrandingResolver::DEFAULT_THEME_COLOR, $brand['theme_color']);
    }

    public function blankValueProvider(): array
    {
        return [
            'null'         => [null],
            'empty string' => [''],
            'spaces'       => ['   '],
            'tabs'         => ["\t\t"],
            'newlines'     => ["\n\r\n"],
            'mixed ws'     => [" \t \n "],
        ];
    }

    // ---- 4. Invalid theme_color -> REYA default (randomized 100+ cases) ---

    /**
     * @dataProvider invalidHexProvider
     */
    public function testInvalidThemeColorFallsBackToDefault(string $garbage): void
    {
        $brand = \BrandingResolver::normalize(
            ['shop_name' => 'S', 'shop_logo' => '/l.png'],
            [],
            $garbage
        );

        $this->assertSame(
            \BrandingResolver::DEFAULT_THEME_COLOR,
            $brand['theme_color'],
            'Invalid hex "' . $garbage . '" should fall back to REYA default'
        );
        $this->assertSame('default', $brand['sources']['theme_color']);
        $this->assertFalse(\BrandingResolver::isValidHexColor($garbage));
    }

    public function invalidHexProvider(): array
    {
        $cases = [
            'no hash'        => ['F85606'],
            'three digit'    => ['#FFF'],
            'eight digit'    => ['#FF8560AA'],
            'five digit'     => ['#12345'],
            'seven digit'    => ['#1234567'],
            'non hex chars'  => ['#GGGGGG'],
            'named color'    => ['red'],
            'rgb()'          => ['rgb(255,0,0)'],
            'empty-ish'      => ['#'],
            'double hash'    => ['##F85606'],
        ];

        // Randomized garbage — 100+ cases per repo property-test convention.
        for ($i = 0; $i < 120; $i++) {
            $cases["random_{$i}"] = [$this->randomInvalidHex($i)];
        }

        return $cases;
    }

    // ---- valid hex acceptance across 100+ random valid colours ------------

    /**
     * @dataProvider validHexProvider
     */
    public function testValidHexColorIsAccepted(string $hex): void
    {
        $brand = \BrandingResolver::normalize([], [], $hex);

        $this->assertTrue(\BrandingResolver::isValidHexColor($hex));
        $this->assertSame($hex, $brand['theme_color']);
        $this->assertSame('provided', $brand['sources']['theme_color']);
    }

    public function validHexProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 100; $i++) {
            $hex = sprintf('#%06X', ($i * 2654435761) & 0xFFFFFF);
            $cases["valid_{$i}"] = [$hex];
        }
        // Lower-case + mixed-case forms must be accepted too.
        $cases['lower'] = ['#abcdef'];
        $cases['mixed'] = ['#AbCdEf'];
        return $cases;
    }

    /**
     * Deterministic garbage generator that never produces a valid #RRGGBB.
     */
    private function randomInvalidHex(int $seed): string
    {
        mt_srand($seed + 7919);
        $strategies = [
            // wrong length hex without/with hash
            fn () => '#' . substr(md5((string) $seed), 0, mt_rand(1, 5)),
            fn () => '#' . substr(md5((string) $seed), 0, mt_rand(7, 12)),
            fn () => substr(md5((string) $seed), 0, 6), // valid chars but no hash
            // contains non-hex letters
            fn () => '#' . str_repeat(chr(mt_rand(103, 122)), 6), // g-z
            // random junk
            fn () => 'color-' . $seed,
            fn () => '#' . $seed . 'xyz',
        ];
        $pick = $strategies[$seed % count($strategies)];
        $val  = $pick();

        // Guard: on the off chance a strategy yields a valid hex, mangle it.
        if (\BrandingResolver::isValidHexColor($val)) {
            $val = 'not#' . $val;
        }
        return $val;
    }
}
