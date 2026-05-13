<?php
/**
 * Inventory Stock Tab - สต็อกสินค้า
 * Tab content for inventory/index.php
 */

require_once __DIR__ . '/../components/page-header.php';
require_once __DIR__ . '/../components/toolbar.php';
require_once __DIR__ . '/../components/data-table.php';
require_once __DIR__ . '/../components/empty-state.php';

// Get products with stock info
$products = [];
$totalStock = 0;
$totalValue = 0;

try {
    // Check if cost_price column exists
    $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
    $hasCostPrice = in_array('cost_price', $cols);
    $costPriceCol = $hasCostPrice ? "cost_price" : "0 as cost_price";
    $valueCalc = $hasCostPrice ? "(stock * COALESCE(cost_price, 0))" : "0";
    
    $search = $_GET['search'] ?? '';
    $category = $_GET['category'] ?? '';
    
    $sql = "SELECT id, name, sku, barcode, stock, {$costPriceCol}, 
                   {$valueCalc} as value, reorder_point, category
            FROM business_items 
            WHERE is_active = 1";
    $params = [];
    
    if ($search) {
        $sql .= " AND (name LIKE ? OR sku LIKE ? OR barcode LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    
    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
    }
    
    $sql .= " ORDER BY name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($products as $p) {
        $totalStock += $p['stock'];
        $totalValue += $p['value'];
    }
    
    // Get categories for filter
    $categories = $db->query("SELECT DISTINCT category FROM business_items WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $products = [];
    $categories = [];
}

// Build category select options for renderToolbar
$categoryOptions = [];
foreach ($categories as $cat) {
    $categoryOptions[] = ['value' => $cat, 'label' => $cat];
}

// Build columns for renderDataTable
$stockColumns = [
    [
        'key' => 'name', 'label' => 'สินค้า', 'align' => 'left',
        'render' => function ($p) {
            $html = '<div style="font-weight:500;">' . htmlspecialchars($p['name']) . '</div>';
            if ($p['category']) {
                $html .= '<div style="font-size:12px;color:var(--color-dark-500);">' . htmlspecialchars($p['category']) . '</div>';
            }
            return $html;
        },
    ],
    [
        'key' => 'sku', 'label' => 'SKU', 'align' => 'center',
        'render' => fn($p) => '<span style="font-family:var(--font-mono);font-size:13px;">' . htmlspecialchars($p['sku'] ?? '-') . '</span>',
    ],
    [
        'key' => 'barcode', 'label' => 'Barcode', 'align' => 'center',
        'render' => fn($p) => '<span style="font-family:var(--font-mono);font-size:13px;">' . htmlspecialchars($p['barcode'] ?? '-') . '</span>',
    ],
    [
        'key' => 'stock', 'label' => 'สต็อก', 'align' => 'center',
        'render' => function ($p) {
            $rop = $p['reorder_point'] ?? 5;
            $status = $p['stock'] <= 0 ? 'out' : ($p['stock'] <= $rop ? 'low' : 'ok');
            $color = $status === 'out' ? 'var(--color-rose-600)' : ($status === 'low' ? 'var(--color-amber-600)' : 'inherit');
            return '<span style="font-weight:700;color:' . $color . ';">' . number_format($p['stock']) . '</span>';
        },
    ],
    [
        'key' => 'rop', 'label' => 'ROP', 'align' => 'center',
        'render' => fn($p) => '<span style="color:var(--color-dark-500);">' . ($p['reorder_point'] ?? 5) . '</span>',
    ],
    [
        'key' => 'cost', 'label' => 'ต้นทุน', 'align' => 'right',
        'render' => fn($p) => '฿' . number_format($p['cost_price'] ?? 0, 2),
    ],
    [
        'key' => 'value', 'label' => 'มูลค่า', 'align' => 'right',
        'render' => fn($p) => '<span style="font-weight:500;">฿' . number_format($p['value'], 2) . '</span>',
    ],
    [
        'key' => 'status', 'label' => 'สถานะ', 'align' => 'center',
        'render' => function ($p) {
            $rop = $p['reorder_point'] ?? 5;
            $status = $p['stock'] <= 0 ? 'out' : ($p['stock'] <= $rop ? 'low' : 'ok');
            $statusColors = ['out' => 'red', 'low' => 'yellow', 'ok' => 'green'];
            $statusLabels = ['out' => 'หมด', 'low' => 'ใกล้หมด', 'ok' => 'ปกติ'];
            return '<span class="px-2 py-1 bg-' . $statusColors[$status] . '-100 text-' . $statusColors[$status] . '-700 rounded text-xs">' . $statusLabels[$status] . '</span>';
        },
    ],
];

$stockEmpty = renderEmptyState('fas fa-boxes', 'ไม่พบข้อมูล', 'ลองปรับตัวกรองหรือเพิ่มสินค้าใหม่');
?>

<?= getPageHeaderStyles() ?>
<?= getToolbarStyles() ?>
<?= getDataTableStyles() ?>
<?= getEmptyStateStyles() ?>

<div class="space-y-6">
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-6 text-white">
            <p class="text-blue-100 text-sm">สินค้าทั้งหมด</p>
            <p class="text-3xl font-bold"><?= count($products) ?> รายการ</p>
        </div>
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-6 text-white">
            <p class="text-green-100 text-sm">จำนวนสต็อกรวม</p>
            <p class="text-3xl font-bold"><?= number_format($totalStock) ?> ชิ้น</p>
        </div>
        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl p-6 text-white">
            <p class="text-purple-100 text-sm">มูลค่าสต็อกรวม</p>
            <p class="text-3xl font-bold">฿<?= number_format($totalValue, 2) ?></p>
        </div>
    </div>

    <!-- Filters -->
    <?= renderToolbar([
        'method' => 'GET',
        'hiddenFields' => ['tab' => 'stock'],
        'search' => [
            'name' => 'search',
            'value' => $_GET['search'] ?? '',
            'placeholder' => 'ชื่อสินค้า, SKU, Barcode...',
        ],
        'selects' => [
            [
                'name' => 'category',
                'value' => $category,
                'placeholder' => '-- ทั้งหมด --',
                'options' => $categoryOptions,
            ],
        ],
        'resetHref' => ($search || $category) ? '?tab=stock' : null,
        'meta' => '<i class="fas fa-boxes" style="margin-right:6px;color:var(--color-primary-500);"></i>รายการสต็อกสินค้า · ' . count($products) . ' รายการ',
    ]) ?>

    <!-- Stock Table -->
    <?= renderDataTable($stockColumns, $products, ['emptyContent' => $stockEmpty]) ?>
</div>
