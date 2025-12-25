<?php
/**
 * LIFF Promotions & Rewards - โปรโมชั่นและแลกแต้ม
 * รวมระบบ:
 * - สินค้าเด่น (Featured)
 * - สินค้าขายดี (Best Seller)
 * - ของรางวัลแลกแต้ม
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/liff-helper.php';

$db = Database::getInstance()->getConnection();

// Get params
$userId = $_GET['user'] ?? null;
$lineAccountId = $_GET['account'] ?? null;
$tab = $_GET['tab'] ?? 'promotions'; // promotions, rewards

// Get line_account_id from user
if (!$lineAccountId && $userId && strpos($userId, 'U') === 0) {
    try {
        $stmt = $db->prepare("SELECT line_account_id FROM users WHERE line_user_id = ?");
        $stmt->execute([$userId]);
        $lineAccountId = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
if (!$lineAccountId) $lineAccountId = $liffData['line_account_id'];

$shopSettings = getShopSettings($db, $lineAccountId);
$companyName = $shopSettings['shop_name'] ?? 'ร้านค้า';
$baseUrl = rtrim(BASE_URL, '/');

// Get promotion settings
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
    'products_per_section' => (int)getPromoSetting($db, $lineAccountId, 'products_per_section', 6),
    'columns_mobile' => (int)getPromoSetting($db, $lineAccountId, 'columns_mobile', 2),
    'columns_desktop' => (int)getPromoSetting($db, $lineAccountId, 'columns_desktop', 4),
    'show_sale_section' => getPromoSetting($db, $lineAccountId, 'show_sale_section', '1'),
    'show_bestseller_section' => getPromoSetting($db, $lineAccountId, 'show_bestseller_section', '1'),
    'show_featured_section' => getPromoSetting($db, $lineAccountId, 'show_featured_section', '1'),
    'show_points_badge' => getPromoSetting($db, $lineAccountId, 'show_points_badge', '1'),
    'primary_color' => getPromoSetting($db, $lineAccountId, 'primary_color', '#11B0A6'),
    'sale_badge_color' => getPromoSetting($db, $lineAccountId, 'sale_badge_color', '#EF4444'),
    'bestseller_badge_color' => getPromoSetting($db, $lineAccountId, 'bestseller_badge_color', '#F59E0B'),
    'featured_badge_color' => getPromoSetting($db, $lineAccountId, 'featured_badge_color', '#8B5CF6'),
    'card_style' => getPromoSetting($db, $lineAccountId, 'card_style', 'rounded'),
    'card_shadow' => getPromoSetting($db, $lineAccountId, 'card_shadow', 'sm'),
    'show_sku' => getPromoSetting($db, $lineAccountId, 'show_sku', '0'),
    'show_stock' => getPromoSetting($db, $lineAccountId, 'show_stock', '0'),
    'banner_height_mobile' => (int)getPromoSetting($db, $lineAccountId, 'banner_height_mobile', 160),
    'banner_height_desktop' => (int)getPromoSetting($db, $lineAccountId, 'banner_height_desktop', 200),
    'banner_autoplay' => getPromoSetting($db, $lineAccountId, 'banner_autoplay', '1'),
    'banner_interval' => (int)getPromoSetting($db, $lineAccountId, 'banner_interval', 5000),
];
$banners = getPromoSetting($db, $lineAccountId, 'banners', []);

// Image size mapping
$imageSizes = ['small' => '120px', 'medium' => '160px', 'large' => '200px', 'xlarge' => '240px'];
$imageSize = $imageSizes[$promoSettings['image_size']] ?? '160px';

// Card border radius
$cardRadius = ['square' => '0', 'rounded' => '8px', 'rounded-lg' => '16px', 'rounded-xl' => '24px'];
$borderRadius = $cardRadius[$promoSettings['card_style']] ?? '16px';

// Check columns
$hasIsFeatured = $hasIsBestseller = false;
try {
    $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
    $hasIsFeatured = in_array('is_featured', $cols);
    $hasIsBestseller = in_array('is_bestseller', $cols);
} catch (Exception $e) {}

// Get Featured Products
$featuredProducts = [];
if ($hasIsFeatured) {
    try {
        $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id
                FROM business_items WHERE is_active = 1 AND is_featured = 1
                ORDER BY id DESC LIMIT 20";
        $featuredProducts = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Get Best Sellers
$bestSellers = [];
if ($hasIsBestseller) {
    try {
        $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id
                FROM business_items WHERE is_active = 1 AND is_bestseller = 1
                ORDER BY id DESC LIMIT 20";
        $bestSellers = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Get Sale Products (มีราคาลด)
$saleProducts = [];
try {
    $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id,
                   ROUND((1 - sale_price/price) * 100) as discount_percent
            FROM business_items 
            WHERE is_active = 1 AND sale_price IS NOT NULL AND sale_price > 0 AND sale_price < price
            ORDER BY discount_percent DESC LIMIT 20";
    $saleProducts = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get Rewards
$rewards = [];
try {
    $sql = "SELECT * FROM point_rewards WHERE is_active = 1 ORDER BY points_required ASC";
    $rewards = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>โปรโมชั่น - <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: <?= $promoSettings['primary_color'] ?>;
            --sale-badge: <?= $promoSettings['sale_badge_color'] ?>;
            --bestseller-badge: <?= $promoSettings['bestseller_badge_color'] ?>;
            --featured-badge: <?= $promoSettings['featured_badge_color'] ?>;
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
        
        .tab-btn { padding: 10px 20px; border-radius: 25px; font-weight: 600; transition: all 0.2s; }
        .tab-btn.active { background: var(--primary); color: white; }
        .tab-btn:not(.active) { background: white; color: #6B7280; border: 1px solid #E5E7EB; }
        
        .product-card { 
            background: white; 
            border-radius: var(--card-radius); 
            overflow: hidden; 
            <?php
            $shadows = ['none' => 'none', 'sm' => '0 2px 8px rgba(0,0,0,0.06)', 'md' => '0 4px 12px rgba(0,0,0,0.1)', 'lg' => '0 8px 24px rgba(0,0,0,0.15)'];
            echo 'box-shadow: ' . ($shadows[$promoSettings['card_shadow']] ?? $shadows['sm']) . ';';
            ?>
            transition: transform 0.15s; 
        }
        .product-card:active { transform: scale(0.98); }
        .product-card .product-image { height: var(--image-size); }
        
        .sale-badge { position: absolute; top: 8px; left: 8px; background: var(--sale-badge); color: white; font-size: 10px; padding: 2px 8px; border-radius: 12px; font-weight: bold; }
        .featured-badge { position: absolute; top: 8px; right: 8px; background: var(--featured-badge); color: white; font-size: 10px; padding: 2px 8px; border-radius: 12px; font-weight: bold; }
        .bestseller-badge { position: absolute; top: 8px; left: 8px; background: var(--bestseller-badge); color: white; font-size: 10px; padding: 2px 8px; border-radius: 12px; font-weight: bold; }
        
        .reward-card { background: white; border-radius: var(--card-radius); overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); transition: transform 0.15s; }
        .reward-card:active { transform: scale(0.98); }
        
        .points-badge { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        
        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .section-title { display: flex; align-items: center; gap: 8px; font-weight: bold; color: #1F2937; }
        .section-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
        
        .product-grid { 
            display: grid; 
            grid-template-columns: repeat(var(--cols-mobile), 1fr); 
            gap: 12px; 
        }
        @media (min-width: 768px) {
            .product-grid { grid-template-columns: repeat(var(--cols-desktop), 1fr); }
        }
        
        .price-text { color: var(--primary); }
        
        /* Banner Slider */
        .banner-slider { height: var(--banner-height-mobile); overflow: hidden; position: relative; }
        @media (min-width: 768px) { .banner-slider { height: var(--banner-height-desktop); } }
        .banner-slider img { width: 100%; height: 100%; object-fit: cover; }
        .banner-dots { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); display: flex; gap: 6px; }
        .banner-dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.5); cursor: pointer; }
        .banner-dot.active { background: white; }
        
        /* Image ratio */
        <?php
        $ratios = ['1:1' => '100%', '4:3' => '75%', '3:4' => '133.33%', '16:9' => '56.25%'];
        $paddingRatio = $ratios[$promoSettings['image_ratio']] ?? '100%';
        ?>
        .product-image-wrapper { position: relative; padding-bottom: <?= $paddingRatio ?>; overflow: hidden; background: #f3f4f6; }
        .product-image-wrapper img, .product-image-wrapper .placeholder { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; }
        .product-image-wrapper .placeholder { display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body class="min-h-screen pb-24">
    <!-- Header -->
    <div class="bg-gradient-to-r from-teal-500 to-teal-600 text-white sticky top-0 z-20">
        <div class="flex items-center justify-between p-4">
            <button onclick="goBack()" class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-white/20">
                <i class="fas fa-arrow-left text-xl"></i>
            </button>
            <h1 class="font-bold text-lg">โปรโมชั่น & ของรางวัล</h1>
            <div class="w-10"></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="p-4 bg-white shadow-sm sticky top-[72px] z-10">
        <div class="flex gap-2 justify-center">
            <button onclick="switchTab('promotions')" class="tab-btn <?= $tab === 'promotions' ? 'active' : '' ?>" id="tabPromotions">
                <i class="fas fa-tags mr-1"></i>โปรโมชั่น
            </button>
            <button onclick="switchTab('rewards')" class="tab-btn <?= $tab === 'rewards' ? 'active' : '' ?>" id="tabRewards">
                <i class="fas fa-gift mr-1"></i>แลกแต้ม
            </button>
        </div>
    </div>

    <!-- Promotions Tab -->
    <div id="contentPromotions" class="<?= $tab !== 'promotions' ? 'hidden' : '' ?>">
        
        <!-- Banner Slider -->
        <?php if (!empty($banners)): ?>
        <div class="banner-slider" id="bannerSlider">
            <?php foreach ($banners as $i => $banner): ?>
            <div class="banner-slide <?= $i === 0 ? 'active' : '' ?>" style="display: <?= $i === 0 ? 'block' : 'none' ?>">
                <?php if (!empty($banner['link'])): ?>
                <a href="<?= htmlspecialchars($banner['link']) ?>">
                    <img src="<?= htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title'] ?? 'Banner') ?>" loading="lazy">
                </a>
                <?php else: ?>
                <img src="<?= htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title'] ?? 'Banner') ?>" loading="lazy">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (count($banners) > 1): ?>
            <div class="banner-dots">
                <?php foreach ($banners as $i => $banner): ?>
                <span class="banner-dot <?= $i === 0 ? 'active' : '' ?>" onclick="goToSlide(<?= $i ?>)"></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Points Badge (if logged in) -->
        <?php if ($promoSettings['show_points_badge'] == '1'): ?>
        <div class="p-4" id="pointsBadgePromo">
            <div class="points-badge rounded-2xl p-4 text-white shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/70 text-sm">แต้มสะสมของคุณ</p>
                        <p class="text-2xl font-bold" id="currentPointsPromo">-</p>
                    </div>
                    <button onclick="switchTab('rewards')" class="px-4 py-2 bg-white/20 rounded-lg text-sm font-bold hover:bg-white/30">
                        <i class="fas fa-gift mr-1"></i>แลกแต้ม
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Sale Products -->
        <?php if (!empty($saleProducts) && $promoSettings['show_sale_section'] == '1'): ?>
        <div class="px-4 mb-6">
            <div class="section-header">
                <div class="section-title">
                    <div class="section-icon bg-red-100">🏷️</div>
                    <span>สินค้าลดราคา</span>
                </div>
                <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&sale=1" class="text-sm" style="color: var(--primary)">ดูทั้งหมด</a>
            </div>
            <div class="scroll-x">
                <?php foreach (array_slice($saleProducts, 0, $promoSettings['products_per_section']) as $p): 
                    $price = $p['sale_price'];
                    $originalPrice = $p['price'];
                    $discount = $p['discount_percent'];
                ?>
                <div class="scroll-item">
                    <div class="product-card relative" onclick="showProduct(<?= $p['id'] ?>)">
                        <span class="sale-badge">-<?= $discount ?>%</span>
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
                                <span class="font-bold" style="color: var(--sale-badge)">฿<?= number_format($price) ?></span>
                                <span class="text-gray-400 text-xs line-through ml-1">฿<?= number_format($originalPrice) ?></span>
                            </div>
                            <?php if ($promoSettings['show_stock'] == '1'): ?>
                            <p class="text-xs text-gray-500 mt-1">คงเหลือ <?= number_format($p['stock'] ?? 0) ?> ชิ้น</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Best Sellers -->
        <?php if (!empty($bestSellers) && $promoSettings['show_bestseller_section'] == '1'): ?>
        <div class="px-4 mb-6">
            <div class="section-header">
                <div class="section-title">
                    <div class="section-icon bg-orange-100">🔥</div>
                    <span>สินค้าขายดี</span>
                </div>
                <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&bestseller=1" class="text-sm" style="color: var(--primary)">ดูทั้งหมด</a>
            </div>
            <div class="product-grid">
                <?php foreach (array_slice($bestSellers, 0, $promoSettings['products_per_section']) as $p): 
                    $price = $p['sale_price'] ?: $p['price'];
                    $originalPrice = $p['sale_price'] ? $p['price'] : null;
                ?>
                <div class="product-card relative" onclick="showProduct(<?= $p['id'] ?>)">
                    <span class="bestseller-badge">🔥 ขายดี</span>
                    <div class="product-image-wrapper">
                        <?php if ($p['image_url']): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" loading="lazy">
                        <?php else: ?>
                        <div class="placeholder"><i class="fas fa-image text-2xl text-gray-300"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="p-2">
                        <h3 class="text-xs font-medium text-gray-800 line-clamp-2 h-8"><?= htmlspecialchars($p['name']) ?></h3>
                        <?php if ($promoSettings['show_sku'] == '1' && !empty($p['sku'])): ?>
                        <p class="text-[10px] text-gray-400"><?= htmlspecialchars($p['sku']) ?></p>
                        <?php endif; ?>
                        <div class="mt-1">
                            <span class="price-text font-bold text-sm">฿<?= number_format($price) ?></span>
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
        </div>
        <?php endif; ?>

        <!-- Featured Products -->
        <?php if (!empty($featuredProducts) && $promoSettings['show_featured_section'] == '1'): ?>
        <div class="px-4 mb-6">
            <div class="section-header">
                <div class="section-title">
                    <div class="section-icon bg-yellow-100">⭐</div>
                    <span>สินค้าแนะนำ</span>
                </div>
                <a href="liff-shop.php?user=<?= $userId ?>&account=<?= $lineAccountId ?>&featured=1" class="text-sm" style="color: var(--primary)">ดูทั้งหมด</a>
            </div>
            <div class="product-grid">
                <?php foreach (array_slice($featuredProducts, 0, $promoSettings['products_per_section']) as $p): 
                    $price = $p['sale_price'] ?: $p['price'];
                    $originalPrice = $p['sale_price'] ? $p['price'] : null;
                ?>
                <div class="product-card relative" onclick="showProduct(<?= $p['id'] ?>)">
                    <span class="featured-badge">⭐ แนะนำ</span>
                    <div class="product-image-wrapper">
                        <?php if ($p['image_url']): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" loading="lazy">
                        <?php else: ?>
                        <div class="placeholder"><i class="fas fa-image text-2xl text-gray-300"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="p-2">
                        <h3 class="text-xs font-medium text-gray-800 line-clamp-2 h-8"><?= htmlspecialchars($p['name']) ?></h3>
                        <?php if ($promoSettings['show_sku'] == '1' && !empty($p['sku'])): ?>
                        <p class="text-[10px] text-gray-400"><?= htmlspecialchars($p['sku']) ?></p>
                        <?php endif; ?>
                        <div class="mt-1">
                            <span class="price-text font-bold text-sm">฿<?= number_format($price) ?></span>
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
        </div>
        <?php endif; ?>

        <?php if ((empty($saleProducts) || $promoSettings['show_sale_section'] != '1') && 
                  (empty($bestSellers) || $promoSettings['show_bestseller_section'] != '1') && 
                  (empty($featuredProducts) || $promoSettings['show_featured_section'] != '1')): ?>
        <div class="text-center py-16 text-gray-500">
            <i class="fas fa-tags text-6xl text-gray-300 mb-4"></i>
            <p>ยังไม่มีโปรโมชั่น</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Rewards Tab -->
    <div id="contentRewards" class="<?= $tab !== 'rewards' ? 'hidden' : '' ?>">
        <!-- Points Badge -->
        <div class="p-4">
            <div class="points-badge rounded-2xl p-4 text-white shadow-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/70 text-sm">แต้มของคุณ</p>
                        <p class="text-3xl font-bold" id="currentPointsReward">-</p>
                    </div>
                    <div class="w-14 h-14 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-coins text-2xl text-yellow-300"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rewards List -->
        <div class="px-4">
            <h2 class="font-bold text-gray-800 mb-3">ของรางวัลที่แลกได้</h2>
            
            <div id="rewardsList" class="space-y-3">
                <?php if (empty($rewards)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-gift text-6xl text-gray-300 mb-4"></i>
                    <p class="text-gray-500">ยังไม่มีของรางวัล</p>
                </div>
                <?php else: ?>
                <?php foreach ($rewards as $reward): 
                    $typeIcons = [
                        'discount' => ['bg' => 'bg-green-50', 'icon' => 'fa-percent', 'color' => 'text-green-500'],
                        'shipping' => ['bg' => 'bg-blue-50', 'icon' => 'fa-truck', 'color' => 'text-blue-500'],
                        'gift' => ['bg' => 'bg-pink-50', 'icon' => 'fa-gift', 'color' => 'text-pink-500'],
                        'product' => ['bg' => 'bg-orange-50', 'icon' => 'fa-box', 'color' => 'text-orange-500'],
                        'coupon' => ['bg' => 'bg-purple-50', 'icon' => 'fa-ticket', 'color' => 'text-purple-500'],
                    ];
                    $iconData = $typeIcons[$reward['type']] ?? $typeIcons['gift'];
                ?>
                <div class="reward-card p-4" data-reward-id="<?= $reward['id'] ?>" data-points="<?= $reward['points_required'] ?>">
                    <div class="flex gap-4">
                        <div class="w-20 h-20 <?= $iconData['bg'] ?> rounded-xl flex items-center justify-center flex-shrink-0">
                            <i class="fas <?= $iconData['icon'] ?> text-3xl <?= $iconData['color'] ?>"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-800 truncate"><?= htmlspecialchars($reward['name']) ?></h3>
                            <p class="text-xs text-gray-500 mb-2 line-clamp-2"><?= htmlspecialchars($reward['description'] ?? '') ?></p>
                            <div class="flex items-center justify-between">
                                <span class="text-purple-600 font-bold text-sm">
                                    <i class="fas fa-coins text-yellow-500 mr-1"></i><?= number_format($reward['points_required']) ?> แต้ม
                                </span>
                                <button onclick="redeemReward(<?= $reward['id'] ?>, '<?= htmlspecialchars(addslashes($reward['name'])) ?>', <?= $reward['points_required'] ?>)" 
                                    class="redeem-btn px-4 py-1.5 rounded-lg text-sm font-bold bg-gray-200 text-gray-400 cursor-not-allowed" disabled>
                                    แลก
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    const BASE_URL = '<?= $baseUrl ?>';
    const LIFF_ID = '<?= $liffId ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    const USER_ID_PARAM = '<?= $userId ?>';
    const BANNER_AUTOPLAY = <?= $promoSettings['banner_autoplay'] == '1' ? 'true' : 'false' ?>;
    const BANNER_INTERVAL = <?= (int)$promoSettings['banner_interval'] ?>;
    
    let lineUserId = null;
    let currentPoints = 0;
    let currentSlide = 0;
    let bannerTimer = null;
    const totalSlides = <?= count($banners) ?>;

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        // Init banner slider
        if (totalSlides > 1 && BANNER_AUTOPLAY) {
            startBannerAutoplay();
        }
        
        if (!LIFF_ID) {
            console.warn('No LIFF ID');
            return;
        }
        
        try {
            await liff.init({ liffId: LIFF_ID });
            
            if (liff.isLoggedIn()) {
                const profile = await liff.getProfile();
                lineUserId = profile.userId;
                await loadPoints();
            }
        } catch (e) {
            console.error('LIFF init error:', e);
        }
    }
    
    // Banner Slider Functions
    function goToSlide(index) {
        if (totalSlides <= 1) return;
        
        const slides = document.querySelectorAll('.banner-slide');
        const dots = document.querySelectorAll('.banner-dot');
        
        slides.forEach((slide, i) => {
            slide.style.display = i === index ? 'block' : 'none';
        });
        dots.forEach((dot, i) => {
            dot.classList.toggle('active', i === index);
        });
        
        currentSlide = index;
    }
    
    function nextSlide() {
        goToSlide((currentSlide + 1) % totalSlides);
    }
    
    function startBannerAutoplay() {
        if (bannerTimer) clearInterval(bannerTimer);
        bannerTimer = setInterval(nextSlide, BANNER_INTERVAL);
    }

    async function loadPoints() {
        if (!lineUserId) return;
        
        try {
            const response = await fetch(`${BASE_URL}/api/points.php?action=history&line_user_id=${lineUserId}&line_account_id=${ACCOUNT_ID}&limit=1`);
            const data = await response.json();
            if (data.success) {
                currentPoints = data.current_points || 0;
                document.getElementById('currentPointsPromo').textContent = numberFormat(currentPoints);
                document.getElementById('currentPointsReward').textContent = numberFormat(currentPoints);
                updateRedeemButtons();
            }
        } catch (e) {
            console.error('Load points error:', e);
        }
    }

    function updateRedeemButtons() {
        document.querySelectorAll('.reward-card').forEach(card => {
            const pointsRequired = parseInt(card.dataset.points);
            const btn = card.querySelector('.redeem-btn');
            if (currentPoints >= pointsRequired) {
                btn.classList.remove('bg-gray-200', 'text-gray-400', 'cursor-not-allowed');
                btn.classList.add('bg-purple-600', 'text-white');
                btn.disabled = false;
            } else {
                btn.classList.add('bg-gray-200', 'text-gray-400', 'cursor-not-allowed');
                btn.classList.remove('bg-purple-600', 'text-white');
                btn.disabled = true;
            }
        });
    }

    function switchTab(tab) {
        const promoTab = document.getElementById('tabPromotions');
        const rewardTab = document.getElementById('tabRewards');
        const promoContent = document.getElementById('contentPromotions');
        const rewardContent = document.getElementById('contentRewards');
        
        if (tab === 'promotions') {
            promoTab.classList.add('active');
            rewardTab.classList.remove('active');
            promoContent.classList.remove('hidden');
            rewardContent.classList.add('hidden');
        } else {
            promoTab.classList.remove('active');
            rewardTab.classList.add('active');
            promoContent.classList.add('hidden');
            rewardContent.classList.remove('hidden');
        }
        
        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    }

    function showProduct(productId) {
        window.location.href = `${BASE_URL}/liff-product-detail.php?id=${productId}&user=${lineUserId || USER_ID_PARAM}&account=${ACCOUNT_ID}`;
    }

    async function redeemReward(rewardId, rewardName, pointsRequired) {
        if (!lineUserId) {
            Swal.fire({ icon: 'warning', title: 'กรุณาเข้าสู่ระบบ', confirmButtonColor: '#7C3AED' });
            return;
        }
        
        if (currentPoints < pointsRequired) {
            Swal.fire({
                icon: 'warning',
                title: 'แต้มไม่เพียงพอ',
                text: `ต้องการ ${numberFormat(pointsRequired)} แต้ม คุณมี ${numberFormat(currentPoints)} แต้ม`,
                confirmButtonColor: '#7C3AED'
            });
            return;
        }
        
        const result = await Swal.fire({
            title: 'ยืนยันการแลก',
            html: `แลก <b>${rewardName}</b><br>ใช้ ${numberFormat(pointsRequired)} แต้ม`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#7C3AED',
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก'
        });
        
        if (!result.isConfirmed) return;
        
        try {
            Swal.fire({ title: 'กำลังดำเนินการ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            const response = await fetch(`${BASE_URL}/api/points.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'redeem',
                    line_user_id: lineUserId,
                    line_account_id: ACCOUNT_ID,
                    reward_id: rewardId
                })
            });
            const data = await response.json();
            
            if (data.success) {
                currentPoints = data.new_balance;
                document.getElementById('currentPointsPromo').textContent = numberFormat(currentPoints);
                document.getElementById('currentPointsReward').textContent = numberFormat(currentPoints);
                updateRedeemButtons();
                
                await Swal.fire({
                    icon: 'success',
                    title: 'แลกสำเร็จ!',
                    html: `รหัสคูปอง: <b class="text-purple-600">${data.coupon_code}</b><br><small class="text-gray-500">กรุณาบันทึกรหัสนี้ไว้</small>`,
                    confirmButtonColor: '#7C3AED'
                });
            } else {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: data.message, confirmButtonColor: '#7C3AED' });
            }
        } catch (e) {
            console.error(e);
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', confirmButtonColor: '#7C3AED' });
        }
    }

    function goBack() {
        if (liff.isInClient()) {
            window.location.href = `${BASE_URL}/liff-shop.php?user=${lineUserId || USER_ID_PARAM}&account=${ACCOUNT_ID}`;
        } else {
            window.history.back();
        }
    }

    function numberFormat(num) {
        return new Intl.NumberFormat('th-TH').format(num);
    }
    </script>
    
    <?php include 'includes/liff-nav.php'; ?>
</body>
</html>
