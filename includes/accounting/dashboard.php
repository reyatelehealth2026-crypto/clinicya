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

// Get Profit/Loss summary
$profitLoss = $dashboardService->getProfitLossSummary($currentMonth);
$profitLossTrend = $dashboardService->getProfitLossTrend(6);

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

<!-- Profit/Loss Summary Section -->
<div class="bg-white rounded-xl shadow mb-8">
    <div class="p-4 border-b flex items-center justify-between">
        <h3 class="font-semibold text-gray-800">
            <i class="fas fa-chart-line text-indigo-500 mr-2"></i>สรุปกำไรขาดทุน (Profit/Loss) - <?= date('M Y', strtotime($currentMonth . '-01')) ?>
        </h3>
        <span class="px-3 py-1 rounded-full text-sm font-medium <?= $profitLoss['is_profitable'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
            <?= $profitLoss['is_profitable'] ? 'กำไร' : 'ขาดทุน' ?>
        </span>
    </div>
    
    <div class="p-6">
        <!-- P&L Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <!-- Revenue -->
            <div class="p-4 bg-blue-50 rounded-lg">
                <p class="text-xs text-blue-600 mb-1">รายได้ (Revenue)</p>
                <p class="text-xl font-bold text-blue-700">฿<?= formatMoney($profitLoss['revenue']['total']) ?></p>
                <p class="text-xs text-blue-500 mt-1"><?= $profitLoss['revenue']['order_count'] ?> รายการ</p>
                <?php if (isset($profitLoss['revenue']['pos_sales']) && $profitLoss['revenue']['pos_sales'] > 0): ?>
                <p class="text-xs text-green-600 mt-1">
                    <i class="fas fa-cash-register"></i> POS: ฿<?= formatMoney($profitLoss['revenue']['pos_sales']) ?>
                </p>
                <?php endif; ?>
            </div>
            
            <!-- COGS -->
            <div class="p-4 bg-red-50 rounded-lg">
                <p class="text-xs text-red-600 mb-1">ต้นทุนขาย (COGS)</p>
                <p class="text-xl font-bold text-red-700">฿<?= formatMoney($profitLoss['cogs']['total']) ?></p>
                <p class="text-xs text-red-500 mt-1"><?= $profitLoss['cogs']['movement_count'] ?? 0 ?> รายการ</p>
            </div>
            
            <!-- Gross Profit -->
            <div class="p-4 <?= $profitLoss['gross_profit'] >= 0 ? 'bg-emerald-50' : 'bg-orange-50' ?> rounded-lg">
                <p class="text-xs <?= $profitLoss['gross_profit'] >= 0 ? 'text-emerald-600' : 'text-orange-600' ?> mb-1">กำไรขั้นต้น</p>
                <p class="text-xl font-bold <?= $profitLoss['gross_profit'] >= 0 ? 'text-emerald-700' : 'text-orange-700' ?>">
                    <?= $profitLoss['gross_profit'] >= 0 ? '' : '-' ?>฿<?= formatMoney(abs($profitLoss['gross_profit'])) ?>
                </p>
                <p class="text-xs <?= $profitLoss['gross_profit'] >= 0 ? 'text-emerald-500' : 'text-orange-500' ?> mt-1">
                    Margin: <?= $profitLoss['gross_margin'] ?>%
                </p>
            </div>
            
            <!-- Operating Expenses -->
            <div class="p-4 bg-purple-50 rounded-lg">
                <p class="text-xs text-purple-600 mb-1">ค่าใช้จ่าย (OPEX)</p>
                <p class="text-xl font-bold text-purple-700">฿<?= formatMoney($profitLoss['expenses']['total']) ?></p>
                <p class="text-xs text-purple-500 mt-1"><?= $profitLoss['expenses']['expense_count'] ?> รายการ</p>
            </div>
            
            <!-- Net Profit -->
            <div class="p-4 <?= $profitLoss['is_profitable'] ? 'bg-green-100 ring-2 ring-green-400' : 'bg-red-100 ring-2 ring-red-400' ?> rounded-lg">
                <p class="text-xs <?= $profitLoss['is_profitable'] ? 'text-green-600' : 'text-red-600' ?> mb-1">กำไรสุทธิ (Net Profit)</p>
                <p class="text-2xl font-bold <?= $profitLoss['is_profitable'] ? 'text-green-700' : 'text-red-700' ?>">
                    <?= $profitLoss['net_profit'] >= 0 ? '+' : '' ?>฿<?= formatMoney($profitLoss['net_profit']) ?>
                </p>
                <p class="text-xs <?= $profitLoss['is_profitable'] ? 'text-green-500' : 'text-red-500' ?> mt-1">
                    Margin: <?= $profitLoss['net_margin'] ?>%
                </p>
            </div>
        </div>
        
        <!-- P&L Breakdown & Trend -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- P&L Waterfall -->
            <div>
                <h4 class="text-sm font-medium text-gray-700 mb-3">รายละเอียดกำไรขาดทุน</h4>
                <div class="space-y-2">
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <span class="text-gray-600">รายได้จากการขาย</span>
                        <span class="font-semibold text-blue-600">+฿<?= formatMoney($profitLoss['revenue']['total']) ?></span>
                    </div>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <span class="text-gray-600">หัก: ต้นทุนขาย</span>
                        <span class="font-semibold text-red-600">-฿<?= formatMoney($profitLoss['cogs']['total']) ?></span>
                    </div>
                    <div class="flex items-center justify-between p-3 <?= $profitLoss['gross_profit'] >= 0 ? 'bg-emerald-50' : 'bg-orange-50' ?> rounded-lg border-l-4 <?= $profitLoss['gross_profit'] >= 0 ? 'border-emerald-500' : 'border-orange-500' ?>">
                        <span class="font-medium <?= $profitLoss['gross_profit'] >= 0 ? 'text-emerald-700' : 'text-orange-700' ?>">= กำไรขั้นต้น</span>
                        <span class="font-bold <?= $profitLoss['gross_profit'] >= 0 ? 'text-emerald-600' : 'text-orange-600' ?>">
                            ฿<?= formatMoney($profitLoss['gross_profit']) ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <span class="text-gray-600">หัก: ค่าใช้จ่ายดำเนินงาน</span>
                        <span class="font-semibold text-purple-600">-฿<?= formatMoney($profitLoss['expenses']['total']) ?></span>
                    </div>
                    <div class="flex items-center justify-between p-3 <?= $profitLoss['is_profitable'] ? 'bg-green-100' : 'bg-red-100' ?> rounded-lg border-l-4 <?= $profitLoss['is_profitable'] ? 'border-green-500' : 'border-red-500' ?>">
                        <span class="font-medium <?= $profitLoss['is_profitable'] ? 'text-green-700' : 'text-red-700' ?>">= กำไรสุทธิ</span>
                        <span class="font-bold text-lg <?= $profitLoss['is_profitable'] ? 'text-green-600' : 'text-red-600' ?>">
                            ฿<?= formatMoney($profitLoss['net_profit']) ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- 6-Month Trend -->
            <div>
                <h4 class="text-sm font-medium text-gray-700 mb-3">แนวโน้ม 6 เดือนย้อนหลัง</h4>
                <div class="space-y-2">
                    <?php 
                    $maxNetProfit = max(array_map(function($t) { return abs($t['net_profit']); }, $profitLossTrend));
                    foreach ($profitLossTrend as $trend): 
                        $barWidth = $maxNetProfit > 0 ? (abs($trend['net_profit']) / $maxNetProfit) * 100 : 0;
                        $isProfit = $trend['net_profit'] >= 0;
                    ?>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-500 w-16"><?= date('M y', strtotime($trend['month'] . '-01')) ?></span>
                        <div class="flex-1 h-6 bg-gray-100 rounded-full overflow-hidden relative">
                            <div class="h-full <?= $isProfit ? 'bg-green-500' : 'bg-red-500' ?> rounded-full transition-all duration-500" 
                                 style="width: <?= $barWidth ?>%"></div>
                        </div>
                        <span class="text-xs font-medium w-24 text-right <?= $isProfit ? 'text-green-600' : 'text-red-600' ?>">
                            <?= $isProfit ? '+' : '' ?>฿<?= formatMoney($trend['net_profit']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Summary Stats -->
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <?php 
                    $totalNetProfit = array_sum(array_column($profitLossTrend, 'net_profit'));
                    $avgNetProfit = count($profitLossTrend) > 0 ? $totalNetProfit / count($profitLossTrend) : 0;
                    $profitableMonths = count(array_filter($profitLossTrend, function($t) { return $t['net_profit'] >= 0; }));
                    ?>
                    <div class="p-3 bg-gray-50 rounded-lg text-center">
                        <p class="text-xs text-gray-500">กำไรเฉลี่ย/เดือน</p>
                        <p class="font-semibold <?= $avgNetProfit >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                            ฿<?= formatMoney($avgNetProfit) ?>
                        </p>
                    </div>
                    <div class="p-3 bg-gray-50 rounded-lg text-center">
                        <p class="text-xs text-gray-500">เดือนที่มีกำไร</p>
                        <p class="font-semibold text-gray-700"><?= $profitableMonths ?>/<?= count($profitLossTrend) ?> เดือน</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Expense Breakdown by Category (if has expenses) -->
        <?php if (!empty($profitLoss['expenses']['by_category'])): ?>
        <div class="mt-6 pt-6 border-t">
            <h4 class="text-sm font-medium text-gray-700 mb-3">ค่าใช้จ่ายแยกตามหมวดหมู่</h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php 
                $expenseColors = ['bg-purple-100 text-purple-700', 'bg-pink-100 text-pink-700', 'bg-indigo-100 text-indigo-700', 'bg-blue-100 text-blue-700'];
                $colorIdx = 0;
                foreach (array_slice($profitLoss['expenses']['by_category'], 0, 4) as $cat): 
                    $color = $expenseColors[$colorIdx % count($expenseColors)];
                    $colorIdx++;
                ?>
                <div class="p-3 <?= $color ?> rounded-lg">
                    <p class="text-xs opacity-75"><?= htmlspecialchars($cat['category_name'] ?? 'อื่นๆ') ?></p>
                    <p class="font-semibold">฿<?= formatMoney($cat['total']) ?></p>
                    <p class="text-xs opacity-60"><?= $cat['count'] ?> รายการ</p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
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
    
    <a href="pos.php?tab=reports" class="flex items-center gap-3 p-4 bg-white rounded-xl shadow hover:shadow-md transition-shadow">
        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
            <i class="fas fa-cash-register text-green-500"></i>
        </div>
        <div>
            <p class="font-medium text-gray-800">POS รายงาน</p>
            <p class="text-xs text-gray-500">ยอดขายหน้าร้าน</p>
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
