<?php
/**
 * Pharmacy - Consolidated Pharmacy Management Page
 * รวมหน้าเภสัชกรรมทั้งหมดเป็นหน้าเดียวแบบ Tab-based
 * 
 * Tabs: Dashboard, Pharmacists, Interactions, Dispense
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/components/tabs.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/toast.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;
$activityLogger = ActivityLogger::getInstance($db);

// Tab configuration
$tabs = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt'],
    'pharmacists' => ['label' => 'เภสัชกร', 'icon' => 'fas fa-user-md'],
    'interactions' => ['label' => 'ยาตีกัน', 'icon' => 'fas fa-pills'],
    'dispense' => ['label' => 'จ่ายยา', 'icon' => 'fas fa-prescription-bottle-alt'],
];

$activeTab = getActiveTab($tabs, 'dashboard');
$pageTitle = 'Pharmacy Management';

// Handle success/error messages from URL
$success = null;
$error = null;

if (isset($_GET['success'])) {
    $successMessages = [
        'created' => 'เพิ่มข้อมูลสำเร็จ',
        'updated' => 'อัพเดทข้อมูลสำเร็จ',
        'deleted' => 'ลบข้อมูลสำเร็จ',
    ];
    $success = $successMessages[$_GET['success']] ?? 'ดำเนินการสำเร็จ';
}

// Process tab content first to capture $success/$error from POST handlers
ob_start();
switch ($activeTab) {
    case 'pharmacists':
        include __DIR__ . '/includes/pharmacy/pharmacists.php';
        break;
    case 'interactions':
        include __DIR__ . '/includes/pharmacy/interactions.php';
        break;
    case 'dispense':
        include __DIR__ . '/includes/pharmacy/dispense.php';
        break;
    default:
        include __DIR__ . '/includes/pharmacy/dashboard.php';
}
$tabContent = ob_get_clean();

require_once __DIR__ . '/includes/header.php';
echo getPageHeaderStyles();
echo getToastStyles();
echo getTabsStyles();
?>

<?= renderToastContainer() ?>

<?php if ($success): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ fireToast(<?= json_encode($success) ?>,'success'); });</script>
<?php endif; ?>
<?php if ($error): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ fireToast(<?= json_encode($error) ?>,'error'); });</script>
<?php endif; ?>

<?= renderPageHeader('Pharmacy Management', 'จัดการเภสัชกรรม, ยาตีกัน และการจ่ายยา', null, [['label' => 'หน้าหลัก', 'href' => '/'], ['label' => 'Pharmacy', 'href' => null]]) ?>

<!-- Tab Navigation -->
<?= renderTabs($tabs, $activeTab, ['preserveParams' => ['session_id']]) ?>

<!-- Tab Content -->
<div class="tab-content">
    <div class="tab-panel">
        <?= $tabContent ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
