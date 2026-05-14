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
require_once __DIR__ . '/includes/components/page-header.php';
require_once __DIR__ . '/includes/components/toast.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;

// Handle AJAX requests BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $tab = $_GET['tab'] ?? 'po';
    
    try {
        switch ($tab) {
            case 'suppliers':
                require_once __DIR__ . '/classes/SupplierService.php';
                $supplierService = new SupplierService($db, $lineAccountId);
                $action = $_POST['action'];
                
                switch ($action) {
                    case 'create':
                        $id = $supplierService->create($_POST);
                        echo json_encode(['success' => true, 'id' => $id]);
                        break;
                    case 'update':
                        $supplierService->update((int)$_POST['id'], $_POST);
                        echo json_encode(['success' => true]);
                        break;
                    case 'toggle':
                        $id = (int)$_POST['id'];
                        $active = (int)$_POST['active'];
                        $active ? $supplierService->activate($id) : $supplierService->deactivate($id);
                        echo json_encode(['success' => true]);
                        break;
                    default:
                        echo json_encode(['success' => false, 'message' => 'Unknown action']);
                }
                break;
            // Add other tabs' AJAX handlers here if needed
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown tab']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

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

echo getPageHeaderStyles();
echo getToastStyles();
echo getTabsStyles();
?>
<?= renderToastContainer() ?>
<?= renderPageHeader('จัดการการจัดซื้อ', $tabTitles[$activeTab] ?? '', null, [['label' => 'หน้าหลัก', 'href' => '/'], ['label' => 'จัดการการจัดซื้อ', 'href' => null]]) ?>
<?php

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
