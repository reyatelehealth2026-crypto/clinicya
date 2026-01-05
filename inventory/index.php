<?php
/**
 * Inventory Management - Tab-based Consolidated Page
 * รวมหน้า Inventory ทั้งหมดเป็นหน้าเดียวแบบ Tab-based UI
 * 
 * Tabs: stock, movements, adjustment, low-stock, reports
 * 
 * @package FileConsolidation
 * @version 1.0.0
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/InventoryService.php';
require_once __DIR__ . '/../includes/components/tabs.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? 1;

// Check if inventory tables exist
$tableExists = false;
try {
    $db->query("SELECT 1 FROM stock_movements LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

// Define tabs
$tabs = [
    'products' => ['label' => 'สินค้า', 'icon' => 'fas fa-box'],
    'stock' => ['label' => 'สต็อกสินค้า', 'icon' => 'fas fa-boxes'],
    'movements' => ['label' => 'การเคลื่อนไหว', 'icon' => 'fas fa-exchange-alt'],
    'adjustment' => ['label' => 'ปรับสต็อก', 'icon' => 'fas fa-sliders-h'],
    'low-stock' => ['label' => 'สินค้าใกล้หมด', 'icon' => 'fas fa-exclamation-triangle'],
    'locations' => ['label' => 'ตำแหน่งจัดเก็บ', 'icon' => 'fas fa-map-marker-alt'],
    'planogram' => ['label' => 'Planogram', 'icon' => 'fas fa-th'],
    'batches' => ['label' => 'Batch/Lot', 'icon' => 'fas fa-layer-group'],
    'put-away' => ['label' => 'Put Away', 'icon' => 'fas fa-inbox'],
    'reports' => ['label' => 'รายงาน', 'icon' => 'fas fa-chart-bar'],
    'wms' => ['label' => 'WMS', 'icon' => 'fas fa-shipping-fast'],
];

// Get active tab
$activeTab = getActiveTab($tabs, 'products');

// Set page title based on active tab
$tabTitles = [
    'products' => 'จัดการสินค้า/บริการ',
    'stock' => 'สต็อกสินค้า',
    'movements' => 'ประวัติการเคลื่อนไหวสต็อก',
    'adjustment' => 'ปรับสต็อก (Stock Adjustment)',
    'low-stock' => 'สินค้าใกล้หมด & จุดสั่งซื้อ (ROP)',
    'locations' => 'ตำแหน่งจัดเก็บ (Warehouse Locations)',
    'planogram' => 'Planogram - ผังชั้นวางสินค้า',
    'batches' => 'Batch/Lot Tracking',
    'put-away' => 'Put Away - จัดเก็บสินค้า',
    'reports' => 'รายงานคลังสินค้า',
    'wms' => 'WMS - Pick Pack Ship',
];
$pageTitle = $tabTitles[$activeTab] ?? 'จัดการคลังสินค้า';

require_once __DIR__ . '/../includes/header.php';

// Output tab styles
echo getTabsStyles();

// Check if inventory is installed
if (!$tableExists):
?>
<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
    <i class="fas fa-database text-yellow-500 text-4xl mb-3"></i>
    <h3 class="text-lg font-semibold text-yellow-700 mb-2">ยังไม่ได้ติดตั้งระบบ Inventory</h3>
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
    case 'products':
        include __DIR__ . '/../includes/inventory/products.php';
        break;
    case 'movements':
        include __DIR__ . '/../includes/inventory/movements.php';
        break;
    case 'adjustment':
        include __DIR__ . '/../includes/inventory/adjustment.php';
        break;
    case 'low-stock':
        include __DIR__ . '/../includes/inventory/low-stock.php';
        break;
    case 'locations':
        include __DIR__ . '/../includes/inventory/locations.php';
        break;
    case 'planogram':
        include __DIR__ . '/../includes/inventory/planogram.php';
        break;
    case 'batches':
        include __DIR__ . '/../includes/inventory/batches.php';
        break;
    case 'put-away':
        include __DIR__ . '/../includes/inventory/put-away.php';
        break;
    case 'reports':
        include __DIR__ . '/../includes/inventory/reports.php';
        break;
    case 'wms':
        include __DIR__ . '/../includes/inventory/wms.php';
        break;
    default:
        include __DIR__ . '/../includes/inventory/stock.php';
}
?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
