<?php
/**
 * Admin SEO Helper
 * 
 * Provides title and favicon for admin pages
 */

if (!function_exists('getAdminPageTitle')) {
    /**
     * Get page title for admin pages
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID
     * @param string $pageTitle Current page title
     * @return string Complete page title
     */
    function getAdminPageTitle($db, $lineAccountId, $pageTitle = 'Admin') {
        try {
            require_once __DIR__ . '/../classes/LandingSEOService.php';
            $seoService = new LandingSEOService($db, $lineAccountId);
            $appName = $seoService->getAppName();
            return $pageTitle . ' - ' . $appName;
        } catch (Exception $e) {
            return $pageTitle . ' - Admin';
        }
    }
}

if (!function_exists('renderAdminFavicon')) {
    /**
     * Render favicon tags for admin pages
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID
     * @return string HTML favicon tags
     */
    function renderAdminFavicon($db, $lineAccountId) {
        try {
            require_once __DIR__ . '/../classes/LandingSEOService.php';
            $seoService = new LandingSEOService($db, $lineAccountId);
            return $seoService->renderFaviconTags();
        } catch (Exception $e) {
            return '';
        }
    }
}
