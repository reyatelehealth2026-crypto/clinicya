<?php
/**
 * Redirect Handler - URL Redirect System
 * จัดการ redirect จาก URL เก่าไปยัง URL ใหม่หลังจาก file consolidation
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

/**
 * Redirect map: old URL => new URL
 * Format: 'old-file.php' => 'new-file.php?tab=xxx'
 */
$redirectMap = [
    // ==================== Analytics ====================
    'advanced-analytics.php' => 'analytics.php?tab=advanced',
    'crm-analytics.php' => 'analytics.php?tab=crm',
    'account-analytics.php' => 'analytics.php?tab=account',
    
    // ==================== Dashboard ====================
    'executive-dashboard.php' => 'dashboard.php?tab=executive',
    'crm-dashboard.php' => 'dashboard.php?tab=crm',
    
    // ==================== AI Chat ====================
    'ai-chatbot.php' => 'ai-chat.php?tab=chatbot',
    'ai-chat-settings.php' => 'ai-chat.php?tab=settings',
    'ai-studio.php' => 'ai-chat.php?tab=studio',
    
    // ==================== Broadcast ====================
    'broadcast-catalog.php' => 'broadcast.php?tab=catalog',
    'broadcast-products.php' => 'broadcast.php?tab=products',
    'broadcast-stats.php' => 'broadcast.php?tab=stats',
    
    // ==================== Rich Menu ====================
    'dynamic-rich-menu.php' => 'rich-menu.php?tab=dynamic',
    'rich-menu-switch.php' => 'rich-menu.php?tab=switch',
    
    // ==================== Video Call ====================
    'video-call-v2.php' => 'video-call.php',
    'video-call-simple.php' => 'video-call.php',
    'video-call-pro.php' => 'video-call.php',
    
    // ==================== LIFF (redirect to SPA) ====================
    'liff-app.php' => 'liff/index.php?page=app',
    'liff-appointment.php' => 'liff/index.php?page=appointment',
    'liff-checkout.php' => 'liff/index.php?page=checkout',
    'liff-consent.php' => 'liff/index.php?page=consent',
    'liff-main.php' => 'liff/index.php?page=main',
    'liff-member-card.php' => 'liff/index.php?page=member',
    'liff-my-appointments.php' => 'liff/index.php?page=appointments',
    'liff-my-orders.php' => 'liff/index.php?page=orders',
    'liff-order-detail.php' => 'liff/index.php?page=order-detail',
    'liff-pharmacy-consult.php' => 'liff/index.php?page=consult',
    'liff-points-history.php' => 'liff/index.php?page=points',
    'liff-points-rules.php' => 'liff/index.php?page=points-rules',
    'liff-product-detail.php' => 'liff/index.php?page=product',
    'liff-promotions.php' => 'liff/index.php?page=promotions',
    'liff-redeem-points.php' => 'liff/index.php?page=redeem',
    'liff-register.php' => 'liff/index.php?page=register',
    'liff-settings.php' => 'liff/index.php?page=settings',
    'liff-share.php' => 'liff/index.php?page=share',
    'liff-shop.php' => 'liff/index.php?page=shop',
    'liff-shop-v3.php' => 'liff/index.php?page=shop',
    'liff-symptom-assessment.php' => 'liff/index.php?page=symptom',
    'liff-video-call.php' => 'liff/index.php?page=video-call',
    'liff-video-call-pro.php' => 'liff/index.php?page=video-call',
    'liff-wishlist.php' => 'liff/index.php?page=wishlist',
    
    // ==================== Membership ====================
    'members.php' => 'membership.php?tab=members',
    'admin-rewards.php' => 'membership.php?tab=rewards',
    'admin-points-settings.php' => 'membership.php?tab=settings',
    
    // ==================== Settings ====================
    'line-accounts.php' => 'settings.php?tab=line',
    'telegram.php' => 'settings.php?tab=telegram',
    'email-settings.php' => 'settings.php?tab=email',
    'notification-settings.php' => 'settings.php?tab=notifications',
    'consent-management.php' => 'settings.php?tab=consent',
    'quick-access-settings.php' => 'settings.php?tab=quick-access',
    
    // ==================== Pharmacy ====================
    'pharmacist-dashboard.php' => 'pharmacy.php?tab=dashboard',
    'pharmacists.php' => 'pharmacy.php?tab=pharmacists',
    'drug-interactions.php' => 'pharmacy.php?tab=interactions',
    'dispense-drugs.php' => 'pharmacy.php?tab=dispense',
    
    // ==================== Inventory ====================
    'inventory/stock-movements.php' => 'inventory/index.php?tab=movements',
    'inventory/stock-adjustment.php' => 'inventory/index.php?tab=adjustment',
    'inventory/low-stock.php' => 'inventory/index.php?tab=low-stock',
    'inventory/reports.php' => 'inventory/index.php?tab=reports',
    
    // ==================== Procurement ====================
    'inventory/purchase-orders.php' => 'procurement.php?tab=po',
    'inventory/goods-receive.php' => 'procurement.php?tab=gr',
    'inventory/suppliers.php' => 'procurement.php?tab=suppliers',
    
    // ==================== Shop Settings ====================
    'shop/liff-shop-settings.php' => 'shop/settings.php?tab=liff',
    'shop/promotion-settings.php' => 'shop/settings.php?tab=promotions',
    'shop/products-grid.php' => 'shop/products.php?view=grid',
    
    // ==================== Scheduled ====================
    'scheduled-reports.php' => 'scheduled.php?tab=reports',
    
    // ==================== Drip Campaign ====================
    'drip-campaign-edit.php' => 'drip-campaigns.php',
    
    // ==================== User Panel Redirects ====================
    'user/analytics.php' => 'analytics.php',
    'user/messages.php' => 'messages.php',
    'user/broadcast.php' => 'broadcast.php',
    
    // ==================== Duplicate Files (removed) ====================
    'users_new.php' => 'users.php',
    'shop/orders_new.php' => 'shop/orders.php',
    'shop/order-detail-new.php' => 'shop/order-detail.php',
];

