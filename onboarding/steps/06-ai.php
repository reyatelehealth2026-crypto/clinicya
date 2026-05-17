<?php /** @var array $prefill */ $p = $prefill['ai']; ?>
<p class="wz-intro">
  เปิดใช้ AI เพื่อให้ตอบลูกค้าอัตโนมัติ (ทักทาย, คัดกรองอาการ, แนะนำยาเบื้องต้น)
  ขั้นนี้ <strong>ทางเลือก</strong> — สามารถข้ามแล้วตั้งภายหลังในเมนู AI Settings
</p>

<div class="wz-field">
  <label>ผู้ให้บริการ AI</label>
  <select name="provider">
    <option value="gemini" <?= (($p['ai_provider'] ?? 'gemini') === 'gemini') ? 'selected' : '' ?>>Google Gemini (แนะนำ)</option>
    <option value="openai" <?= (($p['ai_provider'] ?? '') === 'openai') ? 'selected' : '' ?>>OpenAI</option>
  </select>
</div>

<div class="wz-field">
  <label>โมเดล</label>
  <input type="text" name="model" maxlength="50"
         value="<?= htmlspecialchars($p['model'] ?? 'gemini-2.0-flash') ?>"
         placeholder="เช่น gemini-2.0-flash, gpt-4o-mini">
</div>

<div class="wz-field">
  <label>API Key</label>
  <input type="password" name="api_key" maxlength="255" autocomplete="new-password"
         placeholder="วาง API key (เก็บในฐานข้อมูล)">
  <div class="wz-hint">
    Gemini: <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a>
    &middot; OpenAI: <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>
  </div>
</div>

<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:14px">
  <input type="checkbox" name="skip_ai" value="1"
         onchange="this.form.querySelectorAll('input[name=api_key],input[name=model],select[name=provider]').forEach(el => el.disabled = this.checked)">
  ข้ามขั้นนี้ก่อน (เปิด AI ภายหลังจากเมนู AI Settings)
</label>
