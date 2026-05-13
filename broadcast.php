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
require_once 'classes/ActivityLogger.php';
require_once 'includes/components/tabs.php';
require_once __DIR__ . '/includes/components/page-header.php';

$db = Database::getInstance()->getConnection();
$activityLogger = new ActivityLogger($db);
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

<?= getPageHeaderStyles() ?>

<?= renderPageHeader(
    'Broadcast',
    'ส่งข้อความถึงลูกค้าแบบ Broadcast',
    [
        'label'   => 'Flex Builder',
        'icon'    => 'fas fa-magic',
        'href'    => 'flex-builder.php',
        'type'    => 'link',
        'variant' => 'success',
    ],
    [
        ['label' => 'Marketing', 'href' => null],
        ['label' => 'Broadcast', 'href' => null],
    ]
) ?>

<div style="margin-bottom:var(--space-4,16px);">
    <a href="templates.php" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1px solid var(--color-slate-200);border-radius:var(--radius-md,12px);font-size:var(--text-sm,14px);font-weight:500;color:var(--color-dark-700);background:#ffffff;text-decoration:none;transition:all 150ms ease;">
        <i class="fas fa-file-alt"></i>Templates
    </a>
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

<?php require_once 'includes/footer.php'; ?>
