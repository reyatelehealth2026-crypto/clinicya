<?php
/**
 * POSShiftService - จัดการกะการทำงาน POS
 * 
 * Manages cashier shifts including:
 * - Opening and closing shifts
 * - Cash tracking and variance calculation
 * - Shift summary reports
 * 
 * Requirements: 7.1-7.5
 */

class POSShiftService {
    private $db;
    private $lineAccountId;
    
    /**
     * Constructor
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
    }
    
    /**
     * Open a new shift
     * Requirements: 7.1
     * 
     * @param int $cashierId Cashier user ID
     * @param float $openingCash Opening cash amount
     * @return array Created shift data
     */
    public function openShift(int $cashierId, float $openingCash): array {
        // Check if cashier already has an open shift
        $existingShift = $this->getCurrentShift($cashierId);
        if ($existingShift) {
            throw new Exception('คุณมีกะที่เปิดอยู่แล้ว กรุณาปิดกะก่อน', 400);
        }
        
        // Generate shift number
        $shiftNumber = $this->generateShiftNumber();
        
        $stmt = $this->db->prepare("
            INSERT INTO pos_shifts 
            (line_account_id, cashier_id, shift_number, opening_cash, status, opened_at)
            VALUES (?, ?, ?, ?, 'open', NOW())
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $cashierId,
            $shiftNumber,
            $openingCash
        ]);
        
        $shiftId = (int)$this->db->lastInsertId();
        
