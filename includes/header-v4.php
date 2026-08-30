<?php
/**
 * REYA Admin V4 — Header & Sidebar Component
 * Modern, accessible, performance-optimized admin shell
 * @version 4.0.0
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent direct web access
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(403);
    exit('403 Forbidden');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/shop-data-source.php';

// ============================================================
// Role & Permission System
// ============================================================

/**
 * Maps database roles to menu system roles.
 *
 * @param array<string,mixed> $user Current user data
 * @return string Normalized role: owner|admin|pharmacist|staff|marketing|tech
 */
function getCurrentUserRole(array $user): string
{
    $dbRole = $user['role'] ?? 'staff';

    return match ($dbRole) {
        'super_admin' => 'owner',
        'admin' => 'admin',
        'pharmacist' => 'pharmacist',
        'marketing' => 'marketing',
        'tech' => 'tech',
        default => 'staff',
    };
}

/**
 * Checks if current user has access to a menu item.
 *
 * @param array<string,mixed> $menuItem Menu item with optional 'roles' key
 * @param string $userRole Current user role
 * @return bool
 */
function hasMenuAccess(array $menuItem, string $userRole): bool
{
    if (empty($menuItem['roles'])) {
        return true;
    }
    return in_array($userRole, $menuItem['roles'], true);
}

// ============================================================
// Utility Functions
// ============================================================

/**
 * Generates clean URLs without .php extension.
 */
function cleanUrl(string $url): string
{
    return preg_replace('/\.php$/', '', $url);
}

/**
 * Logs header exceptions for audit.
 */
function logHeaderException(Throwable $e, string $context = 'header.php'): void
{
    error_log(sprintf(
        "[header][%s] %s: %s in %s:%d",
        $context,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

/**
 * Safely fetches a single column value with fallback.
 */
function safeFetchColumn(PDO $db, string $sql, array $params = [], mixed $fallback = null): mixed
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: $fallback;
    } catch (PDOException $e) {
        logHeaderException($e, 'safeFetchColumn');
        return $fallback;
    }
}

// ============================================================
// Redirect non-admin users
// ============================================================

if (isUser()) {
    $redirect = empty($currentUser['line_account_id'])
        ? '/auth/setup-account'
        : '/user/dashboard';
    header("Location: {$redirect}");
    exit;
}

// ============================================================
// Context Detection
// ============================================================

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$baseUrl = '/';

$isShop = str_contains($currentPath, '/shop/');
$isInventory = str_contains($currentPath, '/inventory/');
$isAdmin = str_contains($currentPath, '/admin/');

// ============================================================
// Bot Switching
// ============================================================

if (isset($_GET['switch_bot'])) {
    $_SESSION['current_bot_id'] = filter_input(INPUT_GET, 'switch_bot', FILTER_VALIDATE_INT) ?: 0;
    $redirectUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: {$redirectUrl}");
    exit;
}

// ============================================================
// Database & Bot Context
// ============================================================

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    logHeaderException($e, 'db-connect');
    $db = null;
}

$currentBotId = $_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? null;

// SEO Service
$adminFullTitle = 'Admin';
$adminFaviconUrl = null;
try {
    require_once __DIR__ . '/../classes/LandingSEOService.php';
    $seoService = new LandingSEOService($db, $currentBotId);
    $adminPageTitle = $pageTitle ?? 'Admin';
    $adminFullTitle = $adminPageTitle . ' - ' . $seoService->getAppName();
    $adminFaviconUrl = $seoService->getFaviconUrl();
} catch (Throwable $e) {
    logHeaderException($e, 'seo-init');
}

// LINE Accounts
$lineAccounts = [];
$currentBot = null;
try {
    $lineAccounts = getAccessibleBots();

    if (!empty($lineAccounts)) {
        $sessionBotId = $_SESSION['current_bot_id'] ?? null;

        foreach ($lineAccounts as $acc) {
            if ($acc['id'] == $sessionBotId) {
                $currentBot = $acc;
                break;
            }
        }

        if (!$currentBot) {
            foreach ($lineAccounts as $acc) {
                if (!empty($acc['is_default'])) {
                    $currentBot = $acc;
                    break;
                }
            }
            if (!$currentBot) {
                $currentBot = $lineAccounts[0];
            }
            $_SESSION['current_bot_id'] = $currentBot['id'];
        }
    }
} catch (Throwable $e) {
    logHeaderException($e, 'bot-init');
}

$currentBotId = $currentBot['id'] ?? null;

// ============================================================
// Odoo Mode Detection
// ============================================================

$orderDataSource = $db ? getShopOrderDataSource($db, $currentBotId) : 'local';
$isOdooMode = ($orderDataSource === 'odoo')
    && defined('ODOO_INTEGRATION_ENABLED')
    && ODOO_INTEGRATION_ENABLED === true;

$ordersMenuLabel = $isOdooMode ? 'ออเดอร์ (Odoo)' : 'ออเดอร์';
$dashboardMenuLabel = $isOdooMode ? 'จัดการลูกค้า Odoo' : 'แดชบอร์ดผู้บริหาร';
$dashboardDefaultHref = $isOdooMode ? '/odoo-dashboard' : '/dashboard?tab=executive';

// ============================================================
// Inbox URL
// ============================================================

$inboxUrl = '/inbox-v2';

// ============================================================
// Unread Counts
// ============================================================

$unreadMessages = 0;
$pendingOrders = 0;
$pendingSlips = 0;

if ($db) {
    // Unread messages
    $unreadMessages = (int) safeFetchColumn(
        $db,
        "SELECT COUNT(*) FROM messages WHERE is_read = 0 AND direction = 'incoming' AND (line_account_id = ? OR line_account_id IS NULL)",
        [$currentBotId],
        0
    );

    // Pending orders
    $ordersTable = null;
    foreach (['orders', 'transactions'] as $table) {
        try {
            $db->query("SELECT 1 FROM {$table} LIMIT 1");
            $ordersTable = $table;
            break;
        } catch (PDOException) {
            continue;
        }
    }

    if ($ordersTable) {
        $pendingOrders = (int) safeFetchColumn(
            $db,
            "SELECT COUNT(*) FROM {$ordersTable} WHERE status = 'pending' AND (line_account_id = ? OR line_account_id IS NULL)",
            [$currentBotId],
            0
        );
    }

    // Pending slips
    try {
        $pendingSlips = (int) safeFetchColumn(
            $db,
            "SELECT COUNT(DISTINCT ps.transaction_id) FROM payment_slips ps 
             INNER JOIN transactions t ON ps.transaction_id = t.id 
             WHERE ps.status = 'pending' AND (t.line_account_id = ? OR t.line_account_id IS NULL)",
            [$currentBotId],
            0
        );
    } catch (Throwable $e) {
        logHeaderException($e, 'count-slips');
    }
}

