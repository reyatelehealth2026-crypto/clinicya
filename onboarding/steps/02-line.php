<?php /** @var array $prefill */ $p = $prefill['line']; /** @var string $webhookUrl */ ?>
<p class="wz-intro">
  เชื่อม LINE Official Account: ไปที่
  <a href="https://developers.line.biz/console/" target="_blank" rel="noopener">LINE Developers Console</a>
  → Provider → Messaging API channel แล้ว copy ค่าต่อไปนี้
</p>

<div class="wz-field">
  <label>ชื่อ LINE OA (แสดงในแอดมิน) <span class="wz-req">*</span></label>
  <input type="text" name="display_name" required maxlength="255"
         value="<?= htmlspecialchars($p['name'] ?? '') ?>"
         placeholder="เช่น Clinicya">
</div>

<div class="wz-field">
  <label>Channel ID <span class="wz-req">*</span></label>
  <input type="text" name="channel_id" required maxlength="100"
         value="<?= htmlspecialchars($p['channel_id'] ?? '') ?>"
         placeholder="ตัวเลข 10 หลัก">
</div>

<div class="wz-field">
  <label>Channel Secret <span class="wz-req">*</span></label>
  <input type="text" name="channel_secret" required maxlength="100"
         value="<?= htmlspecialchars(strpos((string)($p['channel_secret'] ?? ''), 'pending_') === 0 ? '' : ($p['channel_secret'] ?? '')) ?>"
         placeholder="Basic settings → Channel secret">
</div>

<div class="wz-field">
  <label>Channel Access Token (long-lived) <span class="wz-req">*</span></label>
  <textarea name="channel_access_token" required
            placeholder="Messaging API → Channel access token (long-lived)"><?= htmlspecialchars($p['channel_access_token'] ?? '') ?></textarea>
</div>

<div class="wz-preview">
  <strong>Webhook URL — paste ลง LINE Developers Console</strong>
  <div class="wz-hint" style="margin-bottom:8px">
    Messaging API → Webhook settings → Webhook URL → Use webhook = ON → Verify
  </div>
  <div class="wz-row">
    <input type="text" id="webhook-url" readonly value="<?= htmlspecialchars($webhookUrl) ?>">
    <button type="button" class="wz-copy" data-copy="#webhook-url">คัดลอก</button>
  </div>
</div>
