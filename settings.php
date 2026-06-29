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
require_once 'includes/components/form-section.php';
require_once 'includes/components/field.php';
require_once 'includes/components/toggle.php';
require_once 'includes/components/sticky-save-bar.php';
require_once 'includes/shop-data-source.php';
require_once 'classes/ActivityLogger.php';

$db = Database::getInstance()->getConnection();
$activityLogger = ActivityLogger::getInstance($db);
$currentBotId = $_SESSION['current_bot_id'] ?? 1;
$lineAccountId = $_SESSION['line_account_id'] ?? $_SESSION['current_bot_id'] ?? 1;
$success = null;
$error = null;

// Tab configuration
$tabs = [
    'line' => ['label' => 'LINE Accounts', 'icon' => 'fab fa-line'],
    'platform' => ['label' => 'การเชื่อมต่อแพลตฟอร์ม', 'icon' => 'fas fa-plug'],
    'general' => ['label' => 'ข้อมูลร้าน', 'icon' => 'fas fa-store'],
    'shop_tax' => ['label' => 'ข้อมูลร้าน / ใบกำกับภาษี', 'icon' => 'fas fa-file-invoice'],
    'welcome' => ['label' => 'ข้อความต้อนรับ', 'icon' => 'fas fa-hand-sparkles'],
    // 'liff' => ['label' => 'LIFF Settings', 'icon' => 'fas fa-mobile-alt'],
    // 'vibe-selling' => ['label' => 'Vibe Selling v2', 'icon' => 'fas fa-brain'],
    // 'telegram' => ['label' => 'Telegram', 'icon' => 'fab fa-telegram'],
    // 'email' => ['label' => 'Email/SMTP', 'icon' => 'fas fa-envelope'],
    'notifications' => ['label' => 'การแจ้งเตือน', 'icon' => 'fas fa-bell'],
    'consent' => ['label' => 'Consent', 'icon' => 'fas fa-shield-alt'],
    // 'quick-access' => ['label' => 'Quick Access', 'icon' => 'fas fa-bolt'],
];

$activeTab = getActiveTab($tabs, 'line');
$pageTitle = 'ตั้งค่าระบบ';

$isShopGeneralRequest = $activeTab === 'general'
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tab'] ?? '') === 'general');
$tableExists = false;
$hasAccountCol = false;

