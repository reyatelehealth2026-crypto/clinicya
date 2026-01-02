<?php
/**
 * AI Chat Settings Tab - ตั้งค่า AI ตอบแชทอัตโนมัติ
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Ensure tables exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT DEFAULT NULL,
        is_enabled TINYINT(1) DEFAULT 0,
        gemini_api_key VARCHAR(255) DEFAULT NULL,
        model VARCHAR(50) DEFAULT 'gemini-2.0-flash',
        system_prompt TEXT,
        temperature DECIMAL(2,1) DEFAULT 0.7,
        max_tokens INT DEFAULT 500,
        response_style VARCHAR(50) DEFAULT 'friendly',
        language VARCHAR(10) DEFAULT 'th',
        fallback_message TEXT,
        business_info TEXT,
        product_knowledge TEXT,
        sender_name VARCHAR(100) DEFAULT NULL,
        sender_icon VARCHAR(500) DEFAULT NULL,
        quick_reply_buttons TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_account (line_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    try { $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN sender_name VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN sender_icon VARCHAR(500) DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN quick_reply_buttons TEXT"); } catch (Exception $e) {}
} catch (Exception $e) {}

// Get current settings
$aiSettings = [];
try {
    if ($currentBotId) {
        $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE line_account_id = ?");
        $stmt->execute([$currentBotId]);
        $aiSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {}

// Get API key from multiple sources
$apiKey = $aiSettings['gemini_api_key'] ?? '';
if (empty($apiKey)) {
    try {
        $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$currentBotId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $apiKey = $result['gemini_api_key'] ?? '';
    } catch (Exception $e) {}
}


// Handle POST
$settingsSuccess = null;
$settingsError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_action'] ?? '') === 'save_ai_settings') {
    try {
        $data = [
            'line_account_id' => $currentBotId,
            'is_enabled' => isset($_POST['ai_is_enabled']) ? 1 : 0,
            'gemini_api_key' => trim($_POST['gemini_api_key'] ?? ''),
            'model' => $_POST['ai_model'] ?? 'gemini-2.0-flash',
            'system_prompt' => trim($_POST['ai_system_prompt'] ?? ''),
            'temperature' => floatval($_POST['ai_temperature'] ?? 0.7),
            'max_tokens' => intval($_POST['ai_max_tokens'] ?? 500),
            'response_style' => $_POST['response_style'] ?? 'friendly',
            'language' => $_POST['language'] ?? 'th',
            'fallback_message' => trim($_POST['fallback_message'] ?? ''),
            'business_info' => trim($_POST['business_info'] ?? ''),
            'product_knowledge' => trim($_POST['product_knowledge'] ?? ''),
            'sender_name' => trim($_POST['sender_name'] ?? ''),
            'sender_icon' => trim($_POST['sender_icon'] ?? ''),
            'quick_reply_buttons' => trim($_POST['quick_reply_buttons'] ?? '')
        ];
        
        $stmt = $db->prepare("INSERT INTO ai_chat_settings 
            (line_account_id, is_enabled, gemini_api_key, model, system_prompt, temperature, max_tokens, response_style, language, fallback_message, business_info, product_knowledge, sender_name, sender_icon, quick_reply_buttons)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            is_enabled = VALUES(is_enabled), gemini_api_key = VALUES(gemini_api_key), model = VALUES(model),
            system_prompt = VALUES(system_prompt), temperature = VALUES(temperature), max_tokens = VALUES(max_tokens),
            response_style = VALUES(response_style), language = VALUES(language), fallback_message = VALUES(fallback_message),
            business_info = VALUES(business_info), product_knowledge = VALUES(product_knowledge),
            sender_name = VALUES(sender_name), sender_icon = VALUES(sender_icon), quick_reply_buttons = VALUES(quick_reply_buttons)");
        
        $stmt->execute(array_values($data));
        
        // Also update ai_settings table
        $stmt = $db->prepare("INSERT INTO ai_settings (line_account_id, gemini_api_key, is_enabled, system_prompt, model) 
            VALUES (?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE gemini_api_key = VALUES(gemini_api_key), is_enabled = VALUES(is_enabled), system_prompt = VALUES(system_prompt), model = VALUES(model)");
        $stmt->execute([$currentBotId, $data['gemini_api_key'], $data['is_enabled'], $data['system_prompt'], $data['model']]);
        
        $settingsSuccess = 'บันทึกการตั้งค่าสำเร็จ';
        
        $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE line_account_id = ?");
        $stmt->execute([$currentBotId]);
        $aiSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $apiKey = $aiSettings['gemini_api_key'] ?? '';
        
    } catch (Exception $e) {
        $settingsError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

// Default values
$isEnabled = $aiSettings['is_enabled'] ?? 0;
$model = $aiSettings['model'] ?? 'gemini-2.0-flash';
$systemPrompt = $aiSettings['system_prompt'] ?? '';
$temperature = $aiSettings['temperature'] ?? 0.7;
$maxTokens = $aiSettings['max_tokens'] ?? 500;
$responseStyle = $aiSettings['response_style'] ?? 'friendly';
$fallbackMessage = $aiSettings['fallback_message'] ?? 'ขออภัยค่ะ ไม่เข้าใจคำถาม กรุณาติดต่อเจ้าหน้าที่';
$businessInfo = $aiSettings['business_info'] ?? '';
$productKnowledge = $aiSettings['product_knowledge'] ?? '';
$senderName = $aiSettings['sender_name'] ?? '';
$senderIcon = $aiSettings['sender_icon'] ?? '';
$quickReplyButtons = $aiSettings['quick_reply_buttons'] ?? '';
?>

<style>
.settings-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
.settings-card-header { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; }
.settings-card-body { padding: 20px; }
.settings-btn-primary { background: #6366f1; color: #fff; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
.settings-btn-primary:hover { background: #4f46e5; }
.settings-btn-secondary { background: #f1f5f9; color: #64748b; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; transition: all 0.2s; }
.settings-btn-secondary:hover { background: #e2e8f0; color: #475569; }
.settings-input-field { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; transition: all 0.2s; background: #fff; }
.settings-input-field:focus { outline: none; border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.2); }
.settings-label { display: block; font-size: 13px; font-weight: 500; color: #64748b; margin-bottom: 8px; }
.settings-toggle { position: relative; width: 48px; height: 26px; }
.settings-toggle input { opacity: 0; width: 0; height: 0; }
.settings-toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #e2e8f0; border-radius: 26px; transition: 0.3s; }
.settings-toggle-slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; }
.settings-toggle input:checked + .settings-toggle-slider { background: #6366f1; }
.settings-toggle input:checked + .settings-toggle-slider:before { transform: translateX(22px); }
.settings-status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: 500; }
.settings-status-on { background: #dcfce7; color: #166534; }
.settings-status-off { background: #f1f5f9; color: #64748b; }
.settings-section-title { font-size: 15px; font-weight: 600; color: #1e293b; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.settings-section-title i { color: #94a3b8; font-size: 14px; }
.settings-hint { font-size: 12px; color: #94a3b8; margin-top: 6px; }
.settings-template-btn { padding: 6px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.2s; }
.settings-template-btn:hover { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
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

    <!-- Page Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">AI ตอบแชทอัตโนมัติ</h1>
                <p class="text-slate-500 text-sm mt-1">ใช้ Gemini AI ตอบข้อความลูกค้าอัตโนมัติ</p>
            </div>
            <div class="settings-status-badge <?= $isEnabled ? 'settings-status-on' : 'settings-status-off' ?>" id="settingsStatusBadge">
                <span class="w-2 h-2 rounded-full <?= $isEnabled ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                <?= $isEnabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?>
            </div>
        </div>
    </div>

    <form method="POST" id="aiSettingsForm">
        <input type="hidden" name="settings_action" value="save_ai_settings">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Settings -->
            <div class="lg:col-span-2 space-y-5">

                <!-- Enable Toggle -->
                <div class="settings-card">
                    <div class="settings-card-body">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-medium text-slate-800">เปิดใช้งาน AI</h3>
                                <p class="text-sm text-slate-500 mt-1">AI จะตอบข้อความที่ไม่มี Auto-Reply ตรงกัน</p>
                            </div>
                            <label class="settings-toggle">
                                <input type="checkbox" name="ai_is_enabled" id="aiIsEnabled" <?= $isEnabled ? 'checked' : '' ?>>
                                <span class="settings-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- API Key -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-key"></i>Gemini API Key</h3>
                    </div>
                    <div class="settings-card-body">
                        <div class="relative">
                            <input type="password" name="gemini_api_key" id="settingsApiKeyInput" value="<?= htmlspecialchars($apiKey) ?>" 
                                   class="settings-input-field font-mono pr-24" placeholder="AIzaSy...">
                            <div class="absolute right-2 top-1/2 -translate-y-1/2 flex gap-2">
                                <button type="button" onclick="toggleSettingsApiKey()" class="text-slate-400 hover:text-slate-600 p-1">
                                    <i class="fas fa-eye" id="settingsEyeIcon"></i>
                                </button>
                                <button type="button" onclick="testSettingsApiKey()" class="settings-btn-secondary text-xs">ทดสอบ</button>
                            </div>
                        </div>
                        <div id="settingsApiTestResult" class="mt-2 text-sm hidden"></div>
                        <p class="settings-hint">
                            <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-indigo-500 hover:underline">
                                รับ API Key ฟรีที่ Google AI Studio →
                            </a>
                        </p>
                    </div>
                </div>

                <!-- Model & Style -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-sliders-h"></i>การตั้งค่า AI</h3>
                    </div>
                    <div class="settings-card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="settings-label">Model</label>
                                <select name="ai_model" class="settings-input-field">
                                    <option value="gemini-2.0-flash" <?= $model === 'gemini-2.0-flash' ? 'selected' : '' ?>>Gemini 2.0 Flash (แนะนำ)</option>
                                    <option value="gemini-1.5-flash" <?= $model === 'gemini-1.5-flash' ? 'selected' : '' ?>>Gemini 1.5 Flash</option>
                                    <option value="gemini-1.5-pro" <?= $model === 'gemini-1.5-pro' ? 'selected' : '' ?>>Gemini 1.5 Pro</option>
                                </select>
                            </div>
                            <div>
                                <label class="settings-label">สไตล์การตอบ</label>
                                <select name="response_style" class="settings-input-field">
                                    <option value="friendly" <?= $responseStyle === 'friendly' ? 'selected' : '' ?>>เป็นมิตร</option>
                                    <option value="professional" <?= $responseStyle === 'professional' ? 'selected' : '' ?>>มืออาชีพ</option>
                                    <option value="casual" <?= $responseStyle === 'casual' ? 'selected' : '' ?>>สบายๆ</option>
                                    <option value="pharmacy_assistant" <?= $responseStyle === 'pharmacy_assistant' ? 'selected' : '' ?>>ผู้ช่วยเภสัชกร</option>
                                </select>
                            </div>
                            <div>
                                <label class="settings-label">Temperature: <span id="settingsTempValue"><?= $temperature ?></span></label>
                                <input type="range" name="ai_temperature" min="0" max="1" step="0.1" value="<?= $temperature ?>" 
                                       class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-indigo-500"
                                       oninput="document.getElementById('settingsTempValue').textContent = this.value">
                                <div class="flex justify-between text-xs text-slate-400 mt-1">
                                    <span>แม่นยำ</span>
                                    <span>สร้างสรรค์</span>
                                </div>
                            </div>
                            <div>
                                <label class="settings-label">ความยาวสูงสุด (tokens)</label>
                                <input type="number" name="ai_max_tokens" value="<?= $maxTokens ?>" min="100" max="2000" class="settings-input-field">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Prompt -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-user-cog"></i>บุคลิกของ AI</h3>
                    </div>
                    <div class="settings-card-body">
                        <textarea name="ai_system_prompt" rows="4" class="settings-input-field resize-none"
                                  placeholder="เช่น: คุณเป็นผู้ช่วยขายของร้าน ABC ตอบคำถามลูกค้าอย่างเป็นมิตร..."><?= htmlspecialchars($systemPrompt) ?></textarea>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" onclick="setSettingsPromptTemplate('shop')" class="settings-template-btn">🛒 ร้านค้า</button>
                            <button type="button" onclick="setSettingsPromptTemplate('pharmacy')" class="settings-template-btn">💊 ร้านยา</button>
                            <button type="button" onclick="setSettingsPromptTemplate('restaurant')" class="settings-template-btn">🍜 ร้านอาหาร</button>
                            <button type="button" onclick="setSettingsPromptTemplate('service')" class="settings-template-btn">💆 บริการ</button>
                            <button type="button" onclick="setSettingsPromptTemplate('support')" class="settings-template-btn">📞 Support</button>
                        </div>
                    </div>
                </div>

                <!-- Business Info -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-store"></i>ข้อมูลธุรกิจ</h3>
                    </div>
                    <div class="settings-card-body">
                        <textarea name="business_info" rows="3" class="settings-input-field resize-none"
                                  placeholder="เช่น: ร้าน ABC เปิด 9:00-21:00 ทุกวัน, ที่อยู่: 123 ถ.สุขุมวิท..."><?= htmlspecialchars($businessInfo) ?></textarea>
                        <p class="settings-hint">ข้อมูลที่ AI จะใช้ในการตอบคำถามเกี่ยวกับร้าน</p>
                    </div>
                </div>

                <!-- Product Knowledge -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-box"></i>ความรู้เกี่ยวกับสินค้า</h3>
                    </div>
                    <div class="settings-card-body">
                        <textarea name="product_knowledge" rows="3" class="settings-input-field resize-none"
                                  placeholder="เช่น: สินค้าขายดี: ขมิ้นชัน 250 บาท, พาราเซตามอล 35 บาท..."><?= htmlspecialchars($productKnowledge) ?></textarea>
                        <p class="settings-hint">ข้อมูลสินค้าที่ AI จะใช้แนะนำลูกค้า</p>
                    </div>
                </div>

                <!-- Fallback Message -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-comment-slash"></i>ข้อความเมื่อ AI ตอบไม่ได้</h3>
                    </div>
                    <div class="settings-card-body">
                        <textarea name="fallback_message" rows="2" class="settings-input-field resize-none"
                                  placeholder="ข้อความที่จะส่งเมื่อ AI ไม่สามารถตอบได้"><?= htmlspecialchars($fallbackMessage) ?></textarea>
                    </div>
                </div>

                <!-- Sender Settings -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-user-circle"></i>ตั้งค่าผู้ส่ง (Sender)</h3>
                    </div>
                    <div class="settings-card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="settings-label">ชื่อผู้ส่ง</label>
                                <input type="text" name="sender_name" value="<?= htmlspecialchars($senderName) ?>" 
                                       class="settings-input-field" placeholder="เช่น: ผู้ช่วยเภสัชกร, AI Assistant">
                            </div>
                            <div>
                                <label class="settings-label">Icon URL</label>
                                <input type="url" name="sender_icon" value="<?= htmlspecialchars($senderIcon) ?>" 
                                       class="settings-input-field" placeholder="https://example.com/icon.png">
                            </div>
                        </div>
                        <p class="settings-hint mt-3">ชื่อและรูปที่จะแสดงเป็นผู้ส่งข้อความ AI (ต้องเป็น HTTPS, ขนาดไม่เกิน 1MB)</p>
                    </div>
                </div>

                <!-- Quick Reply Buttons -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-reply-all"></i>Quick Reply Buttons</h3>
                    </div>
                    <div class="settings-card-body">
                        <div id="settingsQuickReplyContainer">
                            <!-- Quick reply buttons will be added here -->
                        </div>
                        <button type="button" onclick="addSettingsQuickReply()" class="settings-btn-secondary mt-3">
                            <i class="fas fa-plus mr-1"></i>เพิ่มปุ่ม Quick Reply
                        </button>
                        <input type="hidden" name="quick_reply_buttons" id="settingsQuickReplyInput" value="<?= htmlspecialchars($quickReplyButtons) ?>">
                        <p class="settings-hint mt-2">ปุ่มที่จะแสดงให้ลูกค้ากดตอบกลับ (สูงสุด 13 ปุ่ม)</p>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-5">
                <!-- Save Button -->
                <div class="settings-card">
                    <div class="settings-card-body">
                        <button type="submit" class="settings-btn-primary w-full">
                            <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
                        </button>
                    </div>
                </div>

                <!-- Test Chat -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-comments"></i>ทดสอบแชท</h3>
                    </div>
                    <div id="settingsChatMessages" class="h-56 overflow-y-auto p-4 space-y-3 bg-slate-50 border-b border-slate-100">
                        <div class="text-center text-slate-400 text-sm">พิมพ์ข้อความเพื่อทดสอบ AI</div>
                    </div>
                    <div class="p-3 flex gap-2">
                        <input type="text" id="settingsTestMessage" placeholder="พิมพ์ข้อความทดสอบ..." 
                               class="settings-input-field flex-1" onkeypress="if(event.key==='Enter'){event.preventDefault();sendSettingsTestMessage();}">
                        <button type="button" onclick="sendSettingsTestMessage()" class="settings-btn-primary px-4">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>

                <!-- Tips -->
                <div class="settings-card">
                    <div class="settings-card-header">
                        <h3 class="settings-section-title"><i class="fas fa-lightbulb"></i>เคล็ดลับ</h3>
                    </div>
                    <div class="settings-card-body">
                        <ul class="text-sm text-slate-600 space-y-3">
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check text-emerald-500 mt-0.5 text-xs"></i>
                                <span>ใส่ข้อมูลธุรกิจให้ครบ AI จะตอบได้แม่นยำขึ้น</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check text-emerald-500 mt-0.5 text-xs"></i>
                                <span>ใช้ Temperature ต่ำ (0.3-0.5) สำหรับคำตอบที่แม่นยำ</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check text-emerald-500 mt-0.5 text-xs"></i>
                                <span>ทดสอบแชทก่อนเปิดใช้งานจริง</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fas fa-check text-emerald-500 mt-0.5 text-xs"></i>
                                <span>Gemini API ฟรี 60 requests/นาที</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const settingsPromptTemplates = {
    shop: 'คุณเป็นผู้ช่วยขายของที่กระฉับกระเฉงและสุภาพ แนะนำสินค้าตามความต้องการลูกค้า ตอบเรื่องราคาและโปรโมชั่น ช่วยปิดการขายอย่างเป็นธรรมชาติ',
    pharmacy: 'คุณคือ "เภสัชกรวิชาชีพ" ผู้มีความเชี่ยวชาญและเห็นอกเห็นใจคนไข้ วิเคราะห์อาการเบื้องต้นผ่านการสนทนาที่ลื่นไหล ถามข้อมูลสำคัญ: อาการ, ระยะเวลา, อาการร่วม, แพ้ยา, โรคประจำตัว สรุปและแนะนำยาสามัญ (OTC) ที่ปลอดภัย กำชับให้รอการยืนยันจากเภสัชกรก่อนใช้ยาจริงเสมอ',
    restaurant: 'คุณเป็นพนักงานร้านอาหารที่เป็นมิตร ช่วยแนะนำเมนู รับออเดอร์ และตอบคำถามเกี่ยวกับส่วนผสม ราคา และโปรโมชั่น',
    service: 'คุณเป็นผู้ช่วยจองบริการที่เป็นมิตร ช่วยลูกค้าจองคิว ตรวจสอบเวลาว่าง และตอบคำถามเกี่ยวกับบริการและราคา',
    support: 'คุณเป็นเจ้าหน้าที่ฝ่ายบริการลูกค้าที่เป็นมิตรและมืออาชีพ ช่วยแก้ปัญหาและตอบคำถามอย่างรวดเร็ว'
};

function setSettingsPromptTemplate(type) {
    document.querySelector('textarea[name="ai_system_prompt"]').value = settingsPromptTemplates[type] || '';
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
        const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${apiKey}`, {
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

async function sendSettingsTestMessage() {
    const input = document.getElementById('settingsTestMessage');
    const message = input.value.trim();
    if (!message) return;
    
    const apiKey = document.getElementById('settingsApiKeyInput').value.trim();
    if (!apiKey) { alert('กรุณากรอก API Key ก่อน'); return; }
    
    const chatDiv = document.getElementById('settingsChatMessages');
    
    chatDiv.innerHTML += `<div class="flex justify-end"><div class="bg-indigo-500 text-white px-4 py-2 rounded-2xl rounded-br-sm max-w-[80%] text-sm">${escapeSettingsHtml(message)}</div></div>`;
    input.value = '';
    chatDiv.scrollTop = chatDiv.scrollHeight;
    
    chatDiv.innerHTML += `<div id="settingsTyping" class="flex"><div class="bg-slate-200 px-4 py-2 rounded-2xl rounded-bl-sm text-slate-500 text-sm"><i class="fas fa-ellipsis-h animate-pulse"></i></div></div>`;
    chatDiv.scrollTop = chatDiv.scrollHeight;
    
    try {
        const systemPrompt = document.querySelector('textarea[name="ai_system_prompt"]').value;
        const businessInfo = document.querySelector('textarea[name="business_info"]').value;
        const productKnowledge = document.querySelector('textarea[name="product_knowledge"]').value;
        
        let fullPrompt = '';
        if (systemPrompt) fullPrompt += `System: ${systemPrompt}\n\n`;
        if (businessInfo) fullPrompt += `ข้อมูลธุรกิจ: ${businessInfo}\n\n`;
        if (productKnowledge) fullPrompt += `ข้อมูลสินค้า: ${productKnowledge}\n\n`;
        fullPrompt += `ลูกค้าถาม: ${message}\n\nตอบ:`;
        
        const model = document.querySelector('select[name="ai_model"]').value;
        const temperature = parseFloat(document.querySelector('input[name="ai_temperature"]').value);
        
        const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${apiKey}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contents: [{ parts: [{ text: fullPrompt }] }], generationConfig: { temperature } })
        });
        
        document.getElementById('settingsTyping')?.remove();
        
        if (response.ok) {
            const data = await response.json();
            const aiResponse = data.candidates?.[0]?.content?.parts?.[0]?.text || 'ไม่สามารถตอบได้';
            chatDiv.innerHTML += `<div class="flex"><div class="bg-slate-200 px-4 py-2 rounded-2xl rounded-bl-sm max-w-[80%] text-sm text-slate-700">${escapeSettingsHtml(aiResponse)}</div></div>`;
        } else {
            const error = await response.json();
            chatDiv.innerHTML += `<div class="flex"><div class="bg-red-100 text-red-600 px-4 py-2 rounded-2xl rounded-bl-sm text-sm">${error.error?.message || 'เกิดข้อผิดพลาด'}</div></div>`;
        }
    } catch (e) {
        document.getElementById('settingsTyping')?.remove();
        chatDiv.innerHTML += `<div class="flex"><div class="bg-red-100 text-red-600 px-4 py-2 rounded-2xl rounded-bl-sm text-sm">${e.message}</div></div>`;
    }
    
    chatDiv.scrollTop = chatDiv.scrollHeight;
}

function escapeSettingsHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Quick Reply Management
let settingsQuickReplies = [];

function initSettingsQuickReplies() {
    const savedData = document.getElementById('settingsQuickReplyInput').value;
    if (savedData) {
        try {
            settingsQuickReplies = JSON.parse(savedData);
        } catch (e) {
            settingsQuickReplies = [];
        }
    }
    renderSettingsQuickReplies();
}

function renderSettingsQuickReplies() {
    const container = document.getElementById('settingsQuickReplyContainer');
    if (!container) return;
    
    if (settingsQuickReplies.length === 0) {
        container.innerHTML = '<p class="text-slate-400 text-sm">ยังไม่มีปุ่ม Quick Reply</p>';
        return;
    }
    
    container.innerHTML = settingsQuickReplies.map((qr, index) => `
        <div class="flex items-center gap-2 mb-2 p-3 bg-slate-50 rounded-lg border border-slate-200">
            <input type="text" value="${escapeSettingsHtml(qr.label || '')}" placeholder="Label" 
                   class="settings-input-field w-32 text-sm" onchange="updateSettingsQuickReply(${index}, 'label', this.value)">
            <input type="text" value="${escapeSettingsHtml(qr.text || '')}" placeholder="ข้อความที่ส่ง" 
                   class="settings-input-field flex-1 text-sm" onchange="updateSettingsQuickReply(${index}, 'text', this.value)">
            <button type="button" onclick="removeSettingsQuickReply(${index})" class="text-red-500 hover:text-red-700 p-2">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `).join('');
}

function addSettingsQuickReply() {
    if (settingsQuickReplies.length >= 13) {
        alert('Quick Reply สูงสุด 13 ปุ่ม');
        return;
    }
    settingsQuickReplies.push({ label: '', text: '' });
    renderSettingsQuickReplies();
    saveSettingsQuickReplies();
}

function updateSettingsQuickReply(index, field, value) {
    settingsQuickReplies[index][field] = value;
    saveSettingsQuickReplies();
}

function removeSettingsQuickReply(index) {
    settingsQuickReplies.splice(index, 1);
    renderSettingsQuickReplies();
    saveSettingsQuickReplies();
}

function saveSettingsQuickReplies() {
    const validReplies = settingsQuickReplies.filter(qr => qr.label && qr.text);
    document.getElementById('settingsQuickReplyInput').value = JSON.stringify(validReplies);
}

document.addEventListener('DOMContentLoaded', function() {
    initSettingsQuickReplies();
    
    document.getElementById('aiIsEnabled')?.addEventListener('change', function() {
        const badge = document.getElementById('settingsStatusBadge');
        if (this.checked) {
            badge.className = 'settings-status-badge settings-status-on';
            badge.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500"></span>เปิดใช้งาน';
        } else {
            badge.className = 'settings-status-badge settings-status-off';
            badge.innerHTML = '<span class="w-2 h-2 rounded-full bg-slate-400"></span>ปิดใช้งาน';
        }
    });
});
</script>
