<?php
/**
 * LIFF Settings - ตั้งค่า LIFF ครบทุกฟังก์ชัน
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่า LIFF';

// Get all LINE accounts
$accounts = $db->query("SELECT * FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC")->fetchAll(PDO::FETCH_ASSOC);
$currentAccount = $accounts[0] ?? null;

// Get config LIFF IDs
$configLiffId = defined('LIFF_ID') ? LIFF_ID : '';
$configLiffShareId = defined('LIFF_SHARE_ID') ? LIFF_SHARE_ID : '';

// Handle POST
$message = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_account_liff') {
            $accountId = intval($_POST['account_id']);
            $liffId = trim($_POST['liff_id']);
            $db->prepare("UPDATE line_accounts SET liff_id = ? WHERE id = ?")->execute([$liffId, $accountId]);
            $message = "บันทึก LIFF ID สำหรับบัญชีสำเร็จ!";
            // Refresh accounts
            $accounts = $db->query("SELECT * FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

require_once 'includes/header.php';
$baseUrl = rtrim(BASE_URL, '/');
?>

<style>
.liff-card { transition: all 0.2s; }
.liff-card:hover { transform: translateY(-2px); }
.copy-btn:active { transform: scale(0.95); }
</style>

<div class="space-y-6">

    <!-- Unified LIFF Recommendation -->
    <div class="bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl shadow-lg p-6 text-white">
        <div class="flex items-start gap-4">
            <div class="text-4xl">🚀</div>
            <div class="flex-1">
                <h2 class="text-xl font-bold mb-2">แนะนำ: Unified LIFF App (ระบบใหม่)</h2>
                <p class="text-green-100 mb-4">ใช้ LIFF ID เดียวสำหรับทุกฟังก์ชัน! ง่ายต่อการจัดการ ไม่ต้องสร้างหลาย LIFF</p>
                <div class="bg-white/20 rounded-lg p-4 mb-4">
                    <p class="text-sm font-medium mb-2">Endpoint URL สำหรับสร้าง LIFF (ระบบใหม่):</p>
                    <div class="flex gap-2">
                        <input type="text" value="<?= $baseUrl ?>/liff/" readonly 
                            class="flex-1 px-3 py-2 bg-white/30 rounded-lg font-mono text-sm" onclick="this.select()">
                        <button onclick="copyText('<?= $baseUrl ?>/liff/')" class="px-4 py-2 bg-white/30 hover:bg-white/40 rounded-lg">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-xs">
                    <span class="bg-white/20 px-2 py-1 rounded">✅ หน้าหลัก</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ ร้านค้า</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ ตะกร้า</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ ออเดอร์</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ โปรไฟล์</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ นัดหมาย</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ วิดีโอคอล</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ AI Chat</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ แต้มสะสม</span>
                    <span class="bg-white/20 px-2 py-1 rounded">✅ Health Profile</span>
                </div>
                <div class="mt-4 flex gap-2">
                    <a href="<?= $baseUrl ?>/liff/" target="_blank" class="px-4 py-2 bg-white text-green-600 rounded-lg font-bold hover:bg-green-50 transition">
                        <i class="fas fa-external-link-alt mr-1"></i>ทดสอบ LIFF ใหม่
                    </a>
                    <a href="<?= $baseUrl ?>/liff/#/shop" target="_blank" class="px-4 py-2 bg-white/20 text-white rounded-lg hover:bg-white/30 transition">
                        <i class="fas fa-store mr-1"></i>หน้าร้านค้า
                    </a>
                    <a href="<?= $baseUrl ?>/liff/#/appointments" target="_blank" class="px-4 py-2 bg-white/20 text-white rounded-lg hover:bg-white/30 transition">
                        <i class="fas fa-calendar mr-1"></i>นัดหมาย
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <?php if ($message): ?>
    <div class="p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="p-4 bg-red-100 text-red-700 rounded-lg flex items-center">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Overview -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-lg font-bold mb-4 flex items-center">
            <span class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center mr-3">📱</span>
            LIFF คืออะไร?
        </h2>
        <p class="text-gray-600 mb-4">
            LIFF (LINE Front-end Framework) คือเทคโนโลยีที่ช่วยให้เปิดหน้าเว็บภายใน LINE App ได้ 
            ทำให้ผู้ใช้ไม่ต้องออกจาก LINE และสามารถดึงข้อมูลโปรไฟล์ผู้ใช้ได้อัตโนมัติ
        </p>
        
        <!-- LIFF ที่ต้องสร้าง -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-4">
            <h3 class="font-bold text-yellow-800 mb-2"><i class="fas fa-exclamation-triangle mr-1"></i>LIFF ที่ต้องสร้าง (แนะนำ 3 ตัว)</h3>
            <div class="text-sm text-yellow-700 space-y-1">
                <p>1. <strong>LIFF Shop</strong> - สำหรับหน้าร้านค้า+Checkout (ตั้งค่าแยกตาม LINE Account)</p>
                <p>2. <strong>LIFF Video Call</strong> - สำหรับวิดีโอคอล (ตั้งค่าใน config.php → LIFF_ID)</p>
                <p>3. <strong>LIFF Share</strong> - สำหรับแชร์ข้อความ (ตั้งค่าใน config.php → LIFF_SHARE_ID)</p>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-purple-50 rounded-lg p-4 border-2 <?= !empty($currentAccount['liff_id'] ?? '') ? 'border-green-400' : 'border-red-300' ?>">
                <div class="text-2xl mb-2">🛒</div>
                <h3 class="font-semibold">LIFF Shop</h3>
                <p class="text-sm text-gray-600">หน้าร้านค้า+Checkout</p>
                <p class="text-xs mt-2 <?= !empty($currentAccount['liff_id'] ?? '') ? 'text-green-600' : 'text-red-500' ?>">
                    <?= !empty($currentAccount['liff_id'] ?? '') ? '✓ ตั้งค่าแล้ว' : '✗ ยังไม่ได้ตั้งค่า' ?>
                </p>
            </div>
            <div class="bg-blue-50 rounded-lg p-4 border-2 border-gray-200">
                <div class="text-2xl mb-2">💳</div>
                <h3 class="font-semibold">Checkout</h3>
                <p class="text-sm text-gray-600">ใช้ร่วมกับ Shop</p>
                <p class="text-xs mt-2 text-blue-500">ไม่ต้องสร้างแยก</p>
            </div>
            <div class="bg-green-50 rounded-lg p-4 border-2 <?= !empty($configLiffId) ? 'border-green-400' : 'border-red-300' ?>">
                <div class="text-2xl mb-2">📹</div>
                <h3 class="font-semibold">Video Call</h3>
                <p class="text-sm text-gray-600">วิดีโอคอลใน LINE</p>
                <p class="text-xs mt-2 <?= !empty($configLiffId) ? 'text-green-600' : 'text-red-500' ?>">
                    <?= !empty($configLiffId) ? '✓ ตั้งค่าแล้ว' : '✗ ยังไม่ได้ตั้งค่า' ?>
                </p>
            </div>
            <div class="bg-orange-50 rounded-lg p-4 border-2 <?= !empty($configLiffShareId) ? 'border-green-400' : 'border-red-300' ?>">
                <div class="text-2xl mb-2">📤</div>
                <h3 class="font-semibold">Share</h3>
                <p class="text-sm text-gray-600">แชร์ข้อความ</p>
                <p class="text-xs mt-2 <?= !empty($configLiffShareId) ? 'text-green-600' : 'text-red-500' ?>">
                    <?= !empty($configLiffShareId) ? '✓ ตั้งค่าแล้ว' : '✗ ยังไม่ได้ตั้งค่า' ?>
                </p>
            </div>
        </div>
    </div>

    <!-- LIFF IDs per Account -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-5 border-b bg-gradient-to-r from-indigo-500 to-purple-600 text-white">
            <h2 class="font-bold flex items-center">
                <i class="fab fa-line mr-2"></i>LIFF ID ตามบัญชี LINE
            </h2>
            <p class="text-indigo-100 text-sm mt-1">แต่ละบัญชี LINE สามารถมี LIFF ID แยกกันได้</p>
        </div>
        <div class="p-5">
            <?php if (empty($accounts)): ?>
            <p class="text-gray-500 text-center py-8">ยังไม่มีบัญชี LINE <a href="line-accounts.php" class="text-blue-500 hover:underline">เพิ่มบัญชี</a></p>
            <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($accounts as $acc): ?>
                <form method="POST" class="liff-card border rounded-xl p-4 hover:shadow-md">
                    <input type="hidden" name="action" value="update_account_liff">
                    <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <?php if ($acc['picture_url']): ?>
                            <img src="<?= htmlspecialchars($acc['picture_url']) ?>" class="w-10 h-10 rounded-full">
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                                <i class="fab fa-line text-green-500"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <h3 class="font-semibold"><?= htmlspecialchars($acc['name']) ?></h3>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($acc['basic_id'] ?: 'Channel: ' . $acc['channel_id']) ?></p>
                            </div>
                        </div>
                        <?php if ($acc['is_default']): ?>
                        <span class="px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full">⭐ หลัก</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2">
                        <input type="text" name="liff_id" value="<?= htmlspecialchars($acc['liff_id'] ?? '') ?>" 
                               class="flex-1 px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 font-mono text-sm"
                               placeholder="LIFF ID เช่น 2001234567-aBcDeFgH">
                        <button type="submit" class="px-4 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600">
                            <i class="fas fa-save"></i>
                        </button>
                    </div>
                    <?php if (!empty($acc['liff_id'])): ?>
                    <div class="mt-2 flex items-center gap-2 text-sm">
                        <span class="text-gray-500">URL:</span>
                        <a href="https://liff.line.me/<?= htmlspecialchars($acc['liff_id']) ?>" target="_blank" 
                           class="text-blue-500 hover:underline font-mono">
                            https://liff.line.me/<?= htmlspecialchars($acc['liff_id']) ?>
                        </a>
                        <button type="button" onclick="copyText('https://liff.line.me/<?= htmlspecialchars($acc['liff_id']) ?>')" 
                                class="copy-btn text-gray-400 hover:text-gray-600">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Global LIFF IDs (config.php) -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-5 border-b bg-gradient-to-r from-orange-500 to-red-500 text-white">
            <h2 class="font-bold flex items-center">
                <i class="fas fa-cog mr-2"></i>LIFF ID ใน config.php (Video Call & Share)
            </h2>
            <p class="text-orange-100 text-sm mt-1">ใช้สำหรับฟีเจอร์ที่ไม่ผูกกับบัญชี LINE เฉพาะ</p>
        </div>
        <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="border rounded-lg p-4 <?= $configLiffId ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50' ?>">
                    <label class="block text-sm font-medium mb-2">
                        📹 LIFF_ID <span class="text-gray-500">(Video Call)</span>
                        <?= $configLiffId ? '<span class="text-green-600">✓</span>' : '<span class="text-red-500">✗ ต้องตั้งค่า</span>' ?>
                    </label>
                    <div class="flex gap-2">
                        <input type="text" value="<?= htmlspecialchars($configLiffId ?: 'ยังไม่ได้ตั้งค่า') ?>" readonly
                               class="flex-1 px-3 py-2 bg-white border rounded-lg font-mono text-sm <?= $configLiffId ? 'text-green-600' : 'text-red-400' ?>">
                        <?php if ($configLiffId): ?>
                        <button onclick="copyText('https://liff.line.me/<?= htmlspecialchars($configLiffId) ?>')" class="px-3 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg"><i class="fas fa-copy"></i></button>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Endpoint: <code class="bg-gray-100 px-1 rounded"><?= $baseUrl ?>/liff-video-call-pro.php</code></p>
                </div>
                <div class="border rounded-lg p-4 <?= $configLiffShareId ? 'border-green-300 bg-green-50' : 'border-red-300 bg-red-50' ?>">
                    <label class="block text-sm font-medium mb-2">
                        📤 LIFF_SHARE_ID <span class="text-gray-500">(Share)</span>
                        <?= $configLiffShareId ? '<span class="text-green-600">✓</span>' : '<span class="text-red-500">✗ ต้องตั้งค่า</span>' ?>
                    </label>
                    <div class="flex gap-2">
                        <input type="text" value="<?= htmlspecialchars($configLiffShareId ?: 'ยังไม่ได้ตั้งค่า') ?>" readonly
                               class="flex-1 px-3 py-2 bg-white border rounded-lg font-mono text-sm <?= $configLiffShareId ? 'text-green-600' : 'text-red-400' ?>">
                        <?php if ($configLiffShareId): ?>
                        <button onclick="copyText('https://liff.line.me/<?= htmlspecialchars($configLiffShareId) ?>')" class="px-3 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg"><i class="fas fa-copy"></i></button>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">Endpoint: <code class="bg-gray-100 px-1 rounded"><?= $baseUrl ?>/liff-share.php</code></p>
                </div>
            </div>
            
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <p class="font-medium text-blue-700 mb-2"><i class="fas fa-edit mr-1"></i>วิธีแก้ไข LIFF ID ใน config.php</p>
                <ol class="text-blue-600 text-sm space-y-1 list-decimal list-inside">
                    <li>เปิดไฟล์ <code class="bg-blue-100 px-1 rounded">config/config.php</code></li>
                    <li>หาบรรทัด <code class="bg-blue-100 px-1 rounded">define('LIFF_ID', '');</code></li>
                    <li>ใส่ LIFF ID ที่สร้างจาก LINE Developers Console</li>
                    <li>บันทึกไฟล์</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Endpoint URLs -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-5 border-b bg-gradient-to-r from-cyan-500 to-blue-500 text-white">
            <h2 class="font-bold flex items-center">
                <i class="fas fa-link mr-2"></i>Endpoint URLs สำหรับตั้งค่า LIFF
            </h2>
            <p class="text-cyan-100 text-sm mt-1">ใช้ URL เหล่านี้เมื่อสร้าง LIFF App ใน LINE Developers Console</p>
        </div>
        <div class="p-5">
            <div class="space-y-3">
                <?php 
                $endpoints = [
                    ['name' => '🆕 LIFF App ใหม่ (แนะนำ)', 'url' => $baseUrl . '/liff/', 'desc' => 'ระบบ LIFF ใหม่ครบทุกฟังก์ชัน (ร้านค้า, นัดหมาย, วิดีโอคอล, AI Chat)', 'icon' => '🚀', 'config' => 'ตั้งค่าในแต่ละ LINE Account', 'color' => 'green'],
                    ['name' => 'LIFF Shop (เดิม)', 'url' => $baseUrl . '/liff-shop.php', 'desc' => 'หน้าร้านค้าและชำระเงิน (ระบบเดิม)', 'icon' => '🛒', 'config' => 'ตั้งค่าในแต่ละ LINE Account', 'color' => 'purple'],
                    ['name' => 'LIFF Video Call', 'url' => $baseUrl . '/liff-video-call-pro.php', 'desc' => 'วิดีโอคอลกับลูกค้า', 'icon' => '📹', 'config' => 'LIFF_ID ใน config.php', 'color' => 'blue'],
                    ['name' => 'LIFF Share', 'url' => $baseUrl . '/liff-share.php', 'desc' => 'แชร์ข้อความไปยังเพื่อน', 'icon' => '📤', 'config' => 'LIFF_SHARE_ID ใน config.php', 'color' => 'orange'],
                ];
                foreach ($endpoints as $ep): ?>
                <div class="flex items-center gap-4 p-4 bg-<?= $ep['color'] ?>-50 rounded-xl border border-<?= $ep['color'] ?>-200">
                    <div class="text-3xl"><?= $ep['icon'] ?></div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-<?= $ep['color'] ?>-700"><?= $ep['name'] ?></div>
                        <div class="text-xs text-gray-600"><?= $ep['desc'] ?></div>
                        <div class="text-xs text-<?= $ep['color'] ?>-600 mt-1"><i class="fas fa-cog mr-1"></i><?= $ep['config'] ?></div>
                    </div>
                    <div class="flex-1">
                        <input type="text" value="<?= $ep['url'] ?>" readonly 
                               class="w-full px-3 py-2 bg-white border rounded-lg font-mono text-xs" onclick="this.select()">
                    </div>
                    <button onclick="copyText('<?= $ep['url'] ?>')" class="copy-btn px-3 py-2 bg-<?= $ep['color'] ?>-200 hover:bg-<?= $ep['color'] ?>-300 rounded-lg text-<?= $ep['color'] ?>-700">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="mt-4 p-4 bg-green-100 rounded-xl border border-green-200">
                <p class="text-sm text-green-700">
                    <i class="fas fa-lightbulb text-green-500 mr-1"></i>
                    <strong>แนะนำ:</strong> ใช้ <code class="bg-green-200 px-1 rounded"><?= $baseUrl ?>/liff/</code> เป็น Endpoint หลัก เพราะรองรับทุกฟังก์ชันในที่เดียว
                </p>
            </div>
        </div>
    </div>

    <!-- Setup Guide -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-5 border-b bg-gradient-to-r from-green-500 to-emerald-600 text-white">
            <h2 class="font-bold flex items-center">
                <i class="fas fa-book mr-2"></i>วิธีสร้าง LIFF App
            </h2>
        </div>
        <div class="p-5">
            <div class="space-y-4">
                <div class="flex gap-4">
                    <div class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">1</div>
                    <div>
                        <h3 class="font-semibold">เข้า LINE Developers Console</h3>
                        <p class="text-gray-600 text-sm">ไปที่ <a href="https://developers.line.biz/console/" target="_blank" class="text-blue-500 hover:underline">developers.line.biz/console</a></p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">2</div>
                    <div>
                        <h3 class="font-semibold">เลือก Provider → LINE Login Channel</h3>
                        <p class="text-gray-600 text-sm">ถ้ายังไม่มี ให้สร้าง LINE Login Channel ใหม่</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">3</div>
                    <div>
                        <h3 class="font-semibold">ไปที่แท็บ LIFF → Add</h3>
                        <div class="bg-gray-50 rounded-lg p-4 mt-2 text-sm">
                            <p><strong>LIFF app name:</strong> LINE Shop</p>
                            <p><strong>Size:</strong> Full</p>
                            <p><strong>Endpoint URL:</strong> <span class="text-green-600"><?= $baseUrl ?>/liff-shop.php</span></p>
                            <p><strong>Scope:</strong> ✅ profile, ✅ openid</p>
                            <p><strong>Bot link feature:</strong> On (Aggressive)</p>
                        </div>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">4</div>
                    <div>
                        <h3 class="font-semibold">คัดลอก LIFF ID</h3>
                        <p class="text-gray-600 text-sm">นำ LIFF ID ที่ได้มาใส่ในช่องด้านบน</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <div class="w-8 h-8 bg-blue-500 text-white rounded-full flex items-center justify-center font-bold flex-shrink-0">5</div>
                    <div>
                        <h3 class="font-semibold">ตั้งค่า Linked OA (สำคัญ!)</h3>
                        <p class="text-gray-600 text-sm">ใน LIFF settings → Linked OA → เลือก LINE OA ของคุณ</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Section -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-lg font-bold mb-4">🧪 ทดสอบ LIFF</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <a href="liff/" target="_blank" class="block p-4 bg-green-100 rounded-xl hover:bg-green-200 text-center border-2 border-green-300">
                <div class="text-3xl mb-2">🚀</div>
                <div class="font-medium text-green-700">LIFF ใหม่</div>
                <div class="text-xs text-green-600">ระบบใหม่ครบทุกฟังก์ชัน</div>
            </a>
            <a href="liff/#/shop" target="_blank" class="block p-4 bg-purple-50 rounded-xl hover:bg-purple-100 text-center">
                <div class="text-3xl mb-2">🛒</div>
                <div class="font-medium">ร้านค้า</div>
                <div class="text-xs text-gray-500">LIFF ใหม่</div>
            </a>
            <a href="liff/#/appointments" target="_blank" class="block p-4 bg-blue-50 rounded-xl hover:bg-blue-100 text-center">
                <div class="text-3xl mb-2">📅</div>
                <div class="font-medium">นัดหมาย</div>
                <div class="text-xs text-gray-500">LIFF ใหม่</div>
            </a>
            <a href="liff/#/video-call" target="_blank" class="block p-4 bg-teal-50 rounded-xl hover:bg-teal-100 text-center">
                <div class="text-3xl mb-2">📹</div>
                <div class="font-medium">Video Call</div>
                <div class="text-xs text-gray-500">LIFF ใหม่</div>
            </a>
            <?php if ($currentAccount && !empty($currentAccount['liff_id'])): ?>
            <a href="https://liff.line.me/<?= htmlspecialchars($currentAccount['liff_id']) ?>" target="_blank" 
               class="block p-4 bg-green-100 rounded-xl hover:bg-green-200 text-center border-2 border-green-300">
                <div class="text-3xl mb-2">📱</div>
                <div class="font-medium text-green-700">เปิดใน LINE</div>
                <div class="text-xs text-green-600">ใช้ LIFF URL จริง</div>
            </a>
            <?php else: ?>
            <div class="block p-4 bg-gray-100 rounded-xl text-center opacity-50">
                <div class="text-3xl mb-2">📱</div>
                <div class="font-medium">เปิดใน LINE</div>
                <div class="text-xs text-gray-500">ต้องตั้งค่า LIFF ID ก่อน</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Legacy LIFF Links -->
        <div class="mt-4 pt-4 border-t">
            <p class="text-sm text-gray-500 mb-3">LIFF เดิม (สำหรับความเข้ากันได้):</p>
            <div class="flex flex-wrap gap-2">
                <a href="liff-shop.php" target="_blank" class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200">
                    <i class="fas fa-store mr-1"></i>LIFF Shop เดิม
                </a>
                <a href="liff-checkout.php" target="_blank" class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200">
                    <i class="fas fa-credit-card mr-1"></i>Checkout เดิม
                </a>
                <a href="liff-video-call-pro.php" target="_blank" class="px-3 py-1.5 bg-gray-100 text-gray-600 rounded-lg text-sm hover:bg-gray-200">
                    <i class="fas fa-video mr-1"></i>Video Call เดิม
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function copyText(text) {
    navigator.clipboard.writeText(text).then(() => {
        Swal.fire({ icon: 'success', title: 'คัดลอกแล้ว!', timer: 1500, showConfirmButton: false });
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
