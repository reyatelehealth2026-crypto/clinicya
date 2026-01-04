<?php
/**
 * Accounting Management - Tab-based Consolidated Page
 * ระบบจัดการบัญชี รวมหน้าบัญชีทั้งหมดเป็นหน้าเดียวแบบ Tab-based UI
 * 
 * Tabs: Dashboard, เจ้าหนี้ (AP), ลูกหนี้ (AR), ค่าใช้จ่าย (Expenses)
 * 
 * Requirements: 1.2, 2.2, 3.2, 6.1
 * 
 * @package AccountingManagement
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

// Check if accounting tables exist
$tableExists = false;
try {
    $db->query("SELECT 1 FROM account_payables LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

// Tab configuration
$tabs = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt'],
    'ap' => ['label' => 'เจ้าหนี้ (AP)', 'icon' => 'fas fa-file-invoice-dollar'],
    'ar' => ['label' => 'ลูกหนี้ (AR)', 'icon' => 'fas fa-hand-holding-usd'],
    'expenses' => ['label' => 'ค่าใช้จ่าย', 'icon' => 'fas fa-receipt'],
];

$activeTab = getActiveTab($tabs, 'dashboard');

// Set page title based on active tab
$tabTitles = [
    'dashboard' => 'ภาพรวมบัญชี',
    'ap' => 'เจ้าหนี้การค้า (Account Payable)',
    'ar' => 'ลูกหนี้การค้า (Account Receivable)',
    'expenses' => 'ค่าใช้จ่าย (Expenses)',
];
$pageTitle = $tabTitles[$activeTab] ?? 'ระบบบัญชี';

// Handle success/error messages from URL
$success = null;
$error = null;

if (isset($_GET['success'])) {
    $successMessages = [
        'created' => 'เพิ่มข้อมูลสำเร็จ',
        'updated' => 'อัพเดทข้อมูลสำเร็จ',
        'deleted' => 'ลบข้อมูลสำเร็จ',
        'payment_recorded' => 'บันทึกการชำระเงินสำเร็จ',
        'receipt_recorded' => 'บันทึกการรับเงินสำเร็จ',
    ];
    $success = $successMessages[$_GET['success']] ?? 'ดำเนินการสำเร็จ';
}

if (isset($_GET['error'])) {
    $errorMessages = [
        'not_found' => 'ไม่พบข้อมูลที่ต้องการ',
        'invalid_amount' => 'จำนวนเงินไม่ถูกต้อง',
        'save_failed' => 'บันทึกข้อมูลไม่สำเร็จ',
    ];
    $error = $errorMessages[$_GET['error']] ?? 'เกิดข้อผิดพลาด';
}

require_once __DIR__ . '/includes/header.php';
echo getTabsStyles();
?>

<?php if (!$tableExists): ?>
<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
    <i class="fas fa-database text-yellow-500 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold text-yellow-700 mb-2">ยังไม่ได้ติดตั้งระบบบัญชี</h3>
    <p class="text-yellow-600 mb-4">กรุณา run migration script เพื่อสร้างตาราง database</p>
    <div class="bg-white rounded-lg p-4 text-left max-w-lg mx-auto">
        <p class="text-sm text-gray-600 mb-2">Run installation script:</p>
        <code class="text-xs bg-gray-100 p-2 rounded block mb-3">install/run_accounting_migration.php</code>
        <p class="text-sm text-gray-600 mb-2">หรือ run SQL file:</p>
        <code class="text-xs bg-gray-100 p-2 rounded block">database/migration_accounting.sql</code>
    </div>
</div>
<?php else: ?>

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
<?= renderTabs($tabs, $activeTab, ['preserveParams' => ['status', 'supplier_id', 'user_id', 'category_id']]) ?>

<!-- Tab Content -->
<div class="tab-content">
    <div class="tab-panel">
        <?php
        // Load content based on active tab
        switch ($activeTab) {
            case 'ap':
                include __DIR__ . '/includes/accounting/ap.php';
                break;
            case 'ar':
                include __DIR__ . '/includes/accounting/ar.php';
                break;
            case 'expenses':
                include __DIR__ . '/includes/accounting/expenses.php';
                break;
            default:
                include __DIR__ . '/includes/accounting/dashboard.php';
        }
        ?>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
