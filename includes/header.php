<?php
/**
 * Header & Sidebar Component - Modern Admin Dashboard V3.0
 * Unified Shop System
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_check.php';

/**
 * Get current user's role for menu access control
 * Maps database roles to menu system roles
 * @return string Role: owner, admin, pharmacist, staff, marketing, tech
 */
function getCurrentUserRole() {
    global $currentUser;
    
    if (!isset($currentUser['role'])) {
        return 'staff'; // Default role
    }
    
    $dbRole = $currentUser['role'];
    
    // Map database roles to menu system roles
    switch ($dbRole) {
        case 'super_admin':
            return 'owner';
        case 'admin':
            return 'admin';
        case 'pharmacist':
            return 'pharmacist';
        case 'marketing':
            return 'marketing';
        case 'tech':
            return 'tech';
        case 'staff':
        default:
            return 'staff';
    }
}

/**
 * Check if current user has access to a menu item
 * @param array $menuItem Menu item with optional 'roles' key
 * @return bool True if user can access the menu item
 */
function hasMenuAccess($menuItem) {
    // If no roles specified, everyone can access
    if (!isset($menuItem['roles']) || empty($menuItem['roles'])) {
        return true;
    }
    
    $userRole = getCurrentUserRole();
    
    // Check if user's role is in the allowed roles array
    return in_array($userRole, $menuItem['roles']);
}

// Helper function to generate clean URLs (without .php)
function cleanUrl($url) {
    // Remove .php extension for clean URLs
    return preg_replace('/\.php$/', '', $url);
}

// ถ้าเป็น User ทั่วไป ให้ redirect ไปหน้า User Dashboard
if (isUser()) {
    if (empty($currentUser['line_account_id'])) {
        header('Location: /auth/setup-account');
    } else {
        header('Location: /user/dashboard');
    }
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentPath = $_SERVER['PHP_SELF'];

// Detect folder (shop or inventory)
$isShop = strpos($currentPath, '/shop/') !== false;
$isInventory = strpos($currentPath, '/inventory/') !== false;
$isSubfolder = $isShop || $isInventory;
$baseUrl = $isSubfolder ? '../' : '';


// Handle bot switching
if (isset($_GET['switch_bot'])) {
    $_SESSION['current_bot_id'] = (int)$_GET['switch_bot'];
    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: " . $redirectUrl);
    exit;
}

// Get accessible LINE accounts based on user permissions
$lineAccounts = [];
$currentBot = null;
try {
    $db = Database::getInstance()->getConnection();
    
    // Use getAccessibleBots() which respects user permissions
    $lineAccounts = getAccessibleBots();
    
    if (!empty($lineAccounts)) {
        // Check if current bot is accessible
        if (isset($_SESSION['current_bot_id'])) {
            foreach ($lineAccounts as $acc) {
                if ($acc['id'] == $_SESSION['current_bot_id']) {
                    $currentBot = $acc;
                    break;
                }
            }
        }
        // If current bot not accessible or not set, use first accessible
        if (!$currentBot) {
            foreach ($lineAccounts as $acc) {
                if (!empty($acc['is_default'])) { $currentBot = $acc; break; }
            }
            if (!$currentBot) $currentBot = $lineAccounts[0];
            $_SESSION['current_bot_id'] = $currentBot['id'];
        }
    }
} catch (Exception $e) {}

$currentBotId = $currentBot['id'] ?? null;

// Get unread counts
$unreadMessages = 0;
$pendingOrders = 0;
$pendingSlips = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE is_read = 0 AND direction = 'incoming' AND (line_account_id = ? OR line_account_id IS NULL)");
    $stmt->execute([$currentBotId]);
    $unreadMessages = $stmt->fetchColumn() ?: 0;
    
    // Check orders table
    $ordersTable = null;
    try { $db->query("SELECT 1 FROM orders LIMIT 1"); $ordersTable = 'orders'; } catch (Exception $e) {}
    if (!$ordersTable) { try { $db->query("SELECT 1 FROM transactions LIMIT 1"); $ordersTable = 'transactions'; } catch (Exception $e) {} }
    
    if ($ordersTable) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$ordersTable} WHERE status = 'pending' AND (line_account_id = ? OR line_account_id IS NULL)");
        $stmt->execute([$currentBotId]);
        $pendingOrders = $stmt->fetchColumn() ?: 0;
    }
    
    // Count pending slips
    try {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT ps.transaction_id) FROM payment_slips ps 
            INNER JOIN transactions t ON ps.transaction_id = t.id 
            WHERE ps.status = 'pending' AND (t.line_account_id = ? OR t.line_account_id IS NULL)");
        $stmt->execute([$currentBotId]);
        $pendingSlips = $stmt->fetchColumn() ?: 0;
    } catch (Exception $e) {}
} catch (Exception $e) {}

