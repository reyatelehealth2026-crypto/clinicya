<?php
/**
 * AI Chat Settings Tab - ตั้งค่า AI ตอบแชทอัตโนมัติ
 * Version 2.0 - ใช้ ai_settings table เหมือน ai-settings.php
 * รองรับ: โหมดขาย/เภสัชกร/ซัพพอร์ต + โหลดสินค้าอัตโนมัติ
 */

defined('AI_SETTINGS_GEMINI_MODEL') || define('AI_SETTINGS_GEMINI_MODEL', 'gemini-flash-latest');

$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Ensure ai_settings columns exist
try {
    $columns = ['gemini_api_key', 'ai_mode', 'business_info', 'product_knowledge', 'sales_prompt', 'auto_load_products', 'product_load_limit', 'sender_name', 'sender_icon'];
    foreach ($columns as $col) {
        $stmt = $db->query("SHOW COLUMNS FROM ai_settings LIKE '$col'");
        if ($stmt->rowCount() == 0) {
            switch ($col) {
                case 'gemini_api_key':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN gemini_api_key VARCHAR(255)");
                    break;
                case 'ai_mode':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN ai_mode ENUM('pharmacist','sales','support') DEFAULT 'sales'");
                    break;
                case 'business_info':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN business_info TEXT");
                    break;
                case 'product_knowledge':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN product_knowledge TEXT");
                    break;
                case 'sales_prompt':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN sales_prompt TEXT");
                    break;
                case 'auto_load_products':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN auto_load_products TINYINT(1) DEFAULT 1");
                    break;
                case 'product_load_limit':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN product_load_limit INT DEFAULT 50");
                    break;
                case 'sender_name':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN sender_name VARCHAR(100)");
                    break;
                case 'sender_icon':
                    $db->exec("ALTER TABLE ai_settings ADD COLUMN sender_icon VARCHAR(500)");
                    break;
            }
        }
    }
} catch (Exception $e) {
}

// Get settings from ai_settings table
$aiSettings = [];
try {
    $stmt = $currentBotId
        ? $db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ?")
        : $db->prepare("SELECT * FROM ai_settings WHERE line_account_id IS NULL LIMIT 1");
    $currentBotId ? $stmt->execute([$currentBotId]) : $stmt->execute();
    $aiSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
}

// Get product count
$productCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM business_items WHERE is_active=1 AND (line_account_id=? OR line_account_id IS NULL)");
    $stmt->execute([$currentBotId]);
    $productCount = $stmt->fetchColumn();
} catch (Exception $e) {
}