        return $this->getShift($shiftId);
    }
    
    /**
     * Close a shift
     * Requirements: 7.2, 7.3, 7.4
     * 
     * @param int $shiftId Shift ID
     * @param float $closingCash Actual closing cash count
     * @return array Closed shift with summary
     */
    public function closeShift(int $shiftId, float $closingCash): array {
        $shift = $this->getShift($shiftId);
        if (!$shift) {
            throw new Exception('ไม่พบกะ', 404);
        }
        
        if ($shift['status'] !== 'open') {
            throw new Exception('กะนี้ปิดไปแล้ว', 400);
        }
        
        // Calculate expected cash
        $variance = $this->calculateVariance($shiftId, $closingCash);
        
        // Update shift
        $stmt = $this->db->prepare("
            UPDATE pos_shifts 
            SET status = 'closed',
                closing_cash = ?,
                expected_cash = ?,
                variance = ?,
                closed_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $closingCash,
            $variance['expected_cash'],
            $variance['variance'],
            $shiftId
        ]);
        
        return $this->getShiftSummary($shiftId);
    }
    
    /**
     * Get current open shift for cashier
     * Requirements: 7.5
     * 
     * @param int $cashierId Cashier user ID
     * @return array|null Current shift or null
     */
    public function getCurrentShift(int $cashierId): ?array {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   a.display_name as cashier_name
            FROM pos_shifts s
            LEFT JOIN admin_users a ON s.cashier_id = a.id
            WHERE s.cashier_id = ? 
            AND s.status = 'open' 
            AND s.line_account_id = ?
            ORDER BY s.opened_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$cashierId, $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get shift by ID
     */
    public function getShift(int $shiftId): ?array {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   a.display_name as cashier_name
            FROM pos_shifts s
            LEFT JOIN admin_users a ON s.cashier_id = a.id
            WHERE s.id = ? AND s.line_account_id = ?
        ");
        $stmt->execute([$shiftId, $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get shift summary with detailed breakdown
     * Requirements: 7.4
     * 
     * @param int $shiftId Shift ID
     * @return array Shift summary
     */
    public function getShiftSummary(int $shiftId): array {
        $shift = $this->getShift($shiftId);
        if (!$shift) {
            throw new Exception('ไม่พบกะ', 404);
        }
        
        // Get transaction summary
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as transaction_count,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as total_sales,
                COALESCE(SUM(CASE WHEN status = 'voided' THEN total_amount ELSE 0 END), 0) as voided_amount,
                COUNT(CASE WHEN status = 'voided' THEN 1 END) as voided_count
            FROM pos_transactions 
            WHERE shift_id = ?
        ");
        $stmt->execute([$shiftId]);
        $transactionSummary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get payment breakdown
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
        
        // Get returns summary
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as return_count,
                COALESCE(SUM(refund_amount), 0) as total_refunds
            FROM pos_returns 
            WHERE shift_id = ? AND status = 'completed'
        ");
        $stmt->execute([$shiftId]);
        $returnSummary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get cash movements (in/out)
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN movement_type = 'in' THEN amount ELSE 0 END), 0) as cash_in,
                COALESCE(SUM(CASE WHEN movement_type = 'out' THEN amount ELSE 0 END), 0) as cash_out
            FROM pos_cash_movements 
            WHERE shift_id = ?
        ");
        $stmt->execute([$shiftId]);
        $cashMovements = $stmt->fetch(PDO::FETCH_ASSOC);
        $cashAdjustments = ($cashMovements['cash_in'] ?? 0) - ($cashMovements['cash_out'] ?? 0);
        
        // Get top products
        $stmt = $this->db->prepare("
            SELECT 
                ti.product_name,
                SUM(ti.quantity) as total_qty,
                SUM(ti.line_total) as total_sales
            FROM pos_transaction_items ti
            JOIN pos_transactions t ON ti.transaction_id = t.id
            WHERE t.shift_id = ? AND t.status = 'completed'
            GROUP BY ti.product_id, ti.product_name
            ORDER BY total_qty DESC
            LIMIT 10
        ");
        $stmt->execute([$shiftId]);
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate cash summary
        $cashPayments = 0;
        $cashRefunds = (float)$returnSummary['total_refunds'];
        
        foreach ($paymentBreakdown as $payment) {
            if ($payment['payment_method'] === 'cash') {
                $cashPayments = (float)$payment['total'];
            }
        }
        
        $expectedCash = $shift['opening_cash'] + $cashPayments - $cashRefunds + $cashAdjustments;
        
        return [
            'shift' => $shift,
            'summary' => [
                'transaction_count' => (int)$transactionSummary['transaction_count'],
                'total_sales' => (float)$transactionSummary['total_sales'],
                'voided_count' => (int)$transactionSummary['voided_count'],
                'voided_amount' => (float)$transactionSummary['voided_amount'],
                'return_count' => (int)$returnSummary['return_count'],
                'total_refunds' => (float)$returnSummary['total_refunds'],
                'net_sales' => (float)$transactionSummary['total_sales'] - (float)$returnSummary['total_refunds']
            ],
            'returns_summary' => [
                'count' => (int)$returnSummary['return_count'],
                'total' => (float)$returnSummary['total_refunds']
            ],
            'payment_breakdown' => $paymentBreakdown,
            'cash_summary' => [
                'opening_cash' => (float)$shift['opening_cash'],
                'cash_sales' => $cashPayments,
                'cash_refunds' => $cashRefunds,
                'cash_adjustments' => $cashAdjustments,
                'expected_cash' => $expectedCash,
                'closing_cash' => $shift['closing_cash'] ? (float)$shift['closing_cash'] : null,
                'variance' => $shift['variance'] ? (float)$shift['variance'] : null
            ],
            'top_products' => $topProducts
        ];
    }
    
    /**
     * Calculate cash variance
     * Requirements: 7.3
     * 
     * @param int $shiftId Shift ID
     * @param float $actualCash Actual cash count
     * @return array Variance calculation
     */
    public function calculateVariance(int $shiftId, float $actualCash): array {
        $shift = $this->getShift($shiftId);
        if (!$shift) {
            throw new Exception('ไม่พบกะ', 404);
        }
        
        // Get cash payments
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(p.amount), 0) as cash_sales
            FROM pos_payments p
            JOIN pos_transactions t ON p.transaction_id = t.id
            WHERE t.shift_id = ? 
            AND t.status = 'completed'
            AND p.payment_method = 'cash'
        ");
        $stmt->execute([$shiftId]);
        $cashSales = (float)$stmt->fetchColumn();
        
        // Get cash refunds
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(refund_amount), 0) as cash_refunds
            FROM pos_returns 
            WHERE shift_id = ? 
            AND status = 'completed'
            AND refund_method = 'cash'
        ");
        $stmt->execute([$shiftId]);
        $cashRefunds = (float)$stmt->fetchColumn();
        
        // Get cash movements (in/out)
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN movement_type = 'in' THEN amount ELSE 0 END), 0) as cash_in,
                COALESCE(SUM(CASE WHEN movement_type = 'out' THEN amount ELSE 0 END), 0) as cash_out
            FROM pos_cash_movements 
            WHERE shift_id = ?
        ");
        $stmt->execute([$shiftId]);
        $cashMovements = $stmt->fetch(PDO::FETCH_ASSOC);
        $cashAdjustments = ($cashMovements['cash_in'] ?? 0) - ($cashMovements['cash_out'] ?? 0);
        
        // Calculate expected cash
        $expectedCash = $shift['opening_cash'] + $cashSales - $cashRefunds + $cashAdjustments;
        
        // Calculate variance
        $variance = $actualCash - $expectedCash;
        
        return [
            'opening_cash' => (float)$shift['opening_cash'],
            'cash_sales' => $cashSales,
            'cash_refunds' => $cashRefunds,
            'cash_adjustments' => $cashAdjustments,
            'expected_cash' => $expectedCash,
            'actual_cash' => $actualCash,
            'variance' => $variance,
            'variance_status' => $this->getVarianceStatus($variance)
        ];
    }
    
    /**
     * Get variance status label
     */
    private function getVarianceStatus(float $variance): string {
        if (abs($variance) < 0.01) {
            return 'balanced';
        } elseif ($variance > 0) {
            return 'over';
        } else {
            return 'short';
        }
    }
    
    /**
     * Generate shift number
     */
    private function generateShiftNumber(): string {
        $date = date('Ymd');
        $prefix = "SFT-{$date}-";
        
        $stmt = $this->db->prepare("
            SELECT shift_number FROM pos_shifts 
            WHERE shift_number LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(["{$prefix}%"]);
        $last = $stmt->fetchColumn();
        
        if ($last) {
            $seq = (int)substr($last, -3) + 1;
        } else {
            $seq = 1;
        }
        
        return sprintf("%s%03d", $prefix, $seq);
    }
    
    /**
     * Get shifts list
     * 
     * @param array $filters Filters (date, cashier_id, status)
     * @return array Shifts
     */
    public function getShifts(array $filters = []): array {
        $sql = "
            SELECT s.*, 
                   a.display_name as cashier_name
            FROM pos_shifts s
            LEFT JOIN admin_users a ON s.cashier_id = a.id
            WHERE s.line_account_id = ?
        ";
        $params = [$this->lineAccountId];
        
        if (isset($filters['date'])) {
            $sql .= " AND DATE(s.opened_at) = ?";
            $params[] = $filters['date'];
        }
        
        if (isset($filters['cashier_id'])) {
            $sql .= " AND s.cashier_id = ?";
            $params[] = $filters['cashier_id'];
        }
        
        if (isset($filters['status'])) {
            $sql .= " AND s.status = ?";
            $params[] = $filters['status'];
        }
        
        $sql .= " ORDER BY s.opened_at DESC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        } else {
            $sql .= " LIMIT 50";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if cashier has open shift
     * Requirements: 7.5
     * 
     * @param int $cashierId Cashier ID
     * @return bool Has open shift
     */
    public function hasOpenShift(int $cashierId): bool {
        return $this->getCurrentShift($cashierId) !== null;
    }
    
    /**
     * Get any open shift (for admin view)
     */
    public function getAnyOpenShift(): ?array {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   a.display_name as cashier_name
            FROM pos_shifts s
            LEFT JOIN admin_users a ON s.cashier_id = a.id
            WHERE s.status = 'open' AND s.line_account_id = ?
            ORDER BY s.opened_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
