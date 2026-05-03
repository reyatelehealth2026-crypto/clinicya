<?php
/**
 * Notification Settings Tab Content
 * Part of consolidated settings.php
 */

$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Ensure notification_settings table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS notification_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT NOT NULL DEFAULT 0,
        line_notify_enabled TINYINT(1) DEFAULT 1,
        line_notify_new_order TINYINT(1) DEFAULT 1,
        line_notify_payment TINYINT(1) DEFAULT 1,
        line_notify_urgent TINYINT(1) DEFAULT 1,
        line_notify_appointment TINYINT(1) DEFAULT 1,
        line_notify_low_stock TINYINT(1) DEFAULT 0,
        email_enabled TINYINT(1) DEFAULT 0,
        email_addresses TEXT DEFAULT NULL,
        email_notify_urgent TINYINT(1) DEFAULT 1,
        email_notify_daily_report TINYINT(1) DEFAULT 0,
        email_notify_low_stock TINYINT(1) DEFAULT 0,
        telegram_enabled TINYINT(1) DEFAULT 0,
        odoo_liff_notify_enabled TINYINT(1) DEFAULT 1,
        odoo_liff_notify_events TEXT DEFAULT NULL,
        notify_admin_users TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_account (line_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns = $db->query("SHOW COLUMNS FROM notification_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('odoo_liff_notify_enabled', $columns, true)) {
        $db->exec("ALTER TABLE notification_settings ADD COLUMN odoo_liff_notify_enabled TINYINT(1) DEFAULT 1 AFTER telegram_enabled");
    }
    if (!in_array('odoo_liff_notify_events', $columns, true)) {
        $db->exec("ALTER TABLE notification_settings ADD COLUMN odoo_liff_notify_events TEXT DEFAULT NULL AFTER odoo_liff_notify_enabled");
    }
    $db->exec("UPDATE notification_settings SET line_account_id = 0 WHERE line_account_id IS NULL");
} catch (Exception $e) {}

