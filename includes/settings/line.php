<?php
/**
 * LINE Accounts Settings Tab Content
 * Part of consolidated settings.php
 */

// Auto-migrate columns
try {
    $cols = $db->query("SHOW COLUMNS FROM line_accounts")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bot_mode', $cols)) {
        $db->exec("ALTER TABLE line_accounts ADD COLUMN bot_mode ENUM('shop', 'general', 'auto_reply_only') DEFAULT 'shop'");
    }
    if (!in_array('welcome_message', $cols)) {
        $db->exec("ALTER TABLE line_accounts ADD COLUMN welcome_message TEXT");
    }
    if (!in_array('auto_reply_enabled', $cols)) {
        $db->exec("ALTER TABLE line_accounts ADD COLUMN auto_reply_enabled TINYINT(1) DEFAULT 1");
    }
    if (!in_array('shop_enabled', $cols)) {
        $db->exec("ALTER TABLE line_accounts ADD COLUMN shop_enabled TINYINT(1) DEFAULT 1");
    }
    if (!in_array('rich_menu_id', $cols)) {
        $db->exec("ALTER TABLE line_accounts ADD COLUMN rich_menu_id VARCHAR(100)");
    }
} catch (Exception $e) {}

$manager = new LineAccountManager($db);
$accounts = $manager->getAllAccounts();
?>

