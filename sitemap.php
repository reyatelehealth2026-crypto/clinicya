<?php
/**
 * Dynamic Sitemap.xml Handler
 * 
 * Generates XML sitemap dynamically using SitemapGenerator class
 * Requirements: 9.1, 9.3, 9.5
 * 
 * @see classes/SitemapGenerator.php
 */

// Prevent direct access errors
error_reporting(0);

// Load configuration
$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    // Return minimal valid sitemap if config not found
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    exit;
}

require_once $configPath;
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/SitemapGenerator.php';

try {
    // Get database connection
    $db = Database::getInstance()->getConnection();
    
    // Determine base URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = defined('BASE_URL') ? BASE_URL : $protocol . '://' . $host;
    
    // Get line account ID if available (for multi-tenant support)
    $lineAccountId = null;
    if (defined('LINE_ACCOUNT_ID')) {
        $lineAccountId = LINE_ACCOUNT_ID;
    }
    
    // Generate and output sitemap
    $generator = new SitemapGenerator($db, $baseUrl, $lineAccountId);
    $generator->output();
    
} catch (Exception $e) {
    // Return minimal valid sitemap on error
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    
    // At minimum, include the homepage
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $protocol . '://' . $host;
    
    echo '<url>';
    echo '<loc>' . htmlspecialchars($baseUrl . '/') . '</loc>';
    echo '<lastmod>' . date('Y-m-d') . '</lastmod>';
    echo '<changefreq>weekly</changefreq>';
    echo '<priority>1.0</priority>';
    echo '</url>';
    
    echo '</urlset>';
}