// Get current settings
$notifySettings = [];
$accountId = (int)($currentBotId ?: 0);
try {
    $stmt = $db->prepare("SELECT * FROM notification_settings WHERE line_account_id = ?");
    $stmt->execute([$accountId]);
    $notifySettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Get admin users
$adminUsers = [];
try {
    $stmt = $db->query("SELECT id, username, email, line_user_id, role FROM admin_users WHERE is_active = 1 ORDER BY role, username");
    $adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Default values
$lineNotifyEnabled = $notifySettings['line_notify_enabled'] ?? 1;
$lineNotifyNewOrder = $notifySettings['line_notify_new_order'] ?? 1;
$lineNotifyPayment = $notifySettings['line_notify_payment'] ?? 1;
$lineNotifyUrgent = $notifySettings['line_notify_urgent'] ?? 1;
$lineNotifyAppointment = $notifySettings['line_notify_appointment'] ?? 1;
$lineNotifyLowStock = $notifySettings['line_notify_low_stock'] ?? 0;
$emailEnabled = $notifySettings['email_enabled'] ?? 0;
$emailAddresses = $notifySettings['email_addresses'] ?? '';
$emailNotifyUrgent = $notifySettings['email_notify_urgent'] ?? 1;
$emailNotifyDailyReport = $notifySettings['email_notify_daily_report'] ?? 0;
$emailNotifyLowStock = $notifySettings['email_notify_low_stock'] ?? 0;
$telegramEnabled = $notifySettings['telegram_enabled'] ?? 0;
$odooLiffNotifyEnabled = $notifySettings['odoo_liff_notify_enabled'] ?? 1;
$odooLiffNotifyEventsRaw = $notifySettings['odoo_liff_notify_events'] ?? '';
$odooLiffNotifyEvents = array_filter(array_map('trim', explode(',', $odooLiffNotifyEventsRaw)));
if (empty($odooLiffNotifyEvents)) {
    $odooLiffNotifyEvents = ['order.validated', 'order.awaiting_payment', 'order.paid', 'order.in_delivery', 'order.delivered'];
}
$notifyAdminUsersRaw = $notifySettings['notify_admin_users'] ?? '';
$notifyAdminUsers = array_filter(array_map('intval', explode(',', $notifyAdminUsersRaw)));

$odooEventOptions = [
    'order.validated' => 'ยืนยันออเดอร์',
    'order.awaiting_payment' => 'รอชำระเงิน',
    'order.paid' => 'ชำระเงินแล้ว',
    'order.to_delivery' => 'เตรียมส่ง',
    'order.in_delivery' => 'กำลังจัดส่ง',
    'order.delivered' => 'จัดส่งสำเร็จ',
    'invoice.created' => 'ออกใบแจ้งหนี้',
    'invoice.overdue' => 'ใบแจ้งหนี้เกินกำหนด',
];
?>

<style>
.notify-setting-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; transition: all 0.3s; }
.notify-setting-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
.notify-toggle-switch { position: relative; width: 52px; height: 28px; }
.notify-toggle-switch input { opacity: 0; width: 0; height: 0; }
.notify-toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #e2e8f0; border-radius: 28px; transition: 0.3s; }
.notify-toggle-slider:before { position: absolute; content: ""; height: 22px; width: 22px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.notify-toggle-switch input:checked + .notify-toggle-slider { background: linear-gradient(135deg, #10b981, #059669); }
.notify-toggle-switch input:checked + .notify-toggle-slider:before { transform: translateX(24px); }
.notify-channel-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
.notify-input-field { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: all 0.2s; }
.notify-input-field:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
.notify-item { display: flex; align-items: center; justify-content: between; padding: 12px 16px; background: #f8fafc; border-radius: 10px; margin-bottom: 8px; }
</style>

<div class="max-w-5xl mx-auto">
    <div class="mb-8">
        <h2 class="text-xl font-bold text-gray-800">
            <i class="fas fa-bell text-yellow-500 mr-2"></i>ตั้งค่าการแจ้งเตือน
        </h2>
        <p class="text-gray-500 mt-1">จัดการช่องทางและประเภทการแจ้งเตือนทั้งหมด</p>
    </div>

    <form method="POST">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <!-- LINE Notifications -->
                <div class="notify-setting-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <div class="notify-channel-icon bg-green-100">
                                <i class="fab fa-line text-green-500 text-xl"></i>
                            </div>
                            LINE Notification
                        </h3>
                        <label class="notify-toggle-switch">
                            <input type="checkbox" name="line_notify_enabled" <?= $lineNotifyEnabled ? 'checked' : '' ?>>
                            <span class="notify-toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="space-y-2">
                        <label class="notify-item cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="line_notify_new_order" class="mr-3 w-4 h-4 text-green-600" <?= $lineNotifyNewOrder ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium">🛒 ออเดอร์ใหม่</p>
                                <p class="text-sm text-gray-500">แจ้งเตือนเมื่อมีคำสั่งซื้อใหม่</p>
                            </div>
                        </label>
                        
                        <label class="notify-item cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="line_notify_payment" class="mr-3 w-4 h-4 text-green-600" <?= $lineNotifyPayment ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium">💳 การชำระเงิน</p>
                                <p class="text-sm text-gray-500">แจ้งเตือนเมื่อมีการแนบสลิป/ชำระเงิน</p>
                            </div>
                        </label>
                        
                        <label class="notify-item cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="line_notify_urgent" class="mr-3 w-4 h-4 text-green-600" <?= $lineNotifyUrgent ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium">🚨 เคสฉุกเฉิน (Red Flag)</p>
                                <p class="text-sm text-gray-500">แจ้งเตือนเมื่อพบอาการฉุกเฉิน</p>
                            </div>
                        </label>
                        
                        <label class="notify-item cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="line_notify_appointment" class="mr-3 w-4 h-4 text-green-600" <?= $lineNotifyAppointment ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium">📅 นัดหมายใหม่</p>
                                <p class="text-sm text-gray-500">แจ้งเตือนเมื่อมีการจองนัดหมาย</p>
                            </div>
                        </label>
                        
                        <label class="notify-item cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="line_notify_low_stock" class="mr-3 w-4 h-4 text-green-600" <?= $lineNotifyLowStock ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium">📦 สินค้าใกล้หมด</p>
                                <p class="text-sm text-gray-500">แจ้งเตือนเมื่อสต็อกต่ำกว่าที่กำหนด</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Email Notifications -->
                <div class="notify-setting-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <div class="notify-channel-icon bg-blue-100">
                                <i class="fas fa-envelope text-blue-500 text-xl"></i>
                            </div>
                            Email Notification
                        </h3>
                        <label class="notify-toggle-switch">
                            <input type="checkbox" name="email_enabled" <?= $emailEnabled ? 'checked' : '' ?>>
                            <span class="notify-toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-600 mb-2">Email ผู้รับแจ้งเตือน</label>
                        <textarea name="email_addresses" rows="2" class="notify-input-field" placeholder="email1@example.com&#10;email2@example.com"><?= htmlspecialchars($emailAddresses) ?></textarea>
                        <p class="text-xs text-gray-400 mt-1">ใส่ Email หลายรายการได้ (บรรทัดละ 1 Email)</p>
                    </div>
                    
                    <div class="space-y-2">
                        <label class="notify-item cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="email_notify_urgent" class="mr-3 w-4 h-4 text-blue-600" <?= $emailNotifyUrgent ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium">🚨 เคสฉุกเฉิน (Red Flag)</p>
                                <p class="text-sm text-gray-500">ส่ง Email เมื่อพบอาการฉุกเฉิน</p>
                            </div>
                        </label>
                        
                        <label class="notify-item cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="email_notify_daily_report" class="mr-3 w-4 h-4 text-blue-600" <?= $emailNotifyDailyReport ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium">📊 รายงานประจำวัน</p>
                                <p class="text-sm text-gray-500">ส่งสรุปยอดขายและกิจกรรมทุกวัน</p>
                            </div>
                        </label>
                        
                        <label class="notify-item cursor-pointer hover:bg-gray-100">
                            <input type="checkbox" name="email_notify_low_stock" class="mr-3 w-4 h-4 text-blue-600" <?= $emailNotifyLowStock ? 'checked' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium">📦 สินค้าใกล้หมด</p>
                                <p class="text-sm text-gray-500">ส่ง Email เมื่อสต็อกต่ำกว่าที่กำหนด</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Telegram Notifications -->
                <div class="notify-setting-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <div class="notify-channel-icon bg-sky-100">
                                <i class="fab fa-telegram text-sky-500 text-xl"></i>
                            </div>
                            Telegram Notification
                        </h3>
                        <label class="notify-toggle-switch">
                            <input type="checkbox" name="telegram_enabled" <?= $telegramEnabled ? 'checked' : '' ?>>
                            <span class="notify-toggle-slider"></span>
                        </label>
                    </div>
                    
                    <p class="text-sm text-gray-500 mb-4">ตั้งค่า Telegram Bot ได้ที่แท็บ "Telegram"</p>
                </div>

                <?php if (defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED === true): ?>
                <!-- Odoo LIFF Notifications -->
                <div class="notify-setting-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <div class="notify-channel-icon bg-emerald-100">
                                <i class="fas fa-paper-plane text-emerald-600 text-xl"></i>
                            </div>
                            Odoo → LIFF Notification
                        </h3>
                        <label class="notify-toggle-switch">
                            <input type="checkbox" name="odoo_liff_notify_enabled" <?= $odooLiffNotifyEnabled ? 'checked' : '' ?>>
                            <span class="notify-toggle-slider"></span>
                        </label>
                    </div>

                    <p class="text-sm text-gray-500 mb-4">เลือกสถานะที่ต้องการส่งแจ้งเตือนไปยังผู้ใช้ LIFF</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        <?php foreach ($odooEventOptions as $eventCode => $eventLabel): ?>
                            <label class="notify-item cursor-pointer hover:bg-gray-100">
                                <input type="checkbox" name="odoo_liff_notify_events[]" value="<?= htmlspecialchars($eventCode) ?>" class="mr-3 w-4 h-4 text-emerald-600"
                                    <?= in_array($eventCode, $odooLiffNotifyEvents, true) ? 'checked' : '' ?>>
                                <div class="flex-1">
                                    <p class="font-medium"><?= htmlspecialchars($eventLabel) ?></p>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars($eventCode) ?></p>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Odoo Notification Preferences (NEW) -->
                <div class="notify-setting-card p-6 border-2 border-emerald-200">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <div class="notify-channel-icon bg-gradient-to-br from-emerald-500 to-teal-500">
                            <i class="fas fa-cog text-white text-xl"></i>
                        </div>
                        การตั้งค่าการแจ้งเตือน Odoo (ขั้นสูง)
                        <span class="ml-auto text-xs bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full">NEW</span>
                    </h3>

                    <div class="bg-gradient-to-r from-emerald-50 to-teal-50 p-4 rounded-lg mb-4">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-info-circle text-emerald-600 mt-1"></i>
                            <div class="text-sm text-gray-700">
                                <p class="font-semibold mb-1">🎯 Roadmap Batching</p>
                                <p>ระบบจะรวมการแจ้งเตือนหลายสถานะเป็น timeline เดียว เมื่อถึงสถานะ <strong>order.packed (แพ็คเสร็จ)</strong></p>
                                <p class="mt-2 text-xs text-gray-600">
                                    ตัวอย่าง: picker_assigned → picking → picked → packing → <strong>packed</strong> = ส่ง 1 ข้อความแทน 5 ข้อความ
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="notify-item">
                            <div class="flex-1">
                                <p class="font-medium text-gray-800">📊 สถานะระบบ</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    <a href="/tests/check-system.php" target="_blank" class="text-emerald-600 hover:underline">
                                        <i class="fas fa-external-link-alt mr-1"></i>ตรวจสอบสถานะระบบ
                                    </a>
                                </p>
                            </div>
                        </div>

                        <div class="notify-item">
                            <div class="flex-1">
                                <p class="font-medium text-gray-800">🔧 Worker Status</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    <a href="/api/notification-queue-status.php" target="_blank" class="text-emerald-600 hover:underline">
                                        <i class="fas fa-external-link-alt mr-1"></i>ดูสถานะ Queue & Worker
                                    </a>
                                </p>
                            </div>
                        </div>

                        <div class="notify-item">
                            <div class="flex-1">
                                <p class="font-medium text-gray-800">📝 Notification Logs</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    ดูประวัติการส่งแจ้งเตือนทั้งหมดใน database: <code class="text-xs bg-gray-100 px-2 py-1 rounded">odoo_notification_log</code>
                                </p>
                            </div>
                        </div>

                        <div class="notify-item">
                            <div class="flex-1">
                                <p class="font-medium text-gray-800">⚙️ User Preferences</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    ผู้ใช้สามารถตั้งค่าการแจ้งเตือนส่วนตัวได้ที่ LIFF Notification Settings
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <p class="text-sm text-yellow-800">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>หมายเหตุ:</strong> ระบบ Roadmap Batching ทำงานอัตโนมัติผ่าน NotificationRouter และ Worker
                        </p>
                    </div>
                </div>

                <!-- Odoo Notification Test -->
                <div class="notify-setting-card p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <div class="notify-channel-icon bg-indigo-100">
                            <i class="fas fa-vial text-indigo-600 text-xl"></i>
                        </div>
                        ทดสอบส่งแจ้งเตือน Odoo → LIFF
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-2">LINE User ID ปลายทาง</label>
                            <input type="text" name="test_line_user_id" class="notify-input-field" placeholder="Uxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-2">สถานะที่ต้องการทดสอบ</label>
                            <select name="test_odoo_event" class="notify-input-field">
                                <?php foreach ($odooEventOptions as $eventCode => $eventLabel): ?>
                                    <option value="<?= htmlspecialchars($eventCode) ?>"><?= htmlspecialchars($eventLabel) ?> (<?= htmlspecialchars($eventCode) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-2">เลขที่ออเดอร์ (ตัวอย่าง)</label>
                            <input type="text" name="test_order_ref" class="notify-input-field" placeholder="SO-TEST-001">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-2">ชื่อผู้รับ (ตัวอย่าง)</label>
                            <input type="text" name="test_customer_name" class="notify-input-field" placeholder="ลูกค้าทดสอบ">
                        </div>
                    </div>

                    <button type="submit" name="action" value="test_odoo_liff_notification" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                        <i class="fas fa-paper-plane mr-2"></i>ส่งข้อความทดสอบ
                    </button>
                </div>
                <?php endif; /* ODOO_INTEGRATION_ENABLED */ ?>

                <!-- Notification Recipients -->
                <div class="notify-setting-card p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <div class="notify-channel-icon bg-purple-100">
                            <i class="fas fa-users text-purple-500 text-xl"></i>
                        </div>
                        ผู้รับแจ้งเตือน LINE
                    </h3>
                    
                    <p class="text-sm text-gray-500 mb-4">เลือกผู้ใช้ที่จะได้รับแจ้งเตือนผ่าน LINE (ต้องมี LINE User ID)</p>
                    
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <?php foreach ($adminUsers as $user): ?>
                        <?php $hasLineId = !empty($user['line_user_id']); ?>
                        <label class="notify-item cursor-pointer hover:bg-gray-100 <?= !$hasLineId ? 'opacity-50' : '' ?>">
                            <input type="checkbox" name="notify_admin_users[]" value="<?= $user['id'] ?>" 
                                   class="mr-3 w-4 h-4 text-purple-600" 
                                   <?= in_array($user['id'], $notifyAdminUsers) ? 'checked' : '' ?>
                                   <?= !$hasLineId ? 'disabled' : '' ?>>
                            <div class="flex-1">
                                <p class="font-medium"><?= htmlspecialchars($user['username']) ?></p>
                                <p class="text-xs text-gray-500">
                                    <?= htmlspecialchars($user['role']) ?>
                                    <?php if ($user['email']): ?>
                                    • <?= htmlspecialchars($user['email']) ?>
                                    <?php endif; ?>
                                    <?php if (!$hasLineId): ?>
                                    <span class="text-red-500">• ไม่มี LINE User ID</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        
                        <?php if (empty($adminUsers)): ?>
                        <p class="text-gray-400 text-center py-4">ไม่พบผู้ใช้งาน</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Save Button -->
                <div class="notify-setting-card p-6">
                    <button type="submit" name="action" value="save_notifications" class="w-full py-3 bg-gradient-to-r from-emerald-500 to-teal-500 text-white rounded-xl font-semibold hover:opacity-90 transition-all">
                        <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
