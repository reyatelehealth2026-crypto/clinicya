<?php
/**
 * Public Landing Page
 * 
 * A public-facing landing page for the LINE Telepharmacy Platform.
 * Displays shop information, services, and CTA buttons to LIFF App.
 * 
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 3.1, 3.2, 4.1, 4.2, 4.3, 5.1, 5.2, 5.3
 */

// --- Apex marketing landing override (safe: apex root only) ---
// Serve the static REYA marketing landing for the main domain re-ya.com at "/" only.
// Tenant subdomains (tenant-XXXX.re-ya.com), /api, /admin, and every other path/host
// fall through to the normal logic below — nothing else is affected.
$__host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$__path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
if (in_array($__host, ['re-ya.com', 'www.re-ya.com'], true)
    && ($__path === '/' || $__path === '/index.php')) {
    $__landing = __DIR__ . '/landing-reya-pharmacy.html';
    if (is_file($__landing)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($__landing);
        // Flush the page to the visitor FIRST, then do the best-effort new-IP
        // alert so it never adds to TTFB. (PHP-FPM only; degrades gracefully.)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            if (!defined('REYA_SKIP_SUBDOMAIN_RESOLUTION')) {
                define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
            }
            require_once __DIR__ . '/config/config.php';
            require_once __DIR__ . '/config/database.php';
            require_once __DIR__ . '/classes/SiteNotifier.php';
            SiteNotifier::trackLandingVisit();
        } catch (\Throwable $eApex) {
            error_log('[apex-landing] new-ip notify: ' . $eApex->getMessage());
        }
        exit;
    }
}

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

