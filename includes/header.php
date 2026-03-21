<?php
/**
 * Header & Sidebar Component - Modern Admin Dashboard V3.0
 * Unified Shop System
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent direct web access to this include file
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/shop-data-source.php';

/**
 * Get current user's role for menu access control
 * Maps database roles to menu system roles
 * @return string Role: owner, admin, pharmacist, staff, marketing, tech
 */
function getCurrentUserRole()
{
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
function hasMenuAccess($menuItem)
{
    // If no roles specified, everyone can access
    if (!isset($menuItem['roles']) || empty($menuItem['roles'])) {
        return true;
    }

    $userRole = getCurrentUserRole();

    // Check if user's role is in the allowed roles array
    return in_array($userRole, $menuItem['roles']);
}

// Helper function to generate clean URLs (without .php)
function cleanUrl($url)
{
    // Remove .php extension for clean URLs
    return preg_replace('/\.php$/', '', $url);
}

/**
 * Log catches that are intentionally empty so we can audit errors.
 */
function logHeaderException(Throwable $exception, string $context = 'header.php'): void
{
    error_log(sprintf(
        "[header][%s] %s: %s in %s:%d",
        $context,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
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

// Detect folder (shop, inventory, or admin)
$isShop = strpos($currentPath, '/shop/') !== false;
$isInventory = strpos($currentPath, '/inventory/') !== false;
$isAdmin = strpos($currentPath, '/admin/') !== false;
$isSubfolder = $isShop || $isInventory || $isAdmin;

// Use absolute paths for menu URLs to avoid path issues
$baseUrl = '/';


// Handle bot switching
if (isset($_GET['switch_bot'])) {
    $_SESSION['current_bot_id'] = (int) $_GET['switch_bot'];
    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: " . $redirectUrl);
    exit;
}

// Get accessible LINE accounts based on user permissions

// Load SEO settings for admin pages
$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? null;

// Initialize SEO service for title and favicon
require_once __DIR__ . '/../classes/LandingSEOService.php';
$adminSeoService = new LandingSEOService($db, $currentBotId);
$adminPageTitle = isset($pageTitle) ? $pageTitle : 'Admin';
$adminFullTitle = $adminPageTitle . ' - ' . $adminSeoService->getAppName();
$adminFaviconUrl = $adminSeoService->getFaviconUrl();

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
                if (!empty($acc['is_default'])) {
                    $currentBot = $acc;
                    break;
                }
            }
            if (!$currentBot)
                $currentBot = $lineAccounts[0];
            $_SESSION['current_bot_id'] = $currentBot['id'];
        }
    }
} catch (Exception $e) {
    logHeaderException($e);
}

$currentBotId = $currentBot['id'] ?? null;
$orderDataSource = getShopOrderDataSource($db, $currentBotId);
$isOdooMode = $orderDataSource === 'odoo';
$ordersMenuLabel = $isOdooMode ? 'ออเดอร์ (Odoo)' : 'ออเดอร์';
$dashboardMenuLabel = $isOdooMode ? 'จัดการลูกค้า Odoo' : 'แดชบอร์ดผู้บริหาร';
$dashboardDefaultHref = $isOdooMode ? '/dashboard?tab=odoo-customers' : '/dashboard?tab=executive';

// Initialize Vibe Selling Helper for v2 toggle (Requirements: 10.6)
$vibeSellingHelper = null;
$inboxUrl = '/inbox';
try {
    require_once __DIR__ . '/../classes/VibeSellingHelper.php';
    $vibeSellingHelper = VibeSellingHelper::getInstance($db);
    $inboxUrl = $vibeSellingHelper->isV2Enabled($currentBotId) ? '/inbox-v2' : '/inbox';
} catch (Exception $e) {
    logHeaderException($e, 'header-vibe-helper');
    // Fallback to v1 if helper fails
    $inboxUrl = '/inbox';
}

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
    try {
        $db->query("SELECT 1 FROM orders LIMIT 1");
        $ordersTable = 'orders';
    } catch (Exception $e) {
    }
    if (!$ordersTable) {
        try {
            $db->query("SELECT 1 FROM transactions LIMIT 1");
            $ordersTable = 'transactions';
        } catch (Exception $e) {
        }
    }

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
    } catch (Exception $e) {
        logHeaderException($e, 'header-count-slips');
    }
} catch (Exception $e) {
    logHeaderException($e, 'header-counts');
}

