<?php
/**
 * Shop Settings - ตั้งค่าร้านค้า (Tab-based)
 * รวม: General, LIFF Shop, Promotions
 * V3.0 - Consolidated Tab-based UI
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/components/tabs.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'ตั้งค่าร้านค้า';
$currentBotId = $_SESSION['current_bot_id'] ?? 1;
$lineAccountId = $_SESSION['line_account_id'] ?? $_SESSION['current_bot_id'] ?? 1;

// Define tabs
$tabs = [
    'general' => ['label' => 'ทั่วไป', 'icon' => 'fas fa-store'],
    'liff' => ['label' => 'LIFF Shop', 'icon' => 'fas fa-mobile-alt'],
    'promotions' => ['label' => 'ธีม/โปรโมชั่น', 'icon' => 'fas fa-palette'],
];

$activeTab = getActiveTab($tabs, 'general');

// ==================== Handle AJAX requests for LIFF tab ====================
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    $ajaxAction = $_POST['ajax_action'] ?? '';
    
    try {
        switch ($ajaxAction) {
            case 'save_liff_settings':
                $settings = $_POST['settings'] ?? [];
                foreach ($settings as $key => $value) {
                    $stmt = $db->prepare("INSERT INTO liff_shop_settings (line_account_id, setting_key, setting_value) 
                                          VALUES (?, ?, ?) 
                                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $stmt->execute([$lineAccountId, $key, is_array($value) ? json_encode($value) : $value]);
                }
                echo json_encode(['success' => true, 'message' => 'บันทึกการตั้งค่าเรียบร้อย']);
                exit;
                
            case 'toggle_category':
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $enabled = (int)($_POST['enabled'] ?? 0);
                
                $stmt = $db->prepare("SELECT setting_value FROM liff_shop_settings WHERE line_account_id = ? AND setting_key = 'hidden_categories'");
                $stmt->execute([$lineAccountId]);
                $hidden = json_decode($stmt->fetchColumn() ?: '[]', true);
                
                if ($enabled) {
                    $hidden = array_diff($hidden, [$categoryId]);
                } else {
                    $hidden[] = $categoryId;
                    $hidden = array_unique($hidden);
                }
                
                $stmt = $db->prepare("INSERT INTO liff_shop_settings (line_account_id, setting_key, setting_value) 
                                      VALUES (?, 'hidden_categories', ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$lineAccountId, json_encode(array_values($hidden))]);
                
                echo json_encode(['success' => true, 'hidden' => $hidden]);
                exit;
                
            case 'update_order':
                $order = $_POST['order'] ?? [];
                $stmt = $db->prepare("INSERT INTO liff_shop_settings (line_account_id, setting_key, setting_value) 
                                      VALUES (?, 'category_order', ?) 
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute([$lineAccountId, json_encode($order)]);
                echo json_encode(['success' => true]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ==================== Check and create shop_settings table ====================
$tableExists = false;
$hasAccountCol = false;
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

// Check for line_account_id column
if ($tableExists) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM shop_settings LIKE 'line_account_id'");
        $hasAccountCol = $stmt->rowCount() > 0;
        
        if (!$hasAccountCol) {
            $db->exec("ALTER TABLE shop_settings ADD COLUMN line_account_id INT DEFAULT NULL AFTER id");
            $hasAccountCol = true;
        }
    } catch (Exception $e) {}
    
    // Ensure all columns exist
    $columnsToAdd = [
        'shop_logo' => "VARCHAR(500) DEFAULT NULL",
        'cod_enabled' => "TINYINT(1) DEFAULT 0",
        'cod_fee' => "DECIMAL(10,2) DEFAULT 0",
        'auto_confirm_payment' => "TINYINT(1) DEFAULT 0",
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
        } catch (Exception $e) {}
    }
}

// ==================== Handle POST requests ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postTab = $_POST['tab'] ?? 'general';
    
    // Handle General tab POST
    if ($postTab === 'general' && $tableExists) {
        $bankAccounts = json_encode(['banks' => array_map(function($name, $account, $holder) {
            return ['name' => $name, 'account' => $account, 'holder' => $holder];
        }, $_POST['bank_name'] ?? [], $_POST['bank_account'] ?? [], $_POST['bank_holder'] ?? [])]);
        
        try {
            // Handle logo upload
            $logoUrl = $_POST['shop_logo'] ?? '';
            if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/shop/';
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
                'shipping_fee' => (float)($_POST['shipping_fee'] ?? 50),
                'free_shipping_min' => (float)($_POST['free_shipping_min'] ?? 500),
                'bank_accounts' => $bankAccounts,
                'promptpay_number' => $_POST['promptpay_number'] ?? '',
                'contact_phone' => $_POST['contact_phone'] ?? '',
                'is_open' => isset($_POST['is_open']) ? 1 : 0,
                'cod_enabled' => isset($_POST['cod_enabled']) ? 1 : 0,
                'cod_fee' => (float)($_POST['cod_fee'] ?? 0),
                'auto_confirm_payment' => isset($_POST['auto_confirm_payment']) ? 1 : 0,
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
            
            header('Location: settings.php?tab=general&saved=1');
            exit;
        } catch (Exception $e) {
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
    
    // Handle Promotions tab POST
    if ($postTab === 'promotions') {
        $promoAction = $_POST['promo_action'] ?? '';
        
        // Helper function for setting promo settings
        $setPromoSetting = function($key, $value) use ($db, $lineAccountId) {
            $jsonValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
            $stmt = $db->prepare("INSERT INTO promotion_settings (line_account_id, setting_key, setting_value) 
                                  VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$lineAccountId, $key, $jsonValue, $jsonValue]);
        };
        
        // Define themes for apply_theme action
        $themes = [
            'marketplace' => ['primary_color' => '#F85606', 'secondary_color' => '#FFE4D6', 'sale_badge_color' => '#EE4D2D', 'bestseller_badge_color' => '#FFAA00', 'featured_badge_color' => '#FF6B6B', 'card_style' => 'rounded', 'card_shadow' => 'sm', 'image_size' => 'large', 'columns_mobile' => 2, 'layout_style' => 'marketplace'],
            'pharmacy' => ['primary_color' => '#11B0A6', 'secondary_color' => '#E0F7F5', 'sale_badge_color' => '#EF4444', 'bestseller_badge_color' => '#F59E0B', 'featured_badge_color' => '#8B5CF6', 'card_style' => 'rounded-lg', 'card_shadow' => 'sm', 'image_size' => 'medium', 'columns_mobile' => 2, 'layout_style' => 'classic'],
            'modern' => ['primary_color' => '#3B82F6', 'secondary_color' => '#DBEAFE', 'sale_badge_color' => '#DC2626', 'bestseller_badge_color' => '#EA580C', 'featured_badge_color' => '#7C3AED', 'card_style' => 'rounded', 'card_shadow' => 'md', 'image_size' => 'large', 'columns_mobile' => 2, 'layout_style' => 'classic'],
            'minimal' => ['primary_color' => '#1F2937', 'secondary_color' => '#F3F4F6', 'sale_badge_color' => '#EF4444', 'bestseller_badge_color' => '#374151', 'featured_badge_color' => '#6B7280', 'card_style' => 'square', 'card_shadow' => 'none', 'image_size' => 'medium', 'columns_mobile' => 2, 'layout_style' => 'minimal'],
            'warm' => ['primary_color' => '#EC4899', 'secondary_color' => '#FCE7F3', 'sale_badge_color' => '#F43F5E', 'bestseller_badge_color' => '#F97316', 'featured_badge_color' => '#A855F7', 'card_style' => 'rounded-xl', 'card_shadow' => 'lg', 'image_size' => 'large', 'columns_mobile' => 2, 'layout_style' => 'classic'],
        ];
        
        if ($promoAction === 'apply_theme') {
            $themeKey = $_POST['theme'] ?? 'pharmacy';
            if (isset($themes[$themeKey])) {
                $theme = $themes[$themeKey];
                $setPromoSetting('current_theme', $themeKey);
                foreach ($theme as $key => $value) {
                    $setPromoSetting($key, $value);
                }
            }
            header('Location: settings.php?tab=promotions&saved=1');
            exit;
        }
        
        if ($promoAction === 'save_custom') {
            $setPromoSetting('current_theme', 'custom');
            $setPromoSetting('primary_color', $_POST['primary_color'] ?? '#11B0A6');
            $setPromoSetting('sale_badge_color', $_POST['sale_badge_color'] ?? '#EF4444');
            $setPromoSetting('bestseller_badge_color', $_POST['bestseller_badge_color'] ?? '#F59E0B');
            $setPromoSetting('featured_badge_color', $_POST['featured_badge_color'] ?? '#8B5CF6');
            $setPromoSetting('card_style', $_POST['card_style'] ?? 'rounded');
            $setPromoSetting('card_shadow', $_POST['card_shadow'] ?? 'sm');
            $setPromoSetting('image_size', $_POST['image_size'] ?? 'medium');
            $setPromoSetting('columns_mobile', (int)($_POST['columns_mobile'] ?? 2));
            $setPromoSetting('columns_desktop', (int)($_POST['columns_desktop'] ?? 4));
            $setPromoSetting('show_sale_section', isset($_POST['show_sale_section']) ? '1' : '0');
            $setPromoSetting('show_bestseller_section', isset($_POST['show_bestseller_section']) ? '1' : '0');
            $setPromoSetting('show_featured_section', isset($_POST['show_featured_section']) ? '1' : '0');
            
            header('Location: settings.php?tab=promotions&saved=1');
            exit;
        }
    }
}

// Include header after POST handling
require_once __DIR__ . '/../includes/header.php';
?>

<?= getTabsStyles() ?>

<?php if (isset($_GET['saved'])): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i>บันทึกการตั้งค่าสำเร็จ!
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?= renderTabs($tabs, $activeTab) ?>

<div class="tab-panel">
<?php
switch ($activeTab) {
    case 'liff':
        include __DIR__ . '/../includes/shop/liff.php';
        break;
    case 'promotions':
        include __DIR__ . '/../includes/shop/promotions.php';
        break;
    default:
        include __DIR__ . '/../includes/shop/general.php';
}
?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
