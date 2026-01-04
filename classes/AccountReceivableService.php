<?php
/**
 * AccountReceivableService - จัดการลูกหนี้การค้า (Account Receivable)
 * 
 * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 5.2
 */

require_once __DIR__ . '/ReceiptVoucherService.php';
require_once __DIR__ . '/AgingHelper.php';

class AccountReceivableService {
    private $db;
    private $lineAccountId;
    private $receiptVoucherService;
    
    /**
     * Valid AR statuses
     */
    private static $validStatuses = ['open', 'partial', 'paid', 'cancelled'];
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->receiptVoucherService = new ReceiptVoucherService($db, $lineAccountId);
    }
    
    /**
     * Generate unique AR number
     * Format: AR-YYYYMMDD-XXXX
     * 
     * @return string Generated AR number
     */
    public function generateArNumber(): string {
        $date = date('Ymd');
        $prefix = "AR-{$date}-";
        
        // Get the last AR number for today
        $stmt = $this->db->prepare("
            SELECT ar_number FROM account_receivables 
            WHERE ar_number LIKE ? 
            ORDER BY ar_number DESC 
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
     * Create AR from Transaction (Order)
     * Requirement 2.1: Auto-create AR when credit sale is made
     * 
     * @param int $transactionId Transaction ID
     * @param int|null $creditDays Credit terms in days (default 30)
     * @return int Created AR ID
     * @throws Exception If transaction not found or invalid
     */
    public function createFromTransaction(int $transactionId, ?int $creditDays = 30): int {
        // Get transaction details with user info
        $stmt = $this->db->prepare("
            SELECT t.*, u.display_name as customer_name, u.phone as customer_phone
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            throw new Exception('Transaction not found');
        }
        
        // Check if payment_status is not 'paid' (credit sale)
        if ($transaction['payment_status'] === 'paid') {
            throw new Exception('Cannot create AR for paid transaction');
        }
        
        // Check if AR already exists for this transaction
        $stmt = $this->db->prepare("SELECT id FROM account_receivables WHERE transaction_id = ?");
        $stmt->execute([$transactionId]);
        if ($stmt->fetch()) {
            throw new Exception('AR already exists for this transaction');
        }
        
        // Calculate due date based on credit terms
        $invoiceDate = $transaction['created_at'] ? date('Y-m-d', strtotime($transaction['created_at'])) : date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime($invoiceDate . " + {$creditDays} days"));
        
        // Generate AR number
        $arNumber = $this->generateArNumber();
        
        // Create AR record
        $stmt = $this->db->prepare("
            INSERT INTO account_receivables 
            (line_account_id, ar_number, user_id, transaction_id, invoice_number, invoice_date, 
             due_date, total_amount, received_amount, balance, status, notes, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'open', ?, ?)
        ");
        
        $metadata = json_encode([
            'order_number' => $transaction['order_number'],
            'customer_name' => $transaction['customer_name'],
            'customer_phone' => $transaction['customer_phone'],
            'credit_terms' => $creditDays
        ]);
        
        $notes = "Auto-created from Order: {$transaction['order_number']}";
        
        $stmt->execute([
            $this->lineAccountId,
            $arNumber,
            $transaction['user_id'],
            $transactionId,
            $transaction['order_number'], // Use order_number as invoice_number
            $invoiceDate,
            $dueDate,
            $transaction['grand_total'],
            $transaction['grand_total'], // balance = total_amount initially
            $notes,
            $metadata
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    
    /**
     * Create AR manually (without transaction)
     * 
     * @param array $data AR data
     * @return int Created AR ID
     * @throws Exception If validation fails
     */
    public function create(array $data): int {
        // Validate required fields
        if (empty($data['user_id'])) {
            throw new Exception('User ID is required');
        }
        
        if (!isset($data['total_amount']) || $data['total_amount'] <= 0) {
            throw new Exception('Valid total amount is required');
        }
        
        if (empty($data['due_date'])) {
            throw new Exception('Due date is required');
        }
        
        // Generate AR number
        $arNumber = $this->generateArNumber();
        
        // Prepare metadata
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO account_receivables 
            (line_account_id, ar_number, user_id, transaction_id, invoice_number, 
             invoice_date, due_date, total_amount, received_amount, balance, status, notes, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'open', ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $arNumber,
            $data['user_id'],
            $data['transaction_id'] ?? null,
            $data['invoice_number'] ?? null,
            $data['invoice_date'] ?? date('Y-m-d'),
            $data['due_date'],
            $data['total_amount'],
            $data['total_amount'], // balance = total_amount initially
            $data['notes'] ?? null,
            $metadata
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    
    /**
     * Get all AR records with filters
     * Requirement 2.2: Display all outstanding receivables sorted by due date
     * 
     * @param array $filters Optional filters (status, user_id, date_from, date_to, search)
     * @return array List of AR records
     */
    public function getAll(array $filters = []): array {
        $sql = "
            SELECT ar.*, u.display_name as customer_name, u.phone as customer_phone, u.email as customer_email
            FROM account_receivables ar
            LEFT JOIN users u ON ar.user_id = u.id
            WHERE 1=1
        ";
        $params = [];
        
        // Filter by line_account_id
        if ($this->lineAccountId) {
            $sql .= " AND (ar.line_account_id = ? OR ar.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        // Filter by status
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
                $sql .= " AND ar.status IN ({$placeholders})";
                $params = array_merge($params, $filters['status']);
            } else {
                $sql .= " AND ar.status = ?";
                $params[] = $filters['status'];
            }
        }
        
        // Filter by user (customer)
        if (!empty($filters['user_id'])) {
            $sql .= " AND ar.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        // Filter by date range (due_date)
        if (!empty($filters['date_from'])) {
            $sql .= " AND ar.due_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND ar.due_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        // Filter by invoice date range
        if (!empty($filters['invoice_date_from'])) {
            $sql .= " AND ar.invoice_date >= ?";
            $params[] = $filters['invoice_date_from'];
        }
        if (!empty($filters['invoice_date_to'])) {
            $sql .= " AND ar.invoice_date <= ?";
            $params[] = $filters['invoice_date_to'];
        }
        
        // Search by ar_number, invoice_number, or customer name
        if (!empty($filters['search'])) {
            $sql .= " AND (ar.ar_number LIKE ? OR ar.invoice_number LIKE ? OR u.display_name LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        // Sorting
        $sortField = $filters['sort_by'] ?? 'due_date';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'ASC');
        $allowedSortFields = ['due_date', 'total_amount', 'balance', 'created_at', 'ar_number', 'customer_name'];
        $allowedSortOrders = ['ASC', 'DESC'];
        
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'due_date';
        }
        if (!in_array($sortOrder, $allowedSortOrders)) {
            $sortOrder = 'ASC';
        }
        
        // Handle customer_name sort
        if ($sortField === 'customer_name') {
            $sql .= " ORDER BY u.display_name {$sortOrder}";
        } else {
            $sql .= " ORDER BY ar.{$sortField} {$sortOrder}";
        }
        
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
        
        // Add days until due and decode metadata
        $today = new DateTime();
        foreach ($results as &$result) {
            $dueDate = new DateTime($result['due_date']);
            $diff = $today->diff($dueDate);
            $result['days_until_due'] = $diff->invert ? -$diff->days : $diff->days;
            $result['is_overdue'] = $result['days_until_due'] < 0 && in_array($result['status'], ['open', 'partial']);
            
            if ($result['metadata']) {
                $result['metadata'] = json_decode($result['metadata'], true);
            }
        }
        
        return $results;
    }

    
    /**
     * Get AR by ID with full details
     * 
     * @param int $id AR ID
     * @return array|null AR data or null if not found
     */
    public function getById(int $id): ?array {
        $sql = "
            SELECT ar.*, u.display_name as customer_name, u.phone as customer_phone, 
                   u.email as customer_email, u.line_user_id,
                   t.order_number, t.status as order_status
            FROM account_receivables ar
            LEFT JOIN users u ON ar.user_id = u.id
            LEFT JOIN transactions t ON ar.transaction_id = t.id
            WHERE ar.id = ?
        ";
        $params = [$id];
        
        if ($this->lineAccountId) {
            $sql .= " AND (ar.line_account_id = ? OR ar.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return null;
        }
        
        // Calculate days until due
        $today = new DateTime();
        $dueDate = new DateTime($result['due_date']);
        $diff = $today->diff($dueDate);
        $result['days_until_due'] = $diff->invert ? -$diff->days : $diff->days;
        $result['is_overdue'] = $result['days_until_due'] < 0 && in_array($result['status'], ['open', 'partial']);
        
        // Decode metadata
        if ($result['metadata']) {
            $result['metadata'] = json_decode($result['metadata'], true);
        }
        
        // Get receipt history
        $result['receipts'] = $this->receiptVoucherService->getByArId($id);
        
        return $result;
    }

    
    /**
     * Record receipt for AR
     * Requirements 2.3, 2.4, 2.5: Handle partial/full receipts, create voucher, update status
     * 
     * @param int $arId AR ID
     * @param array $receiptData Receipt details
     * @return int Created receipt voucher ID
     * @throws Exception If validation fails
     */
    public function recordReceipt(int $arId, array $receiptData): int {
        // Get AR record
        $ar = $this->getById($arId);
        if (!$ar) {
            throw new Exception('Account Receivable not found');
        }
        
        // Validate AR status
        if (!in_array($ar['status'], ['open', 'partial'])) {
            throw new Exception('Cannot record receipt for AR with status: ' . $ar['status']);
        }
        
        // Validate receipt amount
        if (!isset($receiptData['amount']) || $receiptData['amount'] <= 0) {
            throw new Exception('Valid receipt amount is required');
        }
        
        $receiptAmount = (float)$receiptData['amount'];
        $currentBalance = (float)$ar['balance'];
        
        if ($receiptAmount > $currentBalance) {
            throw new Exception("Receipt amount ({$receiptAmount}) exceeds balance ({$currentBalance})");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Create receipt voucher
            $voucherData = [
                'ar_id' => $arId,
                'receipt_date' => $receiptData['receipt_date'] ?? date('Y-m-d'),
                'amount' => $receiptAmount,
                'payment_method' => $receiptData['payment_method'],
                'bank_account' => $receiptData['bank_account'] ?? null,
                'reference_number' => $receiptData['reference_number'] ?? null,
                'slip_id' => $receiptData['slip_id'] ?? null,
                'attachment_path' => $receiptData['attachment_path'] ?? null,
                'notes' => $receiptData['notes'] ?? null,
                'metadata' => [
                    'ar_number' => $ar['ar_number'],
                    'customer_name' => $ar['customer_name'],
                    'original_balance' => $currentBalance
                ],
                'created_by' => $receiptData['created_by'] ?? null
            ];
            
            $voucherId = $this->receiptVoucherService->create($voucherData);
            
            // Calculate new balance and status
            $newBalance = $currentBalance - $receiptAmount;
            $newReceivedAmount = (float)$ar['received_amount'] + $receiptAmount;
            
            if ($newBalance <= 0) {
                // Fully paid
                $newStatus = 'paid';
                $closedAt = date('Y-m-d H:i:s');
            } else {
                // Partially paid
                $newStatus = 'partial';
                $closedAt = null;
            }
            
            // Update AR record
            $stmt = $this->db->prepare("
                UPDATE account_receivables 
                SET received_amount = ?, balance = ?, status = ?, closed_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newReceivedAmount, $newBalance, $newStatus, $closedAt, $arId]);
            
            $this->db->commit();
            
            return $voucherId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    
    /**
     * Cancel AR record
     * 
     * @param int $id AR ID
     * @param string $reason Cancellation reason
     * @return bool Success
     * @throws Exception If AR cannot be cancelled
     */
    public function cancel(int $id, string $reason): bool {
        $ar = $this->getById($id);
        if (!$ar) {
            throw new Exception('Account Receivable not found');
        }
        
        if ($ar['status'] === 'paid') {
            throw new Exception('Cannot cancel paid AR');
        }
        
        if ($ar['received_amount'] > 0) {
            throw new Exception('Cannot cancel AR with existing receipts');
        }
        
        $metadata = $ar['metadata'] ?? [];
        $metadata['cancel_reason'] = $reason;
        $metadata['cancelled_at'] = date('Y-m-d H:i:s');
        
        $stmt = $this->db->prepare("
            UPDATE account_receivables 
            SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), '\nCancelled: ', ?), 
                metadata = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$reason, json_encode($metadata), $id]);
    }

    
    /**
     * Get aging report for AR
     * Requirement 5.2: Display receivables grouped by age brackets
     * Uses shared AgingHelper for bracket calculation
     * 
     * @return array Aging report with brackets and totals
     */
    public function getAgingReport(): array {
        // Get all open/partial AR records
        $sql = "
            SELECT ar.*, u.display_name as customer_name
            FROM account_receivables ar
            LEFT JOIN users u ON ar.user_id = u.id
            WHERE ar.status IN ('open', 'partial')
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (ar.line_account_id = ? OR ar.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY ar.due_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Use shared AgingHelper to categorize records
        return AgingHelper::categorizeRecords($records);
    }
    
    /**
     * Get upcoming due AR records
     * 
     * @param int $days Number of days to look ahead (default 7)
     * @return array List of AR records due within specified days
     */
    public function getUpcomingDue(int $days = 7): array {
        $today = date('Y-m-d');
        $futureDate = date('Y-m-d', strtotime("+{$days} days"));
        
        return $this->getAll([
            'status' => ['open', 'partial'],
            'date_from' => $today,
            'date_to' => $futureDate,
            'sort_by' => 'due_date',
            'sort_order' => 'ASC'
        ]);
    }
    
    /**
     * Get overdue AR records
     * 
     * @return array List of overdue AR records
     */
    public function getOverdue(): array {
        $today = date('Y-m-d');
        
        $sql = "
            SELECT ar.*, u.display_name as customer_name, u.phone as customer_phone,
                   DATEDIFF(?, ar.due_date) as days_overdue
            FROM account_receivables ar
            LEFT JOIN users u ON ar.user_id = u.id
            WHERE ar.status IN ('open', 'partial')
            AND ar.due_date < ?
        ";
        $params = [$today, $today];
        
        if ($this->lineAccountId) {
            $sql .= " AND (ar.line_account_id = ? OR ar.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY ar.due_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode metadata
        foreach ($results as &$result) {
            if ($result['metadata']) {
                $result['metadata'] = json_decode($result['metadata'], true);
            }
            $result['is_overdue'] = true;
        }
        
        return $results;
    }


    
    /**
     * Get total outstanding AR balance
     * 
     * @return float Total balance of open/partial AR
     */
    public function getTotalOutstanding(): float {
        $sql = "
            SELECT COALESCE(SUM(balance), 0) as total
            FROM account_receivables
            WHERE status IN ('open', 'partial')
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }
    
    /**
     * Get total overdue AR balance
     * 
     * @return float Total balance of overdue AR
     */
    public function getTotalOverdue(): float {
        $today = date('Y-m-d');
        
        $sql = "
            SELECT COALESCE(SUM(balance), 0) as total
            FROM account_receivables
            WHERE status IN ('open', 'partial')
            AND due_date < ?
        ";
        $params = [$today];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }
    
    /**
     * Get AR summary by customer
     * 
     * @return array Summary grouped by customer
     */
    public function getSummaryByCustomer(): array {
        $sql = "
            SELECT 
                ar.user_id,
                u.display_name as customer_name,
                u.phone as customer_phone,
                COUNT(*) as total_records,
                SUM(CASE WHEN ar.status IN ('open', 'partial') THEN 1 ELSE 0 END) as open_records,
                COALESCE(SUM(ar.total_amount), 0) as total_amount,
                COALESCE(SUM(ar.received_amount), 0) as received_amount,
                COALESCE(SUM(CASE WHEN ar.status IN ('open', 'partial') THEN ar.balance ELSE 0 END), 0) as outstanding_balance
            FROM account_receivables ar
            LEFT JOIN users u ON ar.user_id = u.id
            WHERE 1=1
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (ar.line_account_id = ? OR ar.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " GROUP BY ar.user_id, u.display_name, u.phone ORDER BY outstanding_balance DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get monthly AR summary
     * 
     * @param string $month Month in YYYY-MM format
     * @return array Monthly summary
     */
    public function getMonthlySummary(string $month): array {
        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new Exception('Invalid month format. Use YYYY-MM');
        }
        
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        $sql = "
            SELECT 
                COUNT(*) as total_records,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(received_amount), 0) as received_amount,
                COALESCE(SUM(balance), 0) as outstanding_balance,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count
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
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'month' => $month,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => $summary
        ];
    }
    
    /**
     * Get AR by Transaction ID
     * 
     * @param int $transactionId Transaction ID
     * @return array|null AR record or null
     */
    public function getByTransactionId(int $transactionId): ?array {
        $sql = "SELECT * FROM account_receivables WHERE transaction_id = ?";
        $params = [$transactionId];
        
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
     * Get valid statuses
     * 
     * @return array List of valid statuses
     */
    public static function getValidStatuses(): array {
        return self::$validStatuses;
    }
    
    /**
     * Get aging brackets configuration
     * Uses shared AgingHelper
     * 
     * @return array Aging brackets
     */
    public static function getAgingBrackets(): array {
        return AgingHelper::AGING_BRACKETS;
    }
    
    /**
     * Check if AR number exists
     * 
     * @param string $arNumber AR number to check
     * @return bool True if exists
     */
    public function arNumberExists(string $arNumber): bool {
        $stmt = $this->db->prepare("SELECT id FROM account_receivables WHERE ar_number = ?");
        $stmt->execute([$arNumber]);
        return (bool)$stmt->fetch();
    }
}
