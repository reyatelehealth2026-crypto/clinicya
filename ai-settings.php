<?php
/**
 * AI Settings - ตั้งค่า Gemini API
 * Version 2.0 - รองรับโหมดขาย/เภสัชกร/ซัพพอร์ต
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่า AI (Gemini)';

// Ensure all columns exist
try {
    $columns = ['gemini_api_key', 'ai_mode', 'business_info', 'product_knowledge', 'sales_prompt', 'auto_load_products', 'product_load_limit'];
    foreach ($columns as $col) {
        $stmt = $db->query("SHOW COLUMNS FROM ai_settings LIKE '$col'");
        if ($stmt->rowCount() == 0) {
            switch ($col) {
                case 'gemini_api_key': $db->exec("ALTER TABLE ai_settings ADD COLUMN gemini_api_key VARCHAR(255)"); break;
                case 'ai_mode': $db->exec("ALTER TABLE ai_settings ADD COLUMN ai_mode ENUM('pharmacist','sales','support') DEFAULT 'sales'"); break;
                case 'business_info': $db->exec("ALTER TABLE ai_settings ADD COLUMN business_info TEXT"); break;
                case 'product_knowledge': $db->exec("ALTER TABLE ai_settings ADD COLUMN product_knowledge TEXT"); break;
                case 'sales_prompt': $db->exec("ALTER TABLE ai_settings ADD COLUMN sales_prompt TEXT"); break;
                case 'auto_load_products': $db->exec("ALTER TABLE ai_settings ADD COLUMN auto_load_products TINYINT(1) DEFAULT 1"); break;
                case 'product_load_limit': $db->exec("ALTER TABLE ai_settings ADD COLUMN product_load_limit INT DEFAULT 50"); break;
            }
        }
    }
} catch (Exception $e) {}

$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Hardcoded model — ใช้ Gemini Flash latest เท่านั้น
const AI_DEFAULT_MODEL = 'gemini-2.0-flash';

function getAISettings($db, $botId = null) {
    try {
        $stmt = $botId ? $db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ?") : $db->prepare("SELECT * FROM ai_settings WHERE line_account_id IS NULL LIMIT 1");
        $botId ? $stmt->execute([$botId]) : $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { return []; }
}

function saveAISettings($db, $data, $botId = null) {
    try {
        $stmt = $botId ? $db->prepare("SELECT id FROM ai_settings WHERE line_account_id = ?") : $db->prepare("SELECT id FROM ai_settings WHERE line_account_id IS NULL");
        $botId ? $stmt->execute([$botId]) : $stmt->execute();
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt = $db->prepare("UPDATE ai_settings SET is_enabled=?, system_prompt=?, model=?, gemini_api_key=?, ai_mode=?, business_info=?, product_knowledge=?, sales_prompt=?, auto_load_products=?, product_load_limit=? WHERE id=?");
            $stmt->execute([$data['is_enabled']??0, $data['system_prompt']??'', AI_DEFAULT_MODEL, $data['gemini_api_key']??'', $data['ai_mode']??'sales', $data['business_info']??'', $data['product_knowledge']??'', $data['sales_prompt']??'', $data['auto_load_products']??1, $data['product_load_limit']??50, $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO ai_settings (line_account_id, is_enabled, system_prompt, model, gemini_api_key, ai_mode, business_info, product_knowledge, sales_prompt, auto_load_products, product_load_limit) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$botId, $data['is_enabled']??0, $data['system_prompt']??'', AI_DEFAULT_MODEL, $data['gemini_api_key']??'', $data['ai_mode']??'sales', $data['business_info']??'', $data['product_knowledge']??'', $data['sales_prompt']??'', $data['auto_load_products']??1, $data['product_load_limit']??50]);
        }
        return true;
    } catch (Exception $e) { return false; }
}

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $data = [
        'gemini_api_key' => trim($_POST['gemini_api_key'] ?? ''),
        'is_enabled' => isset($_POST['ai_enabled']) ? 1 : 0,
        'system_prompt' => trim($_POST['system_prompt'] ?? ''),
        'ai_mode' => $_POST['ai_mode'] ?? 'sales',
        'business_info' => trim($_POST['business_info'] ?? ''),
        'product_knowledge' => trim($_POST['product_knowledge'] ?? ''),
        'sales_prompt' => trim($_POST['sales_prompt'] ?? ''),
        'auto_load_products' => isset($_POST['auto_load_products']) ? 1 : 0,
        'product_load_limit' => intval($_POST['product_load_limit'] ?? 50)
    ];
    $success = saveAISettings($db, $data, $currentBotId) ? 'บันทึกการตั้งค่าสำเร็จ!' : null;
    $error = !$success ? 'เกิดข้อผิดพลาด' : null;
}

$settings = getAISettings($db, $currentBotId);
$geminiApiKey = $settings['gemini_api_key'] ?? '';
$aiEnabled = ($settings['is_enabled'] ?? 0) == 1;
$systemPrompt = $settings['system_prompt'] ?? '';
$aiMode = $settings['ai_mode'] ?? 'sales';
$businessInfo = $settings['business_info'] ?? '';
$productKnowledge = $settings['product_knowledge'] ?? '';
$salesPrompt = $settings['sales_prompt'] ?? '';
$autoLoadProducts = ($settings['auto_load_products'] ?? 1) == 1;
$productLoadLimit = $settings['product_load_limit'] ?? 50;

$productCount = 0;
try { $stmt = $db->prepare("SELECT COUNT(*) FROM business_items WHERE is_active=1 AND (line_account_id=? OR line_account_id IS NULL)"); $stmt->execute([$currentBotId]); $productCount = $stmt->fetchColumn(); } catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl mx-auto">
    <?php if ($success): ?><div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg"><i class="fas fa-check-circle mr-2"></i><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg"><i class="fas fa-exclamation-circle mr-2"></i><?= $error ?></div><?php endif; ?>

    <form method="POST">
    <input type="hidden" name="action" value="save_settings">
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <!-- API Settings -->
            <div class="bg-white rounded-xl shadow">
                <div class="p-4 border-b"><h3 class="font-semibold"><i class="fas fa-key text-yellow-500 mr-2"></i>ตั้งค่า Gemini API</h3></div>
                <div class="p-6 space-y-4">
                    <label class="flex items-center p-4 bg-gray-50 rounded-lg cursor-pointer">
                        <input type="checkbox" name="ai_enabled" value="1" <?= $aiEnabled ? 'checked' : '' ?> class="w-5 h-5 text-green-500 rounded mr-3">
                        <div><span class="font-medium">เปิดใช้งาน AI</span><p class="text-sm text-gray-500">เปิดใช้งานฟีเจอร์ AI ในระบบ</p></div>
                    </label>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">Gemini API Key *</label>
                        <input type="password" name="gemini_api_key" id="apiKey" value="<?= htmlspecialchars($geminiApiKey) ?>" class="w-full px-4 py-3 border rounded-lg" placeholder="AIzaSy...">
                        <p class="text-xs text-gray-500 mt-1"><a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-blue-500"><i class="fas fa-external-link-alt mr-1"></i>รับ API Key ฟรี</a></p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium mb-2">โหมด AI</label>
                        <select name="ai_mode" id="aiMode" class="w-full px-4 py-3 border rounded-lg" onchange="toggleSales()">
                            <option value="sales" <?= $aiMode === 'sales' ? 'selected' : '' ?>>🛒 พนักงานขาย</option>
                            <option value="support" <?= $aiMode === 'support' ? 'selected' : '' ?>>💬 ซัพพอร์ต</option>
                            <option value="pharmacist" <?= $aiMode === 'pharmacist' ? 'selected' : '' ?>>💊 เภสัชกร</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-2"><i class="fas fa-bolt text-yellow-500 mr-1"></i>โมเดล: <strong>Gemini 2.0 Flash</strong> (ใช้รุ่นล่าสุดอัตโนมัติ)</p>
                    </div>
                </div>
            </div>
            
            <!-- Business Info -->
            <div class="bg-white rounded-xl shadow">
                <div class="p-4 border-b"><h3 class="font-semibold"><i class="fas fa-store text-blue-500 mr-2"></i>ข้อมูลธุรกิจ</h3></div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">ข้อมูลร้าน/ธุรกิจ</label>
                        <textarea name="business_info" rows="4" class="w-full px-4 py-3 border rounded-lg" placeholder="ชื่อร้าน: ...&#10;ที่อยู่: ...&#10;เวลาทำการ: ..."><?= htmlspecialchars($businessInfo) ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">System Prompt (บทบาทหลัก)</label>
                        <textarea name="system_prompt" rows="3" class="w-full px-4 py-3 border rounded-lg" placeholder="คุณคือพนักงานขายของร้าน..."><?= htmlspecialchars($systemPrompt) ?></textarea>
                    </div>
                    <div id="salesSection" class="<?= $aiMode !== 'sales' ? 'hidden' : '' ?>">
                        <label class="block text-sm font-medium mb-2">คำแนะนำสำหรับการขาย</label>
                        <textarea name="sales_prompt" rows="3" class="w-full px-4 py-3 border rounded-lg" placeholder="เน้นแนะนำโปรโมชั่น..."><?= htmlspecialchars($salesPrompt) ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Product Knowledge -->
            <div class="bg-white rounded-xl shadow">
                <div class="p-4 border-b flex justify-between items-center">
                    <h3 class="font-semibold"><i class="fas fa-box text-emerald-500 mr-2"></i>ข้อมูลสินค้า</h3>
                    <span class="text-sm text-gray-500"><?= number_format($productCount) ?> รายการ</span>
                </div>
                <div class="p-6 space-y-4">
                    <label class="flex items-center justify-between p-4 bg-emerald-50 rounded-lg cursor-pointer">
                        <div class="flex items-center">
                            <input type="checkbox" name="auto_load_products" value="1" <?= $autoLoadProducts ? 'checked' : '' ?> class="w-5 h-5 text-emerald-500 rounded mr-3">
                            <div><span class="font-medium text-emerald-800">โหลดสินค้าอัตโนมัติ</span><p class="text-sm text-emerald-600">AI จะดึงข้อมูลสินค้าจากระบบ</p></div>
                        </div>
                        <input type="number" name="product_load_limit" value="<?= $productLoadLimit ?>" min="10" max="200" class="w-20 px-3 py-2 border rounded-lg text-center">
                    </label>
                    <div>
                        <label class="block text-sm font-medium mb-2">ข้อมูลสินค้าเพิ่มเติม</label>
                        <textarea name="product_knowledge" rows="4" class="w-full px-4 py-3 border rounded-lg font-mono text-sm" placeholder="โปรโมชั่นพิเศษ:&#10;- สินค้า A ลด 20%&#10;- ส่งฟรีเมื่อซื้อครบ 500 บาท"><?= htmlspecialchars($productKnowledge) ?></textarea>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="w-full py-4 bg-green-500 text-white rounded-xl hover:bg-green-600 font-medium text-lg">
                <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
            </button>
        </div>
        
        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow p-6">
                <h4 class="font-semibold mb-4"><i class="fas fa-info-circle text-gray-500 mr-2"></i>สถานะ</h4>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">API Key:</span><span class="<?= $geminiApiKey ? 'text-green-600' : 'text-red-600' ?>"><?= $geminiApiKey ? '✅ ตั้งค่าแล้ว' : '❌ ยังไม่ได้ตั้งค่า' ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">สถานะ:</span><span><?= $aiEnabled ? '🟢 เปิด' : '⚪ ปิด' ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">โมเดล:</span><span class="text-blue-600">⚡ Gemini 2.0 Flash</span></div>
                    <div class="flex justify-between"><span class="text-gray-500">โหมด:</span><span class="px-2 py-1 rounded text-xs <?= $aiMode === 'sales' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100' ?>"><?= $aiMode === 'sales' ? '🛒 พนักงานขาย' : ($aiMode === 'pharmacist' ? '💊 เภสัชกร' : '💬 ซัพพอร์ต') ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">สินค้า:</span><span class="text-blue-600"><?= number_format($productCount) ?> รายการ</span></div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow p-6">
                <h4 class="font-semibold mb-4"><i class="fas fa-robot text-purple-500 mr-2"></i>โหมด AI</h4>
                <div class="space-y-3 text-sm">
                    <div class="p-3 bg-emerald-50 rounded-lg"><div class="font-medium text-emerald-700">🛒 พนักงานขาย</div><p class="text-emerald-600 text-xs">แนะนำสินค้า บอกราคา ชวนสั่งซื้อ</p></div>
                    <div class="p-3 bg-gray-50 rounded-lg"><div class="font-medium text-gray-700">💬 ซัพพอร์ต</div><p class="text-gray-600 text-xs">ตอบคำถาม แก้ปัญหา</p></div>
                    <div class="p-3 bg-blue-50 rounded-lg"><div class="font-medium text-blue-700">💊 เภสัชกร</div><p class="text-blue-600 text-xs">ซักประวัติ แนะนำยา</p></div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow p-6">
                <h4 class="font-semibold mb-4"><i class="fas fa-flask text-purple-500 mr-2"></i>ทดสอบ API</h4>
                <button type="button" onclick="testAPI()" class="w-full px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 text-sm"><i class="fas fa-play mr-2"></i>ทดสอบ</button>
                <div id="testResult" class="mt-3"></div>
            </div>
        </div>
    </div>
    </form>
</div>

<script>
function toggleSales() {
    document.getElementById('salesSection').classList.toggle('hidden', document.getElementById('aiMode').value !== 'sales');
}
async function testAPI() {
    const key = document.getElementById('apiKey').value;
    const r = document.getElementById('testResult');
    if (!key) { r.innerHTML = '<div class="p-2 bg-red-50 text-red-600 rounded text-xs">กรุณากรอก API Key</div>'; return; }
    r.innerHTML = '<div class="p-2 bg-gray-50 rounded text-xs"><i class="fas fa-spinner fa-spin mr-1"></i>กำลังทดสอบ...</div>';
    try {
        const res = await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' + key, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contents: [{ parts: [{ text: 'ตอบสั้นๆ: สวัสดี' }] }] })
        });
        const data = await res.json();
        r.innerHTML = data.candidates ? '<div class="p-2 bg-green-50 text-green-600 rounded text-xs">✅ เชื่อมต่อสำเร็จ!</div>' : '<div class="p-2 bg-red-50 text-red-600 rounded text-xs">❌ ' + (data.error?.message || 'Error') + '</div>';
    } catch (e) { r.innerHTML = '<div class="p-2 bg-red-50 text-red-600 rounded text-xs">❌ ' + e.message + '</div>'; }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
