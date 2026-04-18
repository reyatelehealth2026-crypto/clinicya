<?php
/**
 * Manager host product photos (SKU / product_code → fixed URL pattern)
 * Example: https://manager.cnypharmacy.com/uploads/product_photo/0001.jpg
 */

if (!function_exists('managerProductPhotoBaseUrl')) {
    function managerProductPhotoBaseUrl(): string
    {
        if (defined('MANAGER_PRODUCT_PHOTO_BASE_URL') && MANAGER_PRODUCT_PHOTO_BASE_URL !== '') {
            return rtrim((string) MANAGER_PRODUCT_PHOTO_BASE_URL, '/');
        }
        $env = getenv('MANAGER_PRODUCT_PHOTO_BASE_URL');
        return $env ? rtrim((string) $env, '/') : 'https://manager.cnypharmacy.com';
    }
}

if (!function_exists('buildManagerProductPhotoUrl')) {
    /**
     * Build image URL from product_code (preferred) or sku.
     * Numeric codes are left-padded to 4 digits to match files like 0001.jpg
     */
    function buildManagerProductPhotoUrl(?string $productCode, ?string $sku): string
    {
        $key = trim((string) ($productCode ?? ''));
        if ($key === '') {
            $key = trim((string) ($sku ?? ''));
        }
        if ($key === '') {
            return '';
        }
        if (ctype_digit($key)) {
            $key = str_pad($key, 4, '0', STR_PAD_LEFT);
        }
        $base = managerProductPhotoBaseUrl();

        return $base . '/uploads/product_photo/' . rawurlencode($key) . '.jpg';
    }
}
