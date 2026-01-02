<?php
/**
 * Membership Management - Tab-based Consolidated Page
 * รวมหน้า members.php, admin-rewards.php, admin-points-settings.php
 * 
 * @package FileConsolidation
 * @version 1.0.0
 * 
 * Requirements: 19.1, 19.2, 19.3, 19.4
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/components/tabs.php';

// Initialize database and session variables
$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;

// Initialize LoyaltyPoints class if available
$loyalty = null;
try {
    require_once __DIR__ . '/classes/LoyaltyPoints.php';
    $loyalty = new LoyaltyPoints($db, $lineAccountId);
} catch (Exception $e) {
    // LoyaltyPoints class not available
}

// Define tabs
$tabs = [
    'members' => [
        'label' => 'สมาชิก',
        'icon' => 'fas fa-users'
    ],
    'rewards' => [
        'label' => 'รางวัลแลกแต้ม',
        'icon' => 'fas fa-gift'
    ],
    'settings' => [
        'label' => 'ตั้งค่าแต้ม',
        'icon' => 'fas fa-cog'
    ]
];

// Get active tab
$activeTab = getActiveTab($tabs, 'members');
$pageTitle = 'จัดการสมาชิก';

// Set page title based on active tab
switch ($activeTab) {
    case 'rewards':
        $pageTitle = 'รางวัลแลกแต้ม';
        break;
    case 'settings':
        $pageTitle = 'ตั้งค่าระบบแต้ม';
        break;
    default:
        $pageTitle = 'จัดการสมาชิก';
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">
        <i class="fas fa-id-card text-purple-600 mr-2"></i><?= $pageTitle ?>
    </h1>
    <p class="text-gray-500 mt-1">จัดการสมาชิก รางวัล และระบบแต้มสะสม</p>
</div>

<?php 
// Output tab styles
echo getTabsStyles();

// Render tabs
echo renderTabs($tabs, $activeTab, ['style' => 'pills']);
?>

<!-- Tab Content -->
<div class="tab-panel">
<?php
// Load content based on active tab
switch ($activeTab) {
    case 'rewards':
        if ($loyalty) {
            include __DIR__ . '/includes/membership/rewards.php';
        } else {
            echo '<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">';
            echo '<h2 class="text-xl font-bold text-yellow-800 mb-4"><i class="fas fa-exclamation-triangle mr-2"></i>ไม่พบ LoyaltyPoints Class</h2>';
            echo '<p class="text-yellow-700">กรุณาตรวจสอบว่าไฟล์ classes/LoyaltyPoints.php มีอยู่และถูกต้อง</p>';
            echo '</div>';
        }
        break;
        
    case 'settings':
        if ($loyalty) {
            include __DIR__ . '/includes/membership/settings.php';
        } else {
            echo '<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">';
            echo '<h2 class="text-xl font-bold text-yellow-800 mb-4"><i class="fas fa-exclamation-triangle mr-2"></i>ไม่พบ LoyaltyPoints Class</h2>';
            echo '<p class="text-yellow-700">กรุณาตรวจสอบว่าไฟล์ classes/LoyaltyPoints.php มีอยู่และถูกต้อง</p>';
            echo '</div>';
        }
        break;
        
    default: // members
        include __DIR__ . '/includes/membership/members.php';
        break;
}
?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
