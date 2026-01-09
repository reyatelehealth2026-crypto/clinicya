<?php
/**
 * AccountPayableService - จัดการเจ้าหนี้การค้า (Account Payable)
 * 
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 5.1
 */

require_once __DIR__ . '/PaymentVoucherService.php';
require_once __DIR__ . '/AgingHelper.php';

class AccountPayableService {
    private $db;
    private $lineAccountId;
    private $paymentVoucherService;
    
    /**
     * Valid AP statuses
     */
    private static $validStatuses = ['open', 'partial', 'paid', 'cancelled'];
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->paymentVoucherService = new PaymentVoucherService($db, $lineAccountId);
    }
    
    /**
     * Generate unique AP number
     * Format: AP-YYYYMMDD-XXXX
     * 
     * @return string Generated AP number
     */
    public function generateApNumber(): string {
        $date = date('Ymd');
        $prefix = "AP-{$date}-";
        
        // Get the last AP number for today
        $stmt = $this->db->prepare("
            SELECT ap_number FROM account_payables 
            WHERE ap_number LIKE ? 
            ORDER BY ap_number DESC 
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
     * Create AP from Goods Receive (GR)
     * Requirement 1.1: Auto-create AP when GR is completed
     * 
     * @param int $grId Goods Receive ID
     * @return int Created AP ID
     * @throws Exception If GR not found or invalid
     */
    public function createFromGR(int $grId): int {
        // Get GR details with PO and supplier info
        $stmt = $this->db->prepare("
            SELECT gr.*, po.id as po_id, po.po_number, po.total_amount as po_total_amount, po.supplier_id,
                   s.name as supplier_name, s.payment_terms
            FROM goods_receives gr
            LEFT JOIN purchase_orders po ON gr.po_id = po.id
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE gr.id = ?
        ");
        $stmt->execute([$grId]);
        $gr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$gr) {
            throw new Exception('Goods Receive not found');
        }
        
        if ($gr['status'] !== 'confirmed') {
            throw new Exception('Goods Receive must be confirmed before creating AP');
        }
        
        // Check if AP already exists for this GR
        $stmt = $this->db->prepare("SELECT id FROM account_payables WHERE gr_id = ?");
        $stmt->execute([$grId]);
        if ($stmt->fetch()) {
            throw new Exception('AP already exists for this Goods Receive');
        }
        
        // Calculate actual GR total from GR items (not PO total)
        // This fixes the issue where partial receives create AP with full PO amount
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(gri.quantity * poi.unit_cost), 0) as gr_total
            FROM goods_receive_items gri
            LEFT JOIN purchase_order_items poi ON gri.po_item_id = poi.id
            WHERE gri.gr_id = ?
        ");
        $stmt->execute([$grId]);
        $grTotal = (float)$stmt->fetchColumn();
        
        // If no unit_cost found, try to calculate from GR items directly
        if ($grTotal <= 0) {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(gri.quantity * COALESCE(gri.unit_cost, 0)), 0) as gr_total
                FROM goods_receive_items gri
                WHERE gri.gr_id = ?
            ");
            $stmt->execute([$grId]);
            $grTotal = (float)$stmt->fetchColumn();
        }
        
        // Calculate due date based on supplier credit terms
        $creditTerms = (int)($gr['payment_terms'] ?? 30);
        $grDate = $gr['receive_date'] ?? date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime($grDate . " + {$creditTerms} days"));
        
        // Generate AP number
        $apNumber = $this->generateApNumber();
        
        // Create AP record with actual GR total (not PO total)
        $stmt = $this->db->prepare("
            INSERT INTO account_payables 
            (line_account_id, ap_number, supplier_id, po_id, gr_id, invoice_date, 
             due_date, total_amount, paid_amount, balance, status, notes, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'open', ?, ?)
        ");
        
        $metadata = json_encode([
            'gr_number' => $gr['gr_number'],
            'po_number' => $gr['po_number'],
            'supplier_name' => $gr['supplier_name'],
            'credit_terms' => $creditTerms,
            'po_total_amount' => $gr['po_total_amount']
        ]);
        
        $notes = "Auto-created from GR: {$gr['gr_number']}";
        
        $stmt->execute([
            $this->lineAccountId,
            $apNumber,
            $gr['supplier_id'],
            $gr['po_id'],
            $grId,
            $grDate,
            $dueDate,
            $grTotal,
            $grTotal, // balance = total_amount initially
            $notes,
            $metadata
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Create AP manually (without GR)
     * 
     * @param array $data AP data
     * @return int Created AP ID
     * @throws Exception If validation fails
     */
    public function create(array $data): int {
        // Validate required fields
        if (empty($data['supplier_id'])) {
            throw new Exception('Supplier ID is required');
        }
        
        if (!isset($data['total_amount']) || $data['total_amount'] <= 0) {
            throw new Exception('Valid total amount is required');
        }
        
        if (empty($data['due_date'])) {
            throw new Exception('Due date is required');
        }
        
        // Generate AP number
        $apNumber = $this->generateApNumber();
        
        // Prepare metadata
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO account_payables 
            (line_account_id, ap_number, supplier_id, po_id, gr_id, invoice_number, 
             invoice_date, due_date, total_amount, paid_amount, balance, status, notes, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'open', ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $apNumber,
            $data['supplier_id'],
            $data['po_id'] ?? null,
            $data['gr_id'] ?? null,
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
     * Get all AP records with filters
     * Requirement 1.2: Display all outstanding payables sorted by due date
     * 
     * @param array $filters Optional filters (status, supplier_id, date_from, date_to, search)
     * @return array List of AP records
     */
    public function getAll(array $filters = []): array {
        $sql = "
            SELECT ap.*, s.name as supplier_name, s.code as supplier_code
            FROM account_payables ap
            LEFT JOIN suppliers s ON ap.supplier_id = s.id
            WHERE 1=1
        ";
        $params = [];
        
        // Filter by line_account_id
        if ($this->lineAccountId) {
            $sql .= " AND (ap.line_account_id = ? OR ap.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        // Filter by status
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
                $sql .= " AND ap.status IN ({$placeholders})";
                $params = array_merge($params, $filters['status']);
            } else {
                $sql .= " AND ap.status = ?";
                $params[] = $filters['status'];
            }
        }
        
        // Filter by supplier
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND ap.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        // Filter by date range (due_date)
        if (!empty($filters['date_from'])) {
            $sql .= " AND ap.due_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND ap.due_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        // Filter by invoice date range
        if (!empty($filters['invoice_date_from'])) {
            $sql .= " AND ap.invoice_date >= ?";
            $params[] = $filters['invoice_date_from'];
        }
        if (!empty($filters['invoice_date_to'])) {
            $sql .= " AND ap.invoice_date <= ?";
            $params[] = $filters['invoice_date_to'];
        }
        
        // Search by ap_number, invoice_number, or supplier name
        if (!empty($filters['search'])) {
            $sql .= " AND (ap.ap_number LIKE ? OR ap.invoice_number LIKE ? OR s.name LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        // Sorting
        $sortField = $filters['sort_by'] ?? 'due_date';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'ASC');
        $allowedSortFields = ['due_date', 'total_amount', 'balance', 'created_at', 'ap_number', 'supplier_name'];
        $allowedSortOrders = ['ASC', 'DESC'];
        
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'due_date';
        }
        if (!in_array($sortOrder, $allowedSortOrders)) {
            $sortOrder = 'ASC';
        }
        
        // Handle supplier_name sort
        if ($sortField === 'supplier_name') {
            $sql .= " ORDER BY s.name {$sortOrder}";
        } else {
            $sql .= " ORDER BY ap.{$sortField} {$sortOrder}";
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
     * Get AP by ID with full details
     * 
     * @param int $id AP ID
     * @return array|null AP data or null if not found
     */
    public function getById(int $id): ?array {
        $sql = "
            SELECT ap.*, s.name as supplier_name, s.code as supplier_code, 
                   s.contact_person, s.phone as supplier_phone, s.email as supplier_email,
                   po.po_number, gr.gr_number
            FROM account_payables ap
            LEFT JOIN suppliers s ON ap.supplier_id = s.id
            LEFT JOIN purchase_orders po ON ap.po_id = po.id
            LEFT JOIN goods_receives gr ON ap.gr_id = gr.id
            WHERE ap.id = ?
        ";
        $params = [$id];
        
        if ($this->lineAccountId) {
            $sql .= " AND (ap.line_account_id = ? OR ap.line_account_id IS NULL)";
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
        
        // Get payment history
        $result['payments'] = $this->paymentVoucherService->getByApId($id);
        
        return $result;
    }

    
    /**
     * Record payment for AP
     * Requirements 1.3, 1.4, 1.5: Handle partial/full payments, create voucher, update status
     * 
     * @param int $apId AP ID
     * @param array $paymentData Payment details
     * @return int Created payment voucher ID
     * @throws Exception If validation fails
     */
    public function recordPayment(int $apId, array $paymentData): int {
        // Get AP record
        $ap = $this->getById($apId);
        if (!$ap) {
            throw new Exception('Account Payable not found');
        }
        
        // Validate AP status
        if (!in_array($ap['status'], ['open', 'partial'])) {
            throw new Exception('Cannot record payment for AP with status: ' . $ap['status']);
        }
        
        // Validate payment amount
        if (!isset($paymentData['amount']) || $paymentData['amount'] <= 0) {
            throw new Exception('Valid payment amount is required');
        }
        
        $paymentAmount = (float)$paymentData['amount'];
        $currentBalance = (float)$ap['balance'];
        
        if ($paymentAmount > $currentBalance) {
            throw new Exception("Payment amount ({$paymentAmount}) exceeds balance ({$currentBalance})");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Create payment voucher
            $voucherData = [
                'voucher_type' => 'ap',
                'reference_id' => $apId,
                'payment_date' => $paymentData['payment_date'] ?? date('Y-m-d'),
                'amount' => $paymentAmount,
                'payment_method' => $paymentData['payment_method'],
                'bank_account' => $paymentData['bank_account'] ?? null,
                'reference_number' => $paymentData['reference_number'] ?? null,
                'cheque_number' => $paymentData['cheque_number'] ?? null,
                'cheque_date' => $paymentData['cheque_date'] ?? null,
                'attachment_path' => $paymentData['attachment_path'] ?? null,
                'notes' => $paymentData['notes'] ?? null,
                'metadata' => [
                    'ap_number' => $ap['ap_number'],
                    'supplier_name' => $ap['supplier_name'],
                    'original_balance' => $currentBalance
                ],
                'created_by' => $paymentData['created_by'] ?? null
            ];
            
            $voucherId = $this->paymentVoucherService->create($voucherData);
            
            // Calculate new balance and status
            $newBalance = $currentBalance - $paymentAmount;
            $newPaidAmount = (float)$ap['paid_amount'] + $paymentAmount;
            
            if ($newBalance <= 0) {
                // Fully paid
                $newStatus = 'paid';
                $closedAt = date('Y-m-d H:i:s');
            } else {
                // Partially paid
                $newStatus = 'partial';
                $closedAt = null;
            }
            
            // Update AP record
            $stmt = $this->db->prepare("
                UPDATE account_payables 
                SET paid_amount = ?, balance = ?, status = ?, closed_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newPaidAmount, $newBalance, $newStatus, $closedAt, $apId]);
            
            $this->db->commit();
            
            return $voucherId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Cancel AP record
     * 
     * @param int $id AP ID
     * @param string $reason Cancellation reason
     * @return bool Success
     * @throws Exception If AP cannot be cancelled
     */
    public function cancel(int $id, string $reason): bool {
        $ap = $this->getById($id);
        if (!$ap) {
            throw new Exception('Account Payable not found');
        }
        
        if ($ap['status'] === 'paid') {
            throw new Exception('Cannot cancel paid AP');
        }
        
        if ($ap['paid_amount'] > 0) {
            throw new Exception('Cannot cancel AP with existing payments');
        }
        
        $metadata = $ap['metadata'] ?? [];
        $metadata['cancel_reason'] = $reason;
        $metadata['cancelled_at'] = date('Y-m-d H:i:s');
        
        $stmt = $this->db->prepare("
            UPDATE account_payables 
            SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), '\nCancelled: ', ?), 
                metadata = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        return $stmt->execute([$reason, json_encode($metadata), $id]);
    }

    
    /**
     * Get aging report for AP
     * Requirement 5.1: Display payables grouped by age brackets
     * Uses shared AgingHelper for bracket calculation
     * 
     * @return array Aging report with brackets and totals
     */
    public function getAgingReport(): array {
        // Get all open/partial AP records
        $sql = "
            SELECT ap.*, s.name as supplier_name
            FROM account_payables ap
            LEFT JOIN suppliers s ON ap.supplier_id = s.id
            WHERE ap.status IN ('open', 'partial')
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (ap.line_account_id = ? OR ap.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY ap.due_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Use shared AgingHelper to categorize records
        return AgingHelper::categorizeRecords($records);
    }
    
    /**
     * Get upcoming due AP records
     * 
     * @param int $days Number of days to look ahead (default 7)
     * @return array List of AP records due within specified days
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
     * Get overdue AP records
     * 
     * @return array List of overdue AP records
     */
    public function getOverdue(): array {
        $today = date('Y-m-d');
        
        $sql = "
            SELECT ap.*, s.name as supplier_name, s.code as supplier_code,
                   DATEDIFF(?, ap.due_date) as days_overdue
            FROM account_payables ap
            LEFT JOIN suppliers s ON ap.supplier_id = s.id
            WHERE ap.status IN ('open', 'partial')
            AND ap.due_date < ?
        ";
        $params = [$today, $today];
        
        if ($this->lineAccountId) {
            $sql .= " AND (ap.line_account_id = ? OR ap.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY ap.due_date ASC";
        
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
     * Get total outstanding AP balance
     * 
     * @return float Total balance of open/partial AP
     */
    public function getTotalOutstanding(): float {
        $sql = "
            SELECT COALESCE(SUM(balance), 0) as total
            FROM account_payables
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
     * Get total overdue AP balance
     * 
     * @return float Total balance of overdue AP
     */
    public function getTotalOverdue(): float {
        $today = date('Y-m-d');
        
        $sql = "
            SELECT COALESCE(SUM(balance), 0) as total
            FROM account_payables
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
     * Get AP summary by supplier
     * 
     * @return array Summary grouped by supplier
     */
    public function getSummaryBySupplier(): array {
        $sql = "
            SELECT 
                ap.supplier_id,
                s.name as supplier_name,
                s.code as supplier_code,
                COUNT(*) as total_records,
                SUM(CASE WHEN ap.status IN ('open', 'partial') THEN 1 ELSE 0 END) as open_records,
                COALESCE(SUM(ap.total_amount), 0) as total_amount,
                COALESCE(SUM(ap.paid_amount), 0) as paid_amount,
                COALESCE(SUM(CASE WHEN ap.status IN ('open', 'partial') THEN ap.balance ELSE 0 END), 0) as outstanding_balance
            FROM account_payables ap
            LEFT JOIN suppliers s ON ap.supplier_id = s.id
            WHERE 1=1
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (ap.line_account_id = ? OR ap.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " GROUP BY ap.supplier_id, s.name, s.code ORDER BY outstanding_balance DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get monthly AP summary
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
                COALESCE(SUM(paid_amount), 0) as paid_amount,
                COALESCE(SUM(balance), 0) as outstanding_balance,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count
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
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'month' => $month,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => $summary
        ];
    }
    
    /**
     * Get AP by PO ID
     * 
     * @param int $poId Purchase Order ID
     * @return array|null AP record or null
     */
    public function getByPoId(int $poId): ?array {
        $sql = "SELECT * FROM account_payables WHERE po_id = ?";
        $params = [$poId];
        
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
     * Get AP by GR ID
     * 
     * @param int $grId Goods Receive ID
     * @return array|null AP record or null
     */
    public function getByGrId(int $grId): ?array {
        $sql = "SELECT * FROM account_payables WHERE gr_id = ?";
        $params = [$grId];
        
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
     * Check if AP number exists
     * 
     * @param string $apNumber AP number to check
     * @return bool True if exists
     */
    public function apNumberExists(string $apNumber): bool {
        $stmt = $this->db->prepare("SELECT id FROM account_payables WHERE ap_number = ?");
        $stmt->execute([$apNumber]);
        return (bool)$stmt->fetch();
    }
}
