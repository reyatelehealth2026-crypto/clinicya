<?php
/**
 * admin-custom-html.php — Landing page Custom HTML block editor
 *
 * Lets the user paste raw HTML/<div> content that renders inside index.php
 * (just before the footer). Saved to landing_settings[setting_key=custom_html].
 *
 * 2026-05-27
 */
$currentHtml = $landingSettings['custom_html'] ?? '';
?>
<div class="space-y-6">

    <div class="bg-gradient-to-r from-purple-500 to-indigo-600 rounded-xl p-6 text-white">
        <h2 class="text-xl font-bold mb-2 flex items-center gap-2">
            <i class="fas fa-code"></i>
            HTML แบบกำหนดเอง
        </h2>
        <p class="text-sm opacity-90 leading-relaxed">
            วาง HTML ที่ต้องการแสดงในหน้า Landing Page (จะแสดงเหนือ Footer เสมอ).
            รองรับ <code class="bg-white/20 px-1 rounded">&lt;div&gt;</code>, <code class="bg-white/20 px-1 rounded">&lt;style&gt;</code>, ลิ้งค์, รูปภาพ, embed
            (YouTube / Map / Facebook) — เนื้อหาจะถูกเรนเดอร์เหมือนที่พิมพ์ ไม่มีการกรอง
        </p>
        <p class="text-xs mt-3 opacity-75">
            💡 <strong>เทคนิค:</strong> เปิด Live Preview ฝั่งขวาเพื่อดูผลทันทีหลังบันทึก
        </p>
    </div>

    <form method="POST" action="" class="bg-white rounded-xl border border-gray-200 p-6">
        <input type="hidden" name="action" value="save_custom_html">

        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-2">
                <i class="fas fa-code text-purple-500"></i> HTML / CSS / div
            </label>
            <textarea name="custom_html" id="custom_html_editor" rows="14"
                      class="w-full font-mono text-xs px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 resize-y"
                      style="tab-size: 2;"
                      placeholder="<div style='background:#fef3c7; padding:16px; border-radius:12px;'>
  <h3 style='color:#92400e; margin:0 0 8px;'>🎉 โปรโมชั่นพิเศษ</h3>
  <p>ลด 20% เมื่อสั่งซื้อครบ 1,000 บาท ผ่าน LINE</p>
</div>"><?= htmlspecialchars($currentHtml) ?></textarea>
            <p class="text-xs text-gray-500 mt-1">
                ⚠️ ระวัง: HTML จะถูกเรนเดอร์ตรงตามที่เขียน. ลิ้งค์ภายนอก / สคริปต์ ทำงานได้ทั้งหมด —
                ใช้กับเนื้อหาที่คุณไว้ใจเท่านั้น
            </p>
        </div>

        <div class="grid md:grid-cols-2 gap-4 mb-4">
            <button type="button" onclick="reyaInsertSnippet('promo')"
                    class="text-xs px-3 py-2 border border-purple-200 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg">
                + ตัวอย่าง: กล่องโปรโมชั่น
            </button>
            <button type="button" onclick="reyaInsertSnippet('map')"
                    class="text-xs px-3 py-2 border border-purple-200 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg">
                + ตัวอย่าง: Google Maps embed
            </button>
            <button type="button" onclick="reyaInsertSnippet('youtube')"
                    class="text-xs px-3 py-2 border border-purple-200 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg">
                + ตัวอย่าง: YouTube embed
            </button>
            <button type="button" onclick="reyaInsertSnippet('notice')"
                    class="text-xs px-3 py-2 border border-purple-200 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg">
                + ตัวอย่าง: แถบประกาศ
            </button>
        </div>

        <div class="flex items-center justify-between border-t border-gray-100 pt-4">
            <div class="text-xs text-gray-500">
                <i class="fas fa-info-circle"></i>
                เคลียร์ทั้งหมด: ลบเนื้อหาในกล่อง แล้วกดบันทึก
            </div>
            <button type="submit"
                    class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium text-sm inline-flex items-center gap-2">
                <i class="fas fa-save"></i> บันทึก Custom HTML
            </button>
        </div>
    </form>
</div>

<script>
function reyaInsertSnippet(kind) {
    const snippets = {
        promo:
`<div style="background:linear-gradient(135deg,#fef3c7,#fde68a); padding:20px; border-radius:16px; margin:24px auto; max-width:680px; text-align:center;">
  <h3 style="color:#92400e; margin:0 0 8px; font-size:20px;">🎉 โปรโมชั่นพิเศษ</h3>
  <p style="color:#78350f; margin:0;">ลด 20% เมื่อสั่งซื้อครบ 1,000 บาท ผ่าน LINE</p>
</div>`,
        map:
`<div style="max-width:680px; margin:24px auto; border-radius:16px; overflow:hidden;">
  <iframe src="https://www.google.com/maps/embed?pb=..." width="100%" height="320" style="border:0;" allowfullscreen loading="lazy"></iframe>
</div>`,
        youtube:
`<div style="max-width:680px; margin:24px auto; border-radius:16px; overflow:hidden; aspect-ratio:16/9;">
  <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="100%" height="100%" frameborder="0" allowfullscreen></iframe>
</div>`,
        notice:
`<div style="background:#dbeafe; border-left:4px solid #2563eb; padding:14px 20px; margin:24px auto; max-width:680px; border-radius:8px;">
  <strong style="color:#1e40af;">📢 ประกาศ:</strong>
  <span style="color:#1e3a8a;">เปิดให้บริการตามปกติทุกวัน 08:00 - 24:00 น.</span>
</div>`
    };
    const ta = document.getElementById('custom_html_editor');
    if (!ta) return;
    const sep = ta.value.trim() ? '\n\n' : '';
    ta.value = ta.value + sep + snippets[kind];
    ta.focus();
    ta.scrollTop = ta.scrollHeight;
}
</script>
