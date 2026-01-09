<?php
/**
 * Structured Data Component for Landing Page
 * 
 * Outputs Pharmacy JSON-LD structured data with conditional fields.
 * 
 * Requirements: 2.1, 2.2, 2.3, 2.4
 * 
 * Usage:
 *   require_once 'classes/LandingSEOService.php';
 *   require_once 'classes/FAQService.php';
 *   require_once 'classes/TestimonialService.php';
 *   $seoService = new LandingSEOService($db, $lineAccountId);
 *   $faqService = new FAQService($db, $lineAccountId); // Optional
 *   $testimonialService = new TestimonialService($db, $lineAccountId); // Optional
 *   include 'includes/landing/structured-data.php';
 * 
 * Expected variables:
 *   $seoService - Instance of LandingSEOService (required)
 *   $faqService - Instance of FAQService (optional)
 *   $testimonialService - Instance of TestimonialService (optional)
 */

// Ensure $seoService is available
if (!isset($seoService) || !($seoService instanceof LandingSEOService)) {
    return;
}

// Get Pharmacy structured data (Requirements: 2.1, 2.2, 2.3, 2.4)
$pharmacyData = $seoService->getStructuredData();

// Get FAQ structured data if service is available
$faqData = null;
if (isset($faqService) && $faqService instanceof FAQService) {
    $faqData = $faqService->getFAQStructuredData();
}

// Get Review structured data if service is available
$reviewData = null;
if (isset($testimonialService) && $testimonialService instanceof TestimonialService) {
    $reviewData = $testimonialService->getTestimonialStructuredData();
}
?>

<!-- Pharmacy Structured Data (Requirements: 2.1, 2.2, 2.3, 2.4) -->
<?php if (!empty($pharmacyData)): ?>
<script type="application/ld+json">
<?= json_encode($pharmacyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>

</script>
<?php endif; ?>

<?php if (!empty($faqData) && !empty($faqData['mainEntity'])): ?>
<!-- FAQPage Structured Data (Requirements: 4.3) -->
<script type="application/ld+json">
<?= json_encode($faqData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>

</script>
<?php endif; ?>

<?php if (!empty($reviewData)): ?>
<!-- Review Structured Data (Requirements: 5.5) -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "ItemList",
    "itemListElement": <?= json_encode($reviewData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>

}
</script>
<?php endif; ?>
