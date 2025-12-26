<?php
/**
 * Low Stock Alerts - แจ้งเตือนสินค้าใกล้หมด
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/InventoryService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$pageTitle = 'แจ้งเตือนสินค้าใกล้หมด';

$inventoryService = new InventoryService($db, $lineAccountId);

// Get low stock products
$lowStockProducts = $inventoryService->getLowStockProducts();

// Get out of stock products
$outOfStock = [];
$criticalStock = [];
$warningStock = [];

foreach ($lowStockProducts as $p) {
    if ($p['stock'] <= 0) {
        $outOfStock[] = $p;
    } elseif ($p['stock'] <= ($p['min_stock'] ?? 5) / 2) {
        $criticalStock[] = $p;
    } else {
        $warningStock[] = $p;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-red-50 border border-red-200 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-red-600 text-sm font-medium">หมดสต็อก</p>
                    <p class="text-3xl font-bold text-red-700"><?= count($outOfStock) ?></p>
                </div>
                <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-times-circle text-red-500 text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-orange-50 border border-orange-200 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-orange-600 text-sm font-medium">วิกฤต</p>
                    <p class="text-3xl font-bold text-orange-700"><?= count($criticalStock) ?></p>
                </div>
                <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-orange-500 text-xl"></i>
                </div>
            </div>
        </div>
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-yellow-600 text-sm font-medium">ใกล้หมด</p>
                    <p class="text-3xl font-bold text-yellow-700"><?= count($warningStock) ?></p>
                </div>
                <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation text-yellow-500 text-xl"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Out of Stock -->
    <?php if (!empty($outOfStock)): ?>
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b bg-red-50">
            <h2 class="font-semibold text-red-700"><i class="fas fa-times-circle mr-2"></i>สินค้าหมดสต็อก (<?= count($outOfStock) ?>)</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สต็อก</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Min Stock</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($outOfStock as $p): ?>
                    <tr class="hover:bg-red-50">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($p['name']) ?></td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($p['sku'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center"><span class="px-2 py-1 bg-red-100 text-red-700 rounded font-bold"><?= $p['stock'] ?></span></td>
                        <td class="px-4 py-3 text-center text-gray-500"><?= $p['min_stock'] ?? 5 ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase-orders.php?add_product=<?= $p['id'] ?>" class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                                <i class="fas fa-cart-plus mr-1"></i>สั่งซื้อ
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Critical Stock -->
    <?php if (!empty($criticalStock)): ?>
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b bg-orange-50">
            <h2 class="font-semibold text-orange-700"><i class="fas fa-exclamation-triangle mr-2"></i>สต็อกวิกฤต (<?= count($criticalStock) ?>)</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สต็อก</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Min Stock</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($criticalStock as $p): ?>
                    <tr class="hover:bg-orange-50">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($p['name']) ?></td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($p['sku'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center"><span class="px-2 py-1 bg-orange-100 text-orange-700 rounded font-bold"><?= $p['stock'] ?></span></td>
                        <td class="px-4 py-3 text-center text-gray-500"><?= $p['min_stock'] ?? 5 ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase-orders.php?add_product=<?= $p['id'] ?>" class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                                <i class="fas fa-cart-plus mr-1"></i>สั่งซื้อ
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Warning Stock -->
    <?php if (!empty($warningStock)): ?>
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b bg-yellow-50">
            <h2 class="font-semibold text-yellow-700"><i class="fas fa-exclamation mr-2"></i>สต็อกใกล้หมด (<?= count($warningStock) ?>)</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สต็อก</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Min Stock</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($warningStock as $p): ?>
                    <tr class="hover:bg-yellow-50">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($p['name']) ?></td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($p['sku'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center"><span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded font-bold"><?= $p['stock'] ?></span></td>
                        <td class="px-4 py-3 text-center text-gray-500"><?= $p['min_stock'] ?? 5 ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="purchase-orders.php?add_product=<?= $p['id'] ?>" class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                                <i class="fas fa-cart-plus mr-1"></i>สั่งซื้อ
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (empty($lowStockProducts)): ?>
    <div class="bg-green-50 border border-green-200 rounded-xl p-8 text-center">
        <i class="fas fa-check-circle text-green-500 text-4xl mb-3"></i>
        <p class="text-green-700 font-medium">สต็อกสินค้าทั้งหมดอยู่ในระดับปกติ</p>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
