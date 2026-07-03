<?php
/**
 * Admin tab: เว็บร้านโฉมใหม่ (Landing V2)
 * เลือกธีม/hero, พาดหัว, รูปหน้าร้าน, เปิด/ปิด section แล้ว preview ร่างก่อนเผยแพร่
 *
 * ตัวแปรจาก website.php: $db, $currentBotId, $landingV2
 */

$v2Draft = $landingV2->getDraft();
$v2IsPublished = $landingV2->isPublished();

$v2TenantId = class_exists('TenantContext') ? TenantContext::getCurrentTenantId() : null;

// สี swatch ของแต่ละธีม (ใช้แสดงในตัวเลือกเท่านั้น — สีจริงอยู่ใน landing-v2.css)
$v2ThemeSwatches = [
    'mint'     => ['accent' => '#0E7D68', 'bg' => '#E9F5F0'],
    'latte'    => ['accent' => '#A9651F', 'bg' => '#F9EEDC'],
    'forest'   => ['accent' => '#0B5A47', 'bg' => '#103A30'],
    'galaxy'   => ['accent' => '#4C3FA8', 'bg' => '#2B2160'],
    'sunshine' => ['accent' => '#B45309', 'bg' => '#FFF3C9'],
    'ocean'    => ['accent' => '#0A6C8C', 'bg' => '#D8EEF6'],
];
$v2SectionLabels = [
    'banners'     => 'แบนเนอร์สไลด์',
    'services'    => 'บริการของร้าน',
    'products'    => 'สินค้าแนะนำ',
    'faq'         => 'คำถามที่พบบ่อย',
    'articles'    => 'บทความสุขภาพ',
    'custom_html' => 'Custom HTML',
];
?>

