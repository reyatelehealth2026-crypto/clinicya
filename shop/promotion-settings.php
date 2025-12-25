<?php
/**
 * Promotion Settings - ตั้งค่าโปรโมชั่น LIFF
 * - ขนาดรูปภาพ
 * - จำนวนสินค้าที่แสดง
 * - สี/ธีม
 * - Layout
 * - Banner settings
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่าโปรโมชั่น';
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

$message = '';
$messageType = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    
    if ($action === 'save') {
        // Display Settings
        setPromoSetting($db, $lineAccountId, 'image_size', $_POST['image_size'] ?? 'medium');
        setPromoSetting($db, $lineAccountId, 'image_ratio', $_POST['image_ratio'] ?? '1:1');
        setPromoSetting($db, $lineAccountId, 'products_per_section', (int)($_POST['products_per_section'] ?? 6));
        setPromoSetting($db, $lineAccountId, 'columns_mobile', (int)($_POST['columns_mobile'] ?? 2));
        setPromoSetting($db, $lineAccountId, 'columns_desktop', (int)($_POST['columns_desktop'] ?? 4));
        
        // Section Settings
        setPromoSetting($db, $lineAccountId, 'show_sale_section', isset($_POST['show_sale_section']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_bestseller_section', isset($_POST['show_bestseller_section']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_featured_section', isset($_POST['show_featured_section']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_points_badge', isset($_POST['show_points_badge']) ? '1' : '0');
        
        // Theme Settings
        setPromoSetting($db, $lineAccountId, 'primary_color', $_POST['primary_color'] ?? '#11B0A6');
        setPromoSetting($db, $lineAccountId, 'sale_badge_color', $_POST['sale_badge_color'] ?? '#EF4444');
        setPromoSetting($db, $lineAccountId, 'bestseller_badge_color', $_POST['bestseller_badge_color'] ?? '#F59E0B');
        setPromoSetting($db, $lineAccountId, 'featured_badge_color', $_POST['featured_badge_color'] ?? '#8B5CF6');
        
        // Card Style
        setPromoSetting($db, $lineAccountId, 'card_style', $_POST['card_style'] ?? 'rounded');
        setPromoSetting($db, $lineAccountId, 'card_shadow', $_POST['card_shadow'] ?? 'sm');
        setPromoSetting($db, $lineAccountId, 'show_sku', isset($_POST['show_sku']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'show_stock', isset($_POST['show_stock']) ? '1' : '0');
        
        // Banner Settings
        setPromoSetting($db, $lineAccountId, 'banner_height_mobile', (int)($_POST['banner_height_mobile'] ?? 160));
        setPromoSetting($db, $lineAccountId, 'banner_height_desktop', (int)($_POST['banner_height_desktop'] ?? 200));
        setPromoSetting($db, $lineAccountId, 'banner_autoplay', isset($_POST['banner_autoplay']) ? '1' : '0');
        setPromoSetting($db, $lineAccountId, 'banner_interval', (int)($_POST['banner_interval'] ?? 5000));
        
        $message = 'บันทึกการตั้งค่าสำเร็จ!';
        $messageType = 'success';
    }
    
    if ($action === 'add_banner') {
        $banners = getPromoSetting($db, $lineAccountId, 'banners', []);
        $banners[] = [
            'image' => $_POST['banner_image'] ?? '',
            'title' => $_POST['banner_title'] ?? '',
            'link' => $_POST['banner_link'] ?? '',
            'active' => true
        ];
        setPromoSetting($db, $lineAccountId, 'banners', $banners);
        $message = 'เพิ่ม Banner สำเร็จ!';
        $messageType = 'success';
    }
    
    if ($action === 'delete_banner') {
        $index = (int)$_POST['banner_index'];
        $banners = getPromoSetting($db, $lineAccountId, 'banners', []);
        if (isset($banners[$index])) {
            array_splice($banners, $index, 1);
            setPromoSetting($db, $lineAccountId, 'banners', $banners);
        }
        $message = 'ลบ Banner สำเร็จ!';
        $messageType = 'success';
    }
}

// Get current settings
$settings = [
    'image_size' => getPromoSetting($db, $lineAccountId, 'image_size', 'medium'),
    'image_ratio' => getPromoSetting($db, $lineAccountId, 'image_ratio', '1:1'),
    'products_per_section' => getPromoSetting($db, $lineAccountId, 'products_per_section', 6),
    'columns_mobile' => getPromoSetting($db, $lineAccountId, 'columns_mobile', 2),
    'columns_desktop' => getPromoSetting($db, $lineAccountId, 'columns_desktop', 4),
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
    'banner_height_mobile' => getPromoSetting($db, $lineAccountId, 'banner_height_mobile', 160),
    'banner_height_desktop' => getPromoSetting($db, $lineAccountId, 'banner_height_desktop', 200),
    'banner_autoplay' => getPromoSetting($db, $lineAccountId, 'banner_autoplay', '1'),
    'banner_interval' => getPromoSetting($db, $lineAccountId, 'banner_interval', 5000),
];
$banners = getPromoSetting($db, $lineAccountId, 'banners', []);

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
<div class="mb-4 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
    <i class="fas fa-check-circle mr-2"></i><?= $message ?>
</div>
<?php endif; ?>

<!-- Preview Link -->
<div class="mb-6 p-4 bg-gradient-to-r from-teal-500 to-teal-600 rounded-xl text-white">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="font-bold text-lg">ดูตัวอย่างหน้าโปรโมชั่น</h2>
            <p class="text-teal-100 text-sm">ทดสอบการแสดงผลใน LIFF</p>
        </div>
        <a href="<?= BASE_URL ?>/liff-promotions.php?account=<?= $lineAccountId ?>" target="_blank" 
           class="px-4 py-2 bg-white text-teal-600 rounded-lg font-bold hover:bg-teal-50">
            <i class="fas fa-external-link-alt mr-1"></i>เปิดดู
        </a>
    </div>
</div>

<form method="POST" class="space-y-6">
    <input type="hidden" name="action" value="save">
    
    <!-- Display Settings -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-image text-blue-500 mr-2"></i>การแสดงผลรูปภาพ
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ขนาดรูปภาพ</label>
                <select name="image_size" class="w-full px-4 py-2 border rounded-lg">
                    <option value="small" <?= $settings['image_size'] === 'small' ? 'selected' : '' ?>>เล็ก (120px)</option>
                    <option value="medium" <?= $settings['image_size'] === 'medium' ? 'selected' : '' ?>>กลาง (160px)</option>
                    <option value="large" <?= $settings['image_size'] === 'large' ? 'selected' : '' ?>>ใหญ่ (200px)</option>
                    <option value="xlarge" <?= $settings['image_size'] === 'xlarge' ? 'selected' : '' ?>>ใหญ่มาก (240px)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">สัดส่วนรูป</label>
                <select name="image_ratio" class="w-full px-4 py-2 border rounded-lg">
                    <option value="1:1" <?= $settings['image_ratio'] === '1:1' ? 'selected' : '' ?>>1:1 (สี่เหลี่ยมจัตุรัส)</option>
                    <option value="4:3" <?= $settings['image_ratio'] === '4:3' ? 'selected' : '' ?>>4:3 (แนวนอน)</option>
                    <option value="3:4" <?= $settings['image_ratio'] === '3:4' ? 'selected' : '' ?>>3:4 (แนวตั้ง)</option>
                    <option value="16:9" <?= $settings['image_ratio'] === '16:9' ? 'selected' : '' ?>>16:9 (Widescreen)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">จำนวนสินค้าต่อ Section</label>
                <select name="products_per_section" class="w-full px-4 py-2 border rounded-lg">
                    <?php for ($i = 4; $i <= 20; $i += 2): ?>
                    <option value="<?= $i ?>" <?= $settings['products_per_section'] == $i ? 'selected' : '' ?>><?= $i ?> รายการ</option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">จำนวนคอลัมน์ (มือถือ)</label>
                <select name="columns_mobile" class="w-full px-4 py-2 border rounded-lg">
                    <option value="1" <?= $settings['columns_mobile'] == 1 ? 'selected' : '' ?>>1 คอลัมน์</option>
                    <option value="2" <?= $settings['columns_mobile'] == 2 ? 'selected' : '' ?>>2 คอลัมน์</option>
                    <option value="3" <?= $settings['columns_mobile'] == 3 ? 'selected' : '' ?>>3 คอลัมน์</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">จำนวนคอลัมน์ (Desktop)</label>
                <select name="columns_desktop" class="w-full px-4 py-2 border rounded-lg">
                    <option value="3" <?= $settings['columns_desktop'] == 3 ? 'selected' : '' ?>>3 คอลัมน์</option>
                    <option value="4" <?= $settings['columns_desktop'] == 4 ? 'selected' : '' ?>>4 คอลัมน์</option>
                    <option value="5" <?= $settings['columns_desktop'] == 5 ? 'selected' : '' ?>>5 คอลัมน์</option>
                    <option value="6" <?= $settings['columns_desktop'] == 6 ? 'selected' : '' ?>>6 คอลัมน์</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Section Settings -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-layer-group text-purple-500 mr-2"></i>Section ที่แสดง
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <label class="flex items-center gap-2 p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                <input type="checkbox" name="show_sale_section" <?= $settings['show_sale_section'] == '1' ? 'checked' : '' ?> class="w-5 h-5 text-red-500">
                <span>🏷️ สินค้าลดราคา</span>
            </label>
            <label class="flex items-center gap-2 p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                <input type="checkbox" name="show_bestseller_section" <?= $settings['show_bestseller_section'] == '1' ? 'checked' : '' ?> class="w-5 h-5 text-orange-500">
                <span>🔥 สินค้าขายดี</span>
            </label>
            <label class="flex items-center gap-2 p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                <input type="checkbox" name="show_featured_section" <?= $settings['show_featured_section'] == '1' ? 'checked' : '' ?> class="w-5 h-5 text-yellow-500">
                <span>⭐ สินค้าแนะนำ</span>
            </label>
            <label class="flex items-center gap-2 p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                <input type="checkbox" name="show_points_badge" <?= $settings['show_points_badge'] == '1' ? 'checked' : '' ?> class="w-5 h-5 text-purple-500">
                <span>🎁 แสดงแต้มสะสม</span>
            </label>
        </div>
    </div>

    <!-- Theme Settings -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-palette text-pink-500 mr-2"></i>สีและธีม
        </h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">สีหลัก</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="primary_color" value="<?= $settings['primary_color'] ?>" class="w-12 h-10 rounded cursor-pointer">
                    <input type="text" value="<?= $settings['primary_color'] ?>" class="flex-1 px-3 py-2 border rounded-lg text-sm" readonly>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">สี Badge ลดราคา</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="sale_badge_color" value="<?= $settings['sale_badge_color'] ?>" class="w-12 h-10 rounded cursor-pointer">
                    <input type="text" value="<?= $settings['sale_badge_color'] ?>" class="flex-1 px-3 py-2 border rounded-lg text-sm" readonly>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">สี Badge ขายดี</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="bestseller_badge_color" value="<?= $settings['bestseller_badge_color'] ?>" class="w-12 h-10 rounded cursor-pointer">
                    <input type="text" value="<?= $settings['bestseller_badge_color'] ?>" class="flex-1 px-3 py-2 border rounded-lg text-sm" readonly>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">สี Badge แนะนำ</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="featured_badge_color" value="<?= $settings['featured_badge_color'] ?>" class="w-12 h-10 rounded cursor-pointer">
                    <input type="text" value="<?= $settings['featured_badge_color'] ?>" class="flex-1 px-3 py-2 border rounded-lg text-sm" readonly>
                </div>
            </div>
        </div>
    </div>

    <!-- Card Style -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-square text-green-500 mr-2"></i>รูปแบบการ์ดสินค้า
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">รูปแบบขอบ</label>
                <select name="card_style" class="w-full px-4 py-2 border rounded-lg">
                    <option value="square" <?= $settings['card_style'] === 'square' ? 'selected' : '' ?>>เหลี่ยม</option>
                    <option value="rounded" <?= $settings['card_style'] === 'rounded' ? 'selected' : '' ?>>มน (8px)</option>
                    <option value="rounded-lg" <?= $settings['card_style'] === 'rounded-lg' ? 'selected' : '' ?>>มนมาก (16px)</option>
                    <option value="rounded-xl" <?= $settings['card_style'] === 'rounded-xl' ? 'selected' : '' ?>>มนมากที่สุด (24px)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">เงา</label>
                <select name="card_shadow" class="w-full px-4 py-2 border rounded-lg">
                    <option value="none" <?= $settings['card_shadow'] === 'none' ? 'selected' : '' ?>>ไม่มีเงา</option>
                    <option value="sm" <?= $settings['card_shadow'] === 'sm' ? 'selected' : '' ?>>เงาเล็ก</option>
                    <option value="md" <?= $settings['card_shadow'] === 'md' ? 'selected' : '' ?>>เงากลาง</option>
                    <option value="lg" <?= $settings['card_shadow'] === 'lg' ? 'selected' : '' ?>>เงาใหญ่</option>
                </select>
            </div>
            <div class="flex flex-col gap-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="show_sku" <?= $settings['show_sku'] == '1' ? 'checked' : '' ?> class="w-4 h-4">
                    <span class="text-sm">แสดงรหัสสินค้า (SKU)</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="show_stock" <?= $settings['show_stock'] == '1' ? 'checked' : '' ?> class="w-4 h-4">
                    <span class="text-sm">แสดงจำนวนคงเหลือ</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Banner Settings -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-images text-indigo-500 mr-2"></i>ตั้งค่า Banner
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ความสูง (มือถือ)</label>
                <div class="flex items-center gap-2">
                    <input type="number" name="banner_height_mobile" value="<?= $settings['banner_height_mobile'] ?>" min="100" max="400" class="w-full px-4 py-2 border rounded-lg">
                    <span class="text-gray-500">px</span>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ความสูง (Desktop)</label>
                <div class="flex items-center gap-2">
                    <input type="number" name="banner_height_desktop" value="<?= $settings['banner_height_desktop'] ?>" min="100" max="500" class="w-full px-4 py-2 border rounded-lg">
                    <span class="text-gray-500">px</span>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ความเร็วเลื่อน</label>
                <select name="banner_interval" class="w-full px-4 py-2 border rounded-lg">
                    <option value="3000" <?= $settings['banner_interval'] == 3000 ? 'selected' : '' ?>>3 วินาที</option>
                    <option value="5000" <?= $settings['banner_interval'] == 5000 ? 'selected' : '' ?>>5 วินาที</option>
                    <option value="7000" <?= $settings['banner_interval'] == 7000 ? 'selected' : '' ?>>7 วินาที</option>
                    <option value="10000" <?= $settings['banner_interval'] == 10000 ? 'selected' : '' ?>>10 วินาที</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="flex items-center gap-2 cursor-pointer p-2">
                    <input type="checkbox" name="banner_autoplay" <?= $settings['banner_autoplay'] == '1' ? 'checked' : '' ?> class="w-5 h-5">
                    <span>เลื่อนอัตโนมัติ</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Save Button -->
    <div class="flex justify-end">
        <button type="submit" class="px-6 py-3 bg-teal-500 text-white rounded-lg font-bold hover:bg-teal-600">
            <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
        </button>
    </div>
</form>

<!-- Banner Management -->
<div class="bg-white rounded-xl shadow p-6 mt-6">
    <h3 class="font-bold text-gray-800 mb-4 flex items-center">
        <i class="fas fa-image text-orange-500 mr-2"></i>จัดการ Banner โปรโมชั่น
    </h3>
    
    <!-- Add Banner Form -->
    <form method="POST" class="mb-6 p-4 bg-gray-50 rounded-lg">
        <input type="hidden" name="action" value="add_banner">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">URL รูปภาพ *</label>
                <input type="url" name="banner_image" required placeholder="https://..." class="w-full px-4 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อ Banner</label>
                <input type="text" name="banner_title" placeholder="โปรโมชั่นพิเศษ" class="w-full px-4 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Link (ถ้ามี)</label>
                <input type="url" name="banner_link" placeholder="https://..." class="w-full px-4 py-2 border rounded-lg">
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600">
                <i class="fas fa-plus mr-1"></i>เพิ่ม Banner
            </button>
        </div>
    </form>
    
    <!-- Banner List -->
    <?php if (!empty($banners)): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($banners as $i => $banner): ?>
        <div class="border rounded-lg overflow-hidden">
            <div class="aspect-video bg-gray-100">
                <img src="<?= htmlspecialchars($banner['image']) ?>" class="w-full h-full object-cover" onerror="this.src='https://via.placeholder.com/400x200?text=No+Image'">
            </div>
            <div class="p-3">
                <p class="font-medium text-gray-800 truncate"><?= htmlspecialchars($banner['title'] ?: 'Banner ' . ($i + 1)) ?></p>
                <?php if (!empty($banner['link'])): ?>
                <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($banner['link']) ?></p>
                <?php endif; ?>
                <form method="POST" class="mt-2" onsubmit="return confirm('ยืนยันการลบ?')">
                    <input type="hidden" name="action" value="delete_banner">
                    <input type="hidden" name="banner_index" value="<?= $i ?>">
                    <button type="submit" class="text-red-500 text-sm hover:text-red-700">
                        <i class="fas fa-trash mr-1"></i>ลบ
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center py-8 text-gray-500">
        <i class="fas fa-images text-4xl mb-2 text-gray-300"></i>
        <p>ยังไม่มี Banner</p>
    </div>
    <?php endif; ?>
</div>

<!-- Preview Section -->
<div class="bg-white rounded-xl shadow p-6 mt-6">
    <h3 class="font-bold text-gray-800 mb-4 flex items-center">
        <i class="fas fa-eye text-cyan-500 mr-2"></i>ตัวอย่างการแสดงผล
    </h3>
    <div class="border rounded-lg p-4 bg-gray-50">
        <div class="max-w-sm mx-auto">
            <!-- Preview Card -->
            <div class="bg-white <?= $settings['card_style'] ?> shadow-<?= $settings['card_shadow'] ?> overflow-hidden" 
                 style="border-radius: <?= $settings['card_style'] === 'square' ? '0' : ($settings['card_style'] === 'rounded' ? '8px' : ($settings['card_style'] === 'rounded-lg' ? '16px' : '24px')) ?>">
                <div class="relative">
                    <span class="absolute top-2 left-2 px-2 py-1 text-white text-xs font-bold rounded" style="background: <?= $settings['sale_badge_color'] ?>">-20%</span>
                    <div class="aspect-square bg-gray-200 flex items-center justify-center">
                        <i class="fas fa-image text-4xl text-gray-400"></i>
                    </div>
                </div>
                <div class="p-3">
                    <h4 class="font-medium text-gray-800 text-sm">ตัวอย่างสินค้า</h4>
                    <?php if ($settings['show_sku'] == '1'): ?>
                    <p class="text-xs text-gray-400">SKU: ABC123</p>
                    <?php endif; ?>
                    <div class="mt-2 flex items-center gap-2">
                        <span class="font-bold" style="color: <?= $settings['primary_color'] ?>">฿199</span>
                        <span class="text-gray-400 text-sm line-through">฿249</span>
                    </div>
                    <?php if ($settings['show_stock'] == '1'): ?>
                    <p class="text-xs text-gray-500 mt-1">คงเหลือ 50 ชิ้น</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Sync color inputs
document.querySelectorAll('input[type="color"]').forEach(colorInput => {
    colorInput.addEventListener('input', function() {
        this.nextElementSibling.value = this.value;
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
