<?php
/**
 * TenantSso — short-lived, HMAC-signed handoff token for logging a Google-
 * authenticated owner from the ROOT domain into THEIR tenant subdomain.
 *
 * Google OAuth can only redirect to one registered URI (re-ya.com), so the
 * root authenticates, then mints a token the subdomain can verify locally.
 * Both run the same codebase + the same SSO_SECRET_KEY (config/sso_config.php),
 * so the HMAC verifies without any shared storage.
 *
 * Token shape:  base64url(json payload) . base64url(hmac_sha256(payload))
 * Payload:      { email, tid, slug, purpose:'tenant_login', exp }
 */
declare(strict_types=1);

final class TenantSso
{
    private const PURPOSE = 'tenant_login';
    private const TTL      = 30; // seconds — single short-lived hop

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    private static function secret(): string
    {
        if (!defined('SSO_SECRET_KEY') || SSO_SECRET_KEY === '') {
            throw new RuntimeException('SSO_SECRET_KEY not configured.');
        }
        return (string) SSO_SECRET_KEY;
    }

    /** Mint a token for owner $email → tenant $tid ($slug). */
    public static function sign(string $email, int $tid, string $slug): string
    {
        $payload = [
            'email'   => strtolower($email),
            'tid'     => $tid,
            'slug'    => $slug,
            'purpose' => self::PURPOSE,
            'exp'     => time() + self::TTL,
        ];
        $body = self::b64((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        $sig  = self::b64(hash_hmac('sha256', $body, self::secret(), true));
        return $body . '.' . $sig;
    }

    /**
     * Verify a token. Returns the payload array on success, or null if the
     * signature is bad, the token expired, or the purpose mismatches.
     */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        $expect = self::b64(hash_hmac('sha256', $body, self::secret(), true));
        if (!hash_equals($expect, $sig)) {
            return null;
        }
        $p = json_decode(self::unb64($body), true);
        if (!is_array($p)
            || ($p['purpose'] ?? '') !== self::PURPOSE
            || (int)($p['exp'] ?? 0) < time()
            || empty($p['email']) || empty($p['tid'])) {
            return null;
        }
        return $p;
    }
}
