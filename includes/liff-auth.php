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
                if (strcasecmp($k, 'Authorization') === 0) {
                    $hdr = $v;
                    break;
                }
            }
        }
        if ($hdr === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $hdr = $v;
                    break;
                }
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

if (!function_exists('reya_liff_strict_mode')) {
    /**
     * Is this deployment refusing loyalty requests that carry NO token at all?
     *
     * PHASE 6, staged rollout. Verifying a token that IS present is always on and
     * costs nothing, because a request that never had one cannot fail it. But
     * every shipped mini-app build sends `line_user_id` and no Authorization
     * header, so flipping straight to fail-closed would log every customer out of
     * their points until the new client reaches them.
     *
     * So: reject a WRONG token immediately (that is an attack, never a stale
     * client), and reject a MISSING token only once the operator sets
     * LIFF_STRICT_AUTH — after the logs show tokens arriving from real traffic.
     *
     * Configure via the LIFF_STRICT_AUTH constant (config/config.php) or the
     * environment variable of the same name.
     */
    function reya_liff_strict_mode(): bool
    {
        if (defined('LIFF_STRICT_AUTH')) {
            return (bool) constant('LIFF_STRICT_AUTH');
        }

        $env = getenv('LIFF_STRICT_AUTH');
        if ($env === false || $env === '') {
            return false;
        }

        return in_array(strtolower(trim($env)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('reya_liff_guard')) {
    /**
     * Establish who the caller really is, for an endpoint that takes a
     * `line_user_id` from request input.
     *
     * Three outcomes:
     *   - token present and matches   -> returns the VERIFIED id (use this one)
     *   - token present and mismatches -> 401 and the request ends
     *   - token absent                 -> 401 in strict mode; otherwise returns
     *                                     the claimed id and logs the gap
     *
     * Callers should use the RETURNED id from here on, never the raw request
     * value: they are the same string in the happy path, and where they differ
     * the request had no business proceeding on the claimed one.
     *
     * @param string $claimedUserId the line_user_id the request asserts
     * @param string $context       endpoint name, for the log line
     * @return string the identity to act on
     */
    function reya_liff_guard(string $claimedUserId, string $context = 'loyalty'): string
    {
        if ($claimedUserId === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Missing line_user_id',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $token = reya_bearer_token();

        if ($token === null || $token === '') {
            if (reya_liff_strict_mode()) {
                error_log(sprintf('liff-auth[%s]: reject (no token, strict mode)', $context));
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => 'Unauthorized — LIFF token required',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Transitional. Every one of these is a request whose identity is
            // taken on trust; the count is what tells an operator when it is
            // safe to turn strict mode on.
            error_log(sprintf(
                'liff-auth[%s]: UNVERIFIED request (no token) claimed=%s ip=%s',
                $context,
                substr($claimedUserId, 0, 8) . '…',
                $_SERVER['REMOTE_ADDR'] ?? '-'
            ));

            return $claimedUserId;
        }

        // A token WAS supplied. From here it must be valid and it must match —
        // a wrong token is never a stale client, so this is fail-closed
        // regardless of strict mode.
        reya_require_liff_user($claimedUserId);

        return $claimedUserId;
    }
}
