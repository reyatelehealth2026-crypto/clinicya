<?php
/**
 * SEO Meta Component for Landing Page
 * 
 * Outputs canonical URL, keywords, robots, Open Graph tags, and Twitter Card meta tags.
 * 
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5
 * 
 * Usage:
 *   require_once 'classes/LandingSEOService.php';
 *   $seoService = new LandingSEOService($db, $lineAccountId);
 *   include 'includes/landing/seo-meta.php';
 * 
 * Expected variables:
 *   $seoService - Instance of LandingSEOService
 */

// Ensure $seoService is available
if (!isset($seoService) || !($seoService instanceof LandingSEOService)) {
    return;
}

// Get meta tags data
$metaTags = $seoService->getMetaTags();
$ogTags = $seoService->getOpenGraphTags();
$twitterTags = $seoService->getTwitterCardTags();
$faviconUrl = $seoService->getFaviconUrl();
?>

<!-- Favicon -->
<?php if (!empty($faviconUrl)): ?>
<link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
<link rel="shortcut icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
<?php endif; ?>

<!-- SEO Meta Tags (Requirements: 1.1, 1.2, 1.3) -->
<?php if (!empty($metaTags['canonical'])): ?>
<link rel="canonical" href="<?= htmlspecialchars($metaTags['canonical']) ?>">
<?php endif; ?>

<?php if (!empty($metaTags['description'])): ?>
<meta name="description" content="<?= htmlspecialchars($metaTags['description']) ?>">
<?php endif; ?>

<?php if (!empty($metaTags['keywords'])): ?>
<meta name="keywords" content="<?= htmlspecialchars($metaTags['keywords']) ?>">
<?php endif; ?>

<?php if (!empty($metaTags['robots'])): ?>
<meta name="robots" content="<?= htmlspecialchars($metaTags['robots']) ?>">
<?php endif; ?>

<?php if (!empty($metaTags['author'])): ?>
<meta name="author" content="<?= htmlspecialchars($metaTags['author']) ?>">
<?php endif; ?>

<!-- Open Graph Tags (Requirements: 1.4) -->
<?php foreach ($ogTags as $property => $content): ?>
<meta property="<?= htmlspecialchars($property) ?>" content="<?= htmlspecialchars($content) ?>">
<?php endforeach; ?>

<!-- Twitter Card Tags (Requirements: 1.5) -->
<?php foreach ($twitterTags as $name => $content): ?>
<meta name="<?= htmlspecialchars($name) ?>" content="<?= htmlspecialchars($content) ?>">
<?php endforeach; ?>

