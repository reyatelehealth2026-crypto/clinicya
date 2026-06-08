<?php
/**
 * admin-custom-html.php — Landing page Custom HTML editor (per-section overrides)
 *
 * Lets the user replace ANY landing section with their own HTML, or add a block
 * at the end of the page. Click a section in the Live Preview to jump here with
 * that section selected.
 *
 * Storage (landing_settings):
 *   - section = ''        → setting_key = custom_html            (block at end)
 *   - section = hero/...  → setting_key = custom_html_{section}  (replaces it)
 *
 * 2026-06-02
 */
$__sections = [
    ''         => '➕ บล็อกเพิ่มท้ายหน้า (ไม่แทนของเดิม)',
    'hero'     => '🩺 Hero (หัวหน้า — ปรึกษาเภสัชกร)',
    'about'    => '📖 แนะนำบริการ',
    'features' => '⭐ จุดเด่นของแพลตฟอร์ม',
    'services' => '🛍️ บริการของเรา',
    'cta'      => '📣 CTA (ปุ่มเริ่มใช้งาน)',
];
$selSection = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['section'] ?? '')));
if (!array_key_exists($selSection, $__sections)) {
    $selSection = '';
}
$settingKey = $selSection !== '' ? 'custom_html_' . $selSection : 'custom_html';
$currentHtml = $landingSettings[$settingKey] ?? '';
$isOverride = $selSection !== '';
?>
<div class="space-y-6">

    <div class="bg-gradient-to-r from-purple-500 to-indigo-600 rounded-xl p-6 text-white">
        <h2 class="text-xl font-bold mb-2 flex items-center gap-2">
            <i class="fas fa-code"></i> HTML แบบกำหนดเอง — แก้ได้ทุกส่วน
        </h2>
        <p class="text-sm opacity-90 leading-relaxed">
            เลือก <b>ส่วนของหน้า</b> ที่ต้องการ แล้ววาง HTML ของคุณ — ระบบจะ
            <b>แทนที่เนื้อหาเดิม (hardcode)</b> ของส่วนนั้นด้วย HTML ของคุณทันที.
            ถ้าเว้นว่างไว้ จะกลับไปใช้เนื้อหาเดิมของระบบ.
        </p>
        <p class="text-xs mt-3 opacity-75">
            💡 คลิกที่ section ใน Live Preview ฝั่งขวา จะเด้งมาที่ส่วนนั้นให้อัตโนมัติ
        </p>
    </div>

    <!-- Section selector -->
    <div class="bg-white rounded-xl border border-gray-200 p-4">
        <label class="block text-sm font-semibold text-gray-700 mb-2">
            <i class="fas fa-layer-group text-purple-500"></i> เลือกส่วนที่จะแก้
        </label>
        <select id="section_selector"
                onchange="window.location.href='?tab=custom_html' + (this.value ? '&section=' + this.value : '')"
                class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 text-sm">
            <?php foreach ($__sections as $val => $lbl): ?>
                <option value="<?= htmlspecialchars($val) ?>" <?= $val === $selSection ? 'selected' : '' ?>>
                    <?= htmlspecialchars($lbl) ?><?php
                        $k = $val !== '' ? 'custom_html_' . $val : 'custom_html';
                        if (!empty($landingSettings[$k])) echo '  ✓ (ตั้งค่าแล้ว)';
                    ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($isOverride): ?>
            <p class="text-xs text-amber-600 mt-2">
                <i class="fas fa-exclamation-triangle"></i>
                กำลังแก้ส่วน “<?= htmlspecialchars($__sections[$selSection]) ?>” — บันทึกแล้วจะ<b>แทนที่</b>เนื้อหาเดิมของส่วนนี้
                (ลบข้อความในกล่องจนว่าง แล้วบันทึก = กลับไปใช้เนื้อหาเดิม)
            </p>
        <?php else: ?>
            <p class="text-xs text-gray-500 mt-2">
                โหมดนี้จะ<b>เพิ่ม</b>บล็อกใหม่ท้ายหน้า (ไม่แทนของเดิม)
            </p>
        <?php endif; ?>
    </div>

    <form method="POST" action="" class="bg-white rounded-xl border border-gray-200 p-6">
        <input type="hidden" name="action" value="save_custom_html">
        <input type="hidden" name="section" value="<?= htmlspecialchars($selSection) ?>">

        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-code text-purple-500"></i> HTML / CSS
                <?= $isOverride ? '— ส่วน: ' . htmlspecialchars($__sections[$selSection]) : '' ?>
            </label>
            <textarea name="custom_html" id="custom_html_editor" rows="16"
                      class="w-full font-mono text-xs px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 resize-y"
                      style="tab-size: 2;"
                      placeholder="<div class='container'>
  <h2>หัวข้อของคุณ</h2>
  <p>เนื้อหา...</p>
</div>"><?= htmlspecialchars($currentHtml) ?></textarea>
            <p class="text-xs text-gray-500 mt-1">
                ⚠️ HTML จะถูกเรนเดอร์ตรงตามที่เขียน (ไม่มีการกรอง) — รองรับ &lt;div&gt;, &lt;style&gt;, รูป, embed.
                <?php if ($isOverride): ?>แนะนำห่อด้วย <code class="bg-gray-100 px-1 rounded">&lt;div class="container"&gt;</code> เพื่อจัดกึ่งกลางเหมือนเดิม<?php endif; ?>
            </p>
        </div>

        <div class="flex items-center justify-between border-t border-gray-100 pt-4">
            <div class="text-xs text-gray-500">
                <i class="fas fa-info-circle"></i> เว้นว่าง + บันทึก = กลับไปใช้เนื้อหาเดิมของระบบ
            </div>
            <button type="submit"
                    class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium text-sm inline-flex items-center gap-2">
                <i class="fas fa-save"></i> บันทึก
            </button>
        </div>
    </form>
</div>
