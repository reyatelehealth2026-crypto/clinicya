<?php
/**
 * Scheduled - ตั้งเวลาส่งข้อความและรายงาน (Consolidated)
 * รวม: Scheduled Messages + Scheduled Reports
 * 
 * Tabs:
 * - messages: ตั้งเวลาส่งข้อความล่วงหน้า
 * - reports: รายงานอัตโนมัติส่งทาง LINE
 * 
 * @package FileConsolidation
 * @version 2.0.0
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/components/tabs.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Scheduled';

// Get current bot ID
$currentBotId = $_SESSION['current_bot_id'] ?? null;
if (!$currentBotId) {
    require_once 'classes/LineAccountManager.php';
    $lineManager = new LineAccountManager($db);
    $defaultAccount = $lineManager->getDefaultAccount();
    if ($defaultAccount) {
        $currentBotId = $defaultAccount['id'];
        $_SESSION['current_bot_id'] = $currentBotId;
    }
}

// Define tabs
$tabs = [
    'messages' => ['label' => 'ข้อความ', 'icon' => 'fas fa-clock'],
    'reports' => ['label' => 'รายงานอัตโนมัติ', 'icon' => 'fas fa-calendar-alt'],
];

// Get active tab
$activeTab = getActiveTab($tabs, 'messages');

require_once 'includes/header.php';
?>

<div class="content-area">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-800">⏰ Scheduled</h2>
            <p class="text-sm text-gray-500">ตั้งเวลาส่งข้อความและรายงานอัตโนมัติ</p>
        </div>
    </div>
    
    <!-- Tab Styles -->
    <?= getTabsStyles() ?>
    
    <!-- Tab Navigation -->
    <?= renderTabs($tabs, $activeTab) ?>
    
    <!-- Tab Content -->
    <div class="tab-content">
        <div class="tab-panel">
            <?php
            switch ($activeTab) {
                case 'reports':
                    include 'includes/scheduled/reports.php';
                    break;
                    
                case 'messages':
                default:
                    include 'includes/scheduled/messages.php';
                    break;
            }
            ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
