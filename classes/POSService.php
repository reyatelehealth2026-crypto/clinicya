<?php
/**
 * POSService - ระบบ Point of Sale
 * 
 * Main service orchestrating POS operations including:
 * - Transaction management
 * - Cart operations
 * - Discount handling
 * - Customer management
 * - Inventory integration
 * 
 * Requirements: 1.1-1.6, 2.1-2.4, 3.1-3.5
 */

class POSService {
    private $db;
    private $lineAccountId;
    private $inventoryService;
    private $batchService;
    private $loyaltyPoints;
    
    // Transaction statuses
    const STATUS_PENDING = 'pending';
    const STATUS_HOLD = 'hold';
    const STATUS_COMPLETED = 'completed';
    const STATUS_VOIDED = 'voided';
    
    // VAT rate (7% for Thailand)
    const VAT_RATE = 0.07;
    
    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId Line account ID
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
    }
    
    /**
     * Set InventoryService for stock operations
     */
    public function setInventoryService(InventoryService $service): void {
        $this->inventoryService = $service;
    }
    
    /**
     * Set BatchService for FEFO operations
     */
    public function setBatchService(BatchService $service): void {
        $this->batchService = $service;
    }
    
    /**
     * Set LoyaltyPoints for points operations
     */
    public function setLoyaltyPoints(LoyaltyPoints $service): void {
        $this->loyaltyPoints = $service;
    }
    
    // =========================================
    // Transaction Management
    // =========================================
    
    /**
     * Create a new transaction (draft)
     * Requirements: 2.1 - Default to Walk-in Customer
     * 
     * @param int $cashierId Cashier user ID
     * @param int|null $customerId Customer ID (null for walk-in)
     * @return array Created transaction data
     * @throws Exception If no open shift
     */
    public function createTransaction(int $cashierId, ?int $customerId = null): array {
        // Check for open shift (Requirements: 7.5)
        $shift = $this->getCurrentShift($cashierId);
        if (!$shift) {
            throw new Exception('ไม่มีกะที่เปิดอยู่ กรุณาเปิดกะก่อนขาย', 400);
        }
        
        // Generate transaction number
        $transactionNumber = $this->generateTransactionNumber();
        
        // Determine customer type
        $customerType = $customerId ? 'member' : 'walk_in';
        
        $stmt = $this->db->prepare("
            INSERT INTO pos_transactions 
            (line_account_id, transaction_number, shift_id, cashier_id, customer_id, customer_type, status)
            VALUES (?, ?, ?, ?, ?, ?, 'draft')
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $transactionNumber,
            $shift['id'],
            $cashierId,
            $customerId,
            $customerType
        ]);
        
        $transactionId = (int)$this->db->lastInsertId();
        
        return $this->getTransaction($transactionId);
    }
    
    /**
     * Get transaction by ID
     */
    public function getTransaction(int $transactionId): ?array {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   u.display_name as customer_name,
                   u.phone as customer_phone,
                   c.display_name as cashier_name
            FROM pos_transactions t
            LEFT JOIN users u ON t.customer_id = u.id
            LEFT JOIN admin_users c ON t.cashier_id = c.id
            WHERE t.id = ? AND t.line_account_id = ?
        ");
        $stmt->execute([$transactionId, $this->lineAccountId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($transaction) {
            $transaction['items'] = $this->getTransactionItems($transactionId);
        }
        
        return $transaction ?: null;
    }

    
    /**
     * Get transaction items
     */
    public function getTransactionItems(int $transactionId): array {
        $stmt = $this->db->prepare("
            SELECT ti.*, 
                   bi.name as product_name,
                   bi.sku as product_sku,
                   bi.image_url as product_image
            FROM pos_transaction_items ti
            LEFT JOIN business_items bi ON ti.product_id = bi.id
            WHERE ti.transaction_id = ?
            ORDER BY ti.id ASC
        ");
        $stmt->execute([$transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Complete a transaction
     * Requirements: 4.7, 6.1, 6.2, 10.1
     * 
     * @param int $transactionId Transaction ID
     * @param array $payments Payment data
     * @return array Completed transaction
     */
    public function completeTransaction(int $transactionId, array $payments): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการขาย', 404);
        }
        
        if ($transaction['status'] !== 'draft') {
            throw new Exception('รายการนี้ไม่สามารถชำระเงินได้', 400);
        }
        
        // Validate payment total equals transaction total
        $paymentTotal = array_sum(array_column($payments, 'amount'));
        if (abs($paymentTotal - $transaction['total_amount']) > 0.01) {
            throw new Exception('ยอดชำระไม่ตรงกับยอดรวม', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            // 1. Process payments
            foreach ($payments as $payment) {
                $this->processPayment($transactionId, $payment);
            }
            
            // 2. Deduct stock using FEFO (Requirements: 6.1, 6.2)
            $this->deductStockForTransaction($transactionId);
            
            // 3. Award points if member (Requirements: 10.1)
            if ($transaction['customer_id'] && $this->loyaltyPoints) {
                $points = $this->loyaltyPoints->calculatePoints($transaction['total_amount']);
                if ($points > 0) {
                    $this->loyaltyPoints->addPoints(
                        $transaction['customer_id'],
                        $points,
                        'pos_sale',
                        $transactionId,
                        "แต้มจากการซื้อ #{$transaction['transaction_number']}"
                    );
                    
                    // Update transaction with points earned
                    $stmt = $this->db->prepare("
                        UPDATE pos_transactions SET points_earned = ? WHERE id = ?
                    ");
                    $stmt->execute([$points, $transactionId]);
                }
            }
            
            // 4. Update transaction status
            $stmt = $this->db->prepare("
                UPDATE pos_transactions 
                SET status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$transactionId]);
            
            // 5. Update shift totals
            $this->updateShiftTotals($transaction['shift_id'], $transaction['total_amount']);
            
            // 6. Update daily summary
            $this->updateDailySummary($transaction);
            
            $this->db->commit();
            
            return $this->getTransaction($transactionId);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Void a transaction
     * Requirements: 8.3, 8.4
     * 
     * @param int $transactionId Transaction ID
     * @param string $reason Void reason
     * @param int $authorizedBy Manager ID who authorized
     * @return bool Success
     */
    public function voidTransaction(int $transactionId, string $reason, int $authorizedBy): bool {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการขาย', 404);
        }
        
        if ($transaction['status'] !== 'completed') {
            throw new Exception('สามารถยกเลิกได้เฉพาะรายการที่ชำระเงินแล้ว', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            // 1. Restore stock
            $this->restoreStockForTransaction($transactionId);
            
            // 2. Reverse points if member
            if ($transaction['customer_id'] && $transaction['points_earned'] > 0 && $this->loyaltyPoints) {
                $this->loyaltyPoints->deductPoints(
                    $transaction['customer_id'],
                    $transaction['points_earned'],
                    'pos_void',
                    $transactionId,
                    "ยกเลิกแต้มจากรายการ #{$transaction['transaction_number']}"
                );
            }
            
            // 3. Update transaction status
            $stmt = $this->db->prepare("
                UPDATE pos_transactions 
                SET status = 'voided', voided_at = NOW(), voided_by = ?, void_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$authorizedBy, $reason, $transactionId]);
            
            // 4. Update shift totals (subtract)
            $this->updateShiftTotals($transaction['shift_id'], -$transaction['total_amount']);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    // =========================================
    // Cart Operations
    // =========================================
    
    /**
     * Add product to cart
     * Requirements: 1.2, 1.5, 1.6
     * 
     * @param int $transactionId Transaction ID
     * @param int $productId Product ID
     * @param int $quantity Quantity to add
     * @return array Updated cart item
     */
    public function addToCart(int $transactionId, int $productId, int $quantity = 1): array {
        // Validate transaction
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction || $transaction['status'] !== 'draft') {
            throw new Exception('ไม่สามารถเพิ่มสินค้าได้', 400);
        }
        
        // Get product
        $product = $this->getProduct($productId);
        if (!$product) {
            throw new Exception('ไม่พบสินค้า', 404);
        }
        
        // Check stock (Requirements: 1.5)
        $availableStock = $this->getAvailableStock($productId);
        if ($availableStock < $quantity) {
            throw new Exception("สินค้าคงเหลือไม่เพียงพอ (มี {$availableStock} ชิ้น)", 400);
        }
        
        // Check expiry (Requirements: 1.6)
        if ($this->batchService) {
            $nextBatch = $this->batchService->getNextBatchForPicking($productId, 'FEFO');
            if ($nextBatch && $nextBatch['is_expired']) {
                throw new Exception('สินค้าหมดอายุแล้ว ไม่สามารถขายได้', 400);
            }
        }
        
        // Check if product already in cart
        $existingItem = $this->getCartItem($transactionId, $productId);
        
        if ($existingItem) {
            // Update quantity
            $newQuantity = $existingItem['quantity'] + $quantity;
            if ($newQuantity > $availableStock) {
                throw new Exception("สินค้าคงเหลือไม่เพียงพอ (มี {$availableStock} ชิ้น)", 400);
            }
            return $this->updateCartItem($existingItem['id'], $newQuantity);
        }
        
        // Add new item
        $lineTotal = $quantity * $product['price'];
        
        $stmt = $this->db->prepare("
            INSERT INTO pos_transaction_items 
            (transaction_id, product_id, product_name, product_sku, quantity, unit_price, cost_price, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $transactionId,
            $productId,
            $product['name'],
            $product['sku'],
            $quantity,
            $product['price'],
            $product['cost_price'] ?? 0,
            $lineTotal
        ]);
        
        $itemId = (int)$this->db->lastInsertId();
        
        // Recalculate totals
        $this->calculateTotals($transactionId);
        
        return $this->getCartItemById($itemId);
    }

    
    /**
     * Update cart item quantity
     * Requirements: 1.3
     * 
     * @param int $itemId Item ID
     * @param int $quantity New quantity
     * @return array Updated item
     */
    public function updateCartItem(int $itemId, int $quantity): array {
        $item = $this->getCartItemById($itemId);
        if (!$item) {
            throw new Exception('ไม่พบรายการสินค้า', 404);
        }
        
        // Check stock
        $availableStock = $this->getAvailableStock($item['product_id']);
        if ($quantity > $availableStock) {
            throw new Exception("สินค้าคงเหลือไม่เพียงพอ (มี {$availableStock} ชิ้น)", 400);
        }
        
        // Calculate new line total
        $lineTotal = ($quantity * $item['unit_price']) - $item['discount_amount'];
        if ($lineTotal < 0) $lineTotal = 0;
        
        $stmt = $this->db->prepare("
            UPDATE pos_transaction_items 
            SET quantity = ?, line_total = ?
            WHERE id = ?
        ");
        $stmt->execute([$quantity, $lineTotal, $itemId]);
        
        // Recalculate totals
        $this->calculateTotals($item['transaction_id']);
        
        return $this->getCartItemById($itemId);
    }
    
    /**
     * Remove item from cart
     * Requirements: 1.4
     * 
     * @param int $itemId Item ID
     * @return bool Success
     */
    public function removeFromCart(int $itemId): bool {
        $item = $this->getCartItemById($itemId);
        if (!$item) {
            throw new Exception('ไม่พบรายการสินค้า', 404);
        }
        
        $transactionId = $item['transaction_id'];
        
        $stmt = $this->db->prepare("DELETE FROM pos_transaction_items WHERE id = ?");
        $stmt->execute([$itemId]);
        
        // Recalculate totals
        $this->calculateTotals($transactionId);
        
        return true;
    }
    
    /**
     * Apply discount to line item
     * Requirements: 3.1, 3.2, 3.4
     * 
     * @param int $itemId Item ID
     * @param string $type Discount type (percent or fixed)
     * @param float $value Discount value
     * @return array Updated item
     */
    public function applyItemDiscount(int $itemId, string $type, float $value): array {
        $item = $this->getCartItemById($itemId);
        if (!$item) {
            throw new Exception('ไม่พบรายการสินค้า', 404);
        }
        
        $grossTotal = $item['quantity'] * $item['unit_price'];
        
        // Calculate discount amount
        if ($type === 'percent') {
            $discountAmount = $grossTotal * ($value / 100);
        } else {
            $discountAmount = $value;
        }
        
        // Cap discount at item total (Requirements: 3.4)
        if ($discountAmount > $grossTotal) {
            $discountAmount = $grossTotal;
        }
        
        $lineTotal = $grossTotal - $discountAmount;
        
        $stmt = $this->db->prepare("
            UPDATE pos_transaction_items 
            SET discount_type = ?, discount_value = ?, discount_amount = ?, line_total = ?
            WHERE id = ?
        ");
        $stmt->execute([$type, $value, $discountAmount, $lineTotal, $itemId]);
        
        // Recalculate totals
        $this->calculateTotals($item['transaction_id']);
        
        return $this->getCartItemById($itemId);
    }
    
    /**
     * Apply bill-level discount
     * Requirements: 3.3, 3.4
     * 
     * @param int $transactionId Transaction ID
     * @param string $type Discount type (percent or fixed)
     * @param float $value Discount value
     * @return array Updated transaction
     */
    public function applyBillDiscount(int $transactionId, string $type, float $value): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction || $transaction['status'] !== 'draft') {
            throw new Exception('ไม่สามารถใช้ส่วนลดได้', 400);
        }
        
        // Calculate subtotal from items
        $subtotal = 0;
        foreach ($transaction['items'] as $item) {
            $subtotal += $item['line_total'];
        }
        
        // Calculate discount amount
        if ($type === 'percent') {
            $discountAmount = $subtotal * ($value / 100);
        } else {
            $discountAmount = $value;
        }
        
        // Cap discount at subtotal (Requirements: 3.4)
        if ($discountAmount > $subtotal) {
            $discountAmount = $subtotal;
        }
        
        $stmt = $this->db->prepare("
            UPDATE pos_transactions 
            SET discount_type = ?, discount_value = ?, discount_amount = ?
            WHERE id = ?
        ");
        $stmt->execute([$type, $value, $discountAmount, $transactionId]);
        
        // Recalculate totals
        $this->calculateTotals($transactionId);
        
        return $this->getTransaction($transactionId);
    }
    
    // =========================================
    // Customer Methods
    // =========================================
    
    /**
     * Set customer for transaction
     * Requirements: 2.3, 2.4
     * 
     * @param int $transactionId Transaction ID
     * @param int $customerId Customer ID
     * @return array Updated transaction
     */
    public function setCustomer(int $transactionId, int $customerId): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction || $transaction['status'] !== 'draft') {
            throw new Exception('ไม่สามารถเปลี่ยนลูกค้าได้', 400);
        }
        
        // Get customer info
        $customer = $this->getCustomer($customerId);
        if (!$customer) {
            throw new Exception('ไม่พบข้อมูลลูกค้า', 404);
        }
        
        $stmt = $this->db->prepare("
            UPDATE pos_transactions 
            SET customer_id = ?, customer_type = 'member'
            WHERE id = ?
        ");
        $stmt->execute([$customerId, $transactionId]);
        
        return $this->getTransaction($transactionId);
    }
    
    /**
     * Search customers
     * Requirements: 2.2
     * 
     * @param string $query Search query (phone or name)
     * @return array Matching customers
     */
    public function searchCustomers(string $query): array {
        $searchTerm = "%{$query}%";
        
        $stmt = $this->db->prepare("
            SELECT id, display_name, phone, email, 
                   total_points, available_points,
                   picture_url
            FROM users 
            WHERE (phone LIKE ? OR display_name LIKE ? OR email LIKE ?)
            AND (line_account_id = ? OR line_account_id IS NULL)
            ORDER BY display_name ASC
            LIMIT 20
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $this->lineAccountId]);
        
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add tier info if LoyaltyPoints available
        if ($this->loyaltyPoints) {
            foreach ($customers as &$customer) {
                $customer['tier'] = $this->loyaltyPoints->getUserTier($customer['id']);
            }
        }
        
        return $customers;
    }
    
    // =========================================
    // Calculation Methods
    // =========================================
    
    /**
     * Calculate and update transaction totals
     * Requirements: 1.3, 3.1, 3.2, 3.3
     * 
     * @param int $transactionId Transaction ID
     * @return array Calculated totals
     */
    public function calculateTotals(int $transactionId): array {
        // Get current transaction
        $stmt = $this->db->prepare("
            SELECT discount_type, discount_value, discount_amount 
            FROM pos_transactions WHERE id = ?
        ");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate subtotal from items
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(line_total), 0) as subtotal
            FROM pos_transaction_items WHERE transaction_id = ?
        ");
        $stmt->execute([$transactionId]);
        $subtotal = (float)$stmt->fetchColumn();
        
        // Apply bill discount
        $discountAmount = (float)$transaction['discount_amount'];
        $afterDiscount = $subtotal - $discountAmount;
        if ($afterDiscount < 0) $afterDiscount = 0;
        
        // Calculate VAT (included in price, so we extract it)
        // Thai VAT is typically included in displayed price
        $vatAmount = $afterDiscount * self::VAT_RATE / (1 + self::VAT_RATE);
        
        $totalAmount = $afterDiscount;
        
        // Update transaction
        $stmt = $this->db->prepare("
            UPDATE pos_transactions 
            SET subtotal = ?, vat_amount = ?, total_amount = ?
            WHERE id = ?
        ");
        $stmt->execute([$subtotal, $vatAmount, $totalAmount, $transactionId]);
        
        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount
        ];
    }

    
    // =========================================
    // Helper Methods
    // =========================================
    
    /**
     * Get current open shift for cashier
     */
    public function getCurrentShift(int $cashierId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM pos_shifts 
            WHERE cashier_id = ? AND status = 'open' AND line_account_id = ?
            ORDER BY opened_at DESC LIMIT 1
        ");
        $stmt->execute([$cashierId, $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Generate transaction number
     */
    private function generateTransactionNumber(): string {
        $date = date('Ymd');
        $prefix = "POS-{$date}-";
        
        $stmt = $this->db->prepare("
            SELECT transaction_number FROM pos_transactions 
            WHERE transaction_number LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(["{$prefix}%"]);
        $last = $stmt->fetchColumn();
        
        if ($last) {
            $seq = (int)substr($last, -4) + 1;
        } else {
            $seq = 1;
        }
        
        return sprintf("%s%04d", $prefix, $seq);
    }
    
    /**
     * Get product by ID
     */
    private function getProduct(int $productId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, name, sku, price, 0 as cost_price, stock, image_url
            FROM business_items 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get available stock for product
     */
    private function getAvailableStock(int $productId): int {
        $stmt = $this->db->prepare("SELECT COALESCE(stock, 0) FROM business_items WHERE id = ?");
        $stmt->execute([$productId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get cart item by transaction and product
     */
    private function getCartItem(int $transactionId, int $productId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM pos_transaction_items 
            WHERE transaction_id = ? AND product_id = ?
        ");
        $stmt->execute([$transactionId, $productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get cart item by ID
     */
    private function getCartItemById(int $itemId): ?array {
        $stmt = $this->db->prepare("
            SELECT ti.*, bi.name as product_name, bi.sku as product_sku
            FROM pos_transaction_items ti
            LEFT JOIN business_items bi ON ti.product_id = bi.id
            WHERE ti.id = ?
        ");
        $stmt->execute([$itemId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get customer by ID
     */
    private function getCustomer(int $customerId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, display_name, phone, email, total_points, available_points
            FROM users WHERE id = ?
        ");
        $stmt->execute([$customerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Process a single payment
     */
    private function processPayment(int $transactionId, array $payment): int {
        $stmt = $this->db->prepare("
            INSERT INTO pos_payments 
            (transaction_id, payment_method, amount, cash_received, change_amount, reference_number, points_used)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $transactionId,
            $payment['method'],
            $payment['amount'],
            $payment['cash_received'] ?? null,
            $payment['change_amount'] ?? null,
            $payment['reference_number'] ?? null,
            $payment['points_used'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Deduct stock for completed transaction using FEFO
     * Requirements: 6.1, 6.2
     */
    private function deductStockForTransaction(int $transactionId): void {
        $items = $this->getTransactionItems($transactionId);
        $transaction = $this->getTransaction($transactionId);
        
        foreach ($items as $item) {
            $remainingQty = $item['quantity'];
            
            // Use FEFO if BatchService available
            if ($this->batchService) {
                $batches = $this->batchService->getBatchesSortedByExpiry($item['product_id'], true);
                
                foreach ($batches as $batch) {
                    if ($remainingQty <= 0) break;
                    
                    $deductQty = min($remainingQty, $batch['quantity_available']);
                    
                    // Update batch
                    $this->batchService->updateBatch($batch['id'], [
                        'quantity_available' => $batch['quantity_available'] - $deductQty
                    ]);
                    
                    // Update item with batch reference
                    $stmt = $this->db->prepare("
                        UPDATE pos_transaction_items SET batch_id = ? WHERE id = ?
                    ");
                    $stmt->execute([$batch['id'], $item['id']]);
                    
                    $remainingQty -= $deductQty;
                }
            }
            
            // Update main stock via InventoryService
            if ($this->inventoryService) {
                $this->inventoryService->updateStock(
                    $item['product_id'],
                    -$item['quantity'],
                    'sale',
                    'pos_transaction',
                    $transactionId,
                    $transaction['transaction_number'],
                    "ขาย POS #{$transaction['transaction_number']}",
                    $transaction['cashier_id'],
                    $item['cost_price']
                );
            } else {
                // Direct stock update if no InventoryService
                $stmt = $this->db->prepare("
                    UPDATE business_items SET stock = stock - ? WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
        }
    }
    
    /**
     * Restore stock for voided transaction
     * Requirements: 6.3
     */
    private function restoreStockForTransaction(int $transactionId): void {
        $items = $this->getTransactionItems($transactionId);
        $transaction = $this->getTransaction($transactionId);
        
        foreach ($items as $item) {
            // Restore batch if tracked
            if ($item['batch_id'] && $this->batchService) {
                $batch = $this->batchService->getBatch($item['batch_id']);
                if ($batch) {
                    $this->batchService->updateBatch($item['batch_id'], [
                        'quantity_available' => $batch['quantity_available'] + $item['quantity']
                    ]);
                }
            }
            
            // Restore main stock
            if ($this->inventoryService) {
                $this->inventoryService->updateStock(
                    $item['product_id'],
                    $item['quantity'],
                    'void_restore',
                    'pos_void',
                    $transactionId,
                    $transaction['transaction_number'],
                    "คืน Stock จากยกเลิก #{$transaction['transaction_number']}",
                    $transaction['voided_by'],
                    $item['cost_price']
                );
            } else {
                $stmt = $this->db->prepare("
                    UPDATE business_items SET stock = stock + ? WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
        }
    }
    
    /**
     * Update shift totals
     */
    private function updateShiftTotals(int $shiftId, float $amount): void {
        if ($amount >= 0) {
            $stmt = $this->db->prepare("
                UPDATE pos_shifts 
                SET total_sales = total_sales + ?, total_transactions = total_transactions + 1
                WHERE id = ?
            ");
        } else {
            $stmt = $this->db->prepare("
                UPDATE pos_shifts 
                SET total_sales = total_sales + ?, total_transactions = total_transactions - 1
                WHERE id = ?
            ");
        }
        $stmt->execute([$amount, $shiftId]);
    }
    
    /**
     * Update daily summary
     */
    private function updateDailySummary(array $transaction): void {
        $today = date('Y-m-d');
        
        // Check if summary exists
        $stmt = $this->db->prepare("
            SELECT id FROM pos_daily_summary 
            WHERE summary_date = ? AND line_account_id = ?
        ");
        $stmt->execute([$today, $this->lineAccountId]);
        $summaryId = $stmt->fetchColumn();
        
        if (!$summaryId) {
            // Create new summary
            $stmt = $this->db->prepare("
                INSERT INTO pos_daily_summary (line_account_id, summary_date)
                VALUES (?, ?)
            ");
            $stmt->execute([$this->lineAccountId, $today]);
            $summaryId = $this->db->lastInsertId();
        }
        
        // Get payment breakdown
        $stmt = $this->db->prepare("
            SELECT payment_method, SUM(amount) as total
            FROM pos_payments WHERE transaction_id = ?
            GROUP BY payment_method
        ");
        $stmt->execute([$transaction['id']]);
        $payments = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Update summary
        $stmt = $this->db->prepare("
            UPDATE pos_daily_summary SET
                total_sales = total_sales + ?,
                total_transactions = total_transactions + 1,
                total_items_sold = total_items_sold + ?,
                cash_sales = cash_sales + ?,
                transfer_sales = transfer_sales + ?,
                card_sales = card_sales + ?,
                points_sales = points_sales + ?,
                credit_sales = credit_sales + ?,
                total_vat = total_vat + ?,
                net_sales = net_sales + ?
            WHERE id = ?
        ");
        
        $itemCount = count($transaction['items']);
        
        $stmt->execute([
            $transaction['total_amount'],
            $itemCount,
            $payments['cash'] ?? 0,
            $payments['transfer'] ?? 0,
            $payments['card'] ?? 0,
            $payments['points'] ?? 0,
            $payments['credit'] ?? 0,
            $transaction['vat_amount'],
            $transaction['total_amount'] - $transaction['vat_amount'],
            $summaryId
        ]);
    }
    
    // =========================================
    // Search Methods
    // =========================================
    
    /**
     * Search products for POS
     * Requirements: 1.1
     * 
     * @param string $query Search query (name, SKU, or barcode)
     * @return array Matching products
     */
    public function searchProducts(string $query): array {
        try {
            $searchTerm = "%{$query}%";
            
            // Query business_items table - only use columns that exist
            $stmt = $this->db->prepare("
                SELECT 
                    id, 
                    name, 
                    COALESCE(sku, '') as sku, 
                    COALESCE(barcode, '') as barcode, 
                    COALESCE(price, 0) as price, 
                    0 as cost_price,
                    COALESCE(stock, 0) as stock, 
                    COALESCE(image_url, '') as image_url,
                    COALESCE(image_url, '') as image
                FROM business_items 
                WHERE is_active = 1 
                AND (
                    name LIKE ? 
                    OR COALESCE(sku, '') LIKE ? 
                    OR COALESCE(barcode, '') LIKE ? 
                    OR COALESCE(barcode, '') = ?
                )
                ORDER BY 
                    CASE WHEN COALESCE(barcode, '') = ? THEN 0 ELSE 1 END,
                    name ASC
                LIMIT 20
            ");
            $stmt->execute([
                $searchTerm, $searchTerm, $searchTerm, $query, $query
            ]);
            
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add batch info if available
            if ($this->batchService) {
                foreach ($products as &$product) {
                    try {
                        $nextBatch = $this->batchService->getNextBatchForPicking($product['id'], 'FEFO');
                        $product['next_batch'] = $nextBatch;
                        $product['is_expired'] = $nextBatch ? $nextBatch['is_expired'] : false;
                        $product['days_until_expiry'] = $nextBatch ? $nextBatch['days_until_expiry'] : null;
                    } catch (Exception $e) {
                        $product['next_batch'] = null;
                        $product['is_expired'] = false;
                        $product['days_until_expiry'] = null;
                    }
                }
            }
            
            return $products;
        } catch (Exception $e) {
            error_log("POSService::searchProducts error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get transaction history
     * Requirements: 8.1, 8.2, 8.5
     * 
     * @param array $filters Filters (shift_id, date, status, customer_id)
     * @return array Transactions
     */
    public function getTransactionHistory(array $filters = []): array {
        $sql = "
            SELECT t.*, 
                   u.display_name as customer_name,
                   c.display_name as cashier_name
            FROM pos_transactions t
            LEFT JOIN users u ON t.customer_id = u.id
            LEFT JOIN admin_users c ON t.cashier_id = c.id
            WHERE t.line_account_id = ?
        ";
        $params = [$this->lineAccountId];
        
        // Filter by shift (default: current shift)
        if (isset($filters['shift_id'])) {
            $sql .= " AND t.shift_id = ?";
            $params[] = $filters['shift_id'];
        }
        
        // Filter by date
        if (isset($filters['date'])) {
            $sql .= " AND DATE(t.created_at) = ?";
            $params[] = $filters['date'];
        }
        
        // Filter by status
        if (isset($filters['status'])) {
            $sql .= " AND t.status = ?";
            $params[] = $filters['status'];
        }
        
        // Filter by customer
        if (isset($filters['customer_id'])) {
            $sql .= " AND t.customer_id = ?";
            $params[] = $filters['customer_id'];
        }
        
        // Search by transaction number
        if (isset($filters['search'])) {
            $sql .= " AND t.transaction_number LIKE ?";
            $params[] = "%{$filters['search']}%";
        }
        
        $sql .= " ORDER BY t.created_at DESC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        } else {
            $sql .= " LIMIT 100";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // =========================================
    // Hold/Park Transaction Methods
    // =========================================
    
    /**
     * Hold (park) a transaction for later
     * 
     * @param int $transactionId Transaction ID
     * @param string $note Optional note
     * @return array Updated transaction
     */
    public function holdTransaction(int $transactionId, string $note = ''): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการ', 404);
        }
        
        if ($transaction['status'] !== 'draft') {
            throw new Exception('สามารถพักได้เฉพาะรายการที่ยังไม่ชำระ', 400);
        }
        
        if (empty($transaction['items'])) {
            throw new Exception('ไม่มีสินค้าในตะกร้า', 400);
        }
        
        $stmt = $this->db->prepare("
            UPDATE pos_transactions 
            SET status = 'hold', hold_note = ?, hold_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$note, $transactionId]);
        
        return $this->getTransaction($transactionId);
    }
    
    /**
     * Get all held transactions
     * 
     * @param int|null $shiftId Filter by shift
     * @return array Held transactions
     */
    public function getHeldTransactions(?int $shiftId = null): array {
        $sql = "
            SELECT t.*, 
                   u.display_name as customer_name,
                   c.display_name as cashier_name,
                   (SELECT COUNT(*) FROM pos_transaction_items WHERE transaction_id = t.id) as item_count
            FROM pos_transactions t
            LEFT JOIN users u ON t.customer_id = u.id
            LEFT JOIN admin_users c ON t.cashier_id = c.id
            WHERE t.line_account_id = ? AND t.status = 'hold'
        ";
        $params = [$this->lineAccountId];
        
        if ($shiftId) {
            $sql .= " AND t.shift_id = ?";
            $params[] = $shiftId;
        }
        
        $sql .= " ORDER BY t.hold_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Recall a held transaction
     * 
     * @param int $transactionId Transaction ID
     * @return array Recalled transaction
     */
    public function recallTransaction(int $transactionId): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการ', 404);
        }
        
        if ($transaction['status'] !== 'hold') {
            throw new Exception('รายการนี้ไม่ได้ถูกพักไว้', 400);
        }
        
        $stmt = $this->db->prepare("
            UPDATE pos_transactions 
            SET status = 'draft', hold_note = NULL, hold_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$transactionId]);
        
        return $this->getTransaction($transactionId);
    }
    
    /**
     * Delete a held transaction
     * 
     * @param int $transactionId Transaction ID
     * @return bool Success
     */
    public function deleteHeldTransaction(int $transactionId): bool {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการ', 404);
        }
        
        if ($transaction['status'] !== 'hold') {
            throw new Exception('สามารถลบได้เฉพาะรายการที่พักไว้', 400);
        }
        
        $this->db->beginTransaction();
        try {
            // Delete items first
            $stmt = $this->db->prepare("DELETE FROM pos_transaction_items WHERE transaction_id = ?");
            $stmt->execute([$transactionId]);
            
            // Delete transaction
            $stmt = $this->db->prepare("DELETE FROM pos_transactions WHERE id = ?");
            $stmt->execute([$transactionId]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    // =========================================
    // Price Override Methods
    // =========================================
    
    /**
     * Override item price (requires authorization)
     * 
     * @param int $itemId Item ID
     * @param float $newPrice New price
     * @param string $reason Reason for override
     * @param int|null $authorizedBy Manager ID
     * @return array Updated item
     */
    public function overrideItemPrice(int $itemId, float $newPrice, string $reason, ?int $authorizedBy = null): array {
        $item = $this->getCartItemById($itemId);
        if (!$item) {
            throw new Exception('ไม่พบรายการสินค้า', 404);
        }
        
        if ($newPrice < 0) {
            throw new Exception('ราคาต้องไม่ติดลบ', 400);
        }
        
        if (empty($reason)) {
            throw new Exception('กรุณาระบุเหตุผลในการแก้ไขราคา', 400);
        }
        
        $originalPrice = $item['unit_price'];
        $lineTotal = $newPrice * $item['quantity'];
        
        $stmt = $this->db->prepare("
            UPDATE pos_transaction_items 
            SET unit_price = ?, 
                original_price = ?,
                line_total = ?,
                price_override_reason = ?,
                price_override_by = ?,
                price_override_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $newPrice, 
            $originalPrice,
            $lineTotal,
            $reason,
            $authorizedBy,
            $itemId
        ]);
        
        // Recalculate totals
        $this->calculateTotals($item['transaction_id']);
        
        return $this->getCartItemById($itemId);
    }
    
    // =========================================
    // Cash Drawer Operations
    // =========================================
    
    /**
     * Record cash in/out (not from sales)
     * 
     * @param int $shiftId Shift ID
     * @param string $type 'in' or 'out'
     * @param float $amount Amount
     * @param string $reason Reason
     * @param int $userId User ID
     * @return array Created record
     */
    public function recordCashMovement(int $shiftId, string $type, float $amount, string $reason, int $userId): array {
        if (!in_array($type, ['in', 'out'])) {
            throw new Exception('ประเภทไม่ถูกต้อง', 400);
        }
        
        if ($amount <= 0) {
            throw new Exception('จำนวนเงินต้องมากกว่า 0', 400);
        }
        
        if (empty($reason)) {
            throw new Exception('กรุณาระบุเหตุผล', 400);
        }
        
        // Check shift exists and is open
        $stmt = $this->db->prepare("SELECT * FROM pos_shifts WHERE id = ? AND status = 'open'");
        $stmt->execute([$shiftId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shift) {
            throw new Exception('ไม่พบกะที่เปิดอยู่', 404);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO pos_cash_movements 
            (line_account_id, shift_id, movement_type, amount, reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $shiftId,
            $type,
            $amount,
            $reason,
            $userId
        ]);
        
        $movementId = (int)$this->db->lastInsertId();
        
        // Update shift cash balance
        $adjustAmount = $type === 'in' ? $amount : -$amount;
        $stmt = $this->db->prepare("
            UPDATE pos_shifts 
            SET cash_adjustments = COALESCE(cash_adjustments, 0) + ?
            WHERE id = ?
        ");
        $stmt->execute([$adjustAmount, $shiftId]);
        
        return $this->getCashMovement($movementId);
    }
    
    /**
     * Get cash movement by ID
     */
    public function getCashMovement(int $movementId): ?array {
        $stmt = $this->db->prepare("
            SELECT cm.*, a.display_name as created_by_name
            FROM pos_cash_movements cm
            LEFT JOIN admin_users a ON cm.created_by = a.id
            WHERE cm.id = ?
        ");
        $stmt->execute([$movementId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get cash movements for shift
     */
    public function getCashMovements(int $shiftId): array {
        $stmt = $this->db->prepare("
            SELECT cm.*, a.display_name as created_by_name
            FROM pos_cash_movements cm
            LEFT JOIN admin_users a ON cm.created_by = a.id
            WHERE cm.shift_id = ?
            ORDER BY cm.created_at DESC
        ");
        $stmt->execute([$shiftId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // =========================================
    // Reprint Receipt
    // =========================================
    
    /**
     * Get transaction for reprint
     * 
     * @param string $transactionNumber Transaction number
     * @return array|null Transaction data
     */
    public function findTransactionByNumber(string $transactionNumber): ?array {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   u.display_name as customer_name,
                   c.display_name as cashier_name
            FROM pos_transactions t
            LEFT JOIN users u ON t.customer_id = u.id
            LEFT JOIN admin_users c ON t.cashier_id = c.id
            WHERE t.transaction_number = ? AND t.line_account_id = ?
        ");
        $stmt->execute([$transactionNumber, $this->lineAccountId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($transaction) {
            $transaction['items'] = $this->getTransactionItems($transaction['id']);
            
            // Get payments
            $stmt = $this->db->prepare("SELECT * FROM pos_payments WHERE transaction_id = ?");
            $stmt->execute([$transaction['id']]);
            $transaction['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $transaction ?: null;
    }
    
    /**
     * Log receipt reprint
     */
    public function logReceiptReprint(int $transactionId, int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE pos_transactions 
            SET reprint_count = COALESCE(reprint_count, 0) + 1,
                last_reprint_at = NOW(),
                last_reprint_by = ?
            WHERE id = ?
        ");
        $stmt->execute([$userId, $transactionId]);
    }
}