// ============================================================
// Quick Access Configuration
// ============================================================

$quickAccessMenus = [
    // Clinical Station
    'messages' => ['icon' => 'fa-inbox', 'label' => 'กล่องข้อความ', 'url' => $inboxUrl, 'page' => 'inbox', 'badge' => $unreadMessages, 'color' => 'green', 'roles' => ['owner', 'admin', 'pharmacist', 'staff']],
    'chat-analytics' => ['icon' => 'fa-chart-bar', 'label' => 'สถิติแชท', 'url' => $inboxUrl . '?tab=analytics', 'page' => 'inbox', 'color' => 'purple', 'roles' => ['owner', 'admin']],
    'video-call' => ['icon' => 'fa-video', 'label' => 'Video Call', 'url' => '/pharmacist-video-calls', 'page' => 'pharmacist-video-calls', 'color' => 'red', 'roles' => ['pharmacist', 'staff']],
    'auto-reply' => ['icon' => 'fa-robot', 'label' => 'ตอบอัตโนมัติ', 'url' => '/auto-reply', 'page' => 'auto-reply', 'color' => 'pink', 'roles' => ['pharmacist', 'staff']],

    // Roster & Shifts
    'pharmacist-dashboard' => ['icon' => 'fa-user-md', 'label' => 'Dashboard เภสัชกร', 'url' => '/pharmacy?tab=dashboard', 'page' => 'pharmacy', 'color' => 'emerald'],
    'pharmacists' => ['icon' => 'fa-users', 'label' => 'จัดการเภสัชกร', 'url' => '/pharmacy?tab=pharmacists', 'page' => 'pharmacy', 'color' => 'teal'],
    'appointments' => ['icon' => 'fa-calendar-check', 'label' => 'นัดหมาย', 'url' => '/appointments-admin', 'page' => 'appointments-admin', 'color' => 'amber'],
    'dispense-tracking' => ['icon' => 'fa-prescription-bottle-alt', 'label' => 'ติดตามการจ่ายยา', 'url' => '/dispense-tracking', 'page' => 'dispense-tracking', 'color' => 'teal', 'roles' => ['pharmacist', 'owner', 'admin']],

    // Medical Copilot AI
    'ai-chat' => ['icon' => 'fa-comments', 'label' => 'AI ตอบแชท', 'url' => '/ai-chat?tab=settings', 'page' => 'ai-chat', 'color' => 'fuchsia', 'roles' => ['pharmacist']],
    'ai-studio' => ['icon' => 'fa-wand-magic-sparkles', 'label' => 'AI Studio', 'url' => '/ai-chat?tab=studio', 'page' => 'ai-chat', 'color' => 'rose', 'roles' => ['pharmacist']],
    'ai-pharmacy' => ['icon' => 'fa-cog', 'label' => 'ตั้งค่า AI เภสัช', 'url' => '/ai-pharmacy-settings', 'page' => 'ai-pharmacy-settings', 'color' => 'purple', 'roles' => ['pharmacist']],

    // Insights
    'executive' => ['icon' => 'fa-chart-line', 'label' => $dashboardMenuLabel, 'url' => $dashboardDefaultHref, 'page' => 'dashboard', 'color' => 'indigo', 'roles' => ['owner', 'admin']],
    'odoo-customers' => ['icon' => 'fa-file-invoice-dollar', 'label' => 'จัดการลูกค้า Odoo', 'url' => '/odoo-dashboard', 'page' => 'odoo-dashboard', 'color' => 'violet', 'roles' => ['owner', 'admin'], 'condition' => $isOdooMode],
    'triage' => ['icon' => 'fa-stethoscope', 'label' => 'สถิติการรักษา', 'url' => '/triage-analytics', 'page' => 'triage-analytics', 'color' => 'emerald', 'roles' => ['pharmacist', 'owner']],
    'drug-interactions' => ['icon' => 'fa-pills', 'label' => 'ยาตีกัน', 'url' => '/pharmacy?tab=interactions', 'page' => 'pharmacy', 'color' => 'red', 'roles' => ['pharmacist', 'owner']],
    'activity-logs' => ['icon' => 'fa-history', 'label' => 'ประวัติการใช้งาน', 'url' => '/activity-logs', 'page' => 'activity-logs', 'color' => 'slate', 'roles' => ['owner']],

    // Patient & Journey
    'users' => ['icon' => 'fa-users', 'label' => 'รายชื่อลูกค้า', 'url' => '/users', 'page' => 'users', 'color' => 'cyan', 'roles' => ['pharmacist']],
    'user-tags' => ['icon' => 'fa-tags', 'label' => 'แท็กลูกค้า', 'url' => '/user-tags', 'page' => 'user-tags', 'color' => 'sky', 'roles' => ['pharmacist']],

    // Membership
    'members' => ['icon' => 'fa-id-card', 'label' => 'จัดการสมาชิก', 'url' => '/membership?tab=members', 'page' => 'membership', 'color' => 'rose'],
    'rewards' => ['icon' => 'fa-gift', 'label' => 'รางวัลแลกแต้ม', 'url' => '/membership?tab=rewards', 'page' => 'membership', 'color' => 'fuchsia'],
    'points-settings' => ['icon' => 'fa-coins', 'label' => 'ตั้งค่าแต้ม', 'url' => '/membership?tab=settings', 'page' => 'membership', 'color' => 'yellow'],
    'loyalty-members' => ['icon' => 'fa-phone', 'label' => 'สมาชิกเบอร์ (จ่ายแต้ม)', 'url' => '/loyalty-members', 'page' => 'loyalty-members', 'color' => 'emerald'],

    // Marketing
    'broadcast' => ['icon' => 'fa-paper-plane', 'label' => 'บรอดแคสต์', 'url' => '/broadcast', 'page' => 'broadcast', 'color' => 'purple', 'roles' => ['admin', 'marketing']],
    'drip-campaigns' => ['icon' => 'fa-water', 'label' => 'Drip Campaign', 'url' => '/drip-campaigns', 'page' => 'drip-campaigns', 'color' => 'blue', 'roles' => ['admin', 'marketing']],
    'templates' => ['icon' => 'fa-file-alt', 'label' => 'Templates', 'url' => '/templates', 'page' => 'templates', 'color' => 'slate', 'roles' => ['admin', 'marketing']],

    // Digital Front Door
    'rich-menu' => ['icon' => 'fa-th-large', 'label' => 'Rich Menu', 'url' => '/rich-menu', 'page' => 'rich-menu', 'color' => 'teal', 'roles' => ['admin', 'marketing']],
    'liff-settings' => ['icon' => 'fa-mobile-screen', 'label' => 'ตั้งค่า LIFF', 'url' => '/liff-settings', 'page' => 'liff-settings', 'color' => 'lime', 'roles' => ['admin', 'marketing']],

    // Billing & Orders
    'orders' => ['icon' => 'fa-receipt', 'label' => $ordersMenuLabel, 'url' => '/shop/orders', 'page' => 'orders', 'badge' => $pendingOrders, 'badgeColor' => 'yellow', 'color' => 'orange', 'roles' => ['admin', 'staff']],
    'promotions' => ['icon' => 'fa-star', 'label' => 'โปรโมชั่น', 'url' => '/shop/promotions', 'page' => 'promotions', 'color' => 'amber', 'roles' => ['admin', 'staff']],

    // Inventory
    'products' => ['icon' => 'fa-box', 'label' => 'สินค้า / คลัง', 'url' => '/inventory', 'page' => 'inventory', 'color' => 'blue', 'roles' => ['admin', 'pharmacist']],
    'categories' => ['icon' => 'fa-folder', 'label' => 'หมวดหมู่', 'url' => '/shop/categories', 'page' => 'categories', 'color' => 'lime', 'roles' => ['admin', 'pharmacist']],
    'low-stock' => ['icon' => 'fa-exclamation-triangle', 'label' => 'สินค้าใกล้หมด', 'url' => '/inventory?tab=low-stock', 'page' => 'inventory', 'color' => 'red', 'roles' => ['admin', 'pharmacist']],
    'product-units' => ['icon' => 'fa-balance-scale', 'label' => 'หน่วยสินค้า', 'url' => '/inventory/product-units', 'page' => 'product-units', 'color' => 'emerald', 'roles' => ['admin', 'pharmacist']],
    'sync' => ['icon' => 'fa-sync', 'label' => 'Sync สินค้า', 'url' => '/sync-dashboard', 'page' => 'sync-dashboard', 'color' => 'sky', 'roles' => ['admin', 'owner']],

    // Procurement
    'purchase-orders' => ['icon' => 'fa-file-invoice', 'label' => 'ใบสั่งซื้อ (PO)', 'url' => '/procurement?tab=po', 'page' => 'procurement', 'color' => 'violet', 'roles' => ['admin', 'owner']],
    'goods-receive' => ['icon' => 'fa-truck-loading', 'label' => 'รับสินค้า (GR)', 'url' => '/procurement?tab=gr', 'page' => 'procurement', 'color' => 'teal', 'roles' => ['admin', 'owner']],
    'suppliers' => ['icon' => 'fa-truck', 'label' => 'Suppliers', 'url' => '/procurement?tab=suppliers', 'page' => 'procurement', 'color' => 'slate', 'roles' => ['admin', 'owner']],

    // Accounting
    'accounting' => ['icon' => 'fa-calculator', 'label' => 'บัญชี', 'url' => '/accounting', 'page' => 'accounting', 'color' => 'emerald', 'roles' => ['admin', 'owner']],

    // Facility Setup
    'shop-settings' => ['icon' => 'fa-store', 'label' => 'ข้อมูลสถานพยาบาล', 'url' => '/settings.php?tab=general', 'page' => 'settings', 'color' => 'emerald', 'roles' => ['admin', 'owner']],
    'miniapp-settings' => ['icon' => 'fa-mobile-alt', 'label' => 'ตั้งค่าร้านออนไลน์', 'url' => '/admin/miniapp-settings.php', 'page' => 'miniapp-settings', 'color' => 'violet', 'roles' => ['admin', 'owner']],
    'landing-settings' => ['icon' => 'fa-home', 'label' => 'Landing Page', 'url' => '/admin/landing-settings', 'page' => 'landing-settings', 'color' => 'sky', 'roles' => ['admin', 'owner']],
    'admin-users' => ['icon' => 'fa-users-cog', 'label' => 'บุคลากร & สิทธิ์', 'url' => '/admin-users', 'page' => 'admin-users', 'color' => 'indigo', 'roles' => ['owner', 'admin']],
    'line-accounts' => ['icon' => 'fa-layer-group', 'label' => 'บัญชี LINE', 'url' => '/settings?tab=line', 'page' => 'settings', 'color' => 'green', 'roles' => ['owner', 'admin', 'tech']],
    'telegram' => ['icon' => 'fab fa-telegram', 'label' => 'Telegram', 'url' => '/settings?tab=telegram', 'page' => 'settings', 'color' => 'blue', 'roles' => ['owner', 'admin', 'tech']],
    'ai-settings' => ['icon' => 'fa-key', 'label' => 'ตั้งค่า API Key', 'url' => '/ai-settings', 'page' => 'ai-settings', 'color' => 'violet', 'roles' => ['owner', 'admin', 'tech']],
    'consent-management' => ['icon' => 'fa-shield-alt', 'label' => 'Consent & PDPA', 'url' => '/consent-management', 'page' => 'consent-management', 'color' => 'rose', 'roles' => ['owner', 'admin']],
    'scheduled-reports' => ['icon' => 'fa-calendar-alt', 'label' => 'รายงานอัตโนมัติ', 'url' => '/scheduled?tab=reports', 'page' => 'scheduled', 'color' => 'amber', 'roles' => ['owner', 'admin']],
];

