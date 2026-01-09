<?php
/**
 * Dynamic Robots.txt Handler
 * 
 * Generates robots.txt dynamically with proper crawling directives
 * Requirements: 9.2, 9.4
 * 
 * - Includes Sitemap directive pointing to sitemap.xml
 * - Allows all crawlers by default
 * - Blocks sensitive directories
 */

// Set content type for plain text
header('Content-Type: text/plain; charset=utf-8');

// Determine base URL for sitemap reference
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Try to get BASE_URL from config if available
$baseUrl = $protocol . '://' . $host;
$configPath = __DIR__ . '/config/config.php';
if (file_exists($configPath)) {
    // Suppress errors during config load
    @include_once $configPath;
    if (defined('BASE_URL')) {
        $baseUrl = rtrim(BASE_URL, '/');
    }
}

// Output robots.txt content
// Requirements: 9.2 - Serve proper crawling directives
echo "# Robots.txt for " . $host . "\n";
echo "# Generated dynamically\n\n";

// Allow all user agents
echo "User-agent: *\n";

// Allow crawling of main content
echo "Allow: /\n\n";

// Disallow sensitive directories
echo "# Disallow sensitive directories\n";
echo "Disallow: /admin/\n";
echo "Disallow: /api/\n";
echo "Disallow: /config/\n";
echo "Disallow: /classes/\n";
echo "Disallow: /includes/\n";
echo "Disallow: /install/\n";
echo "Disallow: /database/\n";
echo "Disallow: /cron/\n";
echo "Disallow: /vendor/\n";
echo "Disallow: /tests/\n";
echo "Disallow: /.kiro/\n";
echo "Disallow: /.git/\n";
echo "Disallow: /.vscode/\n\n";

// Disallow auth pages
echo "# Disallow auth pages\n";
echo "Disallow: /auth/\n\n";

// Disallow user-specific pages
echo "# Disallow user-specific pages\n";
echo "Disallow: /user/\n";
echo "Disallow: /liff-checkout.php\n";
echo "Disallow: /liff-my-orders.php\n";
echo "Disallow: /liff-my-appointments.php\n";
echo "Disallow: /liff-settings.php\n\n";

// Crawl delay (optional, be nice to servers)
echo "# Crawl delay\n";
echo "Crawl-delay: 1\n\n";

// Requirements: 9.4 - Reference the sitemap location
echo "# Sitemap location\n";
echo "Sitemap: " . $baseUrl . "/sitemap.xml\n";
