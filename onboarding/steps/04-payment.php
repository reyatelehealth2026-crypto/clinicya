<?php
/** @var array $prefill */
$p = $prefill['payment'];
$bank = [];
if (!empty($p['bank_accounts'])) {
    $bank = json_decode((string)$p['bank_accounts'], true) ?: [];
}
?>
<p class="wz-intro">
  ลูกค้าจะใช้ข้อมูลนี้โอนเงินมา ระบบจะแสดง PromptPay QR และเลขบัญชีในขั้นชำระเงิน
</p>

<div class="wz-grid-2">
  <div class="wz-field">
    <label>เบอร์ PromptPay <span class="wz-req">*</span></label>
    <input type="tel" name="promptpay_number" required maxlength="20"
           value="<?= htmlspecialchars($p['promptpay_number'] ?? '') ?>"
           placeholder="0xxxxxxxxx หรือเลขนิติบุคคล">
  </div>
  <div class="wz-field">
    <label>ชื่อบัญชี PromptPay</label>
    <input type="text" name="promptpay_name" maxlength="255"
           value="<?= htmlspecialchars($p['promptpay_name'] ?? '') ?>"
           placeholder="ชื่อ-นามสกุล / ชื่อนิติบุคคล">
  </div>
</div>

<h3 style="margin:18px 0 6px;font-size:15px">บัญชีธนาคาร (ทางเลือก)</h3>

<div class="wz-grid-2">
  <div class="wz-field">
    <label>ธนาคาร</label>
    <input type="text" name="bank_name" maxlength="100"
           value="<?= htmlspecialchars($bank['bank_name'] ?? '') ?>"
           placeholder="เช่น SCB, KBank, Bangkok Bank">
  </div>
  <div class="wz-field">
    <label>เลขบัญชี</label>
    <input type="text" name="bank_account" maxlength="50"
           value="<?= htmlspecialchars($bank['account_no'] ?? '') ?>"
           placeholder="xxx-x-xxxxx-x">
  </div>
</div>

<div class="wz-field">
  <label>ชื่อบัญชีธนาคาร</label>
  <input type="text" name="bank_account_name" maxlength="255"
         value="<?= htmlspecialchars($bank['account_name'] ?? '') ?>"
         placeholder="ชื่อ-นามสกุล / ชื่อนิติบุคคล">
</div>
