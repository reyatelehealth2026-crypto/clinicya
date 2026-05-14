<?php
/**
 * AI Settings - ตั้งค่า Gemini API
 * Version 2.0 - รองรับโหมดขาย/เภสัชกร/ซัพพอร์ต
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/form-section.php';
require_once __DIR__ . '/includes/components/field.php';
require_once __DIR__ . '/includes/components/toggle.php';
require_once __DIR__ . '/includes/components/sticky-save-bar.php';
require_once __DIR__ . '/includes/components/toast.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่า AI (Gemini)';

/** โมเดลคงที่ — ไม่มีตัวเลือกในหน้าตั้งค่า (อัปเดตอัตโนมัติตามช่องทาง Google) */
const AI_SETTINGS_GEMINI_MODEL = 'gemini-flash-latest';

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
            $stmt->execute([$data['is_enabled']??0, $data['system_prompt']??'', AI_SETTINGS_GEMINI_MODEL, $data['gemini_api_key']??'', $data['ai_mode']??'sales', $data['business_info']??'', $data['product_knowledge']??'', $data['sales_prompt']??'', $data['auto_load_products']??1, $data['product_load_limit']??50, $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO ai_settings (line_account_id, is_enabled, system_prompt, model, gemini_api_key, ai_mode, business_info, product_knowledge, sales_prompt, auto_load_products, product_load_limit) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$botId, $data['is_enabled']??0, $data['system_prompt']??'', AI_SETTINGS_GEMINI_MODEL, $data['gemini_api_key']??'', $data['ai_mode']??'sales', $data['business_info']??'', $data['product_knowledge']??'', $data['sales_prompt']??'', $data['auto_load_products']??1, $data['product_load_limit']??50]);
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
echo getPageHeaderStyles();
echo getFormSectionStyles();
echo getFieldStyles();
echo getToggleStyles();
echo getStickySaveBarStyles();
echo getToastStyles();
?>

<?= renderToastContainer() ?>

<?php if ($success): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ fireToast(<?= json_encode($success) ?>,'success'); });</script>
<?php endif; ?>
<?php if ($error): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ fireToast(<?= json_encode($error) ?>,'error'); });</script>
<?php endif; ?>

<?= renderPageHeader('ตั้งค่า AI (Gemini)', 'กำหนดค่า Gemini API, โหมด AI และข้อมูลธุรกิจ', null, [['label' => 'หน้าหลัก', 'href' => '/'], ['label' => 'ตั้งค่า AI', 'href' => null]]) ?>

