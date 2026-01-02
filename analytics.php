<?php
/**
 * สถิติรวม - Analytics Dashboard (Consolidated)
 * รวม: ภาพรวม + วิเคราะห์ขั้นสูง + CRM Analytics + สถิติแยกตามบอท
 * 
 * Tabs:
 * - overview: ภาพรวมสถิติทั่วไป
 * - advanced: วิเคราะห์ขั้นสูง (MVC)
 * - crm: CRM Analytics
 * - account: สถิติแยกตามบอท
 * 
 * @package FileConsolidation
 * @version 2.0.0
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/components/tabs.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'สถิติรวม';
$lineAccountId = $_SESSION['current_bot_id'] ?? null;

// Define tabs
$tabs = [
    'overview' => ['label' => 'ภาพรวม', 'icon' => 'fas fa-chart-line'],
    'advanced' => ['label' => 'วิเคราะห์ขั้นสูง', 'icon' => 'fas fa-chart-bar'],
    'crm' => ['label' => 'CRM', 'icon' => 'fas fa-users'],
    'account' => ['label' => 'แยกตามบอท', 'icon' => 'fas fa-robot'],
];

// Get active tab
$activeTab = getActiveTab($tabs, 'overview');

// Date range filter (for overview tab)
$period = $_GET['period'] ?? '30';
$startDate = $_GET['start'] ?? date('Y-m-d', strtotime("-{$period} days"));
$endDate = $_GET['end'] ?? date('Y-m-d');

require_once 'includes/header.php';
?>

<div class="content-area">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-800">📊 สถิติรวม</h2>
            <p class="text-sm text-gray-500">ภาพรวมข้อมูลลูกค้า ข้อความ และการตลาด</p>
        </div>
        
        <?php if ($activeTab === 'overview'): ?>
        <!-- Period Filter (only for overview tab) -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex bg-white rounded-lg border overflow-hidden">
                <a href="?tab=overview&period=7" class="px-3 py-2 text-sm <?= $period == '7' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">7 วัน</a>
                <a href="?tab=overview&period=30" class="px-3 py-2 text-sm <?= $period == '30' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">30 วัน</a>
                <a href="?tab=overview&period=90" class="px-3 py-2 text-sm <?= $period == '90' ? 'bg-purple-600 text-white' : 'hover:bg-gray-50' ?>">90 วัน</a>
            </div>
            <form class="flex items-center gap-2">
                <input type="hidden" name="tab" value="overview">
                <input type="date" name="start" value="<?= $startDate ?>" class="px-3 py-2 border rounded-lg text-sm">
                <span class="text-gray-400">-</span>
                <input type="date" name="end" value="<?= $endDate ?>" class="px-3 py-2 border rounded-lg text-sm">
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Tab Styles -->
    <?= getTabsStyles() ?>
    
    <!-- Tab Navigation -->
    <?= renderTabs($tabs, $activeTab, ['preserveParams' => ['period', 'start', 'end', 'days', 'account_id', 'date_from', 'date_to']]) ?>
    
    <!-- Tab Content -->
    <div class="tab-content">
        <div class="tab-panel">
            <?php
            switch ($activeTab) {
                case 'advanced':
                    include 'includes/analytics/advanced.php';
                    break;
                    
                case 'crm':
                    include 'includes/analytics/crm.php';
                    break;
                    
                case 'account':
                    include 'includes/analytics/account.php';
                    break;
                    
                case 'overview':
                default:
                    include 'includes/analytics/overview.php';
                    break;
            }
            ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
