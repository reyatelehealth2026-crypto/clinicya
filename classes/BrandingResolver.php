<?php
/**
 * BrandingResolver — ตัวแก้แบรนด์ต่อ tenant (white-label)
 *
 * Resolves the per-tenant white-label brand (shop name, logo, theme colour)
 * for a given LINE account, with a deterministic fallback chain down to the
 * REYA defaults. This centralises the same fallback logic that the sidebar
 * brand block in includes/header.php grew inline (commits 9496981 + 7e6ce1f):
 *
 *   shop_name    : shop_settings.shop_name  -> line_accounts.name        -> 'REYA Pharmacy'
 *   logo_url     : shop_settings.shop_logo  -> line_accounts.picture_url -> REYA default logo
 *   theme_color  : provided/valid hex       -> REYA default green
 *
 * The DB-free normalisation is a pure static method (`normalize`) so it can be
 * unit-tested with plain arrays and reused by any surface (header, mini-app
 * config export, etc.) without a database.
 */

class BrandingResolver
{
    /** REYA brand defaults (match includes/header.php + assets/css theme). */
    public const DEFAULT_SHOP_NAME   = 'REYA Pharmacy';
    public const DEFAULT_LOGO_URL    = '/uploads/shop/logo_1_1778797967.png';
    public const DEFAULT_THEME_COLOR = '#00B900'; // LINE green — header --primary default

    /** @var PDO */
    private $db;

    public function __construct(?PDO $db = null)
    {
        if ($db) {
            $this->db = $db;
        } else {
            require_once __DIR__ . '/../config/database.php';
            $this->db = Database::getInstance()->getConnection();
        }
    }

    /**
     * Resolve the normalized brand struct for a LINE account within the
     * current tenant scope. Never throws on missing tables/rows — falls
     * through to REYA defaults.
     *
     * @return array{shop_name:string, logo_url:string, theme_color:string, sources:array}
     */
    public function resolveForLineAccount(int $lineAccountId): array
    {
        $shopSettings = [];
        $lineAccount  = [];
        $themeColor   = null;

        try {
            $s = $this->db->prepare(
                'SELECT shop_name, shop_logo FROM shop_settings WHERE line_account_id = ? LIMIT 1'
            );
            $s->execute([$lineAccountId]);
            $shopSettings = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { /* table might be missing on some tenants */ }

        try {
            $s = $this->db->prepare(
                'SELECT name, picture_url FROM line_accounts WHERE id = ? LIMIT 1'
            );
            $s->execute([$lineAccountId]);
            $lineAccount = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { /* defensive */ }

        try {
            // Theme colour lives in the promotion_settings key/value store
            // (setting_key = 'primary_color'), same source api/manifest.php reads.
            $s = $this->db->prepare(
                "SELECT setting_value FROM promotion_settings WHERE line_account_id = ? AND setting_key = 'primary_color' LIMIT 1"
            );
            $s->execute([$lineAccountId]);
            $themeColor = $s->fetchColumn() ?: null;
        } catch (\Throwable $e) { /* optional */ }

        return self::normalize($shopSettings, $lineAccount, $themeColor);
    }

    /**
     * Convenience static entry: resolve using the default DB singleton.
     */
    public static function forLineAccount(int $lineAccountId): array
    {
        return (new self())->resolveForLineAccount($lineAccountId);
    }

    /**
     * Pure, DB-free normalisation of raw branding inputs into a brand struct.
     * All inputs are optional; missing/blank/whitespace-only values fall
     * through the tier chain to the REYA defaults.
     *
     * @param array       $shopSettings Raw shop_settings row (shop_name, shop_logo).
     * @param array       $lineAccount  Raw line_accounts row (name, picture_url).
     * @param string|null $themeColor   Candidate theme colour (hex #RRGGBB) or null.
     *
     * @return array{shop_name:string, logo_url:string, theme_color:string, sources:array}
     */
    public static function normalize(array $shopSettings, array $lineAccount = [], ?string $themeColor = null): array
    {
        $shopName    = self::clean($shopSettings['shop_name'] ?? null);
        $shopLogo    = self::clean($shopSettings['shop_logo'] ?? null);
        $oaName      = self::clean($lineAccount['name'] ?? null);
        $oaPicture   = self::clean($lineAccount['picture_url'] ?? null);
        $candidateHx = self::clean($themeColor);

        // shop_name : shop -> OA display name -> REYA default
        if ($shopName !== '') {
            $name       = $shopName;
            $nameSource = 'shop_settings';
        } elseif ($oaName !== '') {
            $name       = $oaName;
            $nameSource = 'line_account';
        } else {
            $name       = self::DEFAULT_SHOP_NAME;
            $nameSource = 'default';
        }

        // logo_url : shop logo -> OA picture -> REYA default logo
        if ($shopLogo !== '') {
            $logo       = $shopLogo;
            $logoSource = 'shop_settings';
        } elseif ($oaPicture !== '') {
            $logo       = $oaPicture;
            $logoSource = 'line_account';
        } else {
            $logo       = self::DEFAULT_LOGO_URL;
            $logoSource = 'default';
        }

        // theme_color : provided valid hex -> REYA default
        if ($candidateHx !== '' && self::isValidHexColor($candidateHx)) {
            $color       = $candidateHx;
            $colorSource = 'provided';
        } else {
            $color       = self::DEFAULT_THEME_COLOR;
            $colorSource = 'default';
        }

        return [
            'shop_name'   => $name,
            'logo_url'    => $logo,
            'theme_color' => $color,
            'sources'     => [
                'shop_name'   => $nameSource,
                'logo_url'    => $logoSource,
                'theme_color' => $colorSource,
            ],
        ];
    }

    /**
     * Validate a hex colour string in strict #RRGGBB form.
     */
    public static function isValidHexColor(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', trim($value));
    }

    /**
     * Coerce a value to a trimmed string; null/non-scalar/whitespace-only => ''.
     */
    private static function clean($value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        return trim((string) $value);
    }
}
