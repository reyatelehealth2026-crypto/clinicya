<?php
/**
 * Admin SEO Settings Tab
 * ตั้งค่า SEO สำหรับ Landing Page
 * 
 * Requirements: 10.1, 10.2
 */

// Get current values
$pageTitle = $landingSettings['page_title'] ?? '';
$appName = $landingSettings['app_name'] ?? '';
$faviconUrl = $landingSettings['favicon_url'] ?? '';
$metaKeywords = $landingSettings['meta_keywords'] ?? '';
$metaDescription = $landingSettings['meta_description'] ?? '';
$latitude = $landingSettings['latitude'] ?? '';
$longitude = $landingSettings['longitude'] ?? '';
$googleMapEmbed = $landingSettings['google_map_embed'] ?? '';
$operatingHours = $landingSettings['operating_hours'] ?? '';

// Parse operating hours JSON
$hours = [];
if (!empty($operatingHours)) {
    $hours = json_decode($operatingHours, true) ?: [];
}

$days = [
    'mon' => 'จันทร์',
    'tue' => 'อังคาร',
    'wed' => 'พุธ',
    'thu' => 'พฤหัสบดี',
    'fri' => 'ศุกร์',
    'sat' => 'เสาร์',
    'sun' => 'อาทิตย์'
];
?>