<div class="space-y-6">

    <!-- สถานะ + ปุ่มหลัก -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h3 class="text-lg font-bold flex items-center gap-2">
                    <i class="fas fa-wand-magic-sparkles text-emerald-600"></i>
                    เว็บร้านโฉมใหม่
                </h3>
                <p class="text-sm text-gray-500 mt-1">
                    สถานะ:
                    <?php if ($v2IsPublished): ?>
                        <span class="badge-status badge-approved">เผยแพร่แล้ว ลูกค้าเห็นหน้าใหม่</span>
                    <?php else: ?>
                        <span class="badge-status badge-pending">ยังไม่เผยแพร่ ลูกค้าเห็นหน้าเดิม</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="/?v2=draft" target="_blank"
                   class="px-4 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium text-sm">
                    <i class="fas fa-eye mr-1"></i> ดูตัวอย่างร่าง
                </a>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="publish_v2">
                    <button type="submit"
                            onclick="return confirm('เผยแพร่ร่างล่าสุดที่บันทึกไว้ให้ลูกค้าเห็นทันที? (ถ้าเพิ่งแก้ฟอร์มอยู่ กดบันทึกร่างก่อน)')"
                            class="px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-medium text-sm">
                        <i class="fas fa-rocket mr-1"></i> เผยแพร่
                    </button>
                </form>
                <?php if ($v2IsPublished): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="unpublish_v2">
                    <button type="submit"
                            onclick="return confirm('ปิดใช้เว็บโฉมใหม่ ลูกค้าจะกลับไปเห็นหน้าเดิมทันที?')"
                            class="px-4 py-2 rounded-lg border border-red-300 text-red-600 hover:bg-red-50 font-medium text-sm">
                        <i class="fas fa-rotate-left mr-1"></i> กลับไปใช้หน้าเดิม
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-xs text-gray-400 mt-3">
            การแก้ไขทั้งหมดบันทึกเป็น "ร่าง" ก่อน ลูกค้าจะเห็นก็ต่อเมื่อกดเผยแพร่
            ส่วนแบนเนอร์ สินค้าแนะนำ FAQ และบทความ แก้ที่แท็บของมันแล้วมีผลกับหน้าเว็บทันที
        </p>
    </div>

    <!-- ฟอร์มร่าง: ธีม / hero / ข้อความ / section -->
    <form method="POST" class="space-y-6">
        <input type="hidden" name="action" value="save_v2_draft">

        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-palette text-emerald-600"></i>
                </span>
                ธีมของร้าน
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <?php foreach (LandingV2Config::THEMES as $slug => $label):
                    $swatch = $v2ThemeSwatches[$slug] ?? ['accent' => '#888', 'bg' => '#eee'];
                ?>
                <label class="relative cursor-pointer border rounded-xl p-3 flex items-center gap-3 hover:border-emerald-400 transition
                              <?= $v2Draft['theme'] === $slug ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-gray-200' ?>">
                    <input type="radio" name="v2_theme" value="<?= htmlspecialchars($slug) ?>"
                           <?= $v2Draft['theme'] === $slug ? 'checked' : '' ?> class="sr-only">
                    <span class="w-9 h-9 rounded-lg flex-none border border-black/10"
                          style="background: linear-gradient(135deg, <?= htmlspecialchars($swatch['bg']) ?> 55%, <?= htmlspecialchars($swatch['accent']) ?> 55%);"></span>
                    <span class="font-medium text-sm"><?= htmlspecialchars($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-star text-blue-600"></i>
                </span>
                ส่วนเปิดหน้า (Hero)
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <label class="relative cursor-pointer border rounded-xl p-4 hover:border-emerald-400 transition
                              <?= $v2Draft['hero'] === 'shop' ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-gray-200' ?>">
                    <input type="radio" name="v2_hero" value="shop" <?= $v2Draft['hero'] === 'shop' ? 'checked' : '' ?> class="sr-only">
                    <div class="font-bold text-sm mb-1">เน้นหน้าร้าน</div>
                    <div class="text-xs text-gray-500">โชว์รูปหน้าร้านจริง สร้างความเชื่อใจ เหมาะกับร้านที่เน้นบริการปรึกษา</div>
                </label>
                <label class="relative cursor-pointer border rounded-xl p-4 hover:border-emerald-400 transition
                              <?= $v2Draft['hero'] === 'product' ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-gray-200' ?>">
                    <input type="radio" name="v2_hero" value="product" <?= $v2Draft['hero'] === 'product' ? 'checked' : '' ?> class="sr-only">
                    <div class="font-bold text-sm mb-1">เน้นสินค้า</div>
                    <div class="text-xs text-gray-500">โชว์สินค้าแนะนำตั้งแต่เปิดหน้า เหมาะกับร้านที่เน้นขายของ (ต้องตั้งสินค้าแนะนำก่อน)</div>
                </label>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-heading text-amber-600"></i>
                </span>
                ข้อความหลัก
            </h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">พาดหัว (เว้นว่าง = ใช้ข้อความมาตรฐาน)</label>
                    <input type="text" name="v2_headline" maxlength="120"
                           value="<?= htmlspecialchars($v2Draft['headline']) ?>"
                           placeholder="ร้านยาใกล้บ้านคุณ ปรึกษาเภสัชกรได้ทุกวัน"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">คำโปรย (เว้นว่าง = ใช้คำอธิบายร้านจากตั้งค่าร้าน)</label>
                    <input type="text" name="v2_tagline" maxlength="200"
                           value="<?= htmlspecialchars($v2Draft['tagline']) ?>"
                           placeholder="เช่น ดูแลคนย่านนี้มากว่า 10 ปี ทักถามอาการผ่าน LINE ได้เลย"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-6">
            <h3 class="text-lg font-bold mb-4 flex items-center gap-2">
                <span class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-list-check text-purple-600"></i>
                </span>
                ส่วนที่แสดงบนหน้าเว็บ
            </h3>
            <p class="text-xs text-gray-400 mb-3">ส่วนที่ไม่มีข้อมูล (เช่น ยังไม่ตั้งสินค้าแนะนำ) จะถูกซ่อนอัตโนมัติแม้จะเปิดไว้</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <?php foreach ($v2SectionLabels as $sec => $label): ?>
                <label class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" name="v2_show[<?= htmlspecialchars($sec) ?>]" value="1"
                           <?= !empty($v2Draft['show'][$sec]) ? 'checked' : '' ?>
                           class="w-4 h-4 text-emerald-600 rounded focus:ring-emerald-500">
                    <span class="text-sm font-medium"><?= htmlspecialchars($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-medium">
                <i class="fas fa-floppy-disk mr-1"></i> บันทึกร่าง
            </button>
        </div>
    </form>

    <!-- รูปหน้าร้าน (อัปโหลดแยกช่อง มีผลกับร่างทันทีที่อัปโหลด) -->
    <div class="bg-white rounded-xl shadow-sm p-6">
        <h3 class="text-lg font-bold mb-1 flex items-center gap-2">
            <span class="w-8 h-8 bg-teal-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-camera text-teal-600"></i>
            </span>
            รูปถ่ายหน้าร้านจริง
        </h3>
        <p class="text-sm text-gray-500 mb-4">รูปจริงช่วยให้ลูกค้าเชื่อใจว่าเป็นร้านที่มีตัวตน แนะนำรูปแนวนอน ไฟล์ JPG/PNG/WEBP ไม่เกิน 5MB</p>
        <?php if (!$v2TenantId): ?>
        <div class="p-4 bg-amber-50 border border-amber-200 text-amber-700 rounded-lg text-sm">
            ไม่พบ tenant ปัจจุบัน จึงอัปโหลดรูปไม่ได้ (super admin ต้องเข้าผ่านร้านก่อน)
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php foreach (LandingV2Config::PHOTO_SLOTS as $slot => $slotLabel):
                $currentFile = $v2Draft['photos'][$slot] ?? '';
                $currentUrl = '';
                if ($currentFile !== '') {
                    try { $currentUrl = TenantFileStorage::url((int) $v2TenantId, 'shop_photos', $currentFile); } catch (Exception $e) {}
                }
            ?>
            <div class="border border-gray-200 rounded-xl p-4">
                <div class="font-medium text-sm mb-2"><?= htmlspecialchars($slotLabel) ?></div>
                <?php if ($currentUrl !== ''): ?>
                <img src="<?= htmlspecialchars($currentUrl) ?>" alt="<?= htmlspecialchars($slotLabel) ?>"
                     class="w-full h-28 object-cover rounded-lg mb-3 border border-gray-100">
                <?php else: ?>
                <div class="w-full h-28 rounded-lg mb-3 bg-gray-50 border border-dashed border-gray-300 flex items-center justify-center text-gray-400 text-xs">
                    ยังไม่มีรูป
                </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" class="space-y-2">
                    <input type="hidden" name="action" value="upload_v2_photo">
                    <input type="hidden" name="slot" value="<?= htmlspecialchars($slot) ?>">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required
                           class="block w-full text-xs text-gray-500 file:mr-2 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-emerald-50 file:text-emerald-700 file:text-xs file:font-medium hover:file:bg-emerald-100">
                    <button type="submit" class="w-full px-3 py-1.5 rounded-lg bg-gray-800 text-white text-xs font-medium hover:bg-gray-700">
                        <i class="fas fa-upload mr-1"></i> อัปโหลด
                    </button>
                </form>
                <?php if ($currentFile !== ''): ?>
                <form method="POST" class="mt-2">
                    <input type="hidden" name="action" value="remove_v2_photo">
                    <input type="hidden" name="slot" value="<?= htmlspecialchars($slot) ?>">
                    <button type="submit" onclick="return confirm('ลบรูปนี้?')"
                            class="w-full px-3 py-1.5 rounded-lg border border-red-200 text-red-600 text-xs font-medium hover:bg-red-50">
                        <i class="fas fa-trash mr-1"></i> ลบรูป
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Radio selection only updates on the server after a full form submit (the
// green border comes from PHP checking the saved draft) — without this, a
// click looks like it did nothing until "บันทึกร่าง" reloads the page.
// This just mirrors that same visual state instantly on click.
document.querySelectorAll('input[name="v2_theme"], input[name="v2_hero"]').forEach(function (input) {
    input.addEventListener('change', function () {
        document.querySelectorAll('input[name="' + input.name + '"]').forEach(function (sibling) {
            var label = sibling.closest('label');
            if (!label) { return; }
            label.classList.remove('border-emerald-500', 'ring-2', 'ring-emerald-200');
            label.classList.remove('border-gray-200');
            label.classList.add(sibling.checked ? 'border-emerald-500' : 'border-gray-200');
            if (sibling.checked) { label.classList.add('ring-2', 'ring-emerald-200'); }
        });
    });
});
</script>