// Get user's quick access preferences
$userQuickAccess = ['messages', 'orders', 'products', 'broadcast'];
$adminUserId = $_SESSION['admin_user']['id'] ?? null;

if ($adminUserId && $db) {
    try {
        $stmt = $db->prepare("SELECT menu_key FROM admin_quick_access WHERE admin_user_id = ? ORDER BY sort_order");
        $stmt->execute([$adminUserId]);
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($keys)) {
            $userQuickAccess = $keys;
        }
    } catch (Throwable $e) {
        logHeaderException($e, 'quick-access');
    }
}

$userRole = getCurrentUserRole($currentUser ?? []);

// Build quick access items (filtered by role + condition)
$quickAccessItems = [];
foreach ($userQuickAccess as $key) {
    if (!isset($quickAccessMenus[$key])) continue;
    $item = $quickAccessMenus[$key];
    if (!hasMenuAccess($item, $userRole)) continue;
    if (isset($item['condition']) && !$item['condition']) continue;
    $quickAccessItems[] = $item;
}

// ============================================================
// Menu Groups (6 Main Groups)
// ============================================================

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
                    $isOdooMode ? ['title' => 'จัดการลูกค้า Odoo', 'href' => '/odoo-dashboard'] : null,
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
            ['title' => 'ติดตามการจ่ายยา', 'icon' => '🔔', 'href' => '/dispense-tracking'],
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
            ['title' => 'สถิติแชท', 'icon' => '📊', 'href' => $inboxUrl . '?tab=analytics'],
            ['title' => 'รายชื่อลูกค้า', 'icon' => '📇', 'href' => '/users'],
            ['title' => 'บรอดแคสต์', 'icon' => '📢', 'href' => '/broadcast'],
            ['title' => 'ระบบสมาชิก', 'icon' => '💳', 'href' => '/membership'],
            ['title' => 'สมาชิกเบอร์ (จ่ายแต้ม)', 'icon' => '🎁', 'href' => '/loyalty-members'],
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
            ['title' => 'ตั้งค่าร้านออนไลน์', 'icon' => '📱', 'href' => '/admin/miniapp-settings.php'],
            ['title' => 'Landing Page', 'icon' => '🏠', 'href' => '/admin/landing-settings'],
            ['title' => 'Rich Menu', 'icon' => '🎨', 'href' => '/rich-menu'],
            ['title' => 'ศูนย์ช่วยเหลือ', 'icon' => '📚', 'href' => '/help'],
        ]
    ],
];

