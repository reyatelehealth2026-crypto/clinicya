<?php
/**
 * PaymentVoucherService - จัดการใบสำคัญจ่าย
 * 
 * Requirements: 4.1, 4.2, 4.3, 4.4
 */

class PaymentVoucherService {
    private $db;
    private $lineAccountId;
    
    /**
     * Valid payment methods
     */
    private static $validPaymentMethods = ['cash', 'transfer', 'cheque', 'credit_card'];
    
    /**
     * Valid voucher types
     */
    private static $validVoucherTypes = ['ap', 'expense'];
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Generate unique voucher number
     * Format: PV-YYYYMMDD-XXXX
     * 
     * @return string Generated voucher number
     */
    public function generateVoucherNumber(): string {
        $date = date('Ymd');
        $prefix = "PV-{$date}-";
        
        // Get the last voucher number for today
        $stmt = $this->db->prepare("
            SELECT voucher_number FROM payment_vouchers 
            WHERE voucher_number LIKE ? 
            ORDER BY voucher_number DESC 
            LIMIT 1
        ");
        $stmt->execute([$prefix . '%']);
        $lastNumber = $stmt->fetchColumn();
        
        if ($lastNumber) {
            // Extract the sequence number and increment
            $sequence = (int)substr($lastNumber, -4);
            $sequence++;
        } else {
            $sequence = 1;
        }
        
        return $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Create new payment voucher
     * 
     * @param array $data Voucher data
     * @return int Created voucher ID
     * @throws Exception If validation fails
     */
    public function create(array $data): int {
        // Validate required fields
        if (empty($data['voucher_type'])) {
            throw new Exception('Voucher type is required');
        }
        if (!in_array($data['voucher_type'], self::$validVoucherTypes)) {
            throw new Exception('Invalid voucher type. Must be: ' . implode(', ', self::$validVoucherTypes));
        }
        
        if (empty($data['reference_id'])) {
            throw new Exception('Reference ID is required');
        }
        
        if (empty($data['payment_date'])) {
            throw new Exception('Payment date is required');
        }
        
        if (!isset($data['amount']) || $data['amount'] <= 0) {
            throw new Exception('Valid amount is required');
        }
        
        if (empty($data['payment_method'])) {
            throw new Exception('Payment method is required');
        }
        if (!in_array($data['payment_method'], self::$validPaymentMethods)) {
            throw new Exception('Invalid payment method. Must be: ' . implode(', ', self::$validPaymentMethods));
        }
        
        // Validate cheque fields if payment method is cheque
        if ($data['payment_method'] === 'cheque') {
            if (empty($data['cheque_number'])) {
                throw new Exception('Cheque number is required for cheque payments');
            }
        }

        // Generate voucher number
        $voucherNumber = $this->generateVoucherNumber();
        
        // Prepare metadata
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO payment_vouchers 
            (line_account_id, voucher_number, voucher_type, reference_id, payment_date, 
             amount, payment_method, bank_account, reference_number, cheque_number, 
             cheque_date, attachment_path, notes, metadata, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $voucherNumber,
            $data['voucher_type'],
            $data['reference_id'],
            $data['payment_date'],
            $data['amount'],
            $data['payment_method'],
            $data['bank_account'] ?? null,
            $data['reference_number'] ?? null,
            $data['cheque_number'] ?? null,
            $data['cheque_date'] ?? null,
            $data['attachment_path'] ?? null,
            $data['notes'] ?? null,
            $metadata,
            $data['created_by'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get voucher by ID
     * 
     * @param int $id Voucher ID
     * @return array|null Voucher data or null if not found
     */
    public function getById(int $id): ?array {
        $sql = "SELECT * FROM payment_vouchers WHERE id = ?";
        $params = [$id];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['metadata']) {
            $result['metadata'] = json_decode($result['metadata'], true);
        }
        
        return $result ?: null;
    }
    
    /**
     * Get voucher by voucher number
     * 
     * @param string $voucherNumber Voucher number
     * @return array|null Voucher data or null if not found
     */
    public function getByVoucherNumber(string $voucherNumber): ?array {
        $sql = "SELECT * FROM payment_vouchers WHERE voucher_number = ?";
        $params = [$voucherNumber];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['metadata']) {
            $result['metadata'] = json_decode($result['metadata'], true);
        }
        
        return $result ?: null;
    }

    /**
     * Get payment history with filters
     * 
     * @param array $filters Optional filters (voucher_type, reference_id, date_from, date_to, payment_method, search)
     * @return array List of vouchers
     */
    public function getHistory(array $filters = []): array {
        $sql = "SELECT * FROM payment_vouchers WHERE 1=1";
        $params = [];
        
        // Filter by line_account_id
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        // Filter by voucher type
        if (!empty($filters['voucher_type'])) {
            $sql .= " AND voucher_type = ?";
            $params[] = $filters['voucher_type'];
        }
        
        // Filter by reference ID
        if (!empty($filters['reference_id'])) {
            $sql .= " AND reference_id = ?";
            $params[] = $filters['reference_id'];
        }
        
        // Filter by date range
        if (!empty($filters['date_from'])) {
            $sql .= " AND payment_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND payment_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        // Filter by payment method
        if (!empty($filters['payment_method'])) {
            $sql .= " AND payment_method = ?";
            $params[] = $filters['payment_method'];
        }
        
        // Search by voucher_number, reference_number, or notes
        if (!empty($filters['search'])) {
            $sql .= " AND (voucher_number LIKE ? OR reference_number LIKE ? OR notes LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        // Sorting
        $sortField = $filters['sort_by'] ?? 'payment_date';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC');
        $allowedSortFields = ['payment_date', 'amount', 'created_at', 'voucher_number'];
        $allowedSortOrders = ['ASC', 'DESC'];
        
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'payment_date';
        }
        if (!in_array($sortOrder, $allowedSortOrders)) {
            $sortOrder = 'DESC';
        }
        
        $sql .= " ORDER BY {$sortField} {$sortOrder}";
        
        // Pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            
            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode metadata for each result
        foreach ($results as &$result) {
            if ($result['metadata']) {
                $result['metadata'] = json_decode($result['metadata'], true);
            }
        }
        
        return $results;
    }

    /**
     * Get payments for a specific AP record
     * 
     * @param int $apId Account Payable ID
     * @return array List of payment vouchers for the AP
     */
    public function getByApId(int $apId): array {
        return $this->getHistory([
            'voucher_type' => 'ap',
            'reference_id' => $apId
        ]);
    }
    
    /**
     * Get payments for a specific expense record
     * 
     * @param int $expenseId Expense ID
     * @return array List of payment vouchers for the expense
     */
    public function getByExpenseId(int $expenseId): array {
        return $this->getHistory([
            'voucher_type' => 'expense',
            'reference_id' => $expenseId
        ]);
    }
    
    /**
     * Get total payments for a reference
     * 
     * @param string $voucherType Voucher type (ap or expense)
     * @param int $referenceId Reference ID
     * @return float Total amount paid
     */
    public function getTotalPayments(string $voucherType, int $referenceId): float {
        $sql = "
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM payment_vouchers 
            WHERE voucher_type = ? AND reference_id = ?
        ";
        $params = [$voucherType, $referenceId];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }
    
    /**
     * Get payment summary by method
     * 
     * @param array $filters Optional filters (date_from, date_to, voucher_type)
     * @return array Summary grouped by payment method
     */
    public function getSummaryByMethod(array $filters = []): array {
        $sql = "
            SELECT 
                payment_method,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount
            FROM payment_vouchers
            WHERE 1=1
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['voucher_type'])) {
            $sql .= " AND voucher_type = ?";
            $params[] = $filters['voucher_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND payment_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND payment_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " GROUP BY payment_method ORDER BY total_amount DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get monthly payment summary
     * 
     * @param string $month Month in YYYY-MM format
     * @return array Summary with totals by type and method
     */
    public function getMonthlySummary(string $month): array {
        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new Exception('Invalid month format. Use YYYY-MM');
        }
        
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        // Get totals by voucher type
        $sql = "
            SELECT 
                voucher_type,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount
            FROM payment_vouchers
            WHERE payment_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " GROUP BY voucher_type";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $byType = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get totals by payment method
        $byMethod = $this->getSummaryByMethod([
            'date_from' => $startDate,
            'date_to' => $endDate
        ]);
        
        // Calculate overall totals
        $totalAmount = 0;
        $totalCount = 0;
        foreach ($byType as $type) {
            $totalAmount += (float)$type['total_amount'];
            $totalCount += (int)$type['count'];
        }
        
        return [
            'month' => $month,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_amount' => $totalAmount,
                'voucher_count' => $totalCount
            ],
            'by_type' => $byType,
            'by_method' => $byMethod
        ];
    }
    
    /**
     * Get valid payment methods
     * 
     * @return array List of valid payment methods
     */
    public static function getValidPaymentMethods(): array {
        return self::$validPaymentMethods;
    }
    
    /**
     * Get valid voucher types
     * 
     * @return array List of valid voucher types
     */
    public static function getValidVoucherTypes(): array {
        return self::$validVoucherTypes;
    }
    
    /**
     * Check if voucher number exists
     * 
     * @param string $voucherNumber Voucher number to check
     * @return bool True if exists
     */
    public function voucherNumberExists(string $voucherNumber): bool {
        $stmt = $this->db->prepare("SELECT id FROM payment_vouchers WHERE voucher_number = ?");
        $stmt->execute([$voucherNumber]);
        return (bool)$stmt->fetch();
    }
}
