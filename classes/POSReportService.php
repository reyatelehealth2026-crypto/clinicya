<?php
/**
 * POSReportService - รายงานการขายหน้าร้าน
 * 
 * สรุปยอดขายรายวัน/รายเดือน
 * แยกตามวิธีชำระเงิน
 * สินค้าขายดี
 * รายงานกะ
 */

class POSReportService {
    private $db;
    private $lineAccountId;
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
    }
    
    /**
     * สรุปยอดขายรายวัน
     */
    public function getDailySummary(string $date = null): array {
        $date = $date ?? date('Y-m-d');
        
        // ยอดขายรวม
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_transactions,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as total_sales,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN vat_amount ELSE 0 END), 0) as total_vat,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN discount_amount ELSE 0 END), 0) as total_discount,
                COUNT(CASE WHEN status = 'voided' THEN 1 END) as voided_count,
                COALESCE(SUM(CASE WHEN status = 'voided' THEN total_amount ELSE 0 END), 0) as voided_amount
            FROM pos_transactions 
            WHERE DATE(created_at) = ? AND line_account_id = ?
        ");
        $stmt->execute([$date, $this->lineAccountId]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // แยกตามวิธีชำระเงิน
        $stmt = $this->db->prepare("
            SELECT 
                p.payment_method,
                COUNT(*) as count,
                COALESCE(SUM(p.amount), 0) as total
            FROM pos_payments p
            JOIN pos_transactions t ON p.transaction_id = t.id
            WHERE DATE(t.created_at) = ? AND t.line_account_id = ? AND t.status = 'completed'
            GROUP BY p.payment_method
        ");
        $stmt->execute([$date, $this->lineAccountId]);
        $paymentBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // คืนสินค้า
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as return_count,
                COALESCE(SUM(refund_amount), 0) as total_refunds
            FROM pos_returns 
            WHERE DATE(created_at) = ? AND line_account_id = ? AND status = 'completed'
        ");
        $stmt->execute([$date, $this->lineAccountId]);
        $returns = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // สินค้าขายดี
        $stmt = $this->db->prepare("
            SELECT 
                ti.product_id,
                ti.product_name,
                SUM(ti.quantity) as total_qty,
                SUM(ti.line_total) as total_sales
            FROM pos_transaction_items ti
            JOIN pos_transactions t ON ti.transaction_id = t.id
            WHERE DATE(t.created_at) = ? AND t.line_account_id = ? AND t.status = 'completed'
            GROUP BY ti.product_id, ti.product_name
            ORDER BY total_qty DESC
            LIMIT 10
        ");
        $stmt->execute([$date, $this->lineAccountId]);
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // รายการขายทั้งหมด
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   u.display_name as customer_name,
                   c.display_name as cashier_name
            FROM pos_transactions t
            LEFT JOIN users u ON t.customer_id = u.id
            LEFT JOIN admin_users c ON t.cashier_id = c.id
            WHERE DATE(t.created_at) = ? AND t.line_account_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$date, $this->lineAccountId]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'date' => $date,
            'summary' => [
                'total_transactions' => (int)$summary['total_transactions'],
                'total_sales' => (float)$summary['total_sales'],
                'total_vat' => (float)$summary['total_vat'],
                'total_discount' => (float)$summary['total_discount'],
                'voided_count' => (int)$summary['voided_count'],
                'voided_amount' => (float)$summary['voided_amount'],
                'return_count' => (int)$returns['return_count'],
                'total_refunds' => (float)$returns['total_refunds'],
                'net_sales' => (float)$summary['total_sales'] - (float)$returns['total_refunds']
            ],
            'payment_breakdown' => $paymentBreakdown,
            'top_products' => $topProducts,
            'transactions' => $transactions
        ];
    }
    
    /**
     * สรุปยอดขายรายเดือน
     */
    public function getMonthlySummary(string $month = null): array {
        $month = $month ?? date('Y-m');
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        // ยอดขายรวมรายเดือน
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_transactions,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as total_sales,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN vat_amount ELSE 0 END), 0) as total_vat,
                COUNT(CASE WHEN status = 'voided' THEN 1 END) as voided_count,
                COALESCE(SUM(CASE WHEN status = 'voided' THEN total_amount ELSE 0 END), 0) as voided_amount
            FROM pos_transactions 
            WHERE DATE(created_at) BETWEEN ? AND ? AND line_account_id = ?
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // แยกตามวิธีชำระเงิน
        $stmt = $this->db->prepare("
            SELECT 
                p.payment_method,
                COUNT(*) as count,
                COALESCE(SUM(p.amount), 0) as total
            FROM pos_payments p
            JOIN pos_transactions t ON p.transaction_id = t.id
            WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.line_account_id = ? AND t.status = 'completed'
            GROUP BY p.payment_method
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId]);
        $paymentBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ยอดขายรายวัน
        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as transactions,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as sales
            FROM pos_transactions 
            WHERE DATE(created_at) BETWEEN ? AND ? AND line_account_id = ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId]);
        $dailyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // สินค้าขายดีประจำเดือน
        $stmt = $this->db->prepare("
            SELECT 
                ti.product_id,
                ti.product_name,
                SUM(ti.quantity) as total_qty,
                SUM(ti.line_total) as total_sales
            FROM pos_transaction_items ti
            JOIN pos_transactions t ON ti.transaction_id = t.id
            WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.line_account_id = ? AND t.status = 'completed'
            GROUP BY ti.product_id, ti.product_name
            ORDER BY total_qty DESC
            LIMIT 20
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId]);
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // คืนสินค้า
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as return_count,
                COALESCE(SUM(refund_amount), 0) as total_refunds
            FROM pos_returns 
            WHERE DATE(created_at) BETWEEN ? AND ? AND line_account_id = ? AND status = 'completed'
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId]);
        $returns = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'month' => $month,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_transactions' => (int)$summary['total_transactions'],
                'total_sales' => (float)$summary['total_sales'],
                'total_vat' => (float)$summary['total_vat'],
                'voided_count' => (int)$summary['voided_count'],
                'voided_amount' => (float)$summary['voided_amount'],
                'return_count' => (int)$returns['return_count'],
                'total_refunds' => (float)$returns['total_refunds'],
                'net_sales' => (float)$summary['total_sales'] - (float)$returns['total_refunds']
            ],
            'payment_breakdown' => $paymentBreakdown,
            'daily_trend' => $dailyTrend,
            'top_products' => $topProducts
        ];
    }

    /**
     * รายงานกะทั้งหมด
     */
    public function getShiftReports(string $date = null): array {
        $date = $date ?? date('Y-m-d');
        
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   c.display_name as cashier_name,
                   (SELECT COUNT(*) FROM pos_transactions WHERE shift_id = s.id AND status = 'completed') as transaction_count,
                   (SELECT COALESCE(SUM(total_amount), 0) FROM pos_transactions WHERE shift_id = s.id AND status = 'completed') as total_sales
            FROM pos_shifts s
            LEFT JOIN admin_users c ON s.cashier_id = c.id
            WHERE DATE(s.opened_at) = ? AND s.line_account_id = ?
            ORDER BY s.opened_at DESC
        ");
        $stmt->execute([$date, $this->lineAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * รายละเอียดกะ
     */
    public function getShiftDetail(int $shiftId): ?array {
        $stmt = $this->db->prepare("
            SELECT s.*, c.display_name as cashier_name
            FROM pos_shifts s
            LEFT JOIN admin_users c ON s.cashier_id = c.id
            WHERE s.id = ? AND s.line_account_id = ?
        ");
        $stmt->execute([$shiftId, $this->lineAccountId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shift) return null;
        
        // รายการขายในกะ
        $stmt = $this->db->prepare("
            SELECT t.*, u.display_name as customer_name
            FROM pos_transactions t
            LEFT JOIN users u ON t.customer_id = u.id
            WHERE t.shift_id = ? AND t.line_account_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$shiftId, $this->lineAccountId]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // แยกตามวิธีชำระเงิน
        $stmt = $this->db->prepare("
            SELECT 
                p.payment_method,
                COUNT(*) as count,
                COALESCE(SUM(p.amount), 0) as total
            FROM pos_payments p
            JOIN pos_transactions t ON p.transaction_id = t.id
            WHERE t.shift_id = ? AND t.status = 'completed'
            GROUP BY p.payment_method
        ");
        $stmt->execute([$shiftId]);
        $paymentBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'shift' => $shift,
            'transactions' => $transactions,
            'payment_breakdown' => $paymentBreakdown
        ];
    }
    
    /**
     * สรุปยอดขายสำหรับหน้าบัญชี
     */
    public function getSalesForAccounting(string $startDate, string $endDate): array {
        // ยอดขายรวม
        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as transactions,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as total_sales,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN vat_amount ELSE 0 END), 0) as total_vat
            FROM pos_transactions 
            WHERE DATE(created_at) BETWEEN ? AND ? AND line_account_id = ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId]);
        $dailySales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // แยกตามวิธีชำระเงิน
        $stmt = $this->db->prepare("
            SELECT 
                p.payment_method,
                COALESCE(SUM(p.amount), 0) as total
            FROM pos_payments p
            JOIN pos_transactions t ON p.transaction_id = t.id
            WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.line_account_id = ? AND t.status = 'completed'
            GROUP BY p.payment_method
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId]);
        $paymentTotals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // รวมยอด
        $totalSales = array_sum(array_column($dailySales, 'total_sales'));
        $totalVat = array_sum(array_column($dailySales, 'total_vat'));
        $totalTransactions = array_sum(array_column($dailySales, 'transactions'));
        
        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total_sales' => $totalSales,
            'total_vat' => $totalVat,
            'total_transactions' => $totalTransactions,
            'cash_sales' => $paymentTotals['cash'] ?? 0,
            'transfer_sales' => $paymentTotals['transfer'] ?? 0,
            'card_sales' => $paymentTotals['card'] ?? 0,
            'points_sales' => $paymentTotals['points'] ?? 0,
            'daily_breakdown' => $dailySales
        ];
    }
    
    /**
     * รายงานสินค้าขายดี
     */
    public function getTopSellingProducts(string $startDate, string $endDate, int $limit = 20): array {
        $stmt = $this->db->prepare("
            SELECT 
                ti.product_id,
                ti.product_name,
                ti.product_sku,
                SUM(ti.quantity) as total_qty,
                SUM(ti.line_total) as total_sales,
                COUNT(DISTINCT ti.transaction_id) as transaction_count
            FROM pos_transaction_items ti
            JOIN pos_transactions t ON ti.transaction_id = t.id
            WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.line_account_id = ? AND t.status = 'completed'
            GROUP BY ti.product_id, ti.product_name, ti.product_sku
            ORDER BY total_qty DESC
            LIMIT ?
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * รายงานพนักงานขาย
     */
    public function getCashierPerformance(string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT 
                t.cashier_id,
                c.display_name as cashier_name,
                COUNT(*) as total_transactions,
                COALESCE(SUM(CASE WHEN t.status = 'completed' THEN t.total_amount ELSE 0 END), 0) as total_sales,
                COUNT(CASE WHEN t.status = 'voided' THEN 1 END) as voided_count
            FROM pos_transactions t
            LEFT JOIN admin_users c ON t.cashier_id = c.id
            WHERE DATE(t.created_at) BETWEEN ? AND ? AND t.line_account_id = ?
            GROUP BY t.cashier_id, c.display_name
            ORDER BY total_sales DESC
        ");
        $stmt->execute([$startDate, $endDate, $this->lineAccountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
