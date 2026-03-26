<?php
/**
 * OPcache Preload Script — preloads frequently-used files into shared memory
 *
 * เมื่อเปิดใช้ opcache.preload ไฟล์เหล่านี้จะถูก compile เป็น bytecode 1 ครั้ง
 * ตอน PHP-FPM start แล้ว share ให้ทุก worker ใช้ร่วมกัน — ไม่ต้อง parse ซ้ำ
 *
 * ติดตั้ง: เพิ่มใน php.ini หรือ opcache.ini:
 *   opcache.preload = /www/wwwroot/cny.re-ya.com/config/preload.php
 *   opcache.preload_user = www
 *
 * ⚠️ ใช้เฉพาะ PHP-FPM (ไม่ทำงานกับ CLI หรือ Apache mod_php)
 * ⚠️ ต้อง restart PHP-FPM หลังแก้ไฟล์ที่ preload (ไม่ auto-revalidate)
 *
 * @requires PHP 7.4+
 */

$basePath = dirname(__DIR__);

// List of files to preload — ordered by importance
$preloadFiles = [
    // Core config + database (ทุก request ใช้)
    $basePath . '/config/config.php',
    $basePath . '/config/database.php',

    // Database singleton
    $basePath . '/classes/Database.php',

    // Dashboard API — the biggest files that benefit most from preloading
    $basePath . '/api/odoo-dashboard-functions.php',
    // Note: main API files are too large (~5000 lines each) and may cause
    // preload memory issues. Use opcache.jit instead for those.

    // Fast endpoint helpers
    $basePath . '/classes/OdooCircuitBreaker.php',

    // Redis cache (if available)
    $basePath . '/classes/RedisCache.php',
];

$loaded = 0;
$failed = 0;

foreach ($preloadFiles as $file) {
    if (file_exists($file)) {
        try {
            opcache_compile_file($file);
            $loaded++;
        } catch (Throwable $e) {
            // Silently skip files that can't be preloaded
            $failed++;
        }
    }
}

// Log preload results (visible in PHP-FPM error log on startup)
if (PHP_SAPI !== 'cli') {
    error_log("[OPcache Preload] Loaded {$loaded} files, {$failed} failed");
}
