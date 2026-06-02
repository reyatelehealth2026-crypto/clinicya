<?php
/**
 * LIFF / LINE Login access-token verification.
 *
 * The Mini App sends `Authorization: Bearer <liff.getAccessToken()>`. We verify
 * the token against LINE's profile endpoint — a valid, unexpired token returns
 * the caller's real `userId`, which must match the `line_user_id` the request
 * claims to act on. This closes the IDOR class where any caller could pass an
 * arbitrary `line_user_id` to read or mutate another user's data.
 *
 * A valid access token issued under the same LINE provider yields the same
 * provider-scoped userId as the Messaging API, so comparing the verified userId
 * against the stored line_user_id is sufficient proof of ownership.
 */

if (!function_exists('reya_bearer_token')) {
    /** Extract the bearer token from the Authorization header (Apache strips it sometimes). */
    function reya_bearer_token(): ?string
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($hdr === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
            }
        }
        if ($hdr === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
            }
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim((string) $hdr), $m)) {
            return trim($m[1]);
        }
        return null;
    }
}

if (!function_exists('reya_liff_resolve_user_id')) {
    /**
     * Resolve the verified LINE userId for the request's bearer token.
     * Returns null on any failure (missing / invalid / expired token, network error).
     */
    function reya_liff_resolve_user_id(): ?string
    {
        $token = reya_bearer_token();
        if ($token === null || $token === '') {
            return null;
        }

        $ch = curl_init('https://api.line.me/v2/profile');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) {
            return null;
        }
        $data = json_decode($resp, true);
        $uid  = is_array($data) ? ($data['userId'] ?? null) : null;
        return (is_string($uid) && $uid !== '') ? $uid : null;
    }
}

if (!function_exists('reya_require_liff_user')) {
    /**
     * Fail-closed guard. Verifies the bearer token belongs to $claimedUserId.
     * On a missing token or mismatch, emits 401 JSON and terminates the request.
     */
    function reya_require_liff_user(string $claimedUserId): void
    {
        $verified = reya_liff_resolve_user_id();
        if ($verified === null || !hash_equals($verified, $claimedUserId)) {
            error_log(sprintf(
                'liff-auth: reject (claimed=%s, verified=%s, ip=%s)',
                substr($claimedUserId, 0, 8) . '…',
                $verified !== null ? substr($verified, 0, 8) . '…' : 'none',
                $_SERVER['REMOTE_ADDR'] ?? '-'
            ));
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error'   => 'Unauthorized — LIFF token missing or does not match user',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