// ==================== Quick Access - User Customizable ====================
// Available quick access menus (using clean URLs without .php)
// Each item includes 'roles' for role-based access control (matching main menu structure)
// Note: Items without 'roles' key are accessible to all staff (per Requirements 9.1, 9.2, 9.3)
$quickAccessMenus = [
    // ==================== Clinical Station - Unified Care Chat ====================
    'messages' => ['icon' => 'fa-inbox', 'label' => 'กล่องข้อความ', 'url' => $inboxUrl, 'page' => 'inbox', 'badge' => $unreadMessages, 'color' => 'green', 'roles' => ['owner', 'admin', 'pharmacist', 'staff']],
    'quick-reply' => ['icon' => 'fa-comments', 'label' => 'แชทหลัก', 'url' => '/inbox-master', 'page' => 'inbox-master', 'color' => 'blue', 'roles' => ['owner', 'admin', 'pharmacist', 'staff']],
    'chat-analytics' => ['icon' => 'fa-chart-bar', 'label' => 'สถิติแชท', 'url' => $inboxUrl . '?tab=analytics', 'page' => 'inbox', 'color' => 'purple', 'roles' => ['owner', 'admin']],
    'video-call' => ['icon' => 'fa-video', 'label' => 'Video Call', 'url' => '/video-call', 'page' => 'video-call', 'color' => 'red', 'roles' => ['pharmacist', 'staff']],
    'auto-reply' => ['icon' => 'fa-robot', 'label' => 'ตอบอัตโนมัติ', 'url' => '/auto-reply', 'page' => 'auto-reply', 'color' => 'pink', 'roles' => ['pharmacist', 'staff']],

    // ==================== Clinical Station - Roster & Shifts (all staff) ====================
    'pharmacist-dashboard' => ['icon' => 'fa-user-md', 'label' => 'Dashboard เภสัชกร', 'url' => '/pharmacy?tab=dashboard', 'page' => 'pharmacy', 'color' => 'emerald'],
    'pharmacists' => ['icon' => 'fa-users', 'label' => 'จัดการเภสัชกร', 'url' => '/pharmacy?tab=pharmacists', 'page' => 'pharmacy', 'color' => 'teal'],
    'appointments' => ['icon' => 'fa-calendar-check', 'label' => 'นัดหมาย', 'url' => '/appointments-admin', 'page' => 'appointments-admin', 'color' => 'amber'],

    // ==================== Clinical Station - Medical Copilot AI ====================
    'ai-chat' => ['icon' => 'fa-comments', 'label' => 'AI ตอบแชท', 'url' => '/ai-chat?tab=settings', 'page' => 'ai-chat', 'color' => 'fuchsia', 'roles' => ['pharmacist']],
    'ai-studio' => ['icon' => 'fa-wand-magic-sparkles', 'label' => 'AI Studio', 'url' => '/ai-chat?tab=studio', 'page' => 'ai-chat', 'color' => 'rose', 'roles' => ['pharmacist']],
    'ai-pharmacy' => ['icon' => 'fa-cog', 'label' => 'ตั้งค่า AI เภสัช', 'url' => '/ai-pharmacy-settings', 'page' => 'ai-pharmacy-settings', 'color' => 'purple', 'roles' => ['pharmacist']],

    // ==================== Insights & Overview ====================
    'executive' => ['icon' => 'fa-chart-line', 'label' => $dashboardMenuLabel, 'url' => $dashboardDefaultHref, 'page' => 'dashboard', 'color' => 'indigo', 'roles' => ['owner', 'admin']],
    'crm-dashboard' => ['icon' => 'fa-users-cog', 'label' => 'CRM Dashboard', 'url' => '/dashboard?tab=crm', 'page' => 'dashboard', 'color' => 'blue', 'roles' => ['owner', 'admin']],
    'odoo-customers' => ['icon' => 'fa-file-invoice-dollar', 'label' => 'จัดการลูกค้า Odoo', 'url' => '/dashboard?tab=odoo-customers', 'page' => 'dashboard', 'color' => 'violet', 'roles' => ['owner', 'admin'], 'condition' => $isOdooMode],
    'triage' => ['icon' => 'fa-stethoscope', 'label' => 'สถิติการรักษา', 'url' => '/triage-analytics', 'page' => 'triage-analytics', 'color' => 'emerald', 'roles' => ['pharmacist', 'owner']],
    'drug-interactions' => ['icon' => 'fa-pills', 'label' => 'ยาตีกัน', 'url' => '/pharmacy?tab=interactions', 'page' => 'pharmacy', 'color' => 'red', 'roles' => ['pharmacist', 'owner']],
    'activity-logs' => ['icon' => 'fa-history', 'label' => 'ประวัติการใช้งาน', 'url' => '/activity-logs', 'page' => 'activity-logs', 'color' => 'slate', 'roles' => ['owner']],

    // ==================== Patient & Journey - EHR ====================
    'users' => ['icon' => 'fa-users', 'label' => 'รายชื่อลูกค้า', 'url' => '/users', 'page' => 'users', 'color' => 'cyan', 'roles' => ['pharmacist']],
    'user-tags' => ['icon' => 'fa-tags', 'label' => 'แท็กลูกค้า', 'url' => '/user-tags', 'page' => 'user-tags', 'color' => 'sky', 'roles' => ['pharmacist']],

    // ==================== Patient & Journey - Membership (all staff) ====================
    'members' => ['icon' => 'fa-id-card', 'label' => 'จัดการสมาชิก', 'url' => '/membership?tab=members', 'page' => 'membership', 'color' => 'rose'],
    'rewards' => ['icon' => 'fa-gift', 'label' => 'รางวัลแลกแต้ม', 'url' => '/membership?tab=rewards', 'page' => 'membership', 'color' => 'fuchsia'],
    'points-settings' => ['icon' => 'fa-coins', 'label' => 'ตั้งค่าแต้ม', 'url' => '/membership?tab=settings', 'page' => 'membership', 'color' => 'yellow'],

    // ==================== Patient & Journey - Care Journey ====================
    'broadcast' => ['icon' => 'fa-paper-plane', 'label' => 'บรอดแคสต์', 'url' => '/broadcast', 'page' => 'broadcast', 'color' => 'purple', 'roles' => ['admin', 'marketing']],
    'broadcast-catalog' => ['icon' => 'fa-layer-group', 'label' => 'แคตตาล็อก', 'url' => '/broadcast?tab=catalog', 'page' => 'broadcast', 'color' => 'violet', 'roles' => ['admin', 'marketing']],
    'drip-campaigns' => ['icon' => 'fa-water', 'label' => 'Drip Campaign', 'url' => '/drip-campaigns', 'page' => 'drip-campaigns', 'color' => 'blue', 'roles' => ['admin', 'marketing']],
    'templates' => ['icon' => 'fa-file-alt', 'label' => 'Templates', 'url' => '/templates', 'page' => 'templates', 'color' => 'slate', 'roles' => ['admin', 'marketing']],

    // ==================== Patient & Journey - Digital Front Door ====================
    'rich-menu' => ['icon' => 'fa-th-large', 'label' => 'Rich Menu', 'url' => '/rich-menu', 'page' => 'rich-menu', 'color' => 'teal', 'roles' => ['admin', 'marketing']],
    'dynamic-rich-menu' => ['icon' => 'fa-random', 'label' => 'Dynamic Rich Menu', 'url' => '/rich-menu?tab=dynamic', 'page' => 'rich-menu', 'color' => 'cyan', 'roles' => ['admin', 'marketing']],
    'liff-settings' => ['icon' => 'fa-mobile-screen', 'label' => 'ตั้งค่า LIFF', 'url' => '/liff-settings', 'page' => 'liff-settings', 'color' => 'lime', 'roles' => ['admin', 'marketing']],

    // ==================== Supply & Revenue - Billing & Orders ====================
    'orders' => ['icon' => 'fa-receipt', 'label' => $ordersMenuLabel, 'url' => '/shop/orders', 'page' => 'orders', 'badge' => $pendingOrders, 'badgeColor' => 'yellow', 'color' => 'orange', 'roles' => ['admin', 'staff']],
    'promotions' => ['icon' => 'fa-star', 'label' => 'โปรโมชั่น', 'url' => '/shop/promotions', 'page' => 'promotions', 'color' => 'amber', 'roles' => ['admin', 'staff']],

    // ==================== Supply & Revenue - Inventory ====================
    'products' => ['icon' => 'fa-box', 'label' => 'สินค้า', 'url' => '/inventory?tab=products', 'page' => 'inventory', 'color' => 'blue', 'roles' => ['admin', 'pharmacist']],
    'categories' => ['icon' => 'fa-folder', 'label' => 'หมวดหมู่', 'url' => '/shop/categories', 'page' => 'categories', 'color' => 'lime', 'roles' => ['admin', 'pharmacist']],
    'stock-adjustment' => ['icon' => 'fa-sliders-h', 'label' => 'ปรับสต็อก', 'url' => '/inventory?tab=adjustment', 'page' => 'inventory', 'color' => 'indigo', 'roles' => ['admin', 'pharmacist']],
    'stock-movements' => ['icon' => 'fa-exchange-alt', 'label' => 'ประวัติเคลื่อนไหว', 'url' => '/inventory?tab=movements', 'page' => 'inventory', 'color' => 'sky', 'roles' => ['admin', 'pharmacist']],
    'low-stock' => ['icon' => 'fa-exclamation-triangle', 'label' => 'สินค้าใกล้หมด', 'url' => '/inventory?tab=low-stock', 'page' => 'inventory', 'color' => 'red', 'roles' => ['admin', 'pharmacist']],
    'product-units' => ['icon' => 'fa-balance-scale', 'label' => 'หน่วยสินค้า', 'url' => '/inventory/product-units', 'page' => 'product-units', 'color' => 'emerald', 'roles' => ['admin', 'pharmacist']],
    'sync' => ['icon' => 'fa-sync', 'label' => 'Sync สินค้า', 'url' => '/sync-dashboard', 'page' => 'sync-dashboard', 'color' => 'sky', 'roles' => ['admin', 'owner']],
    'wms' => ['icon' => 'fa-shipping-fast', 'label' => 'WMS', 'url' => '/inventory?tab=wms', 'page' => 'inventory', 'color' => 'purple', 'roles' => ['admin', 'staff']],
    'locations' => ['icon' => 'fa-map-marker-alt', 'label' => 'ตำแหน่งจัดเก็บ', 'url' => '/inventory?tab=locations', 'page' => 'inventory', 'color' => 'teal', 'roles' => ['admin', 'pharmacist', 'staff']],
    'batches' => ['icon' => 'fa-layer-group', 'label' => 'Batch/Lot', 'url' => '/inventory?tab=batches', 'page' => 'inventory', 'color' => 'amber', 'roles' => ['admin', 'pharmacist', 'staff']],
    'put-away' => ['icon' => 'fa-inbox', 'label' => 'Put Away', 'url' => '/inventory?tab=put-away', 'page' => 'inventory', 'color' => 'violet', 'roles' => ['admin', 'pharmacist', 'staff']],

    // ==================== Supply & Revenue - Procurement ====================
    'purchase-orders' => ['icon' => 'fa-file-invoice', 'label' => 'ใบสั่งซื้อ (PO)', 'url' => '/procurement?tab=po', 'page' => 'procurement', 'color' => 'violet', 'roles' => ['admin', 'owner']],
    'goods-receive' => ['icon' => 'fa-truck-loading', 'label' => 'รับสินค้า (GR)', 'url' => '/procurement?tab=gr', 'page' => 'procurement', 'color' => 'teal', 'roles' => ['admin', 'owner']],
    'suppliers' => ['icon' => 'fa-truck', 'label' => 'Suppliers', 'url' => '/procurement?tab=suppliers', 'page' => 'procurement', 'color' => 'slate', 'roles' => ['admin', 'owner']],

    // ==================== Supply & Revenue - Accounting ====================
    'accounting' => ['icon' => 'fa-calculator', 'label' => 'บัญชี', 'url' => '/accounting', 'page' => 'accounting', 'color' => 'emerald', 'roles' => ['admin', 'owner']],
    'accounting-ap' => ['icon' => 'fa-file-invoice-dollar', 'label' => 'เจ้าหนี้ (AP)', 'url' => '/accounting?tab=ap', 'page' => 'accounting', 'color' => 'red', 'roles' => ['admin', 'owner']],
    'accounting-ar' => ['icon' => 'fa-hand-holding-usd', 'label' => 'ลูกหนี้ (AR)', 'url' => '/accounting?tab=ar', 'page' => 'accounting', 'color' => 'green', 'roles' => ['admin', 'owner']],
    'accounting-expenses' => ['icon' => 'fa-receipt', 'label' => 'ค่าใช้จ่าย', 'url' => '/accounting?tab=expenses', 'page' => 'accounting', 'color' => 'orange', 'roles' => ['admin', 'owner']],

    // ==================== Facility Setup - Facility Profile ====================
    'shop-settings' => ['icon' => 'fa-store', 'label' => 'ข้อมูลสถานพยาบาล', 'url' => '/shop/settings', 'page' => 'settings', 'color' => 'emerald', 'roles' => ['admin', 'owner']],
    'landing-settings' => ['icon' => 'fa-home', 'label' => 'Landing Page', 'url' => '/admin/landing-settings', 'page' => 'landing-settings', 'color' => 'sky', 'roles' => ['admin', 'owner']],

    // ==================== Facility Setup - Staff & Roles ====================
    'admin-users' => ['icon' => 'fa-users-cog', 'label' => 'บุคลากร & สิทธิ์', 'url' => '/admin-users2', 'page' => 'admin-users2', 'color' => 'indigo', 'roles' => ['owner', 'admin']],

    // ==================== Facility Setup - Integrations ====================
    'line-accounts' => ['icon' => 'fa-layer-group', 'label' => 'บัญชี LINE', 'url' => '/settings?tab=line', 'page' => 'settings', 'color' => 'green', 'roles' => ['owner', 'admin', 'tech']],
    'telegram' => ['icon' => 'fab fa-telegram', 'label' => 'Telegram', 'url' => '/settings?tab=telegram', 'page' => 'settings', 'color' => 'blue', 'roles' => ['owner', 'admin', 'tech']],
    'ai-settings' => ['icon' => 'fa-key', 'label' => 'ตั้งค่า API Key', 'url' => '/ai-settings', 'page' => 'ai-settings', 'color' => 'violet', 'roles' => ['owner', 'admin', 'tech']],

    // ==================== Facility Setup - Consent & PDPA ====================
    'consent-management' => ['icon' => 'fa-shield-alt', 'label' => 'Consent & PDPA', 'url' => '/consent-management', 'page' => 'consent-management', 'color' => 'rose', 'roles' => ['owner', 'admin']],

    // ==================== Facility Setup - Reports ====================
    'scheduled-reports' => ['icon' => 'fa-calendar-alt', 'label' => 'รายงานอัตโนมัติ', 'url' => '/scheduled?tab=reports', 'page' => 'scheduled', 'color' => 'amber', 'roles' => ['owner', 'admin']],
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
        logHeaderException($e);
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

// Menu structure with nested submenus - Final Menu Structure V3
// โครงสร้างเมนู 6 กลุ่มหลัก พร้อม submenus แบบ nested
// DEBUG: Menu version 2026-01-03-thai
$supplyMenus = [
    ['title' => 'POS ขายหน้าร้าน', 'icon' => '🛒', 'href' => '/pos'],
    ['title' => $isOdooMode ? 'รายการสั่งซื้อ (Odoo)' : 'รายการสั่งซื้อ', 'icon' => '🧾', 'href' => '/shop/orders', 'badge' => $pendingOrders],
    ['title' => 'คลังสินค้า', 'icon' => '📦', 'href' => '/inventory'],
    ['title' => 'จัดซื้อ', 'icon' => '🚚', 'href' => '/procurement'],
    ['title' => 'บัญชี', 'icon' => '💰', 'href' => '/accounting'],
];

if ($isOdooMode) {
    $supplyMenus[] = ['title' => 'Odoo Dashboard', 'icon' => '🛰️', 'href' => '/odoo-dashboard'];
    $supplyMenus[] = ['title' => 'Odoo Webhooks', 'icon' => '🪝', 'href' => '/odoo-webhooks-dashboard'];
}

$menuGroups = [
    [
        'group_id' => 'insights',
        'group_title' => 'ภาพรวมและสถิติ',
        'group_icon' => '📊',
        'roles' => ['owner', 'admin'],
        'menus' => [
            [
                'title' => 'Dashboard',
                'icon' => '🏠',
                'submenus' => array_filter([
                    ['title' => $isOdooMode ? 'Odoo Overview' : 'Executive Overview', 'href' => $dashboardDefaultHref],
                    ['title' => 'CRM Dashboard', 'href' => '/dashboard?tab=crm'],
                    $isOdooMode ? ['title' => 'จัดการลูกค้า Odoo', 'href' => '/dashboard?tab=odoo-customers'] : null,
                ])
            ],
            ['title' => 'วิเคราะห์ข้อมูล', 'icon' => '📈', 'href' => '/analytics'],
            ['title' => 'ประวัติการใช้งาน', 'icon' => '📋', 'href' => '/activity-logs'],
        ]
    ],
    [
        'group_id' => 'clinical',
        'group_title' => 'งานบริการคลินิก',
        'group_icon' => '🩺',
        'roles' => ['owner', 'admin', 'pharmacist'],
        'menus' => [
            ['title' => 'ห้องยา / จ่ายยา', 'icon' => '💊', 'href' => '/pharmacy'],
            ['title' => 'นัดหมาย', 'icon' => '📅', 'href' => '/appointments-admin'],
            ['title' => 'ปรึกษาออนไลน์', 'icon' => '📹', 'href' => '/pharmacist-video-calls'],
        ]
    ],
    [
        'group_id' => 'patient',
        'group_title' => 'ดูแลลูกค้า',
        'group_icon' => '👥',
        'roles' => ['owner', 'admin', 'marketing', 'staff'],
        'menus' => [
            ['title' => 'กล่องข้อความ', 'icon' => '💬', 'href' => $inboxUrl, 'badge' => $unreadMessages],
            ['title' => 'แชทหลัก', 'icon' => '💬', 'href' => '/inbox-master'],
            ['title' => 'สถิติแชท', 'icon' => '📊', 'href' => $inboxUrl . '?tab=analytics'],
            ['title' => 'รายชื่อลูกค้า', 'icon' => '📇', 'href' => '/users'],
            ['title' => 'บรอดแคสต์', 'icon' => '📢', 'href' => '/broadcast'],
            ['title' => 'ระบบสมาชิก', 'icon' => '💳', 'href' => '/membership'],
        ]
    ],
    [
        'group_id' => 'supply',
        'group_title' => 'คลังสินค้าและยอดขาย',
        'group_icon' => '📦',
        'roles' => ['owner', 'admin', 'staff'],
        'menus' => $supplyMenus
    ],
    [
        'group_id' => 'facility',
        'group_title' => 'ตั้งค่าร้านค้า',
        'group_icon' => '⚙️',
        'roles' => ['owner', 'admin', 'tech'],
        'menus' => [
            ['title' => 'ตั้งค่าระบบ', 'icon' => '🔧', 'href' => '/settings'],
            ['title' => 'ข้อมูลร้าน', 'icon' => '🏪', 'href' => '/shop/settings'],
            ['title' => 'Landing Page', 'icon' => '🏠', 'href' => '/admin/landing-settings'],
            ['title' => 'Rich Menu', 'icon' => '🎨', 'href' => '/rich-menu'],
            ['title' => 'เช็คสถานะระบบ', 'icon' => '🔍', 'href' => '/system-status'],
        ]
    ],
];
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#06C755">
    <meta name="base-url" content="<?= $baseUrl ?>">
    <meta name="line-account-id" content="<?= $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? 1 ?>">
    <title><?= htmlspecialchars($adminFullTitle) ?></title>

    <!-- Favicon & Icons -->
    <?php if (!empty($adminFaviconUrl)): ?>
        <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($adminFaviconUrl) ?>">
        <link rel="shortcut icon" type="image/x-icon" href="<?= htmlspecialchars($adminFaviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= htmlspecialchars($adminFaviconUrl) ?>">
        <link rel="apple-touch-icon-precomposed" href="<?= htmlspecialchars($adminFaviconUrl) ?>">
    <?php else: ?>
        <link rel="icon" type="image/png" href="/assets/images/3.png?v=2">
        <link rel="shortcut icon" type="image/png" href="/assets/images/3.png?v=2">
        <link rel="apple-touch-icon" href="/assets/images/3.png?v=2">
        <link rel="apple-touch-icon-precomposed" href="/assets/images/3.png?v=2">
    <?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #00B900;
            --primary-dark: #00A000;
            --primary-light: #00C300;
            --sidebar-width: 260px;
            --sidebar-bg: #ffffff;
            --sidebar-border: #e5e7eb;
            --sidebar-text: #374151;
            --sidebar-text-muted: #6b7280;
            --sidebar-hover: #f3f4f6;
            --sidebar-active-bg: #ecfdf5;
            --sidebar-active-text: #047857;
        }
        
        body { 
            font-family: 'Inter', 'Noto Sans Thai', sans-serif; 
            background: #f1f5f9;
            margin: 0;
            padding: 0;
        }
        
        /* App Layout - Main Container */
        .app-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar { width: 4px; height: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 2px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        /* Sidebar - Clean White Theme (inbox-master style) */
        .sidebar {
            width: 220px !important;
            min-width: 220px !important;
            max-width: 220px !important;
            flex: 0 0 220px !important;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--sidebar-border);
            transition: transform 0.3s ease;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .sidebar-brand {
            padding: 12px 14px;
            border-bottom: 1px solid var(--sidebar-border);
            background: var(--sidebar-bg);
        }
        
        /* Bot Selector */
        .bot-selector {
            padding: 8px 12px;
            border-bottom: 1px solid var(--sidebar-border);
        }
        
        .bot-card {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            background: #f9fafb;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--sidebar-border);
        }
        
        .bot-card:hover { background: var(--sidebar-hover); border-color: #d1d5db; }
        
        .bot-avatar {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .bot-avatar img { width: 100%; height: 100%; object-fit: cover; }
        
        /* Menu Section */
        .menu-section { padding: 4px 6px 2px; }
        .menu-section-title {
            font-size: 9px;
            font-weight: 600;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 10px 4px;
        }
        
        /* Simple Menu Item */
        .menu-item {
            display: flex;
            align-items: center;
            padding: 7px 10px;
            margin: 1px 6px;
            border-radius: 6px;
            color: var(--sidebar-text);
            font-size: 12px;
            font-weight: 400;
            transition: all 0.15s ease;
            text-decoration: none;
            position: relative;
        }
        
        .menu-item:hover { 
            background: var(--sidebar-hover); 
            color: #111827; 
        }
        .menu-item:hover .menu-icon { color: #374151; }
        
        .menu-item.active {
            background: var(--sidebar-active-bg);
            color: var(--sidebar-active-text);
            font-weight: 500;
        }
        
        .menu-item.active .menu-icon { color: var(--sidebar-active-text); }
        .menu-item.active:hover { background: #d1fae5; }
        
        .menu-icon {
            width: 18px;
            margin-right: 8px;
            font-size: 12px;
            color: var(--sidebar-text-muted);
            text-align: center;
        }
        
        .menu-badge {
            margin-left: auto;
            padding: 1px 6px;
            font-size: 9px;
            font-weight: 600;
            border-radius: 8px;
            background: #ef4444;
            color: white;
        }
        
        .menu-badge.yellow { background: #f59e0b; }
        .menu-badge.blue { background: #3b82f6; }
        .menu-badge.green { background: var(--primary); }
        .menu-badge.orange { background: #f97316; }
        
        /* Group Header Wrapper */
        .menu-parent-wrapper {
            display: flex;
            align-items: center;
            margin: 1px 6px;
        }
        
        /* Group Header - Collapsible */
        .menu-parent {
            display: flex;
            align-items: center;
            padding: 7px 10px;
            flex: 1;
            border-radius: 6px;
            color: var(--sidebar-text);
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }
        
        .menu-parent:hover { 
            background: var(--sidebar-hover); 
            color: #111827; 
        }
        
        /* Sidebar Footer */
        .sidebar-footer {
            padding: 8px 10px;
            border-top: 1px solid var(--sidebar-border);
        }
        
        .sidebar-footer-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 9px;
            color: #9ca3af;
        }
        
        .menu-parent-icon {
            width: 18px;
            margin-right: 8px;
            font-size: 12px;
            text-align: center;
        }
        
        .menu-parent-label { flex: 1; }
        
        .menu-arrow {
            font-size: 9px;
            color: #9ca3af;
            transition: transform 0.2s ease;
        }
        
        .menu-arrow.rotate { transform: rotate(180deg); }
        
        /* Submenu Container */
        .menu-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.25s ease-out;
        }
        
        .menu-submenu.open {
            max-height: 2000px;
            transition: max-height 0.3s ease-in;
        }
        
        .menu-submenu .menu-item {
            padding-left: 32px;
            font-size: 12.5px;
        }
        
        .menu-submenu .menu-icon {
            font-size: 11px;
            width: 14px;
            margin-right: 8px;
        }
        
        /* Nested Menu Group - Simple Style */
        .nested-menu-group {
            margin: 1px 0;
        }
        
        .nested-menu-parent {
            display: flex;
            align-items: center;
            padding: 6px 10px 6px 32px;
            margin: 1px 6px;
            border-radius: 5px;
            color: var(--sidebar-text-muted);
            font-size: 11px;
            font-weight: 400;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
        }
        
        .nested-menu-parent:hover { 
            background: var(--sidebar-hover); 
            color: #111827;
        }
        
        .nested-menu-icon {
            width: 16px;
            margin-right: 6px;
            font-size: 11px;
            text-align: center;
        }
        
        .nested-menu-label { 
            flex: 1; 
            font-size: 11px;
        }
        
        .nested-menu-note {
            font-size: 8px;
            color: #9ca3af;
            margin-right: 4px;
            font-weight: 400;
        }
        
        .nested-arrow {
            font-size: 7px;
            color: #9ca3af;
            transition: transform 0.2s ease;
        }
        
        .nested-arrow.rotate { transform: rotate(90deg); }
        
        /* Nested Submenu Items */
        .nested-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.2s ease-out;
        }
        
        .nested-submenu.open {
            max-height: 500px;
            transition: max-height 0.25s ease-in;
        }
        
        .nested-menu-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 10px 6px 46px;
            margin: 1px 6px;
            border-radius: 5px;
            color: var(--sidebar-text-muted);
            font-size: 11px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        
        .nested-menu-item.direct-link {
            padding: 7px 10px 7px 34px;
            gap: 8px;
            justify-content: flex-start;
        }
        
        .nested-menu-item.direct-link .nested-menu-icon {
            font-size: 12px;
            min-width: 16px;
        }
        
        .nested-menu-item:hover {
            background: var(--sidebar-hover);
            color: #111827;
        }
        
        .nested-menu-item.active {
            background: var(--sidebar-active-bg);
            color: var(--sidebar-active-text);
            font-weight: 500;
        }
        
        /* Quick Access - Hidden for simple theme */
        .quick-access-section {
            display: none;
        }
        
        .quick-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 6px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }
        
        .quick-item:hover { transform: translateY(-1px); }
        
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
            flex: 1 1 auto !important;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow-x: hidden;
            overflow-y: auto;
            height: 100vh;
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
                background: #ffffff !important;
                border-right: none !important;
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
            
            .nested-submenu.open {
                max-height: none !important;
            }
            
            /* Menu items larger touch targets */
            .menu-parent {
                padding: 12px 14px !important;
                min-height: 44px !important;
            }
            
            .nested-menu-parent {
                padding: 10px 14px 10px 38px !important;
                min-height: 40px !important;
            }
            
            .nested-menu-item {
                padding: 10px 14px 10px 56px !important;
                min-height: 40px !important;
            }
            
            /* AI Help button mobile */
            .ai-help-btn {
                width: 36px !important;
                height: 40px !important;
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

    <div class="app-layout">
        <!-- Sidebar -->
        <aside id="sidebar" class="sidebar flex flex-col">
            <!-- Brand -->
            <div class="sidebar-brand flex items-center">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center"
                    style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);">
                    <i class="fab fa-line text-white text-xl"></i>
                </div>
                <div class="ml-3 flex-1">
                    <div class="font-bold text-gray-800 text-sm"><?= APP_NAME ?></div>
                    <div class="text-xs text-gray-400">Admin Panel v3.0</div>
                </div>
                <button onclick="toggleSidebar()" class="md:hidden text-gray-400 hover:text-gray-700">
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
                            <div class="text-sm font-semibold text-gray-700 truncate">
                                <?= htmlspecialchars($currentBot['name'] ?? 'Select Bot') ?></div>
                            <div class="text-xs text-gray-400 truncate">
                                <?= htmlspecialchars($currentBot['basic_id'] ?? '') ?></div>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400 text-xs ml-2"></i>
                    </div>
                    <div id="botDropdown" class="dropdown-menu">
                        <?php foreach ($lineAccounts as $acc): ?>
                            <a href="?switch_bot=<?= $acc['id'] ?>"
                                class="dropdown-item <?= ($currentBot && $currentBot['id'] == $acc['id']) ? 'active' : '' ?>">
                                <div class="bot-avatar" style="width:32px;height:32px;font-size:14px;">
                                    <?php if (!empty($acc['picture_url'])): ?>
                                        <img src="<?= htmlspecialchars($acc['picture_url']) ?>" alt="">
                                    <?php else: ?>
                                        <i class="fab fa-line"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-700 truncate">
                                        <?= htmlspecialchars($acc['name']) ?></div>
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
                <!-- Quick Access Section -->
                <?php if (!empty($quickAccessItems)): ?>
                    <div class="quick-access-section">
                        <div class="flex items-center justify-between mb-2">
                            <div class="menu-section-title mb-0">⚡ Quick Access</div>
                            <a href="<?= $baseUrl ?>settings.php?tab=quick-access"
                                class="text-xs text-gray-400 hover:text-green-600" title="ตั้งค่า Quick Access">
                                <i class="fas fa-cog"></i>
                            </a>
                        </div>
                        <div class="grid grid-cols-4 gap-1">
                            <?php foreach ($quickAccessItems as $item):
                                $itemUrl = $baseUrl . ltrim($item['url'], '/');
                                ?>
                                <a href="<?= $itemUrl ?>" class="quick-item">
                                    <div class="quick-icon <?= $item['color'] ?? 'green' ?>">
                                        <i class="fas <?= $item['icon'] ?>"></i>
                                    </div>
                                    <span class="quick-label"><?= $item['label'] ?></span>
                                    <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                                        <span
                                            class="quick-badge <?= $item['badgeColor'] ?? '' ?>"><?= $item['badge'] > 99 ? '99+' : $item['badge'] ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Main Menu Groups -->
                <?php
                $userRole = getCurrentUserRole();
                foreach ($menuGroups as $group):
                    // ตรวจสอบ role ก่อนแสดง group
                    if (isset($group['roles']) && !in_array($userRole, $group['roles'])) {
                        continue; // ข้าม group นี้ถ้าไม่มีสิทธิ์
                    }
                    ?>
                    <div class="menu-section">
                        <!-- Group Header -->
                        <div class="menu-parent" onclick="toggleSubmenu('group_<?= $group['group_id'] ?>')">
                            <span class="menu-parent-icon"><?= $group['group_icon'] ?></span>
                            <span class="menu-parent-label"><?= $group['group_title'] ?></span>
                            <i class="fas fa-chevron-down menu-arrow"></i>
                        </div>

                        <!-- Group Menus -->
                        <div id="group_<?= $group['group_id'] ?>" class="menu-submenu">
                            <?php foreach ($group['menus'] as $menuIndex => $menu): ?>
                                <?php if (isset($menu['href'])): ?>
                                    <!-- Direct link menu (no submenus) -->
                                    <?php
                                    $menuUrl = $baseUrl . ltrim($menu['href'], '/');
                                    $isActive = strpos($currentPath, $menu['href']) !== false;
                                    ?>
                                    <a href="<?= $menuUrl ?>" class="nested-menu-item direct-link <?= $isActive ? 'active' : '' ?>">
                                        <span class="nested-menu-icon"><?= $menu['icon'] ?></span>
                                        <span><?= $menu['title'] ?></span>
                                        <?php if (!empty($menu['badge']) && $menu['badge'] > 0): ?>
                                            <span class="menu-badge"><?= $menu['badge'] > 99 ? '99+' : $menu['badge'] ?></span>
                                        <?php endif; ?>
                                    </a>
                                <?php elseif (isset($menu['submenus']) && is_array($menu['submenus'])): ?>
                                    <div class="nested-menu-group">
                                        <!-- Menu Title with Submenus -->
                                        <div class="nested-menu-parent"
                                            onclick="toggleNestedSubmenu('submenu_<?= $group['group_id'] ?>_<?= $menuIndex ?>')">
                                            <span class="nested-menu-icon"><?= $menu['icon'] ?></span>
                                            <span class="nested-menu-label"><?= $menu['title'] ?></span>
                                            <?php if (!empty($menu['note'])): ?>
                                                <span class="nested-menu-note"><?= $menu['note'] ?></span>
                                            <?php endif; ?>
                                            <i class="fas fa-chevron-right nested-arrow"></i>
                                        </div>

                                        <!-- Submenus -->
                                        <div id="submenu_<?= $group['group_id'] ?>_<?= $menuIndex ?>" class="nested-submenu">
                                            <?php foreach ($menu['submenus'] as $submenu):
                                                $submenuUrl = $baseUrl . ltrim($submenu['href'], '/');
                                                $isActive = strpos($currentPath, $submenu['href']) !== false;
                                                ?>
                                                <a href="<?= $submenuUrl ?>" class="nested-menu-item <?= $isActive ? 'active' : '' ?>">
                                                    <span><?= $submenu['title'] ?></span>
                                                    <?php if (!empty($submenu['badge']) && $submenu['badge'] > 0): ?>
                                                        <span
                                                            class="menu-badge"><?= $submenu['badge'] > 99 ? '99+' : $submenu['badge'] ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </nav>

            <!-- Sidebar Footer -->
            <div class="sidebar-footer">
                <div class="sidebar-footer-info">
                    <span>LINE CRM Pro v3.5</span>
                    <div class="flex items-center gap-2">
                        <a href="<?= $baseUrl ?>help.php" class="hover:text-white" title="Help"><i
                                class="fas fa-question-circle"></i></a>
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
                    <?php if ($isOdooMode): ?>
                    <!-- Odoo Dashboard Shortcut -->
                    <a href="/odoo-dashboard" class="header-btn" title="Odoo Dashboard"
                       style="background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); color: white; width: auto; padding: 0 14px; gap: 6px; font-size: 12px; font-weight: 600; text-decoration: none;">
                        <i class="fas fa-satellite-dish" style="font-size: 13px;"></i>
                        <span class="hidden sm:inline">Odoo</span>
                    </a>
                    <?php endif; ?>

                    <!-- Quick Access Dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="header-btn" title="Quick Access"
                            style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                            <i class="fas fa-bolt"></i>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition
                            class="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50">
                            <?php foreach ($quickAccessItems as $item):
                                $itemUrl = $baseUrl . ltrim($item['url'], '/');
                                $colorClass = [
                                    'green' => 'text-green-500',
                                    'orange' => 'text-orange-500',
                                    'blue' => 'text-blue-500',
                                    'purple' => 'text-purple-500',
                                    'cyan' => 'text-cyan-500',
                                    'pink' => 'text-pink-500',
                                    'indigo' => 'text-indigo-500',
                                    'teal' => 'text-teal-500',
                                    'amber' => 'text-amber-500',
                                    'emerald' => 'text-emerald-500',
                                    'sky' => 'text-sky-500',
                                    'violet' => 'text-violet-500',
                                    'rose' => 'text-rose-500',
                                    'lime' => 'text-lime-500',
                                    'slate' => 'text-slate-500',
                                ][$item['color'] ?? 'gray'] ?? 'text-gray-500';
                                ?>
                                <a href="<?= $itemUrl ?>"
                                    class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 transition">
                                    <i class="fas <?= $item['icon'] ?> <?= $colorClass ?>"></i>
                                    <span class="text-sm"><?= htmlspecialchars($item['label']) ?></span>
                                </a>
                            <?php endforeach; ?>
                            <div class="border-t my-1"></div>
                            <a href="<?= $baseUrl ?>settings.php?tab=quick-access"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 transition text-gray-500">
                                <i class="fas fa-cog"></i>
                                <span class="text-sm">ตั้งค่า Quick Access</span>
                            </a>
                        </div>
                    </div>

                    <!-- AI Tools Dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="header-btn ai-tools-btn" title="AI Tools"
                            style="background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); color: white;">
                            <i class="fas fa-brain"></i>
                            <i class="fas fa-chevron-down text-xs ml-1"></i>
                        </button>
                        <div x-show="open" @click.away="open = false" x-transition
                            class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50">
                            <a href="<?= $baseUrl ?>ai-chat.php"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 transition">
                                <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                                    <i class="fas fa-comments text-blue-600"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800">AI Chat</div>
                                    <div class="text-xs text-gray-500">คุยกับ AI ทั่วไป</div>
                                </div>
                            </a>
                            <a href="<?= $baseUrl ?>onboarding-assistant.php"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 transition">
                                <div class="w-8 h-8 rounded-lg bg-purple-100 flex items-center justify-center">
                                    <i class="fas fa-robot text-purple-600"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-800">Setup Assistant</div>
                                    <div class="text-xs text-gray-500">ผู้ช่วยตั้งค่าระบบ</div>
                                </div>
                            </a>
                            <a href="<?= $baseUrl ?>ai-settings.php"
                                class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 transition">
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
                    <a href="<?= $baseUrl ?><?= ltrim($inboxUrl, '/') ?>.php" class="header-btn"
                        title="Inbox<?= ($vibeSellingHelper && $vibeSellingHelper->shouldShowV2Badge($currentBotId)) ? ' V2' : '' ?>">
                        <i class="fas fa-inbox"></i>
                        <?php if ($unreadMessages > 0): ?>
                            <span class="badge"><?= $unreadMessages > 99 ? '99+' : $unreadMessages ?></span>
                        <?php endif; ?>
                        <?php if ($vibeSellingHelper && $vibeSellingHelper->shouldShowV2Badge($currentBotId)): ?>
                            <span
                                class="absolute -top-1 -right-1 text-[8px] bg-purple-500 text-white px-1 rounded">V2</span>
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
                        <div id="userMenu"
                            class="hidden absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50">
                            <div class="px-4 py-3 border-b border-gray-100">
                                <div class="font-semibold text-sm text-gray-800">
                                    <?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'] ?? 'Admin') ?>
                                </div>
                                <div class="text-xs text-gray-400"><?= ucfirst($currentUser['role'] ?? 'Admin') ?></div>
                            </div>
                            <a href="<?= $baseUrl ?>admin-users.php"
                                class="flex items-center px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                                <i class="fas fa-user-cog w-5 text-gray-400"></i>
                                <span class="ml-2">Account Settings</span>
                            </a>
                            <a href="<?= $baseUrl ?>help.php"
                                class="flex items-center px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                                <i class="fas fa-question-circle w-5 text-gray-400"></i>
                                <span class="ml-2">Help & Support</span>
                            </a>
                            <div class="border-t border-gray-100 mt-2 pt-2">
                                <a href="<?= $baseUrl ?>auth/logout.php"
                                    class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50">
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

                    // Get current user ID for localStorage key prefix
                    const currentUserId = '<?= $adminUserId ?? "guest" ?>';
                    const menuStorageKey = `openMenus_${currentUserId}`;
                    const nestedMenuStorageKey = `openNestedMenus_${currentUserId}`;

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

                        // Save state to localStorage (per user)
                        const openMenus = JSON.parse(localStorage.getItem(menuStorageKey) || '{}');
                        openMenus[id] = submenu.classList.contains('open');
                        localStorage.setItem(menuStorageKey, JSON.stringify(openMenus));
                    }

                    function toggleNestedSubmenu(id) {
                        const submenu = document.getElementById(id);
                        const parent = submenu.previousElementSibling;
                        const arrow = parent?.querySelector('.nested-arrow');

                        if (submenu) {
                            submenu.classList.toggle('open');
                            if (arrow) {
                                arrow.classList.toggle('rotate');
                            }
                        }

                        // Save state to localStorage (per user)
                        const openNestedMenus = JSON.parse(localStorage.getItem(nestedMenuStorageKey) || '{}');
                        openNestedMenus[id] = submenu.classList.contains('open');
                        localStorage.setItem(nestedMenuStorageKey, JSON.stringify(openNestedMenus));
                    }

                    // Restore menu state on page load
                    document.addEventListener('DOMContentLoaded', function () {
                        const openMenus = JSON.parse(localStorage.getItem(menuStorageKey) || '{}');
                        const openNestedMenus = JSON.parse(localStorage.getItem(nestedMenuStorageKey) || '{}');

                        // Get all submenus
                        document.querySelectorAll('.menu-submenu').forEach(submenu => {
                            const id = submenu.id;
                            const parent = submenu.previousElementSibling;
                            const arrow = parent?.querySelector('.menu-arrow');

                            // Check if this submenu has an active item
                            const hasActiveItem = submenu.querySelector('.nested-menu-item.active') !== null;

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

                        // Restore nested submenu states
                        document.querySelectorAll('.nested-submenu').forEach(submenu => {
                            const id = submenu.id;
                            const parent = submenu.previousElementSibling;
                            const arrow = parent?.querySelector('.nested-arrow');

                            // Check if this nested submenu has an active item
                            const hasActiveItem = submenu.querySelector('.nested-menu-item.active') !== null;

                            if (hasActiveItem) {
                                // Always expand nested group with active item
                                submenu.classList.add('open');
                                if (arrow) arrow.classList.add('rotate');

                                // Also expand parent group
                                const parentGroup = submenu.closest('.menu-submenu');
                                if (parentGroup) {
                                    parentGroup.classList.add('open');
                                    const parentArrow = parentGroup.previousElementSibling?.querySelector('.menu-arrow');
                                    if (parentArrow) parentArrow.classList.add('rotate');
                                }
                            } else if (openNestedMenus[id] !== undefined) {
                                if (openNestedMenus[id]) {
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
                    document.addEventListener('click', function (e) {
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
                    document.addEventListener('keydown', function (e) {
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
                    toggleSidebar = function () {
                        const sidebar = document.getElementById('sidebar');
                        const willBeOpen = !sidebar.classList.contains('open');
                        originalToggleSidebar();

                        if (window.innerWidth <= 768) {
                            toggleSidebarScroll(willBeOpen);
                        }
                    };

                    // Handle resize - close sidebar on desktop
                    window.addEventListener('resize', function () {
                        if (window.innerWidth > 768) {
                            document.getElementById('sidebar')?.classList.remove('open');
                            document.getElementById('mobileOverlay')?.classList.remove('open');
                            toggleSidebarScroll(false);
                        }
                    });

                    // Touch swipe to close sidebar
                    let touchStartX = 0;
                    let touchEndX = 0;

                    document.addEventListener('touchstart', function (e) {
                        touchStartX = e.changedTouches[0].screenX;
                    }, { passive: true });

                    document.addEventListener('touchend', function (e) {
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