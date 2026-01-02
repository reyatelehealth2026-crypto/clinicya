<?php
/**
 * Shop Settings - Promotions Tab Content
 * ตั้งค่าธีมหน้าร้าน LIFF
 */

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
        'description' => 'สไตล์ Lazada/Shopee มี Flash Sale, Quick Menu',
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
    ],
    'pharmacy' => [
        'name' => '💊 ร้านยา',
        'description' => 'โทนสีเขียวมิ้นท์ สะอาดตา',
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
        'description' => 'โทนสีน้ำเงินเข้ม ดูหรูหรา',
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
        'description' => 'โทนขาว-ดำ เรียบง่าย',
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
        'description' => 'โทนสีชมพู-ส้ม อบอุ่น',
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

// Get current settings
$currentTheme = getPromoSetting($db, $lineAccountId, 'current_theme', 'pharmacy');
$promoSettings = [
    'primary_color' => getPromoSetting($db, $lineAccountId, 'primary_color', '#11B0A6'),
    'secondary_color' => getPromoSetting($db, $lineAccountId, 'secondary_color', '#E0F7F5'),
    'sale_badge_color' => getPromoSetting($db, $lineAccountId, 'sale_badge_color', '#EF4444'),
    'bestseller_badge_color' => getPromoSetting($db, $lineAccountId, 'bestseller_badge_color', '#F59E0B'),
    'featured_badge_color' => getPromoSetting($db, $lineAccountId, 'featured_badge_color', '#8B5CF6'),
    'card_style' => getPromoSetting($db, $lineAccountId, 'card_style', 'rounded-lg'),
    'card_shadow' => getPromoSetting($db, $lineAccountId, 'card_shadow', 'sm'),
    'image_size' => getPromoSetting($db, $lineAccountId, 'image_size', 'medium'),
    'columns_mobile' => getPromoSetting($db, $lineAccountId, 'columns_mobile', 2),
    'columns_desktop' => getPromoSetting($db, $lineAccountId, 'columns_desktop', 4),
    'show_sale_section' => getPromoSetting($db, $lineAccountId, 'show_sale_section', '1'),
    'show_bestseller_section' => getPromoSetting($db, $lineAccountId, 'show_bestseller_section', '1'),
    'show_featured_section' => getPromoSetting($db, $lineAccountId, 'show_featured_section', '1'),
];
?>

<style>
.theme-card { transition: all 0.3s; cursor: pointer; position: relative; }
.theme-card:hover { transform: translateY(-4px); }
.theme-card.selected { ring: 3px; ring-color: #11B0A6; }
.theme-card.selected::after { content: '✓'; position: absolute; top: -8px; right: -8px; width: 28px; height: 28px; background: #10B981; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
</style>

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
                <i class="fas fa-external-link-alt"></i>ดูหน้าร้าน
            </a>
        </div>
    </div>
</div>

<!-- Theme Selector -->
<div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
    <h3 class="font-bold text-gray-800 text-lg mb-4 flex items-center gap-2">
        <span class="w-8 h-8 bg-gradient-to-br from-purple-500 to-pink-500 rounded-lg flex items-center justify-center text-white text-sm">
            <i class="fas fa-swatchbook"></i>
        </span>
        เลือกธีมสำเร็จรูป
    </h3>
    
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <?php foreach ($themes as $key => $theme): ?>
        <form method="POST" class="contents">
            <input type="hidden" name="tab" value="promotions">
            <input type="hidden" name="promo_action" value="apply_theme">
            <input type="hidden" name="theme" value="<?= $key ?>">
            <button type="submit" class="theme-card bg-white border-2 rounded-2xl p-4 text-left hover:shadow-lg <?= $currentTheme === $key ? 'border-green-500 selected' : 'border-gray-200' ?>">
                <div class="aspect-square rounded-xl mb-3 p-2 relative overflow-hidden" style="background: linear-gradient(135deg, <?= $theme['primary_color'] ?>20, <?= $theme['primary_color'] ?>40)">
                    <div class="absolute inset-2 bg-white rounded-lg shadow-sm flex flex-col">
                        <div class="h-1/2 bg-gray-100 rounded-t-lg"></div>
                        <div class="p-1.5">
                            <div class="h-1.5 bg-gray-200 rounded w-3/4 mb-1"></div>
                            <div class="h-2 rounded w-1/2" style="background: <?= $theme['primary_color'] ?>"></div>
                        </div>
                    </div>
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
<form method="POST">
    <input type="hidden" name="tab" value="promotions">
    <input type="hidden" name="promo_action" value="save_custom">
    
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
                        <input type="color" name="primary_color" value="<?= $promoSettings['primary_color'] ?>" class="w-10 h-10 rounded-lg cursor-pointer border-0">
                        <input type="text" value="<?= $promoSettings['primary_color'] ?>" class="flex-1 text-sm text-gray-600 bg-transparent outline-none font-mono" readonly>
                    </div>
                </div>
                <div class="group">
                    <label class="block text-xs text-gray-500 mb-1.5">Badge ลดราคา</label>
                    <div class="flex items-center gap-2 p-2 border rounded-xl group-hover:border-gray-400 transition">
                        <input type="color" name="sale_badge_color" value="<?= $promoSettings['sale_badge_color'] ?>" class="w-10 h-10 rounded-lg cursor-pointer border-0">
                        <input type="text" value="<?= $promoSettings['sale_badge_color'] ?>" class="flex-1 text-sm text-gray-600 bg-transparent outline-none font-mono" readonly>
                    </div>
                </div>
                <div class="group">
                    <label class="block text-xs text-gray-500 mb-1.5">Badge ขายดี</label>
                    <div class="flex items-center gap-2 p-2 border rounded-xl group-hover:border-gray-400 transition">
                        <input type="color" name="bestseller_badge_color" value="<?= $promoSettings['bestseller_badge_color'] ?>" class="w-10 h-10 rounded-lg cursor-pointer border-0">
                        <input type="text" value="<?= $promoSettings['bestseller_badge_color'] ?>" class="flex-1 text-sm text-gray-600 bg-transparent outline-none font-mono" readonly>
                    </div>
                </div>
                <div class="group">
                    <label class="block text-xs text-gray-500 mb-1.5">Badge แนะนำ</label>
                    <div class="flex items-center gap-2 p-2 border rounded-xl group-hover:border-gray-400 transition">
                        <input type="color" name="featured_badge_color" value="<?= $promoSettings['featured_badge_color'] ?>" class="w-10 h-10 rounded-lg cursor-pointer border-0">
                        <input type="text" value="<?= $promoSettings['featured_badge_color'] ?>" class="flex-1 text-sm text-gray-600 bg-transparent outline-none font-mono" readonly>
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
                    <input type="radio" name="card_style" value="<?= $key ?>" class="sr-only peer" <?= $promoSettings['card_style'] === $key ? 'checked' : '' ?>>
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
                $shadowStyles = ['none' => 'ไม่มี', 'sm' => 'เล็ก', 'md' => 'กลาง', 'lg' => 'ใหญ่'];
                foreach ($shadowStyles as $key => $name): ?>
                <label class="cursor-pointer">
                    <input type="radio" name="card_shadow" value="<?= $key ?>" class="sr-only peer" <?= $promoSettings['card_shadow'] === $key ? 'checked' : '' ?>>
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
                    <select name="image_size" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="small" <?= $promoSettings['image_size'] === 'small' ? 'selected' : '' ?>>เล็ก</option>
                        <option value="medium" <?= $promoSettings['image_size'] === 'medium' ? 'selected' : '' ?>>กลาง</option>
                        <option value="large" <?= $promoSettings['image_size'] === 'large' ? 'selected' : '' ?>>ใหญ่</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1.5">คอลัมน์ (มือถือ)</label>
                    <select name="columns_mobile" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="1" <?= $promoSettings['columns_mobile'] == 1 ? 'selected' : '' ?>>1</option>
                        <option value="2" <?= $promoSettings['columns_mobile'] == 2 ? 'selected' : '' ?>>2</option>
                        <option value="3" <?= $promoSettings['columns_mobile'] == 3 ? 'selected' : '' ?>>3</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1.5">คอลัมน์ (Desktop)</label>
                    <select name="columns_desktop" class="w-full px-3 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="3" <?= $promoSettings['columns_desktop'] == 3 ? 'selected' : '' ?>>3</option>
                        <option value="4" <?= $promoSettings['columns_desktop'] == 4 ? 'selected' : '' ?>>4</option>
                        <option value="5" <?= $promoSettings['columns_desktop'] == 5 ? 'selected' : '' ?>>5</option>
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
                    <input type="checkbox" name="show_sale_section" <?= $promoSettings['show_sale_section'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-red-500 rounded">
                    <span class="text-sm font-medium">🏷️ ลดราคา</span>
                </label>
                <label class="flex items-center gap-2 px-4 py-2.5 border-2 rounded-xl cursor-pointer hover:bg-gray-50 transition has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                    <input type="checkbox" name="show_bestseller_section" <?= $promoSettings['show_bestseller_section'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-orange-500 rounded">
                    <span class="text-sm font-medium">🔥 ขายดี</span>
                </label>
                <label class="flex items-center gap-2 px-4 py-2.5 border-2 rounded-xl cursor-pointer hover:bg-gray-50 transition has-[:checked]:border-yellow-500 has-[:checked]:bg-yellow-50">
                    <input type="checkbox" name="show_featured_section" <?= $promoSettings['show_featured_section'] == '1' ? 'checked' : '' ?> class="w-4 h-4 text-yellow-500 rounded">
                    <span class="text-sm font-medium">⭐ แนะนำ</span>
                </label>
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

<script>
// Sync color inputs
document.querySelectorAll('input[type="color"]').forEach(colorInput => {
    colorInput.addEventListener('input', function() {
        const textInput = this.parentElement.querySelector('input[type="text"]');
        if (textInput) textInput.value = this.value;
    });
});
</script>
