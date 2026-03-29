<?php
/**
 * Dashboard - Consolidated Dashboard Page
 * รวมหน้า Executive Dashboard และ CRM Dashboard เป็นหน้าเดียว
 * เมนูย้ายไปอยู่ใน Sidebar แล้ว
 * 
 * @package FileConsolidation
 * @version 3.0.0
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

$tabMeta = [
    'executive' => ['icon' => 'fa-chart-line', 'desc' => 'ภาพรวมการทำงานและวิเคราะห์ประจำวัน'],
    'crm' => ['icon' => 'fa-users-cog', 'desc' => 'ศูนย์กลางจัดการลูกค้าและ Automation'],
];

$extraStyles = '
<link rel="stylesheet" href="assets/css/design-tokens.css">
<link rel="stylesheet" href="assets/css/components.css">
<style>
.db-shell {
    max-width: 1440px;
    margin: 0 auto;
}

.db-section {
    background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(247,249,252,0.98) 100%);
    border: 1px solid #d9e2ec;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
    overflow: hidden;
}

.db-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(180deg, rgba(248,250,252,0.95) 0%, rgba(241,245,249,0.92) 100%);
}

.db-section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 700;
    color: #132235;
    letter-spacing: -0.01em;
}

.db-section-title i {
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    font-size: 13px;
}

.db-section-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid;
}

.db-section-body {
    padding: 20px;
}

.db-section-body-flush {
    padding: 0;
}

.db-kpi {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 20px;
    background: linear-gradient(180deg, rgba(255,255,255,0.99) 0%, rgba(248,250,252,0.98) 100%);
    border: 1px solid #d9e2ec;
    border-radius: 16px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
    transition: all 0.15s ease;
}

.db-kpi:hover {
    border-color: #b8c6d6;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.07);
}

.db-kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.15);
}

.db-kpi-copy {
    min-width: 0;
}

.db-kpi-label {
    font-size: 12px;
    font-weight: 600;
    color: #5f7286;
    margin-bottom: 2px;
}

.db-kpi-value {
    font-size: 26px;
    font-weight: 800;
    color: #132235;
    line-height: 1.15;
    letter-spacing: -0.02em;
}

.db-kpi-meta {
    font-size: 11px;
    color: #74869a;
    margin-top: 2px;
}

.db-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 32px 16px;
    color: #94a3b8;
    text-align: center;
}

.db-empty i {
    font-size: 32px;
    color: #cbd5e1;
}

.db-empty p {
    font-size: 13px;
    font-weight: 500;
}

.db-list-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.12s ease;
}

.db-list-item:last-child {
    border-bottom: none;
}

.db-list-item:hover {
    background: #f8fafc;
}

.db-action-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    color: #0f766e;
    background: rgba(15, 118, 110, 0.08);
    border: 1px solid rgba(15, 118, 110, 0.14);
    text-decoration: none;
    transition: all 0.15s ease;
}

.db-action-link:hover {
    background: rgba(15, 118, 110, 0.14);
    color: #0f4c5c;
}

.db-tab-strip {
    display: flex;
    gap: 6px;
    padding: 4px;
    background: linear-gradient(180deg, #edf2f7 0%, #e2e8f0 100%);
    border-radius: 14px;
    border: 1px solid #d9e2ec;
}

.db-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 11px;
    font-size: 13px;
    font-weight: 600;
    color: #5f7286;
    text-decoration: none;
    transition: all 0.15s ease;
    border: 1px solid transparent;
}

.db-tab:hover {
    color: #243447;
    background: rgba(255,255,255,0.6);
}

.db-tab.active {
    background: linear-gradient(180deg, rgba(255,255,255,0.99) 0%, rgba(248,250,252,0.98) 100%);
    color: #0f4c5c;
    border-color: #d9e2ec;
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
}

.db-tab i {
    font-size: 14px;
}
</style>
';

require_once 'includes/header.php';
?>

<div class="db-shell space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="db-tab-strip">
            <?php foreach ($validTabs as $tabKey):
                $meta = $tabMeta[$tabKey] ?? [];
                $title = $pageTitles[$tabKey] ?? ucfirst($tabKey);
                $isActive = $activeTab === $tabKey;
            ?>
                <a href="?tab=<?= $tabKey ?>" class="db-tab <?= $isActive ? 'active' : '' ?>">
                    <i class="fas <?= $meta['icon'] ?? 'fa-circle' ?>"></i>
                    <?= htmlspecialchars($title) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

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

<?php require_once 'includes/footer.php'; ?>
