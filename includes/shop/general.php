<?php
/**
 * Shop Settings - General Tab Content
 * ตั้งค่าร้านค้าทั่วไป
 */

// Get settings
$settings = [];
if ($tableExists) {
    try {
        if ($hasAccountCol && $currentBotId) {
            $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ?");
            $stmt->execute([$currentBotId]);
            $settings = $stmt->fetch();
        }

        if (!$settings) {
            $stmt = $db->query("SELECT * FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1");
            $settings = $stmt->fetch();
        }
    } catch (Exception $e) {
        $settings = [];
    }
}

// Default values
if (!$settings) {
    $settings = [
        'shop_name' => 'LINE Shop',
        'shop_logo' => '',
        'welcome_message' => 'ยินดีต้อนรับ!',
        'shipping_fee' => 50,
        'free_shipping_min' => 500,
        'bank_accounts' => '{"banks":[]}',
        'promptpay_number' => '',
        'contact_phone' => '',
        'is_open' => 1,
        'cod_enabled' => 0,
        'cod_fee' => 0,
        'auto_confirm_payment' => 0,
        'order_data_source' => 'shop',
        'shop_address' => '',
        'shop_email' => '',
        'line_id' => '',
        'facebook_url' => '',
        'instagram_url' => ''
    ];
}

$bankAccounts = json_decode($settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
?>

<form method="POST" enctype="multipart/form-data" id="settings-general-form">
    <input type="hidden" name="tab" value="general">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <?php
        // ── ข้อมูลร้านค้า ────────────────────────────────────────────────────
        ob_start(); ?>
        <div class="space-y-4">
            <?= renderField('shop_name', 'ชื่อร้าน', 'text', $settings['shop_name'] ?? '', [
                'placeholder' => 'LINE Shop',
                'required'    => true,
            ]) ?>

            <div class="field-group">
                <label class="field-label">โลโก้ร้าน</label>
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <?php if (!empty($settings['shop_logo'])): ?>
                        <img src="<?= htmlspecialchars($settings['shop_logo']) ?>" class="w-20 h-20 rounded-lg object-cover border" id="logoPreview">
                        <?php else: ?>
                        <div class="w-20 h-20 rounded-lg bg-gray-100 flex items-center justify-center border" id="logoPreviewDiv">
                            <i class="fas fa-image text-gray-400 text-2xl"></i>
                        </div>
                        <img src="" class="w-20 h-20 rounded-lg object-cover border hidden" id="logoPreview">
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 space-y-2">
                        <div class="flex items-center gap-2">
                            <label class="px-4 py-2 bg-blue-500 text-white rounded-lg cursor-pointer hover:bg-blue-600 transition text-sm">
                                <i class="fas fa-upload mr-1"></i>อัพโหลดรูป
                                <input type="file" name="logo_file" accept="image/*" class="hidden" id="logoFileInput" onchange="previewLogo(this)">
                            </label>
                            <span class="text-xs text-gray-500">หรือ</span>
                        </div>
                        <input type="url" name="shop_logo" id="logoUrlInput"
                               value="<?= htmlspecialchars($settings['shop_logo'] ?? '') ?>"
                               placeholder="วาง URL รูปโลโก้"
                               class="field-input text-sm"
                               onchange="previewLogoUrl(this)">
                        <p class="field-help">ขนาดแนะนำ: 200x200 px</p>
                    </div>
                </div>
            </div>

            <?= renderField('welcome_message', 'ข้อความต้อนรับ', 'textarea', $settings['welcome_message'] ?? '', [
                'rows' => 3,
            ]) ?>

            <?= renderField('shop_address', 'ที่อยู่ร้าน', 'textarea', $settings['shop_address'] ?? '', [
                'rows' => 2,
            ]) ?>

            <div class="grid grid-cols-2 gap-4">
                <?= renderField('contact_phone', 'เบอร์ติดต่อ', 'tel', $settings['contact_phone'] ?? '', [
                    'wrap_class' => 'mb-0',
                ]) ?>
                <?= renderField('shop_email', 'อีเมล', 'email', $settings['shop_email'] ?? '', [
                    'wrap_class' => 'mb-0',
                ]) ?>
            </div>

            <?= renderToggle('is_open', 'สถานะร้านค้า', (bool)($settings['is_open'] ?? 1), 'เปิด/ปิดรับออเดอร์', ['color' => 'emerald']) ?>

            <?php if (defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true): ?>
            <div class="p-4 bg-indigo-50 rounded-lg border border-indigo-100">
                <label class="block text-sm font-medium mb-2 text-indigo-900">แหล่งข้อมูลคำสั่งซื้อ/ยอดขาย</label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <label class="flex items-center gap-2 p-3 bg-white rounded-lg border cursor-pointer">
                        <input type="radio" name="order_data_source" value="shop" class="text-green-500"
                               <?= (($settings['order_data_source'] ?? 'shop') !== 'odoo') ? 'checked' : '' ?>>
                        <span>
                            <span class="font-medium text-sm text-gray-800">Shop (เดิม)</span>
                            <span class="block text-xs text-gray-500">ใช้ข้อมูลจาก transactions/orders ในระบบนี้</span>
                        </span>
                    </label>
                    <label class="flex items-center gap-2 p-3 bg-white rounded-lg border cursor-pointer">
                        <input type="radio" name="order_data_source" value="odoo" class="text-indigo-600"
                               <?= (($settings['order_data_source'] ?? 'shop') === 'odoo') ? 'checked' : '' ?>>
                        <span>
                            <span class="font-medium text-sm text-gray-800">Odoo</span>
                            <span class="block text-xs text-gray-500">ใช้ข้อมูลที่รับจาก Odoo (read-only สำหรับหลังบ้านออเดอร์)</span>
                        </span>
                    </label>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php $shopInfoBody = ob_get_clean();
        echo renderFormSection('ข้อมูลร้านค้า', 'fas fa-store', '', $shopInfoBody);
        ?>

        <?php
        // ── ค่าจัดส่ง ────────────────────────────────────────────────────────
        ob_start(); ?>
        <div class="space-y-4">
            <?= renderField('shipping_fee', 'ค่าจัดส่ง (บาท)', 'number', $settings['shipping_fee'] ?? 50, [
                'min' => '0',
            ]) ?>
            <?= renderField('free_shipping_min', 'ส่งฟรีเมื่อซื้อขั้นต่ำ (บาท)', 'number', $settings['free_shipping_min'] ?? 500, [
                'min'  => '0',
                'help' => 'ใส่ 0 เพื่อปิดส่งฟรี',
            ]) ?>

            <div class="border-t pt-4 mt-4">
                <h4 class="font-medium mb-3">
                    <i class="fas fa-hand-holding-usd mr-2 text-orange-500"></i>เก็บเงินปลายทาง (COD)
                </h4>
                <?= renderToggle('cod_enabled', 'เปิดใช้ COD', (bool)($settings['cod_enabled'] ?? 0), 'ลูกค้าจ่ายเงินตอนรับสินค้า', ['color' => 'amber']) ?>
                <div class="mt-3">
                    <?= renderField('cod_fee', 'ค่าธรรมเนียม COD (บาท)', 'number', $settings['cod_fee'] ?? 0, [
                        'min' => '0',
                    ]) ?>
                </div>
            </div>
        </div>
        <?php $shippingBody = ob_get_clean();
        echo renderFormSection('ค่าจัดส่ง', 'fas fa-truck', '', $shippingBody);
        ?>

        <?php
        // ── โซเชียลมีเดีย ────────────────────────────────────────────────────
        ob_start(); ?>
        <div class="space-y-4">
            <?= renderField('line_id', 'LINE ID', 'text', $settings['line_id'] ?? '', [
                'placeholder' => '@yourlineid',
                'icon'        => 'fab fa-line',
            ]) ?>
            <?= renderField('facebook_url', 'Facebook', 'url', $settings['facebook_url'] ?? '', [
                'placeholder' => 'https://facebook.com/yourpage',
                'icon'        => 'fab fa-facebook',
            ]) ?>
            <?= renderField('instagram_url', 'Instagram', 'url', $settings['instagram_url'] ?? '', [
                'placeholder' => 'https://instagram.com/yourpage',
                'icon'        => 'fab fa-instagram',
            ]) ?>
        </div>
        <?php $socialBody = ob_get_clean();
        echo renderFormSection('โซเชียลมีเดีย', 'fas fa-share-alt', '', $socialBody);
        ?>

        <?php
        // ── ตั้งค่าเพิ่มเติม ─────────────────────────────────────────────────
        ob_start(); ?>
        <div class="space-y-4">
            <?= renderToggle(
                'auto_confirm_payment',
                'ยืนยันการชำระเงินอัตโนมัติ',
                (bool)($settings['auto_confirm_payment'] ?? 0),
                'ระบบจะยืนยันออเดอร์อัตโนมัติเมื่อได้รับสลิป',
                ['color' => 'indigo']
            ) ?>
        </div>
        <?php $extraBody = ob_get_clean();
        echo renderFormSection('ตั้งค่าเพิ่มเติม', 'fas fa-cog', '', $extraBody);
        ?>

    </div><!-- /grid cols-2 -->

    <?php
    // ── ช่องทางชำระเงิน (full-width below grid) ──────────────────────────────
    ob_start(); ?>
    <div class="space-y-4">
        <?= renderField('promptpay_number', 'พร้อมเพย์', 'text', $settings['promptpay_number'] ?? '', [
            'placeholder' => 'เบอร์โทรหรือเลขบัตรประชาชน',
            'icon'        => 'fas fa-qrcode',
        ]) ?>

        <div class="field-group">
            <label class="field-label">บัญชีธนาคาร</label>
            <div id="bankAccounts" class="space-y-3">
                <?php foreach ($bankAccounts as $bank): ?>
                <div class="flex space-x-2 bank-row">
                    <input type="text" name="bank_name[]"
                           value="<?= htmlspecialchars($bank['name']) ?>"
                           placeholder="ธนาคาร" class="field-input flex-1">
                    <input type="text" name="bank_account[]"
                           value="<?= htmlspecialchars($bank['account']) ?>"
                           placeholder="เลขบัญชี" class="field-input flex-1">
                    <input type="text" name="bank_holder[]"
                           value="<?= htmlspecialchars($bank['holder']) ?>"
                           placeholder="ชื่อบัญชี" class="field-input flex-1">
                    <button type="button" onclick="this.parentElement.remove()"
                            class="px-3 py-2 text-red-500 hover:bg-red-50 rounded-lg flex-shrink-0">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="addBankRow()"
                    class="mt-2 px-4 py-2 border rounded-lg hover:bg-gray-50 text-sm">
                <i class="fas fa-plus mr-2"></i>เพิ่มบัญชี
            </button>
        </div>
    </div>
    <?php $paymentBody = ob_get_clean();
    echo renderFormSection('ช่องทางชำระเงิน', 'fas fa-credit-card', '', $paymentBody);
    ?>

    <?= renderStickySaveBar('settings-general-form', 'บันทึกการตั้งค่า', 'settings.php?tab=general') ?>

</form>

<script>
function addBankRow() {
    const html = `
        <div class="flex space-x-2 bank-row">
            <input type="text" name="bank_name[]" placeholder="ธนาคาร" class="field-input flex-1">
            <input type="text" name="bank_account[]" placeholder="เลขบัญชี" class="field-input flex-1">
            <input type="text" name="bank_holder[]" placeholder="ชื่อบัญชี" class="field-input flex-1">
            <button type="button" onclick="this.parentElement.remove()"
                    class="px-3 py-2 text-red-500 hover:bg-red-50 rounded-lg flex-shrink-0">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    document.getElementById('bankAccounts').insertAdjacentHTML('beforeend', html);
}

function previewLogo(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('logoPreview');
            const previewDiv = document.getElementById('logoPreviewDiv');
            preview.src = e.target.result;
            preview.classList.remove('hidden');
            if (previewDiv) previewDiv.classList.add('hidden');
            document.getElementById('logoUrlInput').value = '';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function previewLogoUrl(input) {
    const url = input.value.trim();
    if (url) {
        const preview = document.getElementById('logoPreview');
        const previewDiv = document.getElementById('logoPreviewDiv');
        preview.src = url;
        preview.classList.remove('hidden');
        if (previewDiv) previewDiv.classList.add('hidden');
        document.getElementById('logoFileInput').value = '';
    }
}
</script>
