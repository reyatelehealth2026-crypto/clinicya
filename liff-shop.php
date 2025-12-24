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
$shopLogo = $shopSettings['logo_url'] ?? '';
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

// Check columns
$hasIsFeatured = $hasIsBestseller = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
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
        $countSql = "SELECT COUNT(*) FROM products WHERE $whereClause";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $totalProducts = (int)$stmt->fetchColumn();
        $totalPages = max(1, ceil($totalProducts / $perPage));
        
        // Get products with pagination
        $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id,
                       $featuredCol as is_featured, $bestsellerCol as is_bestseller
                FROM products WHERE $whereClause ORDER BY id DESC LIMIT $perPage OFFSET $offset";
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
                FROM products WHERE is_active = 1 AND is_bestseller = 1";
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
                FROM products WHERE is_active = 1 AND is_featured = 1";
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
                    FROM products WHERE is_active = 1 AND category_id = ?";
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
    <title><?= htmlspecialchars($shopName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #11B0A6; --danger: #EF4444; --warning: #F59E0B; }
        body { font-family: 'Sarabun', sans-serif; background: #F8FAFC; }
        .scroll-x { display: flex; gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; padding-bottom: 8px; }
        .scroll-x::-webkit-scrollbar { display: none; }
        .scroll-item { scroll-snap-align: start; flex-shrink: 0; }
        
        /* Best Seller Card - กรอบเด่น */
        .bestseller-card {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            border: 2px solid #F59E0B;
            border-radius: 16px;
            position: relative;
            overflow: hidden;
        }
        .bestseller-card::before {
            content: '🔥 BEST SELLER';
            position: absolute;
            top: 0; left: 0; right: 0;
            background: linear-gradient(90deg, #EF4444, #DC2626);
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
            border: 2px solid #F59E0B;
            border-radius: 16px;
            position: relative;
        }
        .featured-badge {
            position: absolute;
            top: 8px; right: 8px;
            background: #F59E0B;
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
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: transform 0.15s;
        }
        .product-card:active { transform: scale(0.98); }
        
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
        
        .cart-badge { position: absolute; top: -5px; right: -5px; background: #EF4444; color: white; font-size: 10px; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        
        /* Banner Carousel */
        .banner-carousel { position: relative; overflow: hidden; border-radius: 16px; }
        .banner-track { display: flex; transition: transform 0.3s ease; }
        .banner-slide { flex-shrink: 0; width: 100%; }
        .banner-slide img { width: 100%; height: 140px; object-fit: cover; border-radius: 16px; }
        .banner-dots { position: absolute; bottom: 8px; left: 50%; transform: translateX(-50%); display: flex; gap: 6px; }
        .banner-dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; }
        .banner-dot.active { background: white; }
        
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
                        <img src="<?= htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title'] ?? 'โปรโมชั่น') ?>">
                    </a>
                    <?php else: ?>
                    <img src="<?= htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title'] ?? 'โปรโมชั่น') ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($banners) > 1): ?>
            <div class="banner-dots">
                <?php for ($i = 0; $i < count($banners); $i++): ?>
                <div class="banner-dot <?= $i === 0 ? 'active' : '' ?>" onclick="goToSlide(<?= $i ?>)"></div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
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
        <div class="grid grid-cols-2 gap-3">
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
                <div class="<?= $isBestseller ? 'card-img' : '' ?> aspect-square bg-gray-100 flex items-center justify-center">
                    <?php if ($p['image_url']): ?>
                    <img src="<?= htmlspecialchars($p['image_url']) ?>" class="w-full h-full object-cover" loading="lazy">
                    <?php else: ?>
                    <i class="fas fa-image text-2xl text-gray-300"></i>
                    <?php endif; ?>
                </div>
                <div class="p-2 <?= $isBestseller ? 'bg-white' : '' ?>">
                    <h3 class="text-xs font-medium text-gray-800 line-clamp-2 h-8"><?= htmlspecialchars($p['name']) ?></h3>
                    <div class="mt-1">
                        <span class="<?= $isBestseller ? 'text-red-600' : 'text-teal-600' ?> font-bold text-sm">฿<?= number_format($price) ?></span>
                        <?php if ($originalPrice): ?>
                        <span class="text-gray-400 text-[10px] line-through ml-1">฿<?= number_format($originalPrice) ?></span>
                        <?php endif; ?>
                    </div>
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
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&bestseller=1" class="text-sm text-teal-600">ดูทั้งหมด</a>
        </div>
        
        <div class="scroll-x">
            <?php foreach ($bestSellers as $p): 
                $price = $p['sale_price'] ?: $p['price'];
                $originalPrice = $p['sale_price'] ? $p['price'] : null;
            ?>
            <div class="scroll-item w-40">
                <div class="bestseller-card" onclick="showProduct(<?= $p['id'] ?>)">
                    <div class="card-img aspect-square bg-white flex items-center justify-center">
                        <?php if ($p['image_url']): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                        <i class="fas fa-image text-3xl text-gray-300"></i>
                        <?php endif; ?>
                    </div>
                    <div class="p-3 bg-white">
                        <h3 class="text-sm font-medium text-gray-800 line-clamp-2 h-10"><?= htmlspecialchars($p['name']) ?></h3>
                        <div class="mt-2 flex items-center gap-2">
                            <span class="text-red-600 font-bold">฿<?= number_format($price) ?></span>
                            <?php if ($originalPrice): ?>
                            <span class="text-gray-400 text-xs line-through">฿<?= number_format($originalPrice) ?></span>
                            <?php endif; ?>
                        </div>
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
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&featured=1" class="text-sm text-teal-600">ดูทั้งหมด</a>
        </div>
        
        <div class="scroll-x">
            <?php foreach ($featuredProducts as $p): 
                $price = $p['sale_price'] ?: $p['price'];
                $originalPrice = $p['sale_price'] ? $p['price'] : null;
            ?>
            <div class="scroll-item w-36">
                <div class="featured-card product-card" onclick="showProduct(<?= $p['id'] ?>)">
                    <span class="featured-badge">⭐ แนะนำ</span>
                    <div class="aspect-square bg-gray-100 flex items-center justify-center">
                        <?php if ($p['image_url']): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                        <i class="fas fa-image text-3xl text-gray-300"></i>
                        <?php endif; ?>
                    </div>
                    <div class="p-3">
                        <h3 class="text-sm font-medium text-gray-800 line-clamp-2 h-10"><?= htmlspecialchars($p['name']) ?></h3>
                        <div class="mt-2">
                            <span class="text-teal-600 font-bold">฿<?= number_format($price) ?></span>
                            <?php if ($originalPrice): ?>
                            <span class="text-gray-400 text-xs line-through ml-1">฿<?= number_format($originalPrice) ?></span>
                            <?php endif; ?>
                        </div>
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
            <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&category=<?= $catId ?>" class="text-sm text-teal-600">ดูทั้งหมด</a>
        </div>
        
        <div class="scroll-x">
            <?php foreach ($products as $p): 
                $price = $p['sale_price'] ?: $p['price'];
                $originalPrice = $p['sale_price'] ? $p['price'] : null;
                $isBestseller = (int)($p['is_bestseller'] ?? 0);
                $isFeatured = (int)($p['is_featured'] ?? 0);
            ?>
            <div class="scroll-item w-32">
                <div class="<?= $isBestseller ? 'bestseller-card' : ($isFeatured ? 'featured-card product-card' : 'product-card') ?>" 
                     onclick="showProduct(<?= $p['id'] ?>)">
                    <?php if ($isFeatured && !$isBestseller): ?>
                    <span class="featured-badge text-[8px] px-1.5">⭐</span>
                    <?php endif; ?>
                    <div class="<?= $isBestseller ? 'card-img' : '' ?> aspect-square bg-gray-100 flex items-center justify-center">
                        <?php if ($p['image_url']): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" class="w-full h-full object-cover" loading="lazy">
                        <?php else: ?>
                        <i class="fas fa-image text-2xl text-gray-300"></i>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 <?= $isBestseller ? 'bg-white' : '' ?>">
                        <h3 class="text-[11px] font-medium text-gray-800 line-clamp-2 h-8 leading-tight"><?= htmlspecialchars($p['name']) ?></h3>
                        <div class="mt-1">
                            <span class="<?= $isBestseller ? 'text-red-600' : 'text-teal-600' ?> font-bold text-sm">฿<?= number_format($price) ?></span>
                            <?php if ($originalPrice): ?>
                            <span class="text-gray-400 text-[9px] line-through block">฿<?= number_format($originalPrice) ?></span>
                            <?php endif; ?>
                        </div>
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

    <!-- Product Detail Modal -->
    <div id="productModal" class="fixed inset-0 bg-black/50 z-50 hidden overflow-y-auto">
        <div class="min-h-screen flex items-start justify-center p-4 pt-8 pb-24">
            <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">
                <div class="relative">
                    <button onclick="closeModal()" class="absolute top-3 right-3 z-20 w-8 h-8 bg-black/30 rounded-full flex items-center justify-center text-white">
                        <i class="fas fa-times"></i>
                    </button>
                    <div id="modalImage" class="aspect-square bg-gray-100"></div>
                </div>
                <div class="p-4">
                    <h2 id="modalName" class="font-bold text-lg text-gray-800 mb-2"></h2>
                    <p id="modalSku" class="text-sm text-gray-500 mb-3"></p>
                    <div id="modalDesc" class="text-sm text-gray-600 mb-4 hidden"></div>
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <span id="modalPrice" class="text-2xl font-bold text-teal-600"></span>
                            <span id="modalOriginalPrice" class="text-sm text-gray-400 line-through ml-2 hidden"></span>
                        </div>
                        <div id="modalStock" class="text-sm text-gray-500"></div>
                    </div>
                    <div class="flex items-center gap-3 mb-4">
                        <div class="flex items-center border rounded-lg">
                            <button onclick="changeQty(-1)" class="px-3 py-2 text-gray-600 hover:bg-gray-100"><i class="fas fa-minus"></i></button>
                            <span id="modalQty" class="px-4 py-2 font-medium">1</span>
                            <button onclick="changeQty(1)" class="px-3 py-2 text-gray-600 hover:bg-gray-100"><i class="fas fa-plus"></i></button>
                        </div>
                        <button onclick="addToCart()" class="flex-1 py-3 bg-teal-500 text-white rounded-xl font-medium hover:bg-teal-600 transition">
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
        // Redirect to product detail page
        window.location.href = `liff-product-detail.php?id=${id}&user=${userId}&account=${ACCOUNT_ID}`;
    }

    // Keep old modal function for backward compatibility
    async function showProductModal(id) {
        try {
            const res = await fetch(`${BASE_URL}/api/shop-products.php?product_id=${id}&account=${ACCOUNT_ID}`);
            const data = await res.json();
            if (data.success && data.product) {
                currentProduct = data.product;
                currentQty = 1;
                
                const p = data.product;
                document.getElementById('modalName').textContent = p.name;
                document.getElementById('modalSku').textContent = p.sku ? `รหัส: ${p.sku}` : '';
                
                const price = p.sale_price || p.price;
                document.getElementById('modalPrice').textContent = `฿${Number(price).toLocaleString()}`;
                
                if (p.sale_price && p.price > p.sale_price) {
                    document.getElementById('modalOriginalPrice').textContent = `฿${Number(p.price).toLocaleString()}`;
                    document.getElementById('modalOriginalPrice').classList.remove('hidden');
                } else {
                    document.getElementById('modalOriginalPrice').classList.add('hidden');
                }
                
                document.getElementById('modalStock').textContent = `คงเหลือ: ${p.stock || 999}`;
                document.getElementById('modalQty').textContent = '1';
                
                if (p.description) {
                    document.getElementById('modalDesc').textContent = p.description;
                    document.getElementById('modalDesc').classList.remove('hidden');
                } else {
                    document.getElementById('modalDesc').classList.add('hidden');
                }
                
                const imgDiv = document.getElementById('modalImage');
                if (p.image_url) {
                    imgDiv.innerHTML = `<img src="${p.image_url}" class="w-full h-full object-cover">`;
                } else {
                    imgDiv.innerHTML = '<div class="w-full h-full flex items-center justify-center"><i class="fas fa-image text-5xl text-gray-300"></i></div>';
                }
                
                document.getElementById('productModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        } catch (e) {
            console.error(e);
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
        bannerTrack.style.transform = `translateX(-${index * 100}%)`;
        bannerDots.forEach((dot, i) => {
            dot.classList.toggle('active', i === index);
        });
    }
    
    // Auto slide every 4 seconds
    if (totalSlides > 1) {
        setInterval(() => {
            currentSlide = (currentSlide + 1) % totalSlides;
            goToSlide(currentSlide);
        }, 4000);
    }
    </script>
</body>
</html>
