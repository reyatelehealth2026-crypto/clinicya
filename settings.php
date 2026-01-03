<?php
/**
 * Settings - Consolidated Settings Page
 * รวมหน้าตั้งค่าทั้งหมดเป็นหน้าเดียวแบบ Tab-based
 * 
 * Tabs: LINE, Telegram, Email, Notifications, Consent, Quick Access
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth_check.php';
require_once 'includes/components/tabs.php';
require_once 'classes/ActivityLogger.php';

$db = Database::getInstance()->getConnection();
$activityLogger = ActivityLogger::getInstance($db);

// Tab configuration
$tabs = [
    'line' => ['label' => 'LINE Accounts', 'icon' => 'fab fa-line'],
    'telegram' => ['label' => 'Telegram', 'icon' => 'fab fa-telegram'],
    'email' => ['label' => 'Email/SMTP', 'icon' => 'fas fa-envelope'],
    'notifications' => ['label' => 'การแจ้งเตือน', 'icon' => 'fas fa-bell'],
    'consent' => ['label' => 'Consent', 'icon' => 'fas fa-shield-alt'],
    'quick-access' => ['label' => 'Quick Access', 'icon' => 'fas fa-bolt'],
];

$activeTab = getActiveTab($tabs, 'line');
$pageTitle = 'ตั้งค่าระบบ';

// Load required classes for LINE tab
if ($activeTab === 'line') {
    require_once 'classes/LineAPI.php';
    require_once 'classes/LineAccountManager.php';
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'test_line_connection') {
            require_once 'classes/LineAPI.php';
            require_once 'classes/LineAccountManager.php';
            $manager = new LineAccountManager($db);
            $result = $manager->testConnection($_POST['id']);
            echo json_encode($result);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle form submissions
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // LINE Account actions
    if ($action === 'create_line') {
        require_once 'classes/LineAccountManager.php';
        $manager = new LineAccountManager($db);
        $manager->createAccount([
            'name' => $_POST['name'],
            'channel_id' => $_POST['channel_id'],
            'channel_secret' => $_POST['channel_secret'],
            'channel_access_token' => $_POST['channel_access_token'],
            'basic_id' => $_POST['basic_id'] ?? '',
            'liff_id' => $_POST['liff_id'] ?? null,
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
            'bot_mode' => $_POST['bot_mode'] ?? 'shop',
            'welcome_message' => $_POST['welcome_message'] ?? '',
            'auto_reply_enabled' => isset($_POST['auto_reply_enabled']) ? 1 : 0,
            'shop_enabled' => isset($_POST['shop_enabled']) ? 1 : 0,
        ]);
        header('Location: settings.php?tab=line&success=created');
        exit;
    } elseif ($action === 'update_line') {
        require_once 'classes/LineAccountManager.php';
        $manager = new LineAccountManager($db);
        $manager->updateAccount($_POST['id'], [
            'name' => $_POST['name'],
            'channel_id' => $_POST['channel_id'],
            'channel_secret' => $_POST['channel_secret'],
            'channel_access_token' => $_POST['channel_access_token'],
            'basic_id' => $_POST['basic_id'] ?? '',
            'liff_id' => $_POST['liff_id'] ?? null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
            'bot_mode' => $_POST['bot_mode'] ?? 'shop',
            'welcome_message' => $_POST['welcome_message'] ?? '',
            'auto_reply_enabled' => isset($_POST['auto_reply_enabled']) ? 1 : 0,
            'shop_enabled' => isset($_POST['shop_enabled']) ? 1 : 0,
        ]);
        header('Location: settings.php?tab=line&success=updated');
        exit;
    } elseif ($action === 'delete_line') {
        require_once 'classes/LineAccountManager.php';
        $manager = new LineAccountManager($db);
        $manager->deleteAccount($_POST['id']);
        header('Location: settings.php?tab=line&success=deleted');
        exit;
    } elseif ($action === 'set_default_line') {
        require_once 'classes/LineAccountManager.php';
        $manager = new LineAccountManager($db);
        $manager->setDefault($_POST['id']);
        header('Location: settings.php?tab=line&success=default');
        exit;
    }

    // Telegram actions
    elseif ($action === 'save_telegram_token') {
        $botToken = trim($_POST['bot_token'] ?? '');
        $chatId = trim($_POST['chat_id'] ?? '');
        $stmt = $db->prepare("UPDATE telegram_settings SET bot_token = ?, chat_id = ? WHERE id = 1");
        $stmt->execute([$botToken, $chatId]);
        $success = 'บันทึก Token และ Chat ID สำเร็จ!';
        $activeTab = 'telegram';
    } elseif ($action === 'save_telegram_notifications') {
        $stmt = $db->prepare("UPDATE telegram_settings SET 
            is_enabled = ?, notify_new_follower = ?, notify_new_message = ?, 
            notify_unfollow = ?, notify_new_order = ?, notify_payment = ? WHERE id = 1");
        $stmt->execute([
            isset($_POST['is_enabled']) ? 1 : 0,
            isset($_POST['notify_new_follower']) ? 1 : 0,
            isset($_POST['notify_new_message']) ? 1 : 0,
            isset($_POST['notify_unfollow']) ? 1 : 0,
            isset($_POST['notify_new_order']) ? 1 : 0,
            isset($_POST['notify_payment']) ? 1 : 0
        ]);
        $success = 'บันทึกการตั้งค่าการแจ้งเตือนสำเร็จ!';
        $activeTab = 'telegram';
    } elseif ($action === 'test_telegram') {
        $stmt = $db->query("SELECT bot_token, chat_id FROM telegram_settings WHERE id = 1");
        $tokenSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        $botToken = $tokenSettings['bot_token'] ?? '';
        $chatId = $tokenSettings['chat_id'] ?? '';
        
        if (empty($botToken) || empty($chatId)) {
            $error = 'กรุณาตั้งค่า Bot Token และ Chat ID ก่อน';
        } else {
            $testMessage = "🔔 <b>ทดสอบการแจ้งเตือน</b>\n\nระบบ LINE OA Manager ทำงานปกติ\n📅 " . date('Y-m-d H:i:s');
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => $testMessage, 'parse_mode' => 'HTML'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true) ?? ['ok' => false];
            
            if ($result['ok'] ?? false) {
                $success = 'ส่งข้อความทดสอบสำเร็จ!';
            } else {
                $error = 'ส่งข้อความไม่สำเร็จ: ' . ($result['description'] ?? 'Unknown error');
            }
        }
        $activeTab = 'telegram';
    }
    
    // Get Telegram Chat ID
    elseif ($action === 'get_telegram_chat_id') {
        $botToken = trim($_POST['bot_token'] ?? '');
        if (empty($botToken)) {
            $error = 'กรุณาใส่ Bot Token ก่อน';
        } else {
            $url = "https://api.telegram.org/bot{$botToken}/getUpdates";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true);
            
            if (($result['ok'] ?? false) && !empty($result['result'])) {
                $lastUpdate = end($result['result']);
                $chatId = $lastUpdate['message']['chat']['id'] ?? null;
                if ($chatId) {
                    // Save chat_id
                    $stmt = $db->prepare("UPDATE telegram_settings SET bot_token = ?, chat_id = ? WHERE id = 1");
                    $stmt->execute([$botToken, $chatId]);
                    $success = "พบ Chat ID: {$chatId} และบันทึกแล้ว!";
                } else {
                    $error = 'ไม่พบ Chat ID กรุณาส่งข้อความหา Bot ก่อน';
                }
            } else {
                $error = 'ไม่พบข้อมูล กรุณาส่งข้อความหา Bot ก่อน หรือตรวจสอบ Token';
            }
        }
        $activeTab = 'telegram';
    }
    
    // Set Telegram Webhook
    elseif ($action === 'set_telegram_webhook') {
        $stmt = $db->query("SELECT bot_token FROM telegram_settings WHERE id = 1");
        $tokenSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        $botToken = $tokenSettings['bot_token'] ?? '';
        
        if (empty($botToken)) {
            $error = 'กรุณาตั้งค่า Bot Token ก่อน';
        } else {
            $webhookUrl = rtrim(BASE_URL, '/') . '/telegram_webhook.php';
            $url = "https://api.telegram.org/bot{$botToken}/setWebhook";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['url' => $webhookUrl],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true);
            
            if ($result['ok'] ?? false) {
                $success = "ตั้งค่า Webhook สำเร็จ: {$webhookUrl}";
            } else {
                $error = 'ตั้งค่า Webhook ไม่สำเร็จ: ' . ($result['description'] ?? 'Unknown error');
            }
        }
        $activeTab = 'telegram';
    }

    // Email actions
    elseif ($action === 'save_email') {
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
            $success = 'บันทึกการตั้งค่า Email สำเร็จ';
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
        $activeTab = 'email';
    } elseif ($action === 'test_email') {
        $testEmail = $_POST['test_email'] ?? '';
        if ($testEmail && filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            require_once 'classes/EmailService.php';
            $emailService = new EmailService($db);
            if ($emailService->sendTest($testEmail)) {
                $success = 'ส่ง Email ทดสอบสำเร็จไปยัง ' . $testEmail;
            } else {
                $error = 'ส่ง Email ไม่สำเร็จ - ตรวจสอบการตั้งค่า SMTP';
            }
        } else {
            $error = 'กรุณาระบุ Email ที่ถูกต้อง';
        }
        $activeTab = 'email';
    }

    // Notification settings actions
    elseif ($action === 'save_notifications') {
        try {
            $currentBotId = $_SESSION['current_bot_id'] ?? null;
            $accountId = (int)($currentBotId ?: 0);
            $emailAddresses = trim($_POST['email_addresses'] ?? '');
            $notifyAdminUsers = isset($_POST['notify_admin_users']) ? implode(',', $_POST['notify_admin_users']) : '';
            
            $data = [
                $accountId,
                isset($_POST['line_notify_enabled']) ? 1 : 0,
                isset($_POST['line_notify_new_order']) ? 1 : 0,
                isset($_POST['line_notify_payment']) ? 1 : 0,
                isset($_POST['line_notify_urgent']) ? 1 : 0,
                isset($_POST['line_notify_appointment']) ? 1 : 0,
                isset($_POST['line_notify_low_stock']) ? 1 : 0,
                isset($_POST['email_enabled']) ? 1 : 0,
                $emailAddresses,
                isset($_POST['email_notify_urgent']) ? 1 : 0,
                isset($_POST['email_notify_daily_report']) ? 1 : 0,
                isset($_POST['email_notify_low_stock']) ? 1 : 0,
                isset($_POST['telegram_enabled']) ? 1 : 0,
                $notifyAdminUsers
            ];
            
            $sql = "INSERT INTO notification_settings 
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
                notify_admin_users = VALUES(notify_admin_users)";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($data);
            $success = 'บันทึกการตั้งค่าการแจ้งเตือนสำเร็จ';
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
        $activeTab = 'notifications';
    }
}

require_once 'includes/header.php';
echo getTabsStyles();
?>

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

<!-- Tab Navigation -->
<?= renderTabs($tabs, $activeTab) ?>

<!-- Tab Content -->
<div class="tab-content">
    <div class="tab-panel">
        <?php
        switch ($activeTab) {
            case 'telegram':
                include 'includes/settings/telegram.php';
                break;
            case 'email':
                include 'includes/settings/email.php';
                break;
            case 'notifications':
                include 'includes/settings/notifications.php';
                break;
            case 'consent':
                include 'includes/settings/consent.php';
                break;
            case 'quick-access':
                include 'includes/settings/quick-access.php';
                break;
            default:
                include 'includes/settings/line.php';
        }
        ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
