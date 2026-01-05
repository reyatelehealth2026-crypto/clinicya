<?php
/**
 * Inventory Movements Tab - ประวัติการเคลื่อนไหวสต็อก
 * Tab content for inventory/index.php
 * 
 * Features:
 * - Stock movement history with value tracking
 * - Filter by product, type, and date range
 * - Value change display for cost tracking
 * 
 * Requirements: 6.3
 */

$inventoryService = new InventoryService($db, $lineAccountId);

// Filters
$productId = $_GET['product_id'] ?? null;
$movementType = $_GET['type'] ?? null;
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get movements
$movements = $inventoryService->getStockMovements([
    'product_id' => $productId,
    'movement_type' => $movementType,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'limit' => 100
]);

// Get products for filter
$products = [];
try {
    $stmt = $db->prepare("SELECT id, name, sku FROM business_items WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<div class="space-y-6">
    <!-- Filters -->
    <div class="bg-white rounded-xl shadow p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <input type="hidden" name="tab" value="movements">
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
                    <option value="goods_receive" <?= $movementType === 'goods_receive' ? 'selected' : '' ?>>รับเข้า (GR)</option>
                    <option value="sale" <?= $movementType === 'sale' ? 'selected' : '' ?>>ขาย</option>
                    <option value="adjustment_in" <?= $movementType === 'adjustment_in' ? 'selected' : '' ?>>ปรับเพิ่ม</option>
                    <option value="adjustment_out" <?= $movementType === 'adjustment_out' ? 'selected' : '' ?>>ปรับลด</option>
                    <option value="disposal" <?= $movementType === 'disposal' ? 'selected' : '' ?>>ทำลาย</option>
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
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">มูลค่า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">ก่อน</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">หลัง</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">อ้างอิง</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($movements)): ?>
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">ไม่พบรายการ</td></tr>
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
                                'goods_receive' => ['รับเข้า (GR)', 'green'],
                                'sale' => ['ขาย', 'red'],
                                'adjustment_in' => ['ปรับเพิ่ม', 'blue'],
                                'adjustment_out' => ['ปรับลด', 'orange'],
                                'return' => ['คืนสินค้า', 'purple'],
                                'transfer' => ['โอนย้าย', 'gray'],
                                'disposal' => ['ทำลาย', 'red']
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
                        <td class="px-4 py-3 text-right">
                            <?php 
                            $valueChange = $m['value_change'] ?? null;
                            if ($valueChange !== null && $valueChange != 0): 
                                $valueColor = $valueChange > 0 ? 'green' : 'red';
                            ?>
                            <span class="font-medium text-<?= $valueColor ?>-600">
                                <?= $valueChange > 0 ? '+' : '' ?>฿<?= number_format($valueChange, 2) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
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
