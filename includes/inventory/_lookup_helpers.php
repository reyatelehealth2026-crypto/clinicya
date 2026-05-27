<?php
/**
 * Shared helpers for the small lookup tabs on products.php
 * (drug-groups, generic-names, units, storage-locations, label-templates,
 *  categories, drug-interactions).
 *
 * Each lookup tab POSTs back to itself with X-Requested-With when the form is
 * submitted via fetch(), or as a normal form submit. We support both so the
 * page works even without JavaScript.
 *
 * @package Products
 */

if (!function_exists('reya_csrf_token')) {
    /** Lightweight per-session CSRF token. */
    function reya_csrf_token(): string
    {
        if (empty($_SESSION['reya_products_csrf'])) {
            $_SESSION['reya_products_csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['reya_products_csrf'];
    }
}

if (!function_exists('reya_csrf_check')) {
    function reya_csrf_check(): bool
    {
        $token = $_POST['_csrf'] ?? '';
        return is_string($token) && hash_equals($_SESSION['reya_products_csrf'] ?? '', $token);
    }
}

if (!function_exists('reya_h')) {
    /** Short alias for htmlspecialchars (UTF-8, quotes). */
    function reya_h($s): string
    {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('reya_status_pill')) {
    function reya_status_pill(bool $active): string
    {
        return $active
            ? '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">ใช้งาน</span>'
            : '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-200 text-slate-600">ปิด</span>';
    }
}
