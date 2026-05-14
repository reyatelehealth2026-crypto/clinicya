<?php
/**
 * Rich Menu - Consolidated (Tab-based)
 * รวม: Static Rich Menu + Dynamic Rich Menu + Switch Rich Menu
 * 
 * Tabs:
 * - static: สร้างและจัดการ Rich Menu ด้วย Visual Editor
 * - dynamic: จัดการ Rich Menu แบบ Dynamic ตามเงื่อนไขผู้ใช้
 * - switch: สร้าง Rich Menu แบบสลับหน้าได้
 * 
 * @package FileConsolidation
 * @version 2.0.0
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/LineAPI.php';
require_once 'classes/LineAccountManager.php';
require_once 'includes/components/tabs.php';
require_once __DIR__ . '/includes/components/page-header.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Rich Menu';

// Get current bot ID from session
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Get LineAPI for current bot
$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($currentBotId);

// Define tabs
$tabs = [
    'static' => ['label' => 'Static', 'icon' => 'fas fa-th-large'],
    'dynamic' => ['label' => 'Dynamic', 'icon' => 'fas fa-cogs'],
    'switch' => ['label' => 'สลับหน้า', 'icon' => 'fas fa-exchange-alt'],
];

// Get active tab
$activeTab = getActiveTab($tabs, 'static');

require_once 'includes/header.php';
?>

<?= getPageHeaderStyles() ?>

<?= renderPageHeader(
    'Rich Menu',
    'สร้างและจัดการ Rich Menu สำหรับ LINE Official Account',
    null,
    [
        ['label' => 'Marketing', 'href' => null],
        ['label' => 'Rich Menu', 'href' => null],
    ]
) ?>

<!-- Tab Styles -->
<?= getTabsStyles() ?>

<!-- Tab Navigation -->
<?= renderTabs($tabs, $activeTab) ?>

<!-- Tab Content -->
<div class="tab-content">
    <div class="tab-panel">
        <?php
        switch ($activeTab) {
            case 'dynamic':
                include 'includes/rich-menu/dynamic.php';
                break;

            case 'switch':
                include 'includes/rich-menu/switch.php';
                break;

            case 'static':
            default:
                include 'includes/rich-menu/static.php';
                break;
        }
        ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
