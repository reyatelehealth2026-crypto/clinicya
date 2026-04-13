<?php
/**
 * Mini App Banners Tab - Admin
 * CRUD for miniapp_banners with Universal Link support
 */

$banners = $service->getAllBannersForAdmin();
$editBanner = null;
if (isset($_GET['edit_banner'])) {
    $editBanner = $service->getBannerById((int) $_GET['edit_banner']);
}
?>

<div class="miniapp-section-header">
    <div>
        <h3 style="margin:0; font-size:18px; font-weight:700; color:#1e293b;">แบนเนอร์สไลด์</h3>
        <p style="margin:4px 0 0; font-size:13px; color:#94a3b8;">จัดการแบนเนอร์สำหรับทั้งหน้า Home และ storefront หน้า Shop</p>
    </div>
    <button class="miniapp-btn miniapp-btn-primary" onclick="document.getElementById('bannerFormModal').classList.add('active')">
        <i class="fas fa-plus"></i> เพิ่มแบนเนอร์
    </button>
</div>

<?php if (empty($banners)): ?>
<div style="text-align:center; padding:40px; color:#94a3b8;">
    <i class="fas fa-images" style="font-size:48px; margin-bottom:12px;"></i>
    <p>ยังไม่มีแบนเนอร์ กดปุ่ม "เพิ่มแบนเนอร์" เพื่อเริ่มต้น</p>
