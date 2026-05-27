<?php
/**
 * Auth Check - ตรวจสอบสิทธิ์การเข้าถึง
 * Include ไฟล์นี้ในทุกหน้าที่ต้องการ authentication
 * V2.0 - รองรับ AdminAuth class
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load AdminAuth if available (only if Database class exists)
$adminAuth = null;
if (class_exists('Database') && file_exists(__DIR__ . '/../classes/AdminAuth.php')) {
    try {
        require_once __DIR__ . '/../classes/AdminAuth.php';
        $db = Database::getInstance()->getConnection();
        $adminAuth = new AdminAuth($db);
    } catch (Exception $e) {
        // Ignore errors - AdminAuth is optional
    }
}

// ตรวจสอบว่าล็อกอินหรือยัง
if (!isset($_SESSION['admin_user'])) {
    // Use absolute path to avoid relative path issues
    // Get the base URL dynamically
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    
    // Determine base path (remove /admin, /shop, /user, /auth, /inventory, /onboarding from current path)
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $basePath = preg_replace('#/(admin|shop|user|auth|inventory|onboarding).*$#', '', $scriptPath);
    $basePath = rtrim($basePath, '/');
    
    // Build absolute login URL
    $loginUrl = $basePath . '/auth/login.php';
    
    header('Location: ' . (defined('AUTH_REDIRECT') ? AUTH_REDIRECT : $loginUrl));
    exit;
}

$currentUser = $_SESSION['admin_user'];

/**
 * Tenant context resolution (ADR-001 Phase 1).
 *
 * After a successful admin login we resolve which tenant this admin belongs
 * to and pin it onto $_SESSION + TenantContext for the rest of the session.
 *
 * Mapping rule (transition period):
 *   $_SESSION['current_bot_id'] (legacy LINE account id) → tenants.id
 *   The migration deliberately keeps tenant ids aligned with line_account ids
 *   so production accounts 1 and 4 become tenants 1 and 4. After Wave 2 lands,
 *   the master DB exists; before that, this whole block silently no-ops and
 *   the app keeps using the legacy single-DB connection.
 *
 * Super admins (platform_users) follow a different code path: they do NOT
 * inherit a tenant from session, they MUST explicitly /admin/switch-tenant.php
 * to enter one. See ADR-006 §"Session model".
 */
if (class_exists('TenantContext') || file_exists(__DIR__ . '/../classes/TenantContext.php')) {
    if (!class_exists('TenantContext', false)) {
        require_once __DIR__ . '/../classes/TenantContext.php';
    }

    // $_SESSION['admin_user'] is set by AdminAuth::login(); $_SESSION['user_id']
    // is the equivalent under newer auth flows. Accept either.
    $sessionUserId = (int) ($_SESSION['user_id'] ?? ($_SESSION['admin_user']['id'] ?? 0));
    $botId         = (int) ($_SESSION['current_bot_id'] ?? 0);

    if ($sessionUserId > 0 && empty($_SESSION['active_tenant_id']) && $botId > 0) {
        try {
            $platformDb = Database::platform()->getConnection();
            $stmt = $platformDb->prepare(
                'SELECT id FROM tenants WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$botId]);
            $tenantId = (int) $stmt->fetchColumn();
            if ($tenantId > 0) {
                $_SESSION['active_tenant_id'] = $tenantId;
                TenantContext::setCurrentTenantId($tenantId);
            }
        } catch (\Throwable $e) {
            // Platform DB doesn't exist yet (pre-Wave-2). Fall through to legacy.
            error_log('[auth_check] Tenant resolution skipped: ' . $e->getMessage());
        }
    } elseif (!empty($_SESSION['active_tenant_id'])) {
        // Session already has a tenant — re-pin it onto the static for this request.
        TenantContext::setCurrentTenantId((int) $_SESSION['active_tenant_id']);
    }

    // Platform Owner (super admin) override — explicit switch only.
    // 2026-05-27 BUG FIX: do NOT auto-enter platform context just because the
    // session has platform_user_id. That nukes the tenant context set by the
    // subdomain resolver / session active_tenant_id, sending every admin query
    // to the (mostly empty) platform DB → "Table 'reya_platform.users' doesn't
    // exist" fatal errors across the whole admin UI.
    //
    // Rules now:
    //  - If admin_switched_to_tenant_id is set → that wins (super admin acting as tenant)
    //  - Else if no tenant is in scope yet → fall back to platform (super-admin only pages)
    //  - Else (tenant already pinned by subdomain/session) → keep tenant, no override
    if (!empty($_SESSION['platform_user_id'])) {
        if (!empty($_SESSION['admin_switched_to_tenant_id'])) {
            TenantContext::setCurrentTenantId(
                (int) $_SESSION['admin_switched_to_tenant_id']
            );
        } elseif (TenantContext::getCurrentTenantId() === null) {
            TenantContext::enterPlatformContext();
        }
    }
}

/**
 * Setup Wizard auto-redirect
 *
 * ถ้า admin ยังไม่ตั้งค่าครั้งแรก (onboarding_completed=0 AND onboarding_skipped=0)
 * → ส่งไป /onboarding/wizard.php?step=<step+1> เพื่อทำต่อจากที่ค้าง
 *
 * ละเว้นเมื่ออยู่ในหน้า wizard, logout, หรือ /api/*
 * และ fail-open หาก migration ยังไม่ได้รัน (column ยังไม่มี)
 */
if (!defined('SKIP_ONBOARDING_REDIRECT')) {
    $__path = $_SERVER['REQUEST_URI'] ?? '';
    $__skip = (
        strpos($__path, '/onboarding/') !== false ||
        strpos($__path, '/auth/logout') !== false ||
        strpos($__path, '/api/') === 0
    );
    if (!$__skip && class_exists('Database') && !empty($currentUser['id'])) {
        try {
            $__db = Database::getInstance()->getConnection();
            $__s = $__db->prepare(
                'SELECT onboarding_completed, onboarding_skipped, onboarding_step
                   FROM admin_users WHERE id = :id LIMIT 1'
            );
            $__s->execute([':id' => (int)$currentUser['id']]);
            $__ob = $__s->fetch(PDO::FETCH_ASSOC);
            if ($__ob
                && (int)$__ob['onboarding_completed'] === 0
                && (int)$__ob['onboarding_skipped']   === 0
            ) {
                $__nextStep = min(7, max(1, (int)$__ob['onboarding_step'] + 1));
                header('Location: /onboarding/wizard.php?step=' . $__nextStep);
                exit;
            }
        } catch (Exception $__e) {
            // Migration not yet applied → fail open
        }
    }
}

/**
 * ตรวจสอบว่าเป็น Super Admin หรือไม่
 */
function isSuperAdmin() {
    global $currentUser;
    return isset($currentUser['role']) && $currentUser['role'] === 'super_admin';
}

/**
 * ตรวจสอบว่าเป็น Admin หรือไม่ (รวม super_admin)
 */
function isAdmin() {
    global $currentUser;
    return isset($currentUser['role']) && in_array($currentUser['role'], ['admin', 'super_admin']);
}

/**
 * ตรวจสอบว่าเป็น Staff หรือไม่
 */
function isStaff() {
    global $currentUser;
    return isset($currentUser['role']) && $currentUser['role'] === 'staff';
}

/**
 * ตรวจสอบว่าเป็น User ทั่วไปหรือไม่
 */
function isUser() {
    global $currentUser;
    return isset($currentUser['role']) && $currentUser['role'] === 'user';
}

/**
 * บังคับให้เป็น Super Admin เท่านั้น
 */
function requireSuperAdmin() {
    if (!isSuperAdmin()) {
        header('Location: /admin/?error=no_permission');
        exit;
    }
}

/**
 * บังคับให้เป็น Admin เท่านั้น
 */
function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ' . (defined('USER_DASHBOARD') ? USER_DASHBOARD : 'user/dashboard.php'));
        exit;
    }
}

