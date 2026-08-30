<?php
/**
 * Global page-view Telegram alerts.
 *
 * Loaded via auto_prepend_file before every PHP page in this document root.
 * Keep this file silent and best-effort: it must never break page rendering.
 */

if (PHP_SAPI === 'cli') {
    return;
}

try {
    if (!defined('REYA_SITE_VISIT_PREPEND_LOADED')) {
        define('REYA_SITE_VISIT_PREPEND_LOADED', true);
    }

    require_once __DIR__ . '/../classes/SiteNotifier.php';
    SiteNotifier::registerPageVisitAlert();
} catch (Throwable $e) {
    error_log('[site_visit_prepend] ' . $e->getMessage());
}