<div class="space-y-6">
    <!-- SEO Preview -->
    <div class="bg-gradient-to-r from-blue-500 to-indigo-600 rounded-xl p-6 text-white">
        <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
            <i class="fas fa-search"></i>
            ตัวอย่างการแสดงผลบน Google
        </h2>
        <div class="bg-white rounded-lg p-4 text-gray-800">
            <div class="text-blue-600 text-lg font-medium hover:underline cursor-pointer">
                <?= htmlspecialchars($pageTitle ?: $seoService->getShopName()) ?>
            </div>
            <div class="text-green-700 text-sm">
                <?= htmlspecialchars($seoService->getCanonicalUrl()) ?>
            </div>
            <div class="text-gray-600 text-sm mt-1">
                <?= htmlspecialchars($metaDescription ?: $seoService->getShopDescription()) ?>
            </div>
        </div>
    </div>

    <!-- SEO Settings Form -->
    <form method="POST" class="space-y-6">
        <input type="hidden" name="action" value="save_seo">
        
        <!-- Branding Section -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-palette text-purple-600"></i>
                </span>
                ตั้งค่าแบรนด์และไอคอน
            </h3>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-heading text-purple-600"></i>
                        Title (ชื่อหน้าเว็บที่แสดงบนแท็บเบราว์เซอร์)
                    </label>
                    <input type="text" name="page_title" 
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                        placeholder="ร้านยาออนไลน์ - ส่งยาถึงบ้าน"
                        value="<?= htmlspecialchars($pageTitle) ?>">
                    <p class="text-xs text-gray-500 mt-1">ชื่อที่จะแสดงบนแท็บเบราว์เซอร์และผลการค้นหา Google</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-mobile-alt text-purple-600"></i>
                        ชื่อแอพ (App Name)
                    </label>
                    <input type="text" name="app_name" 
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                        placeholder="ร้านยาออนไลน์"
                        value="<?= htmlspecialchars($appName) ?>">
                    <p class="text-xs text-gray-500 mt-1">ชื่อที่จะแสดงเมื่อบันทึกเป็น PWA บนหน้าจอโทรศัพท์</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-image text-purple-600"></i>
                        Favicon URL
                    </label>
                    <div class="flex gap-3">
                        <?php if (!empty($faviconUrl)): ?>
                        <div class="flex-shrink-0">
                            <img src="<?= htmlspecialchars($faviconUrl) ?>" alt="Favicon" class="w-12 h-12 rounded border border-gray-200">
                        </div>
                        <?php endif; ?>
                        <div class="flex-1">
                            <input type="text" name="favicon_url" 
                                class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                placeholder="https://example.com/favicon.ico หรือ /assets/images/favicon.png"
                                value="<?= htmlspecialchars($faviconUrl) ?>">
                            <p class="text-xs text-gray-500 mt-1">ไอคอนที่แสดงบนแท็บเบราว์เซอร์ (แนะนำขนาด 32x32 หรือ 64x64 px)</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Meta Tags Section -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-tags text-blue-600"></i>
                </span>
                Meta Tags
            </h3>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Meta Description
                        <span class="text-gray-400 font-normal">(คำอธิบายที่แสดงใน Google)</span>
                    </label>
                    <textarea name="meta_description" rows="3" 
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="ร้านยาออนไลน์ครบวงจร พร้อมบริการปรึกษาเภสัชกร ส่งยาถึงบ้าน..."
                        maxlength="160"><?= htmlspecialchars($metaDescription) ?></textarea>
                    <p class="text-xs text-gray-500 mt-1">แนะนำ 150-160 ตัวอักษร (<span id="descCount"><?= strlen($metaDescription) ?></span>/160)</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Meta Keywords
                        <span class="text-gray-400 font-normal">(คำค้นหาที่เกี่ยวข้อง คั่นด้วยเครื่องหมาย ,)</span>
                    </label>
                    <input type="text" name="meta_keywords" 
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="ร้านยาออนไลน์, เภสัชกร, ส่งยาถึงบ้าน, ปรึกษาเภสัชกร"
                        value="<?= htmlspecialchars($metaKeywords) ?>">
                </div>
            </div>
        </div>
        
        <!-- Location Section -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-map-marker-alt text-green-600"></i>
                </span>
                ตำแหน่งที่ตั้ง (สำหรับ Google Maps & Structured Data)
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Latitude (ละติจูด)</label>
                    <input type="text" name="latitude" 
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                        placeholder="13.7563"
                        value="<?= htmlspecialchars($latitude) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Longitude (ลองจิจูด)</label>
                    <input type="text" name="longitude" 
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                        placeholder="100.5018"
                        value="<?= htmlspecialchars($longitude) ?>">
                </div>
            </div>
            
            <div class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Google Map Embed URL
                    <span class="text-gray-400 font-normal">(ไม่บังคับ)</span>
                </label>
                <input type="text" name="google_map_embed" 
                    class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                    placeholder="https://www.google.com/maps/embed?pb=..."
                    value="<?= htmlspecialchars($googleMapEmbed) ?>">
                <p class="text-xs text-gray-500 mt-1">คัดลอก URL จาก Google Maps > Share > Embed a map</p>
            </div>
        </div>
        
        <!-- Operating Hours Section -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-clock text-orange-600"></i>
                </span>
                เวลาทำการ (สำหรับ Structured Data)
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($days as $key => $label): ?>
                <div class="flex items-center gap-3">
                    <label class="w-20 text-sm font-medium text-gray-700"><?= $label ?></label>
                    <input type="text" name="hours[<?= $key ?>]" 
                        class="flex-1 px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent text-sm"
                        placeholder="09:00-21:00 หรือ closed"
                        value="<?= htmlspecialchars($hours[$key] ?? '') ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-500 mt-3">รูปแบบ: 09:00-21:00 หรือพิมพ์ "closed" สำหรับวันหยุด</p>
            
            <input type="hidden" name="operating_hours" id="operatingHoursJson" value="<?= htmlspecialchars($operatingHours) ?>">
        </div>
        
        <!-- Submit Button -->
        <div class="flex justify-end">
            <button type="submit" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium flex items-center gap-2">
                <i class="fas fa-save"></i>
                บันทึกการตั้งค่า SEO
            </button>
        </div>
    </form>
</div>

<script>
// Character counter for description
document.querySelector('textarea[name="meta_description"]').addEventListener('input', function() {
    document.getElementById('descCount').textContent = this.value.length;
});

// Convert hours inputs to JSON before submit
document.querySelector('form').addEventListener('submit', function(e) {
    const hours = {};
    document.querySelectorAll('input[name^="hours["]').forEach(input => {
        const key = input.name.match(/hours\[(\w+)\]/)[1];
        if (input.value.trim()) {
            hours[key] = input.value.trim();
        }
    });
    document.getElementById('operatingHoursJson').value = JSON.stringify(hours);
});
</script>