if ($isShopGeneralRequest) {
    try {
        $db->query("SELECT 1 FROM shop_settings LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS shop_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT DEFAULT NULL,
                shop_name VARCHAR(255) DEFAULT 'LINE Shop',
                shop_logo VARCHAR(500),
                welcome_message TEXT,
                shipping_fee DECIMAL(10,2) DEFAULT 50,
                free_shipping_min DECIMAL(10,2) DEFAULT 500,
                bank_accounts TEXT,
                promptpay_number VARCHAR(20),
                contact_phone VARCHAR(20),
                is_open TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $tableExists = true;
        } catch (Exception $e2) {
            $error = "ไม่สามารถสร้างตารางได้: " . $e2->getMessage();
        }
    }

    if ($tableExists) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM shop_settings LIKE 'line_account_id'");
            $hasAccountCol = $stmt->rowCount() > 0;

            if (!$hasAccountCol) {
                $db->exec("ALTER TABLE shop_settings ADD COLUMN line_account_id INT DEFAULT NULL AFTER id");
                $hasAccountCol = true;
            }
        } catch (Exception $e) {
        }

        $columnsToAdd = [
            'shop_logo' => "VARCHAR(500) DEFAULT NULL",
            'cod_enabled' => "TINYINT(1) DEFAULT 0",
            'cod_fee' => "DECIMAL(10,2) DEFAULT 0",
            'auto_confirm_payment' => "TINYINT(1) DEFAULT 0",
            'order_data_source' => "VARCHAR(20) DEFAULT 'shop'",
            'shop_address' => "TEXT DEFAULT NULL",
            'shop_email' => "VARCHAR(255) DEFAULT NULL",
            'line_id' => "VARCHAR(100) DEFAULT NULL",
            'facebook_url' => "VARCHAR(500) DEFAULT NULL",
            'instagram_url' => "VARCHAR(500) DEFAULT NULL"
        ];

        foreach ($columnsToAdd as $col => $type) {
            try {
                $stmt = $db->query("SHOW COLUMNS FROM shop_settings LIKE '$col'");
                if ($stmt->rowCount() == 0) {
                    $db->exec("ALTER TABLE shop_settings ADD COLUMN $col $type");
                }
            } catch (Exception $e) {
            }
        }
    }
}

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $postTab = $_POST['tab'] ?? '';

    if ($postTab === 'general' && $tableExists) {
        $bankAccounts = json_encode([
            'banks' => array_map(function ($name, $account, $holder) {
                return ['name' => $name, 'account' => $account, 'holder' => $holder];
            }, $_POST['bank_name'] ?? [], $_POST['bank_account'] ?? [], $_POST['bank_holder'] ?? [])
        ]);

        try {
            $logoUrl = $_POST['shop_logo'] ?? '';
            if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/shop/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileExt = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (in_array($fileExt, $allowedExts)) {
                    $fileName = 'logo_' . $currentBotId . '_' . time() . '.' . $fileExt;
                    $uploadPath = $uploadDir . $fileName;

                    if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploadPath)) {
                        $logoUrl = rtrim(BASE_URL, '/') . '/uploads/shop/' . $fileName;
                    }
                }
            }

            $updateFields = [
                'shop_name' => $_POST['shop_name'] ?? '',
                'shop_logo' => $logoUrl,
                'welcome_message' => $_POST['welcome_message'] ?? '',
                'shop_address' => $_POST['shop_address'] ?? '',
                'shop_email' => $_POST['shop_email'] ?? '',
                'shipping_fee' => (float) ($_POST['shipping_fee'] ?? 50),
                'free_shipping_min' => (float) ($_POST['free_shipping_min'] ?? 500),
                'bank_accounts' => $bankAccounts,
                'promptpay_number' => $_POST['promptpay_number'] ?? '',
                'contact_phone' => $_POST['contact_phone'] ?? '',
                'is_open' => isset($_POST['is_open']) ? 1 : 0,
                'cod_enabled' => isset($_POST['cod_enabled']) ? 1 : 0,
                'cod_fee' => (float) ($_POST['cod_fee'] ?? 0),
                'auto_confirm_payment' => isset($_POST['auto_confirm_payment']) ? 1 : 0,
                'order_data_source' => normalizeShopOrderDataSource($_POST['order_data_source'] ?? 'shop'),
                'line_id' => $_POST['line_id'] ?? '',
                'facebook_url' => $_POST['facebook_url'] ?? '',
                'instagram_url' => $_POST['instagram_url'] ?? ''
            ];

            if ($hasAccountCol && $currentBotId) {
                $stmt = $db->prepare("SELECT id FROM shop_settings WHERE line_account_id = ?");
                $stmt->execute([$currentBotId]);
                $existingId = $stmt->fetchColumn();

                if ($existingId) {
                    $setClauses = [];
                    $values = [];
                    foreach ($updateFields as $field => $value) {
                        $setClauses[] = "$field = ?";
                        $values[] = $value;
                    }
                    $values[] = $currentBotId;

                    $stmt = $db->prepare("UPDATE shop_settings SET " . implode(', ', $setClauses) . " WHERE line_account_id = ?");
                    $stmt->execute($values);
                } else {
                    $fields = array_keys($updateFields);
                    $fields[] = 'line_account_id';
                    $values = array_values($updateFields);
                    $values[] = $currentBotId;
                    $placeholders = array_fill(0, count($values), '?');

                    $stmt = $db->prepare("INSERT INTO shop_settings (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
                    $stmt->execute($values);
                }
            } else {
                $setClauses = [];
                $values = [];
                foreach ($updateFields as $field => $value) {
                    $setClauses[] = "$field = ?";
                    $values[] = $value;
                }

                $stmt = $db->prepare("UPDATE shop_settings SET " . implode(', ', $setClauses) . " WHERE id = 1");
                $stmt->execute($values);

                if ($stmt->rowCount() == 0) {
                    $fields = array_keys($updateFields);
                    $placeholders = array_fill(0, count($updateFields), '?');
                    $stmt = $db->prepare("INSERT INTO shop_settings (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")");
                    $stmt->execute(array_values($updateFields));
                }
            }

            $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าทั่วไปร้านค้า', [
                'entity_type' => 'shop_settings',
                'new_value' => ['name' => $_POST['shop_name'] ?? '']
            ]);

            header('Location: settings.php?tab=general&saved=1');
            exit;
        } catch (Exception $e) {
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $activeTab = 'general';
        }
    }

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
            'receipt_points_enabled' => isset($_POST['receipt_points_enabled']) ? 1 : 0,
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
            'receipt_points_enabled' => isset($_POST['receipt_points_enabled']) ? 1 : 0,
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

    // Platform connection actions (Facebook Messenger / TikTok Shop)
    elseif ($action === 'save_facebook') {
        try {
            $fbId = (int) ($_POST['fb_id'] ?? 0);
            $fields = [
                'name'              => trim($_POST['name'] ?? ''),
                'page_id'           => trim($_POST['page_id'] ?? ''),
                'app_id'            => trim($_POST['app_id'] ?? ''),
                'app_secret'        => trim($_POST['app_secret'] ?? ''),
                'page_access_token' => trim($_POST['page_access_token'] ?? ''),
                'verify_token'      => trim($_POST['verify_token'] ?? ''),
                'is_active'         => isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($fields['name'] === '' || $fields['page_id'] === '' || $fields['page_access_token'] === '') {
                throw new Exception('กรุณากรอกชื่อเพจ, Page ID และ Page Access Token');
            }

            if ($fbId > 0) {
                $stmt = $db->prepare("UPDATE facebook_accounts SET name = ?, page_id = ?, app_id = ?, app_secret = ?, page_access_token = ?, verify_token = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$fields['name'], $fields['page_id'], $fields['app_id'], $fields['app_secret'], $fields['page_access_token'], $fields['verify_token'], $fields['is_active'], $fbId]);
                $success = 'อัปเดตการเชื่อมต่อ Facebook Messenger สำเร็จ';
            } else {
                $stmt = $db->prepare("INSERT INTO facebook_accounts (name, page_id, app_id, app_secret, page_access_token, verify_token, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fields['name'], $fields['page_id'], $fields['app_id'], $fields['app_secret'], $fields['page_access_token'], $fields['verify_token'], $fields['is_active']]);
                $success = 'เพิ่มการเชื่อมต่อ Facebook Messenger สำเร็จ';
            }

            $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าการเชื่อมต่อ Facebook Messenger', [
                'entity_type' => 'facebook_accounts',
                'new_value'   => ['name' => $fields['name'], 'page_id' => $fields['page_id']]
            ]);
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
        $activeTab = 'platform';
    } elseif ($action === 'delete_facebook') {
        try {
            $stmt = $db->prepare("DELETE FROM facebook_accounts WHERE id = ?");
            $stmt->execute([(int) ($_POST['fb_id'] ?? 0)]);
            $success = 'ลบการเชื่อมต่อ Facebook Messenger แล้ว';
        } catch (Exception $e) {
            $error = 'ลบไม่สำเร็จ: ' . $e->getMessage();
        }
        $activeTab = 'platform';
    } elseif ($action === 'test_facebook') {
        try {
            $stmt = $db->prepare("SELECT * FROM facebook_accounts WHERE id = ?");
            $stmt->execute([(int) ($_POST['fb_id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('ไม่พบเพจที่ต้องการทดสอบ (บันทึกก่อนทดสอบ)');
            }
            // Validate the page token via debug_token (uses the app token, so it works
            // even when the page token lacks pages_read_engagement — a messaging-only
            // token is still valid). Falls back to /me?fields=name if app creds are unset.
            $pageToken = (string) $row['page_access_token'];
            $appId     = trim((string) ($row['app_id'] ?? ''));
            $appSecret = trim((string) ($row['app_secret'] ?? ''));

            if ($appId !== '' && $appSecret !== '') {
                $verifyUrl = 'https://graph.facebook.com/v19.0/debug_token?input_token='
                    . urlencode($pageToken) . '&access_token=' . urlencode($appId . '|' . $appSecret);
            } else {
                $verifyUrl = 'https://graph.facebook.com/v19.0/me?fields=name&access_token=' . urlencode($pageToken);
            }

            $ch = curl_init($verifyUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $resp = curl_exec($ch);
            $curlErr = curl_error($ch);
            curl_close($ch);
            $res = json_decode((string) $resp, true) ?? [];

            if ($curlErr) {
                $error = 'เชื่อมต่อไม่สำเร็จ: ' . $curlErr;
            } elseif (isset($res['data']['is_valid'])) {
                $d = $res['data'];
                if (!empty($d['is_valid'])) {
                    $pid = (string) ($d['profile_id'] ?? '');
                    if ($pid !== '' && $pid !== (string) $row['page_id']) {
                        $error = 'Token ใช้ได้ แต่เป็นของเพจอื่น (Page ID ' . $pid . ') — ต้องตรงกับ ' . $row['page_id'];
                    } else {
                        $scopes = is_array($d['scopes'] ?? null) ? implode(', ', array_slice($d['scopes'], 0, 8)) : '';
                        $hasMsg = strpos($scopes, 'pages_messaging') !== false;
                        $success = 'เชื่อมต่อ Facebook สำเร็จ: token ใช้งานได้'
                            . ($hasMsg ? ' (มีสิทธิ์ pages_messaging ✓)' : '')
                            . ($scopes !== '' ? ' — scopes: ' . $scopes : '');
                    }
                } else {
                    $msg = $d['error']['message'] ?? 'token หมดอายุหรือถูกเพิกถอน';
                    $error = 'เชื่อมต่อไม่สำเร็จ: ' . $msg;
                }
            } elseif (!empty($res['name'])) {
                $success = 'เชื่อมต่อ Facebook สำเร็จ: ' . $res['name'];
            } else {
                $msg = $res['error']['message'] ?? '';
                $error = 'เชื่อมต่อไม่สำเร็จ: ' . ($msg !== '' ? $msg : 'ตรวจสอบ Page Access Token / App Secret');
            }
        } catch (Exception $e) {
            $error = 'ทดสอบไม่สำเร็จ: ' . $e->getMessage();
        }
        $activeTab = 'platform';
    } elseif ($action === 'save_tiktok') {
        try {
            $ttId = (int) ($_POST['tt_id'] ?? 0);
            $fields = [
                'name'          => trim($_POST['name'] ?? ''),
                'shop_id'       => trim($_POST['shop_id'] ?? ''),
                'app_key'       => trim($_POST['app_key'] ?? ''),
                'app_secret'    => trim($_POST['app_secret'] ?? ''),
                'access_token'  => trim($_POST['access_token'] ?? ''),
                'refresh_token' => trim($_POST['refresh_token'] ?? ''),
                'shop_cipher'   => trim($_POST['shop_cipher'] ?? ''),
                'is_active'     => isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($fields['name'] === '' || $fields['shop_id'] === '' || $fields['access_token'] === '') {
                throw new Exception('กรุณากรอกชื่อร้าน, Shop ID และ Access Token');
            }

            if ($ttId > 0) {
                $stmt = $db->prepare("UPDATE tiktok_shop_accounts SET name = ?, shop_id = ?, app_key = ?, app_secret = ?, access_token = ?, refresh_token = ?, shop_cipher = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$fields['name'], $fields['shop_id'], $fields['app_key'], $fields['app_secret'], $fields['access_token'], $fields['refresh_token'], $fields['shop_cipher'], $fields['is_active'], $ttId]);
                $success = 'อัปเดตการเชื่อมต่อ TikTok Shop สำเร็จ';
            } else {
                $stmt = $db->prepare("INSERT INTO tiktok_shop_accounts (name, shop_id, app_key, app_secret, access_token, refresh_token, shop_cipher, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$fields['name'], $fields['shop_id'], $fields['app_key'], $fields['app_secret'], $fields['access_token'], $fields['refresh_token'], $fields['shop_cipher'], $fields['is_active']]);
                $success = 'เพิ่มการเชื่อมต่อ TikTok Shop สำเร็จ';
            }

            $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าการเชื่อมต่อ TikTok Shop', [
                'entity_type' => 'tiktok_shop_accounts',
                'new_value'   => ['name' => $fields['name'], 'shop_id' => $fields['shop_id']]
            ]);
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
        $activeTab = 'platform';
    } elseif ($action === 'delete_tiktok') {
        try {
            $stmt = $db->prepare("DELETE FROM tiktok_shop_accounts WHERE id = ?");
            $stmt->execute([(int) ($_POST['tt_id'] ?? 0)]);
            $success = 'ลบการเชื่อมต่อ TikTok Shop แล้ว';
        } catch (Exception $e) {
            $error = 'ลบไม่สำเร็จ: ' . $e->getMessage();
        }
        $activeTab = 'platform';
    } elseif ($action === 'test_tiktok') {
        try {
            $stmt = $db->prepare("SELECT * FROM tiktok_shop_accounts WHERE id = ?");
            $stmt->execute([(int) ($_POST['tt_id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('ไม่พบร้านที่ต้องการทดสอบ (บันทึกก่อนทดสอบ)');
            }
            require_once 'classes/TikTokShopAPI.php';
            $api = new TikTokShopAPI($row);
            $res = $api->getConversations(1);
            if ($res['success'] ?? false) {
                $success = 'เชื่อมต่อ TikTok Shop สำเร็จ: ' . $row['name'];
            } else {
                $msg = $res['message'] ?? (is_array($res['error'] ?? null) ? '' : ($res['error'] ?? ''));
                $error = 'เชื่อมต่อไม่สำเร็จ: ' . ($msg !== '' ? $msg : 'ตรวจสอบ Access Token / App Key / Shop Cipher');
            }
        } catch (Exception $e) {
            $error = 'ทดสอบไม่สำเร็จ: ' . $e->getMessage();
        }
        $activeTab = 'platform';
    }

    // Welcome message actions
    elseif ($action === 'save_welcome') {
        try {
            $currentBotId = $_SESSION['current_bot_id'] ?? null;
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;
            $messageType = $_POST['message_type'] ?? 'text';
            $textContent = $_POST['text_content'] ?? '';
            $flexContent = $_POST['flex_content'] ?? '';

            // Check if settings exist for this bot
            $stmt = $db->prepare("SELECT id FROM welcome_settings WHERE line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL)");
            $stmt->execute([$currentBotId, $currentBotId]);
            $exists = $stmt->fetch();

            if ($exists) {
                $stmt = $db->prepare("UPDATE welcome_settings SET is_enabled = ?, message_type = ?, text_content = ?, flex_content = ? WHERE id = ?");
                $stmt->execute([$isEnabled, $messageType, $textContent, $flexContent, $exists['id']]);
            } else {
                $stmt = $db->prepare("INSERT INTO welcome_settings (line_account_id, is_enabled, message_type, text_content, flex_content) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$currentBotId, $isEnabled, $messageType, $textContent, $flexContent]);
            }
            $success = 'บันทึกการตั้งค่าข้อความต้อนรับสำเร็จ!';

            // Log activity
            $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าข้อความต้อนรับ', [
                'entity_type' => 'welcome_settings',
                'new_value' => ['enabled' => $isEnabled, 'type' => $messageType]
            ]);

        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
        $activeTab = 'welcome';
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
        // Ensure columns exist
        try {
            $cols = $db->query("SHOW COLUMNS FROM telegram_settings")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('notify_new_order', $cols)) {
                $db->exec("ALTER TABLE telegram_settings ADD COLUMN notify_new_order TINYINT(1) DEFAULT 1");
            }
            if (!in_array('notify_payment', $cols)) {
                $db->exec("ALTER TABLE telegram_settings ADD COLUMN notify_payment TINYINT(1) DEFAULT 1");
            }
        } catch (Exception $e) {
        }

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

        // Log activity
        $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าการแจ้งเตือน (Telegram)', [
            'entity_type' => 'telegram_settings'
        ]);

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
                (int) ($_POST['smtp_port'] ?? 587),
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
            $accountId = (int) ($currentBotId ?: 0);
            $emailAddresses = trim($_POST['email_addresses'] ?? '');
            $notifyAdminUsers = isset($_POST['notify_admin_users']) ? implode(',', $_POST['notify_admin_users']) : '';
            $odooEvents = isset($_POST['odoo_liff_notify_events']) && is_array($_POST['odoo_liff_notify_events'])
                ? implode(',', array_map('trim', $_POST['odoo_liff_notify_events']))
                : '';

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
                isset($_POST['odoo_liff_notify_enabled']) ? 1 : 0,
                $odooEvents,
                $notifyAdminUsers
            ];

            $sql = "INSERT INTO notification_settings 
                (line_account_id, line_notify_enabled, line_notify_new_order, line_notify_payment, 
                 line_notify_urgent, line_notify_appointment, line_notify_low_stock,
                 email_enabled, email_addresses, email_notify_urgent, email_notify_daily_report, email_notify_low_stock,
                 telegram_enabled, odoo_liff_notify_enabled, odoo_liff_notify_events, notify_admin_users)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                odoo_liff_notify_enabled = VALUES(odoo_liff_notify_enabled),
                odoo_liff_notify_events = VALUES(odoo_liff_notify_events),
                notify_admin_users = VALUES(notify_admin_users)";

            $stmt = $db->prepare($sql);
            $stmt->execute($data);
            $success = 'บันทึกการตั้งค่าการแจ้งเตือนสำเร็จ';

            // Log activity
            $activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าการแจ้งเตือน (System)', [
                'entity_type' => 'notification_settings'
            ]);

        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
        $activeTab = 'notifications';
    }
    elseif ($action === 'test_odoo_liff_notification') {
        try {
            $currentBotId = $_SESSION['current_bot_id'] ?? null;
            $accountId = (int) ($currentBotId ?: 0);
            $lineUserId = trim($_POST['test_line_user_id'] ?? '');
            $eventCode = trim($_POST['test_odoo_event'] ?? 'order.validated');
            $orderRef = trim($_POST['test_order_ref'] ?? 'SO-TEST-001');
            $customerName = trim($_POST['test_customer_name'] ?? 'ลูกค้าทดสอบ');

            if ($lineUserId === '') {
                throw new Exception('กรุณาระบุ LINE User ID ที่ต้องการทดสอบส่ง');
            }

            $eventLabels = [
                'order.validated' => 'ยืนยันออเดอร์',
                'order.awaiting_payment' => 'รอชำระเงิน',
                'order.paid' => 'ชำระเงินแล้ว',
                'order.to_delivery' => 'เตรียมส่ง',
                'order.in_delivery' => 'กำลังจัดส่ง',
                'order.delivered' => 'จัดส่งสำเร็จ',
                'invoice.created' => 'ออกใบแจ้งหนี้',
                'invoice.overdue' => 'ใบแจ้งหนี้เกินกำหนด',
            ];
            if (!isset($eventLabels[$eventCode])) {
                throw new Exception('สถานะทดสอบไม่ถูกต้อง');
            }

            $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$accountId]);
            $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
            $channelAccessToken = trim($lineAccount['channel_access_token'] ?? '');

            if ($channelAccessToken === '') {
                $stmt = $db->query("SELECT channel_access_token FROM line_accounts WHERE is_default = 1 LIMIT 1");
                $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
                $channelAccessToken = trim($lineAccount['channel_access_token'] ?? '');
            }

            if ($channelAccessToken === '') {
                throw new Exception('ไม่พบ Channel Access Token สำหรับส่งข้อความ');
            }

            require_once __DIR__ . '/classes/OdooFlexTemplates.php';

            $message = "[TEST] " . ($eventLabels[$eventCode] ?? $eventCode);
            $flexBubble = OdooFlexTemplates::odooStatusUpdate($eventCode, [
                'order_ref' => $orderRef,
                'event_time' => date('d/m/Y H:i:s'),
                'amount_total' => 3790.50,
                'customer' => [
                    'name' => $customerName,
                ],
            ], $message, false);

            $payload = [
                'to' => $lineUserId,
                'messages' => [
                    [
                        'type' => 'flex',
                        'altText' => '🧪 ทดสอบแจ้งเตือน Odoo ' . ($eventLabels[$eventCode] ?? $eventCode),
                        'contents' => $flexBubble,
                    ]
                ]
            ];

            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $channelAccessToken,
                ],
                CURLOPT_TIMEOUT => 20,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception('เกิดข้อผิดพลาดเครือข่าย: ' . $curlError);
            }

            if ($httpCode !== 200) {
                throw new Exception('LINE API ตอบกลับไม่สำเร็จ (' . $httpCode . '): ' . ($response ?: 'no response'));
            }

            $success = 'ส่งข้อความทดสอบ Odoo → LIFF สำเร็จแล้ว';
        } catch (Exception $e) {
            $error = 'ทดสอบส่งแจ้งเตือนไม่สำเร็จ: ' . $e->getMessage();
        }
        $activeTab = 'notifications';
    }
}

require_once 'includes/header.php';
echo getTabsStyles();
echo getFormSectionStyles();
echo getFieldStyles();
echo getToggleStyles();
echo getStickySaveBarStyles();
?>

<?php if (isset($_GET['saved'])): ?>
    <div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i>บันทึกการตั้งค่าสำเร็จ!
    </div>
<?php endif; ?>

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
            case 'general':
                include 'includes/shop/general.php';
                break;
            case 'shop_tax':
                include 'includes/settings/shop-tax.php';
                break;
            case 'welcome':
                include 'includes/settings/welcome.php';
                break;
            case 'liff':
                include 'includes/settings/liff.php';
                break;
            case 'vibe-selling':
                include 'includes/settings/vibe-selling.php';
                break;
            case 'platform':
                include 'includes/settings/platform.php';
                break;
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