// ============================================================
// Build Visible Menu Groups & Search Index
// ============================================================

$visibleMenuGroups = [];
$searchableMenuItems = [];
$currentGroupTitle = 'Workspace';
$currentMenuLabel = $pageTitle ?? 'Dashboard';

foreach ($menuGroups as $group) {
    if (!empty($group['roles']) && !in_array($userRole, $group['roles'], true)) {
        continue;
    }

    $visibleMenuGroups[] = $group;

    foreach ($group['menus'] as $menu) {
        if (isset($menu['href'])) {
            $searchableMenuItems[] = [
                'title' => $menu['title'],
                'href' => $menu['href'],
                'icon' => $menu['icon'] ?? '•',
                'group' => $group['group_title'],
                'parent' => null,
                'badge' => $menu['badge'] ?? 0,
            ];

            if (str_contains($currentPath, $menu['href'])) {
                $currentGroupTitle = $group['group_title'];
                $currentMenuLabel = $menu['title'];
            }
        } elseif (isset($menu['submenus']) && is_array($menu['submenus'])) {
            foreach ($menu['submenus'] as $submenu) {
                if ($submenu === null) continue;
                $searchableMenuItems[] = [
                    'title' => $submenu['title'],
                    'href' => $submenu['href'],
                    'icon' => $menu['icon'] ?? '•',
                    'group' => $group['group_title'],
                    'parent' => $menu['title'],
                    'badge' => $submenu['badge'] ?? 0,
                ];

                if (str_contains($currentPath, $submenu['href'])) {
                    $currentGroupTitle = $group['group_title'];
                    $currentMenuLabel = $submenu['title'];
                }
            }
        }
    }
}

// Add quick access to search index
foreach ($quickAccessItems as $item) {
    $searchableMenuItems[] = [
        'title' => $item['label'],
        'href' => $item['url'],
        'icon' => '⚡',
        'group' => 'Pinned',
        'parent' => null,
        'badge' => $item['badge'] ?? 0,
    ];
}

$workspaceAlertCount = (int) ($unreadMessages ?? 0) + (int) ($pendingOrders ?? 0);

// ============================================================
// Brand & Subscription Data
// ============================================================

$brandLogo = null;
try {
    $bid = (int) ($currentBotId ?? 0);
    if ($bid > 0 && $db) {
        $brandLogo = safeFetchColumn(
            $db,
            'SELECT shop_logo FROM shop_settings WHERE line_account_id = ? LIMIT 1',
            [$bid],
            ''
        );
    }
} catch (Throwable) {}

if (empty($brandLogo)) {
    $brandLogo = '/uploads/shop/logo_1_1778797967.png';
}

$brandPlan = null;
try {
    $billingHelper = __DIR__ . '/platform-billing-helpers.php';
    if (!function_exists('subscriptionState') && is_file($billingHelper)) {
        require_once $billingHelper;
    }
    $tenantId = class_exists('TenantContext') ? (int) (TenantContext::getCurrentTenantId() ?? 0) : 0;
    if ($tenantId > 0 && function_exists('subscriptionState') && $db) {
        $bst = subscriptionState(Database::platform()->getConnection(), $tenantId);
        $bslug = (string) ($bst['plan_slug'] ?? '');
        $bname = (string) ($bst['plan_name'] ?: ($bslug !== '' ? ucfirst($bslug) : 'Free'));
        $bstate = (string) ($bst['state'] ?? '');

        $brandPlan = match (true) {
            $bstate === 'trial' => ['name' => $bname, 'cls' => 'brand-pill--trial', 'icon' => 'fa-hourglass-half'],
            in_array($bslug, ['monthly', 'yearly'], true) || $bstate === 'active'
                => ['name' => $bname, 'cls' => 'brand-pill--paid', 'icon' => 'fa-crown'],
            default => ['name' => $bname, 'cls' => '', 'icon' => 'fa-leaf'],
        };
    }
} catch (Throwable) {}

// ============================================================
// User Data
// ============================================================

$userDisplayName = htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'] ?? 'Admin');
$userInitial = strtoupper(substr($currentUser['display_name'] ?? $currentUser['username'] ?? 'A', 0, 1));
$userRoleLabel = ucfirst($currentUser['role'] ?? 'Admin');

