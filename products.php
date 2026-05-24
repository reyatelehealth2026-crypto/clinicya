<?php
/**
 * Products — Consolidated Product Management (ข้อมูลสินค้า)
 * รวมการจัดการสินค้าทั้งหมดเป็นหน้าเดียวแบบ Tab-based
 *
 * Inspired by Smile Pharmacy /web/dataproduct, adapted for REYA multi-tenant.
 *
 * Tabs:
 *   list              — สินค้า (default)
 *   categories        — หมวดสินค้า
 *   drug-groups       — กลุ่มยา
 *   generic-names     — ชื่อทางการ
 *   units             — หน่วยสินค้า
 *   storage-locations — พื้นที่เก็บ
 *   label-templates   — ฉลากยา
 *   drug-interactions — Drug Interactions
 *
 * @package Products
 * @version 1.0.0
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/components/tabs.php';
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/toast.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

$db             = Database::getInstance()->getConnection();
$currentBotId   = $_SESSION['current_bot_id'] ?? null;
$lineAccountId  = $currentBotId; // alias used inside tab files
$activityLogger = ActivityLogger::getInstance($db);

// Tab configuration
$tabs = [
    'list'              => ['label' => 'สินค้า',         'icon' => 'fas fa-pills'],
    'categories'        => ['label' => 'หมวดสินค้า',     'icon' => 'fas fa-tags'],
    'drug-groups'       => ['label' => 'กลุ่มยา',        'icon' => 'fas fa-layer-group'],
    'generic-names'     => ['label' => 'ชื่อทางการ',     'icon' => 'fas fa-flask'],
    'units'             => ['label' => 'หน่วยสินค้า',    'icon' => 'fas fa-cubes'],
    'storage-locations' => ['label' => 'พื้นที่เก็บ',    'icon' => 'fas fa-warehouse'],
    'label-templates'   => ['label' => 'ฉลากยา',         'icon' => 'fas fa-tag'],
    'drug-interactions' => ['label' => 'Drug Interactions','icon' => 'fas fa-exclamation-triangle'],
];

$activeTab = getActiveTab($tabs, 'list');
$pageTitle = 'ข้อมูลสินค้า';

// Toast messages from URL
$success = null;
$error   = null;
if (isset($_GET['success'])) {
    $successMessages = [
        'created' => 'เพิ่มข้อมูลสำเร็จ',
        'updated' => 'อัพเดทข้อมูลสำเร็จ',
        'deleted' => 'ลบข้อมูลสำเร็จ',
    ];
    $success = $successMessages[$_GET['success']] ?? 'ดำเนินการสำเร็จ';
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Map tab key → include file
$tabFiles = [
    'list'              => 'list.php',
    'categories'        => 'categories.php',
    'drug-groups'       => 'drug-groups.php',
    'generic-names'     => 'generic-names.php',
    'units'             => 'units.php',
    'storage-locations' => 'storage-locations.php',
    'label-templates'   => 'label-templates.php',
    'drug-interactions' => 'drug-interactions.php',
];

// Buffer the tab content so the included file can set $success / $error
// from its own POST handler before the header renders the toast.
ob_start();
$includeFile = __DIR__ . '/includes/products/' . ($tabFiles[$activeTab] ?? 'list.php');
if (is_file($includeFile)) {
    include $includeFile;
} else {
    echo '<div class="p-6 text-rose-600">ไม่พบไฟล์แท็บ: ' . htmlspecialchars($activeTab) . '</div>';
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

<?= renderPageHeader(
    'ข้อมูลสินค้า',
    'จัดการสินค้า หมวดหมู่ กลุ่มยา หน่วย พื้นที่เก็บ ฉลากยา และ Drug Interactions',
    null,
    [
        ['label' => 'หน้าหลัก', 'href' => '/'],
        ['label' => 'ข้อมูลสินค้า', 'href' => null],
    ]
) ?>

<!-- Tab Navigation -->
<?= renderTabs($tabs, $activeTab, ['preserveParams' => ['session_id']]) ?>

<?php if (!$lineAccountId): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 mb-4 text-sm">
    <i class="fas fa-exclamation-circle mr-1"></i>
    ยังไม่ได้เลือก LINE Account — กรุณาเลือกบัญชีจาก dropdown ด้านบนก่อนจึงจะจัดการข้อมูลสินค้าได้
</div>
<?php endif; ?>

<!-- Tab Content -->
<div class="tab-content">
    <div class="tab-panel">
        <?= $tabContent ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
