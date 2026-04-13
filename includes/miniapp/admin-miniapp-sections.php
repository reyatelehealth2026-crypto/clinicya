<?php
/**
 * Mini App Sections Tab - Admin
 * CRUD for miniapp_home_sections (Flash Sale, Horizontal Scroll, Grid, Banner List)
 */

$sections = $service->getAllSectionsForAdmin();
$editSection = null;
if (isset($_GET['edit_section'])) {
    $editSection = $service->getSectionById((int) $_GET['edit_section']);
}

$sectionStyles = [
    'flash_sale' => 'Flash Sale (countdown + horizontal)',
    'horizontal_scroll' => 'Horizontal Scroll',
    'grid' => 'Grid (2 คอลัมน์)',
    'banner_list' => 'Banner List',
];
?>

<div class="miniapp-section-header">
    <div>
        <h3 style="margin:0; font-size:18px; font-weight:700; color:#1e293b;">Sections</h3>
        <p style="margin:4px 0 0; font-size:13px; color:#94a3b8;">จัดการกลุ่มสินค้า/โปรโมชันสำหรับหน้า Home และ Shop</p>
    </div>
    <button class="miniapp-btn miniapp-btn-primary" onclick="document.getElementById('sectionFormModal').classList.add('active')">
        <i class="fas fa-plus"></i> เพิ่ม Section
    </button>
</div>

<?php if (empty($sections)): ?>
<div style="text-align:center; padding:40px; color:#94a3b8;">
    <i class="fas fa-layer-group" style="font-size:48px; margin-bottom:12px;"></i>
    <p>ยังไม่มี Section กดปุ่ม "เพิ่ม Section" เพื่อเริ่มต้น</p>
