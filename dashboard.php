<?php
/**
 * Dashboard - Consolidated Dashboard Page
 * รวมหน้า Executive Dashboard และ CRM Dashboard เป็นหน้าเดียว
 * เมนูย้ายไปอยู่ใน Sidebar แล้ว
 * 
 * @package FileConsolidation
 * @version 2.0.0
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
require_once 'includes/shop-data-source.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;

$orderDataSource = getShopOrderDataSource($db, $currentBotId);
$isOdooMode = $orderDataSource === 'odoo';

// Get active tab from URL
$activeTab = $_GET['tab'] ?? 'executive';

// Validate tab
$validTabs = ['executive', 'crm'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'executive';
}

// Trigger scheduled broadcasts in background
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$triggerUrl = $baseUrl . '/api/process_scheduled_broadcasts.php';
$context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 1]]);
@file_get_contents($triggerUrl, false, $context);

// Set page title based on active tab
$pageTitles = [
    'executive' => 'Executive Dashboard',
    'crm' => 'CRM Dashboard',
];
$pageTitle = $pageTitles[$activeTab] ?? 'Dashboard';

// Include custom glassmorphism styles
$extraStyles = '
<link rel="stylesheet" href="assets/css/design-tokens.css">
<link rel="stylesheet" href="assets/css/glassmorphism.css">
<link rel="stylesheet" href="assets/css/components.css">
<style>
.dashboard-glass {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(20px) saturate(180%);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}
</style>
';

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <!-- Tab Content -->
    <div class="dashboard-glass">
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
