<?php
/**
 * WMSService - Warehouse Management System Service
 * Pick-Pack-Ship operations for order fulfillment
 */

class WMSService {
    private $db;
    private $lineAccountId;
    
    // WMS Status constants
    const STATUS_PENDING_PICK = 'pending_pick';
    const STATUS_PICKING = 'picking';
    const STATUS_PICKED = 'picked';
    const STATUS_PACKING = 'packing';
    const STATUS_PACKED = 'packed';
    const STATUS_READY_TO_SHIP = 'ready_to_ship';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_ON_HOLD = 'on_hold';
    
    // WMS to Customer Status Mapping (Requirements 7.2, 7.3, 7.4)
    // Maps internal WMS status to customer-facing status
    const WMS_TO_CUSTOMER_STATUS_MAP = [
        'pending_pick' => 'confirmed',      // Order confirmed, waiting to be picked
        'picking' => 'processing',          // Requirements 7.2: picking → processing
        'picked' => 'processing',           // Still processing until packed
        'packing' => 'processing',          // Still processing until packed
        'packed' => 'ready_to_ship',        // Requirements 7.3: packed → ready_to_ship
        'ready_to_ship' => 'ready_to_ship', // Ready for carrier pickup
        'shipped' => 'shipping',            // Requirements 7.4: shipped → shipping
        'on_hold' => 'on_hold'              // Order has issues
    ];
    
    // Batch status constants
    const BATCH_PENDING = 'pending';
    const BATCH_IN_PROGRESS = 'in_progress';
    const BATCH_COMPLETED = 'completed';
    const BATCH_CANCELLED = 'cancelled';
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Set line account ID
     */
    public function setLineAccountId(int $lineAccountId): void {
        $this->lineAccountId = $lineAccountId;
    }
    
    // =============================================
    // PICK OPERATIONS (Requirements 1.1-1.6)
    // =============================================
    
