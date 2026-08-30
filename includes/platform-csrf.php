<?php
/**
 * platform-csrf.php — CSRF token สำหรับฟอร์มในหน้า Platform Owner.
 *
 * แยก session namespace (`reya_platform_csrf`) ออกจาก token ของหน้า products/inventory
 * เพื่อไม่ปนกัน. ใช้คู่กัน: ฝัง platformCsrfToken() เป็น hidden `_csrf` ในทุกฟอร์ม POST
 * แล้วเรียก platformCsrfCheck() ก่อนประมวลผล action.
 */
declare(strict_types=1);

if (!function_exists('platformCsrfToken')) {
    /** token ต่อ session (สร้างครั้งแรกแล้วคงไว้). */
    function platformCsrfToken(): string
    {
        if (empty($_SESSION['reya_platform_csrf'])) {
            $_SESSION['reya_platform_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['reya_platform_csrf'];
    }
}

if (!function_exists('platformCsrfCheck')) {
    /** ตรวจ token จาก $_POST['_csrf'] เทียบกับ session (constant-time). */
    function platformCsrfCheck(): bool
    {
        $token = $_POST['_csrf'] ?? '';
        return is_string($token)
            && $token !== ''
            && hash_equals($_SESSION['reya_platform_csrf'] ?? '', $token);
    }
}
