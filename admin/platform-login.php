<?php
/**
 * admin/platform-login.php — Login for Platform Owners (super admins).
 *
 * Distinct from /auth/login.php which authenticates tenant admin_users.
 * This page authenticates against zrismpsz_reya_platform.platform_users.
 *
 * On success, sets:
 *   $_SESSION['platform_user_id']
 *   $_SESSION['platform_user_email']
 *   $_SESSION['platform_user_name']
 *   $_SESSION['platform_user_role']  (super_admin | support | readonly)
 *
 * After login the user lands in Platform Mode (no tenant context). To inspect
 * a tenant they must use /admin/switch-tenant.php which writes a super_admin_audit
 * row for every switch.
 *
 * GET ?action=logout clears the platform session and returns to the login form.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/TenantContext.php';

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'logout') {
    unset(
        $_SESSION['platform_user_id'],
        $_SESSION['platform_user_email'],
        $_SESSION['platform_user_name'],
        $_SESSION['platform_user_role'],
        $_SESSION['admin_switched_to_tenant_id']
    );
    TenantContext::reset();
    header('Location: /admin/platform-login.php');
    exit;
}

// Already logged in — punt to switch-tenant
if (!empty($_SESSION['platform_user_id'])) {
    header('Location: /admin/switch-tenant.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'กรุณากรอกอีเมลและรหัสผ่าน';
    } else {
        try {
            $platformDb = Database::platform()->getConnection();
            $stmt = $platformDb->prepare(
                'SELECT id, email, name, role, password_hash, is_active
                   FROM platform_users
                  WHERE email = ?
                  LIMIT 1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, (string) $user['password_hash'])) {
                $error = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
            } elseif ((int) $user['is_active'] !== 1) {
                $error = 'บัญชีนี้ถูกระงับการใช้งาน';
            } else {
                // Record last_login
                try {
                    $platformDb->prepare(
                        'UPDATE platform_users SET last_login_at = NOW() WHERE id = ?'
                    )->execute([(int) $user['id']]);
                } catch (\Throwable $e) {
                    error_log('[platform-login] last_login_at update failed: ' . $e->getMessage());
                }

                // Write a login audit row (action=platform_login).
                try {
                    $audit = $platformDb->prepare(
                        'INSERT INTO super_admin_audit
                            (platform_user_id, tenant_id, action, ip_address, user_agent,
                             request_method, request_uri, metadata, created_at)
                         VALUES (?, NULL, "platform_login", ?, ?, ?, ?, NULL, NOW())'
                    );
                    $audit->execute([
                        (int) $user['id'],
                        $_SERVER['REMOTE_ADDR']      ?? null,
                        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                        $_SERVER['REQUEST_METHOD']   ?? null,
                        substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
                    ]);
                } catch (\Throwable $e) {
                    error_log('[platform-login] audit insert failed: ' . $e->getMessage());
                }

                // Establish session. Regenerate the session ID on privilege
                // elevation to defeat session fixation (an attacker who fixed a
                // pre-auth session ID must not retain a valid super-admin session).
                session_regenerate_id(true);

                $_SESSION['platform_user_id']    = (int) $user['id'];
                $_SESSION['platform_user_email'] = (string) $user['email'];
                $_SESSION['platform_user_name']  = (string) $user['name'];
                $_SESSION['platform_user_role']  = (string) $user['role'];

                // Platform Mode by default — they must explicitly switch into a tenant.
                unset($_SESSION['admin_switched_to_tenant_id']);
                TenantContext::enterPlatformContext();

                header('Location: /admin/switch-tenant.php');
                exit;
            }
        } catch (\Throwable $e) {
            error_log('[platform-login] DB error: ' . $e->getMessage());
            $error = 'ระบบติดต่อฐานข้อมูล platform ไม่ได้ — กรุณาลองใหม่หรือแจ้งทีม ops';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Owner Sign In — REYA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Sarabun', sans-serif; }
        .grad-bg {
            background: linear-gradient(135deg, #4338ca 0%, #1e1b4b 100%);
        }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">

<div class="w-full max-w-md">
    <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-100">

        <div class="grad-bg px-8 py-10 text-center text-white">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-white/10 backdrop-blur mb-4">
                <i class="fas fa-user-shield text-3xl"></i>
            </div>
            <h1 class="text-2xl font-bold">REYA Platform Owner</h1>
            <p class="text-indigo-100 text-sm mt-2">Internal use only — cross-tenant audit is enabled</p>
        </div>

        <div class="p-8">
            <?php if ($error): ?>
                <div class="mb-5 p-4 bg-red-50 border border-red-100 text-red-700 rounded-xl text-sm flex items-start gap-3">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <div class="relative">
                        <input type="email"
                               name="email"
                               required
                               autofocus
                               value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>"
                               class="w-full pl-10 pr-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm
                                      focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                               placeholder="you@reya-platform.com">
                        <i class="fas fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                    <div class="relative">
                        <input type="password"
                               name="password"
                               required
                               class="w-full pl-10 pr-3 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm
                                      focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                               placeholder="••••••••">
                        <i class="fas fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    </div>
                </div>

                <button type="submit"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-xl
                               transition flex items-center justify-center gap-2">
                    <span>Sign in as Platform Owner</span>
                    <i class="fas fa-arrow-right text-sm"></i>
                </button>
            </form>

            <div class="mt-6 pt-6 border-t border-slate-100 text-center">
                <p class="text-xs text-slate-400">
                    Tenant admin? <a href="/auth/login.php" class="text-indigo-600 hover:underline">Sign in here</a>.
                </p>
            </div>
        </div>
    </div>

    <p class="text-center text-xs text-slate-400 mt-6">
        &copy; <?= date('Y') ?> REYA Pharmacy SaaS Platform.
        All platform sessions are audited per ADR-006.
    </p>
</div>

</body>
</html>
