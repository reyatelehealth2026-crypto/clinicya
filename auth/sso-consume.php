<?php
/**
 * auth/sso-consume.php — tenant-side end of the Google SSO handoff.
 *
 * Runs ON the tenant subdomain (e.g. myshop.re-ya.com). resolve_subdomain has
 * already pinned TenantContext to this shop (and would have shown the
 * "waiting for approval" screen instead if the shop were still pending_setup).
 *
 * Validates the HMAC token minted by the root (auth/google-callback.php),
 * confirms it was issued for THIS tenant, then establishes the same admin
 * session AdminAuth::login() would — so the owner lands logged into their
 * dashboard without ever typing a password.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/sso_config.php';
require_once __DIR__ . '/../classes/TenantSso.php';

function sso_fail(string $msg): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>เข้าสู่ระบบไม่สำเร็จ</title>'
       . '<style>body{font-family:sans-serif;max-width:460px;margin:80px auto;text-align:center;color:#475569}</style>'
       . '<h1 style="color:#dc2626">เข้าสู่ระบบไม่สำเร็จ</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="/auth/login.php">เข้าสู่ระบบด้วยรหัสผ่าน</a></p>';
    exit;
}

$token = (string)($_GET['token'] ?? '');
$claims = $token !== '' ? TenantSso::verify($token) : null;
if (!$claims) {
    sso_fail('โทเคนไม่ถูกต้องหรือหมดอายุ กรุณาเข้าสู่ระบบใหม่');
}

// The token must have been minted for THIS subdomain's tenant (anti-replay).
$currentTid = 0;
if (class_exists('TenantContext')) {
    $currentTid = (int) (TenantContext::getCurrentTenantId() ?? 0);
}
if ($currentTid === 0) {
    $currentTid = (int)($_SESSION['active_tenant_id'] ?? 0);
}
if ($currentTid === 0 || (int)$claims['tid'] !== $currentTid) {
    sso_fail('โทเคนนี้ไม่ตรงกับร้านนี้');
}

$email = strtolower((string)$claims['email']);

try {
    $db = Database::getInstance()->getConnection(); // tenant-scoped connection
    $stmt = $db->prepare('SELECT * FROM admin_users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        sso_fail('ไม่พบบัญชีเจ้าของร้านนี้');
    }

    // Mirror AdminAuth::login() — last_login + session shape.
    try {
        $db->prepare('UPDATE admin_users SET last_login = NOW(), login_count = COALESCE(login_count, 0) + 1 WHERE id = ?')
           ->execute([$user['id']]);
    } catch (\Throwable $e) {
        // non-fatal
    }

    unset($user['password']);
    $_SESSION['admin_user'] = $user;          // gate checked by includes/auth_check.php
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['active_tenant_id'] = $currentTid;

    // Platform-owner activity feed + Telegram (best-effort).
    if (@is_file(__DIR__ . '/../classes/TenantActivity.php')) {
        require_once __DIR__ . '/../classes/TenantActivity.php';
        TenantActivity::log($currentTid, 'login', (string) ($user['display_name'] ?? $email), 'เข้าสู่ระบบ (Google)');
    }

    header('Location: /', true, 302);
    exit;
} catch (\Throwable $e) {
    error_log('[sso-consume] tid=' . $currentTid . ' email=' . $email . ' err=' . $e->getMessage());
    sso_fail('เกิดข้อผิดพลาดในการเข้าสู่ระบบ');
}
