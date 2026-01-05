<?php
/**
 * ExpenseService - จัดการค่าใช้จ่าย
 * 
 * Requirements: 3.1, 3.2, 3.5
 */

class ExpenseService {
    private $db;
    private $lineAccountId;
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Generate unique expense number
     * Format: EXP-YYYYMMDD-XXXX
     * 
     * @return string Generated expense number
     */
    public function generateExpenseNumber(): string {
        $date = date('Ymd');
        $prefix = "EXP-{$date}-";
        
        // Get the last expense number for today
        $stmt = $this->db->prepare("
            SELECT expense_number FROM expenses 
            WHERE expense_number LIKE ? 
            ORDER BY expense_number DESC 
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
     * Create new expense record
     * 
     * @param array $data Expense data (category_id, amount, expense_date, description, etc.)
     * @return int Created expense ID
     * @throws Exception If validation fails
     */
    public function create(array $data): int {
        // Validate required fields
        if (empty($data['category_id'])) {
            throw new Exception('Category is required');
        }
        if (!isset($data['amount']) || $data['amount'] <= 0) {
            throw new Exception('Valid amount is required');
        }
        if (empty($data['expense_date'])) {
            throw new Exception('Expense date is required');
        }
        
        // Validate category exists
        $stmt = $this->db->prepare("SELECT id FROM expense_categories WHERE id = ? AND is_active = 1");
        $stmt->execute([$data['category_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid expense category');
        }
        
        // Validate payment_status if provided
        $validStatuses = ['unpaid', 'paid'];
        $paymentStatus = $data['payment_status'] ?? 'unpaid';
        if (!in_array($paymentStatus, $validStatuses)) {
            throw new Exception('Invalid payment status');
        }
        
        // Generate expense number
        $expenseNumber = $this->generateExpenseNumber();
        
        // Prepare metadata
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;
        
        $stmt = $this->db->prepare("
            INSERT INTO expenses 
            (line_account_id, expense_number, category_id, amount, expense_date, due_date, 
             description, vendor_name, reference_number, attachment_path, payment_status, 
             notes, metadata, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $expenseNumber,
            $data['category_id'],
            $data['amount'],
            $data['expense_date'],
            $data['due_date'] ?? null,
            $data['description'] ?? null,
            $data['vendor_name'] ?? null,
            $data['reference_number'] ?? null,
            $data['attachment_path'] ?? null,
            $paymentStatus,
            $data['notes'] ?? null,
            $metadata,
            $data['created_by'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    
    /**
     * Update expense record
     * 
     * @param int $id Expense ID
     * @param array $data Updated data
     * @return bool Success status
     * @throws Exception If validation fails
     */
    public function update(int $id, array $data): bool {
        // Check if expense exists
        $expense = $this->getById($id);
        if (!$expense) {
            throw new Exception('Expense not found');
        }
        
        // Cannot update paid expenses (except notes)
        if ($expense['payment_status'] === 'paid' && 
            array_diff(array_keys($data), ['notes'])) {
            throw new Exception('Cannot modify paid expense');
        }
        
        $fields = [];
        $values = [];
        
        $allowedFields = [
            'category_id', 'amount', 'expense_date', 'due_date', 
            'description', 'vendor_name', 'reference_number', 
            'attachment_path', 'payment_status', 'notes', 'metadata'
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'metadata') {
                    $fields[] = "{$field} = ?";
                    $values[] = is_array($data[$field]) ? json_encode($data[$field]) : $data[$field];
                } else {
                    $fields[] = "{$field} = ?";
                    $values[] = $data[$field];
                }
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        // Validate category_id if being updated
        if (isset($data['category_id'])) {
            $stmt = $this->db->prepare("SELECT id FROM expense_categories WHERE id = ? AND is_active = 1");
            $stmt->execute([$data['category_id']]);
            if (!$stmt->fetch()) {
                throw new Exception('Invalid expense category');
            }
        }
        
        // Validate amount if being updated
        if (isset($data['amount']) && $data['amount'] <= 0) {
            throw new Exception('Valid amount is required');
        }
        
        // Validate payment_status if being updated
        if (isset($data['payment_status'])) {
            $validStatuses = ['unpaid', 'paid'];
            if (!in_array($data['payment_status'], $validStatuses)) {
                throw new Exception('Invalid payment status');
            }
        }
        
        $values[] = $id;
        $sql = "UPDATE expenses SET " . implode(', ', $fields) . " WHERE id = ?";
        
        // Add line_account_id check if set
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $values[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }
    
    /**
     * Delete expense record
     * 
     * @param int $id Expense ID
     * @return bool Success status
     * @throws Exception If expense is paid or has voucher
     */
    public function delete(int $id): bool {
        $expense = $this->getById($id);
        if (!$expense) {
            throw new Exception('Expense not found');
        }
        
        // Cannot delete paid expenses
        if ($expense['payment_status'] === 'paid') {
            throw new Exception('Cannot delete paid expense');
        }
        
        // Cannot delete if has payment voucher
        if (!empty($expense['payment_voucher_id'])) {
            throw new Exception('Cannot delete expense with payment voucher');
        }
        
        $sql = "DELETE FROM expenses WHERE id = ?";
        $params = [$id];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    
    /**
     * Get expense by ID
     * 
     * @param int $id Expense ID
     * @return array|null Expense data or null if not found
     */
    public function getById(int $id): ?array {
        $sql = "
            SELECT e.*, ec.name as category_name, ec.name_en as category_name_en, 
                   ec.expense_type as category_type
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE e.id = ?
        ";
        $params = [$id];
        
        if ($this->lineAccountId) {
            $sql .= " AND (e.line_account_id = ? OR e.line_account_id IS NULL)";
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
     * Get all expenses with filters
     * 
     * @param array $filters Optional filters (category_id, date_from, date_to, payment_status, search)
     * @return array List of expenses
     */
    public function getAll(array $filters = []): array {
        $sql = "
            SELECT e.*, ec.name as category_name, ec.name_en as category_name_en,
                   ec.expense_type as category_type
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE 1=1
        ";
        $params = [];
        
        // Filter by line_account_id
        if ($this->lineAccountId) {
            $sql .= " AND (e.line_account_id = ? OR e.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        // Filter by category
        if (!empty($filters['category_id'])) {
            $sql .= " AND e.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        // Filter by date range
        if (!empty($filters['date_from'])) {
            $sql .= " AND e.expense_date >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND e.expense_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        // Filter by payment status
        if (!empty($filters['payment_status'])) {
            $sql .= " AND e.payment_status = ?";
            $params[] = $filters['payment_status'];
        }
        
        // Filter by expense type (from category)
        if (!empty($filters['expense_type'])) {
            $sql .= " AND ec.expense_type = ?";
            $params[] = $filters['expense_type'];
        }
        
        // Search by description, vendor_name, or reference_number
        if (!empty($filters['search'])) {
            $sql .= " AND (e.description LIKE ? OR e.vendor_name LIKE ? OR e.reference_number LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        // Sorting
        $sortField = $filters['sort_by'] ?? 'expense_date';
        $sortOrder = strtoupper($filters['sort_order'] ?? 'DESC');
        $allowedSortFields = ['expense_date', 'amount', 'created_at', 'due_date'];
        $allowedSortOrders = ['ASC', 'DESC'];
        
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'expense_date';
        }
        if (!in_array($sortOrder, $allowedSortOrders)) {
            $sortOrder = 'DESC';
        }
        
        $sql .= " ORDER BY e.{$sortField} {$sortOrder}";
        
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
     * Get expenses by category
     * 
     * @param int $categoryId Category ID
     * @return array List of expenses in the category
     */
    public function getByCategory(int $categoryId): array {
        return $this->getAll(['category_id' => $categoryId]);
    }
    
    /**
     * Get monthly expense summary
     * 
     * @param string $month Month in YYYY-MM format
     * @return array Summary with totals by category and overall
     */
    public function getMonthlySummary(string $month): array {
        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new Exception('Invalid month format. Use YYYY-MM');
        }
        
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        
        // Get totals by category
        $sql = "
            SELECT 
                ec.id as category_id,
                ec.name as category_name,
                ec.name_en as category_name_en,
                ec.expense_type,
                COUNT(e.id) as expense_count,
                COALESCE(SUM(e.amount), 0) as total_amount,
                COALESCE(SUM(CASE WHEN e.payment_status = 'paid' THEN e.amount ELSE 0 END), 0) as paid_amount,
                COALESCE(SUM(CASE WHEN e.payment_status = 'unpaid' THEN e.amount ELSE 0 END), 0) as unpaid_amount
            FROM expense_categories ec
            LEFT JOIN expenses e ON ec.id = e.category_id 
                AND e.expense_date BETWEEN ? AND ?
        ";
        $params = [$startDate, $endDate];
        
        if ($this->lineAccountId) {
            $sql .= " AND (e.line_account_id = ? OR e.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= "
            WHERE ec.is_active = 1
            GROUP BY ec.id, ec.name, ec.name_en, ec.expense_type
            ORDER BY total_amount DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $byCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate overall totals
        $totalAmount = 0;
        $totalPaid = 0;
        $totalUnpaid = 0;
        $totalCount = 0;
        
        foreach ($byCategory as $cat) {
            $totalAmount += (float)$cat['total_amount'];
            $totalPaid += (float)$cat['paid_amount'];
            $totalUnpaid += (float)$cat['unpaid_amount'];
            $totalCount += (int)$cat['expense_count'];
        }
        
        // Get totals by expense type
        $byType = [
            'operating' => 0,
            'administrative' => 0,
            'financial' => 0,
            'other' => 0
        ];
        
        foreach ($byCategory as $cat) {
            $type = $cat['expense_type'] ?? 'other';
            $byType[$type] += (float)$cat['total_amount'];
        }
        
        return [
            'month' => $month,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_amount' => $totalAmount,
                'paid_amount' => $totalPaid,
                'unpaid_amount' => $totalUnpaid,
                'expense_count' => $totalCount
            ],
            'by_category' => $byCategory,
            'by_type' => $byType
        ];
    }
    
    /**
     * Get unpaid expenses
     * 
     * @return array List of unpaid expenses
     */
    public function getUnpaid(): array {
        return $this->getAll(['payment_status' => 'unpaid']);
    }
    
    /**
     * Get overdue expenses (unpaid and past due date)
     * 
     * @return array List of overdue expenses
     */
    public function getOverdue(): array {
        $sql = "
            SELECT e.*, ec.name as category_name, ec.name_en as category_name_en,
                   ec.expense_type as category_type,
                   DATEDIFF(CURDATE(), e.due_date) as days_overdue
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE e.payment_status = 'unpaid' 
              AND e.due_date IS NOT NULL 
              AND e.due_date < CURDATE()
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (e.line_account_id = ? OR e.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY e.due_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get upcoming due expenses
     * 
     * @param int $days Number of days to look ahead
     * @return array List of expenses due within specified days
     */
    public function getUpcomingDue(int $days = 7): array {
        $sql = "
            SELECT e.*, ec.name as category_name, ec.name_en as category_name_en,
                   ec.expense_type as category_type,
                   DATEDIFF(e.due_date, CURDATE()) as days_until_due
            FROM expenses e
            LEFT JOIN expense_categories ec ON e.category_id = ec.id
            WHERE e.payment_status = 'unpaid' 
              AND e.due_date IS NOT NULL 
              AND e.due_date >= CURDATE()
              AND e.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ";
        $params = [$days];
        
        if ($this->lineAccountId) {
            $sql .= " AND (e.line_account_id = ? OR e.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY e.due_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Mark expense as paid
     * 
     * @param int $id Expense ID
     * @param int|null $paymentVoucherId Payment voucher ID (optional)
     * @return bool Success status
     */
    public function markAsPaid(int $id, ?int $paymentVoucherId = null): bool {
        $data = ['payment_status' => 'paid'];
        if ($paymentVoucherId) {
            $data['payment_voucher_id'] = $paymentVoucherId;
        }
        
        $sql = "UPDATE expenses SET payment_status = 'paid'";
        $params = [];
        
        if ($paymentVoucherId) {
            $sql .= ", payment_voucher_id = ?";
            $params[] = $paymentVoucherId;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Generate expense number with custom prefix
     * Format: {PREFIX}-YYYYMMDD-XXXX
     * 
     * @param string $prefix Prefix for expense number (default: EXP)
     * @return string Generated expense number
     */
    public function generateExpenseNumberWithPrefix(string $prefix = 'EXP'): string {
        $date = date('Ymd');
        $fullPrefix = "{$prefix}-{$date}-";
        
        // Get the last expense number for today with this prefix
        $stmt = $this->db->prepare("
            SELECT expense_number FROM expenses 
            WHERE expense_number LIKE ? 
            ORDER BY expense_number DESC 
            LIMIT 1
        ");
        $stmt->execute([$fullPrefix . '%']);
        $lastNumber = $stmt->fetchColumn();
        
        if ($lastNumber) {
            // Extract the sequence number and increment
            $sequence = (int)substr($lastNumber, -4);
            $sequence++;
        } else {
            $sequence = 1;
        }
        
        return $fullPrefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Get or create expense category by name
     * 
     * @param string $categoryName Category name (e.g., 'expiry_loss', 'damage_loss', 'inventory_loss')
     * @return int Category ID
     */
    public function getOrCreateDisposalCategory(string $categoryName): int {
        // Map disposal category names to Thai names
        $categoryMap = [
            'expiry_loss' => ['name' => 'สินค้าหมดอายุ', 'name_en' => 'Expiry Loss', 'expense_type' => 'other'],
            'damage_loss' => ['name' => 'สินค้าเสียหาย', 'name_en' => 'Damage Loss', 'expense_type' => 'other'],
            'inventory_loss' => ['name' => 'สินค้าสูญหาย', 'name_en' => 'Inventory Loss', 'expense_type' => 'other']
        ];
        
        $categoryData = $categoryMap[$categoryName] ?? [
            'name' => 'ค่าใช้จ่ายอื่นๆ',
            'name_en' => 'Other Expenses',
            'expense_type' => 'other'
        ];
        
        // Try to find existing category
        $stmt = $this->db->prepare("
            SELECT id FROM expense_categories 
            WHERE name_en = ? AND (line_account_id = ? OR line_account_id IS NULL)
            LIMIT 1
        ");
        $stmt->execute([$categoryData['name_en'], $this->lineAccountId]);
        $existingId = $stmt->fetchColumn();
        
        if ($existingId) {
            return (int)$existingId;
        }
        
        // Create new category
        $stmt = $this->db->prepare("
            INSERT INTO expense_categories 
            (line_account_id, name, name_en, expense_type, is_default, is_active)
            VALUES (?, ?, ?, ?, 0, 1)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $categoryData['name'],
            $categoryData['name_en'],
            $categoryData['expense_type']
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Create disposal expense record
     * 
     * Creates an expense record for inventory disposal/write-off.
     * 
     * Requirements: 5.1, 5.2, 5.5
     * 
     * @param array $data Disposal data containing:
     *   - batch_id: int - Batch being disposed
     *   - product_id: int - Product ID
     *   - quantity: int - Quantity disposed
     *   - unit_cost: float - Cost per unit
     *   - total_amount: float - Total disposal value (quantity × unit_cost)
     *   - reason: string - Disposal reason
     *   - category: string - Category name (expiry_loss, damage_loss, inventory_loss)
     *   - approved_by: int - Pharmacist/Staff ID who approved
     * @return int Created expense ID
     * @throws Exception If validation fails
     */
    public function createDisposalExpense(array $data): int {
        // Validate required fields
        if (empty($data['batch_id'])) {
            throw new Exception('Batch ID is required for disposal expense');
        }
        if (!isset($data['total_amount']) || $data['total_amount'] < 0) {
            throw new Exception('Valid total amount is required');
        }
        
        // Get or create disposal category
        $categoryName = $data['category'] ?? 'inventory_loss';
        $categoryId = $this->getOrCreateDisposalCategory($categoryName);
        
        // Generate expense number with DSP prefix
        $expenseNumber = $this->generateExpenseNumberWithPrefix('DSP');
        
        // Prepare metadata with disposal details
        $metadata = [
            'batch_id' => $data['batch_id'],
            'product_id' => $data['product_id'] ?? null,
            'quantity' => $data['quantity'] ?? 0,
            'unit_cost' => $data['unit_cost'] ?? 0,
            'disposal_type' => $categoryName
        ];
        
        // Build description
        $description = "Disposal: {$data['reason']}";
        if (isset($data['quantity']) && isset($data['unit_cost'])) {
            $description .= " - Qty: {$data['quantity']} @ " . number_format($data['unit_cost'], 2);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO expenses 
            (line_account_id, expense_number, category_id, amount, expense_date, 
             description, reference_number, payment_status, notes, metadata, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $expenseNumber,
            $categoryId,
            $data['total_amount'],
            date('Y-m-d'),
            $description,
            "BATCH-{$data['batch_id']}",
            $data['reason'] ?? null,
            json_encode($metadata),
            $data['approved_by'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get expense count by status
     * 
     * @return array Count by status
     */
    public function getCountByStatus(): array {
        $sql = "
            SELECT 
                payment_status,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount
            FROM expenses
            WHERE 1=1
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " GROUP BY payment_status";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $counts = [
            'unpaid' => ['count' => 0, 'total_amount' => 0],
            'paid' => ['count' => 0, 'total_amount' => 0]
        ];
        
        foreach ($results as $row) {
            $counts[$row['payment_status']] = [
                'count' => (int)$row['count'],
                'total_amount' => (float)$row['total_amount']
            ];
        }
        
        return $counts;
    }
}
