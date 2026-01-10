<?php
/**
 * POS Reports - รายงานการขายหน้าร้าน
 */

require_once __DIR__ . '/../../classes/POSReportService.php';

$reportService = new POSReportService($db, $currentBotId);

// Get parameters
$reportType = $_GET['report'] ?? 'daily';
$date = $_GET['date'] ?? date('Y-m-d');
$month = $_GET['month'] ?? date('Y-m');

// Get report data based on type
switch ($reportType) {
    case 'monthly':
        $reportData = $reportService->getMonthlySummary($month);
        break;
    case 'shifts':
        $reportData = $reportService->getShiftReports($date);
        break;
    default:
        $reportData = $reportService->getDailySummary($date);
}

// Payment method labels
$paymentLabels = [
    'cash' => 'เงินสด',
    'transfer' => 'โอน/QR',
    'card' => 'บัตร',
    'points' => 'แต้ม',
    'credit' => 'เครดิต'
];

function formatMoney($amount) {
    return number_format((float)$amount, 2);
}
?>

<style>
.report-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.stat-value { font-size: 28px; font-weight: 700; }
.stat-label { font-size: 13px; color: #666; }
.payment-bar { height: 8px; border-radius: 4px; background: #e5e7eb; overflow: hidden; }
.payment-bar-fill { height: 100%; border-radius: 4px; transition: width 0.5s; }
.product-row:hover { background: #f9fafb; }
</style>

<!-- Report Type Tabs -->
<div class="flex gap-2 mb-6">
    <a href="?tab=reports&report=daily&date=<?= $date ?>" 
       class="px-4 py-2 rounded-lg <?= $reportType === 'daily' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
        <i class="fas fa-calendar-day mr-2"></i>รายวัน
    </a>
    <a href="?tab=reports&report=monthly&month=<?= $month ?>" 
       class="px-4 py-2 rounded-lg <?= $reportType === 'monthly' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
        <i class="fas fa-calendar-alt mr-2"></i>รายเดือน
    </a>
    <a href="?tab=reports&report=shifts&date=<?= $date ?>" 
       class="px-4 py-2 rounded-lg <?= $reportType === 'shifts' ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
        <i class="fas fa-user-clock mr-2"></i>รายกะ
    </a>
</div>

<!-- Date/Month Selector -->
<div class="flex items-center gap-4 mb-6">
    <?php if ($reportType === 'monthly'): ?>
    <input type="month" value="<?= $month ?>" 
           onchange="location.href='?tab=reports&report=monthly&month='+this.value"
           class="px-4 py-2 border border-gray-200 rounded-lg">
    <?php else: ?>
    <input type="date" value="<?= $date ?>" 
           onchange="location.href='?tab=reports&report=<?= $reportType ?>&date='+this.value"
           class="px-4 py-2 border border-gray-200 rounded-lg">
    <?php endif; ?>
    
    <button onclick="window.print()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
        <i class="fas fa-print mr-2"></i>พิมพ์
    </button>
    <button onclick="exportReport()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        <i class="fas fa-download mr-2"></i>ส่งออก
    </button>
</div>

<?php if ($reportType === 'daily' || $reportType === 'monthly'): ?>
<!-- Summary Cards -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="report-card">
        <div class="stat-label">ยอดขายรวม</div>
        <div class="stat-value text-green-600">฿<?= formatMoney($reportData['summary']['total_sales']) ?></div>
        <div class="text-sm text-gray-500"><?= $reportData['summary']['total_transactions'] ?> รายการ</div>
    </div>
    
    <div class="report-card">
        <div class="stat-label">ยอดสุทธิ</div>
        <div class="stat-value text-blue-600">฿<?= formatMoney($reportData['summary']['net_sales']) ?></div>
        <div class="text-sm text-gray-500">หลังหักคืนสินค้า</div>
    </div>
    
    <div class="report-card">
        <div class="stat-label">คืนสินค้า</div>
        <div class="stat-value text-red-600">฿<?= formatMoney($reportData['summary']['total_refunds']) ?></div>
        <div class="text-sm text-gray-500"><?= $reportData['summary']['return_count'] ?> รายการ</div>
    </div>
    
    <div class="report-card">
        <div class="stat-label">ยกเลิก</div>
        <div class="stat-value text-orange-600">฿<?= formatMoney($reportData['summary']['voided_amount']) ?></div>
        <div class="text-sm text-gray-500"><?= $reportData['summary']['voided_count'] ?> รายการ</div>
    </div>
</div>

<!-- Payment Breakdown -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <div class="report-card">
        <h3 class="font-semibold mb-4"><i class="fas fa-credit-card mr-2 text-blue-500"></i>แยกตามวิธีชำระเงิน</h3>
        <?php 
        $totalPayments = array_sum(array_column($reportData['payment_breakdown'], 'total'));
        foreach ($reportData['payment_breakdown'] as $payment): 
            $percent = $totalPayments > 0 ? ($payment['total'] / $totalPayments * 100) : 0;
            $colors = ['cash' => '#22c55e', 'transfer' => '#3b82f6', 'card' => '#8b5cf6', 'points' => '#f59e0b', 'credit' => '#ef4444'];
            $color = $colors[$payment['payment_method']] ?? '#6b7280';
        ?>
        <div class="mb-4">
            <div class="flex justify-between mb-1">
                <span><?= $paymentLabels[$payment['payment_method']] ?? $payment['payment_method'] ?></span>
                <span class="font-semibold">฿<?= formatMoney($payment['total']) ?> (<?= number_format($percent, 1) ?>%)</span>
            </div>
            <div class="payment-bar">
                <div class="payment-bar-fill" style="width: <?= $percent ?>%; background: <?= $color ?>"></div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($reportData['payment_breakdown'])): ?>
        <p class="text-gray-500 text-center py-4">ไม่มีข้อมูล</p>
        <?php endif; ?>
    </div>
    
    <!-- Top Products -->
    <div class="report-card">
        <h3 class="font-semibold mb-4"><i class="fas fa-trophy mr-2 text-yellow-500"></i>สินค้าขายดี</h3>
        <div class="max-h-64 overflow-y-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="text-left p-2">#</th>
                        <th class="text-left p-2">สินค้า</th>
                        <th class="text-right p-2">จำนวน</th>
                        <th class="text-right p-2">ยอดขาย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData['top_products'] as $i => $product): ?>
                    <tr class="product-row border-b">
                        <td class="p-2"><?= $i + 1 ?></td>
                        <td class="p-2"><?= htmlspecialchars($product['product_name']) ?></td>
                        <td class="p-2 text-right"><?= number_format($product['total_qty']) ?></td>
                        <td class="p-2 text-right">฿<?= formatMoney($product['total_sales']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (empty($reportData['top_products'])): ?>
            <p class="text-gray-500 text-center py-4">ไม่มีข้อมูล</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($reportType === 'daily' && !empty($reportData['transactions'])): ?>
<!-- Transaction List -->
<div class="report-card">
    <h3 class="font-semibold mb-4"><i class="fas fa-list mr-2 text-gray-500"></i>รายการขายทั้งหมด</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left p-3">เลขที่</th>
                    <th class="text-left p-3">เวลา</th>
                    <th class="text-left p-3">พนักงาน</th>
                    <th class="text-left p-3">ลูกค้า</th>
                    <th class="text-right p-3">ยอดรวม</th>
                    <th class="text-center p-3">สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['transactions'] as $tx): ?>
                <tr class="border-b hover:bg-gray-50">
                    <td class="p-3 font-mono"><?= $tx['transaction_number'] ?></td>
                    <td class="p-3"><?= date('H:i', strtotime($tx['created_at'])) ?></td>
                    <td class="p-3"><?= htmlspecialchars($tx['cashier_name'] ?? '-') ?></td>
                    <td class="p-3"><?= htmlspecialchars($tx['customer_name'] ?? 'Walk-in') ?></td>
                    <td class="p-3 text-right font-semibold">฿<?= formatMoney($tx['total_amount']) ?></td>
                    <td class="p-3 text-center">
                        <?php if ($tx['status'] === 'completed'): ?>
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs">สำเร็จ</span>
                        <?php elseif ($tx['status'] === 'voided'): ?>
                        <span class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs">ยกเลิก</span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs"><?= $tx['status'] ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($reportType === 'monthly' && !empty($reportData['daily_trend'])): ?>
<!-- Daily Trend Chart -->
<div class="report-card">
    <h3 class="font-semibold mb-4"><i class="fas fa-chart-line mr-2 text-blue-500"></i>ยอดขายรายวัน</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left p-3">วันที่</th>
                    <th class="text-right p-3">รายการ</th>
                    <th class="text-right p-3">ยอดขาย</th>
                    <th class="p-3" style="width: 40%">กราฟ</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $maxSales = max(array_column($reportData['daily_trend'], 'sales'));
                foreach ($reportData['daily_trend'] as $day): 
                    $percent = $maxSales > 0 ? ($day['sales'] / $maxSales * 100) : 0;
                ?>
                <tr class="border-b">
                    <td class="p-3"><?= date('d M', strtotime($day['date'])) ?></td>
                    <td class="p-3 text-right"><?= number_format($day['transactions']) ?></td>
                    <td class="p-3 text-right font-semibold">฿<?= formatMoney($day['sales']) ?></td>
                    <td class="p-3">
                        <div class="payment-bar">
                            <div class="payment-bar-fill" style="width: <?= $percent ?>%; background: #22c55e"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($reportType === 'shifts'): ?>
<!-- Shift Reports -->
<div class="report-card">
    <h3 class="font-semibold mb-4"><i class="fas fa-user-clock mr-2 text-purple-500"></i>รายงานกะ - <?= date('d/m/Y', strtotime($date)) ?></h3>
    
    <?php if (empty($reportData)): ?>
    <p class="text-gray-500 text-center py-8">ไม่มีกะในวันนี้</p>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($reportData as $shift): ?>
        <div class="border rounded-lg p-4 hover:bg-gray-50">
            <div class="flex justify-between items-start">
                <div>
                    <div class="font-semibold"><?= $shift['shift_number'] ?></div>
                    <div class="text-sm text-gray-500">
                        พนักงาน: <?= htmlspecialchars($shift['cashier_name'] ?? '-') ?>
                    </div>
                    <div class="text-sm text-gray-500">
                        เปิด: <?= date('H:i', strtotime($shift['opened_at'])) ?>
                        <?php if ($shift['closed_at']): ?>
                        - ปิด: <?= date('H:i', strtotime($shift['closed_at'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-bold text-green-600">฿<?= formatMoney($shift['total_sales']) ?></div>
                    <div class="text-sm text-gray-500"><?= $shift['transaction_count'] ?> รายการ</div>
                    <span class="px-2 py-1 rounded text-xs <?= $shift['status'] === 'open' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700' ?>">
                        <?= $shift['status'] === 'open' ? 'เปิดอยู่' : 'ปิดแล้ว' ?>
                    </span>
                </div>
            </div>
            
            <?php if ($shift['status'] === 'closed'): ?>
            <div class="mt-3 pt-3 border-t grid grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">เงินเปิดกะ:</span>
                    <span class="font-semibold">฿<?= formatMoney($shift['opening_cash']) ?></span>
                </div>
                <div>
                    <span class="text-gray-500">เงินปิดกะ:</span>
                    <span class="font-semibold">฿<?= formatMoney($shift['closing_cash']) ?></span>
                </div>
                <div>
                    <span class="text-gray-500">ส่วนต่าง:</span>
                    <span class="font-semibold <?= $shift['variance'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                        ฿<?= formatMoney($shift['variance']) ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
function exportReport() {
    const type = '<?= $reportType ?>';
    const date = '<?= $date ?>';
    const month = '<?= $month ?>';
    
    // Simple CSV export
    alert('กำลังพัฒนาฟีเจอร์ส่งออก...');
}
</script>