/**
 * บังคับให้เป็น User ที่ตั้งค่า LINE Account แล้ว
 */
function requireUserWithAccount() {
    global $currentUser;
    if (isUser() && empty($currentUser['line_account_id'])) {
        header('Location: ' . (defined('SETUP_ACCOUNT') ? SETUP_ACCOUNT : 'auth/setup-account.php'));
        exit;
    }
}

/**
 * ดึง LINE Account ID ที่ผู้ใช้มีสิทธิ์เข้าถึง
 * Super Admin = ทุกบัญชี (return null)
 * Admin/Staff = เฉพาะบัญชีที่ถูกกำหนด
 * User = เฉพาะบัญชีที่ตั้งค่าไว้
 */
function getAllowedLineAccountId() {
    global $currentUser;
    if (isSuperAdmin()) {
        return null; // Super Admin เข้าถึงได้ทุกบัญชี
    }
    return $currentUser['line_account_id'] ?? null;
}

/**
 * ตรวจสอบว่าผู้ใช้มีสิทธิ์เข้าถึง LINE Account นี้หรือไม่
 */
function canAccessLineAccount($lineAccountId) {
    global $currentUser, $adminAuth;
    
    if (isSuperAdmin()) {
        return true;
    }
    
    // Use AdminAuth if available
    if ($adminAuth) {
        return $adminAuth->canAccessBot($lineAccountId);
    }
    
    // Fallback to session-based check
    return $currentUser['line_account_id'] == $lineAccountId;
}

/**
 * ตรวจสอบสิทธิ์เฉพาะสำหรับ Bot
 */
function canAccessBotPermission($lineAccountId, $permission = 'can_view') {
    global $adminAuth;
    
    if (isSuperAdmin()) {
        return true;
    }
    
    if ($adminAuth) {
        return $adminAuth->canAccessBot($lineAccountId, $permission);
    }
    
    return false;
}

/**
 * ดึงรายการ LINE Bot ที่ผู้ใช้เข้าถึงได้
 */
function getAccessibleBots() {
    global $adminAuth;
    
    if ($adminAuth) {
        return $adminAuth->getAccessibleBots();
    }
    
    // Fallback - return all active bots for admin
    if (isSuperAdmin() || isAdmin()) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT * FROM line_accounts WHERE is_active = 1 ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [];
}
