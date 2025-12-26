<?php
/**
 * LIFF Shop V2 - แสดงสินค้าแยกตามหมวดหมู่
 * - แต่ละหมวดมี section ของตัวเอง
 * - กรอบเด่นสำหรับ Best Seller
 * - ตั้งค่าการแสดงแต่ละหมวดได้
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Get params
$userId = $_GET['user'] ?? null;
$lineAccountId = $_GET['account'] ?? null;
$filterCategory = $_GET['category'] ?? null;
$filterSearch = trim($_GET['search'] ?? '');
$filterFeatured = isset($_GET['featured']) && $_GET['featured'] == '1';
$filterBestseller = isset($_GET['bestseller']) && $_GET['bestseller'] == '1';
$isFiltered = $filterCategory || $filterSearch || $filterFeatured || $filterBestseller;

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20; // สินค้าต่อหน้า
$offset = ($page - 1) * $perPage;

// Get line_account_id from user
if (!$lineAccountId && $userId && strpos($userId, 'U') === 0) {
    try {
        $stmt = $db->prepare("SELECT line_account_id FROM users WHERE line_user_id = ?");
        $stmt->execute([$userId]);
        $lineAccountId = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

require_once 'includes/liff-helper.php';
$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
if (!$lineAccountId) $lineAccountId = $liffData['line_account_id'];
// Get shop settings
$shopSettings = [];
try {
    $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$lineAccountId]);
    $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$shopName = $shopSettings['shop_name'] ?? 'ร้านยา';
$shopLogo = $shopSettings['shop_logo'] ?? '';
$welcomeMessage = $shopSettings['welcome_message'] ?? '';
$shippingFee = $shopSettings['shipping_fee'] ?? 50;
$freeShippingMin = $shopSettings['free_shipping_min'] ?? 500;

// Get LIFF Shop settings
function getLiffShopSetting($db, $lineAccountId, $key, $default = null) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM liff_shop_settings WHERE line_account_id = ? AND setting_key = ?");
        $stmt->execute([$lineAccountId, $key]);
        $value = $stmt->fetchColumn();
        if ($value === false) return $default;
        $decoded = json_decode($value, true);
        return $decoded !== null ? $decoded : $value;
    } catch (Exception $e) {
        return $default;
    }
}

$hiddenCategories = getLiffShopSetting($db, $lineAccountId, 'hidden_categories', []);
$categoryOrder = getLiffShopSetting($db, $lineAccountId, 'category_order', []);
$showBestsellers = getLiffShopSetting($db, $lineAccountId, 'show_bestsellers', '1') == '1';
$showFeatured = getLiffShopSetting($db, $lineAccountId, 'show_featured', '1') == '1';
$productsPerCategory = (int)getLiffShopSetting($db, $lineAccountId, 'products_per_category', '6');

// Get banners
$banners = getLiffShopSetting($db, $lineAccountId, 'banners', []);
if (!is_array($banners)) $banners = [];

// Get promotion settings (shared with liff-promotions.php)
function getPromoSetting($db, $lineAccountId, $key, $default = null) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM promotion_settings WHERE line_account_id = ? AND setting_key = ?");
        $stmt->execute([$lineAccountId, $key]);
        $value = $stmt->fetchColumn();
        if ($value === false) return $default;
        $decoded = json_decode($value, true);
        return $decoded !== null ? $decoded : $value;
    } catch (Exception $e) { return $default; }
}

$promoSettings = [
    'image_size' => getPromoSetting($db, $lineAccountId, 'image_size', 'medium'),
    'image_ratio' => getPromoSetting($db, $lineAccountId, 'image_ratio', '1:1'),
    'columns_mobile' => (int)getPromoSetting($db, $lineAccountId, 'columns_mobile', 2),
    'columns_desktop' => (int)getPromoSetting($db, $lineAccountId, 'columns_desktop', 4),
    'primary_color' => getPromoSetting($db, $lineAccountId, 'primary_color', '#11B0A6'),
    'sale_badge_color' => getPromoSetting($db, $lineAccountId, 'sale_badge_color', '#EF4444'),
    'bestseller_badge_color' => getPromoSetting($db, $lineAccountId, 'bestseller_badge_color', '#F59E0B'),
    'featured_badge_color' => getPromoSetting($db, $lineAccountId, 'featured_badge_color', '#8B5CF6'),
    'card_style' => getPromoSetting($db, $lineAccountId, 'card_style', 'rounded'),
    'card_shadow' => getPromoSetting($db, $lineAccountId, 'card_shadow', 'sm'),
    'show_sku' => getPromoSetting($db, $lineAccountId, 'show_sku', '0'),
    'show_stock' => getPromoSetting($db, $lineAccountId, 'show_stock', '0'),
    'show_description' => getPromoSetting($db, $lineAccountId, 'show_description', '1'),
    'show_usage' => getPromoSetting($db, $lineAccountId, 'show_usage', '1'),
    'show_manufacturer' => getPromoSetting($db, $lineAccountId, 'show_manufacturer', '0'),
    'banner_height_mobile' => (int)getPromoSetting($db, $lineAccountId, 'banner_height_mobile', 160),
    'banner_height_desktop' => (int)getPromoSetting($db, $lineAccountId, 'banner_height_desktop', 200),
    'banner_autoplay' => getPromoSetting($db, $lineAccountId, 'banner_autoplay', '1'),
    'banner_interval' => (int)getPromoSetting($db, $lineAccountId, 'banner_interval', 5000),
];

// Image size mapping
$imageSizes = ['small' => '120px', 'medium' => '160px', 'large' => '200px', 'xlarge' => '240px'];
$imageSize = $imageSizes[$promoSettings['image_size']] ?? '160px';

// Card border radius
$cardRadius = ['square' => '0', 'rounded' => '8px', 'rounded-lg' => '16px', 'rounded-xl' => '24px'];
$borderRadius = $cardRadius[$promoSettings['card_style']] ?? '16px';

// Shadow mapping
$shadows = ['none' => 'none', 'sm' => '0 2px 8px rgba(0,0,0,0.06)', 'md' => '0 4px 12px rgba(0,0,0,0.1)', 'lg' => '0 8px 24px rgba(0,0,0,0.15)'];
$cardShadow = $shadows[$promoSettings['card_shadow']] ?? $shadows['sm'];

// Image ratio
$ratios = ['1:1' => '100%', '4:3' => '75%', '3:4' => '133.33%', '16:9' => '56.25%'];
$paddingRatio = $ratios[$promoSettings['image_ratio']] ?? '100%';

// Check columns
$hasIsFeatured = $hasIsBestseller = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
    $hasIsFeatured = in_array('is_featured', $cols);
    $hasIsBestseller = in_array('is_bestseller', $cols);
} catch (Exception $e) {}

// Get categories
$categories = [];
$catTable = 'item_categories';
try {
    try { $db->query("SELECT 1 FROM item_categories LIMIT 1"); } 
    catch (Exception $e) { $catTable = 'business_categories'; }
    $stmt = $db->query("SELECT * FROM $catTable WHERE is_active = 1 ORDER BY id");
    $allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter out hidden categories
    $categories = array_filter($allCategories, function($cat) use ($hiddenCategories) {
        return !in_array($cat['id'], $hiddenCategories);
    });
    
    // Sort by custom order
    if (!empty($categoryOrder)) {
        usort($categories, function($a, $b) use ($categoryOrder) {
            $posA = array_search($a['id'], $categoryOrder);
            $posB = array_search($b['id'], $categoryOrder);
            if ($posA === false) $posA = 999;
            if ($posB === false) $posB = 999;
            return $posA - $posB;
        });
    }
    $categories = array_values($categories);
} catch (Exception $e) {}

// Category icons and friendly names - ต้องประกาศก่อนใช้
$categoryData = [
    'CNS' => ['icon' => '🧠', 'name' => 'ระบบประสาท'],
    'IMM' => ['icon' => '🛡️', 'name' => 'ภูมิคุ้มกัน'],
    'MSJ' => ['icon' => '💪', 'name' => 'แก้ปวด ลดอักเสบ'],
    'RIS' => ['icon' => '🫁', 'name' => 'ระบบทางเดินหายใจ'],
    'HCD' => ['icon' => '🩺', 'name' => 'เวชภัณฑ์'],
    'VIT' => ['icon' => '💊', 'name' => 'วิตามิน แร่ธาตุ'],
    'HER' => ['icon' => '🌿', 'name' => 'สมุนไพร'],
    'FMC' => ['icon' => '🛒', 'name' => 'สินค้าทั่วไป'],
    'HOR' => ['icon' => '💉', 'name' => 'ฮอร์โมน'],
    'CDS' => ['icon' => '❤️', 'name' => 'หัวใจ หลอดเลือด'],
    'INF' => ['icon' => '🦠', 'name' => 'ต้านเชื้อ'],
    'GIS' => ['icon' => '🍽️', 'name' => 'ระบบทางเดินอาหาร'],
    'SKI' => ['icon' => '🧴', 'name' => 'ผิวหนัง'],
    'NUT' => ['icon' => '🥗', 'name' => 'อาหารเสริม'],
    'COS' => ['icon' => '💄', 'name' => 'เครื่องสำอาง'],
    'SHP' => ['icon' => '🚚', 'name' => 'จัดส่ง'],
    'END' => ['icon' => '🔬', 'name' => 'ต่อมไร้ท่อ'],
    'ENT' => ['icon' => '👁️', 'name' => 'ตา หู คอ จมูก'],
    'OFC' => ['icon' => '📎', 'name' => 'อุปกรณ์สำนักงาน'],
    'ELE' => ['icon' => '🔌', 'name' => 'อุปกรณ์ไฟฟ้า'],
    'OTC' => ['icon' => '💊', 'name' => 'ยาสามัญ'],
    'SM'  => ['icon' => '🎁', 'name' => 'โปรโมชั่น']
];

function getCatIcon($cat, $categoryData) {
    $code = $cat['cny_code'] ?? '';
    if ($code && isset($categoryData[$code])) return $categoryData[$code]['icon'];
    $name = $cat['name'] ?? '';
    foreach ($categoryData as $c => $data) {
        if (strpos($name, $c) === 0) return $data['icon'];
    }
    return '📦';
}

function getCatFriendlyName($cat, $categoryData) {
    $code = $cat['cny_code'] ?? '';
    if ($code && isset($categoryData[$code])) return $categoryData[$code]['name'];
    $name = $cat['name'] ?? '';
    if (is_array($categoryData)) {
        foreach ($categoryData as $c => $data) {
            if (strpos($name, $c) === 0) return $data['name'];
        }
    }
    // ถ้าไม่เจอ ให้ตัดชื่อหลัง - ออก
    if (strpos($name, '-') !== false) {
        $parts = explode('-', $name, 2);
        return $parts[1] ?? $name;
    }
    return $name;
}

// Get filtered products (when filter is applied) with pagination
$filteredProducts = [];
$filterTitle = '';
$totalProducts = 0;
$totalPages = 1;
if ($isFiltered) {
    try {
        $featuredCol = $hasIsFeatured ? "COALESCE(is_featured, 0)" : "0";
        $bestsellerCol = $hasIsBestseller ? "COALESCE(is_bestseller, 0)" : "0";
        
        $where = ["is_active = 1"];
        $params = [];
        
        // ไม่ filter ตาม line_account_id - แสดงสินค้าทั้งหมด
        
        if ($filterCategory) {
            $where[] = "category_id = ?";
            $params[] = $filterCategory;
            // Get category name
            foreach ($categories as $c) {
                if ($c['id'] == $filterCategory) {
                    $filterTitle = getCatFriendlyName($c, $categoryData);
                    break;
                }
            }
            if (!$filterTitle) $filterTitle = 'หมวดหมู่';
        }
        
        if ($filterSearch) {
            $where[] = "(name LIKE ? OR sku LIKE ? OR generic_name LIKE ?)";
            $searchTerm = "%{$filterSearch}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $filterTitle = "ค้นหา: {$filterSearch}";
        }
        
        if ($filterFeatured && $hasIsFeatured) {
            $where[] = "is_featured = 1";
            $filterTitle = 'สินค้าแนะนำ';
        }
        
        if ($filterBestseller && $hasIsBestseller) {
            $where[] = "is_bestseller = 1";
            $filterTitle = 'สินค้าขายดี';
        }
        
        $whereClause = implode(' AND ', $where);
        
        // Count total
        $countSql = "SELECT COUNT(*) FROM business_items WHERE $whereClause";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $totalProducts = (int)$stmt->fetchColumn();
        $totalPages = max(1, ceil($totalProducts / $perPage));
        
        // Get products with pagination
        $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id,
                       $featuredCol as is_featured, $bestsellerCol as is_bestseller
                FROM business_items WHERE $whereClause ORDER BY id DESC LIMIT $perPage OFFSET $offset";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $filteredProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Get Best Sellers (สินค้าขายดี) - ถ้าเปิดใช้งานและไม่มี filter
$bestSellers = [];
if (!$isFiltered && $showBestsellers && $hasIsBestseller) {
    try {
        $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id,
                       COALESCE(is_featured, 0) as is_featured, COALESCE(is_bestseller, 0) as is_bestseller
                FROM business_items WHERE is_active = 1 AND is_bestseller = 1";
        // ไม่ filter ตาม line_account_id - แสดงสินค้าทั้งหมด
        $sql .= " ORDER BY id DESC LIMIT 10";
        $bestSellers = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Get Featured Products (สินค้าเด่น) - ถ้าเปิดใช้งานและไม่มี filter
$featuredProducts = [];
if (!$isFiltered && $showFeatured && $hasIsFeatured) {
    try {
        $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id,
                       COALESCE(is_featured, 0) as is_featured, COALESCE(is_bestseller, 0) as is_bestseller
                FROM business_items WHERE is_active = 1 AND is_featured = 1";
        // ไม่ filter ตาม line_account_id - แสดงสินค้าทั้งหมด
        $sql .= " ORDER BY id DESC LIMIT 10";
        $featuredProducts = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Get products by category (limit per category from settings) - ถ้าไม่มี filter
$productsByCategory = [];
if (!$isFiltered) {
    foreach ($categories as $cat) {
        try {
            $featuredCol = $hasIsFeatured ? "COALESCE(is_featured, 0)" : "0";
            $bestsellerCol = $hasIsBestseller ? "COALESCE(is_bestseller, 0)" : "0";
            $orderBy = $hasIsBestseller ? "is_bestseller DESC, " : "";
            $orderBy .= $hasIsFeatured ? "is_featured DESC, " : "";
            $orderBy .= "id DESC";
            
            // ไม่ filter ตาม line_account_id - แสดงสินค้าทั้งหมด
            $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id,
                           $featuredCol as is_featured, $bestsellerCol as is_bestseller
                    FROM business_items WHERE is_active = 1 AND category_id = ?";
            $sql .= " ORDER BY $orderBy LIMIT " . $productsPerCategory;
            
            $params = [$cat['id']];
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($products)) {
                $productsByCategory[$cat['id']] = [
                    'category' => $cat,
                    'products' => $products
                ];
            }
        } catch (Exception $e) {}
    }
}
$baseUrl = rtrim(BASE_URL, '/');

// Build pagination URL
function buildPageUrl($page, $userId, $lineAccountId, $filterCategory, $filterSearch, $filterFeatured, $filterBestseller) {
    $params = ['user' => $userId, 'account' => $lineAccountId, 'page' => $page];
    if ($filterCategory) $params['category'] = $filterCategory;
    if ($filterSearch) $params['search'] = $filterSearch;
    if ($filterFeatured) $params['featured'] = '1';
    if ($filterBestseller) $params['bestseller'] = '1';
    return 'liff-shop.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($shopName) ?> - ร้านขายยาออนไลน์</title>
    <meta name="description" content="<?= htmlspecialchars($shopName) ?> ร้านขายยาออนไลน์ บริการปรึกษาเภสัชกร ส่งยาถึงบ้าน ยาสามัญประจำบ้าน อาหารเสริม เวชภัณฑ์">
    <meta name="keywords" content="ร้านขายยา, ยาออนไลน์, เภสัชกร, ปรึกษายา, ส่งยาถึงบ้าน, <?= htmlspecialchars($shopName) ?>">
    <meta name="author" content="<?= htmlspecialchars($shopName) ?>">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="<?= htmlspecialchars($shopName) ?> - ร้านขายยาออนไลน์">
    <meta property="og:description" content="บริการปรึกษาเภสัชกร สั่งยาออนไลน์ ส่งถึงบ้าน">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="th_TH">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { 
            --primary: <?= $promoSettings['primary_color'] ?>; 
            --danger: <?= $promoSettings['sale_badge_color'] ?>; 
            --warning: <?= $promoSettings['bestseller_badge_color'] ?>;
            --featured: <?= $promoSettings['featured_badge_color'] ?>;
            --card-radius: <?= $borderRadius ?>;
            --image-size: <?= $imageSize ?>;
            --cols-mobile: <?= $promoSettings['columns_mobile'] ?>;
            --cols-desktop: <?= $promoSettings['columns_desktop'] ?>;
            --banner-height-mobile: <?= $promoSettings['banner_height_mobile'] ?>px;
            --banner-height-desktop: <?= $promoSettings['banner_height_desktop'] ?>px;
        }
        body { font-family: 'Sarabun', sans-serif; background: #F8FAFC; }
        .scroll-x { display: flex; gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; padding-bottom: 8px; }
        .scroll-x::-webkit-scrollbar { display: none; }
        .scroll-item { scroll-snap-align: start; flex-shrink: 0; width: var(--image-size); }
        
        /* Best Seller Card - กรอบเด่น */
        .bestseller-card {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            border: 2px solid var(--warning);
            border-radius: var(--card-radius);
            position: relative;
            overflow: hidden;
        }
        .bestseller-card::before {
            content: '🔥 BEST SELLER';
            position: absolute;
            top: 0; left: 0; right: 0;
            background: linear-gradient(90deg, var(--danger), #DC2626);
            color: white;
            font-size: 10px;
            font-weight: bold;
            text-align: center;
            padding: 4px;
            z-index: 10;
        }
        .bestseller-card .card-img { margin-top: 24px; }
        
        /* Featured Card */
        .featured-card {
            border: 2px solid var(--featured);
            border-radius: var(--card-radius);
            position: relative;
        }
        .featured-badge {
            position: absolute;
            top: 8px; right: 8px;
            background: var(--featured);
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: bold;
            z-index: 10;
        }
        
        /* Product Card */
        .product-card {
            background: white;
            border-radius: var(--card-radius);
            overflow: hidden;
            box-shadow: <?= $cardShadow ?>;
            transition: transform 0.15s;
        }
        .product-card:active { transform: scale(0.98); }
        
        /* Product Image with ratio */
        .product-image-wrapper { position: relative; padding-bottom: <?= $paddingRatio ?>; overflow: hidden; background: #f3f4f6; }
        .product-image-wrapper img, .product-image-wrapper .placeholder { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; }
        .product-image-wrapper .placeholder { display: flex; align-items: center; justify-content: center; }
        
        /* Product Grid */
        .product-grid { 
            display: grid; 
            grid-template-columns: repeat(var(--cols-mobile), 1fr); 
            gap: 12px; 
        }
        @media (min-width: 768px) {
            .product-grid { grid-template-columns: repeat(var(--cols-desktop), 1fr); }
        }
        
        /* Section Header */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: bold;
            color: #1F2937;
        }
        .section-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        
        /* Price colors */
        .price-primary { color: var(--primary); }
        .price-sale { color: var(--danger); }
        
        /* Bottom Nav */
        .bottom-nav {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 10px 0 max(10px, env(safe-area-inset-bottom));
            box-shadow: 0 -4px 15px rgba(0,0,0,0.05);
            z-index: 50;
        }
        .nav-item { display: flex; flex-direction: column; align-items: center; color: #94A3B8; font-size: 10px; cursor: pointer; }
        .nav-item.active { color: var(--primary); }
        .nav-item i { font-size: 20px; margin-bottom: 2px; }
        
        .cart-badge { position: absolute; top: -5px; right: -5px; background: var(--danger); color: white; font-size: 10px; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        
        /* Banner Carousel - Responsive */
        .banner-carousel { position: relative; overflow: hidden; border-radius: var(--card-radius); }
        .banner-track { display: flex; transition: transform 0.4s ease-out; }
        .banner-slide { flex-shrink: 0; width: 100%; }
        .banner-slide img { width: 100%; height: var(--banner-height-mobile); object-fit: cover; border-radius: var(--card-radius); }
        @media (min-width: 640px) {
            .banner-slide img { height: var(--banner-height-desktop); }
        }
        .banner-dots { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; }
        .banner-dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; transition: all 0.2s; }
        .banner-dot.active { background: white; width: 24px; border-radius: 4px; }
        .banner-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; background: rgba(255,255,255,0.9); border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; opacity: 0; transition: opacity 0.2s; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .banner-carousel:hover .banner-nav { opacity: 1; }
        .banner-nav.prev { left: 12px; }
        .banner-nav.next { right: 12px; }
        
        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 16px; }
        .page-btn { min-width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-size: 14px; font-weight: 500; }
        .page-btn.active { background: var(--primary); color: white; }
        .page-btn:not(.active) { background: white; border: 1px solid #E5E7EB; color: #6B7280; }
        .page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body class="pb-20">

    <!-- Header -->
    <div class="bg-white px-4 pt-4 pb-3 sticky top-0 z-40 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
                <?php if ($shopLogo): ?>
                <img src="<?= htmlspecialchars($shopLogo) ?>" class="w-10 h-10 rounded-full object-cover border">
                <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-teal-500 flex items-center justify-center text-white">
                    <i class="fas fa-clinic-medical"></i>
                </div>
                <?php endif; ?>
                <div>
                    <h1 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($shopName) ?></h1>
                    <p class="text-[11px] text-gray-400">ร้านยาออนไลน์</p>
                </div>
            </div>
            <button onclick="openCart()" class="relative p-2">
                <i class="fas fa-shopping-cart text-xl text-gray-600"></i>
                <span id="cartBadge" class="cart-badge hidden">0</span>
            </button>
        </div>
        
        <!-- Search -->
        <div class="relative">
            <input type="text" id="searchInput" placeholder="ค้นหายา, อาหารเสริม..." 
                   value="<?= htmlspecialchars($filterSearch) ?>"
                   class="w-full px-4 py-2.5 pl-10 rounded-xl bg-gray-100 focus:outline-none focus:ring-2 focus:ring-teal-500/50">
            <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400"></i>
        </div>
    </div>

    <!-- Quick Categories -->
    <div class="px-4 py-3">
        <div class="scroll-x">
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>" class="scroll-item px-4 py-2 <?= !$isFiltered ? 'bg-teal-500 text-white' : 'bg-white border' ?> rounded-full text-sm font-medium whitespace-nowrap">
                ทั้งหมด
            </a>
            <?php foreach (array_slice($categories, 0, 10) as $cat): 
                $icon = getCatIcon($cat, $categoryData);
                $name = getCatFriendlyName($cat, $categoryData);
                $isActive = $filterCategory == $cat['id'];
            ?>
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&category=<?= $cat['id'] ?>" 
               class="scroll-item px-4 py-2 <?= $isActive ? 'bg-teal-500 text-white' : 'bg-white border' ?> rounded-full text-sm font-medium whitespace-nowrap hover:bg-gray-50">
                <?= $icon ?> <?= htmlspecialchars($name) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Banner Carousel -->
    <?php if (!empty($banners) && !$isFiltered): ?>
    <div class="px-4 mb-4">
        <div class="banner-carousel" id="bannerCarousel">
            <div class="banner-track" id="bannerTrack">
                <?php foreach ($banners as $i => $banner): ?>
                <div class="banner-slide">
                    <?php if (!empty($banner['link'])): ?>
                    <a href="<?= htmlspecialchars($banner['link']) ?>">
                        <img src="<?= htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title'] ?? 'โปรโมชั่น') ?>" loading="lazy">
                    </a>
                    <?php else: ?>
                    <img src="<?= htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title'] ?? 'โปรโมชั่น') ?>" loading="lazy">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($banners) > 1): ?>
            <div class="banner-nav prev" onclick="prevSlide()"><i class="fas fa-chevron-left text-gray-600"></i></div>
            <div class="banner-nav next" onclick="nextSlide()"><i class="fas fa-chevron-right text-gray-600"></i></div>
            <div class="banner-dots">
                <?php for ($i = 0; $i < count($banners); $i++): ?>
                <div class="banner-dot <?= $i === 0 ? 'active' : '' ?>" onclick="goToSlide(<?= $i ?>)"></div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif (!$isFiltered): ?>
    <!-- Default Banner if no banners configured -->
    <div class="px-4 mb-4">
        <div class="bg-gradient-to-r from-teal-500 to-teal-600 rounded-2xl p-5 text-white">
            <div class="flex items-center justify-between">
                <div class="flex-1">
                    <?php if (!empty($welcomeMessage)): ?>
                    <p class="text-sm text-teal-100 leading-relaxed"><?= nl2br(htmlspecialchars($welcomeMessage)) ?></p>
                    <?php else: ?>
                    <h2 class="font-bold text-lg mb-1">🎉 ยินดีต้อนรับ!</h2>
                    <p class="text-sm text-teal-100">ส่งฟรีเมื่อซื้อครบ ฿<?= number_format($freeShippingMin) ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($shopLogo): ?>
                <img src="<?= htmlspecialchars($shopLogo) ?>" class="w-16 h-16 rounded-xl object-cover border-2 border-white/30 ml-4">
                <?php else: ?>
                <div class="text-4xl ml-4">💊</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isFiltered): ?>
    <!-- Filtered Results -->
    <div class="px-4 mb-6">
        <div class="section-header">
            <div class="section-title">
                <div class="section-icon bg-blue-100">🔍</div>
                <span><?= htmlspecialchars($filterTitle) ?></span>
            </div>
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>" class="text-sm text-gray-500">ล้างตัวกรอง</a>
        </div>
        
        <?php if (empty($filteredProducts)): ?>
        <div class="text-center py-12 text-gray-500">
            <i class="fas fa-box-open text-5xl mb-4 text-gray-300"></i>
            <p>ไม่พบสินค้า</p>
        </div>
        <?php else: ?>
        <div class="product-grid">
            <?php foreach ($filteredProducts as $p): 
                $price = $p['sale_price'] ?: $p['price'];
                $originalPrice = $p['sale_price'] ? $p['price'] : null;
                $isBestseller = (int)($p['is_bestseller'] ?? 0);
                $isFeatured = (int)($p['is_featured'] ?? 0);
            ?>
            <div class="<?= $isBestseller ? 'bestseller-card' : ($isFeatured ? 'featured-card product-card' : 'product-card') ?>" 
                 onclick="showProduct(<?= $p['id'] ?>)">
                <?php if ($isFeatured && !$isBestseller): ?>
                <span class="featured-badge text-[8px] px-1.5">⭐</span>
                <?php endif; ?>
                <div class="<?= $isBestseller ? 'card-img' : '' ?> product-image-wrapper">
                    <?php if ($p['image_url']): ?>
                    <img src="<?= htmlspecialchars($p['image_url']) ?>" loading="lazy">
                    <?php else: ?>
                    <div class="placeholder"><i class="fas fa-image text-2xl text-gray-300"></i></div>
                    <?php endif; ?>
                </div>
                <div class="p-2 <?= $isBestseller ? 'bg-white' : '' ?>">
                    <h3 class="text-xs font-medium text-gray-800 line-clamp-2 h-8"><?= htmlspecialchars($p['name']) ?></h3>
                    <?php if ($promoSettings['show_sku'] == '1' && !empty($p['sku'])): ?>
                    <p class="text-[10px] text-gray-400"><?= htmlspecialchars($p['sku']) ?></p>
                    <?php endif; ?>
                    <div class="mt-1">
                        <span class="<?= $isBestseller ? 'price-sale' : 'price-primary' ?> font-bold text-sm">฿<?= number_format($price) ?></span>
                        <?php if ($originalPrice): ?>
                        <span class="text-gray-400 text-[10px] line-through ml-1">฿<?= number_format($originalPrice) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($promoSettings['show_stock'] == '1'): ?>
                    <p class="text-[10px] text-gray-500">คงเหลือ <?= number_format($p['stock'] ?? 0) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?= buildPageUrl($page - 1, $userId, $lineAccountId, $filterCategory, $filterSearch, $filterFeatured, $filterBestseller) ?>" class="page-btn">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1): ?>
            <a href="<?= buildPageUrl(1, $userId, $lineAccountId, $filterCategory, $filterSearch, $filterFeatured, $filterBestseller) ?>" class="page-btn">1</a>
            <?php if ($startPage > 2): ?><span class="text-gray-400">...</span><?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="<?= buildPageUrl($i, $userId, $lineAccountId, $filterCategory, $filterSearch, $filterFeatured, $filterBestseller) ?>" 
               class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?><span class="text-gray-400">...</span><?php endif; ?>
            <a href="<?= buildPageUrl($totalPages, $userId, $lineAccountId, $filterCategory, $filterSearch, $filterFeatured, $filterBestseller) ?>" class="page-btn"><?= $totalPages ?></a>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="<?= buildPageUrl($page + 1, $userId, $lineAccountId, $filterCategory, $filterSearch, $filterFeatured, $filterBestseller) ?>" class="page-btn">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <p class="text-center text-sm text-gray-500 mt-2">หน้า <?= $page ?> จาก <?= $totalPages ?> (<?= $totalProducts ?> รายการ)</p>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- 🔥 Best Sellers Section -->
    <?php if (!empty($bestSellers)): ?>
    <div class="px-4 mb-6">
        <div class="section-header">
            <div class="section-title">
                <div class="section-icon bg-red-100">🔥</div>
                <span>สินค้าขายดี</span>
            </div>
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&bestseller=1" class="text-sm" style="color: var(--primary)">ดูทั้งหมด</a>
        </div>
        
        <div class="scroll-x">
            <?php foreach ($bestSellers as $p): 
                $price = $p['sale_price'] ?: $p['price'];
                $originalPrice = $p['sale_price'] ? $p['price'] : null;
            ?>
            <div class="scroll-item">
                <div class="bestseller-card" onclick="showProduct(<?= $p['id'] ?>)">
                    <div class="card-img product-image-wrapper bg-white">
                        <?php if ($p['image_url']): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" loading="lazy">
                        <?php else: ?>
                        <div class="placeholder"><i class="fas fa-image text-3xl text-gray-300"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3 bg-white">
                        <h3 class="text-sm font-medium text-gray-800 line-clamp-2 h-10"><?= htmlspecialchars($p['name']) ?></h3>
                        <?php if ($promoSettings['show_sku'] == '1' && !empty($p['sku'])): ?>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($p['sku']) ?></p>
                        <?php endif; ?>
                        <div class="mt-2 flex items-center gap-2">
                            <span class="price-sale font-bold">฿<?= number_format($price) ?></span>
                            <?php if ($originalPrice): ?>
                            <span class="text-gray-400 text-xs line-through">฿<?= number_format($originalPrice) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($promoSettings['show_stock'] == '1'): ?>
                        <p class="text-xs text-gray-500 mt-1">คงเหลือ <?= number_format($p['stock'] ?? 0) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ⭐ Featured Products Section -->
    <?php if (!empty($featuredProducts)): ?>
    <div class="px-4 mb-6">
        <div class="section-header">
            <div class="section-title">
                <div class="section-icon bg-yellow-100">⭐</div>
                <span>สินค้าแนะนำ</span>
            </div>
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&featured=1" class="text-sm" style="color: var(--primary)">ดูทั้งหมด</a>
        </div>
        
        <div class="scroll-x">
            <?php foreach ($featuredProducts as $p): 
                $price = $p['sale_price'] ?: $p['price'];
                $originalPrice = $p['sale_price'] ? $p['price'] : null;
            ?>
            <div class="scroll-item">
                <div class="featured-card product-card" onclick="showProduct(<?= $p['id'] ?>)">
                    <span class="featured-badge">⭐ แนะนำ</span>
                    <div class="product-image-wrapper">
                        <?php if ($p['image_url']): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" loading="lazy">
                        <?php else: ?>
                        <div class="placeholder"><i class="fas fa-image text-3xl text-gray-300"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3">
                        <h3 class="text-sm font-medium text-gray-800 line-clamp-2 h-10"><?= htmlspecialchars($p['name']) ?></h3>
                        <?php if ($promoSettings['show_sku'] == '1' && !empty($p['sku'])): ?>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($p['sku']) ?></p>
                        <?php endif; ?>
                        <div class="mt-2">
                            <span class="price-primary font-bold">฿<?= number_format($price) ?></span>
                            <?php if ($originalPrice): ?>
                            <span class="text-gray-400 text-xs line-through ml-1">฿<?= number_format($originalPrice) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($promoSettings['show_stock'] == '1'): ?>
                        <p class="text-xs text-gray-500 mt-1">คงเหลือ <?= number_format($p['stock'] ?? 0) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Products by Category - Horizontal Scroll -->
    <?php foreach ($productsByCategory as $catId => $data): 
        $cat = $data['category'];
        $products = $data['products'];
        $icon = getCatIcon($cat, $categoryData);
        $catName = getCatFriendlyName($cat, $categoryData);
    ?>
    <div class="px-4 mb-6">
        <div class="section-header">
            <div class="section-title">
                <div class="section-icon bg-gray-100"><?= $icon ?></div>
                <span><?= htmlspecialchars($catName) ?></span>
            </div>
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&category=<?= $catId ?>" class="text-sm" style="color: var(--primary)">ดูทั้งหมด</a>
        </div>
        
        <div class="scroll-x">
            <?php foreach ($products as $p): 
                $price = $p['sale_price'] ?: $p['price'];
                $originalPrice = $p['sale_price'] ? $p['price'] : null;
                $isBestseller = (int)($p['is_bestseller'] ?? 0);
                $isFeatured = (int)($p['is_featured'] ?? 0);
            ?>
            <div class="scroll-item">
                <div class="<?= $isBestseller ? 'bestseller-card' : ($isFeatured ? 'featured-card product-card' : 'product-card') ?>" 
                     onclick="showProduct(<?= $p['id'] ?>)">
                    <?php if ($isFeatured && !$isBestseller): ?>
                    <span class="featured-badge text-[8px] px-1.5">⭐</span>
                    <?php endif; ?>
                    <div class="<?= $isBestseller ? 'card-img' : '' ?> product-image-wrapper">
                        <?php if ($p['image_url']): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" loading="lazy">
                        <?php else: ?>
                        <div class="placeholder"><i class="fas fa-image text-2xl text-gray-300"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 <?= $isBestseller ? 'bg-white' : '' ?>">
                        <h3 class="text-[11px] font-medium text-gray-800 line-clamp-2 h-8 leading-tight"><?= htmlspecialchars($p['name']) ?></h3>
                        <?php if ($promoSettings['show_sku'] == '1' && !empty($p['sku'])): ?>
                        <p class="text-[9px] text-gray-400"><?= htmlspecialchars($p['sku']) ?></p>
                        <?php endif; ?>
                        <div class="mt-1">
                            <span class="<?= $isBestseller ? 'price-sale' : 'price-primary' ?> font-bold text-sm">฿<?= number_format($price) ?></span>
                            <?php if ($originalPrice): ?>
                            <span class="text-gray-400 text-[9px] line-through block">฿<?= number_format($originalPrice) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($promoSettings['show_stock'] == '1'): ?>
                        <p class="text-[9px] text-gray-500">คงเหลือ <?= number_format($p['stock'] ?? 0) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a class="nav-item" onclick="goHome()">
            <i class="fas fa-home"></i>
            <span>หน้าหลัก</span>
        </a>
        <a class="nav-item active">
            <i class="fas fa-shopping-basket"></i>
            <span>ร้านยา</span>
        </a>
        <a class="nav-item" onclick="openCart()">
            <i class="fas fa-shopping-cart"></i>
            <span>ตะกร้า</span>
        </a>
        <a class="nav-item" onclick="openChat()">
            <i class="fas fa-comments"></i>
            <span>แชท</span>
        </a>
    </nav>

    <!-- Product Detail Modal - Full Responsive -->
    <div id="productModal" class="fixed inset-0 bg-black/60 z-50 hidden overflow-y-auto">
        <div class="min-h-screen flex items-end sm:items-center justify-center">
            <div class="bg-white w-full sm:max-w-lg sm:rounded-2xl sm:m-4 rounded-t-3xl max-h-[95vh] sm:max-h-[90vh] overflow-hidden flex flex-col">
                <!-- Modal Header -->
                <div class="relative flex-shrink-0">
                    <button onclick="closeModal()" class="absolute top-4 right-4 z-20 w-10 h-10 bg-black/40 backdrop-blur rounded-full flex items-center justify-center text-white hover:bg-black/60 transition">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                    <!-- Product Image -->
                    <div id="modalImage" class="aspect-square bg-gray-100 w-full"></div>
                </div>
                
                <!-- Modal Content - Scrollable -->
                <div class="flex-1 overflow-y-auto">
                    <div class="p-4 sm:p-5">
                        <!-- Product Name -->
                        <h2 id="modalName" class="font-bold text-xl text-gray-800 mb-1 leading-tight"></h2>
                        <p id="modalSku" class="text-sm text-gray-400 mb-3"></p>
                        
                        <!-- Price Section -->
                        <div class="flex items-center gap-3 mb-4">
                            <span id="modalPrice" class="text-2xl sm:text-3xl font-bold text-teal-600"></span>
                            <span id="modalOriginalPrice" class="text-base text-gray-400 line-through hidden"></span>
                            <span id="modalDiscount" class="px-2 py-0.5 bg-red-100 text-red-600 text-xs font-bold rounded hidden"></span>
                        </div>
                        
                        <!-- Stock Info -->
                        <div id="modalStock" class="flex items-center gap-2 text-sm text-gray-500 mb-4">
                            <i class="fas fa-box"></i>
                            <span></span>
                        </div>
                        
                        <!-- Description / สรรพคุณ -->
                        <div id="modalDescSection" class="hidden mb-4">
                            <h3 class="font-bold text-gray-700 mb-2 flex items-center gap-2">
                                <i class="fas fa-pills text-teal-500"></i>สรรพคุณ / รายละเอียด
                            </h3>
                            <p id="modalDesc" class="text-sm text-gray-600 leading-relaxed"></p>
                        </div>
                        
                        <!-- Usage Instructions -->
                        <div id="modalUsageSection" class="hidden mb-4">
                            <h3 class="font-bold text-gray-700 mb-2 flex items-center gap-2">
                                <i class="fas fa-clipboard-list text-teal-500"></i>วิธีใช้
                            </h3>
                            <p id="modalUsage" class="text-sm text-gray-600 leading-relaxed"></p>
                        </div>
                        
                        <!-- Manufacturer -->
                        <div id="modalManufacturerSection" class="hidden mb-4">
                            <div class="flex items-center gap-2 text-sm text-gray-500">
                                <i class="fas fa-industry"></i>
                                <span id="modalManufacturer"></span>
                            </div>
                        </div>
                        
                        <!-- Generic Name -->
                        <div id="modalGenericSection" class="hidden mb-4">
                            <div class="flex items-center gap-2 text-sm text-gray-500">
                                <i class="fas fa-pills"></i>
                                <span id="modalGeneric"></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Footer - Fixed -->
                <div class="flex-shrink-0 border-t bg-white p-4 sm:p-5">
                    <div class="flex items-center gap-3">
                        <!-- Quantity Selector -->
                        <div class="flex items-center border-2 border-gray-200 rounded-xl overflow-hidden">
                            <button onclick="changeQty(-1)" class="w-11 h-11 flex items-center justify-center text-gray-600 hover:bg-gray-100 active:bg-gray-200 transition">
                                <i class="fas fa-minus"></i>
                            </button>
                            <span id="modalQty" class="w-12 text-center font-bold text-lg">1</span>
                            <button onclick="changeQty(1)" class="w-11 h-11 flex items-center justify-center text-gray-600 hover:bg-gray-100 active:bg-gray-200 transition">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <!-- Add to Cart Button -->
                        <button onclick="addToCart()" id="addToCartBtn" class="flex-1 py-3 bg-gradient-to-r from-teal-500 to-teal-600 text-white rounded-xl font-bold text-base shadow-lg hover:shadow-xl active:scale-[0.98] transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-cart-plus mr-2"></i>เพิ่มลงตะกร้า
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cart Drawer -->
    <div id="cartDrawer" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeCartDrawer()"></div>
        <div class="cart-drawer-content absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl max-h-[70vh] flex flex-col transform translate-y-full transition-transform duration-300">
            <!-- Header -->
            <div class="flex items-center justify-between p-4 border-b">
                <h2 class="font-bold text-lg text-gray-800"><i class="fas fa-shopping-cart text-teal-500 mr-2"></i>ตะกร้าสินค้า</h2>
                <div class="flex items-center gap-3">
                    <button onclick="clearCart()" class="text-red-500 text-sm hover:text-red-700">
                        <i class="fas fa-trash mr-1"></i>ล้างตะกร้า
                    </button>
                    <button onclick="closeCartDrawer()" class="w-8 h-8 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            
            <!-- Cart Items -->
            <div id="cartItems" class="flex-1 overflow-y-auto p-4">
                <!-- Items will be loaded here -->
            </div>
            
            <!-- Footer -->
            <div class="border-t p-4 bg-gray-50">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-gray-600">รวมทั้งหมด</span>
                    <span id="cartTotal" class="text-xl font-bold text-teal-600">฿0</span>
                </div>
                <button onclick="goToCheckout()" id="checkoutBtn" class="w-full py-3 bg-teal-500 text-white rounded-xl font-medium hover:bg-teal-600 transition disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-credit-card mr-2"></i>ดำเนินการชำระเงิน
                </button>
            </div>
        </div>
    </div>

    <style>
        .cart-drawer-content.show { transform: translateY(0) !important; }
    </style>

    <script>
    const LIFF_ID = '<?= $liffId ?>';
    const BASE_URL = '<?= $baseUrl ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    let userId = '<?= $userId ?>';
    let cart = [];
    let currentProduct = null;
    let currentQty = 1;

    document.addEventListener('DOMContentLoaded', async function() {
        if (!userId && LIFF_ID) {
            try {
                await liff.init({ liffId: LIFF_ID });
                if (liff.isLoggedIn()) {
                    const profile = await liff.getProfile();
                    userId = profile.userId;
                }
            } catch (e) { console.error(e); }
        }
        await loadCart();
    });

    async function loadCart() {
        if (!userId) return;
        try {
            const res = await fetch(`${BASE_URL}/api/checkout.php?action=cart&line_user_id=${userId}`);
            const data = await res.json();
            if (data.success && data.items) {
                cart = data.items;
                updateCartBadge();
            }
        } catch (e) { console.error(e); }
    }

    function updateCartBadge() {
        const badge = document.getElementById('cartBadge');
        const count = cart.reduce((sum, item) => sum + item.quantity, 0);
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    function showProduct(id) {
        // Open modal instead of redirect
        showProductModal(id);
    }

    async function showProductModal(id) {
        try {
            // Show loading
            document.getElementById('productModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            document.getElementById('modalImage').innerHTML = '<div class="w-full h-full flex items-center justify-center"><i class="fas fa-spinner fa-spin text-4xl text-gray-300"></i></div>';
            
            const res = await fetch(`${BASE_URL}/api/shop-products.php?product_id=${id}&account=${ACCOUNT_ID}`);
            const data = await res.json();
            
            if (data.success && data.product) {
                currentProduct = data.product;
                currentQty = 1;
                
                const p = data.product;
                document.getElementById('modalName').textContent = p.name;
                document.getElementById('modalSku').textContent = p.sku ? `รหัส: ${p.sku}` : '';
                
                const price = parseFloat(p.sale_price || p.price);
                const originalPrice = p.sale_price ? parseFloat(p.price) : null;
                
                document.getElementById('modalPrice').textContent = `฿${price.toLocaleString()}`;
                
                if (originalPrice && originalPrice > price) {
                    document.getElementById('modalOriginalPrice').textContent = `฿${originalPrice.toLocaleString()}`;
                    document.getElementById('modalOriginalPrice').classList.remove('hidden');
                    
                    const discount = Math.round((1 - price / originalPrice) * 100);
                    document.getElementById('modalDiscount').textContent = `-${discount}%`;
                    document.getElementById('modalDiscount').classList.remove('hidden');
                } else {
                    document.getElementById('modalOriginalPrice').classList.add('hidden');
                    document.getElementById('modalDiscount').classList.add('hidden');
                }
                
                // Stock
                const stock = p.stock || 999;
                const stockEl = document.getElementById('modalStock');
                if (stock > 10) {
                    stockEl.innerHTML = `<i class="fas fa-check-circle text-green-500"></i><span class="text-green-600">มีสินค้า</span>`;
                } else if (stock > 0) {
                    stockEl.innerHTML = `<i class="fas fa-exclamation-circle text-orange-500"></i><span class="text-orange-600">เหลือ ${stock} ชิ้น</span>`;
                } else {
                    stockEl.innerHTML = `<i class="fas fa-times-circle text-red-500"></i><span class="text-red-600">สินค้าหมด</span>`;
                    document.getElementById('addToCartBtn').disabled = true;
                }
                
                document.getElementById('modalQty').textContent = '1';
                
                // Description - check settings
                const showDescription = <?= $promoSettings['show_description'] == '1' ? 'true' : 'false' ?>;
                if (showDescription && p.description) {
                    document.getElementById('modalDesc').textContent = p.description;
                    document.getElementById('modalDescSection').classList.remove('hidden');
                } else {
                    document.getElementById('modalDescSection').classList.add('hidden');
                }
                
                // Usage Instructions - check settings
                const showUsage = <?= $promoSettings['show_usage'] == '1' ? 'true' : 'false' ?>;
                if (showUsage && p.usage_instructions) {
                    document.getElementById('modalUsage').textContent = p.usage_instructions;
                    document.getElementById('modalUsageSection').classList.remove('hidden');
                } else {
                    document.getElementById('modalUsageSection').classList.add('hidden');
                }
                
                // Manufacturer - check settings
                const showManufacturer = <?= $promoSettings['show_manufacturer'] == '1' ? 'true' : 'false' ?>;
                if (showManufacturer && p.manufacturer) {
                    document.getElementById('modalManufacturer').textContent = `ผู้ผลิต: ${p.manufacturer}`;
                    document.getElementById('modalManufacturerSection').classList.remove('hidden');
                } else {
                    document.getElementById('modalManufacturerSection').classList.add('hidden');
                }
                
                // Generic Name
                if (p.generic_name) {
                    document.getElementById('modalGeneric').textContent = `ชื่อสามัญ: ${p.generic_name}`;
                    document.getElementById('modalGenericSection').classList.remove('hidden');
                } else {
                    document.getElementById('modalGenericSection').classList.add('hidden');
                }
                
                // Image
                const imgDiv = document.getElementById('modalImage');
                if (p.image_url) {
                    imgDiv.innerHTML = `<img src="${p.image_url}" class="w-full h-full object-contain bg-white" onerror="this.parentElement.innerHTML='<div class=\\'w-full h-full flex items-center justify-center bg-gray-100\\'><i class=\\'fas fa-image text-5xl text-gray-300\\'></i></div>'">`;
                } else {
                    imgDiv.innerHTML = '<div class="w-full h-full flex items-center justify-center bg-gray-100"><i class="fas fa-image text-5xl text-gray-300"></i></div>';
                }
                
                // Enable add to cart button
                document.getElementById('addToCartBtn').disabled = (stock <= 0);
                
            } else {
                closeModal();
                alert('ไม่พบข้อมูลสินค้า');
            }
        } catch (e) {
            console.error(e);
            closeModal();
            alert('ไม่สามารถโหลดข้อมูลสินค้าได้');
        }
    }

    function closeModal() {
        document.getElementById('productModal').classList.add('hidden');
        document.body.style.overflow = '';
        currentProduct = null;
    }

    function changeQty(delta) {
        currentQty = Math.max(1, currentQty + delta);
        document.getElementById('modalQty').textContent = currentQty;
    }

    async function addToCart() {
        if (!currentProduct || !userId) {
            alert('กรุณาเข้าสู่ระบบก่อน');
            return;
        }
        
        try {
            const res = await fetch(`${BASE_URL}/api/checkout.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add_to_cart',
                    line_user_id: userId,
                    product_id: currentProduct.id,
                    quantity: currentQty,
                    line_account_id: ACCOUNT_ID
                })
            });
            const data = await res.json();
            
            if (data.success) {
                await loadCart();
                closeModal();
                
                // Show toast
                const toast = document.createElement('div');
                toast.className = 'fixed bottom-24 left-1/2 -translate-x-1/2 bg-green-500 text-white px-4 py-2 rounded-full shadow-lg z-50';
                toast.innerHTML = '<i class="fas fa-check mr-2"></i>เพิ่มลงตะกร้าแล้ว';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 2000);
            } else {
                alert(data.error || data.message || 'เกิดข้อผิดพลาด');
            }
        } catch (e) {
            console.error(e);
            alert('เกิดข้อผิดพลาด');
        }
    }

    function openCart() {
        renderCartDrawer();
        document.getElementById('cartDrawer').classList.remove('hidden');
        setTimeout(() => {
            document.querySelector('.cart-drawer-content').classList.add('show');
        }, 10);
        document.body.style.overflow = 'hidden';
    }

    function closeCartDrawer() {
        document.querySelector('.cart-drawer-content').classList.remove('show');
        setTimeout(() => {
            document.getElementById('cartDrawer').classList.add('hidden');
            document.body.style.overflow = '';
        }, 300);
    }

    function renderCartDrawer() {
        const container = document.getElementById('cartItems');
        const totalEl = document.getElementById('cartTotal');
        const checkoutBtn = document.getElementById('checkoutBtn');
        
        if (cart.length === 0) {
            container.innerHTML = `
                <div class="text-center py-12 text-gray-400">
                    <i class="fas fa-shopping-cart text-5xl mb-4"></i>
                    <p>ตะกร้าว่างเปล่า</p>
                </div>
            `;
            totalEl.textContent = '฿0';
            checkoutBtn.disabled = true;
            return;
        }
        
        let html = '';
        let total = 0;
        
        cart.forEach(item => {
            const price = item.sale_price || item.price;
            const subtotal = price * item.quantity;
            total += subtotal;
            
            html += `
                <div class="flex gap-3 mb-4 pb-4 border-b last:border-0">
                    <div class="w-16 h-16 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                        ${item.image_url ? `<img src="${item.image_url}" class="w-full h-full object-cover">` : '<div class="w-full h-full flex items-center justify-center"><i class="fas fa-image text-gray-300"></i></div>'}
                    </div>
                    <div class="flex-1 min-w-0">
                        <h4 class="text-sm font-medium text-gray-800 line-clamp-2">${item.name}</h4>
                        <p class="text-teal-600 font-bold mt-1">฿${Number(price).toLocaleString()}</p>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <button onclick="removeCartItem(${item.product_id})" class="text-red-400 hover:text-red-600">
                            <i class="fas fa-trash-alt text-sm"></i>
                        </button>
                        <div class="flex items-center border rounded">
                            <button onclick="updateCartQty(${item.product_id}, ${item.quantity - 1})" class="px-2 py-1 text-gray-500 hover:bg-gray-100">
                                <i class="fas fa-minus text-xs"></i>
                            </button>
                            <span class="px-2 text-sm font-medium">${item.quantity}</span>
                            <button onclick="updateCartQty(${item.product_id}, ${item.quantity + 1})" class="px-2 py-1 text-gray-500 hover:bg-gray-100">
                                <i class="fas fa-plus text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        totalEl.textContent = `฿${total.toLocaleString()}`;
        checkoutBtn.disabled = false;
    }

    async function updateCartQty(productId, newQty) {
        if (newQty < 1) {
            removeCartItem(productId);
            return;
        }
        
        try {
            const res = await fetch(`${BASE_URL}/api/checkout.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_cart',
                    line_user_id: userId,
                    product_id: productId,
                    quantity: newQty
                })
            });
            const data = await res.json();
            if (data.success) {
                await loadCart();
                renderCartDrawer();
            }
        } catch (e) { console.error(e); }
    }

    async function removeCartItem(productId) {
        try {
            const res = await fetch(`${BASE_URL}/api/checkout.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'remove_from_cart',
                    line_user_id: userId,
                    product_id: productId
                })
            });
            const data = await res.json();
            if (data.success) {
                await loadCart();
                renderCartDrawer();
            }
        } catch (e) { console.error(e); }
    }

    async function clearCart() {
        if (!confirm('ต้องการล้างตะกร้าทั้งหมด?')) return;
        
        try {
            const res = await fetch(`${BASE_URL}/api/checkout.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'clear_cart',
                    line_user_id: userId
                })
            });
            const data = await res.json();
            if (data.success) {
                cart = [];
                updateCartBadge();
                renderCartDrawer();
            }
        } catch (e) { console.error(e); }
    }

    function goToCheckout() {
        closeCartDrawer();
        window.location.href = `liff-checkout.php?user=${userId}&account=${ACCOUNT_ID}`;
    }

    function goHome() {
        window.location.href = `liff-app.php?account=${ACCOUNT_ID}`;
    }

    function openChat() {
        if (typeof liff !== 'undefined' && liff.isInClient()) {
            liff.closeWindow();
        }
    }

    // Search - รีหน้าใหม่แทนเปิด modal
    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const q = this.value.trim();
            if (q) {
                window.location.href = `liff-shop.php?user=${userId}&account=${ACCOUNT_ID}&search=${encodeURIComponent(q)}`;
            }
        }
    });
    
    // Close modal on backdrop click
    document.getElementById('productModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    
    // Banner Carousel
    let currentSlide = 0;
    const bannerTrack = document.getElementById('bannerTrack');
    const bannerDots = document.querySelectorAll('.banner-dot');
    const totalSlides = bannerDots.length;
    
    function goToSlide(index) {
        if (!bannerTrack || totalSlides === 0) return;
        currentSlide = index;
        if (currentSlide < 0) currentSlide = totalSlides - 1;
        if (currentSlide >= totalSlides) currentSlide = 0;
        bannerTrack.style.transform = `translateX(-${currentSlide * 100}%)`;
        bannerDots.forEach((dot, i) => {
            dot.classList.toggle('active', i === currentSlide);
        });
    }
    
    function nextSlide() { goToSlide(currentSlide + 1); }
    function prevSlide() { goToSlide(currentSlide - 1); }
    
    // Auto slide every X seconds (from settings)
    const BANNER_INTERVAL = <?= (int)$promoSettings['banner_interval'] ?>;
    const BANNER_AUTOPLAY = <?= $promoSettings['banner_autoplay'] == '1' ? 'true' : 'false' ?>;
    let autoSlideInterval;
    if (totalSlides > 1 && BANNER_AUTOPLAY) {
        autoSlideInterval = setInterval(() => {
            goToSlide(currentSlide + 1);
        }, BANNER_INTERVAL);
        
        // Pause on hover
        const carousel = document.getElementById('bannerCarousel');
        if (carousel) {
            carousel.addEventListener('mouseenter', () => clearInterval(autoSlideInterval));
            carousel.addEventListener('mouseleave', () => {
                if (BANNER_AUTOPLAY) {
                    autoSlideInterval = setInterval(() => goToSlide(currentSlide + 1), BANNER_INTERVAL);
                }
            });
        }
    }
    
    // Touch swipe support for banner
    let touchStartX = 0;
    let touchEndX = 0;
    if (bannerTrack) {
        bannerTrack.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, { passive: true });
        bannerTrack.addEventListener('touchend', e => {
            touchEndX = e.changedTouches[0].screenX;
            if (touchStartX - touchEndX > 50) nextSlide();
            if (touchEndX - touchStartX > 50) prevSlide();
        }, { passive: true });
    }
    </script>
</body>
</html>