// Build LIFF URL — only for a REAL liff_id. Empty/PENDING tenants render the
// "Coming Soon" CTA (handled by the ?: null branches below) instead of a link
// into a broken shared Mini App.
require_once __DIR__ . '/includes/liff-helper.php';
$liffUrl = ($liffId && reya_is_real_liff_id($liffId)) ? "https://liff.line.me/{$liffId}" : null;
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
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" as="style">
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Dynamic Theme Colors (Requirements: 1.5, 3.2) -->
    <style>
        :root {
            --primary: <?= htmlspecialchars($primaryColor) ?>;
            --primary-dark: <?= htmlspecialchars($primaryColor) ?>dd;
            --primary-light: <?= htmlspecialchars($secondaryColor) ?>;
            --primary-rgb: <?= hexdec(substr($primaryColor, 1, 2)) ?>, <?= hexdec(substr($primaryColor, 3, 2)) ?>, <?= hexdec(substr($primaryColor, 5, 2)) ?>;
            --line-green: #06C755;
            --line-green-hover: #05B04C;
            --surface: #F8FAFC;
            --text: #0F172A;
            --text-muted: #64748B;
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
        
        /* ==================== Header / Navbar ==================== */
        .landing-header {
            position: sticky;
            top: 0;
            z-index: 200;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            transition: background 0.25s ease, box-shadow 0.25s ease;
        }
        
        .landing-header.is-scrolled {
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 8px 32px rgba(15, 23, 42, 0.08);
        }
        
        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            min-height: 56px;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex-shrink: 0;
        }
        
        .logo-section:hover .shop-name {
            color: var(--primary);
        }
        
        .logo-img {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            object-fit: cover;
            background: var(--primary-light);
            flex-shrink: 0;
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
            flex-shrink: 0;
        }
        
        .shop-name {
            font-family: 'Lexend', 'Sarabun', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 42vw;
        }
        
        @media (min-width: 768px) {
            .shop-name { max-width: 280px; }
        }
        
        .nav-desktop {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 4px;
            flex: 1;
        }
        
        .nav-desktop a {
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            color: #374151;
            cursor: pointer;
            transition: color 0.2s, background 0.2s;
        }
        
        .nav-desktop a:hover {
            color: var(--primary);
            background: rgba(var(--primary-rgb), 0.08);
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        
        .btn-admin {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 44px;
            min-width: 44px;
            padding: 10px 14px;
            border-radius: 12px;
            background: #6B7280;
            color: white;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-admin:hover {
            background: #4B5563;
            color: white;
        }
        
        .header-actions .btn-line {
            min-width: auto;
            padding: 10px 18px;
            font-size: 0.95rem;
        }
        
        .nav-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border: none;
            border-radius: 12px;
            background: #F3F4F6;
            color: #1F2937;
            cursor: pointer;
            font-size: 1.2rem;
        }
        
        .nav-toggle:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
        
        .nav-mobile {
            display: none;
            flex-direction: column;
            gap: 4px;
            padding: 8px 0 16px;
            border-top: 1px solid #E5E7EB;
        }
        
        .nav-mobile.is-open {
            display: flex;
        }
        
        .nav-mobile a {
            padding: 14px 12px;
            border-radius: 10px;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
        }
        
        .nav-mobile a:hover {
            background: #F9FAFB;
            color: var(--primary);
        }
        
        @media (min-width: 768px) {
            .nav-desktop { display: flex; }
            .nav-toggle { display: none; }
            .nav-mobile { display: none !important; }
        }
        
        /* ==================== PharmCare Hero (below banner) ==================== */
        .pharm-hero {
            position: relative;
            overflow: hidden;
            padding: 44px 0 52px;
            background: linear-gradient(155deg,
                rgba(var(--primary-rgb), 0.14) 0%,
                #ffffff 42%,
                rgba(var(--primary-rgb), 0.07) 100%);
        }
        
        .pharm-hero::before,
        .pharm-hero::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }
        
        .pharm-hero::before {
            width: min(320px, 90vw);
            height: min(320px, 90vw);
            top: -140px;
            right: -100px;
            background: radial-gradient(circle, rgba(var(--primary-rgb), 0.22) 0%, transparent 68%);
        }
        
        .pharm-hero::after {
            width: 220px;
            height: 220px;
            bottom: -90px;
            left: -70px;
            background: radial-gradient(circle, rgba(var(--primary-rgb), 0.14) 0%, transparent 70%);
        }
        
        .pharm-hero-inner {
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 720px;
            margin: 0 auto;
        }
        
        .pharm-hero__title {
            font-family: 'Lexend', 'Sarabun', sans-serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text);
            line-height: 1.28;
            margin-bottom: 12px;
        }
        
        .pharm-hero__subtitle {
            font-size: 1.05rem;
            color: var(--text-muted);
            margin-bottom: 18px;
            line-height: 1.55;
        }

        .pharm-hero__trust {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin: 0 auto 24px;
            max-width: 640px;
        }

        .pharm-hero__trust-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border: 1px solid rgba(var(--primary-rgb), 0.16);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.78);
            color: #334155;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
        }

        .pharm-hero__trust-item i {
            color: var(--primary);
        }
        
        .pharm-hero__cta {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: stretch;
        }
        
        .pharm-hero__cta .btn {
            min-width: min(100%, 260px);
        }
        
        /* ==================== About intro ==================== */
        .about-intro-section {
            padding: 48px 0;
            background: #fff;
        }
        
        .about-intro-grid {
            display: grid;
            gap: 32px;
            align-items: center;
        }
        
        .about-intro-text p {
            margin-bottom: 16px;
            color: #475569;
            line-height: 1.75;
            font-size: 1rem;
        }
        
        .about-intro-text p:last-of-type {
            margin-bottom: 20px;
        }
        
        .about-intro-visual {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 220px;
            border-radius: 24px;
            background: linear-gradient(145deg, rgba(var(--primary-rgb), 0.12) 0%, rgba(var(--primary-rgb), 0.04) 100%);
            border: 1px solid rgba(var(--primary-rgb), 0.18);
        }
        
        .about-intro-visual i {
            font-size: clamp(3.5rem, 12vw, 5rem);
            color: var(--primary);
            opacity: 0.88;
        }
        
        @media (min-width: 900px) {
            .about-intro-grid {
                grid-template-columns: 1.15fr 1fr;
            }
        }
        
        /* ==================== Features grid ==================== */
        .features-section {
            padding: 48px 0;
            background: var(--surface);
        }
        
        .features-grid-landing {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        .feature-card {
            background: #fff;
            border-radius: 16px;
            padding: 22px 20px;
            border: 1px solid #E5E7EB;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
        }
        
        .feature-card__icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(145deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 14px;
        }
        
        .feature-card h3 {
            font-family: 'Lexend', 'Sarabun', sans-serif;
            font-size: 1.05rem;
            margin-bottom: 8px;
            color: var(--text);
        }
        
        .feature-card p {
            font-size: 0.92rem;
            color: var(--text-muted);
            line-height: 1.55;
        }
        
        @media (min-width: 640px) {
            .features-grid-landing {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .features-grid-landing {
                grid-template-columns: repeat(3, 1fr);
                gap: 20px;
            }
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
            background: var(--line-green-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(6,199,85,0.3);
        }
        
        .btn-outline-primary {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-outline-primary:hover {
            background: rgba(var(--primary-rgb), 0.1);
            transform: translateY(-2px);
        }
        
        .btn-solid-primary {
            background: var(--primary);
            color: #fff;
            border: none;
        }
        
        .btn-solid-primary:hover {
            filter: brightness(1.06);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(var(--primary-rgb), 0.35);
        }
        
        .btn-admin-text {
            display: inline;
        }
        
        @media (max-width: 767px) {
            .btn-admin {
                padding: 10px 12px;
            }
            .btn-admin-text {
                display: none;
            }
        }
        
        .section-title h2 {
            font-family: 'Lexend', 'Sarabun', sans-serif;
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
            background: #fff;
            border-radius: 18px;
            padding: 28px 22px;
            text-align: center;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
            border: 1px solid #E5E7EB;
            cursor: pointer;
            display: block;
        }
        
        .service-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.1);
            border-color: rgba(var(--primary-rgb), 0.35);
        }
        
        .service-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(145deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 18px;
            box-shadow: 0 8px 24px rgba(var(--primary-rgb), 0.25);
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

        .service-card__action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 16px;
            color: var(--primary);
            font-size: 0.9rem;
            font-weight: 700;
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
            padding: 52px 0;
            background: linear-gradient(135deg, var(--primary) 0%, #1e293b 55%, var(--primary-dark) 100%);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .cta-section::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 80% 60% at 80% 20%, rgba(255,255,255,0.12) 0%, transparent 55%);
            pointer-events: none;
        }
        
        .cta-section .container {
            position: relative;
            z-index: 1;
        }
        
        .cta-section h2 {
            font-family: 'Lexend', 'Sarabun', sans-serif;
            color: white;
            margin-bottom: 16px;
        }
        
        .cta-section p {
            opacity: 0.92;
            margin-bottom: 24px;
            max-width: 520px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .cta-section .btn-primary {
            background: var(--line-green);
            color: #fff;
            border: none;
        }
        
        .cta-section .btn-primary:hover {
            background: var(--line-green-hover);
            color: #fff;
        }
        
        /* ==================== Footer ==================== */
        .landing-footer {
            background: #0f172a;
            color: #e2e8f0;
            padding: 40px 0 20px;
        }
        
        .footer-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 32px;
        }
        
        .footer-brand .footer-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .footer-logo img {
            width: 44px;
            height: 44px;
            border-radius: 12px;
        }
        
        .footer-logo span {
            font-family: 'Lexend', 'Sarabun', sans-serif;
            font-size: 1.15rem;
            font-weight: 600;
            color: #fff;
        }
        
        .footer-desc {
            font-size: 0.92rem;
            color: #94a3b8;
            line-height: 1.65;
            max-width: 320px;
        }
        
        .footer-col h4 {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            margin-bottom: 14px;
        }
        
        .footer-links-col {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .footer-links-col a {
            color: #cbd5e1;
            font-size: 0.92rem;
            transition: color 0.2s;
            cursor: pointer;
        }
        
        .footer-links-col a:hover {
            color: #fff;
        }
        
        .footer-contact-lines {
            display: flex;
            flex-direction: column;
            gap: 10px;
            font-size: 0.92rem;
            color: #94a3b8;
        }
        
        .footer-contact-lines a {
            color: #cbd5e1;
            word-break: break-word;
        }
        
        .footer-contact-lines a:hover {
            color: #fff;
        }
        
        .footer-contact-lines i {
            width: 1.1em;
            color: var(--primary-light);
        }
        
        .footer-social {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
        }
        
        @media (min-width: 768px) {
            .footer-grid {
                grid-template-columns: 1.25fr 1fr 1.1fr;
                gap: 40px;
                align-items: start;
            }
        }
        
        .social-link {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            transition: background 0.2s, transform 0.2s;
            cursor: pointer;
        }
        
        .social-link:hover {
            background: var(--primary);
            transform: translateY(-2px);
        }
        
        .footer-bottom {
            grid-column: 1 / -1;
            margin-top: 8px;
        }
        
        .footer-copyright {
            color: #64748b;
            font-size: 0.85rem;
            padding-top: 28px;
            border-top: 1px solid rgba(255,255,255,0.1);
            text-align: center;
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
            
            .pharm-hero {
                padding: 64px 0 72px;
            }
            
            .pharm-hero__title {
                font-size: 2.45rem;
            }
            
            .pharm-hero__subtitle {
                font-size: 1.12rem;
            }

            .pharm-hero__trust {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .pharm-hero__cta {
                flex-direction: row;
                justify-content: center;
                align-items: center;
            }
            
            .pharm-hero__cta .btn {
                min-width: 200px;
            }
            
            .about-intro-section,
            .features-section {
                padding: 64px 0;
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
            .pharm-hero {
                padding: 72px 0 88px;
            }
            
            .pharm-hero__title {
                font-size: 2.85rem;
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
            .header-actions .btn-line,
            .nav-toggle {
                display: none !important;
            }
        }
    </style>
    
    <!-- Structured Data Component (Requirements: 2.1, 2.2, 2.3, 2.4) -->
    <?php include 'includes/landing/structured-data.php'; ?>
</head>
<body>

    <!-- Header -->
    <header class="landing-header" id="top">
        <div class="container">
            <div class="header-inner">
                <a href="#top" class="logo-section">
                    <?php if ($shopLogo): ?>
                    <img src="<?= htmlspecialchars($shopLogo) ?>" alt="<?= htmlspecialchars($shopName) ?>" class="logo-img" width="48" height="48">
                    <?php else: ?>
                    <div class="logo-placeholder">
                        <i class="fas fa-clinic-medical" aria-hidden="true"></i>
                    </div>
                    <?php endif; ?>
                    <span class="shop-name"><?= htmlspecialchars($shopName) ?></span>
                </a>
                <nav class="nav-desktop" aria-label="เมนูหลัก">
                    <a href="#services">บริการ</a>
                    <a href="#about">แนะนำ</a>
                    <a href="#health-articles">บทความ</a>
                    <a href="#contact">ติดต่อ</a>
                </nav>
                <div class="header-actions">
                    <a href="admin/" class="btn-admin" aria-label="จัดการระบบ Admin">
                        <i class="fas fa-cog" aria-hidden="true"></i>
                        <span class="btn-admin-text">Admin</span>
                    </a>
                    <?php if ($liffUrl): ?>
                    <a href="<?= htmlspecialchars($liffUrl) ?>" class="btn btn-line">
                        <i class="fab fa-line" aria-hidden="true"></i>
                        เปิดแอป
                    </a>
                    <?php endif; ?>
                </div>
                <button type="button" class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="navMobile" aria-label="เปิดเมนู">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
            </div>
            <nav class="nav-mobile" id="navMobile" aria-label="เมนูมือถือ">
                <a href="#services">บริการ</a>
                <a href="#about">แนะนำ</a>
                <a href="#health-articles">บทความ</a>
                <a href="#contact">ติดต่อ</a>
                <?php if ($liffUrl): ?>
                <a href="<?= htmlspecialchars($liffUrl) ?>" class="btn btn-line" style="margin-top:8px;text-align:center;justify-content:center;">
                    <i class="fab fa-line" aria-hidden="true"></i>
                    เปิดแอป
                </a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    
    <?php include 'includes/landing/banner-slider.php'; ?>

    <?php
    // Per-section HTML overrides (2026-06-02) — admin can replace ANY hardcoded
    // landing section with their own HTML via /admin/landing-settings → Custom HTML
    // (เลือก section). Saved to landing_settings[setting_key = custom_html_{section}].
    if (!isset($__secOv)) {
        $__secOv = [];
        try {
            $__ovStmt = $db->prepare(
                'SELECT setting_key, setting_value FROM landing_settings
                  WHERE setting_key LIKE "custom_html_%"
                    AND (line_account_id = ? OR line_account_id IS NULL)
                  ORDER BY line_account_id IS NULL ASC'
            );
            $__ovStmt->execute([$lineAccountId]);
            foreach ($__ovStmt->fetchAll(PDO::FETCH_ASSOC) as $__r) {
                $__k = substr($__r['setting_key'], strlen('custom_html_'));
                if ($__k !== '' && !isset($__secOv[$__k]) && trim((string) $__r['setting_value']) !== '') {
                    $__secOv[$__k] = $__r['setting_value'];
                }
            }
        } catch (\Throwable $__e) { /* table may not exist yet — skip */ }
    }
    if (!function_exists('reya_sec')) {
        function reya_sec(string $k): string { global $__secOv; return $__secOv[$k] ?? ''; }
    }
    ?>

    <section class="pharm-hero" aria-labelledby="pharm-hero-title"><?php if (reya_sec('hero') !== ''): ?><?= reya_sec('hero') ?><?php else: ?>
        <div class="container pharm-hero-inner">
            <h1 class="pharm-hero__title" id="pharm-hero-title">ปรึกษาเภสัชกรและสั่งยากับ <?= htmlspecialchars($shopName) ?> ได้ในไม่กี่ขั้นตอน</h1>
            <p class="pharm-hero__subtitle">คุยกับทีมร้านยา ตรวจสอบสินค้า และติดตามออเดอร์ผ่าน LINE/LIFF ในประสบการณ์เดียวที่ออกแบบมาสำหรับคนไข้ไทย</p>
            <div class="pharm-hero__trust" aria-label="จุดเด่นบริการ">
                <span class="pharm-hero__trust-item"><i class="fas fa-user-md" aria-hidden="true"></i> เภสัชกรดูแล</span>
                <span class="pharm-hero__trust-item"><i class="fas fa-truck-fast" aria-hidden="true"></i> จัดส่งถึงบ้าน</span>
                <span class="pharm-hero__trust-item"><i class="fas fa-receipt" aria-hidden="true"></i> ติดตามออเดอร์ได้</span>
            </div>
            <div class="pharm-hero__cta">
                <?php if ($liffUrl): ?>
                <a href="<?= htmlspecialchars($liffUrl) ?>" class="btn btn-line">
                    <i class="fab fa-line" aria-hidden="true"></i>
                    เริ่มใช้งานผ่าน LINE
                </a>
                <?php endif; ?>
                <a href="#featured-products" class="btn btn-solid-primary">
                    <i class="fas fa-shopping-bag" aria-hidden="true"></i>
                    ดูสินค้าแนะนำ
                </a>
                <a href="#services" class="btn btn-outline-primary">
                    <i class="fas fa-briefcase-medical" aria-hidden="true"></i>
                    ดูบริการของเรา
                </a>
            </div>
        </div>
    <?php endif; ?></section>

    <section class="about-intro-section" id="about"><?php if (reya_sec('about') !== ''): ?><?= reya_sec('about') ?><?php else: ?>
        <div class="container">
            <div class="about-intro-grid">
                <div class="about-intro-text">
                    <div class="section-title" style="text-align:left;margin-bottom:1.25rem;">
                        <h2>แนะนำบริการของ <?= htmlspecialchars($shopName) ?></h2>
                    </div>
                    <p><?= htmlspecialchars($shopName) ?> คือแพลตฟอร์มเครือข่ายร้านขายยาออนไลน์ ซึ่งเป็นทางเลือกในการดูแลสุขภาพแบบเข้าถึงง่ายและรวดเร็ว เพราะคุณสามารถปรึกษาเภสัชกรออนไลน์ได้ทันทีผ่านแชต โทร หรือวิดีโอคอล ไม่ว่าจะเป็นอาการเจ็บป่วยเล็กน้อย คำถามเกี่ยวกับการใช้ยา หรือข้อสงสัยด้านสุขภาพอื่นๆ ทีมเภสัชกรร้านยาของเราพร้อมให้คำแนะนำที่เหมาะสมเฉพาะบุคคล</p>
                    <p>เราให้บริการครอบคลุมทั้งยาสามัญประจำบ้าน ยาที่จำหน่ายในร้านยาโดยเภสัชกร ยาตามใบสั่งแพทย์ และผลิตภัณฑ์เสริมอาหาร โดยทุกรายการผ่านการดูแลจากทีมเภสัชกร เพื่อประสิทธิภาพในการรักษาอาการเจ็บป่วยของแต่ละบุคคล สามารถสั่งยาออนไลน์ได้เลย พร้อมมีบริการส่ง Delivery ให้ถึงหน้าบ้านของคุณ</p>
                    <p>นอกจากนี้ ยังมีบริการทางการแพทย์ออนไลน์อีกมากมาย ไม่ว่าจะเป็นการปรึกษาแพทย์ ปรึกษาจิตแพทย์ รวมถึงค้นหาร้านขายยาใกล้ฉัน สนใจใช้บริการรูปแบบใด อ่านรายละเอียดเพิ่มเติมจากทางด้านล่างนี้ได้เลย</p>
                    <a href="#services" class="btn btn-outline-primary" style="min-width:180px;margin-top:4px;">
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        อ่านต่อ
                    </a>
                </div>
                <div class="about-intro-visual" aria-hidden="true">
                    <i class="fas fa-hand-holding-medical"></i>
                </div>
            </div>
        </div>
    <?php endif; ?></section>

    <section class="features-section" id="features"><?php if (reya_sec('features') !== ''): ?><?= reya_sec('features') ?><?php else: ?>
        <div class="container">
            <div class="section-title">
                <h2>คุณสมบัติเด่นของแพลตฟอร์ม</h2>
                <p><?= htmlspecialchars($shopName) ?> เป็นแพลตฟอร์มร้านยาออนไลน์ที่มีความโดดเด่นด้านการให้บริการ ช่วยยกระดับคุณภาพชีวิตในหลากหลายประการ</p>
            </div>
            <div class="features-grid-landing">
                <div class="feature-card">
                    <div class="feature-card__icon"><i class="fas fa-bolt" aria-hidden="true"></i></div>
                    <h3>สะดวกและรวดเร็ว</h3>
                    <p>สั่งซื้อยาออนไลน์ได้ทุกที่ ไม่ต้องเสียเวลาเดินทางไปร้านขายยา</p>
                </div>
                <div class="feature-card">
                    <div class="feature-card__icon"><i class="fas fa-truck" aria-hidden="true"></i></div>
                    <h3>บริการจัดส่งทั่วประเทศ</h3>
                    <p>ภายในพื้นที่กรุงเทพฯ และปริมณฑลรับยาได้ภายใน 1 ชั่วโมง</p>
                </div>
                <div class="feature-card">
                    <div class="feature-card__icon"><i class="fas fa-user-md" aria-hidden="true"></i></div>
                    <h3>เภสัชกรผู้ชำนาญการ</h3>
                    <p>ให้คำปรึกษาและแนะนำการใช้ยาที่ถูกต้องและปลอดภัย</p>
                </div>
                <div class="feature-card">
                    <div class="feature-card__icon"><i class="fas fa-pills" aria-hidden="true"></i></div>
                    <h3>สินค้าหลากหลาย</h3>
                    <p>มีให้เลือกมากมาย ทั้งผลิตภัณฑ์ยา อาหารเสริม ครบทุกความต้องการด้านสุขภาพ</p>
                </div>
                <div class="feature-card">
                    <div class="feature-card__icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></div>
                    <h3>ระบบรักษาความปลอดภัย</h3>
                    <p>มั่นใจได้ในความปลอดภัยของข้อมูลส่วนตัวและการสั่งซื้อยา</p>
                </div>
                <div class="feature-card">
                    <div class="feature-card__icon"><i class="fas fa-circle-check" aria-hidden="true"></i></div>
                    <h3>บริการครบวงจร</h3>
                    <p>ทั้งการปรึกษา การสั่งซื้อ และการจัดส่ง ในแพลตฟอร์มเดียว</p>
                </div>
            </div>
        </div>
    <?php endif; ?></section>

    <!-- Services Section (Requirements: 1.4, 5.1) -->
    <section class="services-section" id="services"><?php if (reya_sec('services') !== ''): ?><?= reya_sec('services') ?><?php else: ?>
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
                    <span class="service-card__action">เลือกสินค้า <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                </a>
                
                <a href="<?= $liffUrl ? htmlspecialchars($liffUrl) . '#/ai-chat' : '#' ?>" class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <h3>ปรึกษาเภสัชกร</h3>
                    <p>พูดคุยกับเภสัชกรผู้เชี่ยวชาญ ได้คำแนะนำที่ถูกต้อง</p>
                    <span class="service-card__action">เริ่มปรึกษา <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                </a>
                
                <a href="<?= $liffUrl ? htmlspecialchars($liffUrl) . '#/appointments' : '#' ?>" class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3>นัดหมายออนไลน์</h3>
                    <p>จองคิวล่วงหน้า ไม่ต้องรอคิว สะดวกรวดเร็ว</p>
                    <span class="service-card__action">จองคิว <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                </a>
            </div>
        </div>
    <?php endif; ?></section>

    <?php include 'includes/landing/featured-products.php'; ?>
    
    <!-- Contact Section with Operating Hours, Phone/LINE Links, and Map (Requirements: 7.1, 7.2, 7.3, 7.4, 7.5) -->
    <?php include 'includes/landing/contact-section.php'; ?>
    
    <!-- Health Articles Section -->
    <?php include 'includes/landing/health-articles.php'; ?>
    
    <?php include 'includes/landing/testimonials.php'; ?>
    
    <!-- FAQ Section (Requirements: 4.1, 4.3, 4.4, 4.5) -->
    <?php include 'includes/landing/faq-section.php'; ?>
    
    <?php include 'includes/landing/trust-badges.php'; ?>
    
    <!-- CTA Section -->
    <?php if ($liffUrl): ?>
    <section class="cta-section"><?php if (reya_sec('cta') !== ''): ?><?= reya_sec('cta') ?><?php else: ?>
        <div class="container">
            <h2>พร้อมเริ่มต้นแล้วหรือยัง?</h2>
            <p>ไม่ว่าคุณจะอยู่ในกรุงเทพฯ หรือต่างจังหวัด <?= htmlspecialchars($shopName) ?> พร้อมเป็นร้านขายยาออนไลน์ที่อยู่เคียงข้างคุณ โดยสามารถเข้าถึงยาและคำแนะนำด้านสุขภาพที่มีคุณภาพได้อย่างทันท่วงที</p>
            <a href="<?= htmlspecialchars($liffUrl) ?>" class="btn btn-primary">
                <i class="fab fa-line" aria-hidden="true"></i>
                เปิดแอปเลย
            </a>
        </div>
    <?php endif; ?></section>
    <?php endif; ?>
    
    <!-- Custom HTML block (2026-05-27) — admin-managed via /admin/landing-settings → Custom HTML tab -->
    <?php
    $__customHtml = '';
    try {
        // Use $lineAccountId resolved at top of file (default LINE OA of this tenant).
        // Fall back to NULL-keyed row for global default.
        $__stmtCH = $db->prepare(
            'SELECT setting_value FROM landing_settings
              WHERE setting_key = "custom_html"
                AND (line_account_id = ? OR line_account_id IS NULL)
              ORDER BY line_account_id IS NULL ASC
              LIMIT 1'
        );
        $__stmtCH->execute([$lineAccountId]);
        $__customHtml = (string) ($__stmtCH->fetchColumn() ?: '');
    } catch (\Throwable $__e) { /* table may not exist yet — skip */ }
    if ($__customHtml !== '') :
    ?>
    <section class="landing-custom-html" style="padding:24px 16px;">
        <div class="container" style="max-width:1100px; margin:0 auto;">
            <?= $__customHtml ?>
        </div>
    </section>
    <?php endif; ?>
    <!-- Footer (Requirements: 3.1, 3.2) -->
    <footer class="landing-footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand footer-col">
                    <div class="footer-logo">
                        <?php if ($shopLogo): ?>
                        <img src="<?= htmlspecialchars($shopLogo) ?>" alt="<?= htmlspecialchars($shopName) ?>" width="44" height="44" loading="lazy">
                        <?php endif; ?>
                        <span><?= htmlspecialchars($shopName) ?></span>
                    </div>
                    <p class="footer-desc"><?= htmlspecialchars($shopDescription) ?></p>
                </div>
                <div class="footer-col">
                    <h4>เมนู</h4>
                    <div class="footer-links-col">
                        <a href="#services">บริการของเรา</a>
                        <a href="#about">แนะนำแพลตฟอร์ม</a>
                        <a href="#features">คุณสมบัติเด่น</a>
                        <a href="#health-articles">บทความสุขภาพ</a>
                        <a href="#contact">ติดต่อเรา</a>
                        <a href="privacy-policy.php">นโยบายความเป็นส่วนตัว</a>
                        <a href="terms-of-service.php">ข้อกำหนดการใช้งาน</a>
                    </div>
                </div>
                <div class="footer-col footer-col--contact">
                    <h4>ติดต่อ</h4>
                    <div class="footer-contact-lines">
                        <?php if ($contactPhone): ?>
                        <span><i class="fas fa-phone" aria-hidden="true"></i> <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', $contactPhone)) ?>"><?= htmlspecialchars($contactPhone) ?></a></span>
                        <?php endif; ?>
                        <?php if ($shopEmail): ?>
                        <span><i class="fas fa-envelope" aria-hidden="true"></i> <a href="mailto:<?= htmlspecialchars($shopEmail) ?>"><?= htmlspecialchars($shopEmail) ?></a></span>
                        <?php endif; ?>
                        <?php if ($shopAddress): ?>
                        <span><i class="fas fa-location-dot" aria-hidden="true"></i> <?= htmlspecialchars($shopAddress) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="footer-social">
                        <?php if ($lineId): ?>
                        <a href="https://line.me/R/ti/p/<?= htmlspecialchars(ltrim($lineId, '@')) ?>" class="social-link" target="_blank" rel="noopener noreferrer" aria-label="LINE OA">
                            <i class="fab fa-line" aria-hidden="true"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($shopSettings['facebook_url'])): ?>
                        <a href="<?= htmlspecialchars($shopSettings['facebook_url']) ?>" class="social-link" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                            <i class="fab fa-facebook-f" aria-hidden="true"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($shopSettings['instagram_url'])): ?>
                        <a href="<?= htmlspecialchars($shopSettings['instagram_url']) ?>" class="social-link" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
                            <i class="fab fa-instagram" aria-hidden="true"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="footer-bottom footer-copyright">
                    <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($shopName) ?>. All rights reserved.</p>
                    <a href="admin/" class="admin-link">
                        <i class="fas fa-lock" aria-hidden="true"></i> Admin
                    </a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Mobile Fixed CTA (Requirements: 2.4) -->
    <?php if ($liffUrl): ?>
    <div class="mobile-cta">
        <a href="#featured-products" class="btn btn-outline-primary">
            <i class="fas fa-shopping-bag" aria-hidden="true"></i>
            สินค้า
        </a>
        <a href="<?= htmlspecialchars($liffUrl) ?>" class="btn btn-line">
            <i class="fab fa-line" aria-hidden="true"></i>
            เริ่มใช้งาน
        </a>
    </div>
    <?php else: ?>
    <div class="mobile-cta">
        <a href="#services" class="btn btn-solid-primary">
            <i class="fas fa-briefcase-medical" aria-hidden="true"></i>
            ดูบริการ
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

    <script>
    (function() {
        var header = document.querySelector('.landing-header');
        var toggle = document.getElementById('navToggle');
        var mobile = document.getElementById('navMobile');
        if (header) {
            function onScroll() {
                header.classList.toggle('is-scrolled', (window.scrollY || window.pageYOffset) > 12);
            }
            onScroll();
            window.addEventListener('scroll', onScroll, { passive: true });
        }
        if (toggle && mobile) {
            toggle.addEventListener('click', function() {
                var open = mobile.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                var icon = toggle.querySelector('i');
                if (icon) {
                    icon.className = open ? 'fas fa-times' : 'fas fa-bars';
                }
            });
            mobile.querySelectorAll('a').forEach(function(a) {
                a.addEventListener('click', function() {
                    mobile.classList.remove('is-open');
                    toggle.setAttribute('aria-expanded', 'false');
                    var icon = toggle.querySelector('i');
                    if (icon) { icon.className = 'fas fa-bars'; }
                });
            });
        }
    })();
    </script>

    <?php if (isset($_GET['preview'])): /* 2026-05-27 — preview-mode click-to-edit */ ?>
    <style>
        /* Hover outline + edit badge for sections, only in preview mode */
        [data-edit-tab], [data-edit-disabled] {
            position: relative;
            transition: outline 0.15s, background 0.15s;
            cursor: pointer;
        }
        [data-edit-tab]:hover {
            outline: 3px dashed #10b981;
            outline-offset: -3px;
            background-color: rgba(16, 185, 129, 0.04);
        }
        [data-edit-tab]:hover::after {
            content: '✏️ ' attr(data-edit-label);
            position: absolute;
            top: 8px; right: 8px;
            background: #10b981; color: white;
            padding: 4px 10px; border-radius: 6px;
            font-size: 12px; font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            pointer-events: none;
            z-index: 9999;
        }
        [data-edit-disabled]:hover {
            outline: 3px dashed #f59e0b;
            outline-offset: -3px;
            background-color: rgba(245, 158, 11, 0.04);
            cursor: not-allowed;
        }
        [data-edit-disabled]:hover::after {
            content: '🚧 ยังไม่มี Admin Tab — ' attr(data-edit-label);
            position: absolute;
            top: 8px; right: 8px;
            background: #f59e0b; color: white;
            padding: 4px 10px; border-radius: 6px;
            font-size: 12px; font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            pointer-events: none;
            z-index: 9999;
        }
        /* Top banner explaining preview mode */
        .reya-preview-banner {
            position: fixed; top: 0; left: 0; right: 0;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white; text-align: center;
            padding: 6px 16px; font-size: 12px; font-weight: 600;
            z-index: 999999; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        body { padding-top: 28px; }
    </style>
    <div class="reya-preview-banner">
        🖱 Preview Mode — คลิกที่ section เพื่อเปิด tab แก้ไข
    </div>
    <script>
    (function () {
        // Map sections to admin tabs. Sections without an admin tab use data-edit-disabled.
        const tabMap = [
            { sel: '.banner-slider, [class*="banner-slider"]',     tab: 'banners',     label: 'แบนเนอร์' },
            { sel: '#featured-products, .featured-products',        tab: 'featured',    label: 'สินค้าแนะนำ' },
            { sel: '#health-articles, .health-articles, .articles-section', tab: 'articles', label: 'บทความ' },
            { sel: '#testimonials, .testimonials-section',          tab: 'testimonials', label: 'รีวิว' },
            { sel: '#faq, .faq-section',                            tab: 'faq',         label: 'FAQ' },
            { sel: '#trust-badges, .trust-badges-section',          tab: 'trust',       label: 'Trust Badges' },
            { sel: '.landing-custom-html',                          tab: 'custom_html', label: 'Custom HTML' },
        ];
        // Previously hardcoded sections — now editable as HTML via the Custom HTML
        // tab (each maps to a section override key, edited in that tab).
        const noTabMap = [
            { sel: '.pharm-hero',           label: 'Hero', tab: 'custom_html', section: 'hero' },
            { sel: '.about-intro-section',  label: 'แนะนำบริการ', tab: 'custom_html', section: 'about' },
            { sel: '.features-section',     label: 'จุดเด่น', tab: 'custom_html', section: 'features' },
            { sel: '.services-section',     label: 'บริการ', tab: 'custom_html', section: 'services' },
            { sel: '.cta-section',          label: 'CTA', tab: 'custom_html', section: 'cta' },
            { sel: '.contact-section',      label: 'ติดต่อ' },
            { sel: '.landing-footer',       label: 'Footer (SEO tab)', tab: 'seo' }  // footer info comes from SEO settings
        ];

        tabMap.forEach(m => {
            document.querySelectorAll(m.sel).forEach(el => {
                el.setAttribute('data-edit-tab', m.tab);
                el.setAttribute('data-edit-label', m.label);
            });
        });
        noTabMap.forEach(m => {
            document.querySelectorAll(m.sel).forEach(el => {
                if (m.tab) {
                    el.setAttribute('data-edit-tab', m.tab);
                    el.setAttribute('data-edit-label', m.label);
                    if (m.section) el.setAttribute('data-edit-section', m.section);
                } else {
                    el.setAttribute('data-edit-disabled', '1');
                    el.setAttribute('data-edit-label', m.label);
                }
            });
        });

        // Click → tell parent admin to switch tab (and which section to edit)
        document.addEventListener('click', function (e) {
            const target = e.target.closest('[data-edit-tab]');
            if (!target) return;
            e.preventDefault();
            e.stopPropagation();
            const tab = target.getAttribute('data-edit-tab');
            const label = target.getAttribute('data-edit-label') || tab;
            const section = target.getAttribute('data-edit-section') || '';
            try {
                window.parent.postMessage({ type: 'reya-edit-tab', tab: tab, label: label, section: section }, '*');
            } catch (err) {}
        }, true);

        // Disable navigation links in preview mode (avoid clicking through to other pages)
        document.querySelectorAll('a[href]').forEach(a => {
            a.addEventListener('click', function (ev) {
                const href = a.getAttribute('href') || '';
                if (href.startsWith('#') || href === '' || href === '#') return;
                ev.preventDefault();
            });
        });
    })();
    </script>
    <?php endif; ?>

</body>
</html>