</div>
<?php else: ?>
<?php foreach ($sections as $section): ?>
<div class="miniapp-card <?= $section['is_active'] ? '' : 'inactive' ?>" <?= !empty($section['bg_color']) ? 'style="border-left:4px solid ' . htmlspecialchars($section['bg_color']) . '"' : '' ?>>
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
        <div style="flex:1; min-width:0;">
            <div style="display:flex; align-items:center; gap:8px;">
                <?php if (!empty($section['icon_url'])): ?>
                <img src="<?= htmlspecialchars($section['icon_url']) ?>" alt="" style="width:28px; height:28px; border-radius:6px;">
                <?php endif; ?>
                <strong style="font-size:15px;"><?= htmlspecialchars($section['title']) ?></strong>
            </div>

            <?php if (!empty($section['subtitle'])): ?>
            <p style="margin:4px 0 0; font-size:13px; color:#64748b;"><?= htmlspecialchars($section['subtitle']) ?></p>
            <?php endif; ?>

            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                <span class="miniapp-badge <?= $section['is_active'] ? 'miniapp-badge-active' : 'miniapp-badge-inactive' ?>">
                    <?= $section['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
                <?php if (isset($section['surface'])): ?>
                <span class="miniapp-badge" style="background:#ecfeff; color:#0f766e;">
                    <?= htmlspecialchars($section['surface']) ?>
                </span>
                <?php endif; ?>
                <span class="miniapp-badge miniapp-badge-style"><?= htmlspecialchars($section['section_style']) ?></span>
                <span class="miniapp-badge" style="background:#f1f5f9; color:#64748b;">
                    Key: <?= htmlspecialchars($section['section_key']) ?>
                </span>
                <span class="miniapp-badge" style="background:#e0f2fe; color:#0284c7;">
                    <?= $service->getProductCount((int) $section['id']) ?> สินค้า
                </span>
            </div>

            <?php if (!empty($section['countdown_ends_at'])): ?>
            <div style="margin-top:6px; font-size:12px; color:#f59e0b;">
                <i class="fas fa-clock"></i> Countdown: <?= htmlspecialchars($section['countdown_ends_at']) ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($section['start_date']) || !empty($section['end_date'])): ?>
            <div style="margin-top:4px; font-size:12px; color:#94a3b8;">
                <i class="fas fa-calendar"></i> <?= htmlspecialchars($section['start_date'] ?: '∞') ?> → <?= htmlspecialchars($section['end_date'] ?: '∞') ?>
            </div>
            <?php endif; ?>
        </div>

        <div style="display:flex; gap:6px; flex-shrink:0;">
            <a href="?tab=products&filter_section=<?= (int) $section['id'] ?>" class="miniapp-btn miniapp-btn-outline miniapp-btn-sm">
                <i class="fas fa-box-open"></i> สินค้า
            </a>
            <a href="?tab=sections&edit_section=<?= (int) $section['id'] ?>" class="miniapp-btn miniapp-btn-outline miniapp-btn-sm">
                <i class="fas fa-edit"></i>
            </a>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="toggle_section">
                <input type="hidden" name="id" value="<?= (int) $section['id'] ?>">
                <button type="submit" class="miniapp-btn miniapp-btn-outline miniapp-btn-sm">
                    <i class="fas fa-<?= $section['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                </button>
            </form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('ลบ Section นี้? สินค้าทั้งหมดใน section นี้จะถูกลบด้วย')">
                <input type="hidden" name="action" value="delete_section">
                <input type="hidden" name="id" value="<?= (int) $section['id'] ?>">
                <button type="submit" class="miniapp-btn miniapp-btn-danger miniapp-btn-sm">
                    <i class="fas fa-trash"></i>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div id="sectionFormModal" class="miniapp-modal <?= $editSection ? 'active' : '' ?>">
    <div class="miniapp-modal-content">
        <div class="miniapp-modal-header">
            <h3 style="margin:0;"><?= $editSection ? 'แก้ไข Section' : 'เพิ่ม Section ใหม่' ?></h3>
            <button class="miniapp-modal-close" onclick="this.closest('.miniapp-modal').classList.remove('active')">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="<?= $editSection ? 'update_section' : 'create_section' ?>">
            <?php if ($editSection): ?>
            <input type="hidden" name="id" value="<?= (int) $editSection['id'] ?>">
            <?php endif; ?>

            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>Section Key *</label>
                    <input type="text" name="section_key" value="<?= htmlspecialchars($editSection['section_key'] ?? '') ?>" required placeholder="เช่น shop_weekly_deals">
                    <p class="miniapp-hint">ใช้ภาษาอังกฤษ + underscore และต้องไม่ซ้ำกัน</p>
                </div>
                <div class="miniapp-form-group">
                    <label>รูปแบบ</label>
                    <select name="section_style" id="sectionStyle" onchange="toggleCountdown(this)">
                        <?php foreach ($sectionStyles as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($editSection['section_style'] ?? 'horizontal_scroll') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="miniapp-form-group">
                <label>ชื่อ Section *</label>
                <input type="text" name="title" value="<?= htmlspecialchars($editSection['title'] ?? '') ?>" required placeholder="เช่น โปรโมชันประจำสัปดาห์">
            </div>

            <div class="miniapp-form-group">
                <label>คำบรรยาย</label>
                <input type="text" name="subtitle" value="<?= htmlspecialchars($editSection['subtitle'] ?? '') ?>" placeholder="เช่น คัดสินค้าที่พร้อมปิดการขายเร็ว">
            </div>

            <div class="miniapp-form-row-3">
                <div class="miniapp-form-group">
                    <label>Surface</label>
                    <select name="surface">
                        <option value="home" <?= ($editSection['surface'] ?? 'home') === 'home' ? 'selected' : '' ?>>Home</option>
                        <option value="shop" <?= ($editSection['surface'] ?? '') === 'shop' ? 'selected' : '' ?>>Shop</option>
                    </select>
                </div>
                <div class="miniapp-form-group">
                    <label>สีพื้นหลัง</label>
                    <input type="text" name="bg_color" value="<?= htmlspecialchars($editSection['bg_color'] ?? '') ?>" placeholder="#8B0000">
                </div>
                <div class="miniapp-form-group">
                    <label>สีตัวอักษร</label>
                    <input type="text" name="text_color" value="<?= htmlspecialchars($editSection['text_color'] ?? '') ?>" placeholder="#FFFFFF">
                </div>
            </div>

            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>URL ไอคอน / โลโก้</label>
                    <input type="text" name="icon_url" value="<?= htmlspecialchars($editSection['icon_url'] ?? '') ?>" placeholder="https://...">
                </div>
                <div class="miniapp-form-group">
                    <label>บทบาท</label>
                    <input type="text" value="<?= ($editSection['surface'] ?? 'home') === 'shop' ? 'แสดงใน storefront หน้า shop' : 'แสดงในหน้า home' ?>" disabled>
                </div>
            </div>

            <div class="miniapp-form-group" id="countdownField" style="<?= ($editSection['section_style'] ?? '') !== 'flash_sale' ? 'display:none' : '' ?>">
                <label>นับถอยหลังถึง (Flash Sale)</label>
                <input type="datetime-local" name="countdown_ends_at" value="<?= !empty($editSection['countdown_ends_at']) ? date('Y-m-d\TH:i', strtotime($editSection['countdown_ends_at'])) : '' ?>">
                <p class="miniapp-hint">ใช้สำหรับ section แบบ flash sale</p>
            </div>

            <div class="miniapp-form-row-3">
                <div class="miniapp-form-group">
                    <label>ลำดับ</label>
                    <input type="number" name="display_order" value="<?= (int) ($editSection['display_order'] ?? 0) ?>" min="0">
                </div>
                <div class="miniapp-form-group">
                    <label>เริ่มแสดง</label>
                    <input type="datetime-local" name="start_date" value="<?= !empty($editSection['start_date']) ? date('Y-m-d\TH:i', strtotime($editSection['start_date'])) : '' ?>">
                </div>
                <div class="miniapp-form-group">
                    <label>หยุดแสดง</label>
                    <input type="datetime-local" name="end_date" value="<?= !empty($editSection['end_date']) ? date('Y-m-d\TH:i', strtotime($editSection['end_date'])) : '' ?>">
                </div>
            </div>

            <div class="miniapp-form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= ($editSection['is_active'] ?? 1) ? 'checked' : '' ?>>
                    เปิดใช้งาน
                </label>
            </div>

            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px;">
                <button type="button" class="miniapp-btn miniapp-btn-outline" onclick="this.closest('.miniapp-modal').classList.remove('active')">ยกเลิก</button>
                <button type="submit" class="miniapp-btn miniapp-btn-primary">
                    <i class="fas fa-save"></i> <?= $editSection ? 'บันทึก' : 'เพิ่ม Section' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleCountdown(select) {
    const field = document.getElementById('countdownField');
    if (field) {
        field.style.display = select.value === 'flash_sale' ? '' : 'none';
    }
}
</script>
