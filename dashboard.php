<?php
/**
 * Dashboard - Consolidated Dashboard Page
 * รวมหน้า Executive Dashboard และ CRM Dashboard เป็นหน้าเดียวแบบ Tab-based
 * 
 * @package FileConsolidation
 * @version 1.0.0
 * 
 * Consolidates:
 * - executive-dashboard.php → ?tab=executive
 * - crm-dashboard.php → ?tab=crm
 * 
 * Requirements: 10.1, 10.2, 10.3, 10.4
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth_check.php';
require_once 'includes/components/tabs.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Define tabs
$tabs = [
    'executive' => [
        'label' => 'Executive Overview',
        'icon' => 'fas fa-chart-line'
    ],
    'crm' => [
        'label' => 'CRM Dashboard',
        'icon' => 'fas fa-users'
    ]
];

// Get active tab
$activeTab = getActiveTab($tabs, 'executive');

// Set page title based on active tab
$pageTitles = [
    'executive' => 'Executive Dashboard',
    'crm' => 'CRM Dashboard'
];
$pageTitle = $pageTitles[$activeTab] ?? 'Dashboard';

require_once 'includes/header.php';

// Output tab styles
echo getTabsStyles();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">📊 Dashboard</h1>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <?php echo renderTabs($tabs, $activeTab, ['preserveParams' => ['date']]); ?>

    <!-- Tab Content -->
    <div class="tab-panel">
        <?php
        switch ($activeTab) {
            case 'crm':
                include 'includes/dashboard/crm.php';
                break;
            case 'executive':
            default:
                include 'includes/dashboard/executive.php';
                break;
        }
        ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