<style>
.account-card { transition: all 0.2s; }
.account-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
.line-tab-btn.active { border-bottom: 2px solid #10B981; color: #10B981; }
</style>

<div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
    <div>
        <h2 class="text-xl font-bold text-gray-800">บัญชี LINE Official Account</h2>
        <p class="text-gray-600">จัดการบัญชี LINE OA และตั้งค่าต่างๆ</p>
    </div>
    <button onclick="openLineModal()" class="px-5 py-2.5 bg-green-500 text-white rounded-lg hover:bg-green-600 shadow-lg hover:shadow-xl transition">
        <i class="fas fa-plus mr-2"></i>เพิ่มบัญชี LINE
    </button>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg flex items-center">
    <i class="fas fa-check-circle mr-2"></i>
    <?= ['created'=>'เพิ่มบัญชีสำเร็จ','updated'=>'อัพเดทสำเร็จ','deleted'=>'ลบสำเร็จ','default'=>'ตั้งเป็นบัญชีหลักสำเร็จ'][$_GET['success']] ?? 'สำเร็จ' ?>
</div>
<?php endif; ?>

<!-- Account Cards -->
<div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
    <?php foreach ($accounts as $account): 
        $botMode = $account['bot_mode'] ?? 'shop';
        $modeInfo = [
            'shop' => ['icon'=>'🛒','label'=>'ร้านค้า','color'=>'purple'],
            'general' => ['icon'=>'💬','label'=>'ทั่วไป','color'=>'blue'],
            'auto_reply_only' => ['icon'=>'🤖','label'=>'Auto Reply','color'=>'orange']
        ][$botMode] ?? ['icon'=>'❓','label'=>$botMode,'color'=>'gray'];
    ?>
    <div class="account-card bg-white rounded-2xl shadow-lg overflow-hidden <?= $account['is_default'] ? 'ring-2 ring-green-500' : '' ?>">
        <!-- Header -->
        <div class="p-5 bg-gradient-to-r from-green-500 to-emerald-600 text-white">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <?php if ($account['picture_url']): ?>
                    <img src="<?= htmlspecialchars($account['picture_url']) ?>" class="w-14 h-14 rounded-full border-2 border-white shadow">
                    <?php else: ?>
                    <div class="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center">
                        <i class="fab fa-line text-3xl"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <h3 class="font-bold text-lg"><?= htmlspecialchars($account['name']) ?></h3>
                        <p class="text-green-100 text-sm"><?= htmlspecialchars($account['basic_id'] ?: 'ไม่มี Basic ID') ?></p>
                    </div>
                </div>
                <?php if ($account['is_default']): ?>
                <span class="px-3 py-1 bg-yellow-400 text-yellow-900 text-xs font-bold rounded-full">⭐ หลัก</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Status Badges -->
        <div class="px-5 py-3 bg-gray-50 flex flex-wrap gap-2">
            <span class="px-2 py-1 text-xs rounded-full <?= $account['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                <?= $account['is_active'] ? '✓ Active' : '✗ Inactive' ?>
            </span>
            <span class="px-2 py-1 text-xs rounded-full bg-<?= $modeInfo['color'] ?>-100 text-<?= $modeInfo['color'] ?>-700">
                <?= $modeInfo['icon'] ?> <?= $modeInfo['label'] ?>
            </span>
            <?php if (!empty($account['liff_id'])): ?>
            <span class="px-2 py-1 text-xs rounded-full bg-indigo-100 text-indigo-700">📱 LIFF</span>
            <?php endif; ?>
        </div>

        <!-- Info -->
        <div class="p-5 space-y-3 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-500">Channel ID</span>
                <span class="font-mono text-gray-700"><?= htmlspecialchars($account['channel_id'] ?? '-') ?></span>
            </div>
            <?php if (!empty($account['liff_id'])): ?>
            <div class="flex justify-between">
                <span class="text-gray-500">LIFF ID</span>
                <span class="font-mono text-green-600 text-xs"><?= htmlspecialchars($account['liff_id']) ?></span>
            </div>
            <?php endif; ?>
            <div>
                <span class="text-gray-500 text-xs">Webhook URL:</span>
                <div class="flex mt-1">
                    <input type="text" readonly value="<?= BASE_URL ?>webhook.php?account=<?= $account['id'] ?>" 
                           class="flex-1 text-xs bg-gray-100 border-0 rounded-l px-2 py-1.5 font-mono" id="webhook_<?= $account['id'] ?>">
                    <button onclick="copyWebhook(<?= $account['id'] ?>)" class="px-3 bg-gray-200 hover:bg-gray-300 rounded-r text-gray-600">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="px-5 pb-5 grid grid-cols-4 gap-2">
            <button onclick="testLineConnection(<?= $account['id'] ?>)" class="p-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 text-center" title="ทดสอบ">
                <i class="fas fa-plug"></i>
            </button>
            <button onclick='editLineAccount(<?= json_encode($account) ?>)' class="p-2 bg-gray-50 text-gray-600 rounded-lg hover:bg-gray-100 text-center" title="แก้ไข">
                <i class="fas fa-cog"></i>
            </button>
            <button onclick="showLineStats(<?= $account['id'] ?>)" class="p-2 bg-purple-50 text-purple-600 rounded-lg hover:bg-purple-100 text-center" title="สถิติ">
                <i class="fas fa-chart-bar"></i>
            </button>
            <?php if (!$account['is_default']): ?>
            <form method="POST" class="contents">
                <input type="hidden" name="action" value="set_default_line">
                <input type="hidden" name="id" value="<?= $account['id'] ?>">
                <button type="submit" class="p-2 bg-yellow-50 text-yellow-600 rounded-lg hover:bg-yellow-100 text-center" title="ตั้งเป็นหลัก">
                    <i class="fas fa-star"></i>
                </button>
            </form>
            <?php else: ?>
            <div class="p-2 bg-yellow-100 text-yellow-600 rounded-lg text-center">
                <i class="fas fa-star"></i>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($accounts)): ?>
    <div class="col-span-full">
        <div class="text-center py-16 bg-white rounded-2xl shadow">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fab fa-line text-4xl text-green-500"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">ยังไม่มีบัญชี LINE</h3>
            <p class="text-gray-500 mb-6">เริ่มต้นเพิ่มบัญชี LINE Official Account แรกของคุณ</p>
            <button onclick="openLineModal()" class="px-6 py-3 bg-green-500 text-white rounded-xl hover:bg-green-600 shadow-lg">
                <i class="fas fa-plus mr-2"></i>เพิ่มบัญชีแรก
            </button>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- LINE Account Modal -->
