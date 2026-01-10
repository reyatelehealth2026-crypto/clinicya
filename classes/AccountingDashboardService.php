<?php
/**
 * AccountingDashboardService - Dashboard and Summary for Accounting Module
 * 
 * Provides aggregated financial data for the accounting dashboard including:
 * - Total AP, AR, and net position
 * - Upcoming payments due
 * - Overdue summaries
 * - Monthly expense summaries by category
 * 
 * Requirements: 6.1, 6.2, 6.3, 6.4
 */

require_once __DIR__ . '/AccountPayableService.php';
require_once __DIR__ . '/AccountReceivableService.php';
require_once __DIR__ . '/ExpenseService.php';

class AccountingDashboardService {
    private $db;
    private $lineAccountId;
    private $apService;
    private $arService;
    private $expenseService;
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->apService = new AccountPayableService($db, $lineAccountId);
        $this->arService = new AccountReceivableService($db, $lineAccountId);
        $this->expenseService = new ExpenseService($db, $lineAccountId);
    }
    
    /**
     * Get summary totals for dashboard
     * Requirement 6.1: Display total AP, total AR, and net position (AR - AP)
     * 
     * @return array Summary with total_ap, total_ar, net_position
     */
    public function getSummary(): array {
        $totalAp = $this->apService->getTotalOutstanding();
        $totalAr = $this->arService->getTotalOutstanding();
        $netPosition = $totalAr - $totalAp;
        
        // Get counts for open/partial records
        $apCounts = $this->getApCounts();
        $arCounts = $this->getArCounts();
        
        return [
            'total_ap' => $totalAp,
            'total_ar' => $totalAr,
            'net_position' => $netPosition,
            'ap_count' => $apCounts['open'] + $apCounts['partial'],
            'ar_count' => $arCounts['open'] + $arCounts['partial'],
            'ap_breakdown' => $apCounts,
            'ar_breakdown' => $arCounts
        ];
    }

    
    /**
     * Get upcoming payments due within specified days
     * Requirement 6.2: Show upcoming payments due within 7 days
     * 
     * @param int $days Number of days to look ahead (default 7)
     * @return array Combined list of upcoming AP and expense payments
     */
    public function getUpcomingPayments(int $days = 7): array {
        // Get upcoming AP payments
        $upcomingAp = $this->apService->getUpcomingDue($days);
        
        // Get upcoming expense payments
        $upcomingExpenses = $this->expenseService->getUpcomingDue($days);
        
        // Format AP records
        $apPayments = array_map(function($ap) {
            return [
                'type' => 'ap',
                'id' => $ap['id'],
                'reference' => $ap['ap_number'],
                'name' => $ap['supplier_name'] ?? 'Unknown Supplier',
                'amount' => (float)$ap['balance'],
                'due_date' => $ap['due_date'],
                'days_until_due' => $ap['days_until_due'] ?? null,
                'status' => $ap['status']
            ];
        }, $upcomingAp);
        
        // Format expense records
        $expensePayments = array_map(function($exp) {
            return [
                'type' => 'expense',
                'id' => $exp['id'],
                'reference' => $exp['expense_number'],
                'name' => $exp['vendor_name'] ?? $exp['category_name'] ?? 'Expense',
                'amount' => (float)$exp['amount'],
                'due_date' => $exp['due_date'],
                'days_until_due' => $exp['days_until_due'] ?? null,
                'status' => $exp['payment_status']
            ];
        }, $upcomingExpenses);
        
        // Combine and sort by due date
        $allPayments = array_merge($apPayments, $expensePayments);
        usort($allPayments, function($a, $b) {
            return strtotime($a['due_date']) - strtotime($b['due_date']);
        });
        
        // Calculate totals
        $totalAmount = array_sum(array_column($allPayments, 'amount'));
        
        return [
            'payments' => $allPayments,
            'total_amount' => $totalAmount,
            'count' => count($allPayments),
            'days_ahead' => $days
        ];
    }
    
    /**
     * Get overdue summary for both AP and AR
     * Requirement 6.3: Show overdue amounts for both AP and AR
     * 
     * @return array Overdue summary with AP and AR details
     */
    public function getOverdueSummary(): array {
        // Get overdue AP
        $overdueAp = $this->apService->getOverdue();
        $totalOverdueAp = $this->apService->getTotalOverdue();
        
        // Get overdue AR
        $overdueAr = $this->arService->getOverdue();
        $totalOverdueAr = $this->arService->getTotalOverdue();
        
        // Get overdue expenses
        $overdueExpenses = $this->expenseService->getOverdue();
        $totalOverdueExpenses = array_sum(array_column($overdueExpenses, 'amount'));
        
        return [
            'ap' => [
                'total_amount' => $totalOverdueAp,
                'count' => count($overdueAp),
                'records' => array_map(function($ap) {
                    return [
                        'id' => $ap['id'],
                        'reference' => $ap['ap_number'],
                        'name' => $ap['supplier_name'] ?? 'Unknown Supplier',
                        'amount' => (float)$ap['balance'],
                        'due_date' => $ap['due_date'],
                        'days_overdue' => $ap['days_overdue'] ?? 0
                    ];
                }, $overdueAp)
            ],
            'ar' => [
                'total_amount' => $totalOverdueAr,
                'count' => count($overdueAr),
                'records' => array_map(function($ar) {
                    return [
                        'id' => $ar['id'],
                        'reference' => $ar['ar_number'],
                        'name' => $ar['customer_name'] ?? 'Unknown Customer',
                        'amount' => (float)$ar['balance'],
                        'due_date' => $ar['due_date'],
                        'days_overdue' => $ar['days_overdue'] ?? 0
                    ];
                }, $overdueAr)
            ],
            'expenses' => [
                'total_amount' => $totalOverdueExpenses,
                'count' => count($overdueExpenses),
                'records' => array_map(function($exp) {
                    return [
                        'id' => $exp['id'],
                        'reference' => $exp['expense_number'],
                        'name' => $exp['vendor_name'] ?? $exp['category_name'] ?? 'Expense',
                        'amount' => (float)$exp['amount'],
                        'due_date' => $exp['due_date'],
                        'days_overdue' => $exp['days_overdue'] ?? 0
                    ];
                }, $overdueExpenses)
            ],
            'total_overdue_payables' => $totalOverdueAp + $totalOverdueExpenses,
            'total_overdue_receivables' => $totalOverdueAr
        ];
    }

    
    /**
     * Get expense summary by category for a specific month
     * Requirement 6.4: Display monthly expense summary by category
     * 
     * @param string $month Month in YYYY-MM format (defaults to current month)
     * @return array Expense summary grouped by category
     */
    public function getExpenseSummaryByCategory(string $month = ''): array {
        if (empty($month)) {
            $month = date('Y-m');
        }
        
        return $this->expenseService->getMonthlySummary($month);
    }
    
    /**
     * Get AP record counts by status
     * 
     * @return array Counts by status
     */
    private function getApCounts(): array {
        $sql = "
            SELECT 
                status,
                COUNT(*) as count
            FROM account_payables
            WHERE 1=1
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " GROUP BY status";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $counts = [
            'open' => 0,
            'partial' => 0,
            'paid' => 0,
            'cancelled' => 0
        ];
        
        foreach ($results as $row) {
            $counts[$row['status']] = (int)$row['count'];
        }
        
        return $counts;
    }
    
    /**
     * Get AR record counts by status
     * 
     * @return array Counts by status
     */
    private function getArCounts(): array {
        $sql = "
            SELECT 
                status,
                COUNT(*) as count
            FROM account_receivables
            WHERE 1=1
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " GROUP BY status";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $counts = [
            'open' => 0,
            'partial' => 0,
            'paid' => 0,
            'cancelled' => 0
        ];
        
        foreach ($results as $row) {
            $counts[$row['status']] = (int)$row['count'];
        }
        
        return $counts;
    }
    
    /**
     * Get complete dashboard data in a single call
     * Combines all dashboard methods for efficiency
     * 
     * @param int $upcomingDays Days to look ahead for upcoming payments
     * @param string $expenseMonth Month for expense summary (YYYY-MM)
     * @return array Complete dashboard data
     */
    public function getDashboardData(int $upcomingDays = 7, string $expenseMonth = ''): array {
        return [
            'summary' => $this->getSummary(),
            'upcoming_payments' => $this->getUpcomingPayments($upcomingDays),
            'overdue' => $this->getOverdueSummary(),
            'expense_summary' => $this->getExpenseSummaryByCategory($expenseMonth),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    
    /**
     * Get aging summary for both AP and AR
     * 
     * @return array Aging summary with brackets for AP and AR
     */
    public function getAgingSummary(): array {
        $apAging = $this->apService->getAgingReport();
        $arAging = $this->arService->getAgingReport();
        
        return [
            'ap' => $apAging,
            'ar' => $arAging
        ];
    }
    
    /**
     * Get cash flow projection for upcoming days
     * Shows expected inflows (AR collections) vs outflows (AP payments)
     * 
     * @param int $days Number of days to project
     * @return array Cash flow projection
     */
    public function getCashFlowProjection(int $days = 30): array {
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        
        // Get AP due in period (outflows)
        $apDue = $this->apService->getAll([
            'status' => ['open', 'partial'],
            'date_from' => $today,
            'date_to' => $endDate
        ]);
        
        // Get AR due in period (inflows)
        $arDue = $this->arService->getAll([
            'status' => ['open', 'partial'],
            'date_from' => $today,
            'date_to' => $endDate
        ]);
        
        // Get expenses due in period (outflows)
        $expensesDue = $this->expenseService->getUpcomingDue($days);
        
        $totalOutflows = array_sum(array_column($apDue, 'balance')) + 
                         array_sum(array_column($expensesDue, 'amount'));
        $totalInflows = array_sum(array_column($arDue, 'balance'));
        
        return [
            'period' => [
                'start' => $today,
                'end' => $endDate,
                'days' => $days
            ],
            'inflows' => [
                'ar_collections' => array_sum(array_column($arDue, 'balance')),
                'ar_count' => count($arDue)
            ],
            'outflows' => [
                'ap_payments' => array_sum(array_column($apDue, 'balance')),
                'ap_count' => count($apDue),
                'expense_payments' => array_sum(array_column($expensesDue, 'amount')),
                'expense_count' => count($expensesDue)
            ],
            'total_inflows' => $totalInflows,
            'total_outflows' => $totalOutflows,
            'net_cash_flow' => $totalInflows - $totalOutflows
        ];
    }
    
    /**
     * Get monthly trend data for AP, AR, and Expenses
     * 
     * @param int $months Number of months to include
     * @return array Monthly trend data
     */
    public function getMonthlyTrend(int $months = 6): array {
        $trends = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $startDate = $month . '-01';
            $endDate = date('Y-m-t', strtotime($startDate));
            
            // Get AP created in month
            $apData = $this->getMonthlyApData($startDate, $endDate);
            
            // Get AR created in month
            $arData = $this->getMonthlyArData($startDate, $endDate);
            
            // Get expenses in month
            $expenseData = $this->expenseService->getMonthlySummary($month);
            
            $trends[] = [
                'month' => $month,
                'ap' => $apData,
                'ar' => $arData,
                'expenses' => $expenseData['summary']
            ];
        }
        
        return $trends;
    }
    
    /**
     * Get monthly AP data
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array AP data for the period
     */
    private function getMonthlyApData(string $startDate, string $endDate): array {
        $sql = "
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(paid_amount), 0) as paid_amount
            FROM account_payables
            WHERE created_at BETWEEN ? AND ?
        ";
        $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get monthly AR data
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array AR data for the period
     */
    private function getMonthlyArData(string $startDate, string $endDate): array {
        $sql = "
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(received_amount), 0) as received_amount
            FROM account_receivables
            WHERE created_at BETWEEN ? AND ?
        ";
        $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get Profit/Loss Summary
     * คำนวณกำไรขาดทุนจากรายรับ (ยอดขาย) และรายจ่าย (ต้นทุน + ค่าใช้จ่าย)
     * 
     * @param string $month Month in YYYY-MM format (defaults to current month)
     * @return array Profit/Loss summary
     */
    public function getProfitLossSummary(string $month = ''): array {
        if (empty($month)) {
            $month = date('Y-m');
        }
        
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        // Get revenue from orders (sales)
        $revenue = $this->getMonthlyRevenue($startDate, $endDate);
        
        // Get cost of goods sold (COGS) from orders
        $cogs = $this->getMonthlyCOGS($startDate, $endDate);
        
        // Get operating expenses
        $expenses = $this->getMonthlyExpenses($startDate, $endDate);
        
        // Calculate gross profit
        $grossProfit = $revenue['total'] - $cogs['total'];
        $grossMargin = $revenue['total'] > 0 ? ($grossProfit / $revenue['total']) * 100 : 0;
        
        // Calculate net profit
        $netProfit = $grossProfit - $expenses['total'];
        $netMargin = $revenue['total'] > 0 ? ($netProfit / $revenue['total']) * 100 : 0;
        
        return [
            'month' => $month,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => round($grossMargin, 2),
            'expenses' => $expenses,
            'net_profit' => $netProfit,
            'net_margin' => round($netMargin, 2),
            'is_profitable' => $netProfit >= 0
        ];
    }
    
    /**
     * Get monthly revenue from orders
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Revenue data
     */
    private function getMonthlyRevenue(string $startDate, string $endDate): array {
        $totalRevenue = 0;
        $totalOrders = 0;
        $posRevenue = 0;
        $posTransactions = 0;
        
        // Get POS sales revenue
        try {
            $sql = "
                SELECT 
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(total_amount), 0) as total
                FROM pos_transactions
                WHERE DATE(created_at) BETWEEN ? AND ?
                AND status = 'completed'
            ";
            $params = [$startDate, $endDate];
            
            if ($this->lineAccountId) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $posResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $posRevenue = (float)($posResult['total'] ?? 0);
            $posTransactions = (int)($posResult['transaction_count'] ?? 0);
        } catch (Exception $e) {
            // POS table doesn't exist
        }
        
        // Get online orders revenue
        try {
            $sql = "
                SELECT 
                    COUNT(*) as order_count,
                    COALESCE(SUM(total_amount), 0) as total
                FROM orders
                WHERE order_date BETWEEN ? AND ?
                AND status IN ('completed', 'delivered', 'paid')
            ";
            $params = [$startDate, $endDate];
            
            if ($this->lineAccountId) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $totalRevenue = (float)($result['total'] ?? 0) + $posRevenue;
            $totalOrders = (int)($result['order_count'] ?? 0) + $posTransactions;
            
            return [
                'total' => $totalRevenue,
                'order_count' => $totalOrders,
                'online_sales' => (float)($result['total'] ?? 0),
                'online_orders' => (int)($result['order_count'] ?? 0),
                'pos_sales' => $posRevenue,
                'pos_transactions' => $posTransactions
            ];
        } catch (Exception $e) {
            // Fallback: use AR as revenue proxy + POS
            $arRevenue = $this->getMonthlyArRevenue($startDate, $endDate);
            return [
                'total' => $arRevenue['total'] + $posRevenue,
                'order_count' => $arRevenue['order_count'] + $posTransactions,
                'online_sales' => $arRevenue['total'],
                'online_orders' => $arRevenue['order_count'],
                'pos_sales' => $posRevenue,
                'pos_transactions' => $posTransactions
            ];
        }
    }
    
    /**
     * Get monthly revenue from AR (fallback)
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Revenue data
     */
    private function getMonthlyArRevenue(string $startDate, string $endDate): array {
        $sql = "
            SELECT 
                COUNT(*) as invoice_count,
                COALESCE(SUM(total_amount), 0) as total
            FROM account_receivables
            WHERE invoice_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total' => (float)($result['total'] ?? 0),
            'order_count' => (int)($result['invoice_count'] ?? 0)
        ];
    }
    
    /**
     * Get monthly COGS from orders or stock movements
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array COGS data
     */
    private function getMonthlyCOGS(string $startDate, string $endDate): array {
        // Try to get COGS from stock movements (sales type)
        try {
            $sql = "
                SELECT 
                    COUNT(*) as movement_count,
                    COALESCE(SUM(ABS(total_value)), 0) as total
                FROM stock_movements
                WHERE movement_date BETWEEN ? AND ?
                AND movement_type = 'sale'
            ";
            $params = [$startDate, $endDate];
            
            if ($this->lineAccountId) {
                $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
                $params[] = $this->lineAccountId;
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ((float)($result['total'] ?? 0) > 0) {
                return [
                    'total' => (float)$result['total'],
                    'movement_count' => (int)$result['movement_count']
                ];
            }
        } catch (Exception $e) {
            // Table doesn't exist
        }
        
        // Fallback: estimate COGS from AP (purchases)
        return $this->getMonthlyApCOGS($startDate, $endDate);
    }
    
    /**
     * Get monthly COGS from AP (fallback - purchases as proxy)
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array COGS data
     */
    private function getMonthlyApCOGS(string $startDate, string $endDate): array {
        // Use all AP as COGS proxy (purchases from suppliers)
        $sql = "
            SELECT 
                COUNT(*) as purchase_count,
                COALESCE(SUM(total_amount), 0) as total
            FROM account_payables
            WHERE invoice_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total' => (float)($result['total'] ?? 0),
            'movement_count' => (int)($result['purchase_count'] ?? 0)
        ];
    }
    
    /**
     * Get monthly operating expenses
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array Expenses data with breakdown by category
     */
    private function getMonthlyExpenses(string $startDate, string $endDate): array {
        $sql = "
            SELECT 
                COUNT(*) as expense_count,
                COALESCE(SUM(amount), 0) as total
            FROM expenses
            WHERE expense_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get breakdown by category
        $sqlCategory = "
            SELECT 
                ec.name as category_name,
                COUNT(*) as count,
                COALESCE(SUM(e.amount), 0) as total
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE e.expense_date BETWEEN ? AND ?
        ";
        $paramsCategory = [$startDate, $endDate];
        
        if ($this->lineAccountId) {
            $sqlCategory .= " AND (e.line_account_id = ? OR e.line_account_id IS NULL)";
            $paramsCategory[] = $this->lineAccountId;
        }
        
        $sqlCategory .= " GROUP BY e.category_id, ec.name ORDER BY total DESC";
        
        $stmtCategory = $this->db->prepare($sqlCategory);
        $stmtCategory->execute($paramsCategory);
        $categories = $stmtCategory->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'total' => (float)($result['total'] ?? 0),
            'expense_count' => (int)($result['expense_count'] ?? 0),
            'by_category' => $categories
        ];
    }
    
    /**
     * Get Profit/Loss trend for multiple months
     * 
     * @param int $months Number of months to include
     * @return array Monthly P&L trend
     */
    public function getProfitLossTrend(int $months = 6): array {
        $trends = [];
        
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $trends[] = $this->getProfitLossSummary($month);
        }
        
        return $trends;
    }
}