/**
 * Handle redirect for old URLs
 * Call this at the beginning of files that have been consolidated
 * 
 * @param bool $exit Whether to exit after redirect (default: true)
 * @return bool True if redirect was performed, false otherwise
 */
function handleRedirect($exit = true) {
    global $redirectMap;
    
    // Get current file path relative to document root
    $currentPath = $_SERVER['PHP_SELF'];
    $currentFile = ltrim($currentPath, '/');
    
    // Also check just the filename for root-level files
    $currentFilename = basename($currentPath);
    
    // Check if current file is in redirect map
    $newUrl = null;
    
    if (isset($redirectMap[$currentFile])) {
        $newUrl = '/' . $redirectMap[$currentFile];
    } elseif (isset($redirectMap[$currentFilename])) {
        $newUrl = '/' . $redirectMap[$currentFilename];
    }
    
    if ($newUrl) {
        // Preserve query parameters
        $newUrl = preserveQueryParams($newUrl);
        
        // Send 301 permanent redirect
        header("HTTP/1.1 301 Moved Permanently");
        header("Location: {$newUrl}");
        
        if ($exit) {
            exit;
        }
        
        return true;
    }
    
    return false;
}

/**
 * Preserve query parameters when redirecting
 * 
 * @param string $newUrl The new URL to redirect to
 * @return string URL with preserved query parameters
 */
function preserveQueryParams($newUrl) {
    // Get current query string excluding 'tab' if it's already in new URL
    $currentParams = $_GET;
    
    // Parse new URL to check for existing params
    $urlParts = parse_url($newUrl);
    $newParams = [];
    
    if (isset($urlParts['query'])) {
        parse_str($urlParts['query'], $newParams);
    }
    
    // Remove params that are already in new URL
    foreach ($newParams as $key => $value) {
        unset($currentParams[$key]);
    }
    
    // If there are remaining params, append them
    if (!empty($currentParams)) {
        $separator = isset($urlParts['query']) ? '&' : '?';
        $newUrl .= $separator . http_build_query($currentParams);
    }
    
    return $newUrl;
}

/**
 * Get redirect URL for a given old URL
 * Useful for generating links that point to new URLs
 * 
 * @param string $oldUrl The old URL
 * @return string|null The new URL or null if no redirect exists
 */
function getRedirectUrl($oldUrl) {
    global $redirectMap;
    
    $oldFile = ltrim($oldUrl, '/');
    $oldFile = preg_replace('/\?.*$/', '', $oldFile); // Remove query string
    
    if (isset($redirectMap[$oldFile])) {
        return '/' . $redirectMap[$oldFile];
    }
    
    return null;
}

/**
 * Check if a URL has a redirect
 * 
 * @param string $url The URL to check
 * @return bool True if URL has a redirect
 */
function hasRedirect($url) {
    return getRedirectUrl($url) !== null;
}

/**
 * Get all redirect mappings
 * Useful for generating sitemap or documentation
 * 
 * @return array The redirect map
 */
function getRedirectMap() {
    global $redirectMap;
    return $redirectMap;
}

/**
 * Add a custom redirect at runtime
 * 
 * @param string $oldUrl The old URL
 * @param string $newUrl The new URL
 */
function addRedirect($oldUrl, $newUrl) {
    global $redirectMap;
    $redirectMap[$oldUrl] = $newUrl;
}

/**
 * Create redirect stub file content
 * Use this to generate stub files for old URLs that redirect to new ones
 * 
 * @param string $newUrl The new URL to redirect to
 * @return string PHP code for the stub file
 */
function createRedirectStub($newUrl) {
    $escapedUrl = addslashes($newUrl);
    return <<<PHP
<?php
/**
 * Redirect stub - This file has been consolidated
 * Redirects to: {$escapedUrl}
 */
require_once __DIR__ . '/includes/redirects.php';
handleRedirect();
PHP;
}
