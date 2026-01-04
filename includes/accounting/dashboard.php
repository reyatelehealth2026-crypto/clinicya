<?php
/**
 * Accounting Dashboard Tab Content
 * แดชบอร์ดสำหรับระบบบัญชี
 * 
 * Requirements: 6.1, 6.2, 6.3, 6.4
 * - Display summary cards (Total AP, AR, Net Position)
 * - Display upcoming payments due within 7 days
 * - Display overdue amounts
 * - Display monthly expense chart by category
 * 
 * @package AccountingManagement
 * @version 1.0.0
 */

require_once __DIR__ . '/../../classes/AccountingDashboardService.php';

// Initialize dashboard service
$dashboardService = new AccountingDashboardService($db, $currentBotId);

// Get all dashboard data
$currentMonth = date('Y-m');
$dashboardData = $dashboardService->getDashboardData(7, $currentMonth);

$summary = $dashboardData['summary'];
$upcomingPayments = $dashboardData['upcoming_payments'];
$overdue = $dashboardData['overdue'];
$expenseSummary = $dashboardData['expense_summary'];

// Get aging summary for additional insights
$agingSummary = $dashboardService->getAgingSummary();

// Format numbers helper
function formatMoney($amount) {
    return number_format((float)$amount, 2);
}
?>

