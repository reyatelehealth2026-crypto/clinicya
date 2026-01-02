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
        'shop_address' => '',
        'shop_email' => '',
        'line_id' => '',
        'facebook_url' => '',
        'instagram_url' => ''
    ];
}

$bankAccounts = json_decode($settings['bank_accounts'] ?? '{"banks":[]}', true)['banks'] ?? [];
?>

<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="tab" value="general">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- General Settings -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-lg font-semibold mb-4"><i class="fas fa-store mr-2 text-green-500"></i>ข้อมูลร้านค้า</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ชื่อร้าน</label>
                    <input type="text" name="shop_name" value="<?= htmlspecialchars($settings['shop_name'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">โลโก้ร้าน</label>
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
                            <input type="url" name="shop_logo" id="logoUrlInput" value="<?= htmlspecialchars($settings['shop_logo'] ?? '') ?>" placeholder="วาง URL รูปโลโก้" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-sm" onchange="previewLogoUrl(this)">
                            <p class="text-xs text-gray-400">ขนาดแนะนำ: 200x200 px</p>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ข้อความต้อนรับ</label>
                    <textarea name="welcome_message" rows="3" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"><?= htmlspecialchars($settings['welcome_message'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ที่อยู่ร้าน</label>
                    <textarea name="shop_address" rows="2" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"><?= htmlspecialchars($settings['shop_address'] ?? '') ?></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">เบอร์ติดต่อ</label>
                        <input type="text" name="contact_phone" value="<?= htmlspecialchars($settings['contact_phone'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">อีเมล</label>
                        <input type="email" name="shop_email" value="<?= htmlspecialchars($settings['shop_email'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                    <div>
                        <p class="font-medium">สถานะร้านค้า</p>
                        <p class="text-sm text-gray-500">เปิด/ปิดรับออเดอร์</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="is_open" class="sr-only peer" <?= ($settings['is_open'] ?? 1) ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500"></div>
                    </label>
                </div>
            </div>
        </div>
        
        <!-- Shipping Settings -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-lg font-semibold mb-4"><i class="fas fa-truck mr-2 text-blue-500"></i>ค่าจัดส่ง</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ค่าจัดส่ง (บาท)</label>
                    <input type="number" name="shipping_fee" value="<?= $settings['shipping_fee'] ?? 50 ?>" min="0" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ส่งฟรีเมื่อซื้อขั้นต่ำ (บาท)</label>
                    <input type="number" name="free_shipping_min" value="<?= $settings['free_shipping_min'] ?? 500 ?>" min="0" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1">ใส่ 0 เพื่อปิดส่งฟรี</p>
                </div>
                
                <div class="border-t pt-4 mt-4">
                    <h4 class="font-medium mb-3"><i class="fas fa-hand-holding-usd mr-2 text-orange-500"></i>เก็บเงินปลายทาง (COD)</h4>
                    <div class="flex items-center justify-between p-3 bg-orange-50 rounded-lg mb-3">
                        <div>
                            <p class="font-medium text-orange-800">เปิดใช้ COD</p>
                            <p class="text-sm text-orange-600">ลูกค้าจ่ายเงินตอนรับสินค้า</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="cod_enabled" class="sr-only peer" <?= ($settings['cod_enabled'] ?? 0) ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                        </label>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">ค่าธรรมเนียม COD (บาท)</label>
                        <input type="number" name="cod_fee" value="<?= $settings['cod_fee'] ?? 0 ?>" min="0" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Social Media -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-lg font-semibold mb-4"><i class="fas fa-share-alt mr-2 text-purple-500"></i>โซเชียลมีเดีย</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1"><i class="fab fa-line text-green-500 mr-1"></i>LINE ID</label>
                    <input type="text" name="line_id" value="<?= htmlspecialchars($settings['line_id'] ?? '') ?>" placeholder="@yourlineid" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1"><i class="fab fa-facebook text-blue-600 mr-1"></i>Facebook</label>
                    <input type="url" name="facebook_url" value="<?= htmlspecialchars($settings['facebook_url'] ?? '') ?>" placeholder="https://facebook.com/yourpage" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1"><i class="fab fa-instagram text-pink-500 mr-1"></i>Instagram</label>
                    <input type="url" name="instagram_url" value="<?= htmlspecialchars($settings['instagram_url'] ?? '') ?>" placeholder="https://instagram.com/yourpage" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-pink-500">
                </div>
            </div>
        </div>
        
        <!-- Auto Confirm -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-lg font-semibold mb-4"><i class="fas fa-cog mr-2 text-gray-500"></i>ตั้งค่าเพิ่มเติม</h3>
            <div class="space-y-4">
                <div class="flex items-center justify-between p-4 bg-blue-50 rounded-lg">
                    <div>
                        <p class="font-medium text-blue-800">ยืนยันการชำระเงินอัตโนมัติ</p>
                        <p class="text-sm text-blue-600">ระบบจะยืนยันออเดอร์อัตโนมัติเมื่อได้รับสลิป</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="auto_confirm_payment" class="sr-only peer" <?= ($settings['auto_confirm_payment'] ?? 0) ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-500"></div>
                    </label>
                </div>
            </div>
        </div>
        
        <!-- Payment Settings -->
        <div class="bg-white rounded-xl shadow p-6 lg:col-span-2">
            <h3 class="text-lg font-semibold mb-4">ช่องทางชำระเงิน</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">พร้อมเพย์</label>
                    <input type="text" name="promptpay_number" value="<?= htmlspecialchars($settings['promptpay_number'] ?? '') ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="เบอร์โทรหรือเลขบัตรประชาชน">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">บัญชีธนาคาร</label>
                    <div id="bankAccounts" class="space-y-3">
                        <?php foreach ($bankAccounts as $i => $bank): ?>
                        <div class="flex space-x-2 bank-row">
                            <input type="text" name="bank_name[]" value="<?= htmlspecialchars($bank['name']) ?>" placeholder="ธนาคาร" class="flex-1 px-4 py-2 border rounded-lg">
                            <input type="text" name="bank_account[]" value="<?= htmlspecialchars($bank['account']) ?>" placeholder="เลขบัญชี" class="flex-1 px-4 py-2 border rounded-lg">
                            <input type="text" name="bank_holder[]" value="<?= htmlspecialchars($bank['holder']) ?>" placeholder="ชื่อบัญชี" class="flex-1 px-4 py-2 border rounded-lg">
                            <button type="button" onclick="this.parentElement.remove()" class="px-3 py-2 text-red-500 hover:bg-red-50 rounded-lg"><i class="fas fa-times"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addBankRow()" class="mt-2 px-4 py-2 border rounded-lg hover:bg-gray-50 text-sm">
                        <i class="fas fa-plus mr-2"></i>เพิ่มบัญชี
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-6">
        <button type="submit" class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
            <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
        </button>
    </div>
</form>

<script>
function addBankRow() {
    const html = `
        <div class="flex space-x-2 bank-row">
            <input type="text" name="bank_name[]" placeholder="ธนาคาร" class="flex-1 px-4 py-2 border rounded-lg">
            <input type="text" name="bank_account[]" placeholder="เลขบัญชี" class="flex-1 px-4 py-2 border rounded-lg">
            <input type="text" name="bank_holder[]" placeholder="ชื่อบัญชี" class="flex-1 px-4 py-2 border rounded-lg">
            <button type="button" onclick="this.parentElement.remove()" class="px-3 py-2 text-red-500 hover:bg-red-50 rounded-lg"><i class="fas fa-times"></i></button>
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
