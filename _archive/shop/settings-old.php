<?php
/**
 * Shop - ตั้งค่าร้านค้า
 * V2.5 - รองรับ Multi-bot (แยกการตั้งค่าตาม LINE Account)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่าร้านค้า';
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// ตรวจสอบและสร้างตาราง shop_settings ถ้ายังไม่มี
$tableExists = false;
try {
    $db->query("SELECT 1 FROM shop_settings LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {
    // สร้างตาราง
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS shop_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT DEFAULT NULL,
            shop_name VARCHAR(255) DEFAULT 'LINE Shop',
            shop_logo VARCHAR(500),
            welcome_message TEXT,
            shipping_fee DECIMAL(10,2) DEFAULT 50,
            free_shipping_min DECIMAL(10,2) DEFAULT 500,
            bank_accounts TEXT,
            promptpay_number VARCHAR(20),
            contact_phone VARCHAR(20),
            is_open TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $tableExists = true;
    } catch (Exception $e2) {
        $error = "ไม่สามารถสร้างตารางได้: " . $e2->getMessage();
    }
}

// ตรวจสอบว่ามี column line_account_id หรือไม่
$hasAccountCol = false;
if ($tableExists) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM shop_settings LIKE 'line_account_id'");
        $hasAccountCol = $stmt->rowCount() > 0;
        
        // เพิ่ม column ถ้ายังไม่มี
        if (!$hasAccountCol) {
            $db->exec("ALTER TABLE shop_settings ADD COLUMN line_account_id INT DEFAULT NULL AFTER id");
            $hasAccountCol = true;
        }
    } catch (Exception $e) {}
    
    // Ensure all columns exist (add missing columns)
    $columnsToAdd = [
        'shop_logo' => "VARCHAR(500) DEFAULT NULL",
        'cod_enabled' => "TINYINT(1) DEFAULT 0",
        'cod_fee' => "DECIMAL(10,2) DEFAULT 0",
        'auto_confirm_payment' => "TINYINT(1) DEFAULT 0",
        'shop_address' => "TEXT DEFAULT NULL",
        'shop_email' => "VARCHAR(255) DEFAULT NULL",
        'line_id' => "VARCHAR(100) DEFAULT NULL",
        'facebook_url' => "VARCHAR(500) DEFAULT NULL",
        'instagram_url' => "VARCHAR(500) DEFAULT NULL"
    ];
    
    foreach ($columnsToAdd as $col => $type) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM shop_settings LIKE '$col'");
            if ($stmt->rowCount() == 0) {
                $db->exec("ALTER TABLE shop_settings ADD COLUMN $col $type");
            }
        } catch (Exception $e) {}
    }
}

// Handle POST BEFORE including header (to allow redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $currentBotId = $_SESSION['current_bot_id'] ?? 1;
    $bankAccounts = json_encode(['banks' => array_map(function($name, $account, $holder) {
        return ['name' => $name, 'account' => $account, 'holder' => $holder];
    }, $_POST['bank_name'] ?? [], $_POST['bank_account'] ?? [], $_POST['bank_holder'] ?? [])]);
    
    try {
        // Handle logo upload
        $logoUrl = $_POST['shop_logo'] ?? '';
        if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/shop/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileExt = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($fileExt, $allowedExts)) {
                $fileName = 'logo_' . $currentBotId . '_' . time() . '.' . $fileExt;
                $uploadPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploadPath)) {
                    $logoUrl = rtrim(BASE_URL, '/') . '/uploads/shop/' . $fileName;
                }
            }
        }
        
        $updateFields = [
            'shop_name' => $_POST['shop_name'] ?? '',
            'shop_logo' => $logoUrl,
            'welcome_message' => $_POST['welcome_message'] ?? '',
            'shop_address' => $_POST['shop_address'] ?? '',
            'shop_email' => $_POST['shop_email'] ?? '',
            'shipping_fee' => (float)($_POST['shipping_fee'] ?? 50),
            'free_shipping_min' => (float)($_POST['free_shipping_min'] ?? 500),
            'bank_accounts' => $bankAccounts,
            'promptpay_number' => $_POST['promptpay_number'] ?? '',
            'contact_phone' => $_POST['contact_phone'] ?? '',
            'is_open' => isset($_POST['is_open']) ? 1 : 0,
            'cod_enabled' => isset($_POST['cod_enabled']) ? 1 : 0,
            'cod_fee' => (float)($_POST['cod_fee'] ?? 0),
            'auto_confirm_payment' => isset($_POST['auto_confirm_payment']) ? 1 : 0,
            'line_id' => $_POST['line_id'] ?? '',
            'facebook_url' => $_POST['facebook_url'] ?? '',
            'instagram_url' => $_POST['instagram_url'] ?? ''
        ];
        
        if ($hasAccountCol && $currentBotId) {
            // Check if settings exist for this bot
            $stmt = $db->prepare("SELECT id FROM shop_settings WHERE line_account_id = ?");
            $stmt->execute([$currentBotId]);
            $existingId = $stmt->fetchColumn();
            
            if ($existingId) {
                // Update existing
                $setClauses = [];
                $values = [];
                foreach ($updateFields as $field => $value) {
                    $setClauses[] = "$field = ?";
                    $values[] = $value;
                }
                $values[] = $currentBotId;
                
                $stmt = $db->prepare("UPDATE shop_settings SET " . implode(', ', $setClauses) . " WHERE line_account_id = ?");
                $stmt->execute($values);
            } else {
                // Insert new for this bot
                $fields = array_keys($updateFields);
                $fields[] = 'line_account_id';
                $values = array_values($updateFields);
                $values[] = $currentBotId;
                $placeholders = array_fill(0, count($values), '?');
                
                $stmt = $db->prepare("INSERT INTO shop_settings (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
                $stmt->execute($values);
            }
        } else {
            // Legacy mode - update id=1
            $setClauses = [];
            $values = [];
            foreach ($updateFields as $field => $value) {
                $setClauses[] = "$field = ?";
                $values[] = $value;
            }
            
            $stmt = $db->prepare("UPDATE shop_settings SET " . implode(', ', $setClauses) . " WHERE id = 1");
            $stmt->execute($values);
            
            if ($stmt->rowCount() == 0) {
                // Insert if not exists
                $fields = array_keys($updateFields);
                $placeholders = array_fill(0, count($updateFields), '?');
                $stmt = $db->prepare("INSERT INTO shop_settings (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
                $stmt->execute(array_values($updateFields));
            }
        }
        header('Location: settings.php?saved=1');
        exit;
    } catch (Exception $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// Include header after POST handling
require_once '../includes/header.php';

// Get settings
$settings = [];
if ($tableExists) {
    try {
        if ($hasAccountCol && $currentBotId) {
            // Get settings for current bot
            $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ?");
            $stmt->execute([$currentBotId]);
            $settings = $stmt->fetch();
        }
        
        // Fallback to legacy settings if not found
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

<?php if (isset($_GET['saved'])): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i>บันทึกการตั้งค่าสำเร็จ!
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
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
                            <!-- Upload Button -->
                            <div class="flex items-center gap-2">
                                <label class="px-4 py-2 bg-blue-500 text-white rounded-lg cursor-pointer hover:bg-blue-600 transition text-sm">
                                    <i class="fas fa-upload mr-1"></i>อัพโหลดรูป
                                    <input type="file" name="logo_file" accept="image/*" class="hidden" id="logoFileInput" onchange="previewLogo(this)">
                                </label>
                                <span class="text-xs text-gray-500">หรือ</span>
                            </div>
                            <!-- URL Input -->
                            <input type="url" name="shop_logo" id="logoUrlInput" value="<?= htmlspecialchars($settings['shop_logo'] ?? '') ?>" placeholder="วาง URL รูปโลโก้" class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 text-sm" onchange="previewLogoUrl(this)">
                            <p class="text-xs text-gray-400">ขนาดแนะนำ: 200x200 px (รูปสี่เหลี่ยมจัตุรัส)</p>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">ข้อความต้อนรับ</label>
                    <textarea name="welcome_message" rows="3" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="เช่น: ยินดีต้อนรับสู่ร้านยาของเรา! ส่งฟรีเมื่อซื้อครบ 500 บาท"><?= htmlspecialchars($settings['welcome_message'] ?? '') ?></textarea>
                    <p class="text-xs text-gray-400 mt-1">ข้อความนี้จะแสดงในแบนเนอร์ต้อนรับบนหน้าร้าน</p>
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
                
                <!-- COD Settings -->
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
                        <p class="text-xs text-gray-500 mt-1">ค่าธรรมเนียมเพิ่มเติมสำหรับ COD</p>
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
                
                <!-- Quick Links -->
                <div class="border-t pt-4">
                    <h4 class="font-medium mb-3">ลิงก์ด่วน</h4>
                    <div class="grid grid-cols-2 gap-2">
                        <a href="liff-shop-settings.php" class="flex items-center gap-2 p-3 bg-teal-50 text-teal-700 rounded-lg hover:bg-teal-100 transition">
                            <i class="fas fa-mobile-alt"></i>
                            <span class="text-sm">ตั้งค่า LIFF Shop</span>
                        </a>
                        <a href="promotions.php" class="flex items-center gap-2 p-3 bg-pink-50 text-pink-700 rounded-lg hover:bg-pink-100 transition">
                            <i class="fas fa-star"></i>
                            <span class="text-sm">สินค้าเด่น/ขายดี</span>
                        </a>
                        <a href="categories.php" class="flex items-center gap-2 p-3 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-100 transition">
                            <i class="fas fa-tags"></i>
                            <span class="text-sm">จัดการหมวดหมู่</span>
                        </a>
                        <a href="products.php" class="flex items-center gap-2 p-3 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 transition">
                            <i class="fas fa-box"></i>
                            <span class="text-sm">จัดการสินค้า</span>
                        </a>
                        <a href="orders.php" class="flex items-center gap-2 p-3 bg-yellow-50 text-yellow-700 rounded-lg hover:bg-yellow-100 transition">
                            <i class="fas fa-shopping-cart"></i>
                            <span class="text-sm">จัดการออเดอร์</span>
                        </a>
                        <a href="<?= rtrim(BASE_URL, '/') ?>/liff/#/shop" target="_blank" class="flex items-center gap-2 p-3 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition">
                            <i class="fas fa-external-link-alt"></i>
                            <span class="text-sm">ดูหน้าร้าน (LIFF ใหม่)</span>
                        </a>
                    </div>
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

// Logo preview functions
function previewLogo(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('logoPreview');
            const previewDiv = document.getElementById('logoPreviewDiv');
            preview.src = e.target.result;
            preview.classList.remove('hidden');
            if (previewDiv) previewDiv.classList.add('hidden');
            // Clear URL input when file is selected
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
        // Clear file input when URL is entered
        document.getElementById('logoFileInput').value = '';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
