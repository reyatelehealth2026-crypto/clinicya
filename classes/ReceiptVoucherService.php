<?php
/**
 * ReceiptVoucherService - จัดการใบสำคัญรับ
 * 
 * Requirements: 4.1, 4.2, 4.3, 4.4
 */

class ReceiptVoucherService {
    private $db;
    private $lineAccountId;
    
    /**
     * Valid payment methods
     */
    private static $validPaymentMethods = ['cash', 'transfer', 'cheque', 'credit_card'];
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Generate unique voucher number
     * Format: RV-YYYYMMDD-XXXX
     * 
     * @return string Generated voucher number
     */
    public function generateVoucherNumber(): string {
        $date = date('Ymd');
        $prefix = "RV-{$date}-";
        
        // Get the last voucher number for today
        $stmt = $this->db->prepare("
            SELECT voucher_number FROM receipt_vouchers 
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
     * Create new receipt voucher
     * 
     * @param array $data Voucher data
     * @return int Created voucher ID
     * @throws Exception If validation fails
     */
    public function create(array $data): int {
        // Validate required fields
        if (empty($data['ar_id'])) {
            throw new Exception('AR ID is required');
        }
        
        if (empty($data['receipt_date'])) {
            throw new Exception('Receipt date is required');
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

        // Generate voucher number
        $voucherNumber = $this->generateVoucherNumber();
        
        // Prepare metadata
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO receipt_vouchers 
            (line_account_id, voucher_number, ar_id, receipt_date, amount, 
             payment_method, bank_account, reference_number, slip_id, 
             attachment_path, notes, metadata, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $voucherNumber,
            $data['ar_id'],
            $data['receipt_date'],
            $data['amount'],
            $data['payment_method'],
            $data['bank_account'] ?? null,
            $data['reference_number'] ?? null,
            $data['slip_id'] ?? null,
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
        $sql = "SELECT * FROM receipt_vouchers WHERE id = ?";
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
        $sql = "SELECT * FROM receipt_vouchers WHERE voucher_number = ?";
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
     * Get receipt history with filters
     * 
     * @param array $filters Optional filters (ar_id, date_from, date_to, payment_method, search)
     * @return array List of vouchers
     */
    public function getHistory(array $filters = []): array {
        $sql = "SELECT * FROM receipt_vouchers WHERE 1=1";
        $params = [];
        
        // Filter by line_account_id
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        // Filter by AR ID
        if (!empty($filters['ar_id'])) {
            $sql .= " AND ar_id = ?";
            $params[] = $filters['ar_id'];
        }
        
        // Filter by date range
        if (!empty($filters['date_from'])) {
            $sql .= " AND receipt_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND receipt_date <= ?";
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
        $sortField = $filters['sort_by'] ?? 'receipt_date';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC');
        $allowedSortFields = ['receipt_date', 'amount', 'created_at', 'voucher_number'];
        $allowedSortOrders = ['ASC', 'DESC'];
        
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'receipt_date';
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
     * Get receipts for a specific AR record
     * 
     * @param int $arId Account Receivable ID
     * @return array List of receipt vouchers for the AR
     */
    public function getByArId(int $arId): array {
        return $this->getHistory([
            'ar_id' => $arId
        ]);
    }
    
    /**
     * Get total receipts for an AR
     * 
     * @param int $arId Account Receivable ID
     * @return float Total amount received
     */
    public function getTotalReceipts(int $arId): float {
        $sql = "
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM receipt_vouchers 
            WHERE ar_id = ?
        ";
        $params = [$arId];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }

    
    /**
     * Get receipt summary by method
     * 
     * @param array $filters Optional filters (date_from, date_to)
     * @return array Summary grouped by payment method
     */
    public function getSummaryByMethod(array $filters = []): array {
        $sql = "
            SELECT 
                payment_method,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount
            FROM receipt_vouchers
            WHERE 1=1
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND receipt_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND receipt_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " GROUP BY payment_method ORDER BY total_amount DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get monthly receipt summary
     * 
     * @param string $month Month in YYYY-MM format
     * @return array Summary with totals by method
     */
    public function getMonthlySummary(string $month): array {
        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new Exception('Invalid month format. Use YYYY-MM');
        }
        
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        // Get totals
        $sql = "
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount
            FROM receipt_vouchers
            WHERE receipt_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get totals by payment method
        $byMethod = $this->getSummaryByMethod([
            'date_from' => $startDate,
            'date_to' => $endDate
        ]);
        
        return [
            'month' => $month,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_amount' => (float)$totals['total_amount'],
                'voucher_count' => (int)$totals['count']
            ],
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
     * Check if voucher number exists
     * 
     * @param string $voucherNumber Voucher number to check
     * @return bool True if exists
     */
    public function voucherNumberExists(string $voucherNumber): bool {
        $stmt = $this->db->prepare("SELECT id FROM receipt_vouchers WHERE voucher_number = ?");
        $stmt->execute([$voucherNumber]);
        return (bool)$stmt->fetch();
    }
}
