<?php
/**
 * LIFF Helper Functions
 * ใช้สำหรับดึง Unified LIFF ID และข้อมูลที่จำเป็น
 */

/**
 * Decide whether a stored liff_id is a REAL, usable LIFF id.
 *
 * A tenant counts as "LIFF-connected" only when line_accounts.liff_id holds a
 * genuine value. Two states mean "not connected yet":
 *   - empty / null   → never set
 *   - 'PENDING…'     → provisioning placeholder seeded for tenants that have an
 *                      OA but have not finished LIFF setup
 *
 * Customers of unconnected tenants must NOT be sent into the shared Mini App
 * (it would init against the wrong/blank LIFF and break) — they fall back to
 * the OA chat instead. See reya_liff_url_or_oa().
 *
 * @param string|null $liffId
 * @return bool
 */
function reya_is_real_liff_id(?string $liffId): bool
{
    if ($liffId === null) {
        return false;
    }
    $liffId = trim($liffId);
    if ($liffId === '') {
        return false;
    }
    // Case-insensitive PENDING* placeholder guard.
    if (stripos($liffId, 'PENDING') === 0) {
        return false;
    }
    return true;
}

/**
 * Build the OA chat fallback URL for a line account.
 *
 * Uses the LINE Basic ID (line_accounts.basic_id, e.g. "@abc1234"). LINE accepts
 * the basic id with or without the leading '@' in the add-friend / chat deep
 * link. Returns '' when no basic_id is on file — caller then renders NO button.
 *
 * @param array<string,mixed> $account A line_accounts row (must contain basic_id)
 * @return string  e.g. "https://line.me/R/ti/p/@abc1234" or '' when unavailable
 */
function reya_oa_chat_url(array $account): string
{
    $basicId = trim((string) ($account['basic_id'] ?? ''));
    if ($basicId === '') {
        return '';
    }
    // LINE deep link to open the OA 1:1 chat / add-friend screen.
    return 'https://line.me/R/ti/p/' . rawurlencode($basicId);
}

/**
 * SINGLE entry point: return the URL a Mini-App button should open for a tenant.
 *
 * Decision:
 *   1. line_accounts.liff_id is REAL  → LIFF deep link
 *        "https://liff.line.me/{liffId}{deepLinkPath}?la={lineAccountId}&liff_id={liffId}"
 *      (deep link carries both ?la= and ?liff_id= so the shared Mini App knows
 *       which tenant + LIFF id it is serving before liff.init() — see
 *       line-mini-app/src/lib/config.ts).
 *   2. otherwise                      → OA chat URL (reya_oa_chat_url), or ''
 *
 * An empty return means "do not render a Mini-App button at all" — callers that
 * already gate on a non-empty URL (FlexTemplates::liffMenu / firstMessageMenu)
 * will simply omit the broken button, which is the desired behaviour.
 *
 * @param PDO         $db
 * @param int|null    $lineAccountId
 * @param string      $deepLinkPath  Path/query appended after the liff id,
 *                                   e.g. "" | "/shop" | "/order?id=123".
 *                                   MUST start with '/' (or '?') when non-empty.
 * @return string
 */
function reya_liff_url_or_oa($db, ?int $lineAccountId, string $deepLinkPath = ''): string
{
    $account = reya_get_line_account_link_row($db, $lineAccountId);
    if ($account === null) {
        return '';
    }

    if (reya_is_real_liff_id($account['liff_id'] ?? null)) {
        $base = 'https://liff.line.me/' . $account['liff_id'];
        // Tag the deep link with the tenant's line_account_id so the shared
        // Mini App resolves the correct tenant/LIFF at runtime instead of
        // trusting build-time NEXT_PUBLIC_* constants.
        return reya_append_liff_context_params(
            $base . $deepLinkPath,
            (int) $account['id'],
            (string) $account['liff_id']
        );
    }

    // No usable LIFF → OA chat fallback (may be '' if no basic_id on file).
    return reya_oa_chat_url($account);
}

