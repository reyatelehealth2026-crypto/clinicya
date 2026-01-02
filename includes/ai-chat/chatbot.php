<?php
/**
 * AI Chatbot Tab - ตอบคำถามอัตโนมัติด้วย OpenAI GPT
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */

// Handle save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['chatbot_action'] ?? '') === 'save_chatbot') {
    $stmt = $db->prepare("UPDATE ai_settings SET is_enabled=?, system_prompt=?, model=?, max_tokens=?, temperature=? WHERE id=1");
    $stmt->execute([
        isset($_POST['is_enabled']) ? 1 : 0,
        $_POST['system_prompt'],
        $_POST['model'],
        (int)$_POST['max_tokens'],
        (float)$_POST['temperature']
    ]);
    header('Location: ai-chat.php?tab=chatbot&saved=1');
    exit;
}

// Get current settings
$stmt = $db->query("SELECT * FROM ai_settings WHERE id = 1");
$chatbotSettings = $stmt->fetch();
if (!$chatbotSettings) {
    $chatbotSettings = [
        'is_enabled' => 0,
        'system_prompt' => '',
        'model' => 'gpt-3.5-turbo',
        'max_tokens' => 500,
        'temperature' => 0.7
    ];
}
?>

<?php if (isset($_GET['saved'])): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i>บันทึกการตั้งค่าสำเร็จ!
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Settings -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold mb-4">ตั้งค่า AI Chatbot</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="chatbot_action" value="save_chatbot">
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                <div>
                    <p class="font-medium">เปิดใช้งาน AI Chatbot</p>
                    <p class="text-sm text-gray-500">ตอบข้อความอัตโนมัติเมื่อไม่มี Auto-Reply ที่ตรงกัน</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_enabled" class="sr-only peer" <?= $chatbotSettings['is_enabled'] ? 'checked' : '' ?>>
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500"></div>
                </label>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">Model</label>
                <select name="model" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    <option value="gpt-3.5-turbo" <?= ($chatbotSettings['model'] ?? '') === 'gpt-3.5-turbo' ? 'selected' : '' ?>>GPT-3.5 Turbo</option>
                    <option value="gpt-4" <?= ($chatbotSettings['model'] ?? '') === 'gpt-4' ? 'selected' : '' ?>>GPT-4</option>
                    <option value="gpt-4-turbo" <?= ($chatbotSettings['model'] ?? '') === 'gpt-4-turbo' ? 'selected' : '' ?>>GPT-4 Turbo</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium mb-1">System Prompt</label>
                <textarea name="system_prompt" rows="6" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="กำหนดบุคลิกและพฤติกรรมของ AI..."><?= htmlspecialchars($chatbotSettings['system_prompt'] ?? '') ?></textarea>
                <p class="text-xs text-gray-500 mt-1">กำหนดบทบาทและวิธีการตอบของ AI</p>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Max Tokens</label>
                    <input type="number" name="max_tokens" value="<?= $chatbotSettings['max_tokens'] ?? 500 ?>" min="50" max="4000" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Temperature</label>
                    <input type="number" name="temperature" value="<?= $chatbotSettings['temperature'] ?? 0.7 ?>" min="0" max="2" step="0.1" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>
            
            <button type="submit" class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
            </button>
        </form>
    </div>

    <!-- Info & Tips -->
    <div class="space-y-6">
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-lg font-semibold mb-4">วิธีการทำงาน</h3>
            <div class="space-y-3 text-sm text-gray-600">
                <div class="flex items-start">
                    <span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0">1</span>
                    <p>เมื่อมีข้อความเข้ามา ระบบจะตรวจสอบ Auto-Reply ก่อน</p>
                </div>
                <div class="flex items-start">
                    <span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0">2</span>
                    <p>หากไม่มี Auto-Reply ที่ตรงกัน และเปิดใช้งาน AI Chatbot</p>
                </div>
                <div class="flex items-start">
                    <span class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0">3</span>
                    <p>ระบบจะส่งข้อความไปยัง OpenAI และตอบกลับอัตโนมัติ</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-lg font-semibold mb-4">ตัวอย่าง System Prompt</h3>
            <div class="space-y-3">
                <div class="p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100" onclick="setChatbotPrompt(this.querySelector('p').textContent)">
                    <p class="text-sm">คุณเป็นผู้ช่วยฝ่ายบริการลูกค้าที่เป็นมิตร ตอบคำถามอย่างสุภาพและกระชับ ใช้ภาษาไทย</p>
                </div>
                <div class="p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100" onclick="setChatbotPrompt(this.querySelector('p').textContent)">
                    <p class="text-sm">คุณเป็นผู้เชี่ยวชาญด้านสินค้า ช่วยแนะนำสินค้าและตอบคำถามเกี่ยวกับราคา โปรโมชั่น และการจัดส่ง</p>
                </div>
                <div class="p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100" onclick="setChatbotPrompt(this.querySelector('p').textContent)">
                    <p class="text-sm">คุณเป็นผู้ช่วยจองคิว ช่วยลูกค้าจองนัดหมาย ตรวจสอบเวลาว่าง และยืนยันการจอง</p>
                </div>
            </div>
        </div>
        
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
            <div class="flex">
                <i class="fas fa-exclamation-triangle text-yellow-500 mr-3 mt-1"></i>
                <div>
                    <p class="font-medium text-yellow-800">หมายเหตุ</p>
                    <p class="text-sm text-yellow-700">การใช้งาน AI Chatbot จะมีค่าใช้จ่ายตาม API ของ OpenAI กรุณาตรวจสอบ usage และ billing ของคุณ</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function setChatbotPrompt(text) {
    document.querySelector('textarea[name="system_prompt"]').value = text;
}
</script>
