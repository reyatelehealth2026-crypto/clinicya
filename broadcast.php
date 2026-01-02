<?php
/**
 * Broadcast - ส่งข้อความแบบ Broadcast (Consolidated)
 * รวม: Broadcast Send + Catalog Builder + Products + Stats
 * 
 * Tabs:
 * - send: ส่งข้อความ Broadcast ทั่วไป
 * - catalog: Drag & Drop Catalog Builder
 * - products: Broadcast สินค้าพร้อม Auto Tag
 * - stats: สถิติ Broadcast
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
require_once 'classes/AdvancedCRM.php';
require_once 'includes/components/tabs.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Broadcast';

// Get current bot ID
$currentBotId = $_SESSION['current_bot_id'] ?? null;
if (!$currentBotId) {
    $lineManager = new LineAccountManager($db);
    $defaultAccount = $lineManager->getDefaultAccount();
    if ($defaultAccount) {
        $currentBotId = $defaultAccount['id'];
        $_SESSION['current_bot_id'] = $currentBotId;
    }
}

// Define tabs
$tabs = [
    'send' => ['label' => 'ส่งข้อความ', 'icon' => 'fas fa-paper-plane'],
    'catalog' => ['label' => 'Catalog Builder', 'icon' => 'fas fa-layer-group'],
    'products' => ['label' => 'สินค้า + Auto Tag', 'icon' => 'fas fa-box'],
    'stats' => ['label' => 'สถิติ', 'icon' => 'fas fa-chart-bar'],
];

// Get active tab
$activeTab = getActiveTab($tabs, 'send');

require_once 'includes/header.php';
?>

<div class="content-area">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-800">📢 Broadcast</h2>
            <p class="text-sm text-gray-500">ส่งข้อความถึงลูกค้าแบบ Broadcast</p>
        </div>
        <div class="flex gap-2">
            <a href="templates.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">
                <i class="fas fa-file-alt mr-1"></i>Templates
            </a>
            <a href="flex-builder.php" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
                <i class="fas fa-magic mr-1"></i>Flex Builder
            </a>
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
                case 'catalog':
                    include 'includes/broadcast/catalog.php';
                    break;
                    
                case 'products':
                    include 'includes/broadcast/products.php';
                    break;
                    
                case 'stats':
                    include 'includes/broadcast/stats.php';
                    break;
                    
                case 'send':
                default:
                    include 'includes/broadcast/send.php';
                    break;
            }
            ?>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
