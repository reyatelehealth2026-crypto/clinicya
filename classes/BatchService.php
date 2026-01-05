<?php
/**
 * BatchService - จัดการ Batch/Lot Tracking
 * 
 * Handles CRUD operations for inventory batches with
 * expiry management and FIFO/FEFO picking support.
 * 
 * Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 9.1, 9.2, 9.3, 10.1, 10.3, 10.4
 */

class BatchService {
    private $db;
    private $lineAccountId;
    
    // Valid batch statuses
    const BATCH_STATUSES = ['active', 'quarantine', 'expired', 'disposed'];
    
    // Default expiry alert threshold in days
    const DEFAULT_EXPIRY_ALERT_DAYS = 90;
    
    // Near expiry alert threshold in days
    const NEAR_EXPIRY_ALERT_DAYS = 30;
    
    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId Line account ID for multi-tenant support
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
    }
    
    // =========================================
    // CRUD Methods (Requirements 8.1, 8.2)
    // =========================================
    
    /**
     * Create a new batch
     * 
     * @param array $data Batch data
     * @return int Created batch ID
     * @throws Exception If validation fails
     */
    public function createBatch(array $data): int {
        // Validate required fields
        if (empty($data['product_id'])) {
            throw new Exception('Product ID is required', 400);
        }
        
        if (empty($data['batch_number'])) {
            throw new Exception('Batch number is required', 400);
        }
        
        if (!isset($data['quantity']) || $data['quantity'] < 0) {
            throw new Exception('Valid quantity is required', 400);
        }
        
        // Set received_at if not provided
        $receivedAt = $data['received_at'] ?? date('Y-m-d H:i:s');
        
        // Set quantity_available to quantity if not provided
        $quantityAvailable = $data['quantity_available'] ?? $data['quantity'];
        
        // Validate status if provided
        $status = $data['status'] ?? 'active';
        if (!in_array($status, self::BATCH_STATUSES)) {
            throw new Exception('Invalid batch status. Allowed: ' . implode(', ', self::BATCH_STATUSES), 400);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO inventory_batches 
            (line_account_id, product_id, batch_number, lot_number, supplier_id, 
             quantity, quantity_available, cost_price, manufacture_date, expiry_date,
             received_at, received_by, location_id, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            (int)$data['product_id'],
            $data['batch_number'],
            $data['lot_number'] ?? null,
            $data['supplier_id'] ?? null,
            (int)$data['quantity'],
            (int)$quantityAvailable,
            $data['cost_price'] ?? null,
            $data['manufacture_date'] ?? null,
            $data['expiry_date'] ?? null,
            $receivedAt,
            $data['received_by'] ?? null,
            $data['location_id'] ?? null,
            $status,
            $data['notes'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    
    /**
     * Update an existing batch
     * 
     * @param int $id Batch ID
     * @param array $data Updated data
     * @return bool True on success
     * @throws Exception If batch not found or validation fails
     */
    public function updateBatch(int $id, array $data): bool {
        // Check if batch exists
        $existing = $this->getBatch($id);
        if (!$existing) {
            throw new Exception('Batch not found', 404);
        }
        
        $updates = [];
        $params = [];
        
        // Handle batch_number update
        if (isset($data['batch_number'])) {
            $updates[] = 'batch_number = ?';
            $params[] = $data['batch_number'];
        }
        
        // Handle lot_number update
        if (array_key_exists('lot_number', $data)) {
            $updates[] = 'lot_number = ?';
            $params[] = $data['lot_number'];
        }
        
        // Handle supplier_id update
        if (array_key_exists('supplier_id', $data)) {
            $updates[] = 'supplier_id = ?';
            $params[] = $data['supplier_id'];
        }
        
        // Handle quantity update
        if (isset($data['quantity'])) {
            if ($data['quantity'] < 0) {
                throw new Exception('Quantity cannot be negative', 400);
            }
            $updates[] = 'quantity = ?';
            $params[] = (int)$data['quantity'];
        }
        
        // Handle quantity_available update
        if (isset($data['quantity_available'])) {
            if ($data['quantity_available'] < 0) {
                throw new Exception('Available quantity cannot be negative', 400);
            }
            $updates[] = 'quantity_available = ?';
            $params[] = (int)$data['quantity_available'];
        }
        
        // Handle cost_price update
        if (array_key_exists('cost_price', $data)) {
            $updates[] = 'cost_price = ?';
            $params[] = $data['cost_price'];
        }
        
        // Handle manufacture_date update
        if (array_key_exists('manufacture_date', $data)) {
            $updates[] = 'manufacture_date = ?';
            $params[] = $data['manufacture_date'];
        }
        
        // Handle expiry_date update
        if (array_key_exists('expiry_date', $data)) {
            $updates[] = 'expiry_date = ?';
            $params[] = $data['expiry_date'];
        }
        
        // Handle location_id update
        if (array_key_exists('location_id', $data)) {
            $updates[] = 'location_id = ?';
            $params[] = $data['location_id'];
        }
        
        // Handle status update
        if (isset($data['status'])) {
            if (!in_array($data['status'], self::BATCH_STATUSES)) {
                throw new Exception('Invalid batch status', 400);
            }
            $updates[] = 'status = ?';
            $params[] = $data['status'];
        }
        
        // Handle notes update
        if (array_key_exists('notes', $data)) {
            $updates[] = 'notes = ?';
            $params[] = $data['notes'];
        }
        
        if (empty($updates)) {
            return true; // Nothing to update
        }
        
        $params[] = $id;
        $params[] = $this->lineAccountId;
        
        $sql = "UPDATE inventory_batches SET " . implode(', ', $updates) . 
               " WHERE id = ? AND line_account_id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Get a batch by ID
     * 
     * @param int $id Batch ID
     * @return array|null Batch data or null if not found
     */
    public function getBatch(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.id = ? AND b.line_account_id = ?
        ");
        $stmt->execute([$id, $this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $result = $this->enrichBatchData($result);
        }
        
        return $result ?: null;
    }
    
    /**
     * Get a batch by batch number
     * 
     * @param string $batchNumber Batch number
     * @param int|null $productId Optional product ID to narrow search
     * @return array|null Batch data or null if not found
     */
    public function getBatchByNumber(string $batchNumber, ?int $productId = null): ?array {
        $sql = "
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.batch_number = ? AND b.line_account_id = ?
        ";
        $params = [$batchNumber, $this->lineAccountId];
        
        if ($productId !== null) {
            $sql .= " AND b.product_id = ?";
            $params[] = $productId;
        }
        
        $sql .= " LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $result = $this->enrichBatchData($result);
        }
        
        return $result ?: null;
    }

    
    // =========================================
    // Batch Query Methods (Requirements 8.3, 8.4, 8.5)
    // =========================================
    
    /**
     * Get all batches for a product
     * 
     * @param int $productId Product ID
     * @param array $filters Optional filters (status, has_stock, etc.)
     * @return array List of batches
     */
    public function getBatchesForProduct(int $productId, array $filters = []): array {
        $sql = "
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.product_id = ? AND b.line_account_id = ?
        ";
        $params = [$productId, $this->lineAccountId];
        
        // Filter by status
        if (isset($filters['status'])) {
            $sql .= " AND b.status = ?";
            $params[] = $filters['status'];
        }
        
        // Filter by has_stock (quantity_available > 0)
        if (isset($filters['has_stock']) && $filters['has_stock']) {
            $sql .= " AND b.quantity_available > 0";
        }
        
        // Filter by location
        if (isset($filters['location_id'])) {
            $sql .= " AND b.location_id = ?";
            $params[] = $filters['location_id'];
        }
        
        // Default ordering by expiry date (FEFO)
        $sql .= " ORDER BY b.expiry_date ASC, b.received_at ASC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enrich each batch with calculated fields
        return array_map([$this, 'enrichBatchData'], $batches);
    }
    
    /**
     * Get batches expiring within specified days
     * 
     * @param int $daysAhead Number of days to look ahead (default 90)
     * @param array $filters Optional filters
     * @return array List of expiring batches
     */
    public function getExpiringBatches(int $daysAhead = 90, array $filters = []): array {
        $futureDate = date('Y-m-d', strtotime("+{$daysAhead} days"));
        $today = date('Y-m-d');
        
        $sql = "
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin,
                   DATEDIFF(b.expiry_date, CURDATE()) as days_until_expiry
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.line_account_id = ?
              AND b.expiry_date IS NOT NULL
              AND b.expiry_date > ?
              AND b.expiry_date <= ?
              AND b.status = 'active'
              AND b.quantity_available > 0
        ";
        $params = [$this->lineAccountId, $today, $futureDate];
        
        // Filter by product
        if (isset($filters['product_id'])) {
            $sql .= " AND b.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        $sql .= " ORDER BY b.expiry_date ASC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map([$this, 'enrichBatchData'], $batches);
    }
    
    /**
     * Get all expired batches
     * 
     * @param array $filters Optional filters
     * @return array List of expired batches
     */
    public function getExpiredBatches(array $filters = []): array {
        $today = date('Y-m-d');
        
        $sql = "
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin,
                   DATEDIFF(CURDATE(), b.expiry_date) as days_expired
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.line_account_id = ?
              AND b.expiry_date IS NOT NULL
              AND b.expiry_date < ?
              AND b.status IN ('active', 'expired')
        ";
        $params = [$this->lineAccountId, $today];
        
        // Filter by product
        if (isset($filters['product_id'])) {
            $sql .= " AND b.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        // Filter by has_stock
        if (isset($filters['has_stock']) && $filters['has_stock']) {
            $sql .= " AND b.quantity_available > 0";
        }
        
        $sql .= " ORDER BY b.expiry_date ASC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map([$this, 'enrichBatchData'], $batches);
    }

    
    // =========================================
    // FIFO/FEFO Methods (Requirements 9.1, 9.2, 9.3)
    // =========================================
    
    /**
     * Get next batch for picking based on FIFO or FEFO method
     * 
     * FEFO (First Expired First Out): For products with expiry dates
     * FIFO (First In First Out): For products without expiry dates
     * 
     * @param int $productId Product ID
     * @param string $method Picking method ('FEFO' or 'FIFO')
     * @return array|null Next batch to pick or null if none available
     */
    public function getNextBatchForPicking(int $productId, string $method = 'FEFO'): ?array {
        $method = strtoupper($method);
        
        // Base query - only active batches with available stock
        $sql = "
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.product_id = ? 
              AND b.line_account_id = ?
              AND b.status = 'active'
              AND b.quantity_available > 0
        ";
        
        // Exclude expired batches (Requirements 10.3)
        $sql .= " AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())";
        
        // Order based on method
        if ($method === 'FEFO') {
            // FEFO: Sort by expiry date ascending (soonest first)
            // For batches without expiry, fall back to received_at
            $sql .= " ORDER BY 
                CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END,
                b.expiry_date ASC,
                b.received_at ASC";
        } else {
            // FIFO: Sort by received date ascending (oldest first)
            $sql .= " ORDER BY b.received_at ASC, b.id ASC";
        }
        
        $sql .= " LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId, $this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $result = $this->enrichBatchData($result);
        }
        
        return $result ?: null;
    }
    
    /**
     * Get batches sorted by expiry date (FEFO order)
     * 
     * @param int $productId Product ID
     * @param bool $activeOnly Only include active batches with stock
     * @return array List of batches sorted by expiry
     */
    public function getBatchesSortedByExpiry(int $productId, bool $activeOnly = true): array {
        $sql = "
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin,
                   DATEDIFF(b.expiry_date, CURDATE()) as days_until_expiry
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.product_id = ? AND b.line_account_id = ?
        ";
        $params = [$productId, $this->lineAccountId];
        
        if ($activeOnly) {
            $sql .= " AND b.status = 'active' AND b.quantity_available > 0";
        }
        
        // Sort by expiry date ascending (soonest first)
        // Batches without expiry date go to the end
        $sql .= " ORDER BY 
            CASE WHEN b.expiry_date IS NULL THEN 1 ELSE 0 END,
            b.expiry_date ASC,
            b.received_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map([$this, 'enrichBatchData'], $batches);
    }
    
    /**
     * Get batches sorted by receive date (FIFO order)
     * 
     * @param int $productId Product ID
     * @param bool $activeOnly Only include active batches with stock
     * @return array List of batches sorted by receive date
     */
    public function getBatchesSortedByReceiveDate(int $productId, bool $activeOnly = true): array {
        $sql = "
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.product_id = ? AND b.line_account_id = ?
        ";
        $params = [$productId, $this->lineAccountId];
        
        if ($activeOnly) {
            $sql .= " AND b.status = 'active' AND b.quantity_available > 0";
        }
        
        // Sort by received date ascending (oldest first)
        $sql .= " ORDER BY b.received_at ASC, b.id ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map([$this, 'enrichBatchData'], $batches);
    }

    
    // =========================================
    // Expiry Management Methods (Requirements 8.5, 10.3, 10.4)
    // =========================================
    
    /**
     * Flag all expired batches
     * Updates status to 'expired' for batches past their expiry date
     * 
     * @return int Number of batches flagged
     */
    public function flagExpiredBatches(): int {
        $today = date('Y-m-d');
        
        $stmt = $this->db->prepare("
            UPDATE inventory_batches 
            SET status = 'expired'
            WHERE line_account_id = ?
              AND expiry_date IS NOT NULL
              AND expiry_date < ?
              AND status = 'active'
        ");
        
        $stmt->execute([$this->lineAccountId, $today]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Dispose a batch (requires pharmacist approval)
     * 
     * @param int $batchId Batch ID
     * @param int $pharmacistId Pharmacist/Staff ID who approved disposal
     * @param string $reason Disposal reason
     * @return bool True on success
     * @throws Exception If batch not found or validation fails
     */
    public function disposeBatch(int $batchId, int $pharmacistId, string $reason): bool {
        // Get batch
        $batch = $this->getBatch($batchId);
        if (!$batch) {
            throw new Exception('Batch not found', 404);
        }
        
        // Validate reason is provided
        if (empty(trim($reason))) {
            throw new Exception('Disposal reason is required', 400);
        }
        
        // Update batch status to disposed
        $stmt = $this->db->prepare("
            UPDATE inventory_batches 
            SET status = 'disposed',
                disposal_date = NOW(),
                disposal_by = ?,
                disposal_reason = ?,
                quantity_available = 0
            WHERE id = ? AND line_account_id = ?
        ");
        
        return $stmt->execute([
            $pharmacistId,
            $reason,
            $batchId,
            $this->lineAccountId
        ]);
    }
    
    /**
     * Dispose a batch with stock update and expense creation
     * 
     * This method performs a complete disposal operation:
     * 1. Calculates disposal value (quantity_available × cost_price)
     * 2. Updates batch status to 'disposed' via disposeBatch()
     * 3. Decreases stock in business_items via InventoryService
     * 4. Creates stock_movement with type 'disposal'
     * 5. Creates expense record for inventory write-off via ExpenseService
     * 
     * Requirements: 2.1, 2.2, 2.3, 2.4, 5.1, 5.2
     * 
     * @param int $batchId Batch ID to dispose
     * @param int $pharmacistId Pharmacist/Staff ID who approved disposal
     * @param string $reason Disposal reason
     * @param InventoryService $inventoryService Inventory service instance
     * @param ExpenseService|null $expenseService Expense service instance (optional)
     * @return array Disposal result with batch_id, disposed_quantity, disposal_value, expense_id
     * @throws Exception If batch not found, invalid status, or insufficient quantity
     */
    public function disposeBatchWithStock(
        int $batchId,
        int $pharmacistId,
        string $reason,
        InventoryService $inventoryService,
        ?ExpenseService $expenseService = null
    ): array {
        // Get batch data before disposal
        $batch = $this->getBatch($batchId);
        if (!$batch) {
            throw new Exception('Batch not found', 404);
        }
        
        // Validate batch is active
        if ($batch['status'] !== 'active') {
            throw new Exception('Cannot dispose non-active batch. Current status: ' . $batch['status'], 400);
        }
        
        // Validate batch has quantity to dispose
        $quantityToDispose = (int)$batch['quantity_available'];
        if ($quantityToDispose <= 0) {
            throw new Exception('Batch has no available quantity to dispose', 400);
        }
        
        // Calculate disposal value
        $costPrice = (float)($batch['cost_price'] ?? 0);
        $disposalValue = $quantityToDispose * $costPrice;
        
        // Determine disposal category based on reason
        $disposalCategory = $this->getDisposalCategory($reason);
        
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // 1. Call existing disposeBatch() to update batch status
            // This sets status='disposed', quantity_available=0, and records disposal info
            $this->disposeBatch($batchId, $pharmacistId, $reason);
            
            // 2. Decrease stock in business_items via InventoryService
            // This also creates stock_movement record with type 'disposal'
            // Pass cost_price for value tracking (Requirements 6.3)
            $inventoryService->updateStock(
                (int)$batch['product_id'],
                -$quantityToDispose,  // Negative to decrease stock
                'disposal',           // movement_type
                'batch_disposal',     // reference_type
                $batchId,             // reference_id
                "DSP-{$batchId}",     // reference_number
                $reason,              // notes
                $pharmacistId,        // created_by
                $costPrice            // unit_cost for value tracking
            );
            
            // 3. Create expense record for inventory write-off (Requirements 5.1, 5.2)
            $expenseId = null;
            if ($expenseService !== null && $disposalValue > 0) {
                $expenseId = $expenseService->createDisposalExpense([
                    'batch_id' => $batchId,
                    'product_id' => (int)$batch['product_id'],
                    'quantity' => $quantityToDispose,
                    'unit_cost' => $costPrice,
                    'total_amount' => $disposalValue,
                    'reason' => $reason,
                    'category' => $disposalCategory,
                    'approved_by' => $pharmacistId
                ]);
            }
            
            $this->db->commit();
            
            return [
                'batch_id' => $batchId,
                'product_id' => (int)$batch['product_id'],
                'disposed_quantity' => $quantityToDispose,
                'cost_price' => $costPrice,
                'disposal_value' => $disposalValue,
                'reason' => $reason,
                'category' => $disposalCategory,
                'disposed_by' => $pharmacistId,
                'expense_id' => $expenseId
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get disposal category based on reason
     * 
     * @param string $reason Disposal reason
     * @return string Category name (expiry_loss, damage_loss, inventory_loss)
     */
    public function getDisposalCategory(string $reason): string {
        $reasonLower = strtolower($reason);
        
        if (strpos($reasonLower, 'expir') !== false || strpos($reasonLower, 'หมดอายุ') !== false) {
            return 'expiry_loss';
        }
        
        if (strpos($reasonLower, 'damage') !== false || strpos($reasonLower, 'เสียหาย') !== false || strpos($reasonLower, 'ชำรุด') !== false) {
            return 'damage_loss';
        }
        
        return 'inventory_loss';
    }
    
    // =========================================
    // Helper Methods
    // =========================================
    
    /**
     * Enrich batch data with calculated fields
     * 
     * @param array $batch Raw batch data
     * @return array Enriched batch data
     */
    private function enrichBatchData(array $batch): array {
        // Calculate days until expiry
        if (!empty($batch['expiry_date'])) {
            $expiryDate = new DateTime($batch['expiry_date']);
            $today = new DateTime('today');
            $diff = $today->diff($expiryDate);
            
            $batch['days_until_expiry'] = $diff->invert ? -$diff->days : $diff->days;
            $batch['is_expired'] = $batch['days_until_expiry'] < 0;
            $batch['is_near_expiry'] = !$batch['is_expired'] && 
                                       $batch['days_until_expiry'] <= self::NEAR_EXPIRY_ALERT_DAYS;
            $batch['expiry_status'] = $this->getExpiryStatus($batch['days_until_expiry']);
        } else {
            $batch['days_until_expiry'] = null;
            $batch['is_expired'] = false;
            $batch['is_near_expiry'] = false;
            $batch['expiry_status'] = 'no_expiry';
        }
        
        // Calculate stock status
        $batch['has_stock'] = ($batch['quantity_available'] ?? 0) > 0;
        $batch['stock_percentage'] = $batch['quantity'] > 0 
            ? round(($batch['quantity_available'] / $batch['quantity']) * 100, 2)
            : 0;
        
        return $batch;
    }
    
    /**
     * Get expiry status label
     * 
     * @param int $daysUntilExpiry Days until expiry
     * @return string Status label
     */
    private function getExpiryStatus(int $daysUntilExpiry): string {
        if ($daysUntilExpiry < 0) {
            return 'expired';
        } elseif ($daysUntilExpiry <= 30) {
            return 'critical';
        } elseif ($daysUntilExpiry <= 90) {
            return 'warning';
        } else {
            return 'ok';
        }
    }
    
    /**
     * Get all batches with optional filters
     * 
     * @param array $filters Optional filters
     * @return array List of batches
     */
    public function getBatches(array $filters = []): array {
        $sql = "
            SELECT b.*, 
                   wl.location_code,
                   wl.zone,
                   wl.shelf,
                   wl.bin
            FROM inventory_batches b
            LEFT JOIN warehouse_locations wl ON b.location_id = wl.id
            WHERE b.line_account_id = ?
        ";
        $params = [$this->lineAccountId];
        
        // Filter by product
        if (isset($filters['product_id'])) {
            $sql .= " AND b.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        // Filter by status
        if (isset($filters['status'])) {
            $sql .= " AND b.status = ?";
            $params[] = $filters['status'];
        }
        
        // Filter by has_stock
        if (isset($filters['has_stock']) && $filters['has_stock']) {
            $sql .= " AND b.quantity_available > 0";
        }
        
        // Filter by location
        if (isset($filters['location_id'])) {
            $sql .= " AND b.location_id = ?";
            $params[] = $filters['location_id'];
        }
        
        // Filter by supplier
        if (isset($filters['supplier_id'])) {
            $sql .= " AND b.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        $sql .= " ORDER BY b.expiry_date ASC, b.received_at ASC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map([$this, 'enrichBatchData'], $batches);
    }
    
    /**
     * Reduce batch quantity (for picking)
     * 
     * @param int $batchId Batch ID
     * @param int $quantity Quantity to reduce
     * @return bool True on success
     * @throws Exception If insufficient stock or batch not found
     */
    public function reduceQuantity(int $batchId, int $quantity): bool {
        $batch = $this->getBatch($batchId);
        if (!$batch) {
            throw new Exception('Batch not found', 404);
        }
        
        if ($batch['status'] !== 'active') {
            throw new Exception('Cannot pick from non-active batch', 400);
        }
        
        if ($batch['is_expired']) {
            throw new Exception('Cannot pick from expired batch', 400);
        }
        
        if ($batch['quantity_available'] < $quantity) {
            throw new Exception('Insufficient stock. Available: ' . $batch['quantity_available'], 400);
        }
        
        $stmt = $this->db->prepare("
            UPDATE inventory_batches 
            SET quantity_available = quantity_available - ?
            WHERE id = ? AND line_account_id = ?
        ");
        
        return $stmt->execute([$quantity, $batchId, $this->lineAccountId]);
    }
    
    /**
     * Get batch statistics for a product
     * 
     * @param int $productId Product ID
     * @return array Statistics
     */
    public function getBatchStatistics(int $productId): array {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_batches,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_batches,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_batches,
                SUM(CASE WHEN status = 'disposed' THEN 1 ELSE 0 END) as disposed_batches,
                SUM(quantity) as total_quantity,
                SUM(quantity_available) as total_available,
                MIN(CASE WHEN status = 'active' AND expiry_date IS NOT NULL THEN expiry_date END) as earliest_expiry,
                MIN(CASE WHEN status = 'active' THEN received_at END) as oldest_batch_date
            FROM inventory_batches
            WHERE product_id = ? AND line_account_id = ?
        ");
        $stmt->execute([$productId, $this->lineAccountId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_batches' => 0,
            'active_batches' => 0,
            'expired_batches' => 0,
            'disposed_batches' => 0,
            'total_quantity' => 0,
            'total_available' => 0,
            'earliest_expiry' => null,
            'oldest_batch_date' => null
        ];
    }
}
