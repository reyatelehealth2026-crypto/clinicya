<?php
/**
 * Email Settings - ตั้งค่า SMTP สำหรับส่ง Email
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่า Email/SMTP';

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS email_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        smtp_host VARCHAR(255) DEFAULT NULL,
        smtp_port INT DEFAULT 587,
        smtp_user VARCHAR(255) DEFAULT NULL,
        smtp_pass VARCHAR(255) DEFAULT NULL,
        smtp_secure ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
        from_email VARCHAR(255) DEFAULT NULL,
        from_name VARCHAR(255) DEFAULT 'Notification',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Load settings
$settings = [];
try {
    $stmt = $db->query("SELECT * FROM email_settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$success = null;
$error = null;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        try {
            $data = [
                $_POST['smtp_host'] ?? '',
                (int)($_POST['smtp_port'] ?? 587),
                $_POST['smtp_user'] ?? '',
                $_POST['smtp_pass'] ?? '',
                $_POST['smtp_secure'] ?? 'tls',
                $_POST['from_email'] ?? '',
                $_POST['from_name'] ?? 'Notification'
            ];
            
            $stmt = $db->prepare("INSERT INTO email_settings (id, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, from_email, from_name)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port),
                smtp_user = VALUES(smtp_user), smtp_pass = VALUES(smtp_pass),
                smtp_secure = VALUES(smtp_secure), from_email = VALUES(from_email),
                from_name = VALUES(from_name)");
            $stmt->execute($data);
            
            $success = 'บันทึกการตั้งค่าสำเร็จ';
            
            // Reload
            $stmt = $db->query("SELECT * FROM email_settings WHERE id = 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    } elseif ($action === 'test') {
        $testEmail = $_POST['test_email'] ?? '';
        if ($testEmail && filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            require_once 'classes/EmailService.php';
            $emailService = new EmailService($db);
            if ($emailService->sendTest($testEmail)) {
                $success = 'ส่ง Email ทดสอบสำเร็จไปยัง ' . $testEmail;
            } else {
                $error = 'ส่ง Email ไม่สำเร็จ - ตรวจสอบการตั้งค่า SMTP หรือดู error_log';
            }
        } else {
            $error = 'กรุณาระบุ Email ที่ถูกต้อง';
        }
    }
}

require_once 'includes/header.php';
?>

<style>
.setting-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; }
.input-field { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
.input-field:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
</style>

<div class="max-w-3xl mx-auto py-6 px-4">
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

    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-800">
            <i class="fas fa-envelope text-blue-500 mr-2"></i>ตั้งค่า Email/SMTP
        </h1>
        <p class="text-gray-500 mt-1">ตั้งค่า SMTP server สำหรับส่ง Email แจ้งเตือน</p>
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="save">
        
        <div class="setting-card p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-server text-blue-500 mr-2"></i>SMTP Server
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">SMTP Host</label>
                    <input type="text" name="smtp_host" class="input-field" placeholder="smtp.gmail.com" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">SMTP Port</label>
                    <input type="number" name="smtp_port" class="input-field" placeholder="587" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">SMTP Username</label>
                    <input type="text" name="smtp_user" class="input-field" placeholder="your@email.com" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">SMTP Password</label>
                    <input type="password" name="smtp_pass" class="input-field" placeholder="••••••••" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">Security</label>
                    <select name="smtp_secure" class="input-field">
                        <option value="tls" <?= ($settings['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587)</option>
                        <option value="ssl" <?= ($settings['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                        <option value="none" <?= ($settings['smtp_secure'] ?? '') === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="setting-card p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-user text-green-500 mr-2"></i>ผู้ส่ง (From)
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">From Email</label>
                    <input type="email" name="from_email" class="input-field" placeholder="noreply@yourdomain.com" value="<?= htmlspecialchars($settings['from_email'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-2">From Name</label>
                    <input type="text" name="from_name" class="input-field" placeholder="Notification" value="<?= htmlspecialchars($settings['from_name'] ?? 'Notification') ?>">
                </div>
            </div>
        </div>
        
        <div class="flex gap-4">
            <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-xl font-semibold hover:opacity-90">
                <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
            </button>
        </div>
    </form>
    
    <!-- Test Email -->
    <div class="setting-card p-6 mt-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">
            <i class="fas fa-paper-plane text-purple-500 mr-2"></i>ทดสอบส่ง Email
        </h3>
        
        <form method="POST" class="flex gap-2">
            <input type="hidden" name="action" value="test">
            <input type="email" name="test_email" class="input-field flex-1" placeholder="test@example.com" required>
            <button type="submit" class="px-6 py-3 bg-purple-500 text-white rounded-xl font-semibold hover:bg-purple-600">
                <i class="fas fa-paper-plane mr-2"></i>ทดสอบ
            </button>
        </form>
    </div>
    
    <!-- Help -->
    <div class="setting-card p-6 mt-6 bg-blue-50 border-blue-200">
        <h4 class="font-semibold text-blue-800 mb-3">
            <i class="fas fa-info-circle mr-2"></i>วิธีตั้งค่า SMTP
        </h4>
        <div class="text-sm text-blue-700 space-y-2">
            <p><strong>Gmail:</strong> smtp.gmail.com, Port 587, TLS, ใช้ App Password</p>
            <p><strong>Outlook:</strong> smtp.office365.com, Port 587, TLS</p>
            <p><strong>Plesk:</strong> mail.yourdomain.com, Port 587, TLS</p>
            <p class="text-xs text-blue-600 mt-3">💡 ถ้าไม่ตั้งค่า SMTP ระบบจะใช้ PHP mail() ซึ่งอาจไม่ทำงานบางโฮสติ้ง</p>
        </div>
    </div>
    
    <div class="mt-6">
        <a href="notification-settings.php" class="text-blue-600 hover:underline">
            <i class="fas fa-arrow-left mr-1"></i>กลับไปหน้าตั้งค่าการแจ้งเตือน
        </a>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
