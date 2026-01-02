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

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? null;

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

require_once __DIR__ . '/includes/header.php';
echo getTabsStyles();
?>

<?php if ($success): ?>
<div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl flex items-center gap-3">
    <i class="fas fa-check-circle text-xl"></i>
    <span><?= htmlspecialchars($success) ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center gap-3">
    <i class="fas fa-exclamation-circle text-xl"></i>
    <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<!-- Tab Navigation -->
<?= renderTabs($tabs, $activeTab, ['preserveParams' => ['session_id']]) ?>

<!-- Tab Content -->
<div class="tab-content">
    <div class="tab-panel">
        <?php
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
        ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