/**
 * Append Mini-App routing context while preserving any URL fragment.
 *
 * @param string $url
 * @param int    $lineAccountId
 * @param string $liffId
 * @return string
 */
function reya_append_liff_context_params(string $url, int $lineAccountId, string $liffId): string
{
    $fragment = '';
    $hashPos = strpos($url, '#');
    if ($hashPos !== false) {
        $fragment = substr($url, $hashPos);
        $url = substr($url, 0, $hashPos);
    }

    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    return $url
        . $sep
        . 'la=' . rawurlencode((string) $lineAccountId)
        . '&liff_id=' . rawurlencode($liffId)
        . $fragment;
}

/**
 * Fetch the minimal line_accounts row needed for link decisions.
 * Cached per request to avoid repeat queries inside one webhook/dispense call.
 *
 * @param PDO      $db
 * @param int|null $lineAccountId  null → default active account
 * @return array<string,mixed>|null
 */
function reya_get_line_account_link_row($db, ?int $lineAccountId): ?array
{
    // Per-request cache. TenantContext pins exactly one tenant DB per request,
    // so an account id maps to one row; this just avoids a repeat query when the
    // same account is resolved twice in one webhook/dispense call. Call
    // reya_liff_helper_reset_cache() when reusing the process across tenants
    // (long-running CLI loops, tests).
    $cache = &reya_liff_helper_cache();
    $cacheKey = $lineAccountId === null ? 'default' : (string) $lineAccountId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        if ($lineAccountId !== null && $lineAccountId > 0) {
            $stmt = $db->prepare("SELECT id, liff_id, basic_id, name FROM line_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$lineAccountId]);
        } else {
            $stmt = $db->query("SELECT id, liff_id, basic_id, name FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1");
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $cache[$cacheKey] = ($row ?: null);
    } catch (Exception $e) {
        error_log("reya_get_line_account_link_row error: " . $e->getMessage());
        return $cache[$cacheKey] = null;
    }
}

/**
 * Backing store for the per-request line-account cache. Returned by reference so
 * callers can read/write a single shared array.
 *
 * @return array<string,array<string,mixed>|null>
 */
function &reya_liff_helper_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Clear the per-request line-account cache. Use between tenants in CLI loops or
 * between test cases.
 */
function reya_liff_helper_reset_cache(): void
{
    $cache = &reya_liff_helper_cache();
    $cache = [];
}

/**
 * Get Unified LIFF ID from line_accounts
 * ใช้ liff_id เดียวสำหรับทุกหน้า
 */
function getUnifiedLiffId($db, $lineAccountId = null) {
    try {
        if ($lineAccountId) {
            $stmt = $db->prepare("SELECT id, liff_id, name FROM line_accounts WHERE id = ?");
            $stmt->execute([$lineAccountId]);
        } else {
            $stmt = $db->query("SELECT id, liff_id, name FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1");
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'liff_id' => $row['liff_id'] ?? '',
                'line_account_id' => $row['id'],
                'account_name' => $row['name']
            ];
        }
    } catch (Exception $e) {
        error_log("getUnifiedLiffId error: " . $e->getMessage());
    }
    return ['liff_id' => '', 'line_account_id' => 1, 'account_name' => ''];
}

/**
 * Get shop settings
 */
function getShopSettings($db, $lineAccountId = null) {
    try {
        if ($lineAccountId) {
            $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1");
            $stmt->execute([$lineAccountId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (empty($settings)) {
            $stmt = $db->query("SELECT * FROM shop_settings LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return $settings ?: [
            'shop_name' => 'LINE Shop',
            'shipping_fee' => 50,
            'free_shipping_min' => 500
        ];
    } catch (Exception $e) {
        return ['shop_name' => 'LINE Shop', 'shipping_fee' => 50, 'free_shipping_min' => 500];
    }
}

/**
 * Get line_account_id from user's line_user_id
 */
function getLineAccountIdFromUser($db, $lineUserId) {
    if (!$lineUserId) return null;
    try {
        $stmt = $db->prepare("SELECT line_account_id FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        return $stmt->fetchColumn() ?: null;
    } catch (Exception $e) {
        return null;
    }
}
