<?php
/**
 * API: Quick Access Preferences
 * จัดการ Quick Access Menu ของผู้ใช้แต่ละคน
 */

header('Content-Type: application/json; charset=utf-8');

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Check auth without redirect (API style)
$adminUserId = $_SESSION['admin_user']['id'] ?? null;

if (!$adminUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance()->getConnection();
$action = $_REQUEST['action'] ?? '';

// Ensure table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS admin_quick_access (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_user_id INT NOT NULL,
        menu_key VARCHAR(50) NOT NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_menu (admin_user_id, menu_key),
        INDEX idx_admin_user (admin_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Available menu items
$availableMenus = [
    'messages' => ['icon' => 'fa-comments', 'label' => 'แชท', 'url' => 'messages.php', 'color' => 'green'],
    'orders' => ['icon' => 'fa-receipt', 'label' => 'ออเดอร์', 'url' => 'shop/orders.php', 'color' => 'orange'],
    'products' => ['icon' => 'fa-box-open', 'label' => 'สินค้า', 'url' => 'shop/products.php', 'color' => 'blue'],
    'broadcast' => ['icon' => 'fa-paper-plane', 'label' => 'บรอดแคสต์', 'url' => 'broadcast-catalog.php', 'color' => 'purple'],
    'users' => ['icon' => 'fa-users', 'label' => 'ลูกค้า', 'url' => 'users.php', 'color' => 'cyan'],
    'auto-reply' => ['icon' => 'fa-robot', 'label' => 'ตอบอัตโนมัติ', 'url' => 'auto-reply.php', 'color' => 'pink'],
    'analytics' => ['icon' => 'fa-chart-pie', 'label' => 'สถิติ', 'url' => 'analytics.php', 'color' => 'indigo'],
    'rich-menu' => ['icon' => 'fa-th-large', 'label' => 'Rich Menu', 'url' => 'rich-menu.php', 'color' => 'teal'],
    'appointments' => ['icon' => 'fa-calendar-check', 'label' => 'นัดหมาย', 'url' => 'appointments-admin.php', 'color' => 'amber'],
    'pharmacist' => ['icon' => 'fa-user-md', 'label' => 'เภสัชกร', 'url' => 'pharmacist-dashboard.php', 'color' => 'emerald'],
    'sync' => ['icon' => 'fa-sync', 'label' => 'Sync สินค้า', 'url' => 'sync-dashboard.php', 'color' => 'sky'],
    'ai-settings' => ['icon' => 'fa-brain', 'label' => 'AI Settings', 'url' => 'ai-pharmacy-settings.php', 'color' => 'violet'],
    'members' => ['icon' => 'fa-id-card', 'label' => 'สมาชิก', 'url' => 'members.php', 'color' => 'rose'],
    'categories' => ['icon' => 'fa-folder', 'label' => 'หมวดหมู่', 'url' => 'shop/categories.php', 'color' => 'lime'],
    'templates' => ['icon' => 'fa-file-alt', 'label' => 'Templates', 'url' => 'templates.php', 'color' => 'slate'],
];

switch ($action) {
    case 'get':
        // Get user's quick access
        $stmt = $db->prepare("SELECT menu_key, sort_order FROM admin_quick_access WHERE admin_user_id = ? ORDER BY sort_order");
        $stmt->execute([$adminUserId]);
        $userMenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no custom settings, return defaults
        if (empty($userMenus)) {
            $defaults = ['messages', 'orders', 'products', 'broadcast'];
            $result = [];
            foreach ($defaults as $i => $key) {
                if (isset($availableMenus[$key])) {
                    $result[] = array_merge(['key' => $key], $availableMenus[$key]);
                }
            }
            echo json_encode(['success' => true, 'data' => $result, 'is_default' => true]);
        } else {
            $result = [];
            foreach ($userMenus as $menu) {
                $key = $menu['menu_key'];
                if (isset($availableMenus[$key])) {
                    $result[] = array_merge(['key' => $key], $availableMenus[$key]);
                }
            }
            echo json_encode(['success' => true, 'data' => $result, 'is_default' => false]);
        }
        break;

    case 'get_available':
        // Get all available menus
        $result = [];
        foreach ($availableMenus as $key => $menu) {
            $result[] = array_merge(['key' => $key], $menu);
        }
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'save':
        // Save user's quick access selection
        $input = json_decode(file_get_contents('php://input'), true);
        $menuKeys = $input['menus'] ?? [];
        
        if (!is_array($menuKeys) || count($menuKeys) > 8) {
            echo json_encode(['success' => false, 'error' => 'เลือกได้สูงสุด 8 รายการ']);
            exit;
        }
        
        // Validate menu keys
        $validKeys = array_filter($menuKeys, fn($k) => isset($availableMenus[$k]));
        
        try {
            $db->beginTransaction();
            
            // Delete existing
            $stmt = $db->prepare("DELETE FROM admin_quick_access WHERE admin_user_id = ?");
            $stmt->execute([$adminUserId]);
            
            // Insert new
            $stmt = $db->prepare("INSERT INTO admin_quick_access (admin_user_id, menu_key, sort_order) VALUES (?, ?, ?)");
            foreach ($validKeys as $i => $key) {
                $stmt->execute([$adminUserId, $key, $i]);
            }
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'บันทึกสำเร็จ']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'reset':
        // Reset to defaults
        $stmt = $db->prepare("DELETE FROM admin_quick_access WHERE admin_user_id = ?");
        $stmt->execute([$adminUserId]);
        echo json_encode(['success' => true, 'message' => 'รีเซ็ตเรียบร้อย']);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
