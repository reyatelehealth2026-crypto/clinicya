<?php
/**
 * internal/session-bridge.php — bidirectional PHP <-> Next.js session bridge
 * (plan docs/plans/2026-07-12-nextjs-full-migration-plan.md §1.4, ADR-006).
 *
 * Next.js is the source of truth for auth during Phase 1 (packages/auth).
 * After login()/switchBot()/switchTenant()/logout() completes its Node-side
 * state change, it POSTs here (best-effort — see @reya/auth's
 * bridgeClient.ts) so legacy PHP pages (includes/auth_check.php,
 * classes/AdminAuth.php, admin/switch-tenant.php) keep working against
 * $_SESSION without being rewritten.
 *
 * Populates $_SESSION using the EXACT keys those files already read:
 *   admin_user, current_bot_id, active_tenant_id, platform_user_id,
 *   platform_user_email, platform_user_name, platform_user_role,
 *   admin_switched_to_tenant_id
 * Do not invent new keys — if a new field is ever needed, add it to BOTH
 * this file and packages/auth/src/types.ts's BridgePhpSessionKeys together.
 *
 * Actions: login-sync | set_bot | set_tenant | destroy | introspect.
 *
 * Security:
 *   - HMAC-SHA256 over the raw request body, shared secret
 *     SESSION_BRIDGE_HMAC_SECRET (same env var name as packages/config's
 *     zod schema), header X-Reya-Signature. Verified with hash_equals()
 *     before ANY $_SESSION access — an unsigned/mis-signed/stale request is
 *     rejected 403 first.
 *   - issuedAt (unix seconds, inside the signed body) must be within a
 *     300-second replay window of the current time — same window
 *     api/odoo-webhook.php::verifySignature() already uses elsewhere in
 *     this codebase.
 *
 * DEPLOYMENT — INTERNAL NETWORK ONLY. This endpoint must never be reachable
 * from the public internet: it accepts an externally-supplied session
 * identifier (see the `sid` handling below) and, while HMAC-authenticated,
 * is designed to sit behind Docker-internal-network isolation / an nginx
 * `internal;` location, not to be internet-facing defense-in-depth on its
 * own. Wiring that real enforcement (nginx allow/deny, firewall rules) is
 * mig-infra's job (plan Phase 0/13) — internal/.htaccess in this directory
 * is only a conservative "deny all via the public Apache vhost" default
 * until mig-infra explicitly carves out the internal Docker network CIDR;
 * it is not itself the security boundary.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/**
 * Emits a JSON error response and halts execution. Always called BEFORE any
 * $_SESSION read/write for a rejected request.
 */
