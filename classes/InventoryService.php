<?php
/**
 * InventoryService - จัดการ Stock และ Stock Movement
 */

class InventoryService {
    private $db;
    private $lineAccountId;
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * Get product stock
     */
    public function getProductStock(int $productId): int {
        $stmt = $this->db->prepare("SELECT COALESCE(stock, 0) FROM business_items WHERE id = ?");
        $stmt->execute([$productId]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Update product stock
     * 
     * @param int $productId Product ID
     * @param int $quantity Quantity change (positive for in, negative for out)
     * @param string $type Movement type (goods_receive, disposal, adjustment_in, adjustment_out, sale)
     * @param string|null $refType Reference type (goods_receive, batch_disposal, adjustment, order)
     * @param int|null $refId Reference ID
     * @param string|null $refNumber Reference number
     * @param string|null $notes Notes
     * @param int|null $createdBy User ID who created the movement
     * @param float|null $unitCost Unit cost for value tracking (Requirements: 6.3)
     * @return bool
     */
    public function updateStock(int $productId, int $quantity, string $type, string $refType = null, int $refId = null, string $refNumber = null, string $notes = null, int $createdBy = null, ?float $unitCost = null): bool {
        $stockBefore = $this->getProductStock($productId);
        $stockAfter = $stockBefore + $quantity;
        
        if ($stockAfter < 0) {
            throw new Exception("Stock cannot be negative");
        }
        
        // Update product stock
        $stmt = $this->db->prepare("UPDATE business_items SET stock = ? WHERE id = ?");
        $stmt->execute([$stockAfter, $productId]);
        
        // Calculate value_change = quantity × unit_cost (Requirements: 6.3)
        $valueChange = null;
        if ($unitCost !== null) {
            $valueChange = $quantity * $unitCost;
        }
        
        // Create movement record with value tracking
        $stmt = $this->db->prepare("
            INSERT INTO stock_movements 
            (line_account_id, product_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, reference_number, notes, created_by, unit_cost, value_change)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId, $productId, $type, $quantity, 
            $stockBefore, $stockAfter, $refType, $refId, $refNumber, $notes, $createdBy,
            $unitCost, $valueChange
        ]);
        
        return true;
    }
    
    /**
     * Get available columns from business_items
     */
    private function getBusinessItemsColumns(): array {
        static $cols = null;
        if ($cols === null) {
            try {
                $cols = $this->db->query("SHOW COLUMNS FROM business_items")->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                $cols = [];
            }
        }
        return $cols;
    }
    
    /**
     * Get low stock products
     */
    public function getLowStockProducts(): array {
        $cols = $this->getBusinessItemsColumns();
        $hasMinStock = in_array('min_stock', $cols);
        $hasReorderPoint = in_array('reorder_point', $cols);
        $hasCostPrice = in_array('cost_price', $cols);
        
        $threshold = $hasReorderPoint ? 'COALESCE(reorder_point, 5)' : ($hasMinStock ? 'COALESCE(min_stock, 5)' : '5');
        
        $sql = "SELECT id, name, sku, stock, " . 
               ($hasMinStock ? "min_stock, " : "5 as min_stock, ") .
               ($hasReorderPoint ? "reorder_point, " : "5 as reorder_point, ") .
               ($hasCostPrice ? "cost_price " : "0 as cost_price ") .
               "FROM business_items 
                WHERE is_active = 1 
                AND stock <= {$threshold}";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY stock ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get stock movements for a product
     */
    public function getStockMovements(array $filters = []): array {
        $sql = "SELECT sm.*, bi.name as product_name, bi.sku 
                FROM stock_movements sm
                LEFT JOIN business_items bi ON sm.product_id = bi.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($filters['product_id'])) {
            $sql .= " AND sm.product_id = ?";
            $params[] = $filters['product_id'];
        }
        
        if ($this->lineAccountId) {
            $sql .= " AND (sm.line_account_id = ? OR sm.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['movement_type'])) {
            $sql .= " AND sm.movement_type = ?";
            $params[] = $filters['movement_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(sm.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(sm.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY sm.created_at DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Create stock adjustment
     */
    public function createAdjustment(array $data): array {
        $productId = (int)$data['product_id'];
        $type = $data['adjustment_type']; // increase or decrease
        $quantity = (int)$data['quantity'];
        $reason = $data['reason'];
        $reasonDetail = $data['reason_detail'] ?? null;
        $createdBy = $data['created_by'] ?? null;
        
        $stockBefore = $this->getProductStock($productId);
        $stockAfter = $type === 'increase' ? $stockBefore + $quantity : $stockBefore - $quantity;
        
        if ($stockAfter < 0) {
            throw new Exception("Stock cannot be negative after adjustment");
        }
        
        // Generate adjustment number
        $adjNumber = $this->generateDocNumber('ADJ');
        
        // Create adjustment record
        $stmt = $this->db->prepare("
            INSERT INTO stock_adjustments 
            (line_account_id, adjustment_number, adjustment_type, product_id, quantity, reason, reason_detail, stock_before, stock_after, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)
        ");
        $stmt->execute([
            $this->lineAccountId, $adjNumber, $type, $productId, $quantity, 
            $reason, $reasonDetail, $stockBefore, $stockAfter, $createdBy
        ]);
        
        $adjustmentId = $this->db->lastInsertId();
        
        return [
            'id' => $adjustmentId,
            'adjustment_number' => $adjNumber,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter
        ];
    }
    
    /**
     * Confirm stock adjustment
     */
    public function confirmAdjustment(int $adjustmentId): bool {
        // Get adjustment
        $stmt = $this->db->prepare("SELECT * FROM stock_adjustments WHERE id = ? AND status = 'draft'");
        $stmt->execute([$adjustmentId]);
        $adj = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$adj) {
            throw new Exception("Adjustment not found or already processed");
        }
        
        // Update stock
        $movementType = $adj['adjustment_type'] === 'increase' ? 'adjustment_in' : 'adjustment_out';
        $quantity = $adj['adjustment_type'] === 'increase' ? $adj['quantity'] : -$adj['quantity'];
        
        $this->updateStock(
            $adj['product_id'], 
            $quantity, 
            $movementType, 
            'adjustment', 
            $adjustmentId, 
            $adj['adjustment_number'],
            $adj['reason'] . ($adj['reason_detail'] ? ': ' . $adj['reason_detail'] : ''),
            $adj['created_by']
        );
        
        // Update adjustment status
        $stmt = $this->db->prepare("UPDATE stock_adjustments SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
        $stmt->execute([$adjustmentId]);
        
        return true;
    }
    
    /**
     * Generate document number
     */
    public function generateDocNumber(string $prefix): string {
        $date = date('Ymd');
        $table = match($prefix) {
            'PO' => 'purchase_orders',
            'GR' => 'goods_receives',
            'ADJ' => 'stock_adjustments',
            default => 'stock_movements'
        };
        $column = match($prefix) {
            'PO' => 'po_number',
            'GR' => 'gr_number',
            'ADJ' => 'adjustment_number',
            default => 'reference_number'
        };
        
        // Get last number for today
        $stmt = $this->db->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(["{$prefix}-{$date}-%"]);
        $last = $stmt->fetchColumn();
        
        if ($last) {
            $seq = (int)substr($last, -4) + 1;
        } else {
            $seq = 1;
        }
        
        return sprintf("%s-%s-%04d", $prefix, $date, $seq);
    }
    
    /**
     * Get stock valuation
     */
    public function getStockValuation(): array {
        $cols = $this->getBusinessItemsColumns();
        $hasCostPrice = in_array('cost_price', $cols);
        
        $costPriceCol = $hasCostPrice ? "cost_price" : "0";
        $valueCalc = $hasCostPrice ? "(stock * COALESCE(cost_price, 0))" : "0";
        
        $sql = "SELECT id, name, sku, stock, {$costPriceCol} as cost_price, {$valueCalc} as value
                FROM business_items 
                WHERE is_active = 1";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY value DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalValue = array_sum(array_column($products, 'value'));
        $totalItems = array_sum(array_column($products, 'stock'));
        
        return [
            'products' => $products,
            'total_value' => $totalValue,
            'total_items' => $totalItems,
            'product_count' => count($products)
        ];
    }
    
    /**
     * Get adjustments list
     */
    public function getAdjustments(array $filters = []): array {
        $sql = "SELECT sa.*, bi.name as product_name, bi.sku
                FROM stock_adjustments sa
                LEFT JOIN business_items bi ON sa.product_id = bi.id
                WHERE 1=1";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (sa.line_account_id = ? OR sa.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND sa.status = ?";
            $params[] = $filters['status'];
        }
        
        $sql .= " ORDER BY sa.created_at DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get low stock products with supplier info
     */
    public function getLowStockProductsWithSupplier(): array {
        $cols = $this->getBusinessItemsColumns();
        $hasMinStock = in_array('min_stock', $cols);
        $hasReorderPoint = in_array('reorder_point', $cols);
        $hasCostPrice = in_array('cost_price', $cols);
        $hasSupplierId = in_array('supplier_id', $cols);
        
        $threshold = $hasReorderPoint ? 'COALESCE(bi.reorder_point, 5)' : ($hasMinStock ? 'COALESCE(bi.min_stock, 5)' : '5');
        
        $sql = "SELECT bi.id, bi.name, bi.sku, bi.stock, " . 
               ($hasMinStock ? "bi.min_stock, " : "5 as min_stock, ") .
               ($hasReorderPoint ? "bi.reorder_point, " : "5 as reorder_point, ") .
               ($hasCostPrice ? "bi.cost_price, " : "0 as cost_price, ") .
               ($hasSupplierId ? "bi.supplier_id, " : "NULL as supplier_id, ") .
               "s.name as supplier_name, s.code as supplier_code
                FROM business_items bi
                LEFT JOIN suppliers s ON " . ($hasSupplierId ? "bi.supplier_id = s.id" : "1=0") . "
                WHERE bi.is_active = 1 
                AND bi.stock <= {$threshold}";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        $sql .= " ORDER BY bi.stock ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get products at reorder point for auto reorder
     */
    public function getProductsAtReorderPoint(): array {
        return $this->getLowStockProductsWithSupplier();
    }
    
    /**
     * Synchronize stock with batch quantities
     * 
     * Calculates the sum of quantity_available from all active batches
     * and compares with business_items.stock. If mismatch found, updates
     * stock and logs the discrepancy.
     * 
     * Requirements: 3.1, 3.2
     * 
     * @param int $productId Product ID to sync
     * @param ActivityLogger|null $logger Optional logger for discrepancies
     * @return array Sync result with details
     */
    public function syncStockWithBatches(int $productId, ?ActivityLogger $logger = null): array {
        // Get current stock from business_items
        $currentStock = $this->getProductStock($productId);
        
        // Calculate sum of active batch quantity_available
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(quantity_available), 0) as batch_total
            FROM inventory_batches
            WHERE product_id = ?
              AND status = 'active'
        ");
        
        if ($this->lineAccountId) {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(quantity_available), 0) as batch_total
                FROM inventory_batches
                WHERE product_id = ?
                  AND status = 'active'
                  AND line_account_id = ?
            ");
            $stmt->execute([$productId, $this->lineAccountId]);
        } else {
            $stmt->execute([$productId]);
        }
        
        $batchTotal = (int)$stmt->fetchColumn();
        
        // Check for mismatch
        $hasMismatch = $currentStock !== $batchTotal;
        $discrepancy = $batchTotal - $currentStock;
        
        $result = [
            'product_id' => $productId,
            'stock_before' => $currentStock,
            'batch_total' => $batchTotal,
            'discrepancy' => $discrepancy,
            'synced' => false,
            'message' => ''
        ];
        
        if ($hasMismatch) {
            // Update stock to match batch total
            $stmt = $this->db->prepare("UPDATE business_items SET stock = ? WHERE id = ?");
            $stmt->execute([$batchTotal, $productId]);
            
            $result['synced'] = true;
            $result['stock_after'] = $batchTotal;
            $result['message'] = "Stock synchronized: {$currentStock} → {$batchTotal} (discrepancy: {$discrepancy})";
            
            // Log discrepancy if logger provided
            if ($logger !== null) {
                $logger->logSystem(
                    'sync',
                    "Stock-batch sync discrepancy for product #{$productId}: stock was {$currentStock}, batches total {$batchTotal}",
                    [
                        'entity_type' => 'product',
                        'entity_id' => $productId,
                        'old_value' => ['stock' => $currentStock],
                        'new_value' => ['stock' => $batchTotal, 'batch_total' => $batchTotal],
                        'extra_data' => [
                            'discrepancy' => $discrepancy,
                            'sync_type' => 'stock_batch_sync'
                        ],
                        'line_account_id' => $this->lineAccountId
                    ]
                );
            }
        } else {
            $result['stock_after'] = $currentStock;
            $result['message'] = "Stock already synchronized: {$currentStock}";
        }
        
        return $result;
    }
    
    /**
     * Synchronize stock with batches for all products
     * 
     * @param ActivityLogger|null $logger Optional logger for discrepancies
     * @return array Summary of sync results
     */
    public function syncAllStocksWithBatches(?ActivityLogger $logger = null): array {
        // Get all products that have batches
        $sql = "
            SELECT DISTINCT product_id 
            FROM inventory_batches 
            WHERE status = 'active'
        ";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $results = [
            'total_products' => count($productIds),
            'synced_count' => 0,
            'no_change_count' => 0,
            'discrepancies' => []
        ];
        
        foreach ($productIds as $productId) {
            $syncResult = $this->syncStockWithBatches((int)$productId, $logger);
            
            if ($syncResult['synced']) {
                $results['synced_count']++;
                $results['discrepancies'][] = $syncResult;
            } else {
                $results['no_change_count']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Get sum of active batch quantities for a product
     * 
     * @param int $productId Product ID
     * @return int Sum of quantity_available from active batches
     */
    public function getActiveBatchTotal(int $productId): int {
        $sql = "
            SELECT COALESCE(SUM(quantity_available), 0) as batch_total
            FROM inventory_batches
            WHERE product_id = ?
              AND status = 'active'
        ";
        $params = [$productId];
        
        if ($this->lineAccountId) {
            $sql .= " AND line_account_id = ?";
            $params[] = $this->lineAccountId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();
    }

}