<?php /** @var array $prefill */ $p = $prefill['pharmacist']; ?>
<p class="wz-intro">
  เพิ่มเภสัชกรคนแรกของร้าน เภสัชกรจะปรากฏในระบบนัดและจ่ายยา
  ทุกใบสั่งยาจะอ้างอิงเลขใบประกอบวิชาชีพ
</p>

<div class="wz-field">
  <label>ชื่อ-นามสกุล <span class="wz-req">*</span></label>
  <input type="text" name="name" required maxlength="255"
         value="<?= htmlspecialchars($p['name'] ?? '') ?>"
         placeholder="เช่น ภก. สมชาย ใจดี">
</div>

<div class="wz-field">
  <label>เลขใบประกอบวิชาชีพ <span class="wz-req">*</span></label>
  <input type="text" name="license_no" required maxlength="50"
         value="<?= htmlspecialchars($p['license_no'] ?? '') ?>"
         placeholder="เช่น ภ.12345">
</div>

<div class="wz-field">
  <label>รูปโปรไฟล์ (JPG / PNG / WebP)</label>
  <input type="file" name="image" accept="image/png,image/jpeg,image/webp">
  <?php if (!empty($p['image_url'])): ?>
    <div class="wz-hint">รูปปัจจุบัน: <?= htmlspecialchars($p['image_url']) ?></div>
  <?php endif; ?>
</div>