function reya_bridge_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['acknowledged' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Applies a partial BridgePhpSessionKeys map (packages/auth/src/types.ts)
 * onto $_SESSION. A key present with value null UNSETS that session key
 * (mirrors AdminAuth::logout()'s `unset($_SESSION[...])` pattern); a key
 * absent from $keys is left untouched.
 */
function reya_bridge_apply_keys(array $keys): void
{
    $stringKeys = ['platform_user_email', 'platform_user_name', 'platform_user_role'];
    $intKeys = ['current_bot_id', 'active_tenant_id', 'platform_user_id', 'admin_switched_to_tenant_id'];

    if (array_key_exists('admin_user', $keys)) {
        if ($keys['admin_user'] === null) {
            unset($_SESSION['admin_user']);
        } else {
            $_SESSION['admin_user'] = $keys['admin_user'];
        }
    }

    foreach ($intKeys as $key) {
        if (!array_key_exists($key, $keys)) {
            continue;
        }
        if ($keys[$key] === null) {
            unset($_SESSION[$key]);
        } else {
            $_SESSION[$key] = (int) $keys[$key];
        }
    }

    foreach ($stringKeys as $key) {
        if (!array_key_exists($key, $keys)) {
            continue;
        }
        if ($keys[$key] === null) {
            unset($_SESSION[$key]);
        } else {
            $_SESSION[$key] = (string) $keys[$key];
        }
    }
}

// -----------------------------------------------------------------------------
// 1) Method + signature + replay-window verification — BEFORE touching
//    $_SESSION or trusting the parsed JSON body.
// -----------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reya_bridge_fail(405, 'method_not_allowed');
}

$secret = getenv('SESSION_BRIDGE_HMAC_SECRET');
if ($secret === false || $secret === '') {
    $secret = $_ENV['SESSION_BRIDGE_HMAC_SECRET'] ?? '';
}
if ($secret === '') {
    // Fail closed — an unconfigured secret must never be treated as "no
    // signature required".
    error_log('[session-bridge] SESSION_BRIDGE_HMAC_SECRET not configured — refusing all requests');
    reya_bridge_fail(503, 'bridge_not_configured');
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$signatureHeader = $_SERVER['HTTP_X_REYA_SIGNATURE'] ?? '';
if ($signatureHeader === '') {
    reya_bridge_fail(403, 'missing_signature');
}

$expectedSignature = hash_hmac('sha256', $rawBody, $secret);
if (!hash_equals($expectedSignature, $signatureHeader)) {
    reya_bridge_fail(403, 'bad_signature');
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    reya_bridge_fail(400, 'invalid_json');
}

// Replay window — 300s, same window api/odoo-webhook.php::verifySignature() uses.
$issuedAt = (int) ($payload['issuedAt'] ?? 0);
if ($issuedAt <= 0 || abs(time() - $issuedAt) > 300) {
    reya_bridge_fail(403, 'stale_request');
}

$action = (string) ($payload['action'] ?? '');
$sid = (string) ($payload['sid'] ?? '');
$phpSessionKeys = is_array($payload['phpSessionKeys'] ?? null) ? $payload['phpSessionKeys'] : [];

$validActions = ['login-sync', 'set_bot', 'set_tenant', 'destroy', 'introspect'];
if (!in_array($action, $validActions, true)) {
    reya_bridge_fail(400, 'unknown_action');
}

// The Node kernel's opaque session id is crypto.randomBytes(32).toString('hex')
// — 64 lowercase hex chars. Validate the shape before ever handing it to
// session_id() below.
if ($sid === '' || !preg_match('/^[a-f0-9]{16,128}$/', $sid)) {
    reya_bridge_fail(400, 'invalid_sid');
}

// -----------------------------------------------------------------------------
// 2) Target the exact PHP session Node is describing. The Node kernel reuses
//    its own opaque session id (the reya_sid / reya_platform_sid cookie
//    value, see packages/auth/src/types.ts's BridgeSyncPayload.sid doc
//    comment) AS the PHP session id: session_id($sid) here, before
//    session_start(), makes this request operate on that exact $_SESSION —
//    the same one PHP's native session_start() resolves to once the browser
//    presents a PHPSESSID cookie carrying the same value. Wiring that
//    PHPSESSID cookie on the Next side is apps/admin's (mig-ui) job, out of
//    scope for this file.
//
//    session.use_strict_mode (when a host enables it) would otherwise
//    reject an externally-supplied id that PHP never itself generated,
//    silently issuing a random one instead and breaking the whole point of
//    this bridge — disabled here, scoped to this script only, not globally.
// -----------------------------------------------------------------------------

ini_set('session.use_strict_mode', '0');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_id($sid);
session_start();

switch ($action) {
    case 'login-sync':
        // Privilege elevation — mirrors classes/AdminAuth.php::login() /
        // admin/platform-login.php's session_regenerate_id(true) on the PHP side too.
        session_regenerate_id(true);
        reya_bridge_apply_keys($phpSessionKeys);
        break;

    case 'set_tenant':
        // Only rotate when ENTERING impersonation (privilege elevation) —
        // mirrors admin/switch-tenant.php's 'enter' action. 'exit' (i.e.
        // admin_switched_to_tenant_id explicitly present and null) doesn't
        // need its own PHP-side rotation — the Node side already issued a
        // new sid before calling here in both cases.
        if (
            array_key_exists('admin_switched_to_tenant_id', $phpSessionKeys)
            && $phpSessionKeys['admin_switched_to_tenant_id'] !== null
        ) {
            session_regenerate_id(true);
        }
        reya_bridge_apply_keys($phpSessionKeys);
        break;

    case 'set_bot':
        reya_bridge_apply_keys($phpSessionKeys);
        break;

    case 'destroy':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        echo json_encode(['acknowledged' => true], JSON_UNESCAPED_UNICODE);
        exit;

    case 'introspect':
        // Read-only — reports what this PHP session currently holds, for
        // synthetic-probe / debugging use (risk register #2's "session
        // bridge down" monitor is a later infra concern; this action just
        // makes the state observable).
        echo json_encode(
            [
                'acknowledged' => true,
                'session' => [
                    'admin_user'                 => $_SESSION['admin_user'] ?? null,
                    'current_bot_id'              => $_SESSION['current_bot_id'] ?? null,
                    'active_tenant_id'             => $_SESSION['active_tenant_id'] ?? null,
                    'platform_user_id'             => $_SESSION['platform_user_id'] ?? null,
                    'platform_user_email'          => $_SESSION['platform_user_email'] ?? null,
                    'platform_user_name'           => $_SESSION['platform_user_name'] ?? null,
                    'platform_user_role'           => $_SESSION['platform_user_role'] ?? null,
                    'admin_switched_to_tenant_id'  => $_SESSION['admin_switched_to_tenant_id'] ?? null,
                ],
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;

    default:
        // Unreachable — $action was already validated against $validActions above.
        reya_bridge_fail(400, 'unknown_action');
}

echo json_encode(['acknowledged' => true], JSON_UNESCAPED_UNICODE);