// Handle POST
$settingsSuccess = null;
$settingsError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_action'] ?? '') === 'save_ai_settings') {
    try {
        $data = [
            'gemini_api_key' => trim($_POST['gemini_api_key'] ?? ''),
            'model' => AI_SETTINGS_GEMINI_MODEL,
            'is_enabled' => isset($_POST['ai_is_enabled']) ? 1 : 0,
            'system_prompt' => trim($_POST['ai_system_prompt'] ?? ''),
            'ai_mode' => $_POST['ai_mode'] ?? 'sales',
            'business_info' => trim($_POST['business_info'] ?? ''),
            'product_knowledge' => trim($_POST['product_knowledge'] ?? ''),
            'sales_prompt' => trim($_POST['sales_prompt'] ?? ''),
            'auto_load_products' => isset($_POST['auto_load_products']) ? 1 : 0,
            'product_load_limit' => intval($_POST['product_load_limit'] ?? 50),
            'sender_name' => trim($_POST['sender_name'] ?? ''),
            'sender_icon' => trim($_POST['sender_icon'] ?? '')
        ];

        // Check if record exists
        $stmt = $currentBotId
            ? $db->prepare("SELECT id FROM ai_settings WHERE line_account_id = ?")
            : $db->prepare("SELECT id FROM ai_settings WHERE line_account_id IS NULL");
        $currentBotId ? $stmt->execute([$currentBotId]) : $stmt->execute();
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare("UPDATE ai_settings SET 
                is_enabled=?, system_prompt=?, model=?, gemini_api_key=?, ai_mode=?, 
                business_info=?, product_knowledge=?, sales_prompt=?, 
                auto_load_products=?, product_load_limit=?, sender_name=?, sender_icon=?
                WHERE id=?");
            $stmt->execute([
                $data['is_enabled'],
                $data['system_prompt'],
                $data['model'],
                $data['gemini_api_key'],
                $data['ai_mode'],
                $data['business_info'],
                $data['product_knowledge'],
                $data['sales_prompt'],
                $data['auto_load_products'],
                $data['product_load_limit'],
                $data['sender_name'],
                $data['sender_icon'],
                $existing['id']
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO ai_settings 
                (line_account_id, is_enabled, system_prompt, model, gemini_api_key, ai_mode, business_info, product_knowledge, sales_prompt, auto_load_products, product_load_limit, sender_name, sender_icon) 
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $currentBotId,
                $data['is_enabled'],
                $data['system_prompt'],
                $data['model'],
                $data['gemini_api_key'],
                $data['ai_mode'],
                $data['business_info'],
                $data['product_knowledge'],
                $data['sales_prompt'],
                $data['auto_load_products'],
                $data['product_load_limit'],
                $data['sender_name'],
                $data['sender_icon']
            ]);
        }



        $settingsSuccess = 'บันทึกการตั้งค่าสำเร็จ';

        // Log activity
        require_once __DIR__ . '/../../classes/ActivityLogger.php';
        $activityLogger = ActivityLogger::getInstance($db);
        $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่า AI Chat', [
            'entity_type' => 'ai_settings',
            'new_value' => [
                'is_enabled' => $data['is_enabled'],
                'mode' => $data['ai_mode'],
                'model' => $data['model']
            ]
        ]);

        // Reload settings
        $stmt = $currentBotId
            ? $db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ?")
            : $db->prepare("SELECT * FROM ai_settings WHERE line_account_id IS NULL LIMIT 1");
        $currentBotId ? $stmt->execute([$currentBotId]) : $stmt->execute();
        $aiSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    } catch (Exception $e) {
        $settingsError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// Default values
$apiKey = $aiSettings['gemini_api_key'] ?? '';
$isEnabled = ($aiSettings['is_enabled'] ?? 0) == 1;
$systemPrompt = $aiSettings['system_prompt'] ?? '';
$aiMode = $aiSettings['ai_mode'] ?? 'sales';
$businessInfo = $aiSettings['business_info'] ?? '';
$productKnowledge = $aiSettings['product_knowledge'] ?? '';
$salesPrompt = $aiSettings['sales_prompt'] ?? '';
$autoLoadProducts = ($aiSettings['auto_load_products'] ?? 1) == 1;
$productLoadLimit = $aiSettings['product_load_limit'] ?? 50;
$senderName = $aiSettings['sender_name'] ?? '';
$senderIcon = $aiSettings['sender_icon'] ?? '';
?>

<style>
    .settings-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
    }

    .settings-card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
    }

    .settings-card-body {
        padding: 20px;
    }

    .settings-btn-primary {
        background: #10b981;
        color: #fff;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
    }

    .settings-btn-primary:hover {
        background: #059669;
    }

    .settings-btn-secondary {
        background: #f1f5f9;
        color: #64748b;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .settings-btn-secondary:hover {
        background: #e2e8f0;
        color: #475569;
    }

    .settings-input-field {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
        background: #fff;
    }

    .settings-input-field:focus {
        outline: none;
        border-color: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
    }

    .settings-label {
        display: block;
        font-size: 13px;
        font-weight: 500;
        color: #64748b;
        margin-bottom: 8px;
    }

    .settings-toggle {
        position: relative;
        width: 48px;
        height: 26px;
    }

    .settings-toggle input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .settings-toggle-slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #e2e8f0;
        border-radius: 26px;
        transition: 0.3s;
    }

    .settings-toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background: #fff;
        border-radius: 50%;
        transition: 0.3s;
    }

    .settings-toggle input:checked+.settings-toggle-slider {
        background: #10b981;
    }

    .settings-toggle input:checked+.settings-toggle-slider:before {
        transform: translateX(22px);
    }

    .settings-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
    }

    .settings-status-on {
        background: #dcfce7;
        color: #166534;
    }

    .settings-status-off {
        background: #f1f5f9;
        color: #64748b;
    }

    .settings-section-title {
        font-size: 15px;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .settings-section-title i {
        color: #94a3b8;
        font-size: 14px;
    }

    .settings-hint {
        font-size: 12px;
        color: #94a3b8;
        margin-top: 6px;
    }

    .settings-template-btn {
        padding: 6px 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 12px;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
    }

    .settings-template-btn:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #475569;
    }

    .mode-card {
        padding: 12px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .mode-card:hover {
        border-color: #10b981;
    }

    .mode-card.active {
        border-color: #10b981;
        background: #f0fdf4;
    }
</style>

<div class="max-w-5xl mx-auto">
    <?php if ($settingsSuccess): ?>
        <div class="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg flex items-center gap-3">
            <i class="fas fa-check-circle"></i><?= htmlspecialchars($settingsSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($settingsError): ?>
        <div class="mb-5 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg flex items-center gap-3">
            <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($settingsError) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="aiSettingsForm">
        <input type="hidden" name="settings_action" value="save_ai_settings">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-5">
                <!-- Enable Toggle + API Key -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-key text-yellow-500"></i>ตั้งค่า Gemini API
                        </h3>
                    </div>
                    <div class="settings-card-body space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <span class="font-medium">เปิดใช้งาน AI</span>
                                <p class="text-sm text-gray-500">AI จะตอบข้อความลูกค้าอัตโนมัติ</p>
                            </div>
                            <label class="settings-toggle">
                                <input type="checkbox" name="ai_is_enabled" id="aiIsEnabled" <?= $isEnabled ? 'checked' : '' ?>>
                                <span class="settings-toggle-slider"></span>
                            </label>
                        </div>

                        <div>
                            <label class="settings-label">Gemini API Key *</label>
                            <div class="relative">
                                <input type="password" name="gemini_api_key" id="settingsApiKeyInput"
                                    value="<?= htmlspecialchars($apiKey) ?>"
                                    class="settings-input-field font-mono pr-24" placeholder="AIzaSy...">
                                <div class="absolute right-2 top-1/2 -translate-y-1/2 flex gap-2">
                                    <button type="button" onclick="toggleSettingsApiKey()"
                                        class="text-slate-400 hover:text-slate-600 p-1">
                                        <i class="fas fa-eye" id="settingsEyeIcon"></i>
                                    </button>
                                    <button type="button" onclick="testSettingsApiKey()"
                                        class="settings-btn-secondary text-xs">ทดสอบ</button>
                                </div>
                            </div>
                            <div id="settingsApiTestResult" class="mt-2 text-sm hidden"></div>
                            <p class="settings-hint"><a href="https://aistudio.google.com/app/apikey" target="_blank"
                                    class="text-blue-500 hover:underline"><i
                                        class="fas fa-external-link-alt mr-1"></i>รับ API Key ฟรี</a></p>
                        </div>

                        <div class="p-4 bg-slate-50 rounded-lg border border-slate-100 mb-4">
                            <p class="text-sm font-medium text-slate-800 mb-1">โมเดล</p>
                            <p class="text-sm text-slate-600">ใช้ <code class="text-xs bg-white px-1 py-0.5 rounded border font-mono"><?= htmlspecialchars(AI_SETTINGS_GEMINI_MODEL) ?></code> เท่านั้น (Gemini Flash รุ่นล่าสุด — ไม่ต้องเลือกโมเดล)</p>
                        </div>
                        <div>
                            <label class="settings-label">โหมด AI</label>
                            <select name="ai_mode" id="aiModeSelect" class="settings-input-field"
                                onchange="toggleSalesSection()">
                                <option value="sales" <?= $aiMode === 'sales' ? 'selected' : '' ?>>🛒 พนักงานขาย
                                </option>
                                <option value="support" <?= $aiMode === 'support' ? 'selected' : '' ?>>💬 ซัพพอร์ต
                                </option>
                                <option value="pharmacist" <?= $aiMode === 'pharmacist' ? 'selected' : '' ?>>💊 เภสัชกร
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Business Info -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-store text-blue-500"></i>ข้อมูลธุรกิจ</h3>
                    </div>
                    <div class="settings-card-body space-y-4">
                        <div>
                            <label class="settings-label">ข้อมูลร้าน/ธุรกิจ</label>
                            <textarea name="business_info" rows="3" class="settings-input-field"
                                placeholder="ชื่อร้าน: ...&#10;ที่อยู่: ...&#10;เวลาทำการ: ..."><?= htmlspecialchars($businessInfo) ?></textarea>
                        </div>
                        <div>
                            <label class="settings-label">System Prompt (บทบาทหลัก)</label>
                            <textarea name="ai_system_prompt" rows="3" class="settings-input-field"
                                placeholder="คุณคือพนักงานขายของร้าน..."><?= htmlspecialchars($systemPrompt) ?></textarea>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" onclick="setPromptTemplate('shop')"
                                    class="settings-template-btn">🛒 ร้านค้า</button>
                                <button type="button" onclick="setPromptTemplate('pharmacy')"
                                    class="settings-template-btn">💊 ร้านยา</button>
                                <button type="button" onclick="setPromptTemplate('restaurant')"
                                    class="settings-template-btn">🍜 ร้านอาหาร</button>
                                <button type="button" onclick="setPromptTemplate('support')"
                                    class="settings-template-btn">📞 Support</button>
                            </div>
                        </div>
                        <div id="salesSection" class="<?= $aiMode !== 'sales' ? 'hidden' : '' ?>">
                            <label class="settings-label">คำแนะนำสำหรับการขาย</label>
                            <textarea name="sales_prompt" rows="2" class="settings-input-field"
                                placeholder="เน้นแนะนำโปรโมชั่น..."><?= htmlspecialchars($salesPrompt) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Product Knowledge -->
                <div class="settings-card">
                    <div class="settings-card-header flex justify-between items-center">
                        <h3 class="settings-section-title"><i class="fas fa-box text-emerald-500"></i>ข้อมูลสินค้า</h3>
                        <span class="text-sm text-gray-500"><?= number_format($productCount) ?> รายการ</span>
                    </div>
                    <div class="settings-card-body space-y-4">
                        <label class="flex items-center justify-between p-4 bg-emerald-50 rounded-lg cursor-pointer">
                            <div class="flex items-center">
                                <input type="checkbox" name="auto_load_products" value="1" <?= $autoLoadProducts ? 'checked' : '' ?> class="w-5 h-5 text-emerald-500 rounded mr-3">
                                <div>
                                    <span class="font-medium text-emerald-800">โหลดสินค้าอัตโนมัติ</span>
                                    <p class="text-sm text-emerald-600">AI จะดึงข้อมูลสินค้าจากระบบ</p>
                                </div>
                            </div>
                            <input type="number" name="product_load_limit" value="<?= $productLoadLimit ?>" min="10"
                                max="200" class="w-20 px-3 py-2 border rounded-lg text-center">
                        </label>
                        <div>
                            <label class="settings-label">ข้อมูลสินค้า/โปรโมชั่นเพิ่มเติม</label>
                            <textarea name="product_knowledge" rows="3" class="settings-input-field font-mono text-sm"
                                placeholder="โปรโมชั่นพิเศษ:&#10;- สินค้า A ลด 20%&#10;- ส่งฟรีเมื่อซื้อครบ 500 บาท"><?= htmlspecialchars($productKnowledge) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Sender Settings -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i
                                class="fas fa-user-circle text-purple-500"></i>ตั้งค่าผู้ส่ง (Sender)</h3>
                    </div>
                    <div class="settings-card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="settings-label">ชื่อผู้ส่ง</label>
                                <input type="text" name="sender_name" value="<?= htmlspecialchars($senderName) ?>"
                                    class="settings-input-field" placeholder="เช่น: AI Assistant">
                            </div>
                            <div>
                                <label class="settings-label">Icon URL</label>
                                <input type="url" name="sender_icon" value="<?= htmlspecialchars($senderIcon) ?>"
                                    class="settings-input-field" placeholder="https://...">
                            </div>
                        </div>
                        <p class="settings-hint mt-2">ชื่อและรูปที่จะแสดงเป็นผู้ส่งข้อความ AI</p>
                    </div>
                </div>

                <button type="submit" class="settings-btn-primary w-full text-lg">
                    <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
                </button>
            </div>

            <!-- Sidebar -->
            <div class="space-y-5">
                <!-- Status -->
                <div class="settings-card">
                    <div class="settings-card-body">
                        <h4 class="font-semibold mb-4"><i class="fas fa-info-circle text-gray-500 mr-2"></i>สถานะ</h4>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between"><span class="text-gray-500">API Key:</span><span
                                    class="<?= $apiKey ? 'text-green-600' : 'text-red-600' ?>"><?= $apiKey ? '✅ ตั้งค่าแล้ว' : '❌ ยังไม่ได้ตั้งค่า' ?></span>
                            </div>
                            <div class="flex justify-between"><span
                                    class="text-gray-500">สถานะ:</span><span><?= $isEnabled ? '🟢 เปิด' : '⚪ ปิด' ?></span>
                            </div>
                            <div class="flex justify-between"><span class="text-gray-500">โหมด:</span><span
                                    class="px-2 py-1 rounded text-xs <?= $aiMode === 'sales' ? 'bg-emerald-100 text-emerald-700' : ($aiMode === 'pharmacist' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100') ?>"><?= $aiMode === 'sales' ? '🛒 พนักงานขาย' : ($aiMode === 'pharmacist' ? '💊 เภสัชกร' : '💬 ซัพพอร์ต') ?></span>
                            </div>
                            <div class="flex justify-between"><span class="text-gray-500">โมเดล:</span><span class="text-slate-700 text-xs font-mono"><?= htmlspecialchars(AI_SETTINGS_GEMINI_MODEL) ?></span></div>
                            <div class="flex justify-between"><span class="text-gray-500">สินค้า:</span><span
                                    class="text-blue-600"><?= number_format($productCount) ?> รายการ</span></div>
                        </div>
                    </div>
                </div>

                <!-- AI Modes Info -->
                <div class="settings-card">
                    <div class="settings-card-body">
                        <h4 class="font-semibold mb-4"><i class="fas fa-robot text-purple-500 mr-2"></i>โหมด AI</h4>
                        <div class="space-y-3 text-sm">
                            <div class="p-3 bg-emerald-50 rounded-lg">
                                <div class="font-medium text-emerald-700">🛒 พนักงานขาย</div>
                                <p class="text-emerald-600 text-xs">แนะนำสินค้า บอกราคา ชวนสั่งซื้อ</p>
                            </div>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <div class="font-medium text-gray-700">💬 ซัพพอร์ต</div>
                                <p class="text-gray-600 text-xs">ตอบคำถาม แก้ปัญหา</p>
                            </div>
                            <div class="p-3 bg-blue-50 rounded-lg">
                                <div class="font-medium text-blue-700">💊 เภสัชกร</div>
                                <p class="text-blue-600 text-xs">ซักประวัติ แนะนำยา</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Chat -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-comments text-indigo-500"></i>ทดสอบแชท</h3>
                    </div>
                    <div id="settingsChatMessages"
                        class="h-48 overflow-y-auto p-4 space-y-3 bg-slate-50 border-b border-slate-100">
                        <div class="text-center text-slate-400 text-sm">พิมพ์ข้อความเพื่อทดสอบ AI</div>
                    </div>
                    <div class="p-3 flex gap-2">
                        <input type="text" id="settingsTestMessage" placeholder="พิมพ์ทดสอบ..."
                            class="settings-input-field flex-1"
                            onkeypress="if(event.key==='Enter'){event.preventDefault();sendTestMessage();}">
                        <button type="button" onclick="sendTestMessage()" class="settings-btn-primary px-4"><i
                                class="fas fa-paper-plane"></i></button>
                    </div>
                </div>

                <!-- Tips -->
                <div class="settings-card">
                    <div class="settings-card-body">
                        <h4 class="font-semibold mb-3"><i class="fas fa-lightbulb text-yellow-500 mr-2"></i>เคล็ดลับ
                        </h4>
                        <ul class="text-sm text-slate-600 space-y-2">
                            <li class="flex items-start gap-2"><i
                                    class="fas fa-check text-emerald-500 mt-0.5 text-xs"></i><span>ใส่ข้อมูลธุรกิจให้ครบ
                                    AI จะตอบได้แม่นยำขึ้น</span></li>
                            <li class="flex items-start gap-2"><i
                                    class="fas fa-check text-emerald-500 mt-0.5 text-xs"></i><span>เปิดโหลดสินค้าอัตโนมัติเพื่อให้
                                    AI รู้จักสินค้า</span></li>
                            <li class="flex items-start gap-2"><i
                                    class="fas fa-check text-emerald-500 mt-0.5 text-xs"></i><span>ทดสอบแชทก่อนเปิดใช้งานจริง</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    const promptTemplates = {
        shop: 'คุณเป็นผู้ช่วยขายของที่กระฉับกระเฉงและสุภาพ แนะนำสินค้าตามความต้องการลูกค้า ตอบเรื่องราคาและโปรโมชั่น ช่วยปิดการขายอย่างเป็นธรรมชาติ',
        pharmacy: 'คุณคือ "เภสัชกรวิชาชีพ" ผู้มีความเชี่ยวชาญและเห็นอกเห็นใจคนไข้ วิเคราะห์อาการเบื้องต้นผ่านการสนทนาที่ลื่นไหล ถามข้อมูลสำคัญ: อาการ, ระยะเวลา, อาการร่วม, แพ้ยา, โรคประจำตัว สรุปและแนะนำยาสามัญ (OTC) ที่ปลอดภัย',
        restaurant: 'คุณเป็นพนักงานร้านอาหารที่เป็นมิตร ช่วยแนะนำเมนู รับออเดอร์ และตอบคำถามเกี่ยวกับส่วนผสม ราคา และโปรโมชั่น',
        support: 'คุณเป็นเจ้าหน้าที่ฝ่ายบริการลูกค้าที่เป็นมิตรและมืออาชีพ ช่วยแก้ปัญหาและตอบคำถามอย่างรวดเร็ว'
    };

    function setPromptTemplate(type) {
        document.querySelector('textarea[name="ai_system_prompt"]').value = promptTemplates[type] || '';
    }

    function toggleSalesSection() {
        const mode = document.getElementById('aiModeSelect').value;
        document.getElementById('salesSection').classList.toggle('hidden', mode !== 'sales');
    }

    function toggleSettingsApiKey() {
        const input = document.getElementById('settingsApiKeyInput');
        const icon = document.getElementById('settingsEyeIcon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    async function testSettingsApiKey() {
        const apiKey = document.getElementById('settingsApiKeyInput').value.trim();
        const resultDiv = document.getElementById('settingsApiTestResult');

        if (!apiKey) {
            resultDiv.innerHTML = '<span class="text-red-600">กรุณากรอก API Key</span>';
            resultDiv.classList.remove('hidden');
            return;
        }

        resultDiv.innerHTML = '<span class="text-indigo-600"><i class="fas fa-spinner fa-spin mr-1"></i>กำลังทดสอบ...</span>';
        resultDiv.classList.remove('hidden');

        try {
            const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=${apiKey}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ contents: [{ parts: [{ text: 'ตอบว่า OK' }] }] })
            });

            if (response.ok) {
                resultDiv.innerHTML = '<span class="text-emerald-600">✓ API Key ใช้งานได้</span>';
            } else {
                const error = await response.json();
                resultDiv.innerHTML = `<span class="text-red-600">✗ ${error.error?.message || 'API Key ไม่ถูกต้อง'}</span>`;
            }
        } catch (e) {
            resultDiv.innerHTML = `<span class="text-red-600">✗ ${e.message}</span>`;
        }
    }

    async function sendTestMessage() {
        const input = document.getElementById('settingsTestMessage');
        const message = input.value.trim();
        if (!message) return;

        const apiKey = document.getElementById('settingsApiKeyInput').value.trim();
        if (!apiKey) { alert('กรุณากรอก API Key ก่อน'); return; }

        const chatDiv = document.getElementById('settingsChatMessages');
        chatDiv.innerHTML += `<div class="flex justify-end"><div class="bg-emerald-500 text-white px-4 py-2 rounded-2xl rounded-br-sm max-w-[80%] text-sm">${escapeHtml(message)}</div></div>`;
        input.value = '';
        chatDiv.scrollTop = chatDiv.scrollHeight;

        chatDiv.innerHTML += `<div id="typing" class="flex"><div class="bg-slate-200 px-4 py-2 rounded-2xl rounded-bl-sm text-slate-500 text-sm"><i class="fas fa-ellipsis-h animate-pulse"></i></div></div>`;
        chatDiv.scrollTop = chatDiv.scrollHeight;

        try {
            const systemPrompt = document.querySelector('textarea[name="ai_system_prompt"]').value;
            const businessInfo = document.querySelector('textarea[name="business_info"]').value;
            const productKnowledge = document.querySelector('textarea[name="product_knowledge"]').value;

            let fullPrompt = '';
            if (systemPrompt) fullPrompt += `System: ${systemPrompt}\n\n`;
            if (businessInfo) fullPrompt += `ข้อมูลธุรกิจ: ${businessInfo}\n\n`;
            if (productKnowledge) fullPrompt += `ข้อมูลสินค้า: ${productKnowledge}\n\n`;
            fullPrompt += `ลูกค้าถาม: ${message}\n\nตอบสั้นๆ:`;

            const model = 'gemini-flash-latest';

            const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${apiKey}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ contents: [{ parts: [{ text: fullPrompt }] }], generationConfig: { temperature: 0.7 } })
            });

            document.getElementById('typing')?.remove();

            if (response.ok) {
                const data = await response.json();
                const aiResponse = data.candidates?.[0]?.content?.parts?.[0]?.text || 'ไม่สามารถตอบได้';
                chatDiv.innerHTML += `<div class="flex"><div class="bg-slate-200 px-4 py-2 rounded-2xl rounded-bl-sm max-w-[80%] text-sm text-slate-700">${escapeHtml(aiResponse)}</div></div>`;
            } else {
                const error = await response.json();
                chatDiv.innerHTML += `<div class="flex"><div class="bg-red-100 text-red-600 px-4 py-2 rounded-2xl rounded-bl-sm text-sm">${error.error?.message || 'เกิดข้อผิดพลาด'}</div></div>`;
            }
        } catch (e) {
            document.getElementById('typing')?.remove();
            chatDiv.innerHTML += `<div class="flex"><div class="bg-red-100 text-red-600 px-4 py-2 rounded-2xl rounded-bl-sm text-sm">${e.message}</div></div>`;
        }

        chatDiv.scrollTop = chatDiv.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>