// ==================== Quick Access - User Customizable ====================
// Available quick access menus (using clean URLs without .php)
// Each item includes 'roles' for role-based access control (matching main menu structure)
// Note: Items without 'roles' key are accessible to all staff (per Requirements 9.1, 9.2, 9.3)
$quickAccessMenus = [
    // ==================== Clinical Station - Unified Care Chat ====================
    'messages' => ['icon' => 'fa-inbox', 'label' => 'แชท', 'url' => '/inbox', 'page' => 'inbox', 'badge' => $unreadMessages, 'color' => 'green', 'roles' => ['pharmacist', 'staff']],
    'video-call' => ['icon' => 'fa-video', 'label' => 'Video Call', 'url' => '/video-call-pro', 'page' => 'video-call-pro', 'color' => 'red', 'roles' => ['pharmacist', 'staff']],
    'auto-reply' => ['icon' => 'fa-robot', 'label' => 'ตอบอัตโนมัติ', 'url' => '/auto-reply', 'page' => 'auto-reply', 'color' => 'pink', 'roles' => ['pharmacist', 'staff']],
    
    // ==================== Clinical Station - Roster & Shifts (all staff) ====================
    'pharmacist-dashboard' => ['icon' => 'fa-user-md', 'label' => 'Dashboard เภสัชกร', 'url' => '/pharmacist-dashboard', 'page' => 'pharmacist-dashboard', 'color' => 'emerald'],
    'pharmacists' => ['icon' => 'fa-users', 'label' => 'จัดการเภสัชกร', 'url' => '/pharmacists', 'page' => 'pharmacists', 'color' => 'teal'],
    'appointments' => ['icon' => 'fa-calendar-check', 'label' => 'นัดหมาย', 'url' => '/appointments-admin', 'page' => 'appointments-admin', 'color' => 'amber'],
    
    // ==================== Clinical Station - Medical Copilot AI ====================
    'ai-chat' => ['icon' => 'fa-comments', 'label' => 'AI ตอบแชท', 'url' => '/ai-chat-settings', 'page' => 'ai-chat-settings', 'color' => 'fuchsia', 'roles' => ['pharmacist']],
    'ai-studio' => ['icon' => 'fa-wand-magic-sparkles', 'label' => 'AI Studio', 'url' => '/ai-studio', 'page' => 'ai-studio', 'color' => 'rose', 'roles' => ['pharmacist']],
    'ai-pharmacy' => ['icon' => 'fa-cog', 'label' => 'ตั้งค่า AI เภสัช', 'url' => '/ai-pharmacy-settings', 'page' => 'ai-pharmacy-settings', 'color' => 'purple', 'roles' => ['pharmacist']],
    
    // ==================== Insights & Overview ====================
    'executive' => ['icon' => 'fa-chart-line', 'label' => 'แดชบอร์ดผู้บริหาร', 'url' => '/executive-dashboard', 'page' => 'executive-dashboard', 'color' => 'indigo', 'roles' => ['owner', 'admin']],
    'triage' => ['icon' => 'fa-stethoscope', 'label' => 'สถิติการรักษา', 'url' => '/triage-analytics', 'page' => 'triage-analytics', 'color' => 'emerald', 'roles' => ['pharmacist', 'owner']],
    'drug-interactions' => ['icon' => 'fa-pills', 'label' => 'ยาตีกัน', 'url' => '/drug-interactions', 'page' => 'drug-interactions', 'color' => 'red', 'roles' => ['pharmacist', 'owner']],
    'activity-logs' => ['icon' => 'fa-history', 'label' => 'ประวัติการใช้งาน', 'url' => '/activity-logs', 'page' => 'activity-logs', 'color' => 'slate', 'roles' => ['owner']],
    
    // ==================== Patient & Journey - EHR ====================
    'users' => ['icon' => 'fa-users', 'label' => 'รายชื่อลูกค้า', 'url' => '/users', 'page' => 'users', 'color' => 'cyan', 'roles' => ['pharmacist']],
    'user-tags' => ['icon' => 'fa-tags', 'label' => 'แท็กลูกค้า', 'url' => '/user-tags', 'page' => 'user-tags', 'color' => 'sky', 'roles' => ['pharmacist']],
    
    // ==================== Patient & Journey - Membership (all staff) ====================
    'members' => ['icon' => 'fa-id-card', 'label' => 'จัดการสมาชิก', 'url' => '/members', 'page' => 'members', 'color' => 'rose'],
    'rewards' => ['icon' => 'fa-gift', 'label' => 'รางวัลแลกแต้ม', 'url' => '/admin-rewards', 'page' => 'admin-rewards', 'color' => 'fuchsia'],
    'points-settings' => ['icon' => 'fa-coins', 'label' => 'ตั้งค่าแต้ม', 'url' => '/admin-points-settings', 'page' => 'admin-points-settings', 'color' => 'yellow'],
    
    // ==================== Patient & Journey - Care Journey ====================
    'broadcast' => ['icon' => 'fa-paper-plane', 'label' => 'บรอดแคสต์', 'url' => '/broadcast', 'page' => 'broadcast', 'color' => 'purple', 'roles' => ['admin', 'marketing']],
    'broadcast-catalog' => ['icon' => 'fa-layer-group', 'label' => 'แคตตาล็อก', 'url' => '/broadcast-catalog-v2', 'page' => 'broadcast-catalog-v2', 'color' => 'violet', 'roles' => ['admin', 'marketing']],
    'drip-campaigns' => ['icon' => 'fa-water', 'label' => 'Drip Campaign', 'url' => '/drip-campaigns', 'page' => 'drip-campaigns', 'color' => 'blue', 'roles' => ['admin', 'marketing']],
    'templates' => ['icon' => 'fa-file-alt', 'label' => 'Templates', 'url' => '/templates', 'page' => 'templates', 'color' => 'slate', 'roles' => ['admin', 'marketing']],
    
    // ==================== Patient & Journey - Digital Front Door ====================
    'rich-menu' => ['icon' => 'fa-th-large', 'label' => 'Rich Menu', 'url' => '/rich-menu', 'page' => 'rich-menu', 'color' => 'teal', 'roles' => ['admin', 'marketing']],
    'dynamic-rich-menu' => ['icon' => 'fa-random', 'label' => 'Dynamic Rich Menu', 'url' => '/dynamic-rich-menu', 'page' => 'dynamic-rich-menu', 'color' => 'cyan', 'roles' => ['admin', 'marketing']],
    'liff-settings' => ['icon' => 'fa-mobile-screen', 'label' => 'ตั้งค่า LIFF', 'url' => '/liff-settings', 'page' => 'liff-settings', 'color' => 'lime', 'roles' => ['admin', 'marketing']],
    
    // ==================== Supply & Revenue - Billing & Orders ====================
    'orders' => ['icon' => 'fa-receipt', 'label' => 'ออเดอร์', 'url' => '/shop/orders', 'page' => 'orders', 'badge' => $pendingOrders, 'badgeColor' => 'yellow', 'color' => 'orange', 'roles' => ['admin', 'staff']],
    'promotions' => ['icon' => 'fa-star', 'label' => 'โปรโมชั่น', 'url' => '/shop/promotions', 'page' => 'promotions', 'color' => 'amber', 'roles' => ['admin', 'staff']],
    
    // ==================== Supply & Revenue - Inventory ====================
    'products' => ['icon' => 'fa-box', 'label' => 'สินค้า', 'url' => '/shop/products', 'page' => 'products', 'color' => 'blue', 'roles' => ['admin', 'pharmacist']],
    'categories' => ['icon' => 'fa-folder', 'label' => 'หมวดหมู่', 'url' => '/shop/categories', 'page' => 'categories', 'color' => 'lime', 'roles' => ['admin', 'pharmacist']],
    'stock-adjustment' => ['icon' => 'fa-sliders-h', 'label' => 'ปรับสต็อก', 'url' => '/inventory/stock-adjustment', 'page' => 'stock-adjustment', 'color' => 'indigo', 'roles' => ['admin', 'pharmacist']],
    'stock-movements' => ['icon' => 'fa-exchange-alt', 'label' => 'ประวัติเคลื่อนไหว', 'url' => '/inventory/stock-movements', 'page' => 'stock-movements', 'color' => 'sky', 'roles' => ['admin', 'pharmacist']],
    'low-stock' => ['icon' => 'fa-exclamation-triangle', 'label' => 'สินค้าใกล้หมด', 'url' => '/inventory/low-stock', 'page' => 'low-stock', 'color' => 'red', 'roles' => ['admin', 'pharmacist']],
    'product-units' => ['icon' => 'fa-balance-scale', 'label' => 'หน่วยสินค้า', 'url' => '/inventory/product-units', 'page' => 'product-units', 'color' => 'emerald', 'roles' => ['admin', 'pharmacist']],
    'sync' => ['icon' => 'fa-sync', 'label' => 'Sync สินค้า', 'url' => '/sync-dashboard', 'page' => 'sync-dashboard', 'color' => 'sky', 'roles' => ['admin', 'owner']],
    
    // ==================== Supply & Revenue - Procurement ====================
    'purchase-orders' => ['icon' => 'fa-file-invoice', 'label' => 'ใบสั่งซื้อ (PO)', 'url' => '/inventory/purchase-orders', 'page' => 'purchase-orders', 'color' => 'violet', 'roles' => ['admin', 'owner']],
    'goods-receive' => ['icon' => 'fa-truck-loading', 'label' => 'รับสินค้า (GR)', 'url' => '/inventory/goods-receive', 'page' => 'goods-receive', 'color' => 'teal', 'roles' => ['admin', 'owner']],
    'suppliers' => ['icon' => 'fa-truck', 'label' => 'Suppliers', 'url' => '/inventory/suppliers', 'page' => 'suppliers', 'color' => 'slate', 'roles' => ['admin', 'owner']],
    
    // ==================== Facility Setup - Facility Profile ====================
    'shop-settings' => ['icon' => 'fa-store', 'label' => 'ข้อมูลสถานพยาบาล', 'url' => '/shop/settings', 'page' => 'settings', 'color' => 'emerald', 'roles' => ['admin', 'owner']],
    
    // ==================== Facility Setup - Staff & Roles ====================
    'admin-users' => ['icon' => 'fa-users-cog', 'label' => 'บุคลากร & สิทธิ์', 'url' => '/admin-users2', 'page' => 'admin-users2', 'color' => 'indigo', 'roles' => ['owner', 'admin']],
    
    // ==================== Facility Setup - Integrations ====================
    'line-accounts' => ['icon' => 'fa-layer-group', 'label' => 'บัญชี LINE', 'url' => '/line-accounts', 'page' => 'line-accounts', 'color' => 'green', 'roles' => ['owner', 'admin', 'tech']],
    'telegram' => ['icon' => 'fab fa-telegram', 'label' => 'Telegram', 'url' => '/telegram', 'page' => 'telegram', 'color' => 'blue', 'roles' => ['owner', 'admin', 'tech']],
    'ai-settings' => ['icon' => 'fa-key', 'label' => 'ตั้งค่า API Key', 'url' => '/ai-settings', 'page' => 'ai-settings', 'color' => 'violet', 'roles' => ['owner', 'admin', 'tech']],
    
    // ==================== Facility Setup - Consent & PDPA ====================
    'consent-management' => ['icon' => 'fa-shield-alt', 'label' => 'Consent & PDPA', 'url' => '/consent-management', 'page' => 'consent-management', 'color' => 'rose', 'roles' => ['owner', 'admin']],
    
    // ==================== Facility Setup - Reports ====================
    'scheduled-reports' => ['icon' => 'fa-calendar-alt', 'label' => 'รายงานอัตโนมัติ', 'url' => '/scheduled-reports', 'page' => 'scheduled-reports', 'color' => 'amber', 'roles' => ['owner', 'admin']],
];

