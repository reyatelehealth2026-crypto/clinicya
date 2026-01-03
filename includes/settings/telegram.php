<?php
/**
 * Telegram Settings Tab Content
 * Part of consolidated settings.php
 */

// Ensure table exists with all columns
try {
    $db->exec("CREATE TABLE IF NOT EXISTS telegram_settings (
        id INT PRIMARY KEY DEFAULT 1,
        bot_token VARCHAR(100) DEFAULT NULL,
        chat_id VARCHAR(50) DEFAULT NULL,
        is_enabled TINYINT(1) DEFAULT 0,
        notify_new_follower TINYINT(1) DEFAULT 1,
        notify_new_message TINYINT(1) DEFAULT 1,
        notify_unfollow TINYINT(1) DEFAULT 1,
        notify_new_order TINYINT(1) DEFAULT 1,
        notify_payment TINYINT(1) DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $db->exec("INSERT IGNORE INTO telegram_settings (id) VALUES (1)");
    
    // Add missing columns if table already exists
    $cols = $db->query("SHOW COLUMNS FROM telegram_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('notify_new_order', $cols)) {
        $db->exec("ALTER TABLE telegram_settings ADD COLUMN notify_new_order TINYINT(1) DEFAULT 1");
    }
    if (!in_array('notify_payment', $cols)) {
        $db->exec("ALTER TABLE telegram_settings ADD COLUMN notify_payment TINYINT(1) DEFAULT 1");
    }
} catch (Exception $e) {}

// Get current settings
$telegramSettings = [];
try {
    $stmt = $db->query("SELECT * FROM telegram_settings WHERE id = 1");
    $telegramSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Bot Token & Chat ID Settings -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold mb-4">
            <i class="fab fa-telegram text-blue-500 mr-2"></i>ตั้งค่า Telegram Bot
        </h3>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_telegram_token">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Bot Token</label>
                <input type="text" name="bot_token" value="<?= htmlspecialchars($telegramSettings['bot_token'] ?? '') ?>" 
                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                <p class="text-xs text-gray-500 mt-1">รับ Token จาก @BotFather</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Chat ID</label>
                <div class="flex gap-2">
                    <input type="text" name="chat_id" value="<?= htmlspecialchars($telegramSettings['chat_id'] ?? '') ?>" 
                        class="flex-1 px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="-1001234567890">
                    <button type="submit" name="action" value="get_telegram_chat_id" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-500 mt-1">Chat ID ของคุณหรือกลุ่ม (กดปุ่มค้นหาหลังส่งข้อความหา Bot)</p>
            </div>
            
            <button type="submit" class="w-full py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 font-medium">
                <i class="fas fa-save mr-2"></i>บันทึก Token & Chat ID
            </button>
        </form>
        
        <hr class="my-4">
        
        <div class="flex gap-2">
            <form method="POST" class="flex-1">
                <input type="hidden" name="action" value="test_telegram">
                <button type="submit" class="w-full py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                    <i class="fas fa-paper-plane mr-2"></i>ทดสอบส่งข้อความ
                </button>
            </form>
            <form method="POST" class="flex-1">
                <input type="hidden" name="action" value="set_telegram_webhook">
                <button type="submit" class="w-full py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 font-medium">
                    <i class="fas fa-link mr-2"></i>ตั้งค่า Webhook
                </button>
            </form>
        </div>
    </div>

    <!-- Notification Settings -->
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold mb-4">
            <i class="fas fa-bell text-yellow-500 mr-2"></i>ตั้งค่าการแจ้งเตือน
        </h3>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_telegram_notifications">
            
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                <div>
                    <p class="font-medium">เปิดใช้งานการแจ้งเตือน</p>
                    <p class="text-sm text-gray-500">รับการแจ้งเตือนผ่าน Telegram Bot</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_enabled" class="sr-only peer" <?= ($telegramSettings['is_enabled'] ?? 0) ? 'checked' : '' ?>>
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500"></div>
                </label>
            </div>

            <div class="space-y-3">
                <p class="font-medium text-gray-700">เลือกประเภทการแจ้งเตือน:</p>
                
                <label class="flex items-center p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                    <input type="checkbox" name="notify_new_follower" class="mr-3 w-4 h-4 text-blue-600" <?= ($telegramSettings['notify_new_follower'] ?? 0) ? 'checked' : '' ?>>
                    <div>
                        <p class="font-medium">🎉 ผู้ติดตามใหม่</p>
                        <p class="text-sm text-gray-500">แจ้งเตือนเมื่อมีคนเพิ่มเพื่อน LINE OA</p>
                    </div>
                </label>
                
                <label class="flex items-center p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                    <input type="checkbox" name="notify_new_message" class="mr-3 w-4 h-4 text-blue-600" <?= ($telegramSettings['notify_new_message'] ?? 0) ? 'checked' : '' ?>>
                    <div>
                        <p class="font-medium">💬 ข้อความใหม่</p>
                        <p class="text-sm text-gray-500">แจ้งเตือนเมื่อมีข้อความเข้ามา</p>
                    </div>
                </label>
                
                <label class="flex items-center p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                    <input type="checkbox" name="notify_unfollow" class="mr-3 w-4 h-4 text-blue-600" <?= ($telegramSettings['notify_unfollow'] ?? 0) ? 'checked' : '' ?>>
                    <div>
                        <p class="font-medium">😢 ยกเลิกติดตาม</p>
                        <p class="text-sm text-gray-500">แจ้งเตือนเมื่อมีคนบล็อก LINE OA</p>
                    </div>
                </label>
                
                <label class="flex items-center p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                    <input type="checkbox" name="notify_new_order" class="mr-3 w-4 h-4 text-blue-600" <?= ($telegramSettings['notify_new_order'] ?? 0) ? 'checked' : '' ?>>
                    <div>
                        <p class="font-medium">🛒 ออเดอร์ใหม่</p>
                        <p class="text-sm text-gray-500">แจ้งเตือนเมื่อมีคำสั่งซื้อใหม่</p>
                    </div>
                </label>
                
                <label class="flex items-center p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                    <input type="checkbox" name="notify_payment" class="mr-3 w-4 h-4 text-blue-600" <?= ($telegramSettings['notify_payment'] ?? 0) ? 'checked' : '' ?>>
                    <div>
                        <p class="font-medium">💳 การชำระเงิน</p>
                        <p class="text-sm text-gray-500">แจ้งเตือนเมื่อมีการแนบสลิป/ชำระเงิน</p>
                    </div>
                </label>
            </div>
            
            <button type="submit" class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
            </button>
        </form>
    </div>
</div>

<!-- Instructions -->
<div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold mb-4">
            <i class="fas fa-book text-purple-500 mr-2"></i>วิธีตั้งค่า Telegram Bot
        </h3>
        <div class="space-y-3 text-sm text-gray-600">
            <div class="flex items-start">
                <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0 text-xs font-bold">1</span>
                <p>เปิด Telegram แล้วค้นหา <a href="https://t.me/BotFather" target="_blank" class="text-blue-500 hover:underline font-medium">@BotFather</a></p>
            </div>
            <div class="flex items-start">
                <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0 text-xs font-bold">2</span>
                <p>พิมพ์ <code class="bg-gray-100 px-2 py-0.5 rounded">/newbot</code> แล้วตั้งชื่อ Bot</p>
            </div>
            <div class="flex items-start">
                <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0 text-xs font-bold">3</span>
                <p>คัดลอก <b>Bot Token</b> ที่ได้รับมาใส่ในช่องด้านบน</p>
            </div>
            <div class="flex items-start">
                <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0 text-xs font-bold">4</span>
                <p>เริ่มแชทกับ Bot ของคุณ แล้วส่งข้อความอะไรก็ได้ 1 ข้อความ</p>
            </div>
            <div class="flex items-start">
                <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0 text-xs font-bold">5</span>
                <p>กดปุ่ม <i class="fas fa-search"></i> ข้าง Chat ID เพื่อค้นหา Chat ID อัตโนมัติ</p>
            </div>
            <div class="flex items-start">
                <span class="w-6 h-6 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mr-3 flex-shrink-0 text-xs font-bold">6</span>
                <p>กด "ทดสอบส่งข้อความ" เพื่อตรวจสอบว่าทำงานได้</p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="text-lg font-semibold mb-4">
            <i class="fas fa-terminal text-green-500 mr-2"></i>คำสั่งที่ใช้ได้ใน Telegram
        </h3>
        <div class="space-y-2 text-sm">
            <div class="p-3 bg-gray-50 rounded-lg">
                <code class="text-purple-600 font-mono">/reply [ID] ข้อความ</code>
                <p class="text-gray-500 mt-1">ตอบกลับข้อความไปยัง LINE User</p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <code class="text-purple-600 font-mono">/r [ID] ข้อความ</code>
                <p class="text-gray-500 mt-1">ตอบกลับ (แบบสั้น)</p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <code class="text-purple-600 font-mono">/broadcast ข้อความ</code>
                <p class="text-gray-500 mt-1">ส่งข้อความถึงผู้ติดตามทุกคน</p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <code class="text-purple-600 font-mono">/users</code>
                <p class="text-gray-500 mt-1">ดูรายชื่อผู้ใช้ล่าสุด</p>
            </div>
            <div class="p-3 bg-gray-50 rounded-lg">
                <code class="text-purple-600 font-mono">/stats</code>
                <p class="text-gray-500 mt-1">ดูสถิติระบบ</p>
            </div>
        </div>
    </div>
</div>
