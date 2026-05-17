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
