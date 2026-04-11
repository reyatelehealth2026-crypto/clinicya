<?php
/**
 * Mini App Products Tab - Admin
 * CRUD for miniapp_home_products with full promotion metadata
 */

$filterSection = isset($_GET['filter_section']) ? (int)$_GET['filter_section'] : null;
$products = $service->getAllProductsForAdmin($filterSection);
$allSections = $service->getAllSectionsForAdmin();

$editProduct = null;
if (isset($_GET['edit_product'])) {
    $editProduct = $service->getProductById((int)$_GET['edit_product']);
}
?>

<div class="miniapp-section-header">
    <div>
        <h3 style="margin:0; font-size:18px; font-weight:700; color:#1e293b;">สินค้า</h3>
        <p style="margin:4px 0 0; font-size:13px; color:#94a3b8;">จัดการสินค้าในแต่ละ Section — ราคา, โปรโมชั่น, ลิ้งค์, badges</p>
    </div>
    <div style="display:flex; gap:8px; align-items:center;">
        <!-- Section filter -->
        <form method="GET" style="display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="tab" value="products">
            <select name="filter_section" onchange="this.form.submit()" style="padding:8px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:13px;">
                <option value="">ทุก Section</option>
                <?php foreach ($allSections as $sec): ?>
                <option value="<?= $sec['id'] ?>" <?= $filterSection == $sec['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sec['title']) ?> (<?= htmlspecialchars($sec['section_key']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <button class="miniapp-btn miniapp-btn-primary" onclick="document.getElementById('productFormModal').classList.add('active')">
            <i class="fas fa-plus"></i> เพิ่มสินค้า
        </button>
    </div>
</div>

<?php if (empty($allSections)): ?>
<div style="text-align:center; padding:40px; color:#f59e0b; background:#fffbeb; border-radius:12px;">
    <i class="fas fa-exclamation-triangle" style="font-size:32px; margin-bottom:8px;"></i>
    <p>กรุณาสร้าง Section ก่อน → <a href="?tab=sections" style="color:#7c3aed; font-weight:600;">ไปที่แท็บ Sections</a></p>
</div>
<?php elseif (empty($products)): ?>
<div style="text-align:center; padding:40px; color:#94a3b8;">
    <i class="fas fa-box-open" style="font-size:48px; margin-bottom:12px;"></i>
    <p>ยังไม่มีสินค้า<?= $filterSection ? ' ใน Section นี้' : '' ?> — กดปุ่ม "เพิ่มสินค้า" เพื่อเริ่มต้น</p>
</div>
<?php else: ?>
<?php foreach ($products as $p): ?>
<div class="miniapp-card <?= $p['is_active'] ? '' : 'inactive' ?>">
    <div style="display:flex; gap:16px; align-items:flex-start;">
        <?php if ($p['image_url']): ?>
        <img src="<?= htmlspecialchars($p['image_url']) ?>" alt="" class="miniapp-preview-img" style="width:80px; height:80px; object-fit:cover;">
        <?php endif; ?>
        <div style="flex:1; min-width:0;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                <div>
                    <strong style="font-size:14px;"><?= htmlspecialchars($p['title']) ?></strong>
                    <?php if ($p['short_description']): ?>
                    <span style="font-size:12px; color:#94a3b8; margin-left:6px;"><?= htmlspecialchars($p['short_description']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex; gap:6px; flex-shrink:0;">
                    <span class="miniapp-badge <?= $p['is_active'] ? 'miniapp-badge-active' : 'miniapp-badge-inactive' ?>">
                        <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
            </div>

            <!-- Price -->
            <div style="margin-top:6px; display:flex; gap:12px; align-items:baseline;">
                <?php if ($p['sale_price']): ?>
                <span style="font-size:16px; font-weight:700; color:#dc2626;">฿<?= number_format($p['sale_price'], 0) ?><?= $p['price_unit'] ? '<small style="font-weight:400; color:#94a3b8;"> '.$p['price_unit'].'</small>' : '' ?></span>
                <?php if ($p['original_price'] && $p['original_price'] > $p['sale_price']): ?>
                <span style="font-size:13px; color:#94a3b8; text-decoration:line-through;">฿<?= number_format($p['original_price'], 0) ?></span>
                <?php endif; ?>
                <?php if ($p['discount_percent']): ?>
                <span style="background:#fef2f2; color:#dc2626; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600;">-<?= (int)$p['discount_percent'] ?>%</span>
                <?php endif; ?>
                <?php elseif ($p['original_price']): ?>
                <span style="font-size:16px; font-weight:700; color:#1e293b;">฿<?= number_format($p['original_price'], 0) ?></span>
                <?php endif; ?>
            </div>

            <!-- Meta info -->
            <div style="margin-top:6px; font-size:12px; color:#94a3b8; display:flex; gap:12px; flex-wrap:wrap;">
                <span><i class="fas fa-layer-group"></i> <?= htmlspecialchars($p['section_title'] ?? 'N/A') ?></span>
                <span><i class="fas fa-link"></i> <?= htmlspecialchars($p['link_type'] ?: 'none') ?></span>
                <span><i class="fas fa-sort"></i> ลำดับ: <?= (int)$p['display_order'] ?></span>
                <?php
                $promoTags = json_decode($p['promotion_tags'] ?? '[]', true) ?: [];
                if (!empty($promoTags)):
                ?>
                <span><i class="fas fa-tag"></i> <?= count($promoTags) ?> โปรแท็ก</span>
                <?php endif; ?>
            </div>

            <!-- Promotion tags preview -->
            <?php if (!empty($promoTags)): ?>
            <div style="margin-top:6px; display:flex; gap:4px; flex-wrap:wrap;">
                <?php foreach ($promoTags as $tag): ?>
                <span style="background:#eff6ff; color:#2563eb; padding:2px 8px; border-radius:8px; font-size:11px;"><?= htmlspecialchars($tag) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="margin-top:10px; display:flex; gap:6px;">
                <a href="?tab=products<?= $filterSection ? '&filter_section='.$filterSection : '' ?>&edit_product=<?= $p['id'] ?>" class="miniapp-btn miniapp-btn-outline miniapp-btn-sm">
                    <i class="fas fa-edit"></i> แก้ไข
                </a>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_product">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="miniapp-btn miniapp-btn-outline miniapp-btn-sm">
                        <i class="fas fa-<?= $p['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
                    </button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('ต้องการลบสินค้านี้?')">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
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

<!-- Product Form Modal -->
<div id="productFormModal" class="miniapp-modal <?= $editProduct ? 'active' : '' ?>">
    <div class="miniapp-modal-content">
        <div class="miniapp-modal-header">
            <h3 style="margin:0;"><?= $editProduct ? 'แก้ไขสินค้า' : 'เพิ่มสินค้าใหม่' ?></h3>
            <button class="miniapp-modal-close" onclick="this.closest('.miniapp-modal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="<?= $editProduct ? 'update_product' : 'create_product' ?>">
            <?php if ($editProduct): ?>
            <input type="hidden" name="id" value="<?= $editProduct['id'] ?>">
            <?php endif; ?>

            <!-- Section -->
            <div class="miniapp-form-group">
                <label>Section *</label>
                <select name="section_id" required>
                    <option value="">-- เลือก Section --</option>
                    <?php foreach ($allSections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= ($editProduct['section_id'] ?? $filterSection) == $sec['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sec['title']) ?> (<?= htmlspecialchars($sec['section_style']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Basic info -->
            <div class="miniapp-form-group">
                <label>ชื่อสินค้า *</label>
                <input type="text" name="title" value="<?= htmlspecialchars($editProduct['title'] ?? '') ?>" required placeholder="เช่น เบญจรงค์ ข้าวหอม 100% 1 กก. x 5">
            </div>

            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>รายละเอียดสั้น</label>
                    <input type="text" name="short_description" value="<?= htmlspecialchars($editProduct['short_description'] ?? '') ?>" placeholder="เช่น 1 กก. x 5">
                </div>
                <div class="miniapp-form-group">
                    <label>ข้อความเพิ่มเติม</label>
                    <input type="text" name="custom_label" value="<?= htmlspecialchars($editProduct['custom_label'] ?? '') ?>" placeholder="optional">
                </div>
            </div>

            <!-- Images -->
            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>URL รูปภาพหลัก *</label>
                    <input type="text" name="image_url" value="<?= htmlspecialchars($editProduct['image_url'] ?? '') ?>" required placeholder="https://...">
                </div>
                <div class="miniapp-form-group">
                    <label>แกลเลอรี่รูปภาพ (JSON)</label>
                    <input type="text" name="image_gallery" value="<?= htmlspecialchars($editProduct['image_gallery'] ?? '[]') ?>" placeholder='["url1","url2"]'>
                    <p class="miniapp-hint">JSON array ของ URL รูป</p>
                </div>
            </div>

            <!-- Pricing -->
            <div class="miniapp-form-row-3">
                <div class="miniapp-form-group">
                    <label>ราคาเดิม</label>
                    <input type="number" name="original_price" step="0.01" value="<?= $editProduct['original_price'] ?? '' ?>" placeholder="175.00">
                </div>
                <div class="miniapp-form-group">
                    <label>ราคาลด</label>
                    <input type="number" name="sale_price" step="0.01" value="<?= $editProduct['sale_price'] ?? '' ?>" placeholder="132.00">
                </div>
                <div class="miniapp-form-group">
                    <label>% ลด</label>
                    <input type="number" name="discount_percent" step="0.01" value="<?= $editProduct['discount_percent'] ?? '' ?>" placeholder="24">
                    <p class="miniapp-hint">กรอกเอง หรือปล่อยว่าง (auto)</p>
                </div>
            </div>

            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>หน่วยราคา</label>
                    <input type="text" name="price_unit" value="<?= htmlspecialchars($editProduct['price_unit'] ?? '') ?>" placeholder="/กก., /แพ็ค, /ขวด">
                </div>
                <div class="miniapp-form-group">
                    <label>ป้ายโปร (Promotion Label)</label>
                    <input type="text" name="promotion_label" value="<?= htmlspecialchars($editProduct['promotion_label'] ?? '') ?>" placeholder="เช่น 24 ชม.">
                </div>
            </div>

            <!-- Promotion tags -->
            <div class="miniapp-form-group">
                <label>แท็กโปรโมชั่น (JSON)</label>
                <textarea name="promotion_tags" rows="2" placeholder='["ซื้อ 999฿ รับส่วนลด 10฿", "ซื้อ 499฿ ได้รับ 40 พอยท์"]'><?= htmlspecialchars($editProduct['promotion_tags'] ?? '[]') ?></textarea>
                <p class="miniapp-hint">JSON array — จะแสดงเป็นแท็กสีฟ้าใต้รูปสินค้า</p>
            </div>

            <!-- Badges -->
            <div class="miniapp-form-group">
                <label>Badges (JSON)</label>
                <textarea name="badges" rows="2" placeholder='[{"text":"3+ units","color":"orange"}, {"text":"จำนวนจำกัด","color":"red"}]'><?= htmlspecialchars($editProduct['badges'] ?? '[]') ?></textarea>
                <p class="miniapp-hint">JSON array — แต่ละตัวมี text + color (red/orange/green/blue)</p>
            </div>

            <!-- Stock -->
            <div class="miniapp-form-row-3">
                <div class="miniapp-form-group">
                    <label>จำนวนสต็อก</label>
                    <input type="number" name="stock_qty" value="<?= $editProduct['stock_qty'] ?? '' ?>" placeholder="optional">
                </div>
                <div class="miniapp-form-group">
                    <label>จำกัดต่อคน</label>
                    <input type="number" name="limit_qty" value="<?= $editProduct['limit_qty'] ?? '' ?>" placeholder="optional">
                </div>
                <div class="miniapp-form-group" style="display:flex; align-items:flex-end;">
                    <label style="display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="show_stock_badge" value="1" <?= ($editProduct['show_stock_badge'] ?? 0) ? 'checked' : '' ?>>
                        แสดง badge "จำนวนจำกัด"
                    </label>
                </div>
            </div>

            <!-- Delivery options -->
            <div class="miniapp-form-group">
                <label>ตัวเลือกจัดส่ง (JSON)</label>
                <textarea name="delivery_options" rows="2" placeholder='["สั่งเช้า ส่งเย็น", "จำนวนจำกัด"]'><?= htmlspecialchars($editProduct['delivery_options'] ?? '[]') ?></textarea>
                <p class="miniapp-hint">JSON array — แสดงเป็น icon tags ใต้สินค้า</p>
            </div>

            <!-- Universal Link -->
            <div class="miniapp-form-row">
                <div class="miniapp-form-group">
                    <label>ประเภทลิ้งค์</label>
                    <select name="link_type">
                        <?php foreach ($linkTypes as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($editProduct['link_type'] ?? 'none') === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="miniapp-form-group">
                    <label>ค่าลิ้งค์</label>
                    <input type="text" name="link_value" value="<?= htmlspecialchars($editProduct['link_value'] ?? '') ?>" placeholder="URL / route / LIFF ID">
                </div>
            </div>

            <!-- Display & scheduling -->
            <div class="miniapp-form-row-3">
                <div class="miniapp-form-group">
                    <label>ลำดับ</label>
                    <input type="number" name="display_order" value="<?= (int)($editProduct['display_order'] ?? 0) ?>" min="0">
                </div>
                <div class="miniapp-form-group">
                    <label>เริ่มแสดง</label>
                    <input type="datetime-local" name="start_date" value="<?= !empty($editProduct['start_date']) ? date('Y-m-d\TH:i', strtotime($editProduct['start_date'])) : '' ?>">
                </div>
                <div class="miniapp-form-group">
                    <label>หยุดแสดง</label>
                    <input type="datetime-local" name="end_date" value="<?= !empty($editProduct['end_date']) ? date('Y-m-d\TH:i', strtotime($editProduct['end_date'])) : '' ?>">
                </div>
            </div>

            <div class="miniapp-form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" <?= ($editProduct['is_active'] ?? 1) ? 'checked' : '' ?>> เปิดใช้งาน
                </label>
            </div>

            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px;">
                <button type="button" class="miniapp-btn miniapp-btn-outline" onclick="this.closest('.miniapp-modal').classList.remove('active')">ยกเลิก</button>
                <button type="submit" class="miniapp-btn miniapp-btn-primary">
                    <i class="fas fa-save"></i> <?= $editProduct ? 'บันทึก' : 'เพิ่มสินค้า' ?>
                </button>
            </div>
        </form>
    </div>
</div>