<div id="lineModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
        <form method="POST" id="lineAccountForm">
            <input type="hidden" name="action" id="lineFormAction" value="create_line">
            <input type="hidden" name="id" id="lineFormId">
            
            <!-- Header -->
            <div class="p-5 border-b flex justify-between items-center bg-gradient-to-r from-green-500 to-emerald-600 text-white">
                <h3 class="text-lg font-bold" id="lineModalTitle"><i class="fab fa-line mr-2"></i>เพิ่มบัญชี LINE</h3>
                <button type="button" onclick="closeLineModal()" class="w-8 h-8 rounded-full hover:bg-white/20 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Tabs -->
            <div class="flex border-b bg-gray-50">
                <button type="button" onclick="showLineTab('basic')" class="line-tab-btn active flex-1 py-3 text-sm font-medium" data-tab="basic">
                    <i class="fas fa-info-circle mr-1"></i>ข้อมูลพื้นฐาน
                </button>
                <button type="button" onclick="showLineTab('settings')" class="line-tab-btn flex-1 py-3 text-sm font-medium" data-tab="settings">
                    <i class="fas fa-cog mr-1"></i>ตั้งค่า
                </button>
                <button type="button" onclick="showLineTab('advanced')" class="line-tab-btn flex-1 py-3 text-sm font-medium" data-tab="advanced">
                    <i class="fas fa-sliders-h mr-1"></i>ขั้นสูง
                </button>
            </div>

            <!-- Content -->
            <div class="flex-1 overflow-y-auto p-5">
                <!-- Tab: Basic -->
                <div id="line-tab-basic" class="line-tab-content space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-sm font-medium mb-1">ชื่อบัญชี <span class="text-red-500">*</span></label>
                            <input type="text" name="name" id="line_name" required class="w-full px-4 py-2.5 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500" placeholder="เช่น ร้านค้า A">
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-sm font-medium mb-1">LINE Basic ID</label>
                            <input type="text" name="basic_id" id="line_basic_id" class="w-full px-4 py-2.5 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="@yourshop">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">Channel ID</label>
                        <input type="text" name="channel_id" id="line_channel_id" class="w-full px-4 py-2.5 border rounded-lg focus:ring-2 focus:ring-green-500 font-mono" placeholder="1234567890">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">Channel Secret <span class="text-red-500">*</span></label>
                        <input type="text" name="channel_secret" id="line_channel_secret" required class="w-full px-4 py-2.5 border rounded-lg focus:ring-2 focus:ring-green-500 font-mono text-sm">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">Channel Access Token <span class="text-red-500">*</span></label>
                        <textarea name="channel_access_token" id="line_channel_access_token" required rows="3" class="w-full px-4 py-2.5 border rounded-lg focus:ring-2 focus:ring-green-500 font-mono text-xs"></textarea>
                    </div>
                    
                    <div class="bg-blue-50 p-4 rounded-xl">
                        <p class="font-medium text-blue-700 mb-2"><i class="fas fa-info-circle mr-1"></i>วิธีรับ Credentials</p>
                        <ol class="list-decimal list-inside text-blue-600 text-sm space-y-1">
                            <li>ไปที่ <a href="https://developers.line.biz/console/" target="_blank" class="underline font-medium">LINE Developers Console</a></li>
                            <li>เลือก Provider → Channel (Messaging API)</li>
                            <li>คัดลอก Channel ID, Channel Secret</li>
                            <li>ไปที่ Messaging API → Issue Channel Access Token</li>
                        </ol>
                    </div>
                </div>

                <!-- Tab: Settings -->
                <div id="line-tab-settings" class="line-tab-content space-y-4 hidden">
                    <div>
                        <label class="block text-sm font-medium mb-2">โหมดบอท <span class="text-red-500">*</span></label>
                        <div class="space-y-2">
                            <label class="flex items-start p-4 border-2 rounded-xl cursor-pointer hover:border-green-300 transition has-[:checked]:border-green-500 has-[:checked]:bg-green-50">
                                <input type="radio" name="bot_mode" value="shop" checked class="mt-1 mr-3 text-green-500">
                                <div>
                                    <span class="font-semibold text-gray-800">🛒 โหมดร้านค้า</span>
                                    <p class="text-xs text-gray-500 mt-1">ระบบร้านค้าเต็มรูปแบบ: สินค้า, ตะกร้า, สั่งซื้อ, Auto Reply, Broadcast, CRM</p>
                                </div>
                            </label>
                            <label class="flex items-start p-4 border-2 rounded-xl cursor-pointer hover:border-blue-300 transition has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                <input type="radio" name="bot_mode" value="general" class="mt-1 mr-3 text-blue-500">
                                <div>
                                    <span class="font-semibold text-gray-800">💬 โหมดทั่วไป</span>
                                    <p class="text-xs text-gray-500 mt-1">ไม่มีระบบร้านค้า: Auto Reply, Broadcast, CRM เท่านั้น</p>
                                </div>
                            </label>
                            <label class="flex items-start p-4 border-2 rounded-xl cursor-pointer hover:border-orange-300 transition has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                                <input type="radio" name="bot_mode" value="auto_reply_only" class="mt-1 mr-3 text-orange-500">
                                <div>
                                    <span class="font-semibold text-gray-800">🤖 Auto Reply เท่านั้น</span>
                                    <p class="text-xs text-gray-500 mt-1">ตอบกลับอัตโนมัติตาม keyword เท่านั้น</p>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">ข้อความต้อนรับ</label>
                        <textarea name="welcome_message" id="line_welcome_message" rows="3" class="w-full px-4 py-2.5 border rounded-lg focus:ring-2 focus:ring-green-500" placeholder="ข้อความที่จะส่งเมื่อมีคนเพิ่มเพื่อน..."></textarea>
                        <p class="text-xs text-gray-500 mt-1">ใช้ {name} แทนชื่อผู้ใช้</p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <label class="flex items-center p-4 border rounded-xl cursor-pointer hover:bg-gray-50">
                            <input type="checkbox" name="auto_reply_enabled" id="line_auto_reply_enabled" checked class="mr-3 w-5 h-5 text-green-500 rounded">
                            <div>
                                <span class="font-medium">🤖 Auto Reply</span>
                                <p class="text-xs text-gray-500">เปิดระบบตอบกลับอัตโนมัติ</p>
                            </div>
                        </label>
                        <label class="flex items-center p-4 border rounded-xl cursor-pointer hover:bg-gray-50">
                            <input type="checkbox" name="shop_enabled" id="line_shop_enabled" checked class="mr-3 w-5 h-5 text-green-500 rounded">
                            <div>
                                <span class="font-medium">🛒 ร้านค้า</span>
                                <p class="text-xs text-gray-500">เปิดระบบร้านค้า</p>
                            </div>
                        </label>
                    </div>
                    
                    <div class="flex gap-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_active" id="line_is_active" checked class="mr-2 w-5 h-5 text-green-500 rounded">
                            <span>เปิดใช้งาน</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_default" id="line_is_default" class="mr-2 w-5 h-5 text-green-500 rounded">
                            <span>ตั้งเป็นบัญชีหลัก</span>
                        </label>
                    </div>
                </div>

                <!-- Tab: Advanced -->
                <div id="line-tab-advanced" class="line-tab-content space-y-4 hidden">
                    <div class="bg-green-50 p-4 rounded-xl mb-4">
                        <p class="font-medium text-green-700 mb-2"><i class="fas fa-magic mr-1"></i>Unified LIFF (แนะนำ)</p>
                        <p class="text-green-600 text-sm">ใช้ LIFF ID เดียวสำหรับทุกฟังก์ชัน - สมัครสมาชิก, ซื้อสินค้า, แลกแต้ม, นัดหมาย ฯลฯ</p>
                    </div>
                    
                    <div class="p-5 border-2 border-green-300 rounded-xl bg-gradient-to-br from-green-50 to-emerald-50">
                        <label class="block text-sm font-medium mb-2 text-green-700">
                            <i class="fas fa-mobile-alt mr-1"></i>LIFF ID (Unified)
                        </label>
                        <input type="text" name="liff_id" id="line_liff_id" class="w-full px-4 py-3 border-2 border-green-200 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 font-mono text-lg" placeholder="2006xxxxxx-xxxxxxxx">
                        <p class="text-sm text-green-600 mt-2">
                            <i class="fas fa-link mr-1"></i>Endpoint URL: <code class="bg-white px-2 py-1 rounded"><?= BASE_URL ?>liff-app.php</code>
                        </p>
                    </div>
                    
                    <div class="bg-yellow-50 p-4 rounded-xl">
                        <p class="font-medium text-yellow-700 mb-2"><i class="fas fa-lightbulb mr-1"></i>วิธีสร้าง LIFF App</p>
                        <ol class="text-yellow-600 text-sm list-decimal list-inside space-y-1">
                            <li>ไปที่ <a href="https://developers.line.biz/console/" target="_blank" class="underline font-medium">LINE Developers Console</a></li>
                            <li>เลือก Provider → Channel (LINE Login)</li>
                            <li>ไปที่ LIFF → Add</li>
                            <li>ตั้งชื่อ เช่น "Unified App"</li>
                            <li>Size: <strong>Full</strong> (แนะนำ)</li>
                            <li>Endpoint URL: <code class="bg-white px-1 rounded"><?= BASE_URL ?>liff-app.php</code></li>
                            <li>Scopes: openid, profile</li>
                            <li>คัดลอก LIFF ID มาใส่ในช่องด้านบน</li>
                        </ol>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="p-5 border-t flex justify-between bg-gray-50">
                <button type="button" id="lineDeleteBtn" onclick="deleteLineAccount()" class="px-4 py-2 text-red-500 hover:bg-red-50 rounded-lg hidden">
                    <i class="fas fa-trash mr-1"></i>ลบบัญชี
                </button>
                <div class="flex gap-2 ml-auto">
                    <button type="button" onclick="closeLineModal()" class="px-5 py-2.5 border rounded-lg hover:bg-gray-100">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2.5 bg-green-500 text-white rounded-lg hover:bg-green-600 shadow">
                        <i class="fas fa-save mr-1"></i>บันทึก
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Test Modal -->
<div id="lineTestModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-6">
        <div id="lineTestResult" class="text-center py-8"></div>
        <button onclick="closeLineTestModal()" class="w-full mt-4 px-4 py-2.5 bg-gray-100 rounded-lg hover:bg-gray-200 font-medium">ปิด</button>
    </div>
