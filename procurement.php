<?php
/**
 * Procurement Management - Tab-based Consolidated Page
 * รวมหน้า Procurement ทั้งหมดเป็นหน้าเดียวแบบ Tab-based UI
 * 
 * Tabs: po, gr, suppliers
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/components/tabs.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;

// Check if procurement tables exist
$tableExists = false;
try {
    $db->query("SELECT 1 FROM purchase_orders LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

// Define tabs
$tabs = [
    'po' => ['label' => 'ใบสั่งซื้อ (PO)', 'icon' => 'fas fa-file-invoice'],
    'gr' => ['label' => 'รับสินค้า (GR)', 'icon' => 'fas fa-truck-loading'],
    'suppliers' => ['label' => 'Supplier', 'icon' => 'fas fa-truck'],
];

// Get active tab
$activeTab = getActiveTab($tabs, 'po');

// Set page title based on active tab
$tabTitles = [
    'po' => 'ใบสั่งซื้อ (Purchase Order)',
    'gr' => 'รับสินค้าเข้าคลัง (Goods Receive)',
    'suppliers' => 'จัดการ Supplier',
];
$pageTitle = $tabTitles[$activeTab] ?? 'จัดการการจัดซื้อ';

require_once __DIR__ . '/includes/header.php';

// Output tab styles
echo getTabsStyles();

// Check if procurement is installed
if (!$tableExists):
?>
<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
    <i class="fas fa-database text-yellow-500 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold text-yellow-700 mb-2">ยังไม่ได้ติดตั้งระบบ Procurement</h3>
    <p class="text-yellow-600 mb-4">กรุณา run migration script เพื่อสร้างตาราง database</p>
    <div class="bg-white rounded-lg p-4 text-left max-w-lg mx-auto">
        <p class="text-sm text-gray-600 mb-2">Run SQL file:</p>
        <code class="text-xs bg-gray-100 p-2 rounded block">database/migration_inventory.sql</code>
    </div>
</div>
<?php else: ?>

<?php
// Render tabs
echo renderTabs($tabs, $activeTab);

// Load content based on active tab
switch ($activeTab) {
    case 'gr':
        include __DIR__ . '/includes/procurement/gr.php';
        break;
    case 'suppliers':
        include __DIR__ . '/includes/procurement/suppliers.php';
        break;
    default:
        include __DIR__ . '/includes/procurement/po.php';
}
?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