// ============================================================
// HTML Output
// ============================================================
?><!DOCTYPE html>
<html lang="th" data-theme="<?= htmlspecialchars($_COOKIE['reya_theme'] ?? 'light') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#06C755">
    <meta name="base-url" content="<?= htmlspecialchars($baseUrl) ?>">
    <meta name="line-account-id" content="<?= htmlspecialchars((string) ($currentBotId ?? 1)) ?>">
    <meta name="user-id" content="<?= htmlspecialchars((string) ($adminUserId ?? 'guest')) ?>">
    <title><?= htmlspecialchars($adminFullTitle) ?></title>

    <!-- Favicon -->
    <?php $favicon = $adminFaviconUrl ?: '/assets/images/3.png?v=2'; ?>
    <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($favicon) ?>">
    <link rel="shortcut icon" type="image/x-icon" href="<?= htmlspecialchars($favicon) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($favicon) ?>">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Tailwind (compat): existing admin page bodies + V4 shell markup still use Tailwind
         utility classes (md:hidden / hidden / sm:*). Keep until pages are converted to BEM. -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Styles -->
    <link rel="stylesheet" href="/assets/css/admin-v4.css?v=4.0.0">
    <?php if (isset($extraStyles)) echo $extraStyles; ?>

    <!-- Shell JS (defines window.ReyaAdmin; footer-v4.js depends on it) -->
    <script src="/assets/js/admin-v4.js?v=4.0.0" defer></script>
</head>

<body data-user-id="<?= htmlspecialchars((string) ($adminUserId ?? 'guest')) ?>">

<?php if (defined('REYA_DEMO_MODE') && REYA_DEMO_MODE):
    $supportUrl = defined('REYA_SUPPORT_CONTACT_URL') ? REYA_SUPPORT_CONTACT_URL : 'https://re-ya.com';
?>
<!-- Demo Watermark -->
<div class="demo-watermark" aria-hidden="true">
    <?php for ($i = 0; $i < 16; $i++): ?>
    <div class="demo-watermark__line">ข้อมูลตัวอย่าง · DEMO · ข้อมูลตัวอย่าง · DEMO</div>
    <?php endfor; ?>
</div>
<div class="demo-banner">
    <span>
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#fff;margin-right:7px;vertical-align:middle"></span>
        โหมดทดลอง · ข้อมูลตัวอย่าง (DEMO) — ระบบจะใช้งานจริงหลังได้รับอนุมัติ
    </span>
    <a href="<?= htmlspecialchars($supportUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank">ติดต่อผู้ดูแลเพื่อเปิดใช้ระบบ</a>
    <button class="demo-banner__close" data-demo-close title="ซ่อน">✕</button>
</div>
<?php endif; ?>

<!-- Page Loader -->
<div id="pageLoader" class="page-loader" aria-hidden="true"></div>

<!-- Toast Container -->
<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

<!-- Mobile Overlay -->
<div id="mobileOverlay" class="mobile-overlay" onclick="toggleSidebar()"></div>

