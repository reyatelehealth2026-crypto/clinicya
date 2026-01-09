<?php
/**
 * Admin Trust Badges Settings Tab
 * ตั้งค่า Trust Badges สำหรับ Landing Page
 * 
 * Requirements: 10.5
 */

// Get current values
$licenseNumber = $landingSettings['license_number'] ?? '';
$establishmentYear = $landingSettings['establishment_year'] ?? '';

// Get current badges preview
$badges = $trustBadgeService->getBadges();
$customerCount = $trustBadgeService->getCustomerCount();
$orderCount = $trustBadgeService->getOrderCount();
$avgRating = $trustBadgeService->getAverageRating();
$reviewCount = $trustBadgeService->getReviewCount();

// Get custom badges
$customBadgesJson = $landingSettings['custom_badges'] ?? '[]';
$customBadges = json_decode($customBadgesJson, true) ?: [];

// Available icons for custom badges
$availableIcons = TrustBadgeService::AVAILABLE_ICONS;
?>

<div class="space-y-6">
    <!-- Preview Section -->
    <div class="bg-gradient-to-r from-emerald-500 to-teal-600 rounded-xl p-6 text-white">
        <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
            <i class="fas fa-eye"></i>
            ตัวอย่าง Trust Badges บนหน้าเว็บ
        </h2>
        
        <?php if (empty($badges)): ?>
        <div class="bg-white/20 rounded-lg p-6 text-center">
            <i class="fas fa-shield-alt text-4xl mb-3 opacity-50"></i>
            <p>ยังไม่มี Trust Badges ที่จะแสดง</p>
            <p class="text-sm opacity-75 mt-1">กรอกข้อมูลด้านล่างเพื่อเริ่มแสดง Trust Badges</p>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-2 md:grid-cols-<?= min(count($badges), 5) ?> gap-4">
            <?php foreach ($badges as $badge): ?>
            <div class="bg-white/20 rounded-xl p-4 text-center backdrop-blur-sm">
                <div class="w-12 h-12 mx-auto mb-2 bg-white/30 rounded-full flex items-center justify-center">
                    <i class="fas fa-<?= htmlspecialchars($badge['icon']) ?> text-xl"></i>
                </div>
                <div class="text-2xl font-bold"><?= htmlspecialchars($badge['value']) ?></div>
                <div class="text-sm opacity-90"><?= htmlspecialchars($badge['label']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Settings Form -->
    <form method="POST" class="space-y-6">
        <input type="hidden" name="action" value="save_trust">
        
        <!-- License Section -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-certificate text-blue-600"></i>
                </span>
                ใบอนุญาตร้านยา
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        เลขที่ใบอนุญาต
                        <span class="text-gray-400 font-normal">(ถ้ามี)</span>
                    </label>
                    <input type="text" name="license_number" 
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="เช่น ข.1234/2567"
                        value="<?= htmlspecialchars($licenseNumber) ?>">
                    <p class="text-xs text-gray-500 mt-1">จะแสดงเป็น Badge "ใบอนุญาตร้านยา" บนหน้าเว็บ</p>
                </div>
                
                <div class="trust-badge-preview">
                    <div class="trust-badge-icon bg-blue-100 text-blue-600">
                        <i class="fas fa-shield-check"></i>
                    </div>
                    <div class="font-bold text-gray-800"><?= $licenseNumber ?: 'ข.XXXX/XXXX' ?></div>
                    <div class="text-sm text-gray-500">ใบอนุญาตร้านยา</div>
                    <?php if (empty($licenseNumber)): ?>
                    <div class="text-xs text-orange-500 mt-2"><i class="fas fa-info-circle"></i> ยังไม่ได้ตั้งค่า</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Establishment Year Section -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-calendar-alt text-purple-600"></i>
                </span>
                ปีที่ก่อตั้ง
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        ปี พ.ศ. หรือ ค.ศ. ที่ก่อตั้ง
                        <span class="text-gray-400 font-normal">(ถ้ามี)</span>
                    </label>
                    <input type="number" name="establishment_year" 
                        class="w-full px-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                        placeholder="เช่น 2020"
                        min="1900" max="<?= date('Y') ?>"
                        value="<?= htmlspecialchars($establishmentYear) ?>">
                    <p class="text-xs text-gray-500 mt-1">ระบบจะคำนวณจำนวนปีที่ดำเนินกิจการอัตโนมัติ</p>
                </div>
                
                <div class="trust-badge-preview">
                    <div class="trust-badge-icon bg-purple-100 text-purple-600">
                        <i class="fas fa-award"></i>
                    </div>
                    <?php 
                    $yearsInBusiness = $establishmentYear ? (date('Y') - (int)$establishmentYear) : 0;
                    ?>
                    <div class="font-bold text-gray-800"><?= $yearsInBusiness > 0 ? $yearsInBusiness . ' ปี' : 'X ปี' ?></div>
                    <div class="text-sm text-gray-500">ประสบการณ์</div>
                    <?php if (empty($establishmentYear)): ?>
                    <div class="text-xs text-orange-500 mt-2"><i class="fas fa-info-circle"></i> ยังไม่ได้ตั้งค่า</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Auto-Generated Badges Info -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-magic text-green-600"></i>
                </span>
                Badges ที่สร้างอัตโนมัติ
            </h3>
            <p class="text-gray-500 text-sm mb-4">Badges เหล่านี้จะแสดงอัตโนมัติเมื่อมีข้อมูลในระบบ</p>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Customer Count -->
                <div class="border rounded-xl p-4 <?= $customerCount > 0 ? 'border-green-200 bg-green-50' : 'border-gray-200' ?>">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-lg <?= $customerCount > 0 ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400' ?> flex items-center justify-center">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <div class="font-medium">ลูกค้าที่ไว้วางใจ</div>
                            <div class="text-2xl font-bold <?= $customerCount > 0 ? 'text-green-600' : 'text-gray-400' ?>">
                                <?= number_format($customerCount) ?>+
                            </div>
                        </div>
                    </div>
                    <div class="text-xs <?= $customerCount > 0 ? 'text-green-600' : 'text-gray-400' ?>">
                        <?= $customerCount > 0 ? '✓ จะแสดงบนหน้าเว็บ' : '✗ ยังไม่มีข้อมูล' ?>
                    </div>
                </div>
                
                <!-- Order Count -->
                <div class="border rounded-xl p-4 <?= $orderCount > 0 ? 'border-green-200 bg-green-50' : 'border-gray-200' ?>">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-lg <?= $orderCount > 0 ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400' ?> flex items-center justify-center">
                            <i class="fas fa-shopping-bag"></i>
                        </div>
                        <div>
                            <div class="font-medium">ออเดอร์สำเร็จ</div>
                            <div class="text-2xl font-bold <?= $orderCount > 0 ? 'text-green-600' : 'text-gray-400' ?>">
                                <?= number_format($orderCount) ?>+
                            </div>
                        </div>
                    </div>
                    <div class="text-xs <?= $orderCount > 0 ? 'text-green-600' : 'text-gray-400' ?>">
                        <?= $orderCount > 0 ? '✓ จะแสดงบนหน้าเว็บ' : '✗ ยังไม่มีข้อมูล' ?>
                    </div>
                </div>
                
                <!-- Rating -->
                <div class="border rounded-xl p-4 <?= $reviewCount > 0 ? 'border-green-200 bg-green-50' : 'border-gray-200' ?>">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-lg <?= $reviewCount > 0 ? 'bg-yellow-100 text-yellow-600' : 'bg-gray-100 text-gray-400' ?> flex items-center justify-center">
                            <i class="fas fa-star"></i>
                        </div>
                        <div>
                            <div class="font-medium">คะแนนรีวิว</div>
                            <div class="text-2xl font-bold <?= $reviewCount > 0 ? 'text-yellow-600' : 'text-gray-400' ?>">
                                <?= $reviewCount > 0 ? number_format($avgRating, 1) . '/5' : '-/5' ?>
                            </div>
                        </div>
                    </div>
                    <div class="text-xs <?= $reviewCount > 0 ? 'text-green-600' : 'text-gray-400' ?>">
                        <?= $reviewCount > 0 ? '✓ จาก ' . $reviewCount . ' รีวิว' : '✗ ยังไม่มีรีวิว' ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Submit Button -->
        <div class="flex justify-end">
            <button type="submit" class="px-6 py-3 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-medium flex items-center gap-2">
                <i class="fas fa-save"></i>
                บันทึกการตั้งค่า Trust Badges
            </button>
        </div>
    </form>
    
    <!-- Custom Badges Section (Requirements: 10.5) -->
    <form method="POST" class="space-y-6 mt-6" id="customBadgesForm">
        <input type="hidden" name="action" value="save_custom_badges">
        <input type="hidden" name="custom_badges_json" id="customBadgesJson" value="<?= htmlspecialchars(json_encode($customBadges)) ?>">
        
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <span class="w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-plus-circle text-indigo-600"></i>
                    </span>
                    Custom Badges
                </h3>
                <button type="button" onclick="addCustomBadge()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm flex items-center gap-2">
                    <i class="fas fa-plus"></i>
                    เพิ่ม Badge
                </button>
            </div>
            <p class="text-gray-500 text-sm mb-4">สร้าง Badge แบบกำหนดเองเพื่อแสดงข้อมูลพิเศษ เช่น "จัดส่งฟรี", "รับประกันคุณภาพ" เป็นต้น</p>
            
            <div id="customBadgesList" class="space-y-4">
                <?php if (empty($customBadges)): ?>
                <div id="noBadgesMessage" class="text-center py-8 text-gray-400">
                    <i class="fas fa-badge text-4xl mb-3"></i>
                    <p>ยังไม่มี Custom Badge</p>
                    <p class="text-sm">คลิก "เพิ่ม Badge" เพื่อสร้าง Badge แบบกำหนดเอง</p>
                </div>
                <?php else: ?>
                <?php foreach ($customBadges as $index => $badge): ?>
                <div class="custom-badge-item border rounded-xl p-4 bg-gray-50" data-index="<?= $index ?>">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-center">
                        <!-- Icon Selector -->
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-gray-500 mb-1">ไอคอน</label>
                            <select class="badge-icon w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" onchange="updateBadgePreview(this)">
                                <?php foreach ($availableIcons as $iconKey => $iconName): ?>
                                <option value="<?= $iconKey ?>" <?= ($badge['icon'] ?? 'star') === $iconKey ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($iconName) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Label -->
                        <div class="md:col-span-3">
                            <label class="block text-xs font-medium text-gray-500 mb-1">ข้อความ</label>
                            <input type="text" class="badge-label w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" 
                                placeholder="เช่น จัดส่งฟรี" value="<?= htmlspecialchars($badge['label'] ?? '') ?>" onchange="updateBadgeData()">
                        </div>
                        
                        <!-- Value -->
                        <div class="md:col-span-3">
                            <label class="block text-xs font-medium text-gray-500 mb-1">ค่าที่แสดง</label>
                            <input type="text" class="badge-value w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" 
                                placeholder="เช่น ทั่วประเทศ" value="<?= htmlspecialchars($badge['value'] ?? '') ?>" onchange="updateBadgeData()">
                        </div>
                        
                        <!-- Preview -->
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-gray-500 mb-1">ตัวอย่าง</label>
                            <div class="badge-preview bg-white border rounded-lg p-2 text-center">
                                <i class="fas fa-<?= htmlspecialchars($badge['icon'] ?? 'star') ?> text-indigo-600 text-lg"></i>
                                <div class="text-xs font-bold truncate"><?= htmlspecialchars($badge['value'] ?? '-') ?></div>
                                <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($badge['label'] ?? '-') ?></div>
                            </div>
                        </div>
                        
                        <!-- Active Toggle & Delete -->
                        <div class="md:col-span-2 flex items-center gap-2 justify-end">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="badge-active w-4 h-4 text-indigo-600 rounded" 
                                    <?= ($badge['is_active'] ?? true) ? 'checked' : '' ?> onchange="updateBadgeData()">
                                <span class="text-xs text-gray-600">เปิดใช้</span>
                            </label>
                            <button type="button" onclick="removeCustomBadge(this)" class="p-2 text-red-500 hover:bg-red-50 rounded-lg">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Submit Custom Badges -->
            <div class="flex justify-end mt-4 pt-4 border-t">
                <button type="submit" class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium flex items-center gap-2">
                    <i class="fas fa-save"></i>
                    บันทึก Custom Badges
                </button>
            </div>
        </div>
    </form>
</div>

<script>
// Available icons for JavaScript
const availableIcons = <?= json_encode($availableIcons) ?>;

function addCustomBadge() {
    const list = document.getElementById('customBadgesList');
    const noBadgesMsg = document.getElementById('noBadgesMessage');
    if (noBadgesMsg) noBadgesMsg.remove();
    
    const index = list.querySelectorAll('.custom-badge-item').length;
    
    const iconOptions = Object.entries(availableIcons).map(([key, name]) => 
        `<option value="${key}">${name}</option>`
    ).join('');
    
    const html = `
        <div class="custom-badge-item border rounded-xl p-4 bg-gray-50" data-index="${index}">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-center">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-500 mb-1">ไอคอน</label>
                    <select class="badge-icon w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" onchange="updateBadgePreview(this)">
                        ${iconOptions}
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-medium text-gray-500 mb-1">ข้อความ</label>
                    <input type="text" class="badge-label w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" 
                        placeholder="เช่น จัดส่งฟรี" onchange="updateBadgeData()">
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-medium text-gray-500 mb-1">ค่าที่แสดง</label>
                    <input type="text" class="badge-value w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" 
                        placeholder="เช่น ทั่วประเทศ" onchange="updateBadgeData()">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-500 mb-1">ตัวอย่าง</label>
                    <div class="badge-preview bg-white border rounded-lg p-2 text-center">
                        <i class="fas fa-star text-indigo-600 text-lg"></i>
                        <div class="text-xs font-bold truncate">-</div>
                        <div class="text-xs text-gray-500 truncate">-</div>
                    </div>
                </div>
                <div class="md:col-span-2 flex items-center gap-2 justify-end">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" class="badge-active w-4 h-4 text-indigo-600 rounded" checked onchange="updateBadgeData()">
                        <span class="text-xs text-gray-600">เปิดใช้</span>
                    </label>
                    <button type="button" onclick="removeCustomBadge(this)" class="p-2 text-red-500 hover:bg-red-50 rounded-lg">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    
    list.insertAdjacentHTML('beforeend', html);
    updateBadgeData();
}

function removeCustomBadge(btn) {
    const item = btn.closest('.custom-badge-item');
    item.remove();
    
    const list = document.getElementById('customBadgesList');
    if (list.querySelectorAll('.custom-badge-item').length === 0) {
        list.innerHTML = `
            <div id="noBadgesMessage" class="text-center py-8 text-gray-400">
                <i class="fas fa-badge text-4xl mb-3"></i>
                <p>ยังไม่มี Custom Badge</p>
                <p class="text-sm">คลิก "เพิ่ม Badge" เพื่อสร้าง Badge แบบกำหนดเอง</p>
            </div>
        `;
    }
    
    updateBadgeData();
}

function updateBadgePreview(select) {
    const item = select.closest('.custom-badge-item');
    const preview = item.querySelector('.badge-preview i');
    const icon = select.value;
    preview.className = `fas fa-${icon} text-indigo-600 text-lg`;
    updateBadgeData();
}

function updateBadgeData() {
    const items = document.querySelectorAll('.custom-badge-item');
    const badges = [];
    
    items.forEach((item, index) => {
        const icon = item.querySelector('.badge-icon').value;
        const label = item.querySelector('.badge-label').value;
        const value = item.querySelector('.badge-value').value;
        const isActive = item.querySelector('.badge-active').checked;
        
        // Update preview
        const preview = item.querySelector('.badge-preview');
        preview.querySelector('i').className = `fas fa-${icon} text-indigo-600 text-lg`;
        preview.querySelectorAll('div')[0].textContent = value || '-';
        preview.querySelectorAll('div')[1].textContent = label || '-';
        
        badges.push({
            id: index + 1,
            icon: icon,
            label: label,
            value: value,
            is_active: isActive
        });
    });
    
    document.getElementById('customBadgesJson').value = JSON.stringify(badges);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateBadgeData();
});
</script>