<div class="max-w-5xl mx-auto">
    <a href="/ai-telepharmacy-settings.php" class="block mb-4 p-4 bg-gradient-to-r from-purple-50 to-blue-50 border border-purple-200 rounded-xl hover:shadow-md transition">
        <div class="flex items-center justify-between">
            <div>
                <div class="font-semibold text-purple-700">💊 AI Telepharmacy — ตั้งค่าครบชุด</div>
                <p class="text-sm text-purple-600 mt-0.5">จัดการสินค้าที่ AI แนะนำได้, จับคู่อาการ → สินค้า, red flag, คำถาม Yes/No, ทดสอบ AI sandbox</p>
            </div>
            <i class="fas fa-arrow-right text-purple-500"></i>
        </div>
    </a>

    <form method="POST" id="aiSettingsForm">
    <input type="hidden" name="action" value="save_settings">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <?php
            // --- Section: API Settings ---
            $apiBody  = renderToggle('ai_enabled', 'เปิดใช้งาน AI', $aiEnabled, 'เปิดใช้งานฟีเจอร์ AI ในระบบ');
            $apiBody .= renderField('gemini_api_key', 'Gemini API Key', 'password', $geminiApiKey, [
                'id'          => 'apiKey',
                'placeholder' => 'AIzaSy...',
                'required'    => true,
                'help'        => '<a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-blue-500"><i class="fas fa-external-link-alt mr-1"></i>รับ API Key ฟรี</a>',
            ]);
            $apiBody .= '<div class="p-4 bg-slate-50 rounded-lg border border-slate-100 mb-4">'
                      . '<p class="text-sm font-medium text-slate-800 mb-1">โมเดล</p>'
                      . '<p class="text-sm text-slate-600">ใช้ <code class="text-xs bg-white px-1 py-0.5 rounded border">gemini-flash-latest</code> เท่านั้น (Gemini Flash รุ่นล่าสุดจาก Google — ไม่ต้องเลือกโมเดล)</p>'
                      . '</div>';
            $apiBody .= renderField('ai_mode', 'โหมด AI', 'select', $aiMode, [
                'choices' => [
                    'sales'      => '🛒 พนักงานขาย',
                    'support'    => '💬 ซัพพอร์ต',
                    'pharmacist' => '💊 เภสัชกร',
                ],
                'id' => 'aiMode',
                'class' => 'onchange-toggle-sales',
            ]);
            echo renderFormSection('ตั้งค่า Gemini API', 'fas fa-key', '', $apiBody);

            // --- Section: Business Info ---
            $bizBody  = renderField('business_info', 'ข้อมูลร้าน/ธุรกิจ', 'textarea', $businessInfo, [
                'rows'        => 4,
                'placeholder' => 'ชื่อร้าน: ...' . "\n" . 'ที่อยู่: ...' . "\n" . 'เวลาทำการ: ...',
            ]);
            $bizBody .= renderField('system_prompt', 'System Prompt (บทบาทหลัก)', 'textarea', $systemPrompt, [
                'rows'        => 3,
                'placeholder' => 'คุณคือพนักงานขายของร้าน...',
            ]);
            $bizBody .= '<div id="salesSection"' . ($aiMode !== 'sales' ? ' style="display:none"' : '') . '>'
                      . renderField('sales_prompt', 'คำแนะนำสำหรับการขาย', 'textarea', $salesPrompt, [
                            'rows'        => 3,
                            'placeholder' => 'เน้นแนะนำโปรโมชั่น...',
                        ])
                      . '</div>';
            echo renderFormSection('ข้อมูลธุรกิจ', 'fas fa-store', '', $bizBody);

            // --- Section: Product Knowledge ---
            $prodBody  = renderToggle('auto_load_products', 'โหลดสินค้าอัตโนมัติ', $autoLoadProducts, 'AI จะดึงข้อมูลสินค้าจากระบบ (' . number_format($productCount) . ' รายการ)');
            $prodBody .= renderField('product_load_limit', 'จำนวนสินค้าสูงสุด', 'number', $productLoadLimit, [
                'min'  => '10',
                'max'  => '200',
                'help' => 'จำนวนสินค้าที่ AI จะโหลดต่อครั้ง (10–200)',
            ]);
            $prodBody .= renderField('product_knowledge', 'ข้อมูลสินค้าเพิ่มเติม', 'textarea', $productKnowledge, [
                'rows'        => 4,
                'placeholder' => 'โปรโมชั่นพิเศษ:' . "\n" . '- สินค้า A ลด 20%' . "\n" . '- ส่งฟรีเมื่อซื้อครบ 500 บาท',
                'class'       => 'font-mono text-sm',
            ]);
            echo renderFormSection('ข้อมูลสินค้า', 'fas fa-box', number_format($productCount) . ' รายการ', $prodBody);
            ?>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-xl shadow p-6">
                <h4 class="font-semibold mb-4"><i class="fas fa-info-circle text-gray-500 mr-2"></i>สถานะ</h4>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between"><span class="text-gray-500">API Key:</span><span class="<?= $geminiApiKey ? 'text-green-600' : 'text-red-600' ?>"><?= $geminiApiKey ? '✅ ตั้งค่าแล้ว' : '❌ ยังไม่ได้ตั้งค่า' ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">สถานะ:</span><span><?= $aiEnabled ? '🟢 เปิด' : '⚪ ปิด' ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">โหมด:</span><span class="px-2 py-1 rounded text-xs <?= $aiMode === 'sales' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100' ?>"><?= $aiMode === 'sales' ? '🛒 พนักงานขาย' : ($aiMode === 'pharmacist' ? '💊 เภสัชกร' : '💬 ซัพพอร์ต') ?></span></div>
                    <div class="flex justify-between"><span class="text-gray-500">โมเดล:</span><span class="text-slate-700 text-xs font-mono">gemini-flash-latest</span></div>
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

    <?= renderStickySaveBar('aiSettingsForm', 'บันทึกการตั้งค่า') ?>
</div>

<script>
document.getElementById('aiMode').addEventListener('change', function() {
    document.getElementById('salesSection').style.display = this.value === 'sales' ? '' : 'none';
});
async function testAPI() {
    const key = document.getElementById('apiKey').value;
    const r = document.getElementById('testResult');
    if (!key) { r.innerHTML = '<div class="p-2 bg-red-50 text-red-600 rounded text-xs">กรุณากรอก API Key</div>'; return; }
    r.innerHTML = '<div class="p-2 bg-gray-50 rounded text-xs"><i class="fas fa-spinner fa-spin mr-1"></i>กำลังทดสอบ...</div>';
    try {
        const res = await fetch('https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=' + key, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contents: [{ parts: [{ text: 'ตอบสั้นๆ: สวัสดี' }] }] })
        });
        const data = await res.json();
        r.innerHTML = data.candidates ? '<div class="p-2 bg-green-50 text-green-600 rounded text-xs">✅ เชื่อมต่อสำเร็จ!</div>' : '<div class="p-2 bg-red-50 text-red-600 rounded text-xs">❌ ' + (data.error?.message || 'Error') + '</div>';
    } catch (e) { r.innerHTML = '<div class="p-2 bg-red-50 text-red-600 rounded text-xs">❌ ' + e.message + '</div>'; }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
