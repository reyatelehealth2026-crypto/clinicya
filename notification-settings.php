<?php
/**
 * Notification Settings - ตั้งค่าการแจ้งเตือนรวมศูนย์
 * รวม: LINE, Email, Telegram
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่าการแจ้งเตือน';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Ensure notification_settings table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS notification_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        line_account_id INT DEFAULT NULL,
        -- LINE Notifications
        line_notify_enabled TINYINT(1) DEFAULT 1,
        line_notify_new_order TINYINT(1) DEFAULT 1,
        line_notify_payment TINYINT(1) DEFAULT 1,
        line_notify_urgent TINYINT(1) DEFAULT 1,
        line_notify_appointment TINYINT(1) DEFAULT 1,
        line_notify_low_stock TINYINT(1) DEFAULT 0,
        -- Email Notifications
        email_enabled TINYINT(1) DEFAULT 0,
        email_addresses TEXT DEFAULT NULL,
        email_notify_urgent TINYINT(1) DEFAULT 1,
        email_notify_daily_report TINYINT(1) DEFAULT 0,
        email_notify_low_stock TINYINT(1) DEFAULT 0,
        -- Telegram (link to telegram_settings)
        telegram_enabled TINYINT(1) DEFAULT 0,
        -- Notification Recipients
        notify_admin_users TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_account (line_account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Get current settings
$settings = [];
try {
    if ($currentBotId) {
        $stmt = $db->prepare("SELECT * FROM notification_settings WHERE line_account_id = ?");
        $stmt->execute([$currentBotId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Exception $e) {}

// Get Telegram settings
$telegramSettings = [];
try {
    $stmt = $db->query("SELECT * FROM telegram_settings WHERE id = 1");
    $telegramSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Get admin users for notification recipients
$adminUsers = [];
try {
    $stmt = $db->query("SELECT id, username, email, line_user_id, role FROM admin_users WHERE is_active = 1 ORDER BY role, username");
    $adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle POST
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        try {
            $emailAddresses = trim($_POST['email_addresses'] ?? '');
            $notifyAdminUsers = isset($_POST['notify_admin_users']) ? implode(',', $_POST['notify_admin_users']) : '';
            
            $data = [
                'line_account_id' => $currentBotId,
                'line_notify_enabled' => isset($_POST['line_notify_enabled']) ? 1 : 0,
                'line_notify_new_order' => isset($_POST['line_notify_new_order']) ? 1 : 0,
                'line_notify_payment' => isset($_POST['line_notify_payment']) ? 1 : 0,
                'line_notify_urgent' => isset($_POST['line_notify_urgent']) ? 1 : 0,
                'line_notify_appointment' => isset($_POST['line_notify_appointment']) ? 1 : 0,
                'line_notify_low_stock' => isset($_POST['line_notify_low_stock']) ? 1 : 0,
                'email_enabled' => isset($_POST['email_enabled']) ? 1 : 0,
                'email_addresses' => $emailAddresses,
                'email_notify_urgent' => isset($_POST['email_notify_urgent']) ? 1 : 0,
                'email_notify_daily_report' => isset($_POST['email_notify_daily_report']) ? 1 : 0,
                'email_notify_low_stock' => isset($_POST['email_notify_low_stock']) ? 1 : 0,
                'telegram_enabled' => isset($_POST['telegram_enabled']) ? 1 : 0,
                'notify_admin_users' => $notifyAdminUsers
            ];
            
            $stmt = $db->prepare("INSERT INTO notification_settings 
                (line_account_id, line_notify_enabled, line_notify_new_order, line_notify_payment, 
                 line_notify_urgent, line_notify_appointment, line_notify_low_stock,
                 email_enabled, email_addresses, email_notify_urgent, email_notify_daily_report, email_notify_low_stock,
                 telegram_enabled, notify_admin_users)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                line_notify_enabled = VALUES(line_notify_enabled),
                line_notify_new_order = VALUES(line_notify_new_order),
                line_notify_payment = VALUES(line_notify_payment),
                line_notify_urgent = VALUES(line_notify_urgent),
                line_notify_appointment = VALUES(line_notify_appointment),
                line_notify_low_stock = VALUES(line_notify_low_stock),
                email_enabled = VALUES(email_enabled),
                email_addresses = VALUES(email_addresses),
                email_notify_urgent = VALUES(email_notify_urgent),
                email_notify_daily_report = VALUES(email_notify_daily_report),
                email_notify_low_stock = VALUES(email_notify_low_stock),
                telegram_enabled = VALUES(telegram_enabled),
                notify_admin_users = VALUES(notify_admin_users)");
            
            $stmt->execute(array_values($data));
            $success = 'บันทึกการตั้งค่าสำเร็จ';
            
            // Reload settings
            $stmt = $db->prepare("SELECT * FROM notification_settings WHERE line_account_id = ?");
            $stmt->execute([$currentBotId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    } elseif ($action === 'test_line') {
        // Test LINE notification
        $testResult = testLineNotification($db, $currentBotId);
        if ($testResult) {
            $success = 'ส่งทดสอบ LINE สำเร็จ';
        } else {
            $error = 'ส่งทดสอบ LINE ไม่สำเร็จ - ตรวจสอบ LINE User ID ของผู้รับ';
        }
    } elseif ($action === 'test_email') {
        $testEmail = $_POST['test_email'] ?? '';
        if ($testEmail && filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $testResult = testEmailNotification($testEmail);
            if ($testResult) {
                $success = 'ส่งทดสอบ Email สำเร็จไปยัง ' . $testEmail;
            } else {
                $error = 'ส่งทดสอบ Email ไม่สำเร็จ';
            }
        } else {
            $error = 'กรุณาระบุ Email ที่ถูกต้อง';
        }
    }
}

// Helper functions
function testLineNotification($db, $lineAccountId) {
    try {
        // Get channel access token
        $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account || empty($account['channel_access_token'])) {
            return false;
        }
        
        // Get admin with LINE user ID
        $stmt = $db->query("SELECT line_user_id FROM admin_users WHERE line_user_id IS NOT NULL AND line_user_id != '' AND is_active = 1 LIMIT 1");
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin || empty($admin['line_user_id'])) {
            return false;
        }
        
        // Send test message
        $message = [
            'type' => 'text',
            'text' => "🔔 ทดสอบการแจ้งเตือน\n\nระบบแจ้งเตือนทำงานปกติ\n📅 " . date('Y-m-d H:i:s')
        ];
        
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $account['channel_access_token']
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'to' => $admin['line_user_id'],
                'messages' => [$message]
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $httpCode === 200;
    } catch (Exception $e) {
        return false;
    }
}

function testEmailNotification($email) {
    $subject = "🔔 ทดสอบการแจ้งเตือน Email";
    $body = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='font-family: Sarabun, Arial, sans-serif; padding: 20px;'>
    <div style='max-width: 500px; margin: 0 auto; background: #f8fafc; padding: 30px; border-radius: 12px;'>
        <h2 style='color: #059669; margin-bottom: 20px;'>✅ ทดสอบการแจ้งเตือน</h2>
        <p>ระบบแจ้งเตือน Email ทำงานปกติ</p>
        <p style='color: #6b7280; font-size: 14px;'>📅 " . date('Y-m-d H:i:s') . "</p>
    </div>
</body>
</html>";
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Notification System <noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>'
    ];
    
    return mail($email, $subject, $body, implode("\r\n", $headers));
}

// Default values
$lineNotifyEnabled = $settings['line_notify_enabled'] ?? 1;
$lineNotifyNewOrder = $settings['line_notify_new_order'] ?? 1;
$lineNotifyPayment = $settings['line_notify_payment'] ?? 1;
$lineNotifyUrgent = $settings['line_notify_urgent'] ?? 1;
$lineNotifyAppointment = $settings['line_notify_appointment'] ?? 1;
$lineNotifyLowStock = $settings['line_notify_low_stock'] ?? 0;
$emailEnabled = $settings['email_enabled'] ?? 0;
$emailAddresses = $settings['email_addresses'] ?? '';
$emailNotifyUrgent = $settings['email_notify_urgent'] ?? 1;
$emailNotifyDailyReport = $settings['email_notify_daily_report'] ?? 0;
$emailNotifyLowStock = $settings['email_notify_low_stock'] ?? 0;
$telegramEnabled = $settings['telegram_enabled'] ?? 0;
$notifyAdminUsers = explode(',', $settings['notify_admin_users'] ?? '');

require_once 'includes/header.php';
?>

<style>
.setting-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; transition: all 0.3s; }
.setting-card:hover { box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
.toggle-switch { position: relative; width: 52px; height: 28px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #e2e8f0; border-radius: 28px; transition: 0.3s; }
.toggle-slider:before { position: absolute; content: ""; height: 22px; width: 22px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.toggle-switch input:checked + .toggle-slider { background: linear-gradient(135deg, #10b981, #059669); }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(24px); }
.channel-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
.input-field { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; transition: all 0.2s; }
.input-field:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
.notify-item { display: flex; align-items: center; justify-content: between; padding: 12px 16px; background: #f8fafc; border-radius: 10px; margin-bottom: 8px; }
</style>

<div class="max-w-5xl mx-auto py-6 px-4">
    <?php if ($success): ?>
    <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl flex items-center gap-3">
        <i class="fas fa-check-circle text-xl"></i>
        <span><?= htmlspecialchars($success) ?></span>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center gap-3">
        <i class="fas fa-exclamation-circle text-xl"></i>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-bell text-yellow-500 mr-2"></i>ตั้งค่าการแจ้งเตือน
                </h1>
                <p class="text-gray-500 mt-1">จัดการช่องทางและประเภทการแจ้งเตือนทั้งหมด</p>
            </div>
        </div>
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Settings -->
            <div class="lg:col-span-2 space-y-6">

                <!-- LINE Notifications -->
                <div class="setting-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <div class="channel-icon bg-green-100">
                                <i class="fab fa-line text-green-500 text-xl"></i>
                            </div>
                            LINE Notification
                        </h3>
                        <label class="toggle-switch">
                            <input type="checkbox" name="line_notify_enabled" <?= $lineNotifyEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
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
                    
                    <div class="mt-4 pt-4 border-t">
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="test_line">
                            <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                                <i class="fas fa-paper-plane mr-2"></i>ทดสอบส่ง LINE
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Email Notifications -->
                <div class="setting-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <div class="channel-icon bg-blue-100">
                                <i class="fas fa-envelope text-blue-500 text-xl"></i>
                            </div>
                            Email Notification
                        </h3>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_enabled" <?= $emailEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-600 mb-2">Email ผู้รับแจ้งเตือน</label>
                        <textarea name="email_addresses" rows="2" class="input-field" placeholder="email1@example.com&#10;email2@example.com"><?= htmlspecialchars($emailAddresses) ?></textarea>
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
                    
                    <div class="mt-4 pt-4 border-t flex gap-2">
                        <input type="email" id="testEmail" placeholder="test@example.com" class="input-field flex-1">
                        <button type="button" onclick="testEmail()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 text-sm whitespace-nowrap">
                            <i class="fas fa-paper-plane mr-2"></i>ทดสอบ
                        </button>
                    </div>
                </div>

                <!-- Telegram Notifications -->
                <div class="setting-card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            <div class="channel-icon bg-sky-100">
                                <i class="fab fa-telegram text-sky-500 text-xl"></i>
                            </div>
                            Telegram Notification
                        </h3>
                        <label class="toggle-switch">
                            <input type="checkbox" name="telegram_enabled" <?= $telegramEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <?php if (empty($telegramSettings['bot_token'])): ?>
                    <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg mb-4">
                        <p class="text-yellow-700 text-sm">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            ยังไม่ได้ตั้งค่า Telegram Bot
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="p-4 bg-green-50 border border-green-200 rounded-lg mb-4">
                        <p class="text-green-700 text-sm">
                            <i class="fas fa-check-circle mr-2"></i>
                            Telegram Bot ตั้งค่าแล้ว
                            <?php if ($telegramSettings['is_enabled'] ?? false): ?>
                            <span class="ml-2 px-2 py-0.5 bg-green-200 rounded text-xs">เปิดใช้งาน</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <a href="telegram.php" class="inline-flex items-center px-4 py-2 bg-sky-500 text-white rounded-lg hover:bg-sky-600 text-sm">
                        <i class="fas fa-cog mr-2"></i>ตั้งค่า Telegram Bot
                    </a>
                </div>

                <!-- Notification Recipients -->
                <div class="setting-card p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                        <div class="channel-icon bg-purple-100">
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
                    
                    <div class="mt-4 pt-4 border-t">
                        <a href="admin-users.php" class="text-sm text-purple-600 hover:underline">
                            <i class="fas fa-user-plus mr-1"></i>จัดการผู้ใช้งาน / เพิ่ม LINE User ID
                        </a>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Save Button -->
                <div class="setting-card p-6">
                    <button type="submit" class="w-full py-3 bg-gradient-to-r from-emerald-500 to-teal-500 text-white rounded-xl font-semibold hover:opacity-90 transition-all">
                        <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
                    </button>
                </div>

                <!-- Quick Links -->
                <div class="setting-card p-6">
                    <h4 class="font-semibold text-gray-800 mb-4">
                        <i class="fas fa-link text-gray-400 mr-2"></i>ลิงก์ที่เกี่ยวข้อง
                    </h4>
                    <div class="space-y-2">
                        <a href="telegram.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-all">
                            <i class="fab fa-telegram text-sky-500"></i>
                            <span class="text-sm">ตั้งค่า Telegram Bot</span>
                        </a>
                        <a href="ai-pharmacy-settings.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-all">
                            <i class="fas fa-clinic-medical text-emerald-500"></i>
                            <span class="text-sm">ตั้งค่า AI เภสัช</span>
                        </a>
                        <a href="admin-users.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-all">
                            <i class="fas fa-users-cog text-purple-500"></i>
                            <span class="text-sm">จัดการผู้ใช้งาน</span>
                        </a>
                        <a href="scheduled-reports.php" class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-all">
                            <i class="fas fa-chart-bar text-blue-500"></i>
                            <span class="text-sm">รายงานอัตโนมัติ</span>
                        </a>
                    </div>
                </div>

                <!-- Notification Types Info -->
                <div class="setting-card p-6">
                    <h4 class="font-semibold text-gray-800 mb-4">
                        <i class="fas fa-info-circle text-blue-400 mr-2"></i>ประเภทการแจ้งเตือน
                    </h4>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-start gap-2">
                            <span class="text-lg">🛒</span>
                            <div>
                                <p class="font-medium text-gray-700">ออเดอร์ใหม่</p>
                                <p class="text-gray-500 text-xs">เมื่อลูกค้าสั่งซื้อสินค้า</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="text-lg">💳</span>
                            <div>
                                <p class="font-medium text-gray-700">การชำระเงิน</p>
                                <p class="text-gray-500 text-xs">เมื่อลูกค้าแนบสลิปโอนเงิน</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="text-lg">🚨</span>
                            <div>
                                <p class="font-medium text-gray-700">เคสฉุกเฉิน</p>
                                <p class="text-gray-500 text-xs">เมื่อ AI พบอาการ Red Flag</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="text-lg">📅</span>
                            <div>
                                <p class="font-medium text-gray-700">นัดหมาย</p>
                                <p class="text-gray-500 text-xs">เมื่อมีการจองนัดหมายใหม่</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <span class="text-lg">📦</span>
                            <div>
                                <p class="font-medium text-gray-700">สินค้าใกล้หมด</p>
                                <p class="text-gray-500 text-xs">เมื่อสต็อกต่ำกว่าที่กำหนด</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function testEmail() {
    const email = document.getElementById('testEmail').value;
    if (!email) {
        alert('กรุณาระบุ Email');
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="test_email">
        <input type="hidden" name="test_email" value="${email}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php require_once 'includes/footer.php'; ?>