</div>
<?php else: ?>
<?php foreach ($banners as $banner): ?>
<div class="miniapp-card <?= $banner['is_active'] ? '' : 'inactive' ?>">
    <div style="display:flex; gap:16px; align-items:flex-start;">
        <?php if (!empty($banner['image_url'])): ?>
        <img src="<?= htmlspecialchars($banner['image_url']) ?>" alt="" class="miniapp-preview-img" style="width:120px; object-fit:cover;">
        <?php endif; ?>

        <div style="flex:1; min-width:0;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                <div>
                    <strong style="font-size:15px;"><?= htmlspecialchars($banner['title'] ?: '(ไม่มีชื่อ)') ?></strong>
                    <?php if (!empty($banner['subtitle'])): ?>
                    <p style="margin:2px 0 0; font-size:13px; color:#64748b;"><?= htmlspecialchars($banner['subtitle']) ?></p>
                    <?php endif; ?>
                </div>

                <div style="display:flex; gap:6px; flex-shrink:0; flex-wrap:wrap; justify-content:flex-end;">
                    <span class="miniapp-badge <?= $banner['is_active'] ? 'miniapp-badge-active' : 'miniapp-badge-inactive' ?>">
                        <?= $banner['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                    <?php if (isset($banner['surface'])): ?>
                    <span class="miniapp-badge" style="background:#ecfeff; color:#0f766e;">
                        <?= htmlspecialchars($banner['surface']) ?>
                    </span>
                    <?php endif; ?>
                    <span class="miniapp-badge miniapp-badge-style"><?= htmlspecialchars($banner['position']) ?></span>
                </div>
            </div>

            <div style="margin-top:8px; font-size:12px; color:#94a3b8; display:flex; gap:16px; flex-wrap:wrap;">
                <span><i class="fas fa-link"></i> <?= htmlspecialchars($banner['link_type'] ?: 'none') ?><?= !empty($banner['link_value']) ? ': ' . htmlspecialchars(mb_strimwidth($banner['link_value'], 0, 40, '...')) : '' ?></span>
                <span><i class="fas fa-sort"></i> ลำดับ: <?= (int) $banner['display_order'] ?></span>
                <?php if (!empty($banner['start_date'])): ?>
                <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($banner['start_date']) ?> → <?= htmlspecialchars($banner['end_date'] ?: '∞') ?></span>
                <?php endif; ?>
            </div>

            <div style="margin-top:10px; display:flex; gap:6px;">
                <a href="?tab=banners&edit_banner=<?= (int) $banner['id'] ?>" class="miniapp-btn miniapp-btn-outline miniapp-btn-sm">
                    <i class="fas fa-edit"></i> แก้ไข
                </a>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_banner">
                    <input type="hidden" name="id" value="<?= (int) $banner['id'] ?>">
                    <button type="submit" class="miniapp-btn miniapp-btn-outline miniapp-btn-sm">
                        <i class="fas fa-<?= $banner['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                        <?= $banner['is_active'] ? 'ปิด' : 'เปิด' ?>
                    </button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('ต้องการลบแบนเนอร์นี้?')">
                    <input type="hidden" name="action" value="delete_banner">
                    <input type="hidden" name="id" value="<?= (int) $banner['id'] ?>">
                    <button type="submit" class="miniapp-btn miniapp-btn-danger miniapp-btn-sm">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div id="bannerFormModal" class="miniapp-modal <?= $editBanner ? 'active' : '' ?>">
    <div class="miniapp-modal-content">
        <div class="miniapp-modal-header">
            <h3 style="margin:0;"><?= $editBanner ? 'แก้ไขแบนเนอร์' : 'เพิ่มแบนเนอร์ใหม่' ?></h3>
            <button class="miniapp-modal-close" onclick="this.closest('.miniapp-modal').classList.remove('active')">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="<?= $editBanner ? 'update_banner' : 'create_banner' ?>">
            <?php if ($editBanner): ?>
            <input type="hidden" name="id" value="<?= (int) $editBanner['id'] ?>">
            <?php endif; ?>

            <div class="miniapp-form-group">
                <label>ชื่อแบนเนอร์</label>
                <input type="text" name="title" value="<?= htmlspecialchars($editBanner['title'] ?? '') ?>" placeholder="เช่น โปรโมชันประจำสัปดาห์">
            </div>

            <div class="miniapp-form-group">
                <label>คำบรรยาย</label>
                <input type="text" name="subtitle" value="<?= htmlspecialchars($editBanner['subtitle'] ?? '') ?>" placeholder="เช่น ลดสูงสุด 60%">
            </div>

            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>URL รูปภาพ *</label>
                    <input type="text" name="image_url" value="<?= htmlspecialchars($editBanner['image_url'] ?? '') ?>" required placeholder="https://...">
                    <p class="miniapp-hint">รูปหลักสำหรับ desktop / tablet</p>
                </div>
                <div class="miniapp-form-group">
                    <label>URL รูปภาพ Mobile</label>
                    <input type="text" name="image_mobile_url" value="<?= htmlspecialchars($editBanner['image_mobile_url'] ?? '') ?>" placeholder="https://... (optional)">
                    <p class="miniapp-hint">ถ้าไม่ระบุจะใช้รูปหลัก</p>
                </div>
            </div>

            <div class="miniapp-form-row-3">
                <div class="miniapp-form-group">
                    <label>ประเภทลิงก์</label>
                    <select name="link_type" id="bannerLinkType" onchange="toggleLinkValue(this, 'bannerLinkValue')">
                        <?php foreach ($linkTypes as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($editBanner['link_type'] ?? 'none') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="miniapp-form-group" id="bannerLinkValue">
                    <label>ค่าลิงก์</label>
                    <input type="text" name="link_value" value="<?= htmlspecialchars($editBanner['link_value'] ?? '') ?>" placeholder="URL / route / LIFF ID">
                    <p class="miniapp-hint">url: https://..., miniapp: /shop, liff: LIFF_ID, deep_link: scheme://</p>
                </div>
                <div class="miniapp-form-group">
                    <label>ข้อความ CTA</label>
                    <input type="text" name="link_label" value="<?= htmlspecialchars($editBanner['link_label'] ?? '') ?>" placeholder="ดูเพิ่มเติม">
                </div>
            </div>

            <div class="miniapp-form-row-3">
                <div class="miniapp-form-group">
                    <label>Surface</label>
                    <select name="surface">
                        <option value="home" <?= ($editBanner['surface'] ?? 'home') === 'home' ? 'selected' : '' ?>>Home</option>
                        <option value="shop" <?= ($editBanner['surface'] ?? '') === 'shop' ? 'selected' : '' ?>>Shop</option>
                    </select>
                    <p class="miniapp-hint">เลือกว่าจะใช้ banner นี้บนหน้า Home หรือ Shop</p>
                </div>
                <div class="miniapp-form-group">
                    <label>ตำแหน่ง</label>
                    <select name="position">
                        <option value="home_top" <?= ($editBanner['position'] ?? 'home_top') === 'home_top' ? 'selected' : '' ?>>Top</option>
                        <option value="home_middle" <?= ($editBanner['position'] ?? '') === 'home_middle' ? 'selected' : '' ?>>Middle</option>
                        <option value="home_bottom" <?= ($editBanner['position'] ?? '') === 'home_bottom' ? 'selected' : '' ?>>Bottom</option>
                    </select>
                </div>
                <div class="miniapp-form-group">
                    <label>ลำดับ</label>
                    <input type="number" name="display_order" value="<?= (int) ($editBanner['display_order'] ?? 0) ?>" min="0">
                </div>
            </div>

            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>สีพื้นหลัง</label>
                    <input type="text" name="bg_color" value="<?= htmlspecialchars($editBanner['bg_color'] ?? '') ?>" placeholder="#ffffff (optional)">
                </div>
                <div class="miniapp-form-group">
                    <label>บทบาท</label>
                    <input type="text" value="<?= ($editBanner['surface'] ?? 'home') === 'shop' ? 'ใช้สำหรับ promo strip หน้า shop' : 'ใช้สำหรับ hero/banner หน้า home' ?>" disabled>
                </div>
            </div>

            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>เริ่มแสดง</label>
                    <input type="datetime-local" name="start_date" value="<?= !empty($editBanner['start_date']) ? date('Y-m-d\TH:i', strtotime($editBanner['start_date'])) : '' ?>">
                    <p class="miniapp-hint">เว้นว่าง = แสดงทันที</p>
                </div>
                <div class="miniapp-form-group">
                    <label>หยุดแสดง</label>
                    <input type="datetime-local" name="end_date" value="<?= !empty($editBanner['end_date']) ? date('Y-m-d\TH:i', strtotime($editBanner['end_date'])) : '' ?>">
                    <p class="miniapp-hint">เว้นว่าง = แสดงตลอด</p>
                </div>
            </div>

            <div class="miniapp-form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= ($editBanner['is_active'] ?? 1) ? 'checked' : '' ?>>
                    เปิดใช้งาน
                </label>
            </div>

            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px;">
                <button type="button" class="miniapp-btn miniapp-btn-outline" onclick="this.closest('.miniapp-modal').classList.remove('active')">ยกเลิก</button>
                <button type="submit" class="miniapp-btn miniapp-btn-primary">
                    <i class="fas fa-save"></i> <?= $editBanner ? 'บันทึก' : 'เพิ่มแบนเนอร์' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleLinkValue(select, targetId) {
    const el = document.getElementById(targetId);
    if (el) {
        el.style.display = select.value === 'none' ? 'none' : '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const linkType = document.getElementById('bannerLinkType');
    if (linkType) {
        toggleLinkValue(linkType, 'bannerLinkValue');
    }
});
</script>
