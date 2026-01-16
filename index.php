<?php
/**
 * Public Landing Page
 * 
 * A public-facing landing page for the LINE Telepharmacy Platform.
 * Displays shop information, services, and CTA buttons to LIFF App.
 * 
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 4.1, 4.2, 4.3, 5.1, 5.2, 5.3
 */

// Check if installed
if (!file_exists('config/installed.lock') && file_exists('install/index.php')) {
    header('Location: install/');
    exit;
}

require_once 'config/config.php';
require_once 'config/database.php';

// Load landing page service classes
require_once 'classes/LandingSEOService.php';
require_once 'classes/FAQService.php';
require_once 'classes/TestimonialService.php';
require_once 'classes/TrustBadgeService.php';
require_once 'classes/LandingBannerService.php';
require_once 'classes/FeaturedProductService.php';

$db = Database::getInstance()->getConnection();

// Helper function to get promotion settings
function getLandingPromoSetting($db, $lineAccountId, $key, $default = null) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM promotion_settings WHERE line_account_id = ? AND setting_key = ?");
        $stmt->execute([$lineAccountId, $key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// Get default LINE account for LIFF URL
$lineAccount = null;
$liffId = null;
try {
    $stmt = $db->query("SELECT * FROM line_accounts WHERE is_default = 1 LIMIT 1");
    $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lineAccount) {
        $stmt = $db->query("SELECT * FROM line_accounts ORDER BY id ASC LIMIT 1");
        $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($lineAccount) {
        $liffId = $lineAccount['liff_id'] ?? null;
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

// Default shop settings
$shopName = $shopSettings['shop_name'] ?? 'LINE Telepharmacy';
$shopLogo = $shopSettings['shop_logo'] ?? '';
$shopDescription = $shopSettings['welcome_message'] ?? 'ร้านยาออนไลน์ครบวงจร พร้อมบริการปรึกษาเภสัชกร';
$contactPhone = $shopSettings['contact_phone'] ?? '';
$shopAddress = $shopSettings['shop_address'] ?? '';
$shopEmail = $shopSettings['shop_email'] ?? '';
$lineId = $shopSettings['line_id'] ?? '';

// Get theme colors from promotion_settings (Requirements: 1.5, 3.2)
require_once 'classes/LandingPageRenderer.php';
$primaryColor = getLandingPromoSetting($db, $lineAccountId, 'primary_color', LandingPageRenderer::DEFAULT_PRIMARY_COLOR);
$secondaryColor = getLandingPromoSetting($db, $lineAccountId, 'secondary_color', LandingPageRenderer::DEFAULT_SECONDARY_COLOR);

// Validate and normalize colors with fallback to defaults
$primaryColor = LandingPageRenderer::normalizeHexColor($primaryColor, LandingPageRenderer::DEFAULT_PRIMARY_COLOR);
$secondaryColor = LandingPageRenderer::normalizeHexColor($secondaryColor, LandingPageRenderer::DEFAULT_SECONDARY_COLOR);

// Get active promotions
$promotions = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM products 
        WHERE is_active = 1 
        AND (is_featured = 1 OR is_bestseller = 1 OR is_new = 1)
        AND (line_account_id = ? OR line_account_id IS NULL)
        ORDER BY is_featured DESC, is_bestseller DESC 
        LIMIT 6
    ");
    $stmt->execute([$lineAccountId]);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Build LIFF URL
$liffUrl = $liffId ? "https://liff.line.me/{$liffId}" : null;
$baseUrl = rtrim(BASE_URL, '/');

// Initialize landing page services (Requirements: 1.1-1.5, 2.1-2.4, 3.1-3.5, 4.1-4.5, 5.1-5.5)
$seoService = new LandingSEOService($db, $lineAccountId);
$faqService = new FAQService($db, $lineAccountId);
$testimonialService = new TestimonialService($db, $lineAccountId);
$trustBadgeService = new TrustBadgeService($db, $lineAccountId);
$bannerService = new LandingBannerService($db, $lineAccountId);
$featuredProductService = new FeaturedProductService($db, $lineAccountId);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="<?= htmlspecialchars($primaryColor) ?>">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="api/manifest.php">
    
    <!-- SEO Meta Tags Component (Requirements: 1.1, 1.2, 1.3, 1.4, 1.5) -->
    <?php include 'includes/landing/seo-meta.php'; ?>
    
    <title><?= htmlspecialchars($seoService->getPageTitle()) ?></title>
    
    <!-- Fonts - Preload for performance (Requirements: 6.3) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" as="style">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Dynamic Theme Colors (Requirements: 1.5, 3.2) -->
    <style>
        :root {
            --primary: <?= htmlspecialchars($primaryColor) ?>;
            --primary-dark: <?= htmlspecialchars($primaryColor) ?>dd;
            --primary-light: <?= htmlspecialchars($secondaryColor) ?>;
            --primary-rgb: <?= hexdec(substr($primaryColor, 1, 2)) ?>, <?= hexdec(substr($primaryColor, 3, 2)) ?>, <?= hexdec(substr($primaryColor, 5, 2)) ?>;
        }
    </style>

    <!-- Landing Page Styles (Requirements: 4.1, 4.2, 4.3 - Responsive Design) -->
    <style>
        /* ==================== Base Reset ==================== */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html {
            font-size: 16px;
            scroll-behavior: smooth;
            -webkit-text-size-adjust: 100%;
        }
        
        body {
            font-family: 'Sarabun', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #1F2937;
            background-color: #F8FAFC;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        
        img {
            max-width: 100%;
            height: auto;
            display: block;
        }
        
        a {
            text-decoration: none;
            color: inherit;
        }
        
        /* ==================== Typography ==================== */
        h1, h2, h3, h4 {
            font-weight: 700;
            line-height: 1.3;
            color: #1F2937;
        }
        
        h1 { font-size: 2rem; }
        h2 { font-size: 1.5rem; }
        h3 { font-size: 1.25rem; }
        
        /* ==================== Container ==================== */
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px;
        }
        
        /* ==================== Header ==================== */
        .landing-header {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 12px 0;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-img {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            object-fit: cover;
            background: var(--primary-light);
        }
        
        .logo-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .shop-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1F2937;
        }
        
        .header-cta {
            display: none;
        }
        
        /* ==================== Hero Section ==================== */
        .hero-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 48px 0 64px;
            position: relative;
            overflow: hidden;
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 60%;
            height: 200%;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
            transform: rotate(-15deg);
        }
        
        .hero-content {
            position: relative;
            z-index: 1;
            text-align: center;
        }
        
        .hero-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 16px;
            color: white;
        }
        
        .hero-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 32px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .hero-cta {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
        }
        
        /* ==================== Buttons ==================== */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 48px;
            min-width: 200px;
        }
        
        .btn-primary {
            background: white;
            color: var(--primary);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        
        .btn-outline {
            background: transparent;
            color: white;
            border: 2px solid rgba(255,255,255,0.5);
        }
        
        .btn-outline:hover {
            background: rgba(255,255,255,0.1);
            border-color: white;
        }
        
        /* LINE Button Style (Requirements: 2.4) */
        .btn-line {
            background: #06C755;
            color: white;
        }
        
        .btn-line:hover {
            background: #05B04C;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(6,199,85,0.3);
        }
        
        /* ==================== Services Section ==================== */
        .services-section {
            padding: 48px 0;
            background: white;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .section-title h2 {
            color: #1F2937;
            margin-bottom: 8px;
        }
        
        .section-title p {
            color: #6B7280;
            font-size: 0.95rem;
        }
        
        .services-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        .service-card {
            background: #F8FAFC;
            border-radius: 16px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid #E5E7EB;
        }
        
        .service-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.08);
            border-color: var(--primary);
        }
        
        .service-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
        }
        
        .service-card h3 {
            margin-bottom: 8px;
            font-size: 1.1rem;
        }
        
        .service-card p {
            color: #6B7280;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        /* ==================== Promotions Section ==================== */
        .promotions-section {
            padding: 48px 0;
            background: #F8FAFC;
        }
        
        .promotions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        .promo-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .promo-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        }
        
        .promo-image {
            aspect-ratio: 1;
            background: #F3F4F6;
            position: relative;
            overflow: hidden;
        }
        
        .promo-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .promo-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .badge-featured {
            background: #EF4444;
            color: white;
        }
        
        .badge-bestseller {
            background: #F59E0B;
            color: white;
        }
        
        .badge-new {
            background: var(--primary);
            color: white;
        }
        
        .promo-info {
            padding: 12px;
        }
        
        .promo-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .promo-price {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        /* ==================== Contact Section ==================== */
        .contact-section {
            padding: 48px 0;
            background: white;
        }
        
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        
        .contact-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        
        .contact-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .contact-info h4 {
            font-size: 1rem;
            margin-bottom: 4px;
        }
        
        .contact-info p {
            color: #6B7280;
            font-size: 0.9rem;
        }
        
        .contact-info a {
            color: var(--primary);
        }
        
        .contact-info a:hover {
            text-decoration: underline;
        }

        /* ==================== CTA Section ==================== */
        .cta-section {
            padding: 48px 0;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            text-align: center;
        }
        
        .cta-section h2 {
            color: white;
            margin-bottom: 16px;
        }
        
        .cta-section p {
            opacity: 0.9;
            margin-bottom: 24px;
        }
        
        /* ==================== Footer ==================== */
        .landing-footer {
            background: #1F2937;
            color: white;
            padding: 32px 0 24px;
        }
        
        .footer-content {
            text-align: center;
        }
        
        .footer-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .footer-logo img {
            width: 40px;
            height: 40px;
            border-radius: 10px;
        }
        
        .footer-logo span {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .footer-links {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        
        .footer-links a {
            color: #9CA3AF;
            font-size: 0.9rem;
            transition: color 0.2s;
        }
        
        .footer-links a:hover {
            color: white;
        }
        
        .footer-social {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .social-link {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            transition: all 0.2s;
        }
        
        .social-link:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }
        
        .footer-copyright {
            color: #6B7280;
            font-size: 0.85rem;
            padding-top: 24px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Admin Link (Requirements: 3.1, 3.2) */
        .admin-link {
            color: #6B7280;
            font-size: 0.8rem;
            margin-top: 16px;
            display: inline-block;
        }
        
        .admin-link:hover {
            color: #9CA3AF;
        }
        
        /* ==================== Mobile Fixed CTA (Requirements: 2.4) ==================== */
        .mobile-cta {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 12px 16px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
            z-index: 100;
            display: flex;
            gap: 12px;
        }
        
        .mobile-cta .btn {
            flex: 1;
            min-width: auto;
        }
        
        /* Add padding to body for fixed CTA */
        body {
            padding-bottom: 80px;
        }
        
        /* ==================== Responsive Design (Requirements: 4.1, 4.2, 4.3) ==================== */
        
        /* Tablet and up (768px+) */
        @media (min-width: 768px) {
            .container {
                padding: 0 24px;
            }
            
            h1 { font-size: 2.5rem; }
            h2 { font-size: 1.75rem; }
            
            .header-cta {
                display: block;
            }
            
            .header-cta .btn {
                min-width: auto;
                padding: 10px 20px;
                min-height: 44px;
            }
            
            .hero-section {
                padding: 80px 0 100px;
            }
            
            .hero-title {
                font-size: 2.5rem;
            }
            
            .hero-subtitle {
                font-size: 1.1rem;
            }
            
            .hero-cta {
                flex-direction: row;
                justify-content: center;
            }
            
            .services-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 24px;
            }
            
            .service-card {
                padding: 32px 24px;
            }
            
            .promotions-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 24px;
            }
            
            .contact-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 32px;
            }
            
            .mobile-cta {
                display: none;
            }
            
            body {
                padding-bottom: 0;
            }
            
            .services-section,
            .promotions-section,
            .contact-section {
                padding: 64px 0;
            }
            
            .cta-section {
                padding: 64px 0;
            }
        }
        
        /* Desktop (1024px+) */
        @media (min-width: 1024px) {
            .hero-section {
                padding: 100px 0 120px;
            }
            
            .hero-title {
                font-size: 3rem;
            }
            
            .promotions-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .contact-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .services-section,
            .promotions-section,
            .contact-section {
                padding: 80px 0;
            }
        }
        
        /* Large Desktop (1280px+) */
        @media (min-width: 1280px) {
            .promotions-grid {
                grid-template-columns: repeat(6, 1fr);
            }
        }
        
        /* LINE In-App Browser Optimizations */
        @supports (-webkit-touch-callout: none) {
            /* iOS specific */
            .btn {
                -webkit-tap-highlight-color: transparent;
            }
        }
        
        /* Safe Area Support for notched devices */
        @supports (padding: max(0px)) {
            .landing-header {
                padding-top: max(12px, env(safe-area-inset-top));
            }
            
            .mobile-cta {
                padding-bottom: max(12px, env(safe-area-inset-bottom));
            }
        }
        
        /* Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            * {
                transition: none !important;
                animation: none !important;
            }
        }
        
        /* Print Styles */
        @media print {
            .mobile-cta,
            .header-cta {
                display: none !important;
            }
        }
    </style>
    
    <!-- Structured Data Component (Requirements: 2.1, 2.2, 2.3, 2.4) -->
    <?php include 'includes/landing/structured-data.php'; ?>
</head>
<body>

    <!-- Header -->
    <header class="landing-header">
        <div class="container">
            <div class="header-content">
                <div class="logo-section">
                    <?php if ($shopLogo): ?>
                    <img src="<?= htmlspecialchars($shopLogo) ?>" alt="<?= htmlspecialchars($shopName) ?>" class="logo-img">
                    <?php else: ?>
                    <div class="logo-placeholder">
                        <i class="fas fa-clinic-medical"></i>
                    </div>
                    <?php endif; ?>
                    <span class="shop-name"><?= htmlspecialchars($shopName) ?></span>
                </div>
                
                <?php if ($liffUrl): ?>
                <div class="header-cta">
                    <a href="admin/" class="btn" style="background:#6B7280;color:white;margin-right:8px;min-width:auto;padding:10px 16px;">
                        <i class="fas fa-cog"></i>
                        Admin
                    </a>
                    <a href="<?= htmlspecialchars($liffUrl) ?>" class="btn btn-line">
                        <i class="fab fa-line"></i>
                        เปิดแอป
                    </a>
                </div>
                <?php else: ?>
                <div class="header-cta">
                    <a href="admin/" class="btn" style="background:#6B7280;color:white;min-width:auto;padding:10px 16px;">
                        <i class="fas fa-cog"></i>
                        Admin
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </header>
    
    <!-- Banner Slider Section (moved to top) -->
    <?php include 'includes/landing/banner-slider.php'; ?>
    
    <!-- Featured Products Section (แทน Hero Section) -->
    <?php include 'includes/landing/featured-products.php'; ?>
    
    <!-- Services Section (Requirements: 1.4, 5.1) -->
    <section class="services-section" id="services">
        <div class="container">
            <div class="section-title">
                <h2>บริการของเรา</h2>
                <p>ครบครันทุกบริการด้านสุขภาพ</p>
            </div>
            
            <div class="services-grid">
                <a href="<?= $liffUrl ? htmlspecialchars($liffUrl) . '#/shop' : '#' ?>" class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <h3>ร้านค้าออนไลน์</h3>
                    <p>เลือกซื้อยาและผลิตภัณฑ์สุขภาพได้ง่ายๆ พร้อมจัดส่งถึงบ้าน</p>
                </a>
                
                <a href="<?= $liffUrl ? htmlspecialchars($liffUrl) . '#/consult' : '#' ?>" class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <h3>ปรึกษาเภสัชกร</h3>
                    <p>พูดคุยกับเภสัชกรผู้เชี่ยวชาญ ได้คำแนะนำที่ถูกต้อง</p>
                </a>
                
                <a href="<?= $liffUrl ? htmlspecialchars($liffUrl) . '#/appointments' : '#' ?>" class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3>นัดหมายออนไลน์</h3>
                    <p>จองคิวล่วงหน้า ไม่ต้องรอคิว สะดวกรวดเร็ว</p>
                </a>
            </div>
        </div>
    </section>
    
    <!-- Contact Section with Operating Hours, Phone/LINE Links, and Map (Requirements: 7.1, 7.2, 7.3, 7.4, 7.5) -->
    <?php include 'includes/landing/contact-section.php'; ?>
    
    <!-- Health Articles Section -->
    <?php include 'includes/landing/health-articles.php'; ?>
    
    <!-- CTA Section -->
    <?php if ($liffUrl): ?>
    <section class="cta-section">
        <div class="container">
            <h2>พร้อมเริ่มต้นแล้วหรือยัง?</h2>
            <p>เปิดแอปผ่าน LINE เพื่อเริ่มใช้บริการได้เลย</p>
            <a href="<?= htmlspecialchars($liffUrl) ?>" class="btn btn-primary">
                <i class="fab fa-line"></i>
                เปิดแอปเลย
            </a>
        </div>
    </section>
    <?php endif; ?>
    
    <!-- FAQ Section (Requirements: 4.1, 4.3, 4.4, 4.5) -->
    <?php include 'includes/landing/faq-section.php'; ?>
    
    <!-- Trust Badges Section (moved after FAQ) -->
    <?php include 'includes/landing/trust-badges.php'; ?>
    
    <!-- Testimonials Section (moved after Trust Badges) -->
    <?php include 'includes/landing/testimonials.php'; ?>
    
    <!-- Footer (Requirements: 3.1, 3.2) -->
    <footer class="landing-footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-logo">
                    <?php if ($shopLogo): ?>
                    <img src="<?= htmlspecialchars($shopLogo) ?>" alt="<?= htmlspecialchars($shopName) ?>" loading="lazy">
                    <?php endif; ?>
                    <span><?= htmlspecialchars($shopName) ?></span>
                </div>
                
                <div class="footer-links">
                    <a href="#services">บริการ</a>
                    <a href="articles.php">บทความ</a>
                    <a href="#contact">ติดต่อ</a>
                    <a href="privacy-policy.php">นโยบายความเป็นส่วนตัว</a>
                    <a href="terms-of-service.php">ข้อกำหนดการใช้งาน</a>
                </div>
                
                <div class="footer-social">
                    <?php if ($lineId): ?>
                    <a href="https://line.me/R/ti/p/<?= htmlspecialchars(ltrim($lineId, '@')) ?>" class="social-link" target="_blank" title="LINE">
                        <i class="fab fa-line"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($shopSettings['facebook_url'])): ?>
                    <a href="<?= htmlspecialchars($shopSettings['facebook_url']) ?>" class="social-link" target="_blank" title="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($shopSettings['instagram_url'])): ?>
                    <a href="<?= htmlspecialchars($shopSettings['instagram_url']) ?>" class="social-link" target="_blank" title="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="footer-copyright">
                    <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($shopName) ?>. All rights reserved.</p>
                    <!-- Admin Login Link (Requirements: 3.1, 3.2) -->
                    <a href="admin/" class="admin-link">
                        <i class="fas fa-lock"></i> Admin
                    </a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Mobile Fixed CTA (Requirements: 2.4) -->
    <?php if ($liffUrl): ?>
    <div class="mobile-cta">
        <a href="admin/" class="btn" style="background:#6B7280;color:white;flex:0.5;">
            <i class="fas fa-cog"></i>
        </a>
        <a href="<?= htmlspecialchars($liffUrl) ?>" class="btn btn-line">
            <i class="fab fa-line"></i>
            เปิดแอป LINE
        </a>
    </div>
    <?php else: ?>
    <div class="mobile-cta">
        <a href="admin/" class="btn" style="background:#6B7280;color:white;">
            <i class="fas fa-cog"></i>
            Admin
        </a>
    </div>
    <?php endif; ?>
    
    <!-- Floating LINE Button (Requirements: 8.1, 8.5) -->
    <?php if ($lineId): ?>
    <a href="https://line.me/R/ti/p/<?= htmlspecialchars(ltrim($lineId, '@')) ?>" 
       class="floating-line-btn" 
       id="floatingLineBtn"
       target="_blank"
       title="แชทกับเราทาง LINE">
        <i class="fab fa-line"></i>
        <span class="floating-line-tooltip">แชทกับเรา</span>
    </a>
    
    <style>
    /* Floating LINE Button Styles (Requirements: 8.1, 8.4, 8.5) */
    .floating-line-btn {
        position: fixed;
        bottom: 100px;
        right: 20px;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: #06C755;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        box-shadow: 0 4px 16px rgba(6, 199, 85, 0.4);
        z-index: 99;
        opacity: 0;
        transform: translateY(20px) scale(0.8);
        transition: all 0.3s ease;
        pointer-events: none;
    }
    
    .floating-line-btn.visible {
        opacity: 1;
        transform: translateY(0) scale(1);
        pointer-events: auto;
    }
    
    .floating-line-btn:hover {
        background: #05B04C;
        transform: translateY(-2px) scale(1.05);
        box-shadow: 0 6px 24px rgba(6, 199, 85, 0.5);
    }
    
    .floating-line-tooltip {
        position: absolute;
        right: 100%;
        top: 50%;
        transform: translateY(-50%);
        background: #1F2937;
        color: white;
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 14px;
        white-space: nowrap;
        margin-right: 12px;
        opacity: 0;
        visibility: hidden;
        transition: all 0.2s ease;
    }
    
    .floating-line-tooltip::after {
        content: '';
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        border: 6px solid transparent;
        border-left-color: #1F2937;
    }
    
    /* Desktop: Show tooltip on hover (Requirements: 8.4) */
    @media (min-width: 768px) {
        .floating-line-btn:hover .floating-line-tooltip {
            opacity: 1;
            visibility: visible;
        }
        
        .floating-line-btn {
            bottom: 40px;
            right: 40px;
            width: 60px;
            height: 60px;
            font-size: 32px;
        }
    }
    
    /* Mobile: Adjust position to not overlap with mobile CTA (Requirements: 8.3) */
    @media (max-width: 767px) {
        .floating-line-btn {
            bottom: 100px;
            right: 16px;
            width: 52px;
            height: 52px;
            font-size: 26px;
        }
        
        .floating-line-tooltip {
            display: none;
        }
    }
    
    /* Reduced Motion */
    @media (prefers-reduced-motion: reduce) {
        .floating-line-btn {
            transition: none;
        }
    }
    </style>
    
    <script>
    /**
     * Floating LINE Button Scroll Behavior
     * Requirements: 8.1 - Show when user scrolls down
     */
    (function() {
        const floatingBtn = document.getElementById('floatingLineBtn');
        if (!floatingBtn) return;
        
        let lastScrollY = 0;
        let ticking = false;
        const showThreshold = 300; // Show after scrolling 300px
        
        function updateFloatingBtn() {
            const scrollY = window.scrollY || window.pageYOffset;
            
            if (scrollY > showThreshold) {
                floatingBtn.classList.add('visible');
            } else {
                floatingBtn.classList.remove('visible');
            }
            
            lastScrollY = scrollY;
            ticking = false;
        }
        
        window.addEventListener('scroll', function() {
            if (!ticking) {
                window.requestAnimationFrame(updateFloatingBtn);
                ticking = true;
            }
        }, { passive: true });
        
        // Initial check
        updateFloatingBtn();
    })();
    </script>
    <?php endif; ?>

</body>
</html>
