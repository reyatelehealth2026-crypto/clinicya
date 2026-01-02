<?php
/**
 * AI Chat Settings - ตั้งค่า AI ตอบแชทอัตโนมัติ
 * Version 3.0 - Flat Design & Soft Colors
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่า AI ตอบแชท';
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
    
    // Add new columns if not exist
    try {
        $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN sender_name VARCHAR(100) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN sender_icon VARCHAR(500) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE ai_chat_settings ADD COLUMN quick_reply_buttons TEXT");
    } catch (Exception $e) {}
} catch (Exception $e) {}

// Get current settings
$settings = [];
try {
    if ($currentBotId) {
        $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE line_account_id = ?");
        $stmt->execute([$currentBotId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {}

// Get API key from multiple sources
$apiKey = $settings['gemini_api_key'] ?? '';
if (empty($apiKey)) {
    try {
        // ai_settings uses column-based structure
        $stmt = $db->prepare("SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ? LIMIT 1");
        $stmt->execute([$currentBotId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $apiKey = $result['gemini_api_key'] ?? '';
    } catch (Exception $e) {}
}

// Handle POST
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        try {
            $data = [
                'line_account_id' => $currentBotId,
                'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
                'gemini_api_key' => trim($_POST['gemini_api_key'] ?? ''),
                'model' => $_POST['model'] ?? 'gemini-2.0-flash',
                'system_prompt' => trim($_POST['system_prompt'] ?? ''),
                'temperature' => floatval($_POST['temperature'] ?? 0.7),
                'max_tokens' => intval($_POST['max_tokens'] ?? 500),
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
            
            // Also update ai_settings table (column-based structure)
            $stmt = $db->prepare("INSERT INTO ai_settings (line_account_id, gemini_api_key, is_enabled, system_prompt, model) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE gemini_api_key = VALUES(gemini_api_key), is_enabled = VALUES(is_enabled), system_prompt = VALUES(system_prompt), model = VALUES(model)");
            $stmt->execute([$currentBotId, $data['gemini_api_key'], $data['is_enabled'], $data['system_prompt'], $data['model']]);
            
            $success = 'บันทึกการตั้งค่าสำเร็จ';
            
            $stmt = $db->prepare("SELECT * FROM ai_chat_settings WHERE line_account_id = ?");
            $stmt->execute([$currentBotId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $apiKey = $settings['gemini_api_key'] ?? '';
            
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}

// Default values
$isEnabled = $settings['is_enabled'] ?? 0;
$model = $settings['model'] ?? 'gemini-2.0-flash';
$systemPrompt = $settings['system_prompt'] ?? '';
$temperature = $settings['temperature'] ?? 0.7;
$maxTokens = $settings['max_tokens'] ?? 500;
$responseStyle = $settings['response_style'] ?? 'friendly';
$fallbackMessage = $settings['fallback_message'] ?? 'ขออภัยค่ะ ไม่เข้าใจคำถาม กรุณาติดต่อเจ้าหน้าที่';
$businessInfo = $settings['business_info'] ?? '';
$productKnowledge = $settings['product_knowledge'] ?? '';
$senderName = $settings['sender_name'] ?? '';
$senderIcon = $settings['sender_icon'] ?? '';
$quickReplyButtons = $settings['quick_reply_buttons'] ?? '';

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Flat Design & Soft Colors */
.card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
.card-header { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; }
.card-body { padding: 20px; }
.btn-primary { background: #6366f1; color: #fff; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.2s; }
.btn-primary:hover { background: #4f46e5; }
.btn-secondary { background: #f1f5f9; color: #64748b; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; transition: all 0.2s; }
.btn-secondary:hover { background: #e2e8f0; color: #475569; }
.input-field { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; transition: all 0.2s; background: #fff; }
.input-field:focus { outline: none; border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.2); }
.label { display: block; font-size: 13px; font-weight: 500; color: #64748b; margin-bottom: 8px; }
.toggle { position: relative; width: 48px; height: 26px; }
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #e2e8f0; border-radius: 26px; transition: 0.3s; }
.toggle-slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; }
.toggle input:checked + .toggle-slider { background: #6366f1; }
.toggle input:checked + .toggle-slider:before { transform: translateX(22px); }
.status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 13px; font-weight: 500; }
.status-on { background: #dcfce7; color: #166534; }
.status-off { background: #f1f5f9; color: #64748b; }
.section-title { font-size: 15px; font-weight: 600; color: #1e293b; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.section-title i { color: #94a3b8; font-size: 14px; }
.hint { font-size: 12px; color: #94a3b8; margin-top: 6px; }
.template-btn { padding: 6px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; color: #64748b; cursor: pointer; transition: all 0.2s; }
.template-btn:hover { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
</style>

<div class="max-w-5xl mx-auto py-6 px-4">
    <?php if ($success): ?>
    <div class="mb-5 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg flex items-center gap-3">
        <i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg flex items-center gap-3">
        <i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-800">AI ตอบแชทอัตโนมัติ</h1>
                <p class="text-slate-500 text-sm mt-1">ใช้ Gemini AI ตอบข้อความลูกค้าอัตโนมัติ</p>
            </div>
            <div class="status-badge <?= $isEnabled ? 'status-on' : 'status-off' ?>" id="statusBadge">
                <span class="w-2 h-2 rounded-full <?= $isEnabled ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                <?= $isEnabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?>
            </div>
        </div>
    </div>

    <form method="POST" id="settingsForm">
        <input type="hidden" name="action" value="save_settings">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Settings -->
            <div class="lg:col-span-2 space-y-5">

                <!-- Enable Toggle -->
                <div class="card">
                    <div class="card-body">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-medium text-slate-800">เปิดใช้งาน AI</h3>
                                <p class="text-sm text-slate-500 mt-1">AI จะตอบข้อความที่ไม่มี Auto-Reply ตรงกัน</p>
                            </div>
                            <label class="toggle">
                                <input type="checkbox" name="is_enabled" id="isEnabled" <?= $isEnabled ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- API Key -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-key"></i>Gemini API Key</h3>
                    </div>
                    <div class="card-body">
                        <div class="relative">
                            <input type="password" name="gemini_api_key" id="apiKeyInput" value="<?= htmlspecialchars($apiKey) ?>" 
                                   class="input-field font-mono pr-24" placeholder="AIzaSy...">
                            <div class="absolute right-2 top-1/2 -translate-y-1/2 flex gap-2">
                                <button type="button" onclick="toggleApiKey()" class="text-slate-400 hover:text-slate-600 p-1">
                                    <i class="fas fa-eye" id="eyeIcon"></i>
                                </button>
                                <button type="button" onclick="testApiKey()" class="btn-secondary text-xs">ทดสอบ</button>
                            </div>
                        </div>
                        <div id="apiTestResult" class="mt-2 text-sm hidden"></div>
                        <p class="hint">
                            <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-indigo-500 hover:underline">
                                รับ API Key ฟรีที่ Google AI Studio →
                            </a>
                        </p>
                    </div>
                </div>

                <!-- Model & Style -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-sliders-h"></i>การตั้งค่า AI</h3>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="label">Model</label>
                                <select name="model" class="input-field">
                                    <option value="gemini-2.0-flash" <?= $model === 'gemini-2.0-flash' ? 'selected' : '' ?>>Gemini 2.0 Flash (แนะนำ)</option>
                                    <option value="gemini-1.5-flash" <?= $model === 'gemini-1.5-flash' ? 'selected' : '' ?>>Gemini 1.5 Flash</option>
                                    <option value="gemini-1.5-pro" <?= $model === 'gemini-1.5-pro' ? 'selected' : '' ?>>Gemini 1.5 Pro</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">สไตล์การตอบ</label>
                                <select name="response_style" class="input-field">
                                    <option value="friendly" <?= $responseStyle === 'friendly' ? 'selected' : '' ?>>เป็นมิตร</option>
                                    <option value="professional" <?= $responseStyle === 'professional' ? 'selected' : '' ?>>มืออาชีพ</option>
                                    <option value="casual" <?= $responseStyle === 'casual' ? 'selected' : '' ?>>สบายๆ</option>
                                    <option value="pharmacy_assistant" <?= $responseStyle === 'pharmacy_assistant' ? 'selected' : '' ?>>ผู้ช่วยเภสัชกร</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Temperature: <span id="tempValue"><?= $temperature ?></span></label>
                                <input type="range" name="temperature" min="0" max="1" step="0.1" value="<?= $temperature ?>" 
                                       class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-indigo-500"
                                       oninput="document.getElementById('tempValue').textContent = this.value">
                                <div class="flex justify-between text-xs text-slate-400 mt-1">
                                    <span>แม่นยำ</span>
                                    <span>สร้างสรรค์</span>
                                </div>
                            </div>
                            <div>
                                <label class="label">ความยาวสูงสุด (tokens)</label>
                                <input type="number" name="max_tokens" value="<?= $maxTokens ?>" min="100" max="2000" class="input-field">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Prompt -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-user-cog"></i>บุคลิกของ AI</h3>
                    </div>
                    <div class="card-body">
                        <textarea name="system_prompt" rows="4" class="input-field resize-none"
                                  placeholder="เช่น: คุณเป็นผู้ช่วยขายของร้าน ABC ตอบคำถามลูกค้าอย่างเป็นมิตร..."><?= htmlspecialchars($systemPrompt) ?></textarea>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" onclick="setPromptTemplate('shop')" class="template-btn">🛒 ร้านค้า</button>
                            <button type="button" onclick="setPromptTemplate('pharmacy')" class="template-btn">💊 ร้านยา</button>
                            <button type="button" onclick="setPromptTemplate('restaurant')" class="template-btn">🍜 ร้านอาหาร</button>
                            <button type="button" onclick="setPromptTemplate('service')" class="template-btn">💆 บริการ</button>
                            <button type="button" onclick="setPromptTemplate('support')" class="template-btn">📞 Support</button>
                        </div>
                    </div>
                </div>

                <!-- Business Info -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-store"></i>ข้อมูลธุรกิจ</h3>
                    </div>
                    <div class="card-body">
                        <textarea name="business_info" rows="3" class="input-field resize-none"
                                  placeholder="เช่น: ร้าน ABC เปิด 9:00-21:00 ทุกวัน, ที่อยู่: 123 ถ.สุขุมวิท..."><?= htmlspecialchars($businessInfo) ?></textarea>
                        <div class="mt-2 flex gap-2">
                            <button type="button" onclick="loadBusinessInfoFromDB()" class="btn-secondary">
                                <i class="fas fa-sync mr-1"></i>โหลดจากฐานข้อมูล
                            </button>
                        </div>
                        <p class="hint">ข้อมูลที่ AI จะใช้ในการตอบคำถามเกี่ยวกับร้าน</p>
                    </div>
                </div>

                <!-- Product Knowledge -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-box"></i>ความรู้เกี่ยวกับสินค้า</h3>
                    </div>
                    <div class="card-body">
                        <textarea name="product_knowledge" rows="3" class="input-field resize-none"
                                  placeholder="เช่น: สินค้าขายดี: ขมิ้นชัน 250 บาท, พาราเซตามอล 35 บาท..."><?= htmlspecialchars($productKnowledge) ?></textarea>
                        <div class="mt-2 flex gap-2">
                            <button type="button" onclick="loadProductsFromDB()" class="btn-secondary">
                                <i class="fas fa-sync mr-1"></i>โหลดสินค้า
                            </button>
                            <button type="button" onclick="loadAllFromDB()" class="btn-secondary" style="background: #dbeafe; color: #1e40af;">
                                <i class="fas fa-database mr-1"></i>โหลดทั้งหมด (ธุรกิจ+สินค้า)
                            </button>
                        </div>
                        <p class="hint">ข้อมูลสินค้าที่ AI จะใช้แนะนำลูกค้า</p>
                    </div>
                </div>

                <!-- Fallback Message -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-comment-slash"></i>ข้อความเมื่อ AI ตอบไม่ได้</h3>
                    </div>
                    <div class="card-body">
                        <textarea name="fallback_message" rows="2" class="input-field resize-none"
                                  placeholder="ข้อความที่จะส่งเมื่อ AI ไม่สามารถตอบได้"><?= htmlspecialchars($fallbackMessage) ?></textarea>
                    </div>
                </div>

                <!-- Sender Settings -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-user-circle"></i>ตั้งค่าผู้ส่ง (Sender)</h3>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="label">ชื่อผู้ส่ง</label>
                                <input type="text" name="sender_name" value="<?= htmlspecialchars($senderName) ?>" 
                                       class="input-field" placeholder="เช่น: ผู้ช่วยเภสัชกร, AI Assistant">
                            </div>
                            <div>
                                <label class="label">Icon URL</label>
                                <input type="url" name="sender_icon" value="<?= htmlspecialchars($senderIcon) ?>" 
                                       class="input-field" placeholder="https://example.com/icon.png">
                            </div>
                        </div>
                        <div class="mt-3 flex items-center gap-3">
                            <?php if ($senderIcon): ?>
                            <img src="<?= htmlspecialchars($senderIcon) ?>" alt="Sender Icon" class="w-10 h-10 rounded-full object-cover border">
                            <?php endif; ?>
                            <p class="hint">ชื่อและรูปที่จะแสดงเป็นผู้ส่งข้อความ AI (ต้องเป็น HTTPS, ขนาดไม่เกิน 1MB)</p>
                        </div>
                    </div>
                </div>

                <!-- Quick Reply Buttons -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-reply-all"></i>Quick Reply Buttons</h3>
                    </div>
                    <div class="card-body">
                        <div id="quickReplyContainer">
                            <!-- Quick reply buttons will be added here -->
                        </div>
                        <button type="button" onclick="addQuickReply()" class="btn-secondary mt-3">
                            <i class="fas fa-plus mr-1"></i>เพิ่มปุ่ม Quick Reply
                        </button>
                        <input type="hidden" name="quick_reply_buttons" id="quickReplyInput" value="<?= htmlspecialchars($quickReplyButtons) ?>">
                        <p class="hint mt-2">ปุ่มที่จะแสดงให้ลูกค้ากดตอบกลับ (สูงสุด 13 ปุ่ม)</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" onclick="setQuickReplyTemplate('pharmacy')" class="template-btn">💊 ร้านยา</button>
                            <button type="button" onclick="setQuickReplyTemplate('shop')" class="template-btn">🛒 ร้านค้า</button>
                            <button type="button" onclick="setQuickReplyTemplate('service')" class="template-btn">💆 บริการ</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-5">
                <!-- Save Button -->
                <div class="card">
                    <div class="card-body">
                        <button type="submit" class="btn-primary w-full">
                            <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
                        </button>
                    </div>
                </div>

                <!-- Test Chat -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-comments"></i>ทดสอบแชท</h3>
                    </div>
                    <div id="chatMessages" class="h-56 overflow-y-auto p-4 space-y-3 bg-slate-50 border-b border-slate-100">
                        <div class="text-center text-slate-400 text-sm">พิมพ์ข้อความเพื่อทดสอบ AI</div>
                    </div>
                    <div class="p-3 flex gap-2">
                        <input type="text" id="testMessage" placeholder="พิมพ์ข้อความทดสอบ..." 
                               class="input-field flex-1" onkeypress="if(event.key==='Enter')sendTestMessage()">
                        <button type="button" onclick="sendTestMessage()" class="btn-primary px-4">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>

                <!-- Tips -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-lightbulb"></i>เคล็ดลับ</h3>
                    </div>
                    <div class="card-body">
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

                <!-- Stats -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="section-title"><i class="fas fa-chart-bar"></i>สถิติ AI</h3>
                    </div>
                    <div class="card-body">
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-500">ตอบวันนี้</span>
                                <span class="font-medium text-slate-700" id="todayCount">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">ตอบเดือนนี้</span>
                                <span class="font-medium text-slate-700" id="monthCount">-</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">เวลาตอบเฉลี่ย</span>
                                <span class="font-medium text-slate-700" id="avgTime">-</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const promptTemplates = {
    professional_pharmacy: `คุณคือ "เภสัชกรวิชาชีพ" ผู้มีความเชี่ยวชาญและเห็นอกเห็นใจคนไข้

เป้าหมาย:
1. วิเคราะห์อาการเบื้องต้นผ่านการสนทนาที่ลื่นไหล
2. ถามข้อมูลสำคัญ: อาการ, ระยะเวลา, อาการร่วม, แพ้ยา, โรคประจำตัว (ถามทีละอย่าง)
3. หากลูกค้าตอบปฏิเสธ (ไม่มี/ไม่เคย) ให้เชื่อทันทีและข้ามไปถามข้อถัดไป
4. สรุปและแนะนำยาสามัญ (OTC) ที่ปลอดภัยเมื่อข้อมูลครบถ้วน
5. กำชับให้รอการยืนยันจากเภสัชกรก่อนใช้ยาจริงเสมอ`,

    general_shop: `คุณเป็นผู้ช่วยขายของที่กระฉับกระเฉงและสุภาพ
- แนะนำสินค้าตามความต้องการลูกค้า
- ตอบเรื่องราคาและโปรโมชั่น
- ช่วยปิดการขายอย่างเป็นธรรมชาติ`
};

function setPromptTemplate(type) {
    const area = document.querySelector('textarea[name="system_prompt"]');
    if (area) area.value = promptTemplates[type] || '';
}
function toggleApiKey() {
    const input = document.getElementById('apiKeyInput');
    const icon = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

async function testApiKey() {
    const apiKey = document.getElementById('apiKeyInput').value.trim();
    const resultDiv = document.getElementById('apiTestResult');
    
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

async function sendTestMessage() {
    const input = document.getElementById('testMessage');
    const message = input.value.trim();
    if (!message) return;
    
    const apiKey = document.getElementById('apiKeyInput').value.trim();
    if (!apiKey) { alert('กรุณากรอก API Key ก่อน'); return; }
    
    const chatDiv = document.getElementById('chatMessages');
    
    chatDiv.innerHTML += `<div class="flex justify-end"><div class="bg-indigo-500 text-white px-4 py-2 rounded-2xl rounded-br-sm max-w-[80%] text-sm">${escapeHtml(message)}</div></div>`;
    input.value = '';
    chatDiv.scrollTop = chatDiv.scrollHeight;
    
    chatDiv.innerHTML += `<div id="typing" class="flex"><div class="bg-slate-200 px-4 py-2 rounded-2xl rounded-bl-sm text-slate-500 text-sm"><i class="fas fa-ellipsis-h animate-pulse"></i></div></div>`;
    chatDiv.scrollTop = chatDiv.scrollHeight;
    
    try {
        const systemPrompt = document.querySelector('textarea[name="system_prompt"]').value;
        const businessInfo = document.querySelector('textarea[name="business_info"]').value;
        const productKnowledge = document.querySelector('textarea[name="product_knowledge"]').value;
        
        let fullPrompt = '';
        if (systemPrompt) fullPrompt += `System: ${systemPrompt}\n\n`;
        if (businessInfo) fullPrompt += `ข้อมูลธุรกิจ: ${businessInfo}\n\n`;
        if (productKnowledge) fullPrompt += `ข้อมูลสินค้า: ${productKnowledge}\n\n`;
        fullPrompt += `ลูกค้าถาม: ${message}\n\nตอบ:`;
        
        const model = document.querySelector('select[name="model"]').value;
        const temperature = parseFloat(document.querySelector('input[name="temperature"]').value);
        
        const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${apiKey}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ contents: [{ parts: [{ text: fullPrompt }] }], generationConfig: { temperature } })
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

async function loadProductsFromDB() {
    try {
        const response = await fetch('api/ajax_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_products_for_ai'
        });
        const data = await response.json();
        
        if (data.success && data.products) {
            let knowledge = 'สินค้าในร้าน:\n';
            data.products.forEach(p => {
                knowledge += `${p.name}: ${p.price} บาท`;
                if (p.description) knowledge += ` (${p.description})`;
                knowledge += '\n';
            });
            document.querySelector('textarea[name="product_knowledge"]').value = knowledge;
            alert('โหลดข้อมูลสินค้าสำเร็จ ' + data.products.length + ' รายการ');
        } else {
            alert('ไม่พบข้อมูลสินค้า');
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด: ' + e.message);
    }
}

async function loadBusinessInfoFromDB() {
    try {
        const response = await fetch('api/ajax_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_business_info_for_ai'
        });
        const data = await response.json();
        
        if (data.success && data.business_info) {
            document.querySelector('textarea[name="business_info"]').value = data.business_info;
            alert('โหลดข้อมูลธุรกิจสำเร็จ');
        } else {
            alert('ไม่พบข้อมูลธุรกิจ กรุณาตั้งค่าร้านค้าก่อน');
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด: ' + e.message);
    }
}

async function loadAllFromDB() {
    try {
        const response = await fetch('api/ajax_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_business_info_for_ai'
        });
        const data = await response.json();
        
        if (data.success) {
            let loaded = [];
            
            if (data.business_info) {
                document.querySelector('textarea[name="business_info"]').value = data.business_info;
                loaded.push('ข้อมูลธุรกิจ');
            }
            
            if (data.product_knowledge) {
                document.querySelector('textarea[name="product_knowledge"]').value = data.product_knowledge;
                loaded.push('สินค้า ' + (data.raw?.product_count || 0) + ' รายการ');
            }
            
            if (loaded.length > 0) {
                alert('โหลดสำเร็จ: ' + loaded.join(', '));
            } else {
                alert('ไม่พบข้อมูล กรุณาตั้งค่าร้านค้าและเพิ่มสินค้าก่อน');
            }
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.error || 'ไม่สามารถโหลดข้อมูลได้'));
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด: ' + e.message);
    }
}

document.getElementById('isEnabled')?.addEventListener('change', function() {
    const badge = document.getElementById('statusBadge');
    const dot = badge.querySelector('span');
    if (this.checked) {
        badge.className = 'status-badge status-on';
        badge.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500"></span>เปิดใช้งาน';
    } else {
        badge.className = 'status-badge status-off';
        badge.innerHTML = '<span class="w-2 h-2 rounded-full bg-slate-400"></span>ปิดใช้งาน';
    }
});

// Quick Reply Management - รองรับหลาย action types
let quickReplies = [];

// Action types ที่รองรับ
const actionTypes = {
    message: { name: 'ข้อความ', fields: ['text'] },
    uri: { name: 'เปิด URL/LIFF', fields: ['uri'] },
    postback: { name: 'Postback', fields: ['data', 'displayText'] },
    datetimepicker: { name: 'เลือกวันเวลา', fields: ['data', 'mode'] },
    camera: { name: 'เปิดกล้อง', fields: [] },
    cameraRoll: { name: 'เลือกรูปจากอัลบั้ม', fields: [] },
    location: { name: 'ส่งตำแหน่ง', fields: [] }
};

const quickReplyTemplates = {
    pharmacy: [
        { label: '💊 สอบถามอาการ', type: 'message', text: 'สอบถามอาการ' },
        { label: '💉 ปรึกษาเภสัชกร', type: 'message', text: 'ต้องการปรึกษาเภสัชกร' },
        { label: '🛒 เข้าร้านค้า', type: 'uri', uri: 'https://liff.line.me/YOUR_LIFF_ID' },
        { label: '📍 ส่งตำแหน่ง', type: 'location' },
        { label: '🚚 จัดส่งยา', type: 'message', text: 'สั่งยาส่งได้ไหม' }
    ],
    shop: [
        { label: '🛒 ดูสินค้า', type: 'uri', uri: 'https://liff.line.me/YOUR_LIFF_ID' },
        { label: '💰 ราคา', type: 'message', text: 'สอบถามราคา' },
        { label: '🚚 การจัดส่ง', type: 'message', text: 'ค่าส่งเท่าไหร่' },
        { label: '📷 ส่งรูปสินค้า', type: 'cameraRoll' },
        { label: '📞 ติดต่อร้าน', type: 'uri', uri: 'tel:0991915416' }
    ],
    service: [
        { label: '📅 จองคิว', type: 'datetimepicker', data: 'action=booking', mode: 'datetime' },
        { label: '💆 บริการ', type: 'message', text: 'มีบริการอะไรบ้าง' },
        { label: '💰 ราคา', type: 'message', text: 'ราคาเท่าไหร่' },
        { label: '📍 ที่ตั้ง', type: 'location' },
        { label: '📷 ส่งรูป', type: 'camera' }
    ]
};

function initQuickReplies() {
    const savedData = document.getElementById('quickReplyInput').value;
    if (savedData) {
        try {
            quickReplies = JSON.parse(savedData);
            // Migrate old format (label, text only) to new format
            quickReplies = quickReplies.map(qr => {
                if (!qr.type) {
                    return { label: qr.label, type: 'message', text: qr.text };
                }
                return qr;
            });
        } catch (e) {
            quickReplies = [];
        }
    }
    renderQuickReplies();
}

function renderQuickReplies() {
    const container = document.getElementById('quickReplyContainer');
    if (!container) return;
    
    if (quickReplies.length === 0) {
        container.innerHTML = '<p class="text-slate-400 text-sm">ยังไม่มีปุ่ม Quick Reply</p>';
        return;
    }
    
    container.innerHTML = quickReplies.map((qr, index) => {
        const typeOptions = Object.entries(actionTypes).map(([key, val]) => 
            `<option value="${key}" ${qr.type === key ? 'selected' : ''}>${val.name}</option>`
        ).join('');
        
        let extraFields = '';
        const type = qr.type || 'message';
        
        if (type === 'message') {
            extraFields = `<input type="text" value="${escapeHtml(qr.text || '')}" placeholder="ข้อความที่ส่ง" 
                           class="input-field flex-1 text-sm" onchange="updateQuickReply(${index}, 'text', this.value)">`;
        } else if (type === 'uri') {
            extraFields = `<input type="text" value="${escapeHtml(qr.uri || '')}" placeholder="URL หรือ LIFF URL" 
                           class="input-field flex-1 text-sm" onchange="updateQuickReply(${index}, 'uri', this.value)">`;
        } else if (type === 'postback') {
            extraFields = `<input type="text" value="${escapeHtml(qr.data || '')}" placeholder="Postback data" 
                           class="input-field flex-1 text-sm" onchange="updateQuickReply(${index}, 'data', this.value)">
                           <input type="text" value="${escapeHtml(qr.displayText || '')}" placeholder="Display text (optional)" 
                           class="input-field w-32 text-sm" onchange="updateQuickReply(${index}, 'displayText', this.value)">`;
        } else if (type === 'datetimepicker') {
            extraFields = `<input type="text" value="${escapeHtml(qr.data || '')}" placeholder="Postback data" 
                           class="input-field flex-1 text-sm" onchange="updateQuickReply(${index}, 'data', this.value)">
                           <select class="input-field w-28 text-sm" onchange="updateQuickReply(${index}, 'mode', this.value)">
                               <option value="datetime" ${qr.mode === 'datetime' ? 'selected' : ''}>วัน+เวลา</option>
                               <option value="date" ${qr.mode === 'date' ? 'selected' : ''}>วันที่</option>
                               <option value="time" ${qr.mode === 'time' ? 'selected' : ''}>เวลา</option>
                           </select>`;
        }
        // camera, cameraRoll, location ไม่ต้องมี extra fields
        
        return `
        <div class="flex flex-wrap items-center gap-2 mb-2 p-3 bg-slate-50 rounded-lg border border-slate-200">
            <input type="text" value="${escapeHtml(qr.label || '')}" placeholder="Label (แสดงบนปุ่ม)" 
                   class="input-field w-40 text-sm" onchange="updateQuickReply(${index}, 'label', this.value)">
            <select class="input-field w-36 text-sm" onchange="changeQuickReplyType(${index}, this.value)">
                ${typeOptions}
            </select>
            ${extraFields}
            <button type="button" onclick="removeQuickReply(${index})" class="text-red-500 hover:text-red-700 p-2">
                <i class="fas fa-trash"></i>
            </button>
        </div>`;
    }).join('');
}

function addQuickReply() {
    if (quickReplies.length >= 13) {
        alert('Quick Reply สูงสุด 13 ปุ่ม');
        return;
    }
    quickReplies.push({ label: '', type: 'message', text: '' });
    renderQuickReplies();
    saveQuickReplies();
}

function changeQuickReplyType(index, newType) {
    const label = quickReplies[index].label;
    quickReplies[index] = { label, type: newType };
    
    // Set defaults based on type
    if (newType === 'message') quickReplies[index].text = '';
    else if (newType === 'uri') quickReplies[index].uri = '';
    else if (newType === 'postback') { quickReplies[index].data = ''; quickReplies[index].displayText = ''; }
    else if (newType === 'datetimepicker') { quickReplies[index].data = ''; quickReplies[index].mode = 'datetime'; }
    
    renderQuickReplies();
    saveQuickReplies();
}

function updateQuickReply(index, field, value) {
    quickReplies[index][field] = value;
    saveQuickReplies();
}

function removeQuickReply(index) {
    quickReplies.splice(index, 1);
    renderQuickReplies();
    saveQuickReplies();
}

function saveQuickReplies() {
    const validReplies = quickReplies.filter(qr => {
        if (!qr.label) return false;
        if (qr.type === 'message' && !qr.text) return false;
        if (qr.type === 'uri' && !qr.uri) return false;
        if (qr.type === 'postback' && !qr.data) return false;
        if (qr.type === 'datetimepicker' && !qr.data) return false;
        return true;
    });
    document.getElementById('quickReplyInput').value = JSON.stringify(validReplies);
}

function setQuickReplyTemplate(type) {
    if (quickReplyTemplates[type]) {
        quickReplies = JSON.parse(JSON.stringify(quickReplyTemplates[type]));
        renderQuickReplies();
        saveQuickReplies();
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initQuickReplies();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