// Get user's quick access preferences
$userQuickAccess = ['messages', 'orders', 'products', 'broadcast']; // defaults
$adminUserId = $_SESSION['admin_user']['id'] ?? null;
if ($adminUserId) {
    try {
        $stmt = $db->prepare("SELECT menu_key FROM admin_quick_access WHERE admin_user_id = ? ORDER BY sort_order");
        $stmt->execute([$adminUserId]);
        $userMenuKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($userMenuKeys)) {
            $userQuickAccess = $userMenuKeys;
        }
    } catch (Exception $e) {
        // Table doesn't exist yet, use defaults
    }
}

// Build quick access items from user preferences (filtered by role access)
$quickAccessItems = [];
foreach ($userQuickAccess as $key) {
    if (isset($quickAccessMenus[$key])) {
        $menuItem = $quickAccessMenus[$key];
        // Only add if user has access based on roles
        if (hasMenuAccess($menuItem)) {
            $quickAccessItems[] = $menuItem;
        }
    }
}

// Menu structure with collapsible submenus - Complete Menu Structure
// จัดกลุ่มเมนูทั้งหมดจากระบบเดิมเข้า 5 กลุ่มใหม่
$menuSections = [
    'quick' => [
        'title' => '⚡ Quick Access',
        'highlight' => true,
        'customizable' => true,
        'items' => $quickAccessItems
    ],
    'main' => [
        'title' => '',
        'items' => [
            ['icon' => 'fa-th-large', 'label' => 'Dashboard', 'url' => '/admin/', 'page' => 'index'],
        ]
    ],
    
    // ==================== กลุ่ม 1: Insights & Overview ====================
    // รวม: Dashboard, รายงาน & สถิติ, Activity Logs
    'insights' => [
        'title' => '📊 Insights & Overview',
        'icon' => 'fa-chart-line',
        'collapsible' => true,
        'items' => [
            // Dashboard
            ['icon' => 'fa-chart-line', 'label' => 'Executive Dashboard', 'url' => '/executive-dashboard', 'page' => 'executive-dashboard', 'roles' => ['owner', 'admin']],
            // รายงาน & สถิติ
            ['icon' => 'fa-chart-pie', 'label' => 'สถิติทั่วไป', 'url' => '/analytics', 'page' => 'analytics', 'roles' => ['owner', 'admin']],
            ['icon' => 'fa-chart-area', 'label' => 'วิเคราะห์ขั้นสูง', 'url' => '/advanced-analytics', 'page' => 'advanced-analytics', 'roles' => ['owner', 'admin']],
            ['icon' => 'fa-users-viewfinder', 'label' => 'CRM Analytics', 'url' => '/crm-analytics', 'page' => 'crm-analytics', 'roles' => ['owner', 'admin']],
            ['icon' => 'fa-calendar-alt', 'label' => 'รายงานอัตโนมัติ', 'url' => '/scheduled-reports', 'page' => 'scheduled-reports', 'roles' => ['owner', 'admin']],
            ['icon' => 'fa-link', 'label' => 'ติดตามลิงก์', 'url' => '/link-tracking', 'page' => 'link-tracking', 'roles' => ['owner', 'admin', 'marketing']],
            // Clinical Analytics
            ['icon' => 'fa-stethoscope', 'label' => 'Triage Analytics', 'url' => '/triage-analytics', 'page' => 'triage-analytics', 'roles' => ['pharmacist', 'owner']],
            ['icon' => 'fa-pills', 'label' => 'ยาตีกัน', 'url' => '/drug-interactions', 'page' => 'drug-interactions', 'roles' => ['pharmacist', 'owner']],
            // Audit
            ['icon' => 'fa-history', 'label' => 'Activity Logs', 'url' => '/activity-logs', 'page' => 'activity-logs', 'roles' => ['owner']],
        ]
    ],
    
    // ==================== กลุ่ม 2: Clinical Station ====================
    // รวม: แชท & ลูกค้า (บางส่วน), เภสัชกร & AI, AI Tools
    'clinical' => [
        'title' => '🏥 Clinical Station',
        'icon' => 'fa-user-md',
        'collapsible' => true,
        'items' => [
            // แชท & การสื่อสาร
            ['icon' => 'fa-inbox', 'label' => 'กล่องข้อความ', 'url' => '/inbox', 'page' => 'inbox', 'badge' => $unreadMessages],
            ['icon' => 'fa-video', 'label' => 'Video Call', 'url' => '/video-call-pro', 'page' => 'video-call-pro', 'roles' => ['pharmacist', 'staff']],
            ['icon' => 'fa-robot', 'label' => 'ตอบอัตโนมัติ', 'url' => '/auto-reply', 'page' => 'auto-reply'],
            // เภสัชกร
            ['icon' => 'fa-user-md', 'label' => 'Dashboard เภสัชกร', 'url' => '/pharmacist-dashboard', 'page' => 'pharmacist-dashboard', 'roles' => ['pharmacist', 'owner', 'admin']],
            ['icon' => 'fa-user-nurse', 'label' => 'จัดการเภสัชกร', 'url' => '/pharmacists', 'page' => 'pharmacists', 'roles' => ['owner', 'admin']],
            // AI Tools
            ['icon' => 'fa-comments', 'label' => 'AI ตอบแชท', 'url' => '/ai-chat-settings', 'page' => 'ai-chat-settings', 'roles' => ['pharmacist', 'admin']],
            ['icon' => 'fa-wand-magic-sparkles', 'label' => 'AI Studio', 'url' => '/ai-studio', 'page' => 'ai-studio', 'roles' => ['pharmacist', 'admin']],
            ['icon' => 'fa-image', 'label' => 'AI สร้างรูป', 'url' => '/ai-image', 'page' => 'ai-image', 'roles' => ['pharmacist', 'admin', 'marketing']],
            ['icon' => 'fa-cog', 'label' => 'ตั้งค่า AI เภสัช', 'url' => '/ai-pharmacy-settings', 'page' => 'ai-pharmacy-settings', 'roles' => ['pharmacist', 'owner']],
        ]
    ],
    
    // ==================== กลุ่ม 3: Patient & Journey ====================
    // รวม: แชท & ลูกค้า (ข้อมูลลูกค้า), สมาชิก & แต้ม, บรอดแคสต์, เครื่องมือ LINE
    'patient' => [
        'title' => '👤 Patient & Journey',
        'icon' => 'fa-users',
        'collapsible' => true,
        'items' => [
            // ข้อมูลลูกค้า
            ['icon' => 'fa-users', 'label' => 'รายชื่อลูกค้า', 'url' => '/users', 'page' => 'users'],
            ['icon' => 'fa-tags', 'label' => 'แท็กลูกค้า', 'url' => '/user-tags', 'page' => 'user-tags'],
            ['icon' => 'fa-layer-group', 'label' => 'กลุ่มลูกค้า (Segments)', 'url' => '/customer-segments', 'page' => 'customer-segments', 'roles' => ['admin', 'marketing']],
            // สมาชิก & แต้ม
            ['icon' => 'fa-id-card', 'label' => 'จัดการสมาชิก', 'url' => '/members', 'page' => 'members'],
            ['icon' => 'fa-gift', 'label' => 'รางวัลแลกแต้ม', 'url' => '/admin-rewards', 'page' => 'admin-rewards'],
            ['icon' => 'fa-coins', 'label' => 'ตั้งค่าแต้ม', 'url' => '/admin-points-settings', 'page' => 'admin-points-settings', 'roles' => ['owner', 'admin']],
            ['icon' => 'fa-calendar-check', 'label' => 'นัดหมาย', 'url' => '/appointments-admin', 'page' => 'appointments-admin'],
            // บรอดแคสต์
            ['icon' => 'fa-paper-plane', 'label' => 'ส่งข้อความ', 'url' => '/broadcast', 'page' => 'broadcast', 'roles' => ['admin', 'marketing']],
            ['icon' => 'fa-images', 'label' => 'แคตตาล็อก', 'url' => '/broadcast-catalog-v2', 'page' => 'broadcast-catalog-v2', 'roles' => ['admin', 'marketing']],
            ['icon' => 'fa-chart-bar', 'label' => 'สถิติบรอดแคสต์', 'url' => '/broadcast-stats', 'page' => 'broadcast-stats', 'roles' => ['admin', 'marketing']],
            ['icon' => 'fa-water', 'label' => 'Drip Campaign', 'url' => '/drip-campaigns', 'page' => 'drip-campaigns', 'roles' => ['admin', 'marketing']],
            // เครื่องมือ LINE
            ['icon' => 'fa-th-large', 'label' => 'Rich Menu', 'url' => '/rich-menu', 'page' => 'rich-menu', 'roles' => ['admin', 'marketing']],
            ['icon' => 'fa-random', 'label' => 'Dynamic Rich Menu', 'url' => '/dynamic-rich-menu', 'page' => 'dynamic-rich-menu', 'roles' => ['admin', 'marketing']],
            ['icon' => 'fa-puzzle-piece', 'label' => 'Flex Builder', 'url' => '/flex-builder', 'page' => 'flex-builder', 'roles' => ['admin', 'marketing']],
            ['icon' => 'fa-hand-wave', 'label' => 'ข้อความต้อนรับ', 'url' => '/welcome-settings', 'page' => 'welcome-settings', 'roles' => ['admin', 'marketing']],
            ['icon' => 'fa-clock', 'label' => 'ตั้งเวลาส่ง', 'url' => '/scheduled', 'page' => 'scheduled', 'roles' => ['admin', 'marketing']],
            ['icon' => 'fa-users-rectangle', 'label' => 'กลุ่ม LINE', 'url' => '/line-groups', 'page' => 'line-groups', 'roles' => ['admin', 'marketing']],
        ]
    ],
    
    // ==================== กลุ่ม 4: Supply & Revenue ====================
    // รวม: ร้านค้า, คลังสินค้า
    'supply' => [
        'title' => '📦 Supply & Revenue',
        'icon' => 'fa-warehouse',
        'collapsible' => true,
        'items' => [
            // ร้านค้า - ออเดอร์
            ['icon' => 'fa-store', 'label' => 'ภาพรวมร้านค้า', 'url' => '/shop/', 'page' => 'shop-index', 'folder' => 'shop', 'roles' => ['admin', 'staff']],
            ['icon' => 'fa-receipt', 'label' => 'ออเดอร์', 'url' => '/shop/orders', 'page' => 'orders', 'badge' => $pendingOrders, 'badgeColor' => 'yellow', 'folder' => 'shop'],
            ['icon' => 'fa-file-invoice-dollar', 'label' => 'รอตรวจสลิป', 'url' => '/shop/orders?filter=pending_slip', 'page' => 'orders-slip', 'badge' => $pendingSlips, 'badgeColor' => 'orange', 'folder' => 'shop'],
            ['icon' => 'fa-percent', 'label' => 'โปรโมชั่น', 'url' => '/shop/promotions', 'page' => 'promotions', 'folder' => 'shop', 'roles' => ['admin']],
            ['icon' => 'fa-sync', 'label' => 'Sync สินค้า', 'url' => '/sync-dashboard', 'page' => 'sync-dashboard', 'roles' => ['admin', 'owner']],
            ['icon' => 'fa-cog', 'label' => 'ตั้งค่าร้าน', 'url' => '/shop/settings', 'page' => 'settings', 'folder' => 'shop', 'roles' => ['admin', 'owner']],
            ['icon' => 'fa-mobile-screen', 'label' => 'ตั้งค่า LIFF Shop', 'url' => '/shop/liff-shop-settings', 'page' => 'liff-shop-settings', 'folder' => 'shop', 'roles' => ['admin', 'owner']],
            // คลังสินค้า
            ['icon' => 'fa-box', 'label' => 'สินค้า', 'url' => '/shop/products', 'page' => 'products', 'folder' => 'shop'],
            ['icon' => 'fa-folder', 'label' => 'หมวดหมู่', 'url' => '/shop/categories', 'page' => 'categories', 'folder' => 'shop'],
            ['icon' => 'fa-truck', 'label' => 'Suppliers', 'url' => '/inventory/suppliers', 'page' => 'suppliers', 'folder' => 'inventory', 'roles' => ['admin', 'owner']],
            ['icon' => 'fa-file-invoice', 'label' => 'ใบสั่งซื้อ (PO)', 'url' => '/inventory/purchase-orders', 'page' => 'purchase-orders', 'folder' => 'inventory', 'roles' => ['admin', 'owner']],
            ['icon' => 'fa-truck-loading', 'label' => 'รับสินค้า (GR)', 'url' => '/inventory/goods-receive', 'page' => 'goods-receive', 'folder' => 'inventory', 'roles' => ['admin', 'owner']],
            ['icon' => 'fa-sliders-h', 'label' => 'ปรับสต็อก', 'url' => '/inventory/stock-adjustment', 'page' => 'stock-adjustment', 'folder' => 'inventory', 'roles' => ['admin', 'pharmacist']],
            ['icon' => 'fa-exchange-alt', 'label' => 'ประวัติเคลื่อนไหว', 'url' => '/inventory/stock-movements', 'page' => 'stock-movements', 'folder' => 'inventory', 'roles' => ['admin', 'pharmacist']],
            ['icon' => 'fa-exclamation-triangle', 'label' => 'สินค้าใกล้หมด', 'url' => '/inventory/low-stock', 'page' => 'low-stock', 'folder' => 'inventory'],
            ['icon' => 'fa-balance-scale', 'label' => 'หน่วยสินค้า', 'url' => '/inventory/product-units', 'page' => 'product-units', 'folder' => 'inventory', 'roles' => ['admin']],
            ['icon' => 'fa-file-alt', 'label' => 'รายงานคลัง', 'url' => '/inventory/reports', 'page' => 'inventory-reports', 'folder' => 'inventory', 'roles' => ['admin', 'owner']],
        ]
    ],
    
    // ==================== กลุ่ม 5: Facility Setup ====================
    // รวม: ตั้งค่าระบบ
    'facility' => [
        'title' => '⚙️ Facility Setup',
        'icon' => 'fa-cog',
        'collapsible' => true,
        'items' => [
            // บัญชี & การเชื่อมต่อ
            ['icon' => 'fab fa-line', 'label' => 'บัญชี LINE', 'url' => '/line-accounts', 'page' => 'line-accounts', 'roles' => ['owner', 'admin', 'tech']],
            ['icon' => 'fa-mobile-screen', 'label' => 'ตั้งค่า LIFF', 'url' => '/liff-settings', 'page' => 'liff-settings', 'roles' => ['admin', 'tech']],
            ['icon' => 'fa-shield-alt', 'label' => 'Consent & PDPA', 'url' => '/consent-management', 'page' => 'consent-management', 'roles' => ['owner', 'admin']],
            ['icon' => 'fa-bell', 'label' => 'การแจ้งเตือน', 'url' => '/notification-settings', 'page' => 'notification-settings', 'roles' => ['admin']],
            ['icon' => 'fab fa-telegram', 'label' => 'Telegram', 'url' => '/telegram', 'page' => 'telegram', 'roles' => ['owner', 'admin', 'tech']],
            // API & ระบบ
            ['icon' => 'fa-key', 'label' => 'ตั้งค่า API Key', 'url' => '/ai-settings', 'page' => 'ai-settings', 'roles' => ['owner', 'admin', 'tech']],
            // ผู้ใช้ระบบ
            ['icon' => 'fa-users-cog', 'label' => 'ผู้ใช้ระบบ', 'url' => '/admin-users2', 'page' => 'admin-users2', 'roles' => ['owner', 'admin']],
            // ช่วยเหลือ
            ['icon' => 'fa-question-circle', 'label' => 'ช่วยเหลือ', 'url' => '/help', 'page' => 'help'],
        ]
    ],
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#06C755">
    <meta name="base-url" content="<?= $baseUrl ?>">
    <title>Re-ya Pharmachy</title>
    
    <!-- Favicon & Icons -->
    <link rel="icon" type="image/png" href="/assets/images/3.png?v=2">
    <link rel="shortcut icon" type="image/png" href="/assets/images/3.png?v=2">
    <link rel="apple-touch-icon" href="/assets/images/3.png?v=2">
    <link rel="apple-touch-icon-precomposed" href="/assets/images/3.png?v=2">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #06C755;
            --primary-dark: #05a648;
            --sidebar-width: 260px;
        }
        
        body { 
            font-family: 'Inter', 'Noto Sans Thai', sans-serif; 
            background: #f1f5f9;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: #ffffff;
            border-right: 1px solid #e2e8f0;
            transition: transform 0.3s ease;
        }
        
        .sidebar-brand {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }
        
        /* Bot Selector */
        .bot-selector {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .bot-card {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            background: #f8fafc;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        
        .bot-card:hover { background: #f1f5f9; border-color: #e2e8f0; }
        
        .bot-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .bot-avatar img { width: 100%; height: 100%; object-fit: cover; }
        
        /* Menu */
        .menu-section { padding: 12px 12px 4px; }
        .menu-section-title {
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 0 12px 8px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            margin: 2px 0;
            border-radius: 10px;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s ease;
            text-decoration: none;
            position: relative;
        }
        
        .menu-item:hover { background: #f1f5f9; color: #334155; }
        .menu-item:hover .menu-icon { color: var(--primary); }
        
        .menu-item.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(6, 199, 85, 0.25);
        }
        
        .menu-item.active .menu-icon { color: white; }
        .menu-item.active:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, #048a3d 100%); }
        
        .menu-icon {
            width: 20px;
            margin-right: 12px;
            font-size: 14px;
            color: #94a3b8;
            text-align: center;
        }
        
        .menu-badge {
            margin-left: auto;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: 600;
            border-radius: 10px;
            background: #ef4444;
            color: white;
        }
        
        .menu-badge.yellow { background: #f59e0b; }
        .menu-badge.blue { background: #3b82f6; }
        .menu-badge.green { background: var(--primary); }
        .menu-badge.orange { background: #f97316; }
        
        /* Collapsible Menu Parent */
        .menu-parent {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            margin: 2px 8px;
            border-radius: 10px;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }
        
        .menu-parent:hover { background: #f1f5f9; color: #1e293b; }
        .menu-parent:hover .menu-parent-icon { color: var(--primary); }
        
        .menu-parent-icon {
            width: 20px;
            margin-right: 10px;
            font-size: 14px;
            color: #64748b;
            text-align: center;
        }
        
        .menu-parent-label { flex: 1; }
        
        .menu-arrow {
            font-size: 10px;
            color: #94a3b8;
            transition: transform 0.2s ease;
        }
        
        .menu-arrow.rotate { transform: rotate(180deg); }
        
        /* Submenu */
        .menu-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.25s ease-out;
            padding-left: 8px;
        }
        
        .menu-submenu.open {
            max-height: 500px;
            transition: max-height 0.3s ease-in;
        }
        
        .menu-submenu .menu-item {
            padding-left: 38px;
            font-size: 12.5px;
        }
        
        .menu-submenu .menu-icon {
            font-size: 12px;
            width: 16px;
            margin-right: 10px;
        }
        
        /* Quick Access Highlight */
        .quick-access-section {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfeff 100%);
            border-radius: 12px;
            margin: 8px 12px;
            padding: 12px 8px;
            border: 1px solid #d1fae5;
        }
        
        .quick-access-section .menu-section-title {
            color: #059669;
            font-size: 11px;
        }
        
        .quick-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 8px;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }
        
        .quick-item:hover { transform: translateY(-2px); }
        
        .quick-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            margin-bottom: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.2s;
        }
        
        .quick-item:hover .quick-icon { transform: scale(1.1); }
        
        .quick-icon.green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .quick-icon.orange { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
        .quick-icon.blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .quick-icon.purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .quick-icon.pink { background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); }
        .quick-icon.cyan { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); }
        .quick-icon.teal { background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); }
        .quick-icon.amber { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .quick-icon.emerald { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .quick-icon.sky { background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); }
        .quick-icon.violet { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .quick-icon.rose { background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%); }
        .quick-icon.fuchsia { background: linear-gradient(135deg, #d946ef 0%, #c026d3 100%); }
        .quick-icon.lime { background: linear-gradient(135deg, #84cc16 0%, #65a30d 100%); }
        .quick-icon.slate { background: linear-gradient(135deg, #64748b 0%, #475569 100%); }
        .quick-icon.indigo { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }
        .quick-icon.red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .quick-icon.yellow { background: linear-gradient(135deg, #eab308 0%, #ca8a04 100%); }
        
        .quick-label {
            font-size: 11px;
            font-weight: 600;
            color: #374151;
            text-align: center;
        }
        
        .quick-badge {
            position: absolute;
            top: 4px;
            right: 4px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            font-size: 10px;
            font-weight: 700;
            border-radius: 9px;
            background: #ef4444;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(239,68,68,0.4);
        }
        
        .quick-badge.yellow { background: #f59e0b; box-shadow: 0 2px 4px rgba(245,158,11,0.4); }
        
        /* Dropdown */
        .dropdown-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.12);
            border: 1px solid #e2e8f0;
            z-index: 100;
            display: none;
            max-height: 280px;
            overflow-y: auto;
        }
        
        .dropdown-menu.open { display: block; }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            transition: background 0.15s;
            cursor: pointer;
        }
        
        .dropdown-item:hover { background: #f8fafc; }
        .dropdown-item.active { background: #ecfdf5; }
        .dropdown-item:first-child { border-radius: 12px 12px 0 0; }
        .dropdown-item:last-child { border-radius: 0 0 12px 12px; }
        
        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }
        
        .top-header {
            background: white;
            padding: 12px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 30;
        }
        
        .page-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .header-actions { display: flex; align-items: center; gap: 8px; }
        
        .header-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            color: #64748b;
            transition: all 0.15s;
            cursor: pointer;
            position: relative;
            border: 1px solid transparent;
        }
        
        .header-btn:hover { background: #f1f5f9; color: #334155; border-color: #e2e8f0; }
        
        .header-btn .badge {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            background: #ef4444;
            color: white;
            font-size: 10px;
            font-weight: 600;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-menu {
            display: flex;
            align-items: center;
            padding: 6px 12px 6px 6px;
            background: #f8fafc;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.15s;
            border: 1px solid transparent;
        }
        
        .user-menu:hover { background: #f1f5f9; border-color: #e2e8f0; }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 13px;
        }
        
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }
        
        /* Mobile */
        @media (max-width: 768px) {
            /* Sidebar - hidden by default, slide in from left */
            .sidebar {
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                bottom: 0 !important;
                width: 280px !important;
                max-width: 85vw !important;
                height: 100vh !important;
                z-index: 1000 !important;
                transform: translateX(-100%) !important;
                display: flex !important;
                flex-direction: column !important;
                background: #fff !important;
                border-right: 1px solid #e2e8f0 !important;
                transition: transform 0.3s ease !important;
            }
            .sidebar.open { 
                transform: translateX(0) !important; 
            }
            
            /* Sidebar nav scrollable */
            .sidebar nav {
                flex: 1 !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                -webkit-overflow-scrolling: touch !important;
                padding-bottom: 100px !important;
                min-height: 0 !important;
            }
            
            /* Ensure all submenus can expand fully on mobile */
            .menu-submenu.open {
                max-height: none !important;
            }
            
            /* Dark overlay when sidebar open */
            .mobile-overlay {
                display: none !important;
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                background: rgba(0,0,0,0.5) !important;
                z-index: 999 !important;
            }
            .mobile-overlay.open { 
                display: block !important; 
            }
            
            /* Top Header - sticky at top */
            .top-header {
                position: sticky !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                height: 56px !important;
                min-height: 56px !important;
                z-index: 100 !important;
                padding: 0 12px !important;
                background: white !important;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
            }
            
            .page-title {
                font-size: 15px !important;
                max-width: 140px !important;
                white-space: nowrap !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }
            
            /* Main content - full width */
            .main-content {
                width: 100% !important;
                min-height: 100vh !important;
                display: flex !important;
                flex-direction: column !important;
            }
            
            /* Content area - scrollable */
            .content-area { 
                flex: 1 !important;
                padding: 16px !important;
                padding-bottom: 80px !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                -webkit-overflow-scrolling: touch !important;
            }
            
            /* Header buttons smaller on mobile */
            .header-btn {
                width: 36px !important;
                height: 36px !important;
                flex-shrink: 0 !important;
            }
            
            .header-actions {
                gap: 6px !important;
            }
            
            .user-menu {
                padding: 4px 8px 4px 4px !important;
            }
            
            .user-avatar {
                width: 28px !important;
                height: 28px !important;
                font-size: 11px !important;
            }
            
            /* Quick access grid on mobile */
            .quick-access-section {
                margin: 8px !important;
                padding: 10px 6px !important;
            }
            
            .quick-icon {
                width: 38px !important;
                height: 38px !important;
                font-size: 15px !important;
            }
            
            .quick-label {
                font-size: 10px !important;
            }
            
            /* Menu items touch-friendly */
            .menu-item {
                padding: 12px !important;
                min-height: 44px !important;
            }
            
            .menu-parent {
                padding: 12px !important;
                min-height: 44px !important;
            }
            
            /* Bot selector */
            .bot-selector {
                padding: 10px 12px !important;
                flex-shrink: 0 !important;
            }
            
            .bot-card {
                padding: 8px 10px !important;
            }
            
            .bot-avatar {
                width: 36px !important;
                height: 36px !important;
            }
            
            /* Sidebar brand & footer */
            .sidebar-brand {
                flex-shrink: 0 !important;
            }
            
            .sidebar > .p-4 {
                flex-shrink: 0 !important;
            }
        }
        
        /* Extra small screens */
        @media (max-width: 375px) {
            .page-title {
                font-size: 14px !important;
                max-width: 100px !important;
            }
            
            .header-btn {
                width: 32px !important;
                height: 32px !important;
            }
            
            .header-actions {
                gap: 4px !important;
            }
            
            .quick-access-section .grid {
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 2px !important;
            }
            
            .quick-icon {
                width: 34px !important;
                height: 34px !important;
                font-size: 14px !important;
            }
        }
        
        /* Safe area for notched phones (iPhone X+) */
        @supports (padding: max(0px)) {
            @media (max-width: 768px) {
                .top-header {
                    padding-top: env(safe-area-inset-top) !important;
                    padding-left: max(12px, env(safe-area-inset-left)) !important;
                    padding-right: max(12px, env(safe-area-inset-right)) !important;
                }
                
                .content-area {
                    padding-bottom: max(80px, calc(20px + env(safe-area-inset-bottom))) !important;
                }
                
                .sidebar {
                    padding-top: env(safe-area-inset-top) !important;
                    padding-bottom: env(safe-area-inset-bottom) !important;
                }
            }
        }
    </style>
</head>
<body>
    <div id="mobileOverlay" class="mobile-overlay" onclick="toggleSidebar()"></div>
    
    <div class="flex h-screen md:h-screen">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar flex flex-col">
            <!-- Brand -->
            <div class="sidebar-brand flex items-center">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fab fa-line text-white text-xl"></i>
                </div>
                <div class="ml-3 flex-1">
                    <div class="font-bold text-white text-sm"><?= APP_NAME ?></div>
                    <div class="text-xs text-white/70">Admin Panel v3.0</div>
                </div>
                <button onclick="toggleSidebar()" class="md:hidden text-white/70 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Bot Selector -->
            <?php if (!empty($lineAccounts)): ?>
            <div class="bot-selector relative">
                <div class="bot-card" onclick="toggleBotDropdown()">
                    <div class="bot-avatar">
                        <?php if ($currentBot && !empty($currentBot['picture_url'])): ?>
                        <img src="<?= htmlspecialchars($currentBot['picture_url']) ?>" alt="">
                        <?php else: ?>
                        <i class="fab fa-line"></i>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 ml-3 min-w-0">
                        <div class="text-sm font-semibold text-gray-800 truncate"><?= htmlspecialchars($currentBot['name'] ?? 'Select Bot') ?></div>
                        <div class="text-xs text-gray-400 truncate"><?= htmlspecialchars($currentBot['basic_id'] ?? '') ?></div>
                    </div>
                    <i class="fas fa-chevron-down text-gray-400 text-xs ml-2"></i>
                </div>
                <div id="botDropdown" class="dropdown-menu">
                    <?php foreach ($lineAccounts as $acc): ?>
                    <a href="?switch_bot=<?= $acc['id'] ?>" class="dropdown-item <?= ($currentBot && $currentBot['id'] == $acc['id']) ? 'active' : '' ?>">
                        <div class="bot-avatar" style="width:32px;height:32px;font-size:14px;">
                            <?php if (!empty($acc['picture_url'])): ?>
                            <img src="<?= htmlspecialchars($acc['picture_url']) ?>" alt="">
                            <?php else: ?>
                            <i class="fab fa-line"></i>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3 flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-700 truncate"><?= htmlspecialchars($acc['name']) ?></div>
                        </div>
                        <?php if ($acc['is_default']): ?>
                        <span class="text-xs bg-green-100 text-green-600 px-2 py-0.5 rounded-full">Default</span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Navigation -->
            <nav class="flex-1 overflow-y-auto py-2">
                <?php foreach ($menuSections as $sectionKey => $section): ?>
                
                <?php if (!empty($section['highlight'])): ?>
                <!-- Quick Access Section -->
                <?php 
                // Filter quick access items by role
                $accessibleQuickItems = array_filter($section['items'], function($item) {
                    return hasMenuAccess($item);
                });
                ?>
                <?php if (!empty($accessibleQuickItems)): ?>
                <div class="quick-access-section">
                    <div class="flex items-center justify-between mb-2">
                        <div class="menu-section-title mb-0"><?= $section['title'] ?></div>
                        <?php if (!empty($section['customizable'])): ?>
                        <a href="<?= $baseUrl ?>quick-access-settings.php" class="text-xs text-gray-400 hover:text-green-600" title="ตั้งค่า Quick Access">
                            <i class="fas fa-cog"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="grid grid-cols-4 gap-1">
                        <?php foreach ($accessibleQuickItems as $item): 
                            $itemUrl = $baseUrl . $item['url'];
                            // Handle shop folder URLs
                            if ($isShop && (strpos($item['url'], '/shop/') !== false || strpos($item['url'], 'shop/') === 0)) {
                                $itemUrl = basename($item['url']);
                            }
                            // Handle inventory folder URLs
                            if ($isInventory && (strpos($item['url'], '/inventory/') !== false || strpos($item['url'], 'inventory/') === 0)) {
                                $itemUrl = basename($item['url']);
                            }
                        ?>
                        <a href="<?= $itemUrl ?>" class="quick-item">
                            <div class="quick-icon <?= $item['color'] ?? 'green' ?>">
                                <i class="fas <?= $item['icon'] ?>"></i>
                            </div>
                            <span class="quick-label"><?= $item['label'] ?></span>
                            <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                            <span class="quick-badge <?= $item['badgeColor'] ?? '' ?>"><?= $item['badge'] > 99 ? '99+' : $item['badge'] ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <!-- Regular Menu Section -->
                <?php 
                // Filter menu items by role access (Requirements 7.1, 7.2)
                $accessibleItems = array_filter($section['items'], function($item) {
                    return hasMenuAccess($item);
                });
                
                // Skip rendering this group if no accessible items (Requirements 7.2)
                if (empty($accessibleItems)) continue;
                
                // Check if any accessible item in this section is active (Requirements 8.3)
                $sectionHasActive = false;
                foreach ($accessibleItems as $item) {
                    $checkActive = false;
                    
                    // Determine which folder this menu item belongs to based on URL
                    $itemIsShopUrl = strpos($item['url'], '/shop/') !== false || strpos($item['url'], 'shop/') === 0;
                    $itemIsInventoryUrl = strpos($item['url'], '/inventory/') !== false || strpos($item['url'], 'inventory/') === 0;
                    
                    if ($itemIsShopUrl) {
                        // Shop folder items - active when in shop folder and page matches
                        $checkActive = $isShop && $currentPage === $item['page'];
                    } elseif ($itemIsInventoryUrl) {
                        // Inventory folder items - active when in inventory folder and page matches
                        $checkActive = $isInventory && $currentPage === $item['page'];
                    } else {
                        // Root level items - active when not in any subfolder and page matches
                        $checkActive = !$isSubfolder && $currentPage === $item['page'];
                    }
                    
                    if ($checkActive) { $sectionHasActive = true; break; }
                }
                $isCollapsible = !empty($section['collapsible']);
                $sectionId = 'menu_' . $sectionKey;
                ?>
                <div class="menu-section">
                    <?php if ($section['title']): ?>
                    <?php if ($isCollapsible): ?>
                    <div class="menu-parent" onclick="toggleSubmenu('<?= $sectionId ?>')">
                        <span class="menu-parent-icon"><i class="fas <?= $section['icon'] ?? 'fa-folder' ?>"></i></span>
                        <span class="menu-parent-label"><?= $section['title'] ?></span>
                        <i class="fas fa-chevron-down menu-arrow <?= $sectionHasActive ? 'rotate' : '' ?>"></i>
                    </div>
                    <?php else: ?>
                    <div class="menu-section-title"><?= $section['title'] ?></div>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <div id="<?= $sectionId ?>" class="menu-submenu <?= $isCollapsible ? ($sectionHasActive ? 'open' : '') : 'open' ?>">
                        <?php foreach ($accessibleItems as $item): 
                            $itemUrl = $baseUrl . $item['url'];
                            $isActive = false;
                            
                            // Determine which folder this menu item belongs to based on URL
                            $itemIsShopUrl = strpos($item['url'], '/shop/') !== false || strpos($item['url'], 'shop/') === 0;
                            $itemIsInventoryUrl = strpos($item['url'], '/inventory/') !== false || strpos($item['url'], 'inventory/') === 0;
                            
                            // Check if active based on URL folder type
                            if ($itemIsShopUrl) {
                                // Shop folder items - active when in shop folder and page matches
                                if ($isShop && $currentPage === $item['page']) {
                                    $isActive = true;
                                    $itemUrl = basename($item['url']); // Use relative URL when in shop folder
                                }
                            } elseif ($itemIsInventoryUrl) {
                                // Inventory folder items - active when in inventory folder and page matches
                                if ($isInventory && $currentPage === $item['page']) {
                                    $isActive = true;
                                    $itemUrl = basename($item['url']); // Use relative URL when in inventory folder
                                }
                            } else {
                                // Root level items - active when not in any subfolder and page matches
                                $isActive = !$isSubfolder && $currentPage === $item['page'];
                            }
                        ?>
                        <a href="<?= $itemUrl ?>" class="menu-item <?= $isActive ? 'active' : '' ?>">
                            <span class="menu-icon"><i class="fas <?= $item['icon'] ?>"></i></span>
                            <?= $item['label'] ?>
                            <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                            <span class="menu-badge <?= $item['badgeColor'] ?? '' ?>"><?= $item['badge'] ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php endforeach; ?>
            </nav>
            
            <!-- Sidebar Footer -->
            <div class="p-4 border-t border-gray-100">
                <div class="flex items-center justify-between text-xs text-gray-400">
                    <span>LINE CRM Pro v3.5</span>
                    <div class="flex items-center gap-2">
                        <a href="<?= $baseUrl ?>video-call-pro.php" class="hover:text-green-500" title="Video Call"><i class="fas fa-video"></i></a>
                        <a href="<?= $baseUrl ?>help.php" class="hover:text-gray-600" title="Help"><i class="fas fa-question-circle"></i></a>
                    </div>
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Header -->
            <header class="top-header">
                <div class="flex items-center">
                    <button onclick="toggleSidebar()" class="md:hidden mr-4 text-gray-500 hover:text-gray-700">
                        <i class="fas fa-bars text-lg"></i>
                    </button>
                    <h1 class="page-title"><?= $pageTitle ?? 'Dashboard' ?></h1>
                </div>
                
                <div class="header-actions">
                    <!-- AI Tools Dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="header-btn ai-tools-btn" title="AI Tools" style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: white;">
                            <i class="fas fa-brain"></i>
                            <i class="fas fa-chevron-down text-xs ml-1"></i>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition
                             class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50">
                            <a href="<?= $baseUrl ?>ai-chat.php" class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 transition">
                                <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                                    <i class="fas fa-comments text-blue-600"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800">AI Chat</div>
                                    <div class="text-xs text-gray-500">คุยกับ AI ทั่วไป</div>
                                </div>
                            </a>
                            <a href="<?= $baseUrl ?>onboarding-assistant.php" class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 transition">
                                <div class="w-8 h-8 rounded-lg bg-purple-100 flex items-center justify-center">
                                    <i class="fas fa-robot text-purple-600"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800">Setup Assistant</div>
                                    <div class="text-xs text-gray-500">ผู้ช่วยตั้งค่าระบบ</div>
                                </div>
                            </a>
                            <a href="<?= $baseUrl ?>ai-settings.php" class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 transition">
                                <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center">
                                    <i class="fas fa-cog text-gray-600"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800">AI Settings</div>
                                    <div class="text-xs text-gray-500">ตั้งค่า API Key</div>
                                </div>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <a href="<?= $baseUrl ?>inbox.php" class="header-btn" title="Inbox (Real-time)">
                        <i class="fas fa-inbox"></i>
                        <?php if ($unreadMessages > 0): ?>
                        <span class="badge"><?= $unreadMessages > 99 ? '99+' : $unreadMessages ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <a href="<?= $baseUrl ?>shop/orders.php" class="header-btn" title="Orders">
                        <i class="fas fa-shopping-bag"></i>
                        <?php if ($pendingOrders > 0): ?>
                        <span class="badge" style="background:#f59e0b"><?= $pendingOrders ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <div class="header-btn" onclick="toggleTheme()" title="Toggle Theme">
                        <i class="fas fa-moon"></i>
                    </div>
                    
                    <!-- User Menu -->
                    <div class="relative">
                        <div class="user-menu" onclick="toggleUserMenu()">
                            <div class="user-avatar">
                                <?= strtoupper(substr($currentUser['display_name'] ?? $currentUser['username'] ?? 'A', 0, 1)) ?>
                            </div>
                            <span class="ml-2 text-sm font-medium text-gray-700 hidden sm:block">
                                <?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'] ?? 'Admin') ?>
                            </span>
                            <i class="fas fa-chevron-down text-gray-400 text-xs ml-2 hidden sm:block"></i>
                        </div>
                        <div id="userMenu" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50">
                            <div class="px-4 py-3 border-b border-gray-100">
                                <div class="font-semibold text-sm text-gray-800"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'] ?? 'Admin') ?></div>
                                <div class="text-xs text-gray-400"><?= ucfirst($currentUser['role'] ?? 'Admin') ?></div>
                            </div>
                            <a href="<?= $baseUrl ?>admin-users.php" class="flex items-center px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                                <i class="fas fa-user-cog w-5 text-gray-400"></i>
                                <span class="ml-2">Account Settings</span>
                            </a>
                            <a href="<?= $baseUrl ?>help.php" class="flex items-center px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                                <i class="fas fa-question-circle w-5 text-gray-400"></i>
                                <span class="ml-2">Help & Support</span>
                            </a>
                            <div class="border-t border-gray-100 mt-2 pt-2">
                                <a href="<?= $baseUrl ?>auth/logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                    <i class="fas fa-sign-out-alt w-5"></i>
                                    <span class="ml-2">Logout</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Content Area -->
            <div class="content-area">

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('mobileOverlay').classList.toggle('open');
}

function toggleBotDropdown() {
    document.getElementById('botDropdown').classList.toggle('open');
}

function toggleSubmenu(id) {
    const submenu = document.getElementById(id);
    const parent = submenu.previousElementSibling;
    const arrow = parent?.querySelector('.menu-arrow');
    
    if (submenu) {
        submenu.classList.toggle('open');
        if (arrow) {
            arrow.classList.toggle('rotate');
        }
    }
    
    // Save state to localStorage
    const openMenus = JSON.parse(localStorage.getItem('openMenus') || '{}');
    openMenus[id] = submenu.classList.contains('open');
    localStorage.setItem('openMenus', JSON.stringify(openMenus));
}

// Restore menu state on page load
document.addEventListener('DOMContentLoaded', function() {
    const openMenus = JSON.parse(localStorage.getItem('openMenus') || '{}');
    
    // Get all submenus
    document.querySelectorAll('.menu-submenu').forEach(submenu => {
        const id = submenu.id;
        const parent = submenu.previousElementSibling;
        const arrow = parent?.querySelector('.menu-arrow');
        
        // Check if this submenu has an active item (Requirements 8.3)
        const hasActiveItem = submenu.querySelector('.menu-item.active') !== null;
        
        if (hasActiveItem) {
            // Always expand group with active item
            submenu.classList.add('open');
            if (arrow) arrow.classList.add('rotate');
        } else if (openMenus[id] !== undefined) {
            // Restore saved state for non-active groups
            if (openMenus[id]) {
                submenu.classList.add('open');
                if (arrow) arrow.classList.add('rotate');
            } else {
                submenu.classList.remove('open');
                if (arrow) arrow.classList.remove('rotate');
            }
        }
    });
});

function toggleUserMenu() {
    document.getElementById('userMenu').classList.toggle('hidden');
}

function toggleTheme() {
    // Placeholder for theme toggle
    document.body.classList.toggle('dark');
}

// Close dropdowns on outside click
document.addEventListener('click', function(e) {
    const botDropdown = document.getElementById('botDropdown');
    const botCard = e.target.closest('.bot-card');
    if (botDropdown && !botCard && !botDropdown.contains(e.target)) {
        botDropdown.classList.remove('open');
    }
    
    const userMenu = document.getElementById('userMenu');
    const userMenuBtn = e.target.closest('.user-menu');
    if (userMenu && !userMenuBtn && !userMenu.contains(e.target)) {
        userMenu.classList.add('hidden');
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('botDropdown')?.classList.remove('open');
        document.getElementById('userMenu')?.classList.add('hidden');
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('mobileOverlay')?.classList.remove('open');
    }
});

// Mobile: Prevent body scroll when sidebar is open
function toggleSidebarScroll(isOpen) {
    if (isOpen) {
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        document.body.style.height = '100%';
    } else {
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.height = '';
    }
}

// Override toggleSidebar for mobile scroll handling
const originalToggleSidebar = toggleSidebar;
toggleSidebar = function() {
    const sidebar = document.getElementById('sidebar');
    const willBeOpen = !sidebar.classList.contains('open');
    originalToggleSidebar();
    
    if (window.innerWidth <= 768) {
        toggleSidebarScroll(willBeOpen);
    }
};

// Handle resize - close sidebar on desktop
window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('mobileOverlay')?.classList.remove('open');
        toggleSidebarScroll(false);
    }
});

// Touch swipe to close sidebar
let touchStartX = 0;
let touchEndX = 0;

document.addEventListener('touchstart', function(e) {
    touchStartX = e.changedTouches[0].screenX;
}, { passive: true });

document.addEventListener('touchend', function(e) {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
}, { passive: true });

function handleSwipe() {
    const sidebar = document.getElementById('sidebar');
    const swipeDistance = touchStartX - touchEndX;
    
    // Swipe left to close sidebar (when open)
    if (swipeDistance > 80 && sidebar?.classList.contains('open')) {
        toggleSidebar();
    }
    
    // Swipe right from edge to open sidebar (when closed)
    if (swipeDistance < -80 && touchStartX < 30 && !sidebar?.classList.contains('open')) {
        toggleSidebar();
    }
}

// Fix iOS 100vh issue
function setVH() {
    let vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
}
setVH();
window.addEventListener('resize', setVH);
</script>
