<?php
/**
 * Inventory Stock Tab - สต็อกสินค้า
 * Tab content for inventory/index.php
 */

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
?>

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
    <div class="bg-white rounded-xl shadow p-4">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="tab" value="stock">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium mb-1">ค้นหา</label>
                <input type="text" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" 
                       placeholder="ชื่อสินค้า, SKU, Barcode..." 
                       class="w-full px-3 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">หมวดหมู่</label>
                <select name="category" class="px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= ($category === $cat) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-search mr-1"></i>ค้นหา
            </button>
            <?php if ($search || $category): ?>
            <a href="?tab=stock" class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">ล้างตัวกรอง</a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Stock Table -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold"><i class="fas fa-boxes mr-2 text-blue-500"></i>รายการสต็อกสินค้า</h2>
            <span class="text-sm text-gray-500"><?= count($products) ?> รายการ</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Barcode</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สต็อก</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ROP</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">ต้นทุน</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">มูลค่า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สถานะ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($products)): ?>
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                    <?php foreach ($products as $p): 
                        $rop = $p['reorder_point'] ?? 5;
                        $status = $p['stock'] <= 0 ? 'out' : ($p['stock'] <= $rop ? 'low' : 'ok');
                        $statusColors = ['out' => 'red', 'low' => 'yellow', 'ok' => 'green'];
                        $statusLabels = ['out' => 'หมด', 'low' => 'ใกล้หมด', 'ok' => 'ปกติ'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium"><?= htmlspecialchars($p['name']) ?></div>
                            <?php if ($p['category']): ?>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($p['category']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($p['sku'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($p['barcode'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center font-bold <?= $status === 'out' ? 'text-red-600' : ($status === 'low' ? 'text-yellow-600' : '') ?>">
                            <?= number_format($p['stock']) ?>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-500"><?= $rop ?></td>
                        <td class="px-4 py-3 text-right">฿<?= number_format($p['cost_price'] ?? 0, 2) ?></td>
                        <td class="px-4 py-3 text-right font-medium">฿<?= number_format($p['value'], 2) ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-1 bg-<?= $statusColors[$status] ?>-100 text-<?= $statusColors[$status] ?>-700 rounded text-xs">
                                <?= $statusLabels[$status] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
