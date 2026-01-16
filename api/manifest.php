<?php
/**
 * Dynamic PWA Manifest Generator
 * 
 * Generates manifest.json dynamically based on landing settings
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LandingSEOService.php';

$db = Database::getInstance()->getConnection();

// Get default LINE account
$lineAccount = null;
try {
    $stmt = $db->query("SELECT * FROM line_accounts WHERE is_default = 1 LIMIT 1");
    $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lineAccount) {
        $stmt = $db->query("SELECT * FROM line_accounts ORDER BY id ASC LIMIT 1");
        $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$lineAccountId = $lineAccount['id'] ?? 1;

// Get shop settings
$shopSettings = [];
try {
    $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$lineAccountId]);
    $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shopSettings) {
        $stmt = $db->query("SELECT * FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1");
        $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// Initialize SEO service
$seoService = new LandingSEOService($db, $lineAccountId);

// Get settings
$appName = $seoService->getAppName();
$pageTitle = $seoService->getPageTitle();
$description = $seoService->getShopDescription();
$faviconUrl = $seoService->getFaviconUrl();
$shopLogo = $shopSettings['shop_logo'] ?? '';

// Use favicon or shop logo for icons
$iconUrl = !empty($faviconUrl) ? $faviconUrl : (!empty($shopLogo) ? $shopLogo : '/assets/images/3.png');

// Make absolute URL if relative
if (strpos($iconUrl, 'http') !== 0) {
    $baseUrl = rtrim(BASE_URL, '/');
    $iconUrl = $baseUrl . '/' . ltrim($iconUrl, '/');
}

// Get theme color from promotion settings
$primaryColor = '#06C755'; // Default LINE green
try {
    $stmt = $db->prepare("SELECT setting_value FROM promotion_settings WHERE line_account_id = ? AND setting_key = 'primary_color'");
    $stmt->execute([$lineAccountId]);
    $color = $stmt->fetchColumn();
    if ($color) {
        $primaryColor = $color;
    }
} catch (Exception $e) {}

// Build manifest
$manifest = [
    'name' => $pageTitle,
    'short_name' => $appName,
    'description' => $description,
    'start_url' => '/',
    'display' => 'standalone',
    'background_color' => $primaryColor,
    'theme_color' => $primaryColor,
    'orientation' => 'portrait-primary',
    'scope' => '/',
    'lang' => 'th',
    'icons' => [
        [
            'src' => $iconUrl,
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'maskable any'
        ],
        [
            'src' => $iconUrl,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable any'
        ]
    ],
    'shortcuts' => [
        [
            'name' => 'ข้อความ',
            'short_name' => 'Messages',
            'description' => 'ดูข้อความจากลูกค้า',
            'url' => '/messages',
            'icons' => [['src' => $iconUrl, 'sizes' => '96x96']]
        ],
        [
            'name' => 'คำสั่งซื้อ',
            'short_name' => 'Orders',
            'description' => 'ดูคำสั่งซื้อ',
            'url' => '/shop/orders',
            'icons' => [['src' => $iconUrl, 'sizes' => '96x96']]
        ]
    ],
    'categories' => ['business', 'productivity', 'medical']
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