</div>

<script>
let currentLineAccountId = null;

function showLineTab(tab) {
    document.querySelectorAll('.line-tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.line-tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('line-tab-' + tab).classList.remove('hidden');
    document.querySelector(`.line-tab-btn[data-tab="${tab}"]`).classList.add('active');
}

function openLineModal() {
    document.getElementById('lineModal').classList.remove('hidden');
    document.getElementById('lineModal').classList.add('flex');
    document.getElementById('lineFormAction').value = 'create_line';
    document.getElementById('lineModalTitle').innerHTML = '<i class="fab fa-line mr-2"></i>เพิ่มบัญชี LINE';
    document.getElementById('lineDeleteBtn').classList.add('hidden');
    document.getElementById('lineAccountForm').reset();
    document.getElementById('line_is_active').checked = true;
    document.getElementById('line_auto_reply_enabled').checked = true;
    document.getElementById('line_shop_enabled').checked = true;
    showLineTab('basic');
}

function closeLineModal() {
    document.getElementById('lineModal').classList.add('hidden');
    document.getElementById('lineModal').classList.remove('flex');
}

function editLineAccount(account) {
    currentLineAccountId = account.id;
    document.getElementById('lineModal').classList.remove('hidden');
    document.getElementById('lineModal').classList.add('flex');
    document.getElementById('lineFormAction').value = 'update_line';
    document.getElementById('lineFormId').value = account.id;
    document.getElementById('lineModalTitle').innerHTML = '<i class="fas fa-cog mr-2"></i>ตั้งค่าบัญชี: ' + account.name;
    document.getElementById('lineDeleteBtn').classList.remove('hidden');
    
    document.getElementById('line_name').value = account.name || '';
    document.getElementById('line_channel_id').value = account.channel_id || '';
    document.getElementById('line_channel_secret').value = account.channel_secret || '';
    document.getElementById('line_channel_access_token').value = account.channel_access_token || '';
    document.getElementById('line_basic_id').value = account.basic_id || '';
    document.getElementById('line_liff_id').value = account.liff_id || '';
    document.getElementById('line_welcome_message').value = account.welcome_message || '';
    document.getElementById('line_is_active').checked = account.is_active == 1;
    document.getElementById('line_is_default').checked = account.is_default == 1;
    document.getElementById('line_auto_reply_enabled').checked = account.auto_reply_enabled != 0;
    document.getElementById('line_shop_enabled').checked = account.shop_enabled != 0;
    
    document.querySelectorAll('input[name="bot_mode"]').forEach(el => {
        el.checked = el.value === (account.bot_mode || 'shop');
    });
    
    showLineTab('basic');
}

function deleteLineAccount() {
    if (!currentLineAccountId || !confirm('ต้องการลบบัญชีนี้? ข้อมูลทั้งหมดจะถูกลบ')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `<input type="hidden" name="action" value="delete_line"><input type="hidden" name="id" value="${currentLineAccountId}">`;
    document.body.appendChild(form);
    form.submit();
}

function copyWebhook(id) {
    const input = document.getElementById('webhook_' + id);
    input.select();
    document.execCommand('copy');
    alert('คัดลอก Webhook URL แล้ว!');
}

function testLineConnection(accountId) {
    document.getElementById('lineTestModal').classList.remove('hidden');
    document.getElementById('lineTestModal').classList.add('flex');
    document.getElementById('lineTestResult').innerHTML = '<i class="fas fa-spinner fa-spin text-4xl text-gray-400"></i><p class="mt-3 text-gray-500">กำลังทดสอบ...</p>';
    
    fetch('settings.php?tab=line', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: `action=test_line_connection&id=${accountId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('lineTestResult').innerHTML = `
                <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
                <h3 class="text-xl font-bold text-green-600">เชื่อมต่อสำเร็จ!</h3>
                ${data.data?.displayName ? `<p class="text-gray-600 mt-2 text-lg">${data.data.displayName}</p>` : ''}
                ${data.data?.pictureUrl ? `<img src="${data.data.pictureUrl}" class="w-20 h-20 rounded-full mx-auto mt-4 border-4 border-green-200">` : ''}
            `;
        } else {
            document.getElementById('lineTestResult').innerHTML = `
                <i class="fas fa-times-circle text-6xl text-red-500 mb-4"></i>
                <h3 class="text-xl font-bold text-red-600">เชื่อมต่อไม่สำเร็จ</h3>
                <p class="text-gray-600 mt-2">${data.message || 'กรุณาตรวจสอบ credentials'}</p>
            `;
        }
    })
    .catch(err => {
        document.getElementById('lineTestResult').innerHTML = `<i class="fas fa-exclamation-triangle text-6xl text-yellow-500 mb-4"></i><p class="text-gray-600">${err.message}</p>`;
    });
}

function closeLineTestModal() {
    document.getElementById('lineTestModal').classList.add('hidden');
    document.getElementById('lineTestModal').classList.remove('flex');
}

function showLineStats(accountId) {
    alert('ดูสถิติบัญชี ID: ' + accountId);
}

// Close modals on backdrop click
['lineModal', 'lineTestModal'].forEach(id => {
    document.getElementById(id)?.addEventListener('click', e => {
        if (e.target.id === id) {
            document.getElementById(id).classList.add('hidden');
            document.getElementById(id).classList.remove('flex');
        }
    });
});
</script>
