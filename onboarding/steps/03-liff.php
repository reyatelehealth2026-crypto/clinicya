<?php /** @var array $prefill */ $p = $prefill['liff']; /** @var string $liffEndpoint */ ?>
<p class="wz-intro">
  LIFF (LINE Front-end Framework) คือ Mini App ที่ลูกค้าเปิดในแชตเพื่อเลือกสินค้า, ดูประวัติ, นัดเภสัชกร
  สร้างที่ LINE Developers → Messaging API channel → LIFF tab → <em>Add</em>
</p>

<div class="wz-preview">
  <strong>Endpoint URL — ใส่ตอนสร้าง LIFF app</strong>
  <div class="wz-hint" style="margin-bottom:8px">
    Size: <b>Full</b> &nbsp;|&nbsp; Scopes: <code>profile openid chat_message.write</code>
  </div>
  <div class="wz-row">
    <input type="text" id="liff-endpoint" readonly value="<?= htmlspecialchars($liffEndpoint) ?>">
    <button type="button" class="wz-copy" data-copy="#liff-endpoint">คัดลอก</button>
  </div>
</div>

<div class="wz-field">
  <label>ชื่อ LIFF App <span class="wz-req">*</span></label>
  <input type="text" name="liff_name" required maxlength="100"
         value="<?= htmlspecialchars($p['name'] ?? 'Clinicya Mini App') ?>"
         placeholder="เช่น Clinicya Mini App">
</div>

<div class="wz-field">
  <label>LIFF ID <span class="wz-req">*</span></label>
  <input type="text" name="liff_id" required maxlength="100"
         value="<?= htmlspecialchars($p['liff_id'] ?? '') ?>"
         placeholder="เช่น 1234567890-abcdefgh">
  <div class="wz-hint">หลังสร้าง LIFF app เสร็จ จะได้ LIFF ID มา paste ที่นี่</div>
</div>

<div class="wz-field">
  <label>Endpoint URL (ตามที่ใส่ใน LINE) <span class="wz-req">*</span></label>
  <input type="url" name="endpoint_url" required maxlength="500"
         value="<?= htmlspecialchars($p['endpoint_url'] ?? $liffEndpoint) ?>"
         placeholder="https://...">
</div>
