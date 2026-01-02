<?php
/**
 * Stock Movements - ประวัติการเคลื่อนไหวสต็อก
 * 
 * DEPRECATED: This file has been consolidated into inventory/index.php
 * Redirects to: inventory/index.php?tab=movements
 */
require_once __DIR__ . '/../includes/redirects.php';
handleRedirect();

// Fallback if redirect doesn't work
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/InventoryService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$pageTitle = 'ประวัติการเคลื่อนไหวสต็อก';

$inventoryService = new InventoryService($db, $lineAccountId);

// Check if table exists
$tableExists = false;
try {
    $db->query("SELECT 1 FROM stock_movements LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {}

// Filters
$productId = $_GET['product_id'] ?? null;
$movementType = $_GET['type'] ?? null;
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get movements
$movements = $tableExists ? $inventoryService->getStockMovements([
    'product_id' => $productId,
    'movement_type' => $movementType,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'limit' => 100
]) : [];

// Get products for filter
$products = [];
try {
    $stmt = $db->prepare("SELECT id, name, sku FROM business_items WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../includes/header.php';

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

<div class="space-y-6">
    <!-- Filters -->
    <div class="bg-white rounded-xl shadow p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">สินค้า</label>
                <select name="product_id" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $productId == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ประเภท</label>
                <select name="type" class="w-full px-3 py-2 border rounded-lg">
                    <option value="">-- ทั้งหมด --</option>
                    <option value="receive" <?= $movementType === 'receive' ? 'selected' : '' ?>>รับเข้า (GR)</option>
                    <option value="sale" <?= $movementType === 'sale' ? 'selected' : '' ?>>ขาย</option>
                    <option value="adjustment_in" <?= $movementType === 'adjustment_in' ? 'selected' : '' ?>>ปรับเพิ่ม</option>
                    <option value="adjustment_out" <?= $movementType === 'adjustment_out' ? 'selected' : '' ?>>ปรับลด</option>
                    <option value="return" <?= $movementType === 'return' ? 'selected' : '' ?>>คืนสินค้า</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">จากวันที่</label>
                <input type="date" name="date_from" value="<?= $dateFrom ?>" class="w-full px-3 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ถึงวันที่</label>
                <input type="date" name="date_to" value="<?= $dateTo ?>" class="w-full px-3 py-2 border rounded-lg">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <i class="fas fa-search mr-1"></i>ค้นหา
                </button>
            </div>
        </form>
    </div>
    
    <!-- Movements Table -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex justify-between items-center">
            <h2 class="font-semibold"><i class="fas fa-exchange-alt mr-2 text-blue-500"></i>รายการเคลื่อนไหว</h2>
            <span class="text-sm text-gray-500"><?= count($movements) ?> รายการ</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">วันที่/เวลา</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ประเภท</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จำนวน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ก่อน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">หลัง</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">อ้างอิง</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($movements)): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">ไม่พบรายการ</td></tr>
                    <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-sm"><?= htmlspecialchars($m['product_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($m['sku'] ?? '') ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php
                            $typeLabels = [
                                'receive' => ['รับเข้า', 'green'],
                                'sale' => ['ขาย', 'red'],
                                'adjustment_in' => ['ปรับเพิ่ม', 'blue'],
                                'adjustment_out' => ['ปรับลด', 'orange'],
                                'return' => ['คืนสินค้า', 'purple'],
                                'transfer' => ['โอนย้าย', 'gray']
                            ];
                            $label = $typeLabels[$m['movement_type']] ?? [$m['movement_type'], 'gray'];
                            ?>
                            <span class="px-2 py-1 bg-<?= $label[1] ?>-100 text-<?= $label[1] ?>-700 rounded text-xs">
                                <?= $label[0] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center font-medium <?= $m['quantity'] > 0 ? 'text-green-600' : 'text-red-600' ?>">
                            <?= $m['quantity'] > 0 ? '+' : '' ?><?= $m['quantity'] ?>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-500"><?= $m['stock_before'] ?></td>
                        <td class="px-4 py-3 text-center font-medium"><?= $m['stock_after'] ?></td>
                        <td class="px-4 py-3 text-sm">
                            <?php if ($m['reference_number']): ?>
                            <span class="font-mono text-xs"><?= htmlspecialchars($m['reference_number']) ?></span>
                            <?php endif; ?>
                            <?php if ($m['notes']): ?>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($m['notes']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
