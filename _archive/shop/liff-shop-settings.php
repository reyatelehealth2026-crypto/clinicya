    <?php
/**
 * LIFF Shop Settings - ตั้งค่าการแสดงผลหน้า LIFF Shop
 * - เลือกหมวดหมู่ที่จะแสดง
 * - ลำดับการแสดงหมวดหมู่
 * - จำนวนสินค้าต่อหมวด
 * - เปิด/ปิด section ต่างๆ
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['line_account_id'] ?? $_SESSION['current_bot_id'] ?? null;
$pageTitle = 'ตั้งค่าหน้า LIFF Shop';

// Create settings table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS liff_shop_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_setting (line_account_id, setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'save_settings':
                $settings = $_POST['settings'] ?? [];
                
                foreach ($settings as $key => $value) {
                    $stmt = $db->prepare("INSERT INTO liff_shop_settings (line_account_id, setting_key, setting_value) 
                                          VALUES (?, ?, ?) 
                                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->execute([$lineAccountId, $key, is_array($value) ? json_encode($value) : $value]);
                }
                
                echo json_encode(['success' => true, 'message' => 'บันทึกการตั้งค่าเรียบร้อย']);
                exit;
                
            case 'toggle_category':
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $enabled = (int)($_POST['enabled'] ?? 0);
                
                // Get current hidden categories
                $stmt = $db->prepare("SELECT setting_value FROM liff_shop_settings WHERE line_account_id = ? AND setting_key = 'hidden_categories'");
                $stmt->execute([$lineAccountId]);
                $hidden = json_decode($stmt->fetchColumn() ?: '[]', true);
                
                if ($enabled) {
                    $hidden = array_diff($hidden, [$categoryId]);
                } else {
                    $hidden[] = $categoryId;
                    $hidden = array_unique($hidden);
                }
                
                $stmt = $db->prepare("INSERT INTO liff_shop_settings (line_account_id, setting_key, setting_value) 
                                      VALUES (?, 'hidden_categories', ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$lineAccountId, json_encode(array_values($hidden))]);
                
                echo json_encode(['success' => true, 'hidden' => $hidden]);
                exit;
                
            case 'update_order':
                $order = $_POST['order'] ?? [];
                $stmt = $db->prepare("INSERT INTO liff_shop_settings (line_account_id, setting_key, setting_value) 
                                      VALUES (?, 'category_order', ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$lineAccountId, json_encode($order)]);
                echo json_encode(['success' => true]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Get current settings
function getSetting($db, $lineAccountId, $key, $default = null) {
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

$hiddenCategories = getSetting($db, $lineAccountId, 'hidden_categories', []);
$categoryOrder = getSetting($db, $lineAccountId, 'category_order', []);
$showBestsellers = getSetting($db, $lineAccountId, 'show_bestsellers', '1');
$showFeatured = getSetting($db, $lineAccountId, 'show_featured', '1');
$productsPerCategory = getSetting($db, $lineAccountId, 'products_per_category', '6');
$banners = getSetting($db, $lineAccountId, 'banners', []);
if (!is_array($banners)) $banners = [];

// Get categories
$categories = [];
$catTable = 'item_categories';
try {
    try { $db->query("SELECT 1 FROM item_categories LIMIT 1"); } 
    catch (Exception $e) { $catTable = 'business_categories'; }
    $stmt = $db->query("SELECT * FROM $catTable WHERE is_active = 1 ORDER BY id");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Sort categories by custom order
if (!empty($categoryOrder)) {
    usort($categories, function($a, $b) use ($categoryOrder) {
        $posA = array_search($a['id'], $categoryOrder);
        $posB = array_search($b['id'], $categoryOrder);
        if ($posA === false) $posA = 999;
        if ($posB === false) $posB = 999;
        return $posA - $posB;
    });
}

// Count products per category
$productCounts = [];
try {
    $stmt = $db->query("SELECT category_id, COUNT(*) as cnt FROM business_items WHERE is_active = 1 GROUP BY category_id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $productCounts[$row['category_id']] = $row['cnt'];
    }
} catch (Exception $e) {}

// Count bestsellers per category
$bestsellerCounts = [];
try {
    $stmt = $db->query("SELECT category_id, COUNT(*) as cnt FROM business_items WHERE is_active = 1 AND COALESCE(is_bestseller, 0) = 1 GROUP BY category_id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bestsellerCounts[$row['category_id']] = $row['cnt'];
    }
} catch (Exception $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.category-item { transition: all 0.2s; }
.category-item:hover { background: #f8fafc; }
.category-item.disabled { opacity: 0.5; }
.drag-handle { cursor: grab; }
.drag-handle:active { cursor: grabbing; }
.sortable-ghost { opacity: 0.4; background: #e0f2fe; }
</style>

<!-- Shop URL Info -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-store mr-2 text-teal-500"></i>หน้าร้าน LIFF Shop</h2>
    </div>
    <div class="p-4">
        <div class="p-3 bg-blue-50 rounded-lg">
            <p class="text-sm text-blue-700">
                <i class="fas fa-link mr-1"></i>
                <strong>URL หน้าร้าน:</strong>
                <span id="shopUrl" class="font-mono"><?= BASE_URL ?>liff-shop.php?account=<?= $lineAccountId ?></span>
                <button onclick="copyUrl()" class="ml-2 text-blue-600 hover:text-blue-800"><i class="fas fa-copy"></i></button>
            </p>
        </div>
        <div class="mt-3 flex items-center gap-4">
            <a href="<?= BASE_URL ?>liff-shop.php?account=<?= $lineAccountId ?>" target="_blank" 
               class="inline-flex items-center gap-2 px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition">
                <i class="fas fa-external-link-alt"></i>
                <span>ดูตัวอย่างหน้าร้าน</span>
            </a>
        </div>
    </div>
</div>

<!-- Display Settings -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-sliders-h mr-2 text-purple-500"></i>ตั้งค่าการแสดงผล</h2>
    </div>
    <div class="p-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" id="showBestsellers" <?= $showBestsellers ? 'checked' : '' ?> class="w-5 h-5 text-red-600 rounded">
                    <span class="ml-3">
                        <span class="font-medium text-gray-800">🔥 แสดง Best Sellers</span>
                        <span class="block text-sm text-gray-500">Section สินค้าขายดี</span>
                    </span>
                </label>
            </div>
            <div>
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" id="showFeatured" <?= $showFeatured ? 'checked' : '' ?> class="w-5 h-5 text-yellow-600 rounded">
                    <span class="ml-3">
                        <span class="font-medium text-gray-800">⭐ แสดงสินค้าแนะนำ</span>
                        <span class="block text-sm text-gray-500">Section สินค้าเด่น</span>
                    </span>
                </label>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">จำนวนสินค้าต่อหมวด</label>
                <select id="productsPerCategory" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    <option value="4" <?= $productsPerCategory == '4' ? 'selected' : '' ?>>4 รายการ</option>
                    <option value="6" <?= $productsPerCategory == '6' ? 'selected' : '' ?>>6 รายการ</option>
                    <option value="8" <?= $productsPerCategory == '8' ? 'selected' : '' ?>>8 รายการ</option>
                    <option value="10" <?= $productsPerCategory == '10' ? 'selected' : '' ?>>10 รายการ</option>
                    <option value="12" <?= $productsPerCategory == '12' ? 'selected' : '' ?>>12 รายการ</option>
                </select>
            </div>
        </div>
        
        <div class="mt-4 flex justify-end">
            <button onclick="saveDisplaySettings()" class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700">
                <i class="fas fa-save mr-1"></i>บันทึกการตั้งค่า
            </button>
        </div>
    </div>
</div>

<!-- Banner Management -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="p-4 border-b flex items-center justify-between">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-images mr-2 text-pink-500"></i>จัดการแบนเนอร์โปรโมชั่น</h2>
        <button onclick="addBanner()" class="px-3 py-1.5 bg-pink-100 text-pink-700 rounded-lg text-sm hover:bg-pink-200">
            <i class="fas fa-plus mr-1"></i>เพิ่มแบนเนอร์
        </button>
    </div>
    <div class="p-4">
        <p class="text-sm text-gray-500 mb-4">
            <i class="fas fa-info-circle mr-1"></i>
            แบนเนอร์จะแสดงเป็น Carousel ที่ด้านบนของหน้าร้าน (เฉพาะ V2) | ขนาดแนะนำ: 800x300 px
        </p>
        
        <div id="bannerList" class="space-y-3">
            <?php if (empty($banners)): ?>
            <div id="noBannerMsg" class="text-center py-8 text-gray-400">
                <i class="fas fa-image text-4xl mb-2"></i>
                <p>ยังไม่มีแบนเนอร์</p>
            </div>
            <?php else: ?>
            <?php foreach ($banners as $i => $banner): ?>
            <div class="banner-item flex items-center gap-4 p-3 border rounded-lg" data-index="<?= $i ?>">
                <div class="w-32 h-20 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                    <img src="<?= htmlspecialchars($banner['image']) ?>" class="w-full h-full object-cover" onerror="this.src='https://via.placeholder.com/320x120?text=No+Image'">
                </div>
                <div class="flex-1 min-w-0">
                    <input type="text" class="banner-title w-full px-3 py-1.5 border rounded mb-1 text-sm" placeholder="ชื่อแบนเนอร์" value="<?= htmlspecialchars($banner['title'] ?? '') ?>">
                    <input type="url" class="banner-image w-full px-3 py-1.5 border rounded mb-1 text-sm" placeholder="URL รูปภาพ" value="<?= htmlspecialchars($banner['image']) ?>">
                    <input type="url" class="banner-link w-full px-3 py-1.5 border rounded text-sm" placeholder="URL ลิงก์ (ถ้ามี)" value="<?= htmlspecialchars($banner['link'] ?? '') ?>">
                </div>
                <button onclick="removeBanner(this)" class="text-red-500 hover:text-red-700 p-2">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="mt-4 flex justify-end">
            <button onclick="saveBanners()" class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700">
                <i class="fas fa-save mr-1"></i>บันทึกแบนเนอร์
            </button>
        </div>
    </div>
</div>

<!-- Category Management -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex items-center justify-between">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-tags mr-2 text-blue-500"></i>จัดการหมวดหมู่ที่แสดง</h2>
        <div class="flex gap-2">
            <button onclick="enableAll()" class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-sm hover:bg-green-200">
                <i class="fas fa-check-double mr-1"></i>เปิดทั้งหมด
            </button>
            <button onclick="disableAll()" class="px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-sm hover:bg-red-200">
                <i class="fas fa-times mr-1"></i>ปิดทั้งหมด
            </button>
        </div>
    </div>
    
    <div class="p-4">
        <p class="text-sm text-gray-500 mb-4">
            <i class="fas fa-info-circle mr-1"></i>
            ลากเพื่อเรียงลำดับหมวดหมู่ | คลิกสวิตช์เพื่อเปิด/ปิดการแสดงผล
        </p>
        
        <div id="categoryList" class="space-y-2">
            <?php foreach ($categories as $cat): 
                $isHidden = in_array($cat['id'], $hiddenCategories);
                $productCount = $productCounts[$cat['id']] ?? 0;
                $bestsellerCount = $bestsellerCounts[$cat['id']] ?? 0;
                $catName = $cat['name'];
                $code = '';
                if (strpos($catName, '-') !== false) {
                    $parts = explode('-', $catName, 2);
                    $code = $parts[0];
                    $catName = $parts[1] ?? $catName;
                }
            ?>
            <div class="category-item flex items-center gap-4 p-3 border rounded-lg <?= $isHidden ? 'disabled' : '' ?>" data-id="<?= $cat['id'] ?>">
                <div class="drag-handle text-gray-400 hover:text-gray-600">
                    <i class="fas fa-grip-vertical text-lg"></i>
                </div>
                
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <?php if ($code): ?>
                        <span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-xs font-bold rounded"><?= $code ?></span>
                        <?php endif; ?>
                        <span class="font-medium text-gray-800"><?= htmlspecialchars($catName) ?></span>
                    </div>
                    <div class="flex items-center gap-3 mt-1 text-sm text-gray-500">
                        <span><i class="fas fa-box mr-1"></i><?= number_format($productCount) ?> สินค้า</span>
                        <?php if ($bestsellerCount > 0): ?>
                        <span class="text-red-500"><i class="fas fa-fire mr-1"></i><?= $bestsellerCount ?> Best Seller</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="flex items-center gap-3">
                    <a href="promotions.php?category=<?= $cat['id'] ?>" class="text-sm text-blue-600 hover:text-blue-800">
                        <i class="fas fa-edit"></i> จัดการ
                    </a>
                    
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer category-toggle" data-id="<?= $cat['id'] ?>" <?= !$isHidden ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-teal-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-600"></div>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// Initialize Sortable
const categoryList = document.getElementById('categoryList');
new Sortable(categoryList, {
    handle: '.drag-handle',
    animation: 150,
    ghostClass: 'sortable-ghost',
    onEnd: function() {
        const order = Array.from(categoryList.querySelectorAll('.category-item')).map(el => parseInt(el.dataset.id));
        saveOrder(order);
    }
});

// Toggle category
document.querySelectorAll('.category-toggle').forEach(toggle => {
    toggle.addEventListener('change', async function() {
        const categoryId = this.dataset.id;
        const enabled = this.checked ? 1 : 0;
        const item = this.closest('.category-item');
        
        item.classList.toggle('disabled', !enabled);
        
        const formData = new FormData();
        formData.append('action', 'toggle_category');
        formData.append('category_id', categoryId);
        formData.append('enabled', enabled);
        
        await fetch('liff-shop-settings.php', { method: 'POST', body: formData });
    });
});

// Save order
async function saveOrder(order) {
    const formData = new FormData();
    formData.append('action', 'update_order');
    order.forEach(id => formData.append('order[]', id));
    await fetch('liff-shop-settings.php', { method: 'POST', body: formData });
}

// Save display settings
async function saveDisplaySettings() {
    const formData = new FormData();
    formData.append('action', 'save_settings');
    formData.append('settings[show_bestsellers]', document.getElementById('showBestsellers').checked ? '1' : '0');
    formData.append('settings[show_featured]', document.getElementById('showFeatured').checked ? '1' : '0');
    formData.append('settings[products_per_category]', document.getElementById('productsPerCategory').value);
    
    const res = await fetch('liff-shop-settings.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    if (data.success) {
        alert('บันทึกการตั้งค่าเรียบร้อย');
    }
}

// Copy URL
function copyUrl() {
    const url = document.getElementById('shopUrl').textContent;
    navigator.clipboard.writeText(url).then(() => alert('คัดลอก URL แล้ว'));
}

// Enable/Disable all
async function enableAll() {
    document.querySelectorAll('.category-toggle').forEach(toggle => {
        toggle.checked = true;
        toggle.closest('.category-item').classList.remove('disabled');
    });
    
    const formData = new FormData();
    formData.append('action', 'save_settings');
    formData.append('settings[hidden_categories]', '[]');
    await fetch('liff-shop-settings.php', { method: 'POST', body: formData });
}

async function disableAll() {
    const ids = [];
    document.querySelectorAll('.category-toggle').forEach(toggle => {
        toggle.checked = false;
        toggle.closest('.category-item').classList.add('disabled');
        ids.push(parseInt(toggle.dataset.id));
    });
    
    const formData = new FormData();
    formData.append('action', 'save_settings');
    formData.append('settings[hidden_categories]', JSON.stringify(ids));
    await fetch('liff-shop-settings.php', { method: 'POST', body: formData });
}

// Banner Management
function addBanner() {
    const noBannerMsg = document.getElementById('noBannerMsg');
    if (noBannerMsg) noBannerMsg.remove();
    
    const list = document.getElementById('bannerList');
    const index = list.querySelectorAll('.banner-item').length;
    
    const html = `
        <div class="banner-item flex items-center gap-4 p-3 border rounded-lg" data-index="${index}">
            <div class="w-32 h-20 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0 flex items-center justify-center">
                <i class="fas fa-image text-2xl text-gray-300"></i>
            </div>
            <div class="flex-1 min-w-0">
                <input type="text" class="banner-title w-full px-3 py-1.5 border rounded mb-1 text-sm" placeholder="ชื่อแบนเนอร์">
                <input type="url" class="banner-image w-full px-3 py-1.5 border rounded mb-1 text-sm" placeholder="URL รูปภาพ" onchange="updateBannerPreview(this)">
                <input type="url" class="banner-link w-full px-3 py-1.5 border rounded text-sm" placeholder="URL ลิงก์ (ถ้ามี)">
            </div>
            <button onclick="removeBanner(this)" class="text-red-500 hover:text-red-700 p-2">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    list.insertAdjacentHTML('beforeend', html);
}

function removeBanner(btn) {
    btn.closest('.banner-item').remove();
}

function updateBannerPreview(input) {
    const item = input.closest('.banner-item');
    const preview = item.querySelector('img') || item.querySelector('.w-32');
    if (input.value) {
        preview.outerHTML = `<img src="${input.value}" class="w-full h-full object-cover" onerror="this.src='https://via.placeholder.com/320x120?text=Error'">`;
    }
}

async function saveBanners() {
    const banners = [];
    document.querySelectorAll('.banner-item').forEach(item => {
        const image = item.querySelector('.banner-image').value.trim();
        if (image) {
            banners.push({
                title: item.querySelector('.banner-title').value.trim(),
                image: image,
                link: item.querySelector('.banner-link').value.trim()
            });
        }
    });
    
    const formData = new FormData();
    formData.append('action', 'save_settings');
    formData.append('settings[banners]', JSON.stringify(banners));
    
    const res = await fetch('liff-shop-settings.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    if (data.success) {
        alert('บันทึกแบนเนอร์เรียบร้อย');
    } else {
        alert('เกิดข้อผิดพลาด: ' + (data.error || 'Unknown'));
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
