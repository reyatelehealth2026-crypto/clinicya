<?php
/**
 * auth/google-callback.php — Google OAuth redirect target.
 *
 * 1. Verifies the CSRF state.
 * 2. Exchanges the auth code for tokens (server-side, with the client secret).
 * 3. Verifies the id_token (aud / iss / exp / email_verified) and extracts the
 *    Google sub + email + name.
 * 4. Routes:
 *      - known owner (platform_users.google_id has a tenant) → into their shop
 *      - new account → onboarding (choose shop name + subdomain)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sso_config.php';
require_once __DIR__ . '/../classes/TenantSso.php';
$cfg = __DIR__ . '/../config/google_oauth.php';
if (!is_file($cfg)) {
    http_response_code(500);
    exit('Google OAuth not configured.');
}
require_once $cfg;

function gerr(string $msg): void
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $base = defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com';
    echo '<!doctype html><meta charset="utf-8"><title>เข้าสู่ระบบไม่สำเร็จ</title>'
       . '<style>body{font-family:sans-serif;max-width:480px;margin:80px auto;text-align:center;color:#475569}</style>'
       . '<h1 style="color:#dc2626">เข้าสู่ระบบไม่สำเร็จ</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="https://' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/signup.php">ลองใหม่</a></p>';
    exit;
}

function b64url_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

// 1) CSRF state ------------------------------------------------------------
$state = (string)($_GET['state'] ?? '');
$want  = (string)($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);
if ($state === '' || $want === '' || !hash_equals($want, $state)) {
    gerr('คำขอไม่ถูกต้อง (state mismatch) กรุณาลองใหม่');
}
if (isset($_GET['error'])) {
    gerr('Google ปฏิเสธ: ' . (string)$_GET['error']);
}
$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    gerr('ไม่พบ authorization code');
}

// 2) Exchange code for tokens ---------------------------------------------
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($resp === false || $http !== 200) {
    error_log('[google-callback] token exchange failed http=' . $http . ' resp=' . substr((string)$resp, 0, 300));
    gerr('แลกโทเคนกับ Google ไม่สำเร็จ');
}
$token   = json_decode((string)$resp, true) ?: [];
$idToken = (string)($token['id_token'] ?? '');
if ($idToken === '' || substr_count($idToken, '.') !== 2) {
    gerr('ไม่ได้รับ id_token จาก Google');
}

// 3) Verify id_token -------------------------------------------------------
[$h64, $p64] = explode('.', $idToken);
$claims = json_decode(b64url_decode($p64), true) ?: [];
$iss = (string)($claims['iss'] ?? '');
$aud = (string)($claims['aud'] ?? '');
$exp = (int)($claims['exp'] ?? 0);
$validIss = in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true);
if (!$validIss || !hash_equals(GOOGLE_CLIENT_ID, $aud) || $exp < time()) {
    gerr('id_token ไม่ถูกต้องหรือหมดอายุ');
}
$sub   = (string)($claims['sub'] ?? '');
$email = strtolower((string)($claims['email'] ?? ''));
$name  = (string)($claims['name'] ?? '') ?: $email;
$emailVerified = !empty($claims['email_verified']);
if ($sub === '' || $email === '' || !$emailVerified) {
    gerr('บัญชี Google นี้ยังไม่ยืนยันอีเมล');
}

// 4) Route -----------------------------------------------------------------
$db = Database::platform()->getConnection();
$base = defined('REYA_BASE_DOMAIN') ? REYA_BASE_DOMAIN : 're-ya.com';

$stmt = $db->prepare('SELECT id, tenant_id, role, name FROM platform_users WHERE google_id = ? OR email = ? LIMIT 1');
$stmt->execute([$sub, $email]);
$pu = $stmt->fetch(PDO::FETCH_ASSOC);

// 4a) Platform owner → straight into the Platform Owner console.
//     Identity is gated SOLELY on the explicit REYA_OWNER_EMAILS allowlist
//     (config/notify_config.php). We must NOT use platform_users.role here:
//     SelfServeProvisioning seeds every SHOP owner as role='owner' (a tenant
//     owner, not a platform owner), so a role check would let any shop owner
//     into the platform console. Allowlist email only.
$ownerCfg = __DIR__ . '/../config/notify_config.php';
if (is_file($ownerCfg)) {
    require_once $ownerCfg;
}
$ownerEmails = array_filter(array_map(
    'trim',
    explode(',', strtolower(defined('REYA_OWNER_EMAILS') ? REYA_OWNER_EMAILS : ''))
));
if ($email !== '' && in_array($email, $ownerEmails, true)) {
    try {
        if ($pu) {
            $puId = (int)$pu['id'];
            $db->prepare('UPDATE platform_users SET google_id = ?, auth_provider = "google", last_login_at = NOW() WHERE id = ?')
               ->execute([$sub, $puId]);
            $puName = (string)($pu['name'] ?? '') ?: $name;
        } else {
            $db->prepare(
                'INSERT INTO platform_users (email, password_hash, name, google_id, role, auth_provider, last_login_at, created_at)
                 VALUES (?, "", ?, ?, "super_admin", "google", NOW(), NOW())'
            )->execute([$email, $name, $sub]);
            $puId   = (int)$db->lastInsertId();
            $puName = $name;
        }
        $_SESSION['platform_user_id']   = $puId;
        $_SESSION['platform_user_name'] = $puName;
        header('Location: /admin/platform-dashboard.php', true, 302);
        exit;
    } catch (\Throwable $eOwner) {
        // Fall through to normal routing rather than blocking login.
        error_log('[google-callback] owner login failed: ' . $eOwner->getMessage());
    }
}

if ($pu && !empty($pu['tenant_id'])) {
    // Known owner → fetch their shop and send them to it.
    $t = $db->prepare('SELECT slug, status FROM tenants WHERE id = ? LIMIT 1');
    $t->execute([(int)$pu['tenant_id']]);
    $tenant = $t->fetch(PDO::FETCH_ASSOC);
    if ($tenant) {
        // Backfill google_id if they previously had only email.
        $db->prepare('UPDATE platform_users SET google_id = ?, auth_provider = "google", last_login_at = NOW() WHERE id = ?')
           ->execute([$sub, (int)$pu['id']]);
        // Mint a short-lived SSO token and hand off to the tenant subdomain,
        // which logs the owner straight into their dashboard (auth/sso-consume.php).
        // If the shop is still pending_setup, resolve_subdomain shows the
        // "waiting for approval" screen before sso-consume even runs.
        $ssoToken = TenantSso::sign($email, (int)$pu['tenant_id'], (string)$tenant['slug']);
        header('Location: https://' . $tenant['slug'] . '.' . $base . '/auth/sso-consume.php?token=' . urlencode($ssoToken), true, 302);
        exit;
    }
}

// New account (or staff without a tenant) → onboarding.
$_SESSION['google_pending'] = ['sub' => $sub, 'email' => $email, 'name' => $name, 'ts' => time()];
header('Location: /auth/google-onboard.php', true, 302);
exit;
