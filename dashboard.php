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

// Trigger scheduled broadcasts in background (non-blocking when fastcgi available)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
$triggerUrl = $baseUrl . '/api/process_scheduled_broadcasts.php';
$broadcastCtx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 1]]);
if (function_exists('fastcgi_finish_request')) {
    register_shutdown_function(function () use ($triggerUrl, $broadcastCtx) {
        @file_get_contents($triggerUrl, false, $broadcastCtx);
    });
} else {
    @file_get_contents($triggerUrl, false, $broadcastCtx);
}

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

// Load Archetype B partials — styles emitted via get*Styles() calls below
require_once __DIR__ . '/includes/components/kpi-card.php';
require_once __DIR__ . '/includes/components/section-card.php';
require_once __DIR__ . '/includes/components/period-selector.php';

$extraStyles = '<link rel="stylesheet" href="assets/css/design-tokens.css">
<link rel="stylesheet" href="assets/css/components.css">'
    . getKpiCardStyles()
    . getSectionCardStyles()
    . getPeriodSelectorStyles()
    . '
<style>
/* ── Dashboard shell layout ──────────────────────────────────────── */
.db-shell {
    max-width: 1440px;
    margin: 0 auto;
}

/* ── Alert modifiers for new archetype components ────────────────── */
.kpi-card--alert {
    border-color: #fca5a5 !important;
    background: #fff5f5 !important;
}
.dark .kpi-card--alert {
    border-color: rgba(244,63,94,.4) !important;
    background: rgba(244,63,94,.07) !important;
}
.section-card--alert {
    border-color: #fca5a5 !important;
}
.section-card--alert .section-card__head {
    background: #fef2f2 !important;
}
.section-card--alert .section-card__head::after {
    background: linear-gradient(90deg, #fecaca, transparent) !important;
}
.dark .section-card--alert {
    border-color: rgba(244,63,94,.4) !important;
}
.dark .section-card--alert .section-card__head {
    background: rgba(244,63,94,.07) !important;
}

/* ── Section-badge (used by executive.php problem count) ─────────── */
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

/* ── Legacy db-* shim — 30-day deprecation grace period ──────────── */
.db-section { background:#fff; border:1px solid var(--color-slate-200); border-radius:var(--radius-lg); box-shadow:0 1px 3px rgba(15,23,42,.06),0 6px 16px rgba(15,23,42,.04); overflow:hidden; }
.db-section-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
.db-section-title { display:flex; align-items:center; gap:10px; font-size:14px; font-weight:700; color:#1e293b; letter-spacing:-0.01em; }
.db-section-title i { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; border-radius:9px; font-size:13px; }
.db-section-body { padding:20px; }
.db-section-body-flush { padding:0; }
.db-kpi { display:flex; align-items:center; gap:14px; padding:18px 20px; background:#fff; border:1px solid #d1d9e6; border-radius:14px; box-shadow:0 1px 3px rgba(15,23,42,.06),0 4px 12px rgba(15,23,42,.04); transition:all .18s ease; }
.db-kpi:hover { border-color:#94a3b8; box-shadow:0 1px 3px rgba(15,23,42,.06),0 8px 24px rgba(15,23,42,.08); transform:translateY(-1px); }
.db-kpi-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
.db-kpi-copy { min-width:0; flex:1; }
.db-kpi-label { font-size:12px; font-weight:600; color:#64748b; margin-bottom:2px; text-transform:uppercase; letter-spacing:.03em; }
.db-kpi-value { font-size:24px; font-weight:800; color:#0f172a; line-height:1.2; letter-spacing:-0.02em; }
.db-kpi-meta { font-size:11px; color:#64748b; margin-top:2px; font-weight:500; }
.db-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; padding:36px 16px; color:#94a3b8; text-align:center; }
.db-empty i { font-size:28px; color:#cbd5e1; }
.db-empty p { font-size:13px; font-weight:500; }
.db-list-item { display:flex; align-items:center; gap:12px; padding:12px 20px; border-bottom:1px solid #f1f5f9; transition:background .12s ease; }
.db-list-item:last-child { border-bottom:none; }
.db-list-item:hover { background:#f8fafc; }
.db-action-link { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:8px; font-size:12px; font-weight:600; color:#235e45; background:#ecfaf3; border:1px solid #aedfc4; text-decoration:none; transition:all .15s ease; }
.db-action-link:hover { background:#d6f1e3; border-color:#79c79f; }
.db-kpi--alert { border-color:#fca5a5 !important; background:#fff5f5 !important; }
.db-section--alert { border-color:#fca5a5 !important; }
.db-section--alert .db-section-header { background:#fef2f2 !important; border-bottom-color:#fecaca !important; }
</style>
';

require_once 'includes/header.php';
?>

<div class="db-shell space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <?php
        $tabOptions = [];
        foreach ($validTabs as $tabKey) {
            $meta  = $tabMeta[$tabKey] ?? [];
            $label = ($pageTitles[$tabKey] ?? ucfirst($tabKey));
            $icon  = $meta['icon'] ?? 'fa-circle';
            $tabOptions[$tabKey] = '<i class="fas ' . htmlspecialchars($icon) . '" aria-hidden="true" style="margin-right:6px;font-size:13px;"></i>' . htmlspecialchars($label);
        }
        // Build tab strip manually using period-selector markup so styling is consistent
        echo '<div class="period-selector" role="tablist" aria-label="Dashboard tabs">';
        foreach ($tabOptions as $tabKey => $tabInner) {
            $isActive = ($activeTab === $tabKey);
            $cls  = 'period-chip' . ($isActive ? ' period-chip--active' : '');
            $aria = $isActive ? ' aria-current="true"' : '';
            echo '<a href="?tab=' . urlencode($tabKey) . '" class="' . $cls . '"' . $aria . '>' . $tabInner . '</a>';
        }
        echo '</div>';
        ?>
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