    /**
     * Get orders in pick queue
     * Returns orders with status 'confirmed' or 'paid' and wms_status 'pending_pick'
     * Sorted by created_at ascending (oldest first)
     * Requirements: 1.1, 1.2
     * 
     * @param array $filters Optional filters (limit, offset)
     * @return array List of orders ready for picking
     */
    public function getPickQueue(array $filters = []): array {
        $sql = "SELECT t.*, 
                       (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) as item_count,
                       (SELECT SUM(quantity) FROM transaction_items WHERE transaction_id = t.id) as total_quantity,
                       u.display_name as customer_name
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE t.wms_status = ?
                AND t.status IN ('confirmed', 'paid')";
        
        $params = [self::STATUS_PENDING_PICK];
        
        if ($this->lineAccountId) {
            $sql .= " AND t.line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        // Sort by created_at ascending (oldest first) - Requirements 1.2
        $sql .= " ORDER BY t.created_at ASC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Start picking an order
     * Changes order status to 'picking' and records picker assignment
     * Requirements: 1.3
     * 
     * @param int $orderId Order ID
     * @param int $pickerId Picker staff ID
     * @return bool Success
     * @throws Exception if order not found or invalid status
     */
    public function startPicking(int $orderId, int $pickerId): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        // Verify order is in pending_pick status
        if ($order['wms_status'] !== self::STATUS_PENDING_PICK) {
            throw new Exception("Order is not in pending_pick status. Current status: " . ($order['wms_status'] ?? 'null'));
        }
        
        // Verify order status is confirmed or paid
        if (!in_array($order['status'], ['confirmed', 'paid'])) {
            throw new Exception("Order status must be confirmed or paid");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update order status to picking
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET wms_status = ?, picker_id = ?, pick_started_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([self::STATUS_PICKING, $pickerId, $orderId]);
            
            // Initialize pick items for this order
            $this->initializePickItems($orderId);
            
            // Log activity
            $this->logActivity($orderId, 'pick_started', null, $pickerId, 
                "Picking started by staff #{$pickerId}");
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Confirm an item has been picked
     * Marks the item as picked and updates progress
     * Requirements: 1.5
     * 
     * @param int $orderId Order ID
     * @param int $itemId Transaction item ID
     * @param int|null $quantityPicked Quantity picked (defaults to required quantity)
     * @return bool Success
     * @throws Exception if item not found or already picked
     */
    public function confirmItemPicked(int $orderId, int $itemId, ?int $quantityPicked = null): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify order is in picking status
        if ($order['wms_status'] !== self::STATUS_PICKING) {
            throw new Exception("Order is not in picking status");
        }
        
        // Get pick item
        $stmt = $this->db->prepare("
            SELECT * FROM wms_pick_items 
            WHERE order_id = ? AND transaction_item_id = ?
        ");
        $stmt->execute([$orderId, $itemId]);
        $pickItem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pickItem) {
            throw new Exception("Pick item not found");
        }
        
        if ($pickItem['status'] === 'picked') {
            throw new Exception("Item already picked");
        }
        
        // Default to required quantity if not specified
        if ($quantityPicked === null) {
            $quantityPicked = $pickItem['quantity_required'];
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update pick item
            $stmt = $this->db->prepare("
                UPDATE wms_pick_items 
                SET status = 'picked', 
                    quantity_picked = ?,
                    picked_by = ?,
                    picked_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$quantityPicked, $order['picker_id'], $pickItem['id']]);
            
            // Log activity
            $this->logActivity($orderId, 'item_picked', $itemId, $order['picker_id'],
                "Item picked: qty {$quantityPicked}",
                ['quantity_picked' => $quantityPicked, 'quantity_required' => $pickItem['quantity_required']]);
            
            $this->db->commit();
            
            // Check if all items are picked and auto-complete if so
            $this->checkAndAutoCompletePicking($orderId);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Complete picking for an order
     * Changes order status to 'picked' and moves to pack queue
     * Requirements: 1.6
     * 
     * @param int $orderId Order ID
     * @return bool Success
     * @throws Exception if order not found or not all items picked
     */
    public function completePicking(int $orderId): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        // Verify order is in picking status
        if ($order['wms_status'] !== self::STATUS_PICKING) {
            throw new Exception("Order is not in picking status");
        }
        
        // Check all items are picked
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM wms_pick_items 
            WHERE order_id = ? AND status NOT IN ('picked', 'short', 'damaged')
        ");
        $stmt->execute([$orderId]);
        $unpickedCount = (int)$stmt->fetchColumn();
        
        if ($unpickedCount > 0) {
            throw new Exception("Not all items have been picked. {$unpickedCount} items remaining.");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update order status to picked
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET wms_status = ?, pick_completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([self::STATUS_PICKED, $orderId]);
            
            // Log activity
            $this->logActivity($orderId, 'pick_completed', null, $order['picker_id'],
                "Picking completed");
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get pick list items for an order
     * Returns product name, SKU, quantity, and storage location
     * Requirements: 1.4
     * 
     * @param int $orderId Order ID
     * @return array Pick list items
     */
    public function getPickList(int $orderId): array {
        // Auto-initialize pick items if not exists
        $this->initializePickItems($orderId);
        
        $stmt = $this->db->prepare("
            SELECT 
                wpi.id as pick_item_id,
                wpi.transaction_item_id,
                wpi.product_id,
                wpi.quantity_required,
                wpi.quantity_picked,
                wpi.status as pick_status,
                wpi.picked_at,
                ti.product_name,
                ti.product_sku,
                ti.product_price,
                NULL as storage_location
            FROM wms_pick_items wpi
            JOIN transaction_items ti ON wpi.transaction_item_id = ti.id
            LEFT JOIN business_items bi ON wpi.product_id = bi.id
            WHERE wpi.order_id = ?
            ORDER BY ti.product_name ASC
        ");
        $stmt->execute([$orderId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if all items are picked and auto-complete picking
     * Requirements: 1.6
     * 
     * @param int $orderId Order ID
     */
    private function checkAndAutoCompletePicking(int $orderId): void {
        // Check if all items are picked (or marked as short/damaged)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM wms_pick_items 
            WHERE order_id = ? AND status = 'pending'
        ");
        $stmt->execute([$orderId]);
        $pendingCount = (int)$stmt->fetchColumn();
        
        if ($pendingCount === 0) {
            // All items processed, auto-complete picking
            try {
                $this->completePicking($orderId);
            } catch (Exception $e) {
                // Log but don't throw - this is an auto-complete attempt
                error_log("Auto-complete picking failed for order {$orderId}: " . $e->getMessage());
            }
        }
    }
    
    // =============================================
    // BATCH PICK OPERATIONS (Requirements 2.1-2.4)
    // =============================================
    
    /**
     * Create a batch pick from multiple orders
     * Combines items from multiple orders into a consolidated pick list
     * Requirements: 2.1, 2.2
     * 
     * @param array $orderIds Array of order IDs to include in batch
     * @return int Batch ID
     * @throws Exception if orders are invalid or already in a batch
     */
    public function createBatchPick(array $orderIds): int {
        if (empty($orderIds)) {
            throw new Exception("No orders provided for batch pick");
        }
        
        // Validate all orders exist and are in pending_pick status
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $params = $orderIds;
        
        $sql = "SELECT id, wms_status FROM transactions WHERE id IN ({$placeholders})";
        if ($this->lineAccountId) {
            $sql .= " AND line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($orders) !== count($orderIds)) {
            throw new Exception("Some orders not found or not accessible");
        }
        
        // Check all orders are in pending_pick status
        foreach ($orders as $order) {
            if ($order['wms_status'] !== self::STATUS_PENDING_PICK) {
                throw new Exception("Order #{$order['id']} is not in pending_pick status");
            }
        }
        
        // Check orders are not already in another batch
        $stmt = $this->db->prepare("
            SELECT bpo.order_id 
            FROM wms_batch_pick_orders bpo
            JOIN wms_batch_picks bp ON bpo.batch_id = bp.id
            WHERE bpo.order_id IN ({$placeholders})
            AND bp.status IN ('pending', 'in_progress')
        ");
        $stmt->execute($orderIds);
        $existingOrders = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($existingOrders)) {
            throw new Exception("Orders already in active batch: " . implode(', ', $existingOrders));
        }
        
        $this->db->beginTransaction();
        
        try {
            // Generate batch number
            $batchNumber = $this->generateBatchNumber();
            
            // Calculate totals
            $totalOrders = count($orderIds);
            $totalItems = $this->calculateTotalItems($orderIds);
            
            // Create batch record
            $stmt = $this->db->prepare("
                INSERT INTO wms_batch_picks 
                (line_account_id, batch_number, status, total_orders, total_items, created_at)
                VALUES (?, ?, 'pending', ?, ?, NOW())
            ");
            $stmt->execute([
                $this->lineAccountId,
                $batchNumber,
                $totalOrders,
                $totalItems
            ]);
            
            $batchId = (int)$this->db->lastInsertId();
            
            // Add orders to batch
            $stmt = $this->db->prepare("
                INSERT INTO wms_batch_pick_orders (batch_id, order_id, pick_status, created_at)
                VALUES (?, ?, 'pending', NOW())
            ");
            
            foreach ($orderIds as $orderId) {
                $stmt->execute([$batchId, $orderId]);
            }
            
            // Log activity for each order
            foreach ($orderIds as $orderId) {
                $this->logActivity($orderId, 'pick_started', null, null, 
                    "Added to batch {$batchNumber}", 
                    ['batch_id' => $batchId, 'batch_number' => $batchNumber]
                );
            }
            
            $this->db->commit();
            
            return $batchId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get consolidated batch pick list
     * Groups items by product to minimize walking distance
     * Requirements: 2.2, 2.4
     * 
     * @param int $batchId Batch ID
     * @return array Consolidated pick list with product grouping
     */
    public function getBatchPickList(int $batchId): array {
        // Get batch info
        $stmt = $this->db->prepare("
            SELECT bp.*, 
                   (SELECT COUNT(*) FROM wms_batch_pick_orders WHERE batch_id = bp.id) as order_count
            FROM wms_batch_picks bp
            WHERE bp.id = ?
        ");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            throw new Exception("Batch not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $batch['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this batch");
        }
        
        // Get orders in batch
        $stmt = $this->db->prepare("
            SELECT bpo.order_id, bpo.pick_status, bpo.picked_at,
                   t.order_number, t.shipping_name, t.wms_status
            FROM wms_batch_pick_orders bpo
            JOIN transactions t ON bpo.order_id = t.id
            WHERE bpo.batch_id = ?
            ORDER BY t.created_at ASC
        ");
        $stmt->execute([$batchId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all items from all orders, grouped by product
        $orderIds = array_column($orders, 'order_id');
        
        if (empty($orderIds)) {
            return [
                'batch' => $batch,
                'orders' => [],
                'consolidated_items' => [],
                'order_items' => []
            ];
        }
        
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        // Get consolidated items (grouped by product)
        // Note: storage_location may be NULL if business_items doesn't have location column
        $stmt = $this->db->prepare("
            SELECT 
                ti.product_id,
                ti.product_name,
                ti.product_sku,
                NULL as storage_location,
                SUM(ti.quantity) as total_quantity,
                COUNT(DISTINCT ti.transaction_id) as order_count,
                GROUP_CONCAT(DISTINCT ti.transaction_id) as order_ids
            FROM transaction_items ti
            LEFT JOIN business_items bi ON ti.product_id = bi.id
            WHERE ti.transaction_id IN ({$placeholders})
            GROUP BY ti.product_id, ti.product_name, ti.product_sku
            ORDER BY ti.product_name ASC
        ");
        $stmt->execute($orderIds);
        $consolidatedItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process consolidated items to include order breakdown
        foreach ($consolidatedItems as &$item) {
            $item['order_ids'] = explode(',', $item['order_ids']);
            $item['order_breakdown'] = $this->getItemOrderBreakdown(
                $item['product_id'], 
                $item['order_ids']
            );
        }
        
        // Get items per order (for distribution after picking)
        $stmt = $this->db->prepare("
            SELECT 
                ti.transaction_id as order_id,
                ti.id as item_id,
                ti.product_id,
                ti.product_name,
                ti.product_sku,
                ti.quantity,
                COALESCE(wpi.status, 'pending') as pick_status,
                COALESCE(wpi.quantity_picked, 0) as quantity_picked
            FROM transaction_items ti
            LEFT JOIN wms_pick_items wpi ON ti.id = wpi.transaction_item_id
            WHERE ti.transaction_id IN ({$placeholders})
            ORDER BY ti.transaction_id, ti.product_name
        ");
        $stmt->execute($orderIds);
        $allItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group items by order
        $orderItems = [];
        foreach ($allItems as $item) {
            $orderId = $item['order_id'];
            if (!isset($orderItems[$orderId])) {
                $orderItems[$orderId] = [];
            }
            $orderItems[$orderId][] = $item;
        }
        
        return [
            'batch' => $batch,
            'orders' => $orders,
            'consolidated_items' => $consolidatedItems,
            'order_items' => $orderItems
        ];
    }
    
    /**
     * Complete batch pick - distribute items back to individual orders
     * Requirements: 2.3
     * 
     * @param int $batchId Batch ID
     * @return bool Success
     */
    public function completeBatchPick(int $batchId): bool {
        // Get batch info
        $stmt = $this->db->prepare("SELECT * FROM wms_batch_picks WHERE id = ?");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            throw new Exception("Batch not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $batch['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this batch");
        }
        
        if ($batch['status'] === self::BATCH_COMPLETED) {
            throw new Exception("Batch already completed");
        }
        
        if ($batch['status'] === self::BATCH_CANCELLED) {
            throw new Exception("Cannot complete cancelled batch");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Get all orders in batch
            $stmt = $this->db->prepare("
                SELECT bpo.order_id, bpo.pick_status
                FROM wms_batch_pick_orders bpo
                WHERE bpo.batch_id = ?
            ");
            $stmt->execute([$batchId]);
            $batchOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Update each order's status to 'picked'
            foreach ($batchOrders as $batchOrder) {
                $orderId = $batchOrder['order_id'];
                
                // Update order WMS status
                $stmt = $this->db->prepare("
                    UPDATE transactions 
                    SET wms_status = ?, pick_completed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([self::STATUS_PICKED, $orderId]);
                
                // Update batch order status
                $stmt = $this->db->prepare("
                    UPDATE wms_batch_pick_orders 
                    SET pick_status = 'picked', picked_at = NOW()
                    WHERE batch_id = ? AND order_id = ?
                ");
                $stmt->execute([$batchId, $orderId]);
                
                // Log activity
                $this->logActivity($orderId, 'pick_completed', null, $batch['picker_id'],
                    "Batch pick completed: {$batch['batch_number']}",
                    ['batch_id' => $batchId]
                );
            }
            
            // Update batch status
            $stmt = $this->db->prepare("
                UPDATE wms_batch_picks 
                SET status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$batchId]);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Start batch picking - assign picker and change status
     * 
     * @param int $batchId Batch ID
     * @param int $pickerId Picker staff ID
     * @return bool Success
     */
    public function startBatchPick(int $batchId, int $pickerId): bool {
        $stmt = $this->db->prepare("SELECT * FROM wms_batch_picks WHERE id = ?");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            throw new Exception("Batch not found");
        }
        
        if ($batch['status'] !== self::BATCH_PENDING) {
            throw new Exception("Batch is not in pending status");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update batch
            $stmt = $this->db->prepare("
                UPDATE wms_batch_picks 
                SET status = 'in_progress', picker_id = ?, started_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$pickerId, $batchId]);
            
            // Update all orders in batch to 'picking' status
            $stmt = $this->db->prepare("
                SELECT order_id FROM wms_batch_pick_orders WHERE batch_id = ?
            ");
            $stmt->execute([$batchId]);
            $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($orderIds as $orderId) {
                $stmt = $this->db->prepare("
                    UPDATE transactions 
                    SET wms_status = ?, picker_id = ?, pick_started_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([self::STATUS_PICKING, $pickerId, $orderId]);
                
                // Initialize pick items for this order
                $this->initializePickItems($orderId);
            }
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Cancel a batch pick
     * 
     * @param int $batchId Batch ID
     * @return bool Success
     */
    public function cancelBatchPick(int $batchId): bool {
        $stmt = $this->db->prepare("SELECT * FROM wms_batch_picks WHERE id = ?");
        $stmt->execute([$batchId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            throw new Exception("Batch not found");
        }
        
        if ($batch['status'] === self::BATCH_COMPLETED) {
            throw new Exception("Cannot cancel completed batch");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Reset orders back to pending_pick
            $stmt = $this->db->prepare("
                SELECT order_id FROM wms_batch_pick_orders WHERE batch_id = ?
            ");
            $stmt->execute([$batchId]);
            $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($orderIds as $orderId) {
                $stmt = $this->db->prepare("
                    UPDATE transactions 
                    SET wms_status = ?, picker_id = NULL, pick_started_at = NULL
                    WHERE id = ?
                ");
                $stmt->execute([self::STATUS_PENDING_PICK, $orderId]);
            }
            
            // Update batch status
            $stmt = $this->db->prepare("
                UPDATE wms_batch_picks SET status = 'cancelled' WHERE id = ?
            ");
            $stmt->execute([$batchId]);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get list of batches
     * 
     * @param array $filters Optional filters (status, date_from, date_to)
     * @return array List of batches
     */
    public function getBatches(array $filters = []): array {
        $sql = "SELECT bp.*, 
                       au.username as picker_name
                FROM wms_batch_picks bp
                LEFT JOIN admin_users au ON bp.picker_id = au.id
                WHERE 1=1";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND bp.line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND bp.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(bp.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(bp.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY bp.created_at DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // =============================================
    // PACK OPERATIONS (Requirements 3.1-3.5)
    // =============================================
    
    /**
     * Get orders in pack queue
     * Returns orders with wms_status 'picked' ready for packing
     * Requirements: 3.1
     * 
     * @param array $filters Optional filters (limit, offset)
     * @return array List of orders ready for packing
     */
    public function getPackQueue(array $filters = []): array {
        $sql = "SELECT t.*, 
                       (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) as item_count,
                       (SELECT SUM(quantity) FROM transaction_items WHERE transaction_id = t.id) as total_quantity,
                       u.display_name as customer_name,
                       au.username as picker_name
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.id
                LEFT JOIN admin_users au ON t.picker_id = au.id
                WHERE t.wms_status = ?";
        
        $params = [self::STATUS_PICKED];
        
        if ($this->lineAccountId) {
            $sql .= " AND t.line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY t.pick_completed_at ASC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Start packing an order
     * Changes order status to 'packing' and records packer assignment
     * Requirements: 3.2
     * 
     * @param int $orderId Order ID
     * @param int $packerId Packer staff ID
     * @return bool Success
     * @throws Exception if order not found or invalid status
     */
    public function startPacking(int $orderId, int $packerId): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        // Verify order is in picked status
        if ($order['wms_status'] !== self::STATUS_PICKED) {
            throw new Exception("Order is not in picked status. Current status: " . ($order['wms_status'] ?? 'null'));
        }
        
        // Verify all items are picked (Requirements 3.2)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM wms_pick_items 
            WHERE order_id = ? AND status NOT IN ('picked', 'short', 'damaged')
        ");
        $stmt->execute([$orderId]);
        $unpickedCount = (int)$stmt->fetchColumn();
        
        if ($unpickedCount > 0) {
            throw new Exception("Not all items are picked. Cannot start packing.");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update order status to packing
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET wms_status = ?, packer_id = ?, pack_started_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([self::STATUS_PACKING, $packerId, $orderId]);
            
            // Log activity
            $this->logActivity($orderId, 'pack_started', null, $packerId, 
                "Packing started by staff #{$packerId}");
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Complete packing for an order
     * Changes order status to 'packed' and records package info
     * Requirements: 3.3, 3.5
     * 
     * @param int $orderId Order ID
     * @param array|null $packageInfo Optional package info (weight, dimensions)
     * @return bool Success
     * @throws Exception if order not found or invalid status
     */
    public function completePacking(int $orderId, ?array $packageInfo = null): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        // Verify order is in packing status
        if ($order['wms_status'] !== self::STATUS_PACKING) {
            throw new Exception("Order is not in packing status. Current status: " . ($order['wms_status'] ?? 'null'));
        }
        
        $this->db->beginTransaction();
        
        try {
            // Build update query with optional package info
            $updateFields = ['wms_status = ?', 'pack_completed_at = NOW()'];
            $params = [self::STATUS_PACKED];
            
            if ($packageInfo) {
                if (isset($packageInfo['weight'])) {
                    $updateFields[] = 'package_weight = ?';
                    $params[] = $packageInfo['weight'];
                }
                if (isset($packageInfo['dimensions'])) {
                    $updateFields[] = 'package_dimensions = ?';
                    $params[] = $packageInfo['dimensions'];
                }
            }
            
            $params[] = $orderId;
            
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $stmt->execute($params);
            
            // Update customer-facing status to ready_to_ship (Requirements 7.3)
            $stmt = $this->db->prepare("
                UPDATE transactions SET status = 'ready_to_ship' WHERE id = ?
            ");
            $stmt->execute([$orderId]);
            
            // Log activity
            $this->logActivity($orderId, 'pack_completed', null, $order['packer_id'],
                "Packing completed",
                $packageInfo);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    // =============================================
    // SHIP OPERATIONS (Requirements 5.1-5.4)
    // =============================================
    
    /**
     * Get orders in ship queue
     * Returns orders with wms_status 'packed' or 'ready_to_ship'
     * Requirements: 5.1
     * 
     * @param array $filters Optional filters (limit, offset)
     * @return array List of orders ready for shipping
     */
    public function getShipQueue(array $filters = []): array {
        $sql = "SELECT t.*, 
                       (SELECT COUNT(*) FROM transaction_items WHERE transaction_id = t.id) as item_count,
                       u.display_name as customer_name,
                       picker.username as picker_name,
                       packer.username as packer_name
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.id
                LEFT JOIN admin_users picker ON t.picker_id = picker.id
                LEFT JOIN admin_users packer ON t.packer_id = packer.id
                WHERE t.wms_status IN (?, ?)";
        
        $params = [self::STATUS_PACKED, self::STATUS_READY_TO_SHIP];
        
        if ($this->lineAccountId) {
            $sql .= " AND t.line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY t.pack_completed_at ASC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Assign carrier and tracking number to an order
     * Requirements: 5.1, 5.2
     * 
     * @param int $orderId Order ID
     * @param string $carrier Carrier name (Kerry, Flash, J&T, Thailand Post, etc.)
     * @param string $trackingNumber Tracking number
     * @param bool $sendNotification Whether to send LINE notification (default: true)
     * @return bool Success
     * @throws Exception if order not found or invalid status
     */
    public function assignCarrier(int $orderId, string $carrier, string $trackingNumber, bool $sendNotification = true): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        // Verify order is in packed or ready_to_ship status
        if (!in_array($order['wms_status'], [self::STATUS_PACKED, self::STATUS_READY_TO_SHIP])) {
            throw new Exception("Order is not ready for shipping. Current status: " . ($order['wms_status'] ?? 'null'));
        }
        
        // Validate tracking number format (basic validation)
        $trackingNumber = trim($trackingNumber);
        if (empty($trackingNumber)) {
            throw new Exception("Tracking number is required");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update order with carrier and tracking
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET carrier = ?, shipping_tracking = ?, wms_status = ?, shipped_at = NOW(), status = 'shipping'
                WHERE id = ?
            ");
            $stmt->execute([$carrier, $trackingNumber, self::STATUS_SHIPPED, $orderId]);
            
            // Log activity
            $this->logActivity($orderId, 'shipped', null, null,
                "Shipped via {$carrier}, tracking: {$trackingNumber}",
                ['carrier' => $carrier, 'tracking_number' => $trackingNumber]);
            
            $this->db->commit();
            
            // Send LINE notification to customer (Requirements 5.3, 7.5)
            if ($sendNotification) {
                try {
                    $this->sendShippedNotification($orderId, $trackingNumber, $carrier);
                } catch (Exception $e) {
                    // Log but don't fail the operation if notification fails
                    error_log("WMSService::assignCarrier - Failed to send notification: " . $e->getMessage());
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Confirm order has been shipped
     * Changes order status to 'shipped'
     * Requirements: 5.3
     * 
     * @param int $orderId Order ID
     * @param bool $sendNotification Whether to send LINE notification (default: true)
     * @return bool Success
     * @throws Exception if order not found or missing tracking
     */
    public function confirmShipped(int $orderId, bool $sendNotification = true): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        // Verify order has tracking number
        if (empty($order['tracking_number'])) {
            throw new Exception("Order must have tracking number before confirming shipment");
        }
        
        // If already shipped, return success
        if ($order['wms_status'] === self::STATUS_SHIPPED) {
            return true;
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update order status
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET wms_status = ?, shipped_at = NOW(), status = 'shipping'
                WHERE id = ?
            ");
            $stmt->execute([self::STATUS_SHIPPED, $orderId]);
            
            // Log activity
            $this->logActivity($orderId, 'shipped', null, null, "Shipment confirmed");
            
            $this->db->commit();
            
            // Send LINE notification to customer (Requirements 7.5)
            if ($sendNotification) {
                try {
                    $this->sendShippedNotification($orderId, $order['tracking_number'], $order['carrier']);
                } catch (Exception $e) {
                    // Log but don't fail the operation if notification fails
                    error_log("WMSService::confirmShipped - Failed to send notification: " . $e->getMessage());
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    // =============================================
    // DASHBOARD OPERATIONS (Requirements 6.1-6.4)
    // =============================================
    
    /**
     * Get WMS dashboard statistics
     * Returns counts for each status and today's metrics
     * Requirements: 6.1, 6.2
     * 
     * @return array Dashboard statistics
     */
    public function getDashboardStats(): array {
        $params = [];
        $lineAccountCondition = '';
        
        if ($this->lineAccountId) {
            $lineAccountCondition = ' AND line_account_id = ?';
            $params[] = $this->lineAccountId;
        }
        
        // Get counts by WMS status
        $stmt = $this->db->prepare("
            SELECT wms_status, COUNT(*) as count
            FROM transactions
            WHERE wms_status IS NOT NULL {$lineAccountCondition}
            GROUP BY wms_status
        ");
        $stmt->execute($params);
        $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Ensure all statuses have a count
        $allStatuses = [
            self::STATUS_PENDING_PICK,
            self::STATUS_PICKING,
            self::STATUS_PICKED,
            self::STATUS_PACKING,
            self::STATUS_PACKED,
            self::STATUS_READY_TO_SHIP,
            self::STATUS_SHIPPED,
            self::STATUS_ON_HOLD
        ];
        
        foreach ($allStatuses as $status) {
            if (!isset($statusCounts[$status])) {
                $statusCounts[$status] = 0;
            }
        }
        
        // Get today's metrics
        $todayStart = date('Y-m-d 00:00:00');
        $todayParams = $this->lineAccountId ? [$this->lineAccountId, $todayStart] : [$todayStart];
        
        // Orders shipped today
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM transactions
            WHERE wms_status = 'shipped' 
            AND shipped_at >= ? {$lineAccountCondition}
        ");
        $stmt->execute(array_reverse($todayParams));
        $shippedToday = (int)$stmt->fetchColumn();
        
        // Orders picked today
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM transactions
            WHERE pick_completed_at >= ? {$lineAccountCondition}
        ");
        $stmt->execute(array_reverse($todayParams));
        $pickedToday = (int)$stmt->fetchColumn();
        
        // Orders packed today
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM transactions
            WHERE pack_completed_at >= ? {$lineAccountCondition}
        ");
        $stmt->execute(array_reverse($todayParams));
        $packedToday = (int)$stmt->fetchColumn();
        
        // Average fulfillment time (pick to ship) for orders shipped today
        $stmt = $this->db->prepare("
            SELECT AVG(TIMESTAMPDIFF(MINUTE, pick_started_at, shipped_at)) as avg_minutes
            FROM transactions
            WHERE wms_status = 'shipped' 
            AND shipped_at >= ?
            AND pick_started_at IS NOT NULL
            {$lineAccountCondition}
        ");
        $stmt->execute(array_reverse($todayParams));
        $avgFulfillmentMinutes = $stmt->fetchColumn();
        
        return [
            'status_counts' => $statusCounts,
            'today' => [
                'shipped' => $shippedToday,
                'picked' => $pickedToday,
                'packed' => $packedToday,
                'avg_fulfillment_minutes' => $avgFulfillmentMinutes ? round($avgFulfillmentMinutes, 1) : null
            ],
            'totals' => [
                'in_progress' => ($statusCounts[self::STATUS_PENDING_PICK] ?? 0) + 
                                 ($statusCounts[self::STATUS_PICKING] ?? 0) + 
                                 ($statusCounts[self::STATUS_PICKED] ?? 0) + 
                                 ($statusCounts[self::STATUS_PACKING] ?? 0) + 
                                 ($statusCounts[self::STATUS_PACKED] ?? 0) + 
                                 ($statusCounts[self::STATUS_READY_TO_SHIP] ?? 0),
                'on_hold' => $statusCounts[self::STATUS_ON_HOLD] ?? 0
            ]
        ];
    }
    
    /**
     * Get overdue orders (not shipped within SLA)
     * Requirements: 6.3
     * 
     * @param int $slaHours SLA hours (default 24)
     * @return array List of overdue orders
     */
    public function getOverdueOrders(int $slaHours = 24): array {
        $sql = "SELECT t.*, 
                       TIMESTAMPDIFF(HOUR, t.created_at, NOW()) as hours_since_order,
                       u.display_name as customer_name
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE t.wms_status IS NOT NULL
                AND t.wms_status NOT IN (?, ?)
                AND t.created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)";
        
        $params = [self::STATUS_SHIPPED, self::STATUS_ON_HOLD, $slaHours];
        
        if ($this->lineAccountId) {
            $sql .= " AND t.line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY t.created_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // =============================================
    // EXCEPTION HANDLING (Requirements 9.1-9.5)
    // =============================================
    
    /**
     * Mark an item as short (out of stock during picking)
     * Requirements: 9.1
     * 
     * @param int $orderId Order ID
     * @param int $itemId Transaction item ID
     * @param string $reason Reason for shortage
     * @return bool Success
     */
    public function markItemShort(int $orderId, int $itemId, string $reason): bool {
        // Get pick item
        $stmt = $this->db->prepare("
            SELECT * FROM wms_pick_items 
            WHERE order_id = ? AND transaction_item_id = ?
        ");
        $stmt->execute([$orderId, $itemId]);
        $pickItem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pickItem) {
            throw new Exception("Pick item not found");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update pick item status
            $stmt = $this->db->prepare("
                UPDATE wms_pick_items 
                SET status = 'short', notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $pickItem['id']]);
            
            // Update order exception field
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET wms_exception = ?
                WHERE id = ?
            ");
            $stmt->execute(["Item short: {$reason}", $orderId]);
            
            // Log activity
            $this->logActivity($orderId, 'item_short', $itemId, null, $reason,
                ['product_id' => $pickItem['product_id'], 'quantity_required' => $pickItem['quantity_required']]);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Mark an item as damaged
     * Requirements: 9.1
     * 
     * @param int $orderId Order ID
     * @param int $itemId Transaction item ID
     * @param string $reason Reason/description of damage
     * @return bool Success
     */
    public function markItemDamaged(int $orderId, int $itemId, string $reason): bool {
        // Get pick item
        $stmt = $this->db->prepare("
            SELECT * FROM wms_pick_items 
            WHERE order_id = ? AND transaction_item_id = ?
        ");
        $stmt->execute([$orderId, $itemId]);
        $pickItem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pickItem) {
            throw new Exception("Pick item not found");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update pick item status
            $stmt = $this->db->prepare("
                UPDATE wms_pick_items 
                SET status = 'damaged', notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $pickItem['id']]);
            
            // Update order exception field
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET wms_exception = ?
                WHERE id = ?
            ");
            $stmt->execute(["Item damaged: {$reason}", $orderId]);
            
            // Log activity
            $this->logActivity($orderId, 'item_damaged', $itemId, null, $reason,
                ['product_id' => $pickItem['product_id'], 'quantity_required' => $pickItem['quantity_required']]);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Put an order on hold
     * Requirements: 9.2
     * 
     * @param int $orderId Order ID
     * @param string $reason Reason for hold
     * @return bool Success
     */
    public function putOrderOnHold(int $orderId, string $reason): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Store previous status for potential restoration
            $previousStatus = $order['wms_status'];
            
            // Update order status
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET wms_status = ?, wms_exception = ?
                WHERE id = ?
            ");
            $stmt->execute([self::STATUS_ON_HOLD, $reason, $orderId]);
            
            // Log activity
            $this->logActivity($orderId, 'on_hold', null, null, $reason,
                ['previous_status' => $previousStatus]);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Resolve an exception on an order
     * Requirements: 9.5
     * 
     * @param int $orderId Order ID
     * @param string $resolution Resolution description
     * @param int $staffId Staff member who resolved
     * @param string|null $newStatus Optional new WMS status to set
     * @return bool Success
     */
    public function resolveException(int $orderId, string $resolution, int $staffId, ?string $newStatus = null): bool {
        // Get order
        $stmt = $this->db->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        // Verify line account access
        if ($this->lineAccountId && $order['line_account_id'] != $this->lineAccountId) {
            throw new Exception("Access denied to this order");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Build update query
            $updateFields = [
                'wms_exception = NULL',
                'wms_exception_resolved_at = NOW()',
                'wms_exception_resolved_by = ?'
            ];
            $params = [$staffId];
            
            // If new status provided, update it
            if ($newStatus) {
                $updateFields[] = 'wms_status = ?';
                $params[] = $newStatus;
            } elseif ($order['wms_status'] === self::STATUS_ON_HOLD) {
                // Default: move back to pending_pick if was on hold
                $updateFields[] = 'wms_status = ?';
                $params[] = self::STATUS_PENDING_PICK;
            }
            
            $params[] = $orderId;
            
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $stmt->execute($params);
            
            // Log activity
            $this->logActivity($orderId, 'exception_resolved', null, $staffId, $resolution,
                ['previous_exception' => $order['wms_exception'], 'new_status' => $newStatus]);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get orders with exceptions
     * Requirements: 9.4
     * 
     * @return array List of orders with exceptions
     */
    public function getExceptionOrders(): array {
        $sql = "SELECT t.*, 
                       u.display_name as customer_name,
                       TIMESTAMPDIFF(HOUR, t.created_at, NOW()) as hours_since_order
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE (t.wms_status = ? OR t.wms_exception IS NOT NULL)";
        
        $params = [self::STATUS_ON_HOLD];
        
        if ($this->lineAccountId) {
            $sql .= " AND t.line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY t.created_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // =============================================
    // HELPER METHODS
    // =============================================
    
    // =============================================
    // STATUS MAPPING (Requirements 7.1-7.4)
    // =============================================
    
    /**
     * Get customer-facing status from WMS status
     * Requirements: 7.2, 7.3, 7.4
     * 
     * @param string $wmsStatus WMS internal status
     * @return string Customer-facing status
     */
    public static function getCustomerStatus(string $wmsStatus): string {
        return self::WMS_TO_CUSTOMER_STATUS_MAP[$wmsStatus] ?? 'processing';
    }
    
    /**
     * Map WMS status to customer status
     * Returns the mapping array for reference
     * 
     * @return array Status mapping array
     */
    public static function getStatusMapping(): array {
        return self::WMS_TO_CUSTOMER_STATUS_MAP;
    }
    
    /**
     * Synchronize customer-facing status based on WMS status
     * Requirements: 7.1
     * 
     * @param int $orderId Order ID
     * @param string $wmsStatus WMS status
     * @return bool Success
     */
    public function syncCustomerStatus(int $orderId, string $wmsStatus): bool {
        $customerStatus = self::getCustomerStatus($wmsStatus);
        
        $stmt = $this->db->prepare("
            UPDATE transactions 
            SET status = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([$customerStatus, $orderId]);
    }
    
    /**
     * Update WMS status and sync customer status
     * This is the main method to use when changing WMS status
     * Requirements: 7.1, 7.2, 7.3, 7.4
     * 
     * @param int $orderId Order ID
     * @param string $newWmsStatus New WMS status
     * @param array $additionalFields Additional fields to update (e.g., tracking_number, carrier)
     * @return bool Success
     */
    public function updateWmsStatusWithSync(int $orderId, string $newWmsStatus, array $additionalFields = []): bool {
        $this->db->beginTransaction();
        
        try {
            // Build update query for WMS status
            $updateFields = ['wms_status = ?'];
            $params = [$newWmsStatus];
            
            // Add timestamp fields based on status
            switch ($newWmsStatus) {
                case self::STATUS_PICKING:
                    $updateFields[] = 'pick_started_at = COALESCE(pick_started_at, NOW())';
                    break;
                case self::STATUS_PICKED:
                    $updateFields[] = 'pick_completed_at = NOW()';
                    break;
                case self::STATUS_PACKING:
                    $updateFields[] = 'pack_started_at = COALESCE(pack_started_at, NOW())';
                    break;
                case self::STATUS_PACKED:
                    $updateFields[] = 'pack_completed_at = NOW()';
                    break;
                case self::STATUS_SHIPPED:
                    $updateFields[] = 'shipped_at = NOW()';
                    break;
            }
            
            // Add any additional fields
            foreach ($additionalFields as $field => $value) {
                $updateFields[] = "{$field} = ?";
                $params[] = $value;
            }
            
            $params[] = $orderId;
            
            // Update WMS status
            $stmt = $this->db->prepare("
                UPDATE transactions 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $stmt->execute($params);
            
            // Sync customer-facing status
            $this->syncCustomerStatus($orderId, $newWmsStatus);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get order with both WMS and customer status
     * 
     * @param int $orderId Order ID
     * @return array|null Order data with status info
     */
    public function getOrderWithStatus(int $orderId): ?array {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   u.display_name as customer_name,
                   u.line_user_id
            FROM transactions t
            LEFT JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            return null;
        }
        
        // Add computed customer status based on WMS status
        if ($order['wms_status']) {
            $order['computed_customer_status'] = self::getCustomerStatus($order['wms_status']);
        }
        
        return $order;
    }
    
    /**
     * Validate status transition
     * Returns true if the transition is valid
     * 
     * @param string|null $currentStatus Current WMS status
     * @param string $newStatus New WMS status
     * @return bool Whether transition is valid
     */
    public function isValidStatusTransition(?string $currentStatus, string $newStatus): bool {
        // Define valid transitions
        $validTransitions = [
            null => [self::STATUS_PENDING_PICK],
            self::STATUS_PENDING_PICK => [self::STATUS_PICKING, self::STATUS_ON_HOLD],
            self::STATUS_PICKING => [self::STATUS_PICKED, self::STATUS_ON_HOLD],
            self::STATUS_PICKED => [self::STATUS_PACKING, self::STATUS_ON_HOLD],
            self::STATUS_PACKING => [self::STATUS_PACKED, self::STATUS_ON_HOLD],
            self::STATUS_PACKED => [self::STATUS_READY_TO_SHIP, self::STATUS_SHIPPED, self::STATUS_ON_HOLD],
            self::STATUS_READY_TO_SHIP => [self::STATUS_SHIPPED, self::STATUS_ON_HOLD],
            self::STATUS_SHIPPED => [], // Terminal state
            self::STATUS_ON_HOLD => [self::STATUS_PENDING_PICK, self::STATUS_PICKING, self::STATUS_PICKED, self::STATUS_PACKING]
        ];
        
        $allowedTransitions = $validTransitions[$currentStatus] ?? [];
        return in_array($newStatus, $allowedTransitions);
    }
    
    /**
     * Get allowed next statuses for an order
     * 
     * @param string|null $currentStatus Current WMS status
     * @return array List of allowed next statuses
     */
    public function getAllowedNextStatuses(?string $currentStatus): array {
        $validTransitions = [
            null => [self::STATUS_PENDING_PICK],
            self::STATUS_PENDING_PICK => [self::STATUS_PICKING, self::STATUS_ON_HOLD],
            self::STATUS_PICKING => [self::STATUS_PICKED, self::STATUS_ON_HOLD],
            self::STATUS_PICKED => [self::STATUS_PACKING, self::STATUS_ON_HOLD],
            self::STATUS_PACKING => [self::STATUS_PACKED, self::STATUS_ON_HOLD],
            self::STATUS_PACKED => [self::STATUS_READY_TO_SHIP, self::STATUS_SHIPPED, self::STATUS_ON_HOLD],
            self::STATUS_READY_TO_SHIP => [self::STATUS_SHIPPED, self::STATUS_ON_HOLD],
            self::STATUS_SHIPPED => [],
            self::STATUS_ON_HOLD => [self::STATUS_PENDING_PICK, self::STATUS_PICKING, self::STATUS_PICKED, self::STATUS_PACKING]
        ];
        
        return $validTransitions[$currentStatus] ?? [];
    }
    
    // =============================================
    // LINE NOTIFICATION (Requirements 7.5)
    // =============================================
    
    /**
     * Send LINE notification to customer when order ships
     * Requirements: 5.3, 7.5
     * 
     * @param int $orderId Order ID
     * @param string|null $trackingNumber Tracking number (optional)
     * @param string|null $carrier Carrier name (optional)
     * @return array Result with success status and message
     */
    public function sendShippedNotification(int $orderId, ?string $trackingNumber = null, ?string $carrier = null): array {
        try {
            // Get order with user info
            $stmt = $this->db->prepare("
                SELECT t.*, 
                       t.order_number,
                       t.tracking_number,
                       t.carrier,
                       u.line_user_id,
                       u.display_name as customer_name,
                       u.reply_token,
                       u.reply_token_expires,
                       la.channel_access_token,
                       la.channel_secret
                FROM transactions t
                JOIN users u ON t.user_id = u.id
                LEFT JOIN line_accounts la ON t.line_account_id = la.id
                WHERE t.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                return ['success' => false, 'message' => 'Order not found'];
            }
            
            if (!$order['line_user_id']) {
                return ['success' => false, 'message' => 'Customer has no LINE user ID'];
            }
            
            if (!$order['channel_access_token']) {
                return ['success' => false, 'message' => 'LINE account not configured'];
            }
            
            // Use provided values or fall back to order values
            $trackingNumber = $trackingNumber ?? $order['tracking_number'];
            $carrier = $carrier ?? $order['carrier'];
            
            // Build notification message
            $message = $this->buildShippedNotificationMessage($order, $trackingNumber, $carrier);
            
            // Send via LINE API
            require_once __DIR__ . '/LineAPI.php';
            $line = new LineAPI($order['channel_access_token'], $order['channel_secret']);
            
            // Try to use sendMessage (which tries reply token first) or fallback to pushMessage
            if (method_exists($line, 'sendMessage')) {
                $result = $line->sendMessage(
                    $order['line_user_id'],
                    [$message],
                    $order['reply_token'] ?? null,
                    $order['reply_token_expires'] ?? null,
                    $this->db
                );
            } else {
                $result = $line->pushMessage($order['line_user_id'], [$message]);
            }
            
            // Log the notification
            $this->logActivity($orderId, 'notification_sent', null, null, 
                "Shipped notification sent to customer",
                ['tracking_number' => $trackingNumber, 'carrier' => $carrier, 'result_code' => $result['code'] ?? null]
            );
            
            if (($result['code'] ?? 0) === 200) {
                return ['success' => true, 'message' => 'Notification sent successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to send notification', 'error' => $result['body'] ?? null];
            }
            
        } catch (Exception $e) {
            error_log("WMSService::sendShippedNotification error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Build Flex Message for shipped notification
     * 
     * @param array $order Order data
     * @param string|null $trackingNumber Tracking number
     * @param string|null $carrier Carrier name
     * @return array Flex message structure
     */
    private function buildShippedNotificationMessage(array $order, ?string $trackingNumber, ?string $carrier): array {
        $orderNumber = $order['order_number'] ?? "#{$order['id']}";
        $customerName = $order['customer_name'] ?? 'ลูกค้า';
        
        // Build tracking URL based on carrier
        $trackingUrl = $this->getCarrierTrackingUrl($carrier, $trackingNumber);
        
        // Build body contents
        $bodyContents = [
            [
                'type' => 'text',
                'text' => '📦 คำสั่งซื้อของคุณถูกจัดส่งแล้ว!',
                'weight' => 'bold',
                'size' => 'lg',
                'color' => '#1DB446',
                'wrap' => true
            ],
            [
                'type' => 'separator',
                'margin' => 'lg'
            ],
            [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'lg',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => 'เลขที่คำสั่งซื้อ',
                                'size' => 'sm',
                                'color' => '#555555',
                                'flex' => 0
                            ],
                            [
                                'type' => 'text',
                                'text' => $orderNumber,
                                'size' => 'sm',
                                'color' => '#111111',
                                'align' => 'end',
                                'weight' => 'bold'
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        // Add carrier info if available
        if ($carrier) {
            $bodyContents[2]['contents'][] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'ขนส่ง',
                        'size' => 'sm',
                        'color' => '#555555',
                        'flex' => 0
                    ],
                    [
                        'type' => 'text',
                        'text' => $carrier,
                        'size' => 'sm',
                        'color' => '#111111',
                        'align' => 'end'
                    ]
                ]
            ];
        }
        
        // Add tracking number if available
        if ($trackingNumber) {
            $bodyContents[2]['contents'][] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'เลขพัสดุ',
                        'size' => 'sm',
                        'color' => '#555555',
                        'flex' => 0
                    ],
                    [
                        'type' => 'text',
                        'text' => $trackingNumber,
                        'size' => 'sm',
                        'color' => '#1DB446',
                        'align' => 'end',
                        'weight' => 'bold'
                    ]
                ]
            ];
        }
        
        // Build footer with tracking button if URL available
        $footer = null;
        if ($trackingUrl) {
            $footer = [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => '🔍 ติดตามพัสดุ',
                            'uri' => $trackingUrl
                        ],
                        'color' => '#1DB446'
                    ]
                ],
                'flex' => 0
            ];
        }
        
        // Build the Flex message
        $flexContent = [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '🚚 แจ้งจัดส่งสินค้า',
                        'color' => '#ffffff',
                        'size' => 'md',
                        'weight' => 'bold'
                    ]
                ],
                'backgroundColor' => '#1DB446',
                'paddingAll' => 'md'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => $bodyContents
            ]
        ];
        
        if ($footer) {
            $flexContent['footer'] = $footer;
        }
        
        return [
            'type' => 'flex',
            'altText' => "📦 คำสั่งซื้อ {$orderNumber} ถูกจัดส่งแล้ว" . ($trackingNumber ? " เลขพัสดุ: {$trackingNumber}" : ""),
            'contents' => $flexContent
        ];
    }
    
    /**
     * Get carrier tracking URL
     * 
     * @param string|null $carrier Carrier name
     * @param string|null $trackingNumber Tracking number
     * @return string|null Tracking URL or null
     */
    private function getCarrierTrackingUrl(?string $carrier, ?string $trackingNumber): ?string {
        if (!$trackingNumber) {
            return null;
        }
        
        $carrier = strtolower($carrier ?? '');
        
        // Map carriers to their tracking URLs
        $trackingUrls = [
            'kerry' => "https://th.kerryexpress.com/th/track/?track={$trackingNumber}",
            'kerry express' => "https://th.kerryexpress.com/th/track/?track={$trackingNumber}",
            'flash' => "https://www.flashexpress.co.th/tracking/?se={$trackingNumber}",
            'flash express' => "https://www.flashexpress.co.th/tracking/?se={$trackingNumber}",
            'j&t' => "https://www.jtexpress.co.th/index/query/gzquery.html?billcode={$trackingNumber}",
            'j&t express' => "https://www.jtexpress.co.th/index/query/gzquery.html?billcode={$trackingNumber}",
            'jt' => "https://www.jtexpress.co.th/index/query/gzquery.html?billcode={$trackingNumber}",
            'thailand post' => "https://track.thailandpost.co.th/?trackNumber={$trackingNumber}",
            'ไปรษณีย์ไทย' => "https://track.thailandpost.co.th/?trackNumber={$trackingNumber}",
            'ems' => "https://track.thailandpost.co.th/?trackNumber={$trackingNumber}",
            'dhl' => "https://www.dhl.com/th-th/home/tracking.html?tracking-id={$trackingNumber}",
            'scg express' => "https://www.scgexpress.co.th/tracking?tracking_no={$trackingNumber}",
            'scg' => "https://www.scgexpress.co.th/tracking?tracking_no={$trackingNumber}",
            'ninja van' => "https://www.ninjavan.co/th-th/tracking?id={$trackingNumber}",
            'ninjavan' => "https://www.ninjavan.co/th-th/tracking?id={$trackingNumber}",
            'best express' => "https://www.best-inc.co.th/track?bills={$trackingNumber}",
            'best' => "https://www.best-inc.co.th/track?bills={$trackingNumber}",
            'shopee express' => "https://spx.co.th/tracking?id={$trackingNumber}",
            'spx' => "https://spx.co.th/tracking?id={$trackingNumber}",
            'lazada express' => "https://www.lazada.co.th/order-tracking/?tradeOrderId={$trackingNumber}",
            'lex' => "https://www.lazada.co.th/order-tracking/?tradeOrderId={$trackingNumber}"
        ];
        
        return $trackingUrls[$carrier] ?? null;
    }
    
    /**
     * Send status change notification to customer
     * Generic method for any status change notification
     * Requirements: 7.5
     * 
     * @param int $orderId Order ID
     * @param string $newStatus New WMS status
     * @return array Result with success status
     */
    public function sendStatusChangeNotification(int $orderId, string $newStatus): array {
        // Only send notification for shipped status (as per Requirements 7.5)
        // Other status changes can be added here if needed
        if ($newStatus === self::STATUS_SHIPPED) {
            return $this->sendShippedNotification($orderId);
        }
        
        // For other statuses, we don't send notifications by default
        return ['success' => true, 'message' => 'No notification required for this status'];
    }
    
    /**
     * Generate batch number
     */
    private function generateBatchNumber(): string {
        $date = date('Ymd');
        
        $stmt = $this->db->prepare("
            SELECT batch_number FROM wms_batch_picks 
            WHERE batch_number LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(["BATCH-{$date}-%"]);
        $last = $stmt->fetchColumn();
        
        if ($last) {
            $seq = (int)substr($last, -4) + 1;
        } else {
            $seq = 1;
        }
        
        return sprintf("BATCH-%s-%04d", $date, $seq);
    }
    
    /**
     * Calculate total items across orders
     */
    private function calculateTotalItems(array $orderIds): int {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(quantity), 0) 
            FROM transaction_items 
            WHERE transaction_id IN ({$placeholders})
        ");
        $stmt->execute($orderIds);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get item breakdown by order
     */
    private function getItemOrderBreakdown(int $productId, array $orderIds): array {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $params = array_merge([$productId], $orderIds);
        
        $stmt = $this->db->prepare("
            SELECT ti.transaction_id as order_id, t.order_number, ti.quantity
            FROM transaction_items ti
            JOIN transactions t ON ti.transaction_id = t.id
            WHERE ti.product_id = ? AND ti.transaction_id IN ({$placeholders})
        ");
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Initialize pick items for an order
     */
    private function initializePickItems(int $orderId): void {
        // Check if already initialized
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM wms_pick_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        
        if ($stmt->fetchColumn() > 0) {
            return; // Already initialized
        }
        
        // Get order items
        $stmt = $this->db->prepare("
            SELECT id, product_id, quantity 
            FROM transaction_items 
            WHERE transaction_id = ?
        ");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create pick items
        $stmt = $this->db->prepare("
            INSERT INTO wms_pick_items 
            (order_id, transaction_item_id, product_id, quantity_required, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        
        foreach ($items as $item) {
            $stmt->execute([
                $orderId,
                $item['id'],
                $item['product_id'],
                $item['quantity']
            ]);
        }
    }
    
    /**
     * Log WMS activity
     */
    private function logActivity(int $orderId, string $action, ?int $itemId = null, ?int $staffId = null, ?string $notes = null, ?array $metadata = null): void {
        $stmt = $this->db->prepare("
            INSERT INTO wms_activity_logs 
            (line_account_id, order_id, action, item_id, staff_id, notes, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $this->lineAccountId,
            $orderId,
            $action,
            $itemId,
            $staffId,
            $notes,
            $metadata ? json_encode($metadata) : null
        ]);
    }
    
    // =============================================
    // DATA EXPORT (Requirements 10.1, 10.3, 10.4)
    // =============================================
    
    /**
     * Export fulfillment data to JSON with all status timestamps
     * Requirements: 10.1
     * 
     * @param array $filters Optional filters (date_from, date_to, status, order_ids)
     * @return array JSON-serializable fulfillment data
     */
    public function exportFulfillmentDataJson(array $filters = []): array {
        $orders = $this->getFulfillmentOrders($filters);
        
        $exportData = [
            'export_date' => date('Y-m-d H:i:s'),
            'export_type' => 'fulfillment_data',
            'line_account_id' => $this->lineAccountId,
            'total_orders' => count($orders),
            'filters_applied' => $filters,
            'orders' => []
        ];
        
        foreach ($orders as $order) {
            $orderData = [
                'order_id' => (int)$order['id'],
                'order_number' => $order['order_number'],
                'customer' => [
                    'name' => $order['customer_name'] ?? $order['shipping_name'],
                    'phone' => $order['shipping_phone'] ?? null,
                    'address' => $order['shipping_address'] ?? null
                ],
                'status' => [
                    'wms_status' => $order['wms_status'],
                    'customer_status' => $order['status'],
                    'exception' => $order['wms_exception'] ?? null
                ],
                'timestamps' => [
                    'created_at' => $order['created_at'],
                    'pick_started_at' => $order['pick_started_at'],
                    'pick_completed_at' => $order['pick_completed_at'],
                    'pack_started_at' => $order['pack_started_at'],
                    'pack_completed_at' => $order['pack_completed_at'],
                    'shipped_at' => $order['shipped_at']
                ],
                'shipping' => [
                    'carrier' => $order['carrier'],
                    'tracking_number' => $order['tracking_number'],
                    'package_weight' => $order['package_weight'] ? (float)$order['package_weight'] : null,
                    'package_dimensions' => $order['package_dimensions']
                ],
                'staff' => [
                    'picker_id' => $order['picker_id'] ? (int)$order['picker_id'] : null,
                    'picker_name' => $order['picker_name'] ?? null,
                    'packer_id' => $order['packer_id'] ? (int)$order['packer_id'] : null,
                    'packer_name' => $order['packer_name'] ?? null
                ],
                'items' => $this->getOrderItemsForExport((int)$order['id']),
                'totals' => [
                    'subtotal' => (float)($order['subtotal'] ?? 0),
                    'shipping_fee' => (float)($order['shipping_fee'] ?? 0),
                    'discount' => (float)($order['discount'] ?? 0),
                    'total' => (float)($order['total_amount'] ?? 0)
                ]
            ];
            
            $exportData['orders'][] = $orderData;
        }
        
        return $exportData;
    }
    
    /**
     * Export shipping data to CSV for carriers
     * Requirements: 10.4
     * 
     * @param array $filters Optional filters (date_from, date_to, carrier, status)
     * @return string CSV content
     */
    public function exportCarrierCsv(array $filters = []): string {
        // Default to packed/ready_to_ship orders if no status filter
        if (empty($filters['status'])) {
            $filters['status'] = [self::STATUS_PACKED, self::STATUS_READY_TO_SHIP, self::STATUS_SHIPPED];
        }
        
        $orders = $this->getFulfillmentOrders($filters);
        
        // CSV headers - standard carrier format
        $headers = [
            'order_number',
            'recipient_name',
            'recipient_phone',
            'recipient_address',
            'recipient_province',
            'recipient_postal_code',
            'package_weight_kg',
            'package_dimensions',
            'cod_amount',
            'item_description',
            'item_quantity',
            'carrier',
            'tracking_number',
            'shipped_date',
            'notes'
        ];
        
        // Start CSV output
        $output = fopen('php://temp', 'r+');
        
        // Write BOM for UTF-8 Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");
        
        // Write headers
        fputcsv($output, $headers);
        
        // Write data rows
        foreach ($orders as $order) {
            // Get items summary
            $items = $this->getOrderItemsForExport((int)$order['id']);
            $itemDescriptions = [];
            $totalQuantity = 0;
            
            foreach ($items as $item) {
                $itemDescriptions[] = $item['product_name'] . ' x' . $item['quantity'];
                $totalQuantity += $item['quantity'];
            }
            
            // Parse address for province and postal code if possible
            $address = $order['shipping_address'] ?? '';
            $province = '';
            $postalCode = '';
            
            // Try to extract postal code (5 digits in Thai addresses)
            if (preg_match('/(\d{5})/', $address, $matches)) {
                $postalCode = $matches[1];
            }
            
            $row = [
                $order['order_number'],
                $order['shipping_name'] ?? $order['customer_name'] ?? '',
                $order['shipping_phone'] ?? '',
                $address,
                $province,
                $postalCode,
                $order['package_weight'] ?? '',
                $order['package_dimensions'] ?? '',
                $order['payment_method'] === 'cod' ? ($order['total_amount'] ?? 0) : 0,
                implode(', ', $itemDescriptions),
                $totalQuantity,
                $order['carrier'] ?? '',
                $order['tracking_number'] ?? '',
                $order['shipped_at'] ? date('Y-m-d', strtotime($order['shipped_at'])) : '',
                $order['wms_exception'] ?? ''
            ];
            
            fputcsv($output, $row);
        }
        
        // Get CSV content
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Get fulfillment orders for export
     * 
     * @param array $filters Filters (date_from, date_to, status, order_ids, carrier)
     * @return array Orders data
     */
    private function getFulfillmentOrders(array $filters = []): array {
        $sql = "SELECT t.*, 
                       u.display_name as customer_name,
                       picker.username as picker_name,
                       packer.username as packer_name
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.id
                LEFT JOIN admin_users picker ON t.picker_id = picker.id
                LEFT JOIN admin_users packer ON t.packer_id = packer.id
                WHERE t.wms_status IS NOT NULL";
        
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND t.line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        // Filter by date range
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(t.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(t.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        // Filter by status
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = implode(',', array_fill(0, count($filters['status']), '?'));
                $sql .= " AND t.wms_status IN ({$placeholders})";
                $params = array_merge($params, $filters['status']);
            } else {
                $sql .= " AND t.wms_status = ?";
                $params[] = $filters['status'];
            }
        }
        
        // Filter by specific order IDs
        if (!empty($filters['order_ids'])) {
            $placeholders = implode(',', array_fill(0, count($filters['order_ids']), '?'));
            $sql .= " AND t.id IN ({$placeholders})";
            $params = array_merge($params, $filters['order_ids']);
        }
        
        // Filter by carrier
        if (!empty($filters['carrier'])) {
            $sql .= " AND t.carrier = ?";
            $params[] = $filters['carrier'];
        }
        
        $sql .= " ORDER BY t.created_at DESC";
        
        // Limit results if specified
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get order items for export
     * 
     * @param int $orderId Order ID
     * @return array Items data
     */
    private function getOrderItemsForExport(int $orderId): array {
        $stmt = $this->db->prepare("
            SELECT 
                ti.id as item_id,
                ti.product_id,
                ti.product_name,
                ti.product_sku,
                ti.quantity,
                ti.unit_price,
                (ti.quantity * ti.unit_price) as line_total,
                COALESCE(wpi.status, 'pending') as pick_status,
                COALESCE(wpi.quantity_picked, 0) as quantity_picked
            FROM transaction_items ti
            LEFT JOIN wms_pick_items wpi ON ti.id = wpi.transaction_item_id
            WHERE ti.transaction_id = ?
            ORDER BY ti.id
        ");
        $stmt->execute([$orderId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Deserialize JSON fulfillment data back to array
     * Used for round-trip validation
     * Requirements: 10.3
     * 
     * @param string $jsonData JSON string
     * @return array Deserialized data
     * @throws Exception if JSON is invalid
     */
    public function deserializeFulfillmentData(string $jsonData): array {
        $data = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON: " . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Serialize fulfillment data to JSON string
     * Requirements: 10.1, 10.3
     * 
     * @param array $data Fulfillment data array
     * @return string JSON string
     */
    public function serializeFulfillmentData(array $data): string {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
