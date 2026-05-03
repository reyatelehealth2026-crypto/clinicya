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
$isOdooMode = ($orderDataSource === 'odoo')
    && defined('ODOO_INTEGRATION_ENABLED')
    && ODOO_INTEGRATION_ENABLED === true;

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
    background: #ffffff;
    border: 1px solid #d1d9e6;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 6px 16px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}

.db-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 20px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}

.db-section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    letter-spacing: -0.01em;
}

.db-section-title i {
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
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
    background: #ffffff;
    border: 1px solid #d1d9e6;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 4px 12px rgba(15, 23, 42, 0.04);
    transition: all 0.18s ease;
}

.db-kpi:hover {
    border-color: #94a3b8;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 8px 24px rgba(15, 23, 42, 0.08);
    transform: translateY(-1px);
}

.db-kpi-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    flex-shrink: 0;
}

.db-kpi-copy {
    min-width: 0;
    flex: 1;
}

.db-kpi-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 2px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.db-kpi-value {
    font-size: 24px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
    letter-spacing: -0.02em;
}

.db-kpi-meta {
    font-size: 11px;
    color: #64748b;
    margin-top: 2px;
    font-weight: 500;
}

.db-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 36px 16px;
    color: #94a3b8;
    text-align: center;
}

.db-empty i {
    font-size: 28px;
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
    padding: 12px 20px;
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
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    color: #0f766e;
    background: #f0fdfa;
    border: 1px solid #99f6e4;
    text-decoration: none;
    transition: all 0.15s ease;
}

.db-action-link:hover {
    background: #ccfbf1;
    border-color: #5eead4;
}

.db-tab-strip {
    display: inline-flex;
    gap: 4px;
    padding: 4px;
    background: #e2e8f0;
    border-radius: 12px;
    border: 1px solid #cbd5e1;
}

.db-tab {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 18px;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    transition: all 0.15s ease;
    border: 1px solid transparent;
}

.db-tab:hover {
    color: #334155;
    background: rgba(255,255,255,0.5);
}

.db-tab.active {
    background: #ffffff;
    color: #0f766e;
    border-color: #cbd5e1;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    font-weight: 700;
}

.db-tab i {
    font-size: 14px;
}

.db-kpi--alert {
    border-color: #fca5a5 !important;
    background: #fff5f5 !important;
}

.db-section--alert {
    border-color: #fca5a5 !important;
}

.db-section--alert .db-section-header {
    background: #fef2f2 !important;
    border-bottom-color: #fecaca !important;
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