<style>
.stat-card { transition: all 0.3s; }
.stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
.overdue-badge { animation: pulse 2s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
.positive-value { color: #22c55e; }
.negative-value { color: #ef4444; }
.neutral-value { color: #64748b; }
.chart-bar { transition: width 0.5s ease; }
</style>

<!-- Header Actions -->
<div class="flex items-center justify-between mb-6">
    <div>
        <p class="text-gray-500">ภาพรวมสถานะการเงิน เจ้าหนี้ ลูกหนี้ และค่าใช้จ่าย</p>
    </div>
    <div class="flex gap-3">
        <button onclick="refreshDashboard()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
            <i class="fas fa-sync-alt mr-2"></i>รีเฟรช
        </button>
        <select id="monthSelector" onchange="changeMonth(this.value)" class="px-4 py-2 bg-white border border-gray-200 rounded-lg">
            <?php 
            for ($i = 0; $i < 6; $i++): 
                $monthValue = date('Y-m', strtotime("-{$i} months"));
                $monthLabel = date('M Y', strtotime("-{$i} months"));
                $selected = ($monthValue === $currentMonth) ? 'selected' : '';
            ?>
            <option value="<?= $monthValue ?>" <?= $selected ?>><?= $monthLabel ?></option>
            <?php endfor; ?>
        </select>
    </div>
</div>

<!-- Summary Cards - Requirement 6.1 -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
    <!-- Total AP -->
    <div class="stat-card bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">เจ้าหนี้ (AP)</p>
                <p class="text-2xl font-bold text-red-600">฿<?= formatMoney($summary['total_ap']) ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $summary['ap_count'] ?> รายการ</p>
            </div>
            <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center">
                <i class="fas fa-file-invoice-dollar text-red-500 text-xl"></i>
            </div>
        </div>
    </div>
    
    <!-- Total AR -->
    <div class="stat-card bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">ลูกหนี้ (AR)</p>
                <p class="text-2xl font-bold text-green-600">฿<?= formatMoney($summary['total_ar']) ?></p>
                <p class="text-xs text-gray-400 mt-1"><?= $summary['ar_count'] ?> รายการ</p>
            </div>
            <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center">
                <i class="fas fa-hand-holding-usd text-green-500 text-xl"></i>
            </div>
        </div>
    </div>
    
    <!-- Net Position -->
    <div class="stat-card bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">สถานะสุทธิ</p>
                <?php 
                $netPosition = $summary['net_position'];
                $netClass = $netPosition >= 0 ? 'text-green-600' : 'text-red-600';
                $netSign = $netPosition >= 0 ? '+' : '';
                ?>
                <p class="text-2xl font-bold <?= $netClass ?>"><?= $netSign ?>฿<?= formatMoney($netPosition) ?></p>
                <p class="text-xs text-gray-400 mt-1">AR - AP</p>
            </div>
            <div class="w-14 h-14 <?= $netPosition >= 0 ? 'bg-green-100' : 'bg-red-100' ?> rounded-full flex items-center justify-center">
                <i class="fas fa-balance-scale <?= $netPosition >= 0 ? 'text-green-500' : 'text-red-500' ?> text-xl"></i>
            </div>
        </div>
    </div>
    
    <!-- Total Overdue -->
    <div class="stat-card bg-white rounded-xl shadow p-6 <?= ($overdue['total_overdue_payables'] > 0 || $overdue['total_overdue_receivables'] > 0) ? 'ring-2 ring-orange-400' : '' ?>">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-500 text-sm">ค้างชำระ</p>
                <p class="text-2xl font-bold text-orange-600">฿<?= formatMoney($overdue['total_overdue_payables'] + $overdue['total_overdue_receivables']) ?></p>
                <p class="text-xs text-gray-400 mt-1">
                    AP: <?= $overdue['ap']['count'] ?> | AR: <?= $overdue['ar']['count'] ?>
                </p>
            </div>
            <div class="w-14 h-14 bg-orange-100 rounded-full flex items-center justify-center <?= ($overdue['total_overdue_payables'] > 0) ? 'overdue-badge' : '' ?>">
                <i class="fas fa-exclamation-triangle text-orange-500 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Upcoming Payments - Requirement 6.2 -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b flex items-center justify-between">
                <h3 class="font-semibold text-gray-800">
                    <i class="fas fa-calendar-alt text-blue-500 mr-2"></i>รายการครบกำหนดใน 7 วัน
                </h3>
                <span class="text-sm text-gray-500">
                    <?= $upcomingPayments['count'] ?> รายการ | รวม ฿<?= formatMoney($upcomingPayments['total_amount']) ?>
                </span>
            </div>
            
            <div class="divide-y max-h-[400px] overflow-y-auto">
                <?php if (empty($upcomingPayments['payments'])): ?>
                <div class="p-8 text-center text-gray-400">
                    <i class="fas fa-check-circle text-4xl mb-3 text-green-400"></i>
                    <p>ไม่มีรายการครบกำหนดใน 7 วันข้างหน้า</p>
                </div>
                <?php else: ?>
                <?php foreach ($upcomingPayments['payments'] as $payment): ?>
                <?php 
                    $daysUntil = $payment['days_until_due'] ?? 0;
                    $urgencyClass = $daysUntil <= 2 ? 'border-l-4 border-red-500 bg-red-50' : 
                                   ($daysUntil <= 5 ? 'border-l-4 border-yellow-500 bg-yellow-50' : 'border-l-4 border-blue-500');
                    $typeLabel = $payment['type'] === 'ap' ? 'เจ้าหนี้' : 'ค่าใช้จ่าย';
                    $typeColor = $payment['type'] === 'ap' ? 'bg-red-100 text-red-700' : 'bg-purple-100 text-purple-700';
                ?>
                <div class="p-4 hover:bg-gray-50 <?= $urgencyClass ?>">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="px-2 py-1 text-xs rounded-full <?= $typeColor ?>"><?= $typeLabel ?></span>
                            <div>
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($payment['name']) ?></p>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars($payment['reference']) ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-gray-800">฿<?= formatMoney($payment['amount']) ?></p>
                            <p class="text-xs <?= $daysUntil <= 2 ? 'text-red-600 font-medium' : 'text-gray-500' ?>">
                                <?php if ($daysUntil == 0): ?>
                                    ครบกำหนดวันนี้
                                <?php elseif ($daysUntil == 1): ?>
                                    ครบกำหนดพรุ่งนี้
                                <?php else: ?>
                                    อีก <?= $daysUntil ?> วัน
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Overdue Summary - Requirement 6.3 -->
    <div>
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b">
                <h3 class="font-semibold text-gray-800">
                    <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>รายการค้างชำระ
                </h3>
            </div>
            
            <div class="p-4 space-y-4">
                <!-- AP Overdue -->
                <div class="p-3 bg-red-50 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-red-700">เจ้าหนี้ค้างชำระ</span>
                        <span class="text-sm text-red-600"><?= $overdue['ap']['count'] ?> รายการ</span>
                    </div>
                    <p class="text-xl font-bold text-red-700">฿<?= formatMoney($overdue['ap']['total_amount']) ?></p>
                    <?php if (!empty($overdue['ap']['records'])): ?>
                    <div class="mt-2 space-y-1">
                        <?php foreach (array_slice($overdue['ap']['records'], 0, 3) as $record): ?>
                        <div class="text-xs text-red-600 flex justify-between">
                            <span><?= htmlspecialchars($record['name']) ?></span>
                            <span><?= $record['days_overdue'] ?> วัน</span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($overdue['ap']['records']) > 3): ?>
                        <a href="accounting.php?tab=ap&status=overdue" class="text-xs text-red-700 hover:underline">
                            ดูทั้งหมด →
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- AR Overdue -->
                <div class="p-3 bg-orange-50 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-orange-700">ลูกหนี้ค้างชำระ</span>
                        <span class="text-sm text-orange-600"><?= $overdue['ar']['count'] ?> รายการ</span>
                    </div>
                    <p class="text-xl font-bold text-orange-700">฿<?= formatMoney($overdue['ar']['total_amount']) ?></p>
                    <?php if (!empty($overdue['ar']['records'])): ?>
                    <div class="mt-2 space-y-1">
                        <?php foreach (array_slice($overdue['ar']['records'], 0, 3) as $record): ?>
                        <div class="text-xs text-orange-600 flex justify-between">
                            <span><?= htmlspecialchars($record['name']) ?></span>
                            <span><?= $record['days_overdue'] ?> วัน</span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (count($overdue['ar']['records']) > 3): ?>
                        <a href="accounting.php?tab=ar&status=overdue" class="text-xs text-orange-700 hover:underline">
                            ดูทั้งหมด →
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Expense Chart - Requirement 6.4 -->
<div class="mt-6">
    <div class="bg-white rounded-xl shadow">
        <div class="p-4 border-b flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">
                <i class="fas fa-chart-pie text-purple-500 mr-2"></i>ค่าใช้จ่ายประจำเดือน <?= date('M Y', strtotime($currentMonth . '-01')) ?>
            </h3>
            <span class="text-sm text-gray-500">
                รวม ฿<?= formatMoney($expenseSummary['summary']['total_amount'] ?? 0) ?>
            </span>
        </div>
        
        <div class="p-4">
            <?php 
            // Filter categories with expenses
            $categoriesWithExpenses = array_filter($expenseSummary['by_category'] ?? [], function($cat) {
                return (float)$cat['total_amount'] > 0;
            });
            ?>
            <?php if (empty($categoriesWithExpenses)): ?>
            <div class="text-center text-gray-400 py-8">
                <i class="fas fa-receipt text-4xl mb-3"></i>
                <p>ยังไม่มีค่าใช้จ่ายในเดือนนี้</p>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Bar Chart -->
                <div class="space-y-3">
                    <?php 
                    $maxAmount = max(array_column($categoriesWithExpenses, 'total_amount'));
                    $colors = ['bg-blue-500', 'bg-green-500', 'bg-yellow-500', 'bg-purple-500', 'bg-pink-500', 'bg-indigo-500', 'bg-red-500', 'bg-orange-500'];
                    $colorIndex = 0;
                    foreach ($categoriesWithExpenses as $category): 
                        $percentage = $maxAmount > 0 ? ($category['total_amount'] / $maxAmount) * 100 : 0;
                        $color = $colors[$colorIndex % count($colors)];
                        $colorIndex++;
                    ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-700"><?= htmlspecialchars($category['category_name']) ?></span>
                            <span class="font-medium">฿<?= formatMoney($category['total_amount']) ?></span>
                        </div>
                        <div class="h-4 bg-gray-100 rounded-full overflow-hidden">
                            <div class="chart-bar h-full <?= $color ?> rounded-full" style="width: <?= $percentage ?>%"></div>
                        </div>
                        <div class="text-xs text-gray-400 mt-1"><?= $category['expense_count'] ?> รายการ</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Summary Stats -->
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500">จำนวนรายการ</p>
                            <p class="text-2xl font-bold text-gray-800"><?= $expenseSummary['summary']['expense_count'] ?? 0 ?></p>
                        </div>
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500">ค่าเฉลี่ย/รายการ</p>
                            <?php 
                            $avgAmount = ($expenseSummary['summary']['expense_count'] ?? 0) > 0 
                                ? ($expenseSummary['summary']['total_amount'] ?? 0) / $expenseSummary['summary']['expense_count'] 
                                : 0;
                            ?>
                            <p class="text-2xl font-bold text-gray-800">฿<?= formatMoney($avgAmount) ?></p>
                        </div>
                    </div>
                    
                    <div class="p-4 bg-blue-50 rounded-lg">
                        <p class="text-sm text-blue-600 mb-2">สถานะการชำระ</p>
                        <div class="flex justify-between">
                            <div>
                                <p class="text-xs text-gray-500">ชำระแล้ว</p>
                                <p class="font-semibold text-green-600">฿<?= formatMoney($expenseSummary['summary']['paid_amount'] ?? 0) ?></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">ยังไม่ชำระ</p>
                                <p class="font-semibold text-red-600">฿<?= formatMoney($expenseSummary['summary']['unpaid_amount'] ?? 0) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <a href="accounting.php?tab=expenses" class="block text-center py-2 px-4 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
                        <i class="fas fa-list mr-2"></i>ดูรายละเอียดค่าใช้จ่าย
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4">
    <a href="accounting.php?tab=ap" class="flex items-center gap-3 p-4 bg-white rounded-xl shadow hover:shadow-md transition-shadow">
        <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-file-invoice-dollar text-red-500"></i>
        </div>
        <div>
            <p class="font-medium text-gray-800">เจ้าหนี้</p>
            <p class="text-xs text-gray-500"><?= $summary['ap_count'] ?> รายการ</p>
        </div>
    </a>
    
    <a href="accounting.php?tab=ar" class="flex items-center gap-3 p-4 bg-white rounded-xl shadow hover:shadow-md transition-shadow">
        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-hand-holding-usd text-green-500"></i>
        </div>
        <div>
            <p class="font-medium text-gray-800">ลูกหนี้</p>
            <p class="text-xs text-gray-500"><?= $summary['ar_count'] ?> รายการ</p>
        </div>
    </a>
    
    <a href="accounting.php?tab=expenses" class="flex items-center gap-3 p-4 bg-white rounded-xl shadow hover:shadow-md transition-shadow">
        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-receipt text-purple-500"></i>
        </div>
        <div>
            <p class="font-medium text-gray-800">ค่าใช้จ่าย</p>
            <p class="text-xs text-gray-500">บันทึกรายจ่าย</p>
        </div>
    </a>
    
    <a href="procurement.php" class="flex items-center gap-3 p-4 bg-white rounded-xl shadow hover:shadow-md transition-shadow">
        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-shopping-cart text-blue-500"></i>
        </div>
        <div>
            <p class="font-medium text-gray-800">จัดซื้อ</p>
            <p class="text-xs text-gray-500">PO / GR</p>
        </div>
    </a>
</div>

<script>
function refreshDashboard() {
    location.reload();
}

function changeMonth(month) {
    const url = new URL(window.location.href);
    url.searchParams.set('expense_month', month);
    window.location.href = url.toString();
}

// Auto-refresh every 5 minutes
setTimeout(function() {
    refreshDashboard();
}, 300000);
</script>
