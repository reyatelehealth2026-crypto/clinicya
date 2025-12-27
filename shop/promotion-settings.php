<?php
/**
 * Promotion Settings V2 - ตั้งค่าธีมหน้าร้าน LIFF
 * - เลือกธีมสำเร็จรูป 4 แบบ
 * - Preview แบบ Real-time
 * - ปรับแต่งเพิ่มเติมได้
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่าธีมหน้าร้าน';
$lineAccountId = $_SESSION['current_bot_id'] ?? 1;

// Ensure settings table exists
$db->exec("CREATE TABLE IF NOT EXISTS promotion_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_setting (line_account_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Helper functions
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

function setPromoSetting($db, $lineAccountId, $key, $value) {
    $jsonValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    $stmt = $db->prepare("INSERT INTO promotion_settings (line_account_id, setting_key, setting_value) 
                          VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$lineAccountId, $key, $jsonValue, $jsonValue]);
}

// Define 5 Themes
$themes = [
    'marketplace' => [
        'name' => '🛒 Marketplace',
        'description' => 'สไตล์ Lazada/Shopee มี Flash Sale, Quick Menu, Bottom Nav',
        'primary_color' => '#F85606',
        'secondary_color' => '#FFE4D6',
        'sale_badge_color' => '#EE4D2D',
        'bestseller_badge_color' => '#FFAA00',
        'featured_badge_color' => '#FF6B6B',
        'card_style' => 'rounded',
        'card_shadow' => 'sm',
        'image_size' => 'large',
        'columns_mobile' => 2,
        'layout_style' => 'marketplace',
        'show_flash_sale' => true,
        'show_quick_menu' => true,
        'show_sold_count' => true,
        'show_rating' => true,
    ],
    'pharmacy' => [
        'name' => '💊 ร้านยา',
        'description' => 'โทนสีเขียวมิ้นท์ สะอาดตา เหมาะกับร้านยา/สุขภาพ',
        'primary_color' => '#11B0A6',
        'secondary_color' => '#E0F7F5',
        'sale_badge_color' => '#EF4444',
        'bestseller_badge_color' => '#F59E0B',
        'featured_badge_color' => '#8B5CF6',
        'card_style' => 'rounded-lg',
        'card_shadow' => 'sm',
        'image_size' => 'medium',
        'columns_mobile' => 2,
        'layout_style' => 'classic',
    ],
    'modern' => [
        'name' => '🛍️ โมเดิร์น',
        'description' => 'โทนสีน้ำเงินเข้ม ดูหรูหรา เหมาะกับร้านค้าทั่วไป',
        'primary_color' => '#3B82F6',
        'secondary_color' => '#DBEAFE',
        'sale_badge_color' => '#DC2626',
        'bestseller_badge_color' => '#EA580C',
        'featured_badge_color' => '#7C3AED',
        'card_style' => 'rounded',
        'card_shadow' => 'md',
        'image_size' => 'large',
        'columns_mobile' => 2,
        'layout_style' => 'classic',
    ],
    'minimal' => [
        'name' => '✨ มินิมอล',
        'description' => 'โทนขาว-ดำ เรียบง่าย สะอาดตา',
        'primary_color' => '#1F2937',
        'secondary_color' => '#F3F4F6',
        'sale_badge_color' => '#EF4444',
        'bestseller_badge_color' => '#374151',
        'featured_badge_color' => '#6B7280',
        'card_style' => 'square',
        'card_shadow' => 'none',
        'image_size' => 'medium',
        'columns_mobile' => 2,
        'layout_style' => 'minimal',
    ],
    'warm' => [
        'name' => '🌸 อบอุ่น',
        'description' => 'โทนสีชมพู-ส้ม อบอุ่น เหมาะกับร้านเครื่องสำอาง/ของขวัญ',
        'primary_color' => '#EC4899',
        'secondary_color' => '#FCE7F3',
        'sale_badge_color' => '#F43F5E',
        'bestseller_badge_color' => '#F97316',
        'featured_badge_color' => '#A855F7',
        'card_style' => 'rounded-xl',
        'card_shadow' => 'lg',
        'image_size' => 'large',
        'columns_mobile' => 2,
        'layout_style' => 'classic',
    ],
];

$message = '';
$messageType = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    
    if ($action === 'apply_theme') {
        $themeKey = $_POST['theme'] ?? 'pharmacy';
        if (isset($themes[$themeKey])) {
            $theme = $themes[$themeKey];
            setPromoSetting($db, $lineAccountId, 'current_theme', $themeKey);
            setPromoSetting($db, $lineAccountId, 'primary_color', $theme['primary_color']);
            setPromoSetting($db, $lineAccountId, 'secondary_color', $theme['secondary_color'] ?? '#E0F7F5');
            setPromoSetting($db, $lineAccountId, 'sale_badge_color', $theme['sale_badge_color']);
            setPromoSetting($db, $lineAccountId, 'bestseller_badge_color', $theme['bestseller_badge_color']);
            setPromoSetting($db, $lineAccountId, 'featured_badge_color', $theme['featured_badge_color']);
            setPromoSetting($db, $lineAccountId, 'card_style', $theme['card_style']);
            setPromoSetting($db, $lineAccountId, 'card_shadow', $theme['card_shadow']);
            setPromoSetting($db, $lineAccountId, 'image_size', $theme['image_size']);
            setPromoSetting($db, $lineAccountId, 'columns_mobile', $theme['columns_mobile']);
            setPromoSetting($db, $lineAccountId, 'layout_style', $theme['layout_style'] ?? 'classic');
            
            // Marketplace specific settings
            if ($themeKey === 'marketplace') {
                setPromoSetting($db, $lineAccountId, 'show_flash_sale', '1');
                setPromoSetting($db, $lineAccountId, 'show_quick_menu', '1');
                setPromoSetting($db, $lineAccountId, 'show_sold_count', '1');
                setPromoSetting($db, $lineAccountId, 'show_rating', '1');
                setPromoSetting($db, $lineAccountId, 'show_bottom_nav', '1');
            }
            
            $message = 'เปลี่ยนธีมเป็น "' . $theme['name'] . '" สำเร็จ!';
            $messageType = 'success';
        }
    }
    
    if ($action === 'save_custom') {
        setPromoSetting($db, $lineAccountId, 'current_theme', 'custom');
        setPromoSetting($db, $lineAccountId, 'primary_color', $_POST['primary_color'] ?? '#11B0A6');
        setPromoSetting($db, $lineAccountId, 'sale_badge_color', $_POST['sale_badge_color'] ?? '#EF4444');
        setPromoSetting($db, $lineAccountId, 'bestseller_badge_color', $_POST['bestseller_badge_color'] ?? '#F59E0B');
        setPromoSetting($db, $lineAccountId, 'featured_badge_color', $_POST['featured_badge_color'] ?? '#8B5CF6');
        setPromoSetting($db, $lineAccountId, 'card_style', $_POST['card_style'] ?? 'rounded');
        setPromoSetting($db, $lineAccountId, 'card_shadow', $_POST['card_shadow'] ?? 'sm');
        setPromoSetting($db, $lineAccountId, 'image_size', $_POST['image_size'] ?? 'medium');
        setPromoSetting($db, $lineAccountId, 'columns_mobile', (int)($_POST['columns_mobile'] ?? 2));
        setPromoSetting($db, $lineAccountId, 'columns_desktop', (int)($_POST['columns_desktop'] ?? 4));
        setPromoSetting($db, $lineAccountId, 'image_ratio', $_POST['image_ratio'] ?? '1:1');
        setPromoSetting($db, $lineAccountId, 'products_per_section', (int)($_POST['products_per_section'] ?? 8));
        
        // Section toggles
        setPromoSetting($db, $lineAccountId, 'show_sale_section', isset($_POST['show_sale_section']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_bestseller_section', isset($_POST['show_bestseller_section']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_featured_section', isset($_POST['show_featured_section']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_sku', isset($_POST['show_sku']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_stock', isset($_POST['show_stock']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_description', isset($_POST['show_description']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_usage', isset($_POST['show_usage']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_manufacturer', isset($_POST['show_manufacturer']) ? '1' : '0');
        
        $message = 'บันทึกการตั้งค่าสำเร็จ!';
        $messageType = 'success';
    }
}

// Get current settings
$currentTheme = getPromoSetting($db, $lineAccountId, 'current_theme', 'pharmacy');
$settings = [
    'primary_color' => getPromoSetting($db, $lineAccountId, 'primary_color', '#11B0A6'),
    'secondary_color' => getPromoSetting($db, $lineAccountId, 'secondary_color', '#E0F7F5'),
    'sale_badge_color' => getPromoSetting($db, $lineAccountId, 'sale_badge_color', '#EF4444'),
    'bestseller_badge_color' => getPromoSetting($db, $lineAccountId, 'bestseller_badge_color', '#F59E0B'),
    'featured_badge_color' => getPromoSetting($db, $lineAccountId, 'featured_badge_color', '#8B5CF6'),
    'card_style' => getPromoSetting($db, $lineAccountId, 'card_style', 'rounded-lg'),
    'card_shadow' => getPromoSetting($db, $lineAccountId, 'card_shadow', 'sm'),
    'image_size' => getPromoSetting($db, $lineAccountId, 'image_size', 'medium'),
    'image_ratio' => getPromoSetting($db, $lineAccountId, 'image_ratio', '1:1'),
    'columns_mobile' => getPromoSetting($db, $lineAccountId, 'columns_mobile', 2),
    'columns_desktop' => getPromoSetting($db, $lineAccountId, 'columns_desktop', 4),
    'products_per_section' => getPromoSetting($db, $lineAccountId, 'products_per_section', 8),
    'show_sale_section' => getPromoSetting($db, $lineAccountId, 'show_sale_section', '1'),
    'show_bestseller_section' => getPromoSetting($db, $lineAccountId, 'show_bestseller_section', '1'),
    'show_featured_section' => getPromoSetting($db, $lineAccountId, 'show_featured_section', '1'),
    'show_sku' => getPromoSetting($db, $lineAccountId, 'show_sku', '0'),
    'show_stock' => getPromoSetting($db, $lineAccountId, 'show_stock', '0'),
    'show_description' => getPromoSetting($db, $lineAccountId, 'show_description', '1'),
    'show_usage' => getPromoSetting($db, $lineAccountId, 'show_usage', '1'),
    'show_manufacturer' => getPromoSetting($db, $lineAccountId, 'show_manufacturer', '0'),
    'layout_style' => getPromoSetting($db, $lineAccountId, 'layout_style', 'classic'),
    'show_flash_sale' => getPromoSetting($db, $lineAccountId, 'show_flash_sale', '0'),
    'show_quick_menu' => getPromoSetting($db, $lineAccountId, 'show_quick_menu', '0'),
    'show_sold_count' => getPromoSetting($db, $lineAccountId, 'show_sold_count', '0'),
    'show_rating' => getPromoSetting($db, $lineAccountId, 'show_rating', '0'),
    'show_bottom_nav' => getPromoSetting($db, $lineAccountId, 'show_bottom_nav', '1'),
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.theme-card { transition: all 0.3s; cursor: pointer; position: relative; }
.theme-card:hover { transform: translateY(-4px); }
.theme-card.selected { ring: 3px; ring-color: #11B0A6; }
.theme-card.selected::after { content: '✓'; position: absolute; top: -8px; right: -8px; width: 28px; height: 28px; background: #10B981; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
.preview-phone { width: 280px; height: 500px; background: #f8fafc; border-radius: 32px; border: 8px solid #1f2937; position: relative; overflow: hidden; }
.preview-phone::before { content: ''; position: absolute; top: 8px; left: 50%; transform: translateX(-50%); width: 80px; height: 24px; background: #1f2937; border-radius: 12px; z-index: 10; }
.preview-content { height: 100%; overflow-y: auto; padding-top: 40px; }
.preview-card { transition: all 0.2s; }
.color-dot { width: 24px; height: 24px; border-radius: 50%; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
</style>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-xl <?= $messageType === 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200' ?> flex items-center gap-3">
    <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> text-xl"></i>
    <span class="font-medium"><?= $message ?></span>
</div>
<?php endif; ?>

<!-- Header with Preview Link -->
<div class="mb-6 p-5 bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-500 rounded-2xl text-white shadow-lg">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="font-bold text-xl flex items-center gap-2">
                <i class="fas fa-palette"></i>ตั้งค่าธีมหน้าร้าน
            </h2>
            <p class="text-white/80 text-sm mt-1">เลือกธีมสำเร็จรูปหรือปรับแต่งเอง</p>
        </div>
        <div class="flex gap-2">
            <a href="<?= BASE_URL ?>liff/#/shop" target="_blank" 
               class="px-5 py-2.5 bg-white text-purple-600 rounded-xl font-bold hover:bg-purple-50 transition flex items-center gap-2 shadow">
                <i class="fas fa-external-link-alt"></i>ดูหน้าร้าน (LIFF ใหม่)
            </a>
            <a href="<?= BASE_URL ?>liff-shop.php?account=<?= $lineAccountId ?>" target="_blank" 
               class="px-4 py-2.5 bg-white/20 text-white rounded-xl hover:bg-white/30 transition flex items-center gap-2 text-sm">
                <i class="fas fa-store"></i>LIFF เดิม
            </a>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Left: Theme Selection & Settings -->
    <div class="lg:col-span-2 space-y-6">
        
        <!-- Theme Selector -->
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <h3 class="font-bold text-gray-800 text-lg mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-gradient-to-br from-purple-500 to-pink-500 rounded-lg flex items-center justify-center text-white text-sm">
                    <i class="fas fa-swatchbook"></i>
                </span>
                เลือกธีมสำเร็จรูป
            </h3>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php foreach ($themes as $key => $theme): ?>
                <form method="POST" class="contents">
                    <input type="hidden" name="action" value="apply_theme">
                    <input type="hidden" name="theme" value="<?= $key ?>">
                    <button type="submit" class="theme-card bg-white border-2 rounded-2xl p-4 text-left hover:shadow-lg <?= $currentTheme === $key ? 'border-green-500 selected' : 'border-gray-200' ?>">
                        <!-- Theme Preview Mini -->
                        <div class="aspect-square rounded-xl mb-3 p-2 relative overflow-hidden" style="background: linear-gradient(135deg, <?= $theme['primary_color'] ?>20, <?= $theme['primary_color'] ?>40)">
                            <div class="absolute inset-2 bg-white rounded-lg shadow-sm flex flex-col">
                                <div class="h-1/2 bg-gray-100 rounded-t-lg"></div>
                                <div class="p-1.5">
                                    <div class="h-1.5 bg-gray-200 rounded w-3/4 mb-1"></div>
                                    <div class="h-2 rounded w-1/2" style="background: <?= $theme['primary_color'] ?>"></div>
                                </div>
                            </div>
                            <!-- Color dots -->
                            <div class="absolute bottom-1 right-1 flex gap-0.5">
                                <div class="w-3 h-3 rounded-full" style="background: <?= $theme['primary_color'] ?>"></div>
                                <div class="w-3 h-3 rounded-full" style="background: <?= $theme['sale_badge_color'] ?>"></div>
                            </div>
                        </div>
                        <h4 class="font-bold text-gray-800 text-sm"><?= $theme['name'] ?></h4>
                        <p class="text-xs text-gray-500 mt-1 line-clamp-2"><?= $theme['description'] ?></p>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Custom Settings -->
        <form method="POST" id="customForm">
            <input type="hidden" name="action" value="save_custom">
            
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                        <span class="w-8 h-8 bg-gradient-to-br from-blue-500 to-cyan-500 rounded-lg flex items-center justify-center text-white text-sm">
                            <i class="fas fa-sliders-h"></i>
                        </span>
                        ปรับแต่งเพิ่มเติม
                    </h3>
                    <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full">
                        <?= $currentTheme === 'custom' ? '🎨 กำหนดเอง' : '📦 ธีม: ' . ($themes[$currentTheme]['name'] ?? 'ไม่ระบุ') ?>
                    </span>
                </div>
                
                <!-- Color Settings -->
                <div class="mb-6">
                    <h4 class="font-medium text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-palette text-pink-500"></i>สี
                    </h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="group">
                            <label class="block text-xs text-gray-500 mb-1.5">สีหลัก</label>
                            <div class="flex items-center gap-2 p-2 border rounded-xl group-hover:border-gray-400 transition">
                                <input type="color" name="primary_color" value="<?= $settings['primary_color'] ?>" 
                                       class="w-10 h-10 rounded-lg cursor-pointer border-0" onchange="updatePreview()">
                                <input type="text" value="<?= $settings['primary_color'] ?>" 
                                       class="flex-1 text-sm text-gray-600 bg-transparent outline-none font-mono" readonly>
                            </div>
                        </div>
                        <div class="group">
                            <label class="block text-xs text-gray-500 mb-1.5">Badge ลดราคา</label>
                            <div class="flex items-center gap-2 p-2 border rounded-xl group-hover:border-gray-400 transition">
                                <input type="color" name="sale_badge_color" value="<?= $settings['sale_badge_color'] ?>" 
                                       class="w-10 h-10 rounded-lg cursor-pointer border-0" onchange="updatePreview()">
                                <input type="text" value="<?= $settings['sale_badge_color'] ?>" 
                                       class="flex-1 text-sm text-gray-600 bg-transparent outline-none font-mono" readonly>
                            </div>
                        </div>
                        <div class="group">
                            <label class="block text-xs text-gray-500 mb-1.5">Badge ขายดี</label>
                            <div class="flex items-center gap-2 p-2 border rounded-xl group-hover:border-gray-400 transition">
                                <input type="color" name="bestseller_badge_color" value="<?= $settings['bestseller_badge_color'] ?>" 
                                       class="w-10 h-10 rounded-lg cursor-pointer border-0" onchange="updatePreview()">
                                <input type="text" value="<?= $settings['bestseller_badge_color'] ?>" 
                                       class="flex-1 text-sm text-gray-600 bg-transparent outline-none font-mono" readonly>
                            </div>
                        </div>
                        <div class="group">
                            <label class="block text-xs text-gray-500 mb-1.5">Badge แนะนำ</label>
                            <div class="flex items-center gap-2 p-2 border rounded-xl group-hover:border-gray-400 transition">
                                <input type="color" name="featured_badge_color" value="<?= $settings['featured_badge_color'] ?>" 
                                       class="w-10 h-10 rounded-lg cursor-pointer border-0" onchange="updatePreview()">
                                <input type="text" value="<?= $settings['featured_badge_color'] ?>" 
                                       class="flex-1 text-sm text-gray-600 bg-transparent outline-none font-mono" readonly>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Card Style -->
                <div class="mb-6">
                    <h4 class="font-medium text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-square text-blue-500"></i>รูปแบบการ์ด
                    </h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <?php 
                        $cardStyles = [
                            'square' => ['name' => 'เหลี่ยม', 'radius' => '0'],
                            'rounded' => ['name' => 'มน', 'radius' => '8px'],
                            'rounded-lg' => ['name' => 'มนมาก', 'radius' => '16px'],
                            'rounded-xl' => ['name' => 'มนสุด', 'radius' => '24px'],
                        ];
                        foreach ($cardStyles as $key => $style): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="card_style" value="<?= $key ?>" class="sr-only peer" 
                                   <?= $settings['card_style'] === $key ? 'checked' : '' ?> onchange="updatePreview()">
                            <div class="p-3 border-2 rounded-xl text-center peer-checked:border-blue-500 peer-checked:bg-blue-50 hover:bg-gray-50 transition">
                                <div class="w-12 h-12 bg-gray-200 mx-auto mb-2" style="border-radius: <?= $style['radius'] ?>"></div>
                                <span class="text-sm font-medium text-gray-700"><?= $style['name'] ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Shadow Style -->
                <div class="mb-6">
                    <h4 class="font-medium text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-clone text-purple-500"></i>เงา
                    </h4>
                    <div class="grid grid-cols-4 gap-3">
                        <?php 
                        $shadowStyles = [
                            'none' => 'ไม่มี',
                            'sm' => 'เล็ก',
                            'md' => 'กลาง',
                            'lg' => 'ใหญ่',
                        ];
                        foreach ($shadowStyles as $key => $name): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="card_shadow" value="<?= $key ?>" class="sr-only peer" 
                                   <?= $settings['card_shadow'] === $key ? 'checked' : '' ?> onchange="updatePreview()">
                            <div class="p-3 border-2 rounded-xl text-center peer-checked:border-purple-500 peer-checked:bg-purple-50 hover:bg-gray-50 transition">
                                <span class="text-sm font-medium text-gray-700"><?= $name ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Layout Settings -->
                <div class="mb-6">
                    <h4 class="font-medium text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-th text-green-500"></i>เลย์เอาต์
                    </h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1.5">ขนาดรูป</label>
                            <select name="image_size" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="updatePreview()">
                                <option value="small" <?= $settings['image_size'] === 'small' ? 'selected' : '' ?>>เล็ก</option>
                                <option value="medium" <?= $settings['image_size'] === 'medium' ? 'selected' : '' ?>>กลาง</option>
                                <option value="large" <?= $settings['image_size'] === 'large' ? 'selected' : '' ?>>ใหญ่</option>
                                <option value="xlarge" <?= $settings['image_size'] === 'xlarge' ? 'selected' : '' ?>>ใหญ่มาก</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1.5">สัดส่วนรูป</label>
                            <select name="image_ratio" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="1:1" <?= $settings['image_ratio'] === '1:1' ? 'selected' : '' ?>>1:1</option>
                                <option value="4:3" <?= $settings['image_ratio'] === '4:3' ? 'selected' : '' ?>>4:3</option>
                                <option value="3:4" <?= $settings['image_ratio'] === '3:4' ? 'selected' : '' ?>>3:4</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1.5">คอลัมน์ (มือถือ)</label>
                            <select name="columns_mobile" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" onchange="updatePreview()">
                                <option value="1" <?= $settings['columns_mobile'] == 1 ? 'selected' : '' ?>>1</option>
                                <option value="2" <?= $settings['columns_mobile'] == 2 ? 'selected' : '' ?>>2</option>
                                <option value="3" <?= $settings['columns_mobile'] == 3 ? 'selected' : '' ?>>3</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1.5">คอลัมน์ (Desktop)</label>
                            <select name="columns_desktop" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="3" <?= $settings['columns_desktop'] == 3 ? 'selected' : '' ?>>3</option>
                                <option value="4" <?= $settings['columns_desktop'] == 4 ? 'selected' : '' ?>>4</option>
                                <option value="5" <?= $settings['columns_desktop'] == 5 ? 'selected' : '' ?>>5</option>
                                <option value="6" <?= $settings['columns_desktop'] == 6 ? 'selected' : '' ?>>6</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Section Toggles -->
                <div class="mb-6">
                    <h4 class="font-medium text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-layer-group text-orange-500"></i>Section ที่แสดง
                    </h4>
                    <div class="flex flex-wrap gap-3">
                        <label class="flex items-center gap-2 px-4 py-2.5 border-2 rounded-xl cursor-pointer hover:bg-gray-50 transition has-[:checked]:border-red-500 has-[:checked]:bg-red-50">
                            <input type="checkbox" name="show_sale_section" <?= $settings['show_sale_section'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-red-500 rounded">
                            <span class="text-sm font-medium">🏷️ ลดราคา</span>
                        </label>
                        <label class="flex items-center gap-2 px-4 py-2.5 border-2 rounded-xl cursor-pointer hover:bg-gray-50 transition has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                            <input type="checkbox" name="show_bestseller_section" <?= $settings['show_bestseller_section'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-orange-500 rounded">
                            <span class="text-sm font-medium">🔥 ขายดี</span>
                        </label>
                        <label class="flex items-center gap-2 px-4 py-2.5 border-2 rounded-xl cursor-pointer hover:bg-gray-50 transition has-[:checked]:border-yellow-500 has-[:checked]:bg-yellow-50">
                            <input type="checkbox" name="show_featured_section" <?= $settings['show_featured_section'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-yellow-500 rounded">
                            <span class="text-sm font-medium">⭐ แนะนำ</span>
                        </label>
                    </div>
                </div>
                
                <!-- Extra Options -->
                <div class="mb-6">
                    <h4 class="font-medium text-gray-700 mb-3 flex items-center gap-2">
                        <i class="fas fa-cog text-gray-500"></i>ตัวเลือกเพิ่มเติม
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Product Info Options -->
                        <div class="p-4 bg-blue-50 rounded-xl">
                            <h5 class="font-medium text-blue-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-info-circle"></i>ข้อมูลสินค้า
                            </h5>
                            <div class="space-y-2">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="show_sku" <?= $settings['show_sku'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-blue-500 rounded">
                                    <span class="text-sm text-gray-700">แสดงรหัสสินค้า (SKU)</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="show_stock" <?= $settings['show_stock'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-blue-500 rounded">
                                    <span class="text-sm text-gray-700">แสดงจำนวนคงเหลือ</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="show_manufacturer" <?= $settings['show_manufacturer'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-blue-500 rounded">
                                    <span class="text-sm text-gray-700">แสดงผู้ผลิต/ยี่ห้อ</span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Pharmacy Info Options -->
                        <div class="p-4 bg-green-50 rounded-xl">
                            <h5 class="font-medium text-green-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-pills"></i>ข้อมูลยา/สรรพคุณ
                            </h5>
                            <div class="space-y-2">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="show_description" <?= $settings['show_description'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-green-500 rounded">
                                    <span class="text-sm text-gray-700">แสดงสรรพคุณ/รายละเอียด</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="show_usage" <?= $settings['show_usage'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-green-500 rounded">
                                    <span class="text-sm text-gray-700">แสดงวิธีใช้</span>
                                </label>
                            </div>
                            <p class="text-xs text-green-600 mt-2">
                                <i class="fas fa-lightbulb mr-1"></i>ข้อมูลจะแสดงในหน้ารายละเอียดสินค้า
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Save Button -->
                <div class="flex justify-end pt-4 border-t">
                    <button type="submit" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl font-bold hover:from-blue-700 hover:to-purple-700 transition shadow-lg flex items-center gap-2">
                        <i class="fas fa-save"></i>บันทึกการตั้งค่า
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Right: Live Preview -->
    <div class="lg:col-span-1">
        <div class="sticky top-4">
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="font-bold text-gray-800 text-lg mb-4 flex items-center gap-2">
                    <span class="w-8 h-8 bg-gradient-to-br from-green-500 to-teal-500 rounded-lg flex items-center justify-center text-white text-sm">
                        <i class="fas fa-mobile-alt"></i>
                    </span>
                    ตัวอย่าง
                </h3>
                
                <!-- Phone Preview -->
                <div class="flex justify-center">
                    <div class="preview-phone shadow-2xl">
                        <div class="preview-content" id="previewContent">
                            <!-- Header -->
                            <div class="px-3 py-2 bg-white border-b sticky top-0 z-10">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs" id="previewLogo" style="background: <?= $settings['primary_color'] ?>">
                                        <i class="fas fa-store"></i>
                                    </div>
                                    <div>
                                        <div class="font-bold text-gray-800 text-sm">ร้านค้าตัวอย่าง</div>
                                        <div class="text-[10px] text-gray-400">ออนไลน์</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Banner -->
                            <div class="p-3">
                                <div class="rounded-xl p-3 text-white text-sm" id="previewBanner" style="background: linear-gradient(135deg, <?= $settings['primary_color'] ?>, <?= $settings['primary_color'] ?>dd)">
                                    <div class="font-bold">🎉 ยินดีต้อนรับ!</div>
                                    <div class="text-xs opacity-80">ส่งฟรีเมื่อซื้อครบ ฿500</div>
                                </div>
                            </div>
                            
                            <!-- Section Title -->
                            <div class="px-3 mb-2">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm">🔥</span>
                                    <span class="font-bold text-gray-800 text-sm">สินค้าขายดี</span>
                                </div>
                            </div>
                            
                            <!-- Product Grid -->
                            <div class="px-3 pb-4">
                                <div class="grid gap-2" id="previewGrid" style="grid-template-columns: repeat(<?= $settings['columns_mobile'] ?>, 1fr)">
                                    <!-- Product Cards -->
                                    <?php for ($i = 0; $i < 4; $i++): ?>
                                    <div class="preview-card bg-white overflow-hidden" id="previewCard<?= $i ?>" 
                                         style="border-radius: <?= $settings['card_style'] === 'square' ? '0' : ($settings['card_style'] === 'rounded' ? '8px' : ($settings['card_style'] === 'rounded-lg' ? '12px' : '16px')) ?>; 
                                                box-shadow: <?= $settings['card_shadow'] === 'none' ? 'none' : ($settings['card_shadow'] === 'sm' ? '0 1px 3px rgba(0,0,0,0.1)' : ($settings['card_shadow'] === 'md' ? '0 4px 6px rgba(0,0,0,0.1)' : '0 10px 15px rgba(0,0,0,0.1)')) ?>">
                                        <div class="relative">
                                            <?php if ($i === 0): ?>
                                            <span class="absolute top-1 left-1 px-1.5 py-0.5 text-white text-[8px] font-bold rounded" id="previewSaleBadge" style="background: <?= $settings['sale_badge_color'] ?>">-20%</span>
                                            <?php endif; ?>
                                            <?php if ($i === 1): ?>
                                            <span class="absolute top-1 left-1 px-1.5 py-0.5 text-white text-[8px] font-bold rounded" id="previewBestBadge" style="background: <?= $settings['bestseller_badge_color'] ?>">🔥</span>
                                            <?php endif; ?>
                                            <div class="aspect-square bg-gray-100 flex items-center justify-center">
                                                <i class="fas fa-image text-gray-300"></i>
                                            </div>
                                        </div>
                                        <div class="p-1.5">
                                            <div class="text-[10px] text-gray-800 font-medium truncate">สินค้าตัวอย่าง</div>
                                            <div class="text-[10px] font-bold mt-0.5" id="previewPrice<?= $i ?>" style="color: <?= $settings['primary_color'] ?>">฿199</div>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Info -->
                <div class="mt-4 p-3 bg-gray-50 rounded-xl">
                    <div class="text-xs text-gray-500 space-y-1">
                        <div class="flex justify-between">
                            <span>ธีมปัจจุบัน:</span>
                            <span class="font-medium text-gray-700"><?= $currentTheme === 'custom' ? 'กำหนดเอง' : ($themes[$currentTheme]['name'] ?? '-') ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>คอลัมน์:</span>
                            <span class="font-medium text-gray-700"><?= $settings['columns_mobile'] ?> (มือถือ) / <?= $settings['columns_desktop'] ?> (Desktop)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Sync color inputs
document.querySelectorAll('input[type="color"]').forEach(colorInput => {
    colorInput.addEventListener('input', function() {
        const textInput = this.parentElement.querySelector('input[type="text"]');
        if (textInput) textInput.value = this.value;
        updatePreview();
    });
});

// Update preview in real-time
function updatePreview() {
    const primaryColor = document.querySelector('input[name="primary_color"]').value;
    const saleColor = document.querySelector('input[name="sale_badge_color"]').value;
    const bestColor = document.querySelector('input[name="bestseller_badge_color"]').value;
    const cardStyle = document.querySelector('input[name="card_style"]:checked')?.value || 'rounded';
    const cardShadow = document.querySelector('input[name="card_shadow"]:checked')?.value || 'sm';
    const columns = document.querySelector('select[name="columns_mobile"]').value;
    
    // Update colors
    document.getElementById('previewLogo').style.background = primaryColor;
    document.getElementById('previewBanner').style.background = `linear-gradient(135deg, ${primaryColor}, ${primaryColor}dd)`;
    document.getElementById('previewSaleBadge').style.background = saleColor;
    document.getElementById('previewBestBadge').style.background = bestColor;
    
    // Update prices
    for (let i = 0; i < 4; i++) {
        const priceEl = document.getElementById('previewPrice' + i);
        if (priceEl) priceEl.style.color = primaryColor;
    }
    
    // Update card styles
    const radiusMap = { 'square': '0', 'rounded': '8px', 'rounded-lg': '12px', 'rounded-xl': '16px' };
    const shadowMap = { 'none': 'none', 'sm': '0 1px 3px rgba(0,0,0,0.1)', 'md': '0 4px 6px rgba(0,0,0,0.1)', 'lg': '0 10px 15px rgba(0,0,0,0.1)' };
    
    for (let i = 0; i < 4; i++) {
        const card = document.getElementById('previewCard' + i);
        if (card) {
            card.style.borderRadius = radiusMap[cardStyle];
            card.style.boxShadow = shadowMap[cardShadow];
        }
    }
    
    // Update grid columns
    document.getElementById('previewGrid').style.gridTemplateColumns = `repeat(${columns}, 1fr)`;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>