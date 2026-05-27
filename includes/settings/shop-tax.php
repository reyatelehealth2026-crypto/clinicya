<?php
/**
 * Settings tab: ข้อมูลกิจการสำหรับเอกสารทางภาษี
 * Tenant-scoped shop_tax_info CRUD — prints on QT / INV / TAX / RE / CN / etc.
 *
 * Loaded from settings.php via include 'includes/settings/shop-tax.php'.
 * POSTs via fetch to /api/shop-tax.php (action=get / action=save).
 */

if (!function_exists('isAdmin')) {
    require_once __DIR__ . '/../../includes/auth_check.php';
}

// Read current row directly (same tenant resolution as api/shop-tax.php).
$db = Database::getInstance()->getConnection();
$lineAccountId = (int)($_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 0);
if ($lineAccountId <= 0 && !empty($_SESSION['user_id'])) {
    try {
        $s = $db->prepare('SELECT line_account_id FROM admin_users WHERE id = ? LIMIT 1');
        $s->execute([(int)$_SESSION['user_id']]);
        $lineAccountId = (int)($s->fetchColumn() ?: 0);
    } catch (\Throwable $e) {}
}
if ($lineAccountId <= 0) {
    try {
        $r = $db->query('SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $lineAccountId = (int)($r['id'] ?? 0);
    } catch (\Throwable $e) {}
}

$row = [
    'business_name'      => '',
    'business_name_en'   => '',
    'tax_id'             => '',
    'branch_code'        => '00000',
    'address'            => '',
    'phone'              => '',
    'email'              => '',
    'logo_url'           => '',
    'authorized_signer'  => '',
    'signer_position'    => '',
    'is_vat_registered'  => 0,
    'default_vat_rate'   => 7.00,
];
if ($lineAccountId > 0) {
    try {
        $s = $db->prepare('SELECT * FROM shop_tax_info WHERE line_account_id = ?');
        $s->execute([$lineAccountId]);
        $found = $s->fetch(PDO::FETCH_ASSOC);
        if ($found) { $row = array_merge($row, $found); }
    } catch (\Throwable $e) { /* table may not exist on stale envs */ }
}

$h = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>

<div class="bg-white rounded-xl border border-slate-200 p-6">
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-semibold text-slate-900 flex items-center gap-2">
                <i class="fas fa-file-invoice text-indigo-600"></i>
                ข้อมูลกิจการ (พิมพ์บนใบกำกับภาษี / ใบเสนอราคา)
            </h2>
            <p class="text-sm text-slate-500 mt-1">
                ข้อมูลนี้จะถูกพิมพ์เป็นหัวกระดาษเอกสารทุกประเภท (QT / INV / TAX / RE / CN / DN / PO ฯลฯ).
                เก็บตามผู้ออกใบ — แยกตามบัญชี LINE (multi-tenant).
            </p>
        </div>
        <a href="documents.php" class="text-sm text-slate-500 hover:text-slate-800 inline-flex items-center gap-1">
            <i class="fas fa-arrow-left"></i> กลับหน้าเอกสาร
        </a>
    </div>

    <div id="shopTaxAlert" class="hidden mb-4 p-3 rounded-lg text-sm"></div>

    <form id="shopTaxForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-slate-50 rounded-lg border border-slate-200">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อกิจการ (ไทย) <span class="text-rose-500">*</span></label>
                <input type="text" name="business_name" maxlength="255" required
                       value="<?= $h($row['business_name']) ?>"
                       class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="เช่น บริษัท เรยา เฮลธ์ จำกัด">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">ชื่อกิจการ (อังกฤษ)</label>
                <input type="text" name="business_name_en" maxlength="255"
                       value="<?= $h($row['business_name_en']) ?>"
                       class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="REYA Health Co., Ltd.">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">เลขประจำตัวผู้เสียภาษี (13 หลัก)</label>
            <input type="text" name="tax_id" maxlength="20" pattern="[0-9]{10,13}"
                   value="<?= $h($row['tax_id']) ?>"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="0105566123456">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">รหัสสาขา</label>
            <input type="text" name="branch_code" maxlength="20"
                   value="<?= $h($row['branch_code']) ?>"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="00000 = สำนักงานใหญ่">
        </div>

        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">ที่อยู่กิจการ</label>
            <textarea name="address" rows="3"
                      class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                      placeholder="123 ถนน... แขวง... เขต... กรุงเทพฯ 10110"><?= $h($row['address']) ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">เบอร์โทร</label>
            <input type="text" name="phone" maxlength="50"
                   value="<?= $h($row['phone']) ?>"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="02-123-4567">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">อีเมล</label>
            <input type="email" name="email" maxlength="100"
                   value="<?= $h($row['email']) ?>"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="contact@yourshop.com">
        </div>

        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-slate-700 mb-1">URL โลโก้ (https://...)</label>
            <input type="url" name="logo_url" maxlength="500"
                   value="<?= $h($row['logo_url']) ?>"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="https://your-cdn.com/logo.png">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ผู้มีอำนาจลงนาม</label>
            <input type="text" name="authorized_signer" maxlength="255"
                   value="<?= $h($row['authorized_signer']) ?>"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="นาย ก. ขีดเส้น">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">ตำแหน่ง</label>
            <input type="text" name="signer_position" maxlength="100"
                   value="<?= $h($row['signer_position']) ?>"
                   class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="กรรมการผู้จัดการ">
        </div>

        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-amber-50 rounded-lg border border-amber-200">
            <div class="flex items-center gap-3">
                <input type="checkbox" name="is_vat_registered" id="isVatRegistered" value="1"
                       <?= !empty($row['is_vat_registered']) ? 'checked' : '' ?>
                       class="w-5 h-5 text-indigo-600 rounded focus:ring-indigo-500">
                <label for="isVatRegistered" class="text-sm font-medium text-slate-700">
                    จดทะเบียนภาษีมูลค่าเพิ่ม (VAT)
                </label>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">อัตรา VAT เริ่มต้น (%)</label>
                <input type="number" name="default_vat_rate" min="0" max="99" step="0.01"
                       value="<?= $h($row['default_vat_rate']) ?>"
                       class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
        </div>

        <div class="md:col-span-2 pt-4 border-t border-slate-200 flex items-center justify-end gap-3">
            <a href="documents.php" class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800">ยกเลิก</a>
            <button type="submit" id="shopTaxSubmit"
                    class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg inline-flex items-center gap-2 disabled:opacity-50">
                <i class="fas fa-save"></i> บันทึก
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('shopTaxForm');
    const alertBox = document.getElementById('shopTaxAlert');
    const submitBtn = document.getElementById('shopTaxSubmit');
    if (!form) return;

    function showAlert(type, msg) {
        alertBox.className = 'mb-4 p-3 rounded-lg text-sm ' +
            (type === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
                           : 'bg-rose-50 border border-rose-200 text-rose-800');
        alertBox.textContent = msg;
        alertBox.classList.remove('hidden');
        if (type === 'ok') {
            setTimeout(() => alertBox.classList.add('hidden'), 4000);
        }
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const payload = {};
        for (const [k, v] of fd.entries()) { payload[k] = v; }
        payload.is_vat_registered = form.querySelector('[name="is_vat_registered"]').checked ? 1 : 0;

        submitBtn.disabled = true;
        try {
            const res = await fetch('api/shop-tax.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
            });
            const json = await res.json();
            if (json.success) {
                showAlert('ok', 'บันทึกข้อมูลกิจการสำเร็จ — เอกสารใหม่จะแสดงข้อมูลนี้');
            } else {
                showAlert('err', json.message || json.error || 'บันทึกไม่สำเร็จ');
            }
        } catch (err) {
            showAlert('err', 'เครือข่ายมีปัญหา: ' + err.message);
        } finally {
            submitBtn.disabled = false;
        }
    });
})();
</script>
