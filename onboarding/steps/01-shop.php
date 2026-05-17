<?php /** @var array $prefill */ $p = $prefill['shop']; ?>
<p class="wz-intro">
  ตั้งค่าข้อมูลร้านพื้นฐาน ลูกค้าจะเห็นชื่อ-ที่อยู่นี้ในใบเสร็จ, รับยา และข้อความ Flex ทุกครั้งที่ส่ง
</p>

<div class="wz-field">
  <label>ชื่อร้าน <span class="wz-req">*</span></label>
  <input type="text" name="shop_name" required maxlength="255"
         value="<?= htmlspecialchars($p['shop_name'] ?? '') ?>"
         placeholder="เช่น Clinicya Pharmacy">
</div>

<div class="wz-grid-2">
  <div class="wz-field">
    <label>เบอร์โทรร้าน <span class="wz-req">*</span></label>
    <input type="tel" name="contact_phone" required maxlength="20"
           value="<?= htmlspecialchars($p['contact_phone'] ?? '') ?>"
           placeholder="0xxxxxxxxx">
  </div>
  <div class="wz-field">
    <label>เวลาเปิด-ปิด</label>
    <input type="text" name="open_hours" maxlength="100"
           value="<?= htmlspecialchars($p['welcome_message'] ?? '') ?>"
           placeholder="จ.-ศ. 09:00-20:00">
    <div class="wz-hint">เก็บเป็นข้อความต้อนรับ (welcome_message)</div>
  </div>
</div>

<div class="wz-field">
  <label>ที่อยู่ร้าน <span class="wz-req">*</span></label>
  <textarea name="address" required
            placeholder="เลขที่ ถนน ตำบล อำเภอ จังหวัด รหัสไปรษณีย์"><?= htmlspecialchars($p['address'] ?? '') ?></textarea>
</div>

<div class="wz-field">
  <label>โลโก้ร้าน (JPG / PNG / WebP)</label>
  <input type="file" name="shop_logo" accept="image/png,image/jpeg,image/webp">
  <?php if (!empty($p['shop_logo'])): ?>
    <div class="wz-hint">โลโก้ปัจจุบัน: <?= htmlspecialchars($p['shop_logo']) ?></div>
  <?php endif; ?>
</div>