<!-- App Layout -->
<div class="reya-app">

    <!-- Sidebar -->
    <aside id="sidebar" class="reya-app__sidebar" role="navigation" aria-label="Main navigation">

        <!-- Brand -->
        <div class="sidebar-brand">
            <div class="sidebar-brand__row">
                <div class="sidebar-brand__logo">
                    <img src="<?= htmlspecialchars($brandLogo) ?>" alt="REYA" loading="eager"
                         onerror="this.style.display='none'; this.parentElement.textContent='R';">
                </div>
                <div class="sidebar-brand__meta">
                    <div class="sidebar-brand__title">REYA Pharmacy</div>
                    <div class="sidebar-brand__subtitle">Pharmacy Admin</div>
                </div>
                <button onclick="toggleSidebar()" class="md:hidden text-gray-400 hover:text-gray-700" aria-label="Close sidebar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sidebar-brand__pills">
                <?php if ($brandPlan): ?>
                <a href="<?= $baseUrl ?>billing.php" class="brand-pill <?= $brandPlan['cls'] ?>" title="แผนการใช้งาน">
                    <i class="fas <?= $brandPlan['icon'] ?>"></i><?= htmlspecialchars($brandPlan['name']) ?>
                </a>
                <?php endif; ?>
                <span class="brand-pill"><i class="fas fa-layer-group"></i><?= count($visibleMenuGroups) ?> sections</span>
                <?php if ($workspaceAlertCount > 0): ?>
                <span class="brand-pill"><i class="fas fa-bell"></i><?= $workspaceAlertCount > 99 ? '99+' : $workspaceAlertCount ?> alerts</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bot Selector -->
        <?php if (!empty($lineAccounts)): ?>
        <div class="bot-selector">
            <div class="bot-card" data-bot-toggle role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false">
                <div class="bot-card__avatar">
                    <?php if ($currentBot && !empty($currentBot['picture_url'])): ?>
                        <img src="<?= htmlspecialchars($currentBot['picture_url']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <i class="fab fa-line"></i>
                    <?php endif; ?>
                </div>
                <div class="bot-card__info">
                    <div class="bot-card__name"><?= htmlspecialchars($currentBot['name'] ?? 'Select Bot') ?></div>
                    <div class="bot-card__id"><?= htmlspecialchars($currentBot['basic_id'] ?? '') ?></div>
                </div>
                <i class="fas fa-chevron-down text-gray-400 text-xs" aria-hidden="true"></i>
            </div>
            <div id="botDropdown" class="dropdown" role="listbox">
                <?php foreach ($lineAccounts as $acc): ?>
                <a href="?switch_bot=<?= $acc['id'] ?>"
                   class="dropdown-item <?= ($currentBot && $currentBot['id'] == $acc['id']) ? 'is-active' : '' ?>"
                   role="option"
                   aria-selected="<?= ($currentBot && $currentBot['id'] == $acc['id']) ? 'true' : 'false' ?>">
                    <div class="bot-card__avatar dropdown-item__avatar">
                        <?php if (!empty($acc['picture_url'])): ?>
                            <img src="<?= htmlspecialchars($acc['picture_url']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <i class="fab fa-line"></i>
                        <?php endif; ?>
                    </div>
                    <span class="dropdown-item__name"><?= htmlspecialchars($acc['name']) ?></span>
                    <?php if ($acc['is_default']): ?>
                    <span class="dropdown-item__badge">Default</span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Navigation -->
        <nav class="sidebar-nav">

            <!-- Quick Access -->
            <?php if (!empty($quickAccessItems)): ?>
            <div class="quick-access">
                <div class="quick-access__header">
                    <div>
                        <div class="quick-access__title">Workspace</div>
                        <div style="font-size:11px;color:#5d7084;margin-top:2px;"><i class="fas fa-thumbtack" style="margin-right:4px;"></i>Pinned shortcuts</div>
                    </div>
                    <a href="<?= $baseUrl ?>settings.php?tab=quick-access" class="text-gray-400 hover:text-green-600" title="ตั้งค่า Quick Access">
                        <i class="fas fa-cog"></i>
                    </a>
                </div>
                <div class="quick-access__grid">
                    <?php foreach ($quickAccessItems as $item):
                        $itemUrl = $baseUrl . ltrim($item['url'], '/');
                    ?>
                    <a href="<?= $itemUrl ?>" class="quick-item" data-nav-track
                       data-nav-title="<?= htmlspecialchars($item['label']) ?>"
                       data-nav-group="Pinned"
                       data-nav-icon="<?= htmlspecialchars($item['icon']) ?>">
                        <div class="quick-item__icon quick-item__icon--<?= $item['color'] ?? 'green' ?>">
                            <i class="fas <?= $item['icon'] ?>"></i>
                        </div>
                        <span class="quick-item__label"><?= htmlspecialchars($item['label']) ?></span>
                        <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
                        <span class="quick-item__badge"><?= $item['badge'] > 99 ? '99+' : $item['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <div id="recentNavSection" class="recent-nav is-hidden">
                    <div class="quick-access__title">Recent</div>
                    <div id="recentNavList" class="recent-nav__list"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Menu Groups -->
            <?php foreach ($visibleMenuGroups as $group):
                $groupHasActive = false;
                $groupBadgeCount = 0;
                foreach ($group['menus'] as $groupMenu) {
                    if (!empty($groupMenu['badge'])) $groupBadgeCount += (int) $groupMenu['badge'];
                    if (isset($groupMenu['href']) && str_contains($currentPath, $groupMenu['href'])) {
                        $groupHasActive = true;
                    }
                    if (isset($groupMenu['submenus']) && is_array($groupMenu['submenus'])) {
                        foreach ($groupMenu['submenus'] as $submenu) {
                            if ($submenu === null) continue;
                            if (!empty($submenu['badge'])) $groupBadgeCount += (int) $submenu['badge'];
                            if (str_contains($currentPath, $submenu['href'])) $groupHasActive = true;
                        }
                    }
                }
                $groupId = htmlspecialchars($group['group_id']);
            ?>
            <div class="menu-group <?= $groupHasActive ? 'has-active' : '' ?>" data-group-id="<?= $groupId ?>">
                <button type="button" class="menu-group__header" data-menu-group="<?= $groupId ?>"
                        aria-expanded="false" aria-controls="group_body_<?= $groupId ?>">
                    <span class="menu-group__icon" aria-hidden="true"><?= $group['group_icon'] ?></span>
                    <span class="menu-group__label"><?= htmlspecialchars($group['group_title']) ?></span>
                    <?php if ($groupBadgeCount > 0): ?>
                    <span class="group-badge"><?= $groupBadgeCount > 99 ? '99+' : $groupBadgeCount ?></span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down menu-group__arrow" aria-hidden="true"></i>
                </button>

                <div id="group_body_<?= $groupId ?>" class="menu-group__body" role="region">
                    <?php foreach ($group['menus'] as $menuIndex => $menu):
                        if (isset($menu['href'])):
                            $menuUrl = $baseUrl . ltrim($menu['href'], '/');
                            $isActive = str_contains($currentPath, $menu['href']);
                    ?>
                    <a href="<?= $menuUrl ?>" class="menu-item <?= $isActive ? 'is-active' : '' ?>"
                       data-nav-track
                       data-nav-title="<?= htmlspecialchars($menu['title']) ?>"
                       data-nav-group="<?= htmlspecialchars($group['group_title']) ?>"
                       data-nav-icon="<?= htmlspecialchars($menu['icon'] ?? '') ?>">
                        <span class="menu-item__icon" aria-hidden="true"><?= $menu['icon'] ?></span>
                        <span class="menu-item__label"><?= htmlspecialchars($menu['title']) ?></span>
                        <?php if (!empty($menu['badge']) && $menu['badge'] > 0): ?>
                        <span class="menu-item__badge"><?= $menu['badge'] > 99 ? '99+' : $menu['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                    <?php elseif (isset($menu['submenus']) && is_array($menu['submenus'])): ?>
                    <div class="nested-menu">
                        <button type="button" class="nested-menu__header" data-nested-menu="<?= $groupId ?>_<?= $menuIndex ?>"
                                aria-expanded="false" aria-controls="nested_body_<?= $groupId ?>_<?= $menuIndex ?>">
                            <span class="nested-menu__icon" aria-hidden="true"><?= $menu['icon'] ?></span>
                            <span class="nested-menu__label"><?= htmlspecialchars($menu['title']) ?></span>
                            <i class="fas fa-chevron-right nested-menu__arrow" aria-hidden="true"></i>
                        </button>
                        <div id="nested_body_<?= $groupId ?>_<?= $menuIndex ?>" class="nested-menu__body" role="region">
                            <?php foreach ($menu['submenus'] as $submenu):
                                if ($submenu === null) continue;
                                $submenuUrl = $baseUrl . ltrim($submenu['href'], '/');
                                $isActive = str_contains($currentPath, $submenu['href']);
                            ?>
                            <a href="<?= $submenuUrl ?>" class="nested-menu__item <?= $isActive ? 'is-active' : '' ?>"
                               data-nav-track
                               data-nav-title="<?= htmlspecialchars($submenu['title']) ?>"
                               data-nav-group="<?= htmlspecialchars($group['group_title']) ?>"
                               data-nav-parent="<?= htmlspecialchars($menu['title']) ?>"
                               data-nav-icon="<?= htmlspecialchars($menu['icon'] ?? '') ?>">
                                <span><?= htmlspecialchars($submenu['title']) ?></span>
                                <?php if (!empty($submenu['badge']) && $submenu['badge'] > 0): ?>
                                <span class="menu-item__badge"><?= $submenu['badge'] > 99 ? '99+' : $submenu['badge'] ?></span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </nav>

        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="sidebar-footer__row">
                <span>REYA Admin v4.0</span>
                <div class="sidebar-footer__links">
                    <a href="<?= $baseUrl ?>help.php" title="Help"><i class="fas fa-question-circle"></i></a>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <div class="reya-app__main">

        <!-- Top Header -->
        <header class="top-header" role="banner">
            <div class="top-header__primary">
                <button onclick="toggleSidebar()" class="md:hidden mr-3 text-gray-500 hover:text-gray-700" aria-label="Open sidebar">
                    <i class="fas fa-bars text-lg"></i>
                </button>
                <div class="page-title-group">
                    <!-- Breadcrumb -->
                    <nav class="breadcrumb" aria-label="Breadcrumb">
                        <span class="breadcrumb__item"><?= htmlspecialchars($currentGroupTitle) ?></span>
                        <span class="breadcrumb__item is-active" aria-current="page"><?= htmlspecialchars($currentMenuLabel) ?></span>
                    </nav>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
                        <?php if ($workspaceAlertCount > 0): ?>
                        <span class="workspace-chip"><i class="fas fa-bell"></i><?= $workspaceAlertCount > 99 ? '99+' : $workspaceAlertCount ?> pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="page-subtitle">
                        <?= htmlspecialchars($currentMenuLabel) ?> · กด <kbd class="kb-key">Ctrl</kbd> + <kbd class="kb-key">K</kbd> เพื่อค้นหา · กด <kbd class="kb-key">?</kbd> ดูคีย์ลัด
                    </div>
                </div>

                <div class="command-launcher hidden md:block">
                    <button type="button" class="command-launcher__btn" onclick="openCommandPalette()" aria-label="Open command palette">
                        <span style="display:flex;align-items:center;gap:10px;min-width:0;">
                            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                            <span class="command-launcher__text">ค้นหาเมนู, หน้าที่ใช้บ่อย หรือกระโดดไป workflow ถัดไป</span>
                        </span>
                        <span class="command-launcher__kbd">Ctrl K</span>
                    </button>
                </div>
            </div>

            <div class="header-actions">
                <?php if ($isOdooMode): ?>
                <a href="/odoo-dashboard" class="header-btn header-btn--odoo" title="Odoo Dashboard">
                    <i class="fas fa-satellite-dish" aria-hidden="true"></i>
                    <span class="hidden sm:inline">Odoo</span>
                </a>
                <?php endif; ?>

                <!-- Quick Access Dropdown -->
                <div class="relative">
                    <button type="button" class="header-btn header-btn--primary" data-dropdown-trigger="quickAccessDropdown" aria-haspopup="true" aria-expanded="false" title="Quick Access">
                        <i class="fas fa-bolt" aria-hidden="true"></i>
                    </button>
                    <div id="quickAccessDropdown" class="dropdown-panel" role="menu">
                        <?php foreach ($quickAccessItems as $item):
                            $itemUrl = $baseUrl . ltrim($item['url'], '/');
                        ?>
                        <a href="<?= $itemUrl ?>" class="dropdown-panel__item" role="menuitem" data-nav-track
                           data-nav-title="<?= htmlspecialchars($item['label']) ?>"
                           data-nav-group="Quick Access">
                            <i class="fas <?= $item['icon'] ?> dropdown-panel__icon" style="color:var(--reya-accent)"></i>
                            <span><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                        <?php endforeach; ?>
                        <div class="dropdown-panel__section" style="border-top:1px solid var(--border-subtle);padding-top:8px;">
                            <a href="<?= $baseUrl ?>settings.php?tab=quick-access" class="dropdown-panel__item" role="menuitem">
                                <i class="fas fa-cog dropdown-panel__icon"></i>
                                <span>ตั้งค่า Quick Access</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- AI Tools Dropdown -->
                <div class="relative">
                    <button type="button" class="header-btn" data-dropdown-trigger="aiToolsDropdown" aria-haspopup="true" aria-expanded="false" title="AI Tools">
                        <i class="fas fa-brain" aria-hidden="true"></i>
                        <i class="fas fa-chevron-down text-xs ml-1" aria-hidden="true"></i>
                    </button>
                    <div id="aiToolsDropdown" class="dropdown-panel" role="menu">
                        <a href="<?= $baseUrl ?>ai-chat.php" class="ai-tool" role="menuitem">
                            <div class="ai-tool__icon ai-tool__icon--blue"><i class="fas fa-comments"></i></div>
                            <div class="ai-tool__info">
                                <div class="ai-tool__name">AI Chat</div>
                                <div class="ai-tool__desc">คุยกับ AI ทั่วไป</div>
                            </div>
                        </a>
                        <a href="<?= $baseUrl ?>onboarding-assistant.php" class="ai-tool" role="menuitem">
                            <div class="ai-tool__icon ai-tool__icon--purple"><i class="fas fa-robot"></i></div>
                            <div class="ai-tool__info">
                                <div class="ai-tool__name">Setup Assistant</div>
                                <div class="ai-tool__desc">ผู้ช่วยตั้งค่าระบบ</div>
                            </div>
                        </a>
                        <a href="<?= $baseUrl ?>ai-settings.php" class="ai-tool" role="menuitem">
                            <div class="ai-tool__icon ai-tool__icon--gray"><i class="fas fa-cog"></i></div>
                            <div class="ai-tool__info">
                                <div class="ai-tool__name">AI Settings</div>
                                <div class="ai-tool__desc">ตั้งค่า API Key</div>
                            </div>
                        </a>
                    </div>
                </div>

                <span class="header-divider" aria-hidden="true"></span>

                <a href="<?= $baseUrl ?><?= ltrim($inboxUrl, '/') ?>.php" class="header-btn" title="Inbox">
                    <i class="fas fa-inbox" aria-hidden="true"></i>
                    <?php if ($unreadMessages > 0): ?>
                    <span class="header-btn__badge"><?= $unreadMessages > 99 ? '99+' : $unreadMessages ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?= $baseUrl ?>shop/orders.php" class="header-btn" title="Orders">
                    <i class="fas fa-shopping-bag" aria-hidden="true"></i>
                    <?php if ($pendingOrders > 0): ?>
                    <span class="header-btn__badge header-btn__badge--yellow"><?= $pendingOrders ?></span>
                    <?php endif; ?>
                </a>

                <span class="header-divider" aria-hidden="true"></span>

                <?php if (in_array($currentUser['role'] ?? '', ['super_admin', 'admin'], true)): ?>
                <a href="<?= $baseUrl ?>billing.php" class="header-btn header-btn--plan" title="แผน & ชำระเงิน">
                    <i class="fas fa-crown" aria-hidden="true"></i>
                </a>
                <?php endif; ?>

                <button type="button" class="header-btn" onclick="toggleTheme()" title="Toggle Theme" aria-label="Toggle dark mode">
                    <i class="fas fa-moon" aria-hidden="true"></i>
                </button>

                <!-- User Menu -->
                <div class="relative">
                    <button type="button" class="user-menu" data-dropdown-trigger="userMenuDropdown" aria-haspopup="true" aria-expanded="false">
                        <div class="user-avatar" aria-hidden="true"><?= $userInitial ?></div>
                        <span class="user-name hidden sm:block"><?= $userDisplayName ?></span>
                        <i class="fas fa-chevron-down text-gray-400 text-xs hidden sm:block" aria-hidden="true"></i>
                    </button>
                    <div id="userMenuDropdown" class="dropdown-panel" role="menu">
                        <div class="dropdown-panel__header">
                            <div class="dropdown-panel__name"><?= $userDisplayName ?></div>
                            <div class="dropdown-panel__role"><?= htmlspecialchars($userRoleLabel) ?></div>
                        </div>
                        <div class="dropdown-panel__section">
                            <a href="<?= $baseUrl ?>admin-users.php" class="dropdown-panel__item" role="menuitem">
                                <i class="fas fa-user-cog dropdown-panel__icon"></i>
                                <span>Account Settings</span>
                            </a>
                            <a href="<?= $baseUrl ?>help.php" class="dropdown-panel__item" role="menuitem">
                                <i class="fas fa-question-circle dropdown-panel__icon"></i>
                                <span>Help & Support</span>
                            </a>
                            <button type="button" data-admin-tour-launch class="dropdown-panel__item" role="menuitem" style="width:100%;text-align:left;">
                                <i class="fas fa-graduation-cap dropdown-panel__icon"></i>
                                <span>🎓 ทัวร์ระบบ (Tour)</span>
                            </button>
                        </div>
                        <div class="dropdown-panel__section">
                            <a href="<?= $baseUrl ?>auth/logout.php" class="dropdown-panel__item is-danger" role="menuitem">
                                <i class="fas fa-sign-out-alt dropdown-panel__icon"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Command Palette -->
        <div id="commandPalette" class="command-palette" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="commandPaletteInput">
            <div class="command-palette__backdrop" onclick="closeCommandPalette()"></div>
            <div class="command-palette__dialog">
                <div class="command-palette__input-wrap">
                    <i class="fas fa-magnifying-glass text-slate-400" aria-hidden="true"></i>
                    <input id="commandPaletteInput" type="text" placeholder="พิมพ์ชื่อเมนู, กลุ่มงาน, หรือ workflow ที่ต้องการ..." autocomplete="off" aria-label="Search menu">
                    <span class="command-launcher__kbd">Esc</span>
                </div>
                <div class="command-palette__meta">
                    <span>Jump to page</span>
                    <span id="commandPaletteCount"><?= count($searchableMenuItems) ?> items</span>
                </div>
                <div id="commandPaletteResults" class="command-palette__results">
                    <?php foreach ($searchableMenuItems as $menuItem):
                        $menuItemUrl = $baseUrl . ltrim($menuItem['href'], '/');
                        $menuMeta = $menuItem['group'] . (!empty($menuItem['parent']) ? ' · ' . $menuItem['parent'] : '');
                    ?>
                    <a href="<?= $menuItemUrl ?>" class="command-result" data-command-item data-nav-track
                       data-nav-title="<?= htmlspecialchars($menuItem['title']) ?>"
                       data-nav-group="<?= htmlspecialchars($menuItem['group']) ?>"
                       data-nav-parent="<?= htmlspecialchars($menuItem['parent'] ?? '') ?>"
                       data-nav-icon="<?= htmlspecialchars($menuItem['icon'] ?? '') ?>">
                        <span class="command-result__icon" aria-hidden="true"><?= htmlspecialchars($menuItem['icon'] ?? '•') ?></span>
                        <span class="command-result__copy">
                            <span class="command-result__title"><?= htmlspecialchars($menuItem['title']) ?></span>
                            <span class="command-result__meta"><?= htmlspecialchars($menuMeta) ?></span>
                        </span>
                        <?php if (!empty($menuItem['badge'])): ?>
                        <span class="command-result__badge"><?= $menuItem['badge'] > 99 ? '99+' : $menuItem['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                    <div id="commandPaletteEmpty" class="command-result__empty hidden">
                        ไม่พบเมนูที่ตรงกับคำค้น ลองพิมพ์ชื่อกลุ่ม เช่น Inbox, Dashboard, Orders
                    </div>
                </div>
            </div>
        </div>

        <!-- Keyboard Help Overlay -->
        <div id="keyboardHelp" class="keyboard-help" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="keyboardHelpTitle">
            <div class="keyboard-help__backdrop" onclick="ReyaAdmin.keyboard.close()"></div>
            <div class="keyboard-help__dialog">
                <div class="keyboard-help__header">
                    <h2 id="keyboardHelpTitle" class="keyboard-help__title">⌨️ คีย์ลัด (Keyboard Shortcuts)</h2>
                    <button type="button" class="header-btn" data-close-help aria-label="Close keyboard help">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="keyboard-help__body">
                    <div class="kb-shortcut-group">
                        <div class="kb-shortcut-group__title">การนำทาง</div>
                        <div class="kb-row"><span class="kb-row__label">เปิด Command Palette</span><span class="kb-row__keys"><kbd class="kb-key">Ctrl</kbd><kbd class="kb-key">K</kbd></span></div>
                        <div class="kb-row"><span class="kb-row__label">เปิด/ปิด Sidebar (มือถือ)</span><span class="kb-row__keys"><kbd class="kb-key">Esc</kbd></span></div>
                        <div class="kb-row"><span class="kb-row__label">เปิด Keyboard Help</span><span class="kb-row__keys"><kbd class="kb-key">?</kbd></span></div>
                    </div>
                    <div class="kb-shortcut-group">
                        <div class="kb-shortcut-group__title">Command Palette</div>
                        <div class="kb-row"><span class="kb-row__label">เลือกรายการถัดไป</span><span class="kb-row__keys"><kbd class="kb-key">↓</kbd></span></div>
                        <div class="kb-row"><span class="kb-row__label">เลือกรายการก่อนหน้า</span><span class="kb-row__keys"><kbd class="kb-key">↑</kbd></span></div>
                        <div class="kb-row"><span class="kb-row__label">เปิดหน้าที่เลือก</span><span class="kb-row__keys"><kbd class="kb-key">Enter</kbd></span></div>
                        <div class="kb-row"><span class="kb-row__label">ปิด</span><span class="kb-row__keys"><kbd class="kb-key">Esc</kbd></span></div>
                    </div>
                    <div class="kb-shortcut-group">
                        <div class="kb-shortcut-group__title">ทั่วไป</div>
                        <div class="kb-row"><span class="kb-row__label">สลับ Dark/Light Mode</span><span class="kb-row__keys"><kbd class="kb-key">Ctrl</kbd><kbd class="kb-key">Shift</kbd><kbd class="kb-key">L</kbd></span></div>
                        <div class="kb-row"><span class="kb-row__label">ปิด Dropdown/Modal ทั้งหมด</span><span class="kb-row__keys"><kbd class="kb-key">Esc</kbd></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="reya-app__content">
