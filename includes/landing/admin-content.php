<?php
/**
 * Admin: Landing Page Content Editor
 * แก้ไขเนื้อหา Hero / About / Features / Services / CTA บนหน้า Landing
 *
 * Required scope (from admin/landing-settings.php):
 *   $contentService  (LandingContentService)
 *   $shopName        (string)
 */

if (!isset($contentService)) {
    echo '<div class="p-4 bg-red-50 border border-red-200 rounded">contentService ไม่พร้อม</div>';
    return;
}

if (!isset($shopName)) {
    try {
        $sn = $db->prepare("SELECT shop_name FROM shop_settings WHERE line_account_id = ? LIMIT 1");
        $sn->execute([$currentBotId]);
        $shopName = $sn->fetchColumn() ?: 'ร้านของเรา';
    } catch (Exception $e) {
        $shopName = 'ร้านของเรา';
    }
}

$content = $contentService->getAll();
$jsonAttr = fn(array $a) => htmlspecialchars(json_encode($a, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>

<style>
.lc-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:20px; margin-bottom:18px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.lc-card h3 { font-size:1rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:.5rem; margin:0 0 4px; }
.lc-card .lc-card__desc { color:#64748b; font-size:.85rem; margin-bottom:14px; }
.lc-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.lc-row--3 { grid-template-columns:repeat(3, 1fr); }
@media (max-width: 768px) { .lc-row, .lc-row--3 { grid-template-columns:1fr; } }
.lc-label { display:block; font-size:.8rem; font-weight:600; color:#475569; margin-bottom:6px; }
.lc-input, .lc-textarea {
    width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;
    font-size:.9rem; color:#0f172a; background:#fff; transition:border .15s, box-shadow .15s;
}
.lc-input:focus, .lc-textarea:focus { outline:none; border-color:#0ea5e9; box-shadow:0 0 0 3px rgba(14,165,233,.12); }
.lc-textarea { min-height:84px; resize:vertical; font-family:inherit; }
.lc-help { font-size:.72rem; color:#94a3b8; margin-top:4px; }
.lc-help code { background:#f1f5f9; padding:1px 5px; border-radius:4px; font-size:.7rem; color:#0369a1; }

.lc-repeater { display:flex; flex-direction:column; gap:10px; }
.lc-item { border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; padding:12px; }
.lc-item__head { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; gap:8px; }
.lc-item__handle { color:#94a3b8; cursor:grab; padding:4px; }
.lc-item__handle:active { cursor:grabbing; }
.lc-item.lc-dragging { opacity:.5; background:#e0f2fe; }
.lc-item__title { font-weight:600; color:#334155; font-size:.85rem; flex:1; }
.lc-btn-icon { padding:6px 8px; border-radius:6px; background:#fff; border:1px solid #e2e8f0; color:#64748b; cursor:pointer; }
.lc-btn-icon:hover { color:#dc2626; border-color:#fecaca; background:#fef2f2; }
.lc-btn-add {
    margin-top:8px; padding:8px 14px; background:#0ea5e9; color:#fff; border:0; border-radius:8px;
    font-size:.85rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
}
.lc-btn-add:hover { background:#0284c7; }

.lc-preview {
    padding:10px; background:linear-gradient(135deg,#ecfeff,#e0f2fe); border:1px dashed #38bdf8;
    border-radius:8px; text-align:center; color:#0c4a6e; font-size:.8rem; line-height:1.3;
}
.lc-preview i { font-size:1.2rem; display:block; margin-bottom:4px; color:#0284c7; }

.lc-save-bar {
    position:sticky; bottom:0; z-index:10;
    background:#fff; border-top:1px solid #e5e7eb; padding:14px 20px;
    display:flex; justify-content:space-between; align-items:center; gap:12px;
    margin:18px 0 0; border-radius:12px;
    box-shadow:0 -4px 12px rgba(0,0,0,.04);
    /* iOS safe-area for home indicator + URL bar */
    padding-bottom: max(14px, env(safe-area-inset-bottom));
    transition: opacity .15s ease;
}
.lc-save-bar.lc-save-bar--dimmed { opacity: .4; }
.lc-save-bar__left { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.lc-btn-save {
    padding:10px 22px; background:#10b981; color:#fff; border:0; border-radius:8px;
    font-size:.95rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px;
}
.lc-btn-save:hover { background:#059669; }
.lc-btn-reset {
    padding:8px 14px; background:transparent; color:#64748b; border:1px solid #e2e8f0; border-radius:8px;
    font-size:.85rem; cursor:pointer;
}
.lc-btn-reset:hover { color:#dc2626; border-color:#fecaca; }
.lc-btn-restore {
    padding:8px 12px; background:transparent; color:#0284c7; border:1px solid #bae6fd; border-radius:8px;
    font-size:.82rem; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
}
.lc-btn-restore:hover { background:#f0f9ff; }

/* up/down repeater controls */
.lc-item__ctrls { display:flex; align-items:center; gap:4px; }
.lc-btn-move {
    padding:6px 8px; border-radius:6px; background:#fff; border:1px solid #e2e8f0;
    color:#475569; cursor:pointer; font-size:.85rem; line-height:1;
}
.lc-btn-move:hover { color:#0ea5e9; border-color:#bae6fd; background:#f0f9ff; }
.lc-btn-move:disabled { opacity:.35; cursor:not-allowed; }

/* {shop} insert chip */
.lc-chip-shop {
    display:inline-flex; align-items:center; gap:4px;
    padding:2px 8px; margin-top:4px; background:#fef3c7; color:#92400e;
    border:1px solid #fde68a; border-radius:999px; font-size:.7rem; font-weight:600;
    cursor:pointer; user-select:none;
}
.lc-chip-shop:hover { background:#fde68a; }

/* icon picker */
.lc-icon-field { display:flex; gap:6px; align-items:stretch; }
.lc-icon-field .lc-input { flex:1; }
.lc-btn-pick {
    padding:8px 12px; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;
    border-radius:8px; cursor:pointer; font-size:.9rem;
}
.lc-btn-pick:hover { background:#e0f2fe; color:#0284c7; border-color:#bae6fd; }

/* modal */
.lc-modal-overlay {
    position:fixed; inset:0; background:rgba(15,23,42,.55); display:none;
    align-items:center; justify-content:center; z-index:9999; padding:16px;
}
.lc-modal-overlay.lc-open { display:flex; }
.lc-modal {
    background:#fff; border-radius:14px; max-width:720px; width:100%;
    max-height:85vh; overflow:hidden; display:flex; flex-direction:column;
    box-shadow:0 25px 50px -12px rgba(0,0,0,.3);
}
.lc-modal__head { padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; }
.lc-modal__title { font-weight:700; color:#0f172a; font-size:1rem; }
.lc-modal__close { background:none; border:0; color:#94a3b8; font-size:1.3rem; cursor:pointer; padding:0 6px; }
.lc-modal__body { padding:16px 18px; overflow-y:auto; }
.lc-modal__foot { padding:12px 18px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px; background:#f8fafc; }
.lc-modal__cat-title { font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin:14px 0 6px; font-weight:700; }
.lc-modal__cat-title:first-child { margin-top:0; }

.lc-icon-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(110px, 1fr)); gap:8px; }
.lc-icon-cell {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    padding:10px 6px; border:1px solid #e2e8f0; border-radius:8px; background:#fff;
    cursor:pointer; transition:all .15s; text-align:center;
}
.lc-icon-cell:hover { border-color:#0ea5e9; background:#f0f9ff; }
.lc-icon-cell i { font-size:1.25rem; color:#475569; margin-bottom:4px; }
.lc-icon-cell:hover i { color:#0284c7; }
.lc-icon-cell__label { font-size:.65rem; color:#64748b; word-break:break-all; line-height:1.2; }

.lc-reset-input { width:100%; padding:10px 12px; border:2px solid #fecaca; border-radius:8px; font-size:.95rem; margin:10px 0; }
.lc-reset-input:focus { outline:none; border-color:#dc2626; box-shadow:0 0 0 3px rgba(220,38,38,.12); }
.lc-btn-danger { padding:10px 20px; background:#dc2626; color:#fff; border:0; border-radius:8px; font-weight:600; cursor:pointer; }
.lc-btn-danger:hover:not(:disabled) { background:#b91c1c; }
.lc-btn-danger:disabled { opacity:.4; cursor:not-allowed; }
.lc-btn-cancel { padding:10px 16px; background:#fff; color:#475569; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; }

/* draft-restore banner */
.lc-draft-banner {
    background:linear-gradient(135deg,#fef9c3,#fde68a); border:1px solid #fcd34d;
    color:#78350f; padding:10px 14px; border-radius:10px; margin-bottom:12px;
    display:flex; justify-content:space-between; align-items:center; gap:10px; font-size:.85rem;
}
.lc-draft-banner button { padding:6px 12px; border-radius:6px; border:0; cursor:pointer; font-weight:600; font-size:.8rem; }
.lc-draft-banner .lc-draft-yes { background:#f59e0b; color:#fff; }
.lc-draft-banner .lc-draft-no { background:transparent; color:#78350f; }
</style>

<form method="POST" id="landingContentForm">
    <input type="hidden" name="action" value="save_landing_content">
    <input type="hidden" name="reset_content" id="resetField" value="0">
    <!-- hidden JSON inputs for repeaters -->
    <input type="hidden" name="hero_trust_items"   id="json_hero_trust_items"   value="<?= $jsonAttr($content['hero_trust_items']) ?>">
    <input type="hidden" name="about_paragraphs"   id="json_about_paragraphs"   value="<?= $jsonAttr($content['about_paragraphs']) ?>">
    <input type="hidden" name="features_cards"     id="json_features_cards"     value="<?= $jsonAttr($content['features_cards']) ?>">
    <input type="hidden" name="services_cards"     id="json_services_cards"     value="<?= $jsonAttr($content['services_cards']) ?>">

    <!-- Info banner -->
    <div class="lc-card" style="background:linear-gradient(135deg,#eff6ff,#dbeafe); border-color:#bfdbfe;">
        <h3 style="color:#1e40af;"><i class="fas fa-lightbulb"></i> วิธีใช้</h3>
        <p class="lc-card__desc" style="margin:0; color:#1e3a8a;">
            ใช้ <code style="background:#fff;padding:2px 6px;border-radius:4px;">{shop}</code> ในข้อความ ระบบจะแทนที่ด้วยชื่อร้าน (<strong><?= $h($shopName) ?></strong>) อัตโนมัติ —
            ไอคอนใช้ชื่อ Font Awesome เช่น <code style="background:#fff;padding:2px 6px;border-radius:4px;">fa-pills</code>, <code style="background:#fff;padding:2px 6px;border-radius:4px;">fa-truck</code>
            (<a href="https://fontawesome.com/search?o=r&m=free" target="_blank" rel="noopener" style="color:#1d4ed8;text-decoration:underline;">ดูทั้งหมด</a>)
        </p>
    </div>

    <!-- ====================== HERO ====================== -->
    <div class="lc-card">
        <h3><i class="fas fa-flag" style="color:#06b6d4;"></i> Hero (หัวเรื่องหลัก)</h3>
        <p class="lc-card__desc">ส่วนแรกของหน้า — title, subtitle, trust pills และปุ่ม CTA</p>

        <div class="lc-row">
            <div>
                <label class="lc-label">หัวเรื่อง (H1)</label>
                <input class="lc-input" type="text" name="hero_title" data-shop="1" value="<?= $h($content['hero_title']) ?>">
                <span class="lc-chip-shop" data-insert-shop="hero_title"><i class="fas fa-store"></i> ใส่ {shop}</span>
            </div>
            <div>
                <label class="lc-label">คำโปรย (Subtitle)</label>
                <input class="lc-input" type="text" name="hero_subtitle" data-shop="1" value="<?= $h($content['hero_subtitle']) ?>">
                <span class="lc-chip-shop" data-insert-shop="hero_subtitle"><i class="fas fa-store"></i> ใส่ {shop}</span>
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="lc-label">Trust Pills (ป้ายจุดเด่นใต้ subtitle)</label>
            <div id="rep-hero" class="lc-repeater"></div>
            <button type="button" class="lc-btn-add" data-rep="hero"><i class="fas fa-plus"></i> เพิ่ม Pill</button>
        </div>

        <div class="lc-row lc-row--3" style="margin-top:18px;">
            <div>
                <label class="lc-label">ปุ่ม #1 (LINE)</label>
                <input class="lc-input" type="text" name="hero_cta_primary_label" value="<?= $h($content['hero_cta_primary_label']) ?>" placeholder="ข้อความปุ่ม">
                <input class="lc-input" type="text" name="hero_cta_primary_icon" data-icon="1" value="<?= $h($content['hero_cta_primary_icon']) ?>"  placeholder="fab fa-line" style="margin-top:6px;">
                <p class="lc-help">ลิงก์จะใช้ LIFF URL อัตโนมัติ</p>
            </div>
            <div>
                <label class="lc-label">ปุ่ม #2 (สินค้าแนะนำ)</label>
                <input class="lc-input" type="text" name="hero_cta_secondary_label" value="<?= $h($content['hero_cta_secondary_label']) ?>" placeholder="ข้อความปุ่ม">
                <input class="lc-input" type="text" name="hero_cta_secondary_icon" data-icon="1" value="<?= $h($content['hero_cta_secondary_icon']) ?>"  placeholder="fas fa-shopping-bag" style="margin-top:6px;">
                <input class="lc-input" type="text" name="hero_cta_secondary_link"  value="<?= $h($content['hero_cta_secondary_link']) ?>"  placeholder="#featured-products" style="margin-top:6px;">
            </div>
            <div>
                <label class="lc-label">ปุ่ม #3 (บริการ)</label>
                <input class="lc-input" type="text" name="hero_cta_tertiary_label" value="<?= $h($content['hero_cta_tertiary_label']) ?>" placeholder="ข้อความปุ่ม">
                <input class="lc-input" type="text" name="hero_cta_tertiary_icon" data-icon="1" value="<?= $h($content['hero_cta_tertiary_icon']) ?>"  placeholder="fas fa-briefcase-medical" style="margin-top:6px;">
                <input class="lc-input" type="text" name="hero_cta_tertiary_link"  value="<?= $h($content['hero_cta_tertiary_link']) ?>"  placeholder="#services" style="margin-top:6px;">
            </div>
        </div>
    </div>

    <!-- ====================== ABOUT ====================== -->
    <div class="lc-card">
        <h3><i class="fas fa-circle-info" style="color:#10b981;"></i> About (แนะนำบริการ)</h3>
        <p class="lc-card__desc">ย่อหน้าแนะนำธุรกิจ — ใส่ได้กี่ย่อหน้าก็ได้</p>

        <div class="lc-row">
            <div>
                <label class="lc-label">หัวเรื่อง (H2)</label>
                <input class="lc-input" type="text" name="about_heading" data-shop="1" value="<?= $h($content['about_heading']) ?>">
                <span class="lc-chip-shop" data-insert-shop="about_heading"><i class="fas fa-store"></i> ใส่ {shop}</span>
            </div>
            <div>
                <label class="lc-label">ไอคอนใหญ่ด้านข้าง</label>
                <input class="lc-input" type="text" name="about_icon" data-icon="1" value="<?= $h($content['about_icon']) ?>" placeholder="fa-hand-holding-medical">
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="lc-label">ย่อหน้าเนื้อหา (ลากเพื่อจัดลำดับ)</label>
            <div id="rep-about" class="lc-repeater"></div>
            <button type="button" class="lc-btn-add" data-rep="about"><i class="fas fa-plus"></i> เพิ่มย่อหน้า</button>
        </div>

        <div class="lc-row" style="margin-top:14px;">
            <div>
                <label class="lc-label">ข้อความปุ่ม "อ่านต่อ"</label>
                <input class="lc-input" type="text" name="about_cta_label" value="<?= $h($content['about_cta_label']) ?>">
            </div>
            <div>
                <label class="lc-label">ลิงก์ปุ่ม</label>
                <input class="lc-input" type="text" name="about_cta_link" value="<?= $h($content['about_cta_link']) ?>" placeholder="#services">
            </div>
        </div>
    </div>

    <!-- ====================== FEATURES ====================== -->
    <div class="lc-card">
        <h3><i class="fas fa-star" style="color:#f59e0b;"></i> Features (คุณสมบัติเด่น)</h3>
        <p class="lc-card__desc">การ์ดคุณสมบัติ — แสดงเป็นกริด</p>

        <div class="lc-row">
            <div>
                <label class="lc-label">หัวเรื่อง (H2)</label>
                <input class="lc-input" type="text" name="features_heading" value="<?= $h($content['features_heading']) ?>">
            </div>
            <div>
                <label class="lc-label">คำโปรย</label>
                <input class="lc-input" type="text" name="features_subheading" data-shop="1" value="<?= $h($content['features_subheading']) ?>">
                <span class="lc-chip-shop" data-insert-shop="features_subheading"><i class="fas fa-store"></i> ใส่ {shop}</span>
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="lc-label">การ์ด (ลากเพื่อจัดลำดับ)</label>
            <div id="rep-features" class="lc-repeater"></div>
            <button type="button" class="lc-btn-add" data-rep="features"><i class="fas fa-plus"></i> เพิ่มการ์ด</button>
        </div>
    </div>

    <!-- ====================== SERVICES ====================== -->
    <div class="lc-card">
        <h3><i class="fas fa-briefcase-medical" style="color:#6366f1;"></i> Services (บริการของเรา)</h3>
        <p class="lc-card__desc">การ์ดลิงก์ไปยังบริการต่างๆ ของ LIFF</p>

        <div class="lc-row">
            <div>
                <label class="lc-label">หัวเรื่อง (H2)</label>
                <input class="lc-input" type="text" name="services_heading" value="<?= $h($content['services_heading']) ?>">
            </div>
            <div>
                <label class="lc-label">คำโปรย</label>
                <input class="lc-input" type="text" name="services_subheading" value="<?= $h($content['services_subheading']) ?>">
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="lc-label">บริการ (ลากเพื่อจัดลำดับ)</label>
            <div id="rep-services" class="lc-repeater"></div>
            <button type="button" class="lc-btn-add" data-rep="services"><i class="fas fa-plus"></i> เพิ่มบริการ</button>
        </div>
    </div>

    <!-- ====================== CTA ====================== -->
    <div class="lc-card">
        <h3><i class="fas fa-bullhorn" style="color:#f43f5e;"></i> CTA (เชิญชวนตอนท้าย)</h3>
        <p class="lc-card__desc">แถบสีเข้มท้ายหน้าก่อน footer</p>
        <div class="lc-row">
            <div>
                <label class="lc-label">หัวเรื่อง</label>
                <input class="lc-input" type="text" name="cta_heading" value="<?= $h($content['cta_heading']) ?>">
            </div>
            <div>
                <label class="lc-label">ข้อความปุ่ม</label>
                <input class="lc-input" type="text" name="cta_button_label" value="<?= $h($content['cta_button_label']) ?>">
                <input class="lc-input" type="text" name="cta_button_icon" data-icon="1" value="<?= $h($content['cta_button_icon']) ?>" placeholder="fab fa-line" style="margin-top:6px;">
            </div>
        </div>
        <div style="margin-top:14px;">
            <label class="lc-label">ข้อความ</label>
            <textarea class="lc-textarea" name="cta_paragraph" data-shop="1" rows="3"><?= $h($content['cta_paragraph']) ?></textarea>
            <span class="lc-chip-shop" data-insert-shop="cta_paragraph"><i class="fas fa-store"></i> ใส่ {shop}</span>
        </div>
    </div>

    <div class="lc-save-bar" id="lcSaveBar">
        <div class="lc-save-bar__left">
            <button type="button" class="lc-btn-reset" id="lcResetBtn">
                <i class="fas fa-rotate-left"></i> ลบเนื้อหาที่แก้ → คืน default
            </button>
            <button type="button" class="lc-btn-restore" id="lcRestoreBtn" title="ย้อนคืน snapshot ล่าสุดก่อนการรีเซ็ต">
                <i class="fas fa-clock-rotate-left"></i> ย้อนคืนล่าสุด
            </button>
        </div>
        <button type="submit" class="lc-btn-save">
            <i class="fas fa-floppy-disk"></i> บันทึกเนื้อหา Landing Page
        </button>
    </div>
</form>

<!-- Separate form for restore (single-action POST) -->
<form method="POST" id="lcRestoreForm" style="display:none;">
    <input type="hidden" name="action" value="restore_landing_content">
</form>

<!-- Type-to-confirm reset modal -->
<div class="lc-modal-overlay" id="lcResetModal" role="dialog" aria-modal="true">
    <div class="lc-modal">
        <div class="lc-modal__head">
            <span class="lc-modal__title"><i class="fas fa-triangle-exclamation" style="color:#dc2626;"></i> ยืนยันการลบเนื้อหา</span>
            <button type="button" class="lc-modal__close" data-close-modal="lcResetModal">&times;</button>
        </div>
        <div class="lc-modal__body">
            <p style="color:#475569; font-size:.9rem; line-height:1.5;">
                การกระทำนี้จะ <strong style="color:#dc2626;">ลบเนื้อหาที่แก้ไขทั้งหมด</strong> และคืนค่ากลับเป็น default
                ระบบจะ <strong>สำรองข้อมูลปัจจุบันไว้</strong> โดยอัตโนมัติ — สามารถกด "ย้อนคืนล่าสุด" เพื่อกู้คืนได้
            </p>
            <p style="color:#475569; font-size:.9rem; margin-top:10px;">
                เพื่อยืนยัน พิมพ์ <code style="background:#fef2f2; color:#dc2626; padding:2px 8px; border-radius:4px; font-weight:700;"><?= $h($shopName ?: 'RESET') ?></code> ในช่องด้านล่าง:
            </p>
            <input type="text" class="lc-reset-input" id="lcResetConfirmInput" autocomplete="off" placeholder="พิมพ์เพื่อยืนยัน...">
        </div>
        <div class="lc-modal__foot">
            <button type="button" class="lc-btn-cancel" data-close-modal="lcResetModal">ยกเลิก</button>
            <button type="button" class="lc-btn-danger" id="lcResetConfirmBtn" disabled>ลบและคืน default</button>
        </div>
    </div>
</div>

<!-- Icon picker modal -->
<div class="lc-modal-overlay" id="lcIconModal" role="dialog" aria-modal="true">
    <div class="lc-modal">
        <div class="lc-modal__head">
            <span class="lc-modal__title"><i class="fas fa-icons"></i> เลือกไอคอน</span>
            <button type="button" class="lc-modal__close" data-close-modal="lcIconModal">&times;</button>
        </div>
        <div class="lc-modal__body" id="lcIconModalBody"></div>
        <div class="lc-modal__foot">
            <button type="button" class="lc-btn-cancel" data-close-modal="lcIconModal">ปิด</button>
        </div>
    </div>
</div>

<script>
(function(){
    'use strict';

    const REPS = {
        hero: {
            jsonId: 'json_hero_trust_items',
            data: <?= json_encode($content['hero_trust_items'], JSON_UNESCAPED_UNICODE) ?>,
            template: (it, i) => `
                <div class="lc-item" draggable="true" data-i="${i}">
                  <div class="lc-item__head">
                    <span class="lc-item__handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="lc-item__title">Pill #${i+1}</span>
                    <span class="lc-item__ctrls">
                      <button type="button" class="lc-btn-move" data-move="up" title="เลื่อนขึ้น">▲</button>
                      <button type="button" class="lc-btn-move" data-move="down" title="เลื่อนลง">▼</button>
                      <button type="button" class="lc-btn-icon" data-remove><i class="fas fa-trash"></i></button>
                    </span>
                  </div>
                  <div class="lc-row">
                    <div><label class="lc-label">ไอคอน</label><input class="lc-input" data-k="icon" data-icon="1" value="${esc(it.icon||'')}" placeholder="fa-user-md"></div>
                    <div><label class="lc-label">ข้อความ</label><input class="lc-input" data-k="label" value="${esc(it.label||'')}" placeholder="เภสัชกรดูแล"></div>
                  </div>
                </div>`,
            empty: { icon:'fa-star', label:'' }
        },
        about: {
            jsonId: 'json_about_paragraphs',
            data: <?= json_encode($content['about_paragraphs'], JSON_UNESCAPED_UNICODE) ?>,
            template: (it, i) => `
                <div class="lc-item" draggable="true" data-i="${i}">
                  <div class="lc-item__head">
                    <span class="lc-item__handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="lc-item__title">ย่อหน้า #${i+1}</span>
                    <span class="lc-item__ctrls">
                      <button type="button" class="lc-btn-move" data-move="up" title="เลื่อนขึ้น">▲</button>
                      <button type="button" class="lc-btn-move" data-move="down" title="เลื่อนลง">▼</button>
                      <button type="button" class="lc-btn-icon" data-remove><i class="fas fa-trash"></i></button>
                    </span>
                  </div>
                  <textarea class="lc-textarea" data-k="_string" data-shop="1" rows="3">${esc(typeof it === 'string' ? it : (it.text||''))}</textarea>
                  <span class="lc-chip-shop" data-insert-shop-target><i class="fas fa-store"></i> ใส่ {shop}</span>
                </div>`,
            isStringList: true,
            empty: ''
        },
        features: {
            jsonId: 'json_features_cards',
            data: <?= json_encode($content['features_cards'], JSON_UNESCAPED_UNICODE) ?>,
            template: (it, i) => `
                <div class="lc-item" draggable="true" data-i="${i}">
                  <div class="lc-item__head">
                    <span class="lc-item__handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="lc-item__title">การ์ด #${i+1}</span>
                    <span class="lc-item__ctrls">
                      <button type="button" class="lc-btn-move" data-move="up" title="เลื่อนขึ้น">▲</button>
                      <button type="button" class="lc-btn-move" data-move="down" title="เลื่อนลง">▼</button>
                      <button type="button" class="lc-btn-icon" data-remove><i class="fas fa-trash"></i></button>
                    </span>
                  </div>
                  <div class="lc-row lc-row--3">
                    <div><label class="lc-label">ไอคอน</label><input class="lc-input" data-k="icon" data-icon="1" value="${esc(it.icon||'')}" placeholder="fa-bolt"></div>
                    <div><label class="lc-label">หัวข้อ</label><input class="lc-input" data-k="title" value="${esc(it.title||'')}"></div>
                    <div><label class="lc-label">ตัวอย่าง</label>
                      <div class="lc-preview"><i class="${normIcon(it.icon||'fa-star')}"></i>${esc(it.title||'-')}</div>
                    </div>
                  </div>
                  <div style="margin-top:8px;">
                    <label class="lc-label">รายละเอียด</label>
                    <textarea class="lc-textarea" data-k="desc" rows="2">${esc(it.desc||'')}</textarea>
                  </div>
                </div>`,
            empty: { icon:'fa-star', title:'หัวข้อใหม่', desc:'รายละเอียด...' }
        },
        services: {
            jsonId: 'json_services_cards',
            data: <?= json_encode($content['services_cards'], JSON_UNESCAPED_UNICODE) ?>,
            template: (it, i) => `
                <div class="lc-item" draggable="true" data-i="${i}">
                  <div class="lc-item__head">
                    <span class="lc-item__handle"><i class="fas fa-grip-vertical"></i></span>
                    <span class="lc-item__title">บริการ #${i+1}</span>
                    <span class="lc-item__ctrls">
                      <button type="button" class="lc-btn-move" data-move="up" title="เลื่อนขึ้น">▲</button>
                      <button type="button" class="lc-btn-move" data-move="down" title="เลื่อนลง">▼</button>
                      <button type="button" class="lc-btn-icon" data-remove><i class="fas fa-trash"></i></button>
                    </span>
                  </div>
                  <div class="lc-row lc-row--3">
                    <div><label class="lc-label">ไอคอน</label><input class="lc-input" data-k="icon" data-icon="1" value="${esc(it.icon||'')}" placeholder="fa-shopping-bag"></div>
                    <div><label class="lc-label">หัวข้อ</label><input class="lc-input" data-k="title" value="${esc(it.title||'')}"></div>
                    <div><label class="lc-label">ข้อความปุ่ม</label><input class="lc-input" data-k="action" value="${esc(it.action||'')}" placeholder="เลือกสินค้า"></div>
                  </div>
                  <div class="lc-row" style="margin-top:8px;">
                    <div><label class="lc-label">รายละเอียด</label><textarea class="lc-textarea" data-k="desc" rows="2">${esc(it.desc||'')}</textarea></div>
                    <div><label class="lc-label">LIFF Path (เช่น #/shop)</label><input class="lc-input" data-k="liff_path" value="${esc(it.liff_path||'')}" placeholder="#/shop"></div>
                  </div>
                </div>`,
            empty: { icon:'fa-circle', title:'บริการใหม่', desc:'...', action:'ดูเพิ่ม', liff_path:'#/' }
        }
    };

    // Normalize an FA class string: ensure it has a prefix (fas/fab/far/fal).
    // If the user types just "fa-pills", assume "fas fa-pills" for preview/render.
    function normIcon(s) {
        s = String(s||'').trim();
        if (!s) return 'fas fa-star';
        // Already has a known prefix?
        if (/\b(fas|fab|far|fal|fad)\b/.test(s)) return s;
        // Plain "fa-xxx" → prefix with "fas"
        if (/^fa-/.test(s)) return 'fas ' + s;
        return 'fas fa-' + s.replace(/^fa-?/, '');
    }

    function esc(s){ return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function render(name){
        const cfg = REPS[name];
        const root = document.getElementById('rep-' + name);
        root.innerHTML = cfg.data.map((it, i) => cfg.template(it, i)).join('');
        attachItemEvents(name);
    }

    function collect(name){
        const cfg = REPS[name];
        const items = document.querySelectorAll('#rep-' + name + ' .lc-item');
        cfg.data = Array.from(items).map(node => {
            if (cfg.isStringList) {
                return node.querySelector('[data-k="_string"]').value;
            }
            const obj = {};
            node.querySelectorAll('[data-k]').forEach(inp => { obj[inp.dataset.k] = inp.value; });
            return obj;
        });
        document.getElementById(cfg.jsonId).value = JSON.stringify(cfg.data);
    }

    function attachItemEvents(name){
        const root = document.getElementById('rep-' + name);
        root.querySelectorAll('[data-remove]').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.closest('.lc-item').remove();
                collect(name);
                renumber(name);
                refreshMoveButtons(name);
            });
        });
        // ▲▼ move buttons (touch-friendly fallback for drag-drop)
        root.querySelectorAll('[data-move]').forEach(btn => {
            btn.addEventListener('click', () => {
                const item = btn.closest('.lc-item');
                const dir = btn.dataset.move;
                const sibling = dir === 'up' ? item.previousElementSibling : item.nextElementSibling;
                if (!sibling) return;
                if (dir === 'up') item.parentNode.insertBefore(item, sibling);
                else item.parentNode.insertBefore(sibling, item);
                collect(name);
                renumber(name);
                refreshMoveButtons(name);
            });
        });
        root.querySelectorAll('input, textarea').forEach(inp => {
            inp.addEventListener('input', () => collect(name));
        });
        // Repeater {shop} chip
        root.querySelectorAll('[data-insert-shop-target]').forEach(chip => {
            chip.addEventListener('click', () => {
                const ta = chip.parentNode.querySelector('[data-shop="1"]');
                if (ta) insertAtCursor(ta, '{shop}');
                collect(name);
            });
        });
        // Icon picker buttons inside repeater
        attachIconPickers(root);

        let dragSrc = null;
        root.querySelectorAll('.lc-item').forEach(item => {
            item.addEventListener('dragstart', e => {
                dragSrc = item;
                item.classList.add('lc-dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('lc-dragging');
                collect(name);
                renumber(name);
                refreshMoveButtons(name);
            });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                if (dragSrc && dragSrc !== item) {
                    const rect = item.getBoundingClientRect();
                    const after = (e.clientY - rect.top) > rect.height / 2;
                    item.parentNode.insertBefore(dragSrc, after ? item.nextSibling : item);
                }
            });
        });
        refreshMoveButtons(name);
    }

    function refreshMoveButtons(name){
        const items = document.querySelectorAll('#rep-' + name + ' .lc-item');
        items.forEach((it, i) => {
            const up = it.querySelector('[data-move="up"]');
            const dn = it.querySelector('[data-move="down"]');
            if (up) up.disabled = (i === 0);
            if (dn) dn.disabled = (i === items.length - 1);
        });
    }

    function insertAtCursor(el, text){
        if (!el) return;
        el.focus();
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        el.value = el.value.slice(0, start) + text + el.value.slice(end);
        const pos = start + text.length;
        try { el.setSelectionRange(pos, pos); } catch (e) {}
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function renumber(name){
        const titles = document.querySelectorAll('#rep-' + name + ' .lc-item__title');
        const labels = { hero:'Pill', about:'ย่อหน้า', features:'การ์ด', services:'บริการ' };
        titles.forEach((el, i) => { el.textContent = labels[name] + ' #' + (i+1); });
    }

    document.querySelectorAll('[data-rep]').forEach(btn => {
        btn.addEventListener('click', () => {
            const name = btn.dataset.rep;
            const cfg = REPS[name];
            cfg.data.push(typeof cfg.empty === 'object' ? Object.assign({}, cfg.empty) : cfg.empty);
            render(name);
            collect(name);
        });
    });

    Object.keys(REPS).forEach(render);
    Object.keys(REPS).forEach(collect);

    // ============================================================
    // Top-level {shop} insert chips (non-repeater fields)
    // ============================================================
    document.querySelectorAll('[data-insert-shop]').forEach(chip => {
        chip.addEventListener('click', () => {
            const name = chip.dataset.insertShop;
            const el = document.querySelector('[name="' + name + '"]');
            if (el) insertAtCursor(el, '{shop}');
        });
    });

    // ============================================================
    // Icon picker — ~40 common FA icons by category
    // ============================================================
    const ICONS = {
        'สุขภาพ / ยา': [
            'fas fa-pills', 'fas fa-prescription-bottle', 'fas fa-prescription-bottle-medical',
            'fas fa-user-md', 'fas fa-user-doctor', 'fas fa-stethoscope',
            'fas fa-briefcase-medical', 'fas fa-hospital', 'fas fa-heart-pulse',
            'fas fa-hand-holding-medical', 'fas fa-syringe', 'fas fa-capsules'
        ],
        'การค้า / ออเดอร์': [
            'fas fa-shopping-bag', 'fas fa-shopping-cart', 'fas fa-cart-shopping',
            'fas fa-store', 'fas fa-receipt', 'fas fa-tag',
            'fas fa-truck', 'fas fa-truck-fast', 'fas fa-box',
            'fas fa-credit-card', 'fas fa-coins', 'fas fa-money-bill'
        ],
        'การสื่อสาร': [
            'fab fa-line', 'fab fa-facebook', 'fab fa-instagram',
            'fas fa-phone', 'fas fa-comments', 'fas fa-message',
            'fas fa-envelope', 'fas fa-video', 'fas fa-headset'
        ],
        'ทั่วไป': [
            'fas fa-star', 'fas fa-heart', 'fas fa-bolt',
            'fas fa-shield-alt', 'fas fa-circle-check', 'fas fa-clock',
            'fas fa-calendar-check', 'fas fa-location-dot', 'fas fa-bell',
            'fas fa-fire', 'fas fa-thumbs-up', 'fas fa-award'
        ]
    };

    let activePickerTarget = null;
    function openIconPicker(targetInput){
        activePickerTarget = targetInput;
        const body = document.getElementById('lcIconModalBody');
        let html = '';
        Object.keys(ICONS).forEach(cat => {
            html += `<div class="lc-modal__cat-title">${esc(cat)}</div><div class="lc-icon-grid">`;
            ICONS[cat].forEach(cls => {
                html += `<div class="lc-icon-cell" data-icon-cls="${esc(cls)}"><i class="${cls}"></i><span class="lc-icon-cell__label">${esc(cls.replace(/^(fas|fab|far|fal)\s+fa-/, ''))}</span></div>`;
            });
            html += '</div>';
        });
        body.innerHTML = html;
        body.querySelectorAll('[data-icon-cls]').forEach(cell => {
            cell.addEventListener('click', () => {
                if (activePickerTarget) {
                    activePickerTarget.value = cell.dataset.iconCls;
                    activePickerTarget.dispatchEvent(new Event('input', { bubbles: true }));
                }
                closeModal('lcIconModal');
            });
        });
        openModal('lcIconModal');
    }

    function attachIconPickers(scope){
        scope.querySelectorAll('input[data-icon="1"]').forEach(inp => {
            if (inp.dataset.iconBound) return;
            inp.dataset.iconBound = '1';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'lc-btn-pick';
            btn.title = 'เลือกไอคอนจากรายการ';
            btn.innerHTML = '<i class="fas fa-magnifying-glass"></i>';
            btn.addEventListener('click', () => openIconPicker(inp));
            // Wrap input + button
            const wrap = document.createElement('div');
            wrap.className = 'lc-icon-field';
            inp.parentNode.insertBefore(wrap, inp);
            wrap.appendChild(inp);
            wrap.appendChild(btn);
        });
    }
    attachIconPickers(document);

    // ============================================================
    // Modal helpers
    // ============================================================
    function openModal(id){ document.getElementById(id).classList.add('lc-open'); }
    function closeModal(id){ document.getElementById(id).classList.remove('lc-open'); }
    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.dataset.closeModal));
    });
    document.querySelectorAll('.lc-modal-overlay').forEach(ov => {
        ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('lc-open'); });
    });

    // ============================================================
    // Type-to-confirm reset
    // ============================================================
    const RESET_PHRASE = <?= json_encode($shopName ?: 'RESET', JSON_UNESCAPED_UNICODE) ?>;
    const resetBtn = document.getElementById('lcResetBtn');
    const resetConfirmInput = document.getElementById('lcResetConfirmInput');
    const resetConfirmBtn = document.getElementById('lcResetConfirmBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            resetConfirmInput.value = '';
            resetConfirmBtn.disabled = true;
            openModal('lcResetModal');
            setTimeout(() => resetConfirmInput.focus(), 50);
        });
    }
    if (resetConfirmInput) {
        resetConfirmInput.addEventListener('input', () => {
            resetConfirmBtn.disabled = (resetConfirmInput.value.trim() !== RESET_PHRASE);
        });
    }
    if (resetConfirmBtn) {
        resetConfirmBtn.addEventListener('click', () => {
            // Skip beforeunload — intentional submit
            window.__lcSkipUnload = true;
            try { localStorage.removeItem('landing_content_draft'); } catch(e){}
            document.getElementById('resetField').value = '1';
            document.getElementById('landingContentForm').submit();
        });
    }

    // ============================================================
    // Restore latest backup
    // ============================================================
    const restoreBtn = document.getElementById('lcRestoreBtn');
    if (restoreBtn) {
        restoreBtn.addEventListener('click', () => {
            if (!confirm('ย้อนคืน snapshot ล่าสุดของเนื้อหา? ค่าปัจจุบันจะถูกแทนที่')) return;
            window.__lcSkipUnload = true;
            document.getElementById('lcRestoreForm').submit();
        });
    }

    // ============================================================
    // Submit-clear localStorage + skip unload warn
    // ============================================================
    document.getElementById('landingContentForm').addEventListener('submit', () => {
        window.__lcSkipUnload = true;
        try { localStorage.removeItem('landing_content_draft'); } catch(e){}
    });

    // ============================================================
    // beforeunload warn + localStorage autosave
    // ============================================================
    const form = document.getElementById('landingContentForm');
    let dirty = false;
    const DRAFT_KEY = 'landing_content_draft';
    const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000;

    function snapshotForm(){
        const data = {};
        form.querySelectorAll('input[name], textarea[name]').forEach(el => {
            if (el.type === 'hidden') {
                // capture repeater JSON too
                data[el.name] = el.value;
            } else {
                data[el.name] = el.value;
            }
        });
        return data;
    }
    function applyDraft(draft){
        if (!draft || typeof draft !== 'object') return;
        Object.keys(draft).forEach(k => {
            const el = form.querySelector('[name="' + k + '"]');
            if (!el) return;
            el.value = draft[k];
        });
        // Re-render repeaters from restored JSON
        Object.keys(REPS).forEach(name => {
            const json = document.getElementById(REPS[name].jsonId).value;
            try {
                const parsed = JSON.parse(json);
                if (Array.isArray(parsed)) {
                    REPS[name].data = parsed;
                    render(name);
                }
            } catch(e){}
        });
        // Re-attach icon pickers for any new inputs
        attachIconPickers(document);
    }

    // Draft restore banner
    try {
        const raw = localStorage.getItem(DRAFT_KEY);
        if (raw) {
            const obj = JSON.parse(raw);
            if (obj && obj.ts && (Date.now() - obj.ts) < DRAFT_MAX_AGE_MS && obj.fields) {
                const banner = document.createElement('div');
                banner.className = 'lc-draft-banner';
                const when = new Date(obj.ts).toLocaleString('th-TH');
                banner.innerHTML = `<span><i class="fas fa-clock-rotate-left"></i> พบฉบับร่างที่ยังไม่ได้บันทึก (${when}) — กดเพื่อเรียกคืน</span>
                    <span><button type="button" class="lc-draft-yes">เรียกคืน</button>
                          <button type="button" class="lc-draft-no">ทิ้ง</button></span>`;
                form.insertBefore(banner, form.firstChild);
                banner.querySelector('.lc-draft-yes').addEventListener('click', () => {
                    applyDraft(obj.fields);
                    banner.remove();
                });
                banner.querySelector('.lc-draft-no').addEventListener('click', () => {
                    try { localStorage.removeItem(DRAFT_KEY); } catch(e){}
                    banner.remove();
                });
            } else if (raw) {
                // stale or malformed — purge
                localStorage.removeItem(DRAFT_KEY);
            }
        }
    } catch(e){}

    // Debounced autosave
    let saveTimer = null;
    function scheduleAutosave(){
        dirty = true;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            try {
                localStorage.setItem(DRAFT_KEY, JSON.stringify({ ts: Date.now(), fields: snapshotForm() }));
            } catch(e){}
        }, 500);
    }
    form.addEventListener('input', scheduleAutosave);
    form.addEventListener('change', scheduleAutosave);

    window.addEventListener('beforeunload', e => {
        if (window.__lcSkipUnload) return;
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
        return '';
    });

    // ============================================================
    // Sticky bar: dim while editing textarea (mobile keyboard overlap)
    // ============================================================
    const saveBar = document.getElementById('lcSaveBar');
    document.addEventListener('focusin', e => {
        if (e.target.tagName === 'TEXTAREA') saveBar.classList.add('lc-save-bar--dimmed');
    });
    document.addEventListener('focusout', e => {
        if (e.target.tagName === 'TEXTAREA') saveBar.classList.remove('lc-save-bar--dimmed');
    });
})();
</script>
