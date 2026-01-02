<?php
/**
 * Inventory Reports Tab - รายงานคลังสินค้า
 * Tab content for inventory/index.php
 */

$inventoryService = new InventoryService($db, $lineAccountId);

// Get report type
$reportType = $_GET['report'] ?? 'valuation';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Stock Valuation Report
$stockValuation = [];
$totalValue = 0;
$totalItems = 0;

if ($reportType === 'valuation') {
    try {
        $cols = $db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
        $hasCostPrice = in_array('cost_price', $cols);
        
        $costPriceCol = $hasCostPrice ? "cost_price" : "0";
        $valueCalc = $hasCostPrice ? "(stock * COALESCE(cost_price, 0))" : "0";
        
        $stmt = $db->prepare("
            SELECT id, name, sku, stock, {$costPriceCol} as cost_price, 
                   {$valueCalc} as value
            FROM business_items 
            WHERE is_active = 1 AND stock > 0
            ORDER BY value DESC
        ");
        $stmt->execute();
        $stockValuation = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($stockValuation as $item) {
            $totalValue += $item['value'];
            $totalItems += $item['stock'];
        }
    } catch (Exception $e) {}
}

// Movement Summary
$movementSummary = [];
if ($reportType === 'movement') {
    try {
        $stmt = $db->prepare("
            SELECT movement_type, 
                   COUNT(*) as count,
                   SUM(ABS(quantity)) as total_qty
            FROM stock_movements
            WHERE created_at BETWEEN ? AND ?
            GROUP BY movement_type
        ");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $movementSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Purchase Summary
$purchaseSummary = [];
if ($reportType === 'purchase') {
    try {
        $stmt = $db->prepare("
            SELECT s.name as supplier_name,
                   COUNT(po.id) as po_count,
                   SUM(po.total_amount) as total_amount
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.status IN ('submitted', 'partial', 'completed')
              AND po.order_date BETWEEN ? AND ?
            GROUP BY po.supplier_id
            ORDER BY total_amount DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $purchaseSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>

<div class="space-y-6">
    <!-- Report Selection -->
    <div class="bg-white rounded-xl shadow p-4">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <input type="hidden" name="tab" value="reports">
            <div>
                <label class="block text-sm font-medium mb-1">ประเภทรายงาน</label>
                <select name="report" onchange="this.form.submit()" class="px-3 py-2 border rounded-lg">
                    <option value="valuation" <?= $reportType === 'valuation' ? 'selected' : '' ?>>มูลค่าสต็อก</option>
                    <option value="movement" <?= $reportType === 'movement' ? 'selected' : '' ?>>สรุปการเคลื่อนไหว</option>
                    <option value="purchase" <?= $reportType === 'purchase' ? 'selected' : '' ?>>สรุปการสั่งซื้อ</option>
                </select>
            </div>
            <?php if ($reportType !== 'valuation'): ?>
            <div>
                <label class="block text-sm font-medium mb-1">จากวันที่</label>
                <input type="date" name="date_from" value="<?= $dateFrom ?>" class="px-3 py-2 border rounded-lg">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ถึงวันที่</label>
                <input type="date" name="date_to" value="<?= $dateTo ?>" class="px-3 py-2 border rounded-lg">
            </div>
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                <i class="fas fa-search mr-1"></i>ดูรายงาน
            </button>
            <?php endif; ?>
            <button type="button" onclick="exportReport()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-download mr-1"></i>Export CSV
            </button>
        </form>
    </div>
    
    <?php if ($reportType === 'valuation'): ?>
    <!-- Stock Valuation Report -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-6 text-white">
            <p class="text-green-100 text-sm">มูลค่าสต็อกรวม</p>
            <p class="text-3xl font-bold">฿<?= number_format($totalValue, 2) ?></p>
        </div>
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl p-6 text-white">
            <p class="text-blue-100 text-sm">จำนวนสินค้าในคลัง</p>
            <p class="text-3xl font-bold"><?= number_format($totalItems) ?> ชิ้น</p>
        </div>
    </div>
    
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold"><i class="fas fa-boxes mr-2 text-green-500"></i>รายละเอียดมูลค่าสต็อก</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full" id="reportTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">สินค้า</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">SKU</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">สต็อก</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">ต้นทุน/หน่วย</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">มูลค่ารวม</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($stockValuation as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($item['name']) ?></td>
                        <td class="px-4 py-3 text-center font-mono text-sm"><?= htmlspecialchars($item['sku'] ?? '-') ?></td>
                        <td class="px-4 py-3 text-center"><?= number_format($item['stock']) ?></td>
                        <td class="px-4 py-3 text-right">฿<?= number_format($item['cost_price'] ?? 0, 2) ?></td>
                        <td class="px-4 py-3 text-right font-medium">฿<?= number_format($item['value'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-100">
                    <tr>
                        <td colspan="2" class="px-4 py-3 font-bold">รวมทั้งหมด</td>
                        <td class="px-4 py-3 text-center font-bold"><?= number_format($totalItems) ?></td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 text-right font-bold">฿<?= number_format($totalValue, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($reportType === 'movement'): ?>
    <!-- Movement Summary Report -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold"><i class="fas fa-exchange-alt mr-2 text-blue-500"></i>สรุปการเคลื่อนไหวสต็อก</h2>
            <p class="text-sm text-gray-500"><?= date('d/m/Y', strtotime($dateFrom)) ?> - <?= date('d/m/Y', strtotime($dateTo)) ?></p>
        </div>
        <div class="p-4">
            <?php if (empty($movementSummary)): ?>
            <p class="text-center text-gray-500 py-8">ไม่พบข้อมูลในช่วงเวลาที่เลือก</p>
            <?php else: ?>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <?php 
                $typeLabels = [
                    'receive' => ['รับเข้า (GR)', 'green', 'fa-truck-loading'],
                    'sale' => ['ขาย', 'red', 'fa-shopping-cart'],
                    'adjustment_in' => ['ปรับเพิ่ม', 'blue', 'fa-plus-circle'],
                    'adjustment_out' => ['ปรับลด', 'orange', 'fa-minus-circle'],
                    'return' => ['คืนสินค้า', 'purple', 'fa-undo'],
                    'transfer' => ['โอนย้าย', 'gray', 'fa-exchange-alt']
                ];
                foreach ($movementSummary as $m): 
                    $label = $typeLabels[$m['movement_type']] ?? [$m['movement_type'], 'gray', 'fa-circle'];
                ?>
                <div class="bg-<?= $label[1] ?>-50 border border-<?= $label[1] ?>-200 rounded-xl p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-<?= $label[1] ?>-100 rounded-full flex items-center justify-center">
                            <i class="fas <?= $label[2] ?> text-<?= $label[1] ?>-500"></i>
                        </div>
                        <div>
                            <p class="text-<?= $label[1] ?>-700 text-sm font-medium"><?= $label[0] ?></p>
                            <p class="text-xl font-bold text-<?= $label[1] ?>-800"><?= number_format($m['total_qty']) ?></p>
                            <p class="text-xs text-<?= $label[1] ?>-600"><?= $m['count'] ?> รายการ</p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($reportType === 'purchase'): ?>
    <!-- Purchase Summary Report -->
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b">
            <h2 class="font-semibold"><i class="fas fa-file-invoice-dollar mr-2 text-purple-500"></i>สรุปการสั่งซื้อตาม Supplier</h2>
            <p class="text-sm text-gray-500"><?= date('d/m/Y', strtotime($dateFrom)) ?> - <?= date('d/m/Y', strtotime($dateTo)) ?></p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full" id="reportTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Supplier</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">จำนวน PO</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">ยอดรวม</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php 
                    $grandTotal = 0;
                    foreach ($purchaseSummary as $s): 
                        $grandTotal += $s['total_amount'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($s['supplier_name'] ?? 'ไม่ระบุ') ?></td>
                        <td class="px-4 py-3 text-center"><?= $s['po_count'] ?></td>
                        <td class="px-4 py-3 text-right font-medium">฿<?= number_format($s['total_amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-100">
                    <tr>
                        <td class="px-4 py-3 font-bold">รวมทั้งหมด</td>
                        <td class="px-4 py-3 text-center font-bold"><?= array_sum(array_column($purchaseSummary, 'po_count')) ?></td>
                        <td class="px-4 py-3 text-right font-bold">฿<?= number_format($grandTotal, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function exportReport() {
    const table = document.getElementById('reportTable');
    if (!table) { alert('ไม่พบข้อมูลสำหรับ export'); return; }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        cols.forEach(col => rowData.push('"' + col.innerText.replace(/"/g, '""') + '"'));
        csv.push(rowData.join(','));
    });
    
    const blob = new Blob(['\ufeff' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'inventory_report_<?= date('Ymd') ?>.csv';
    link.click();
}
</script>
