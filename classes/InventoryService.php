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
     */
    public function updateStock(int $productId, int $quantity, string $type, string $refType = null, int $refId = null, string $refNumber = null, string $notes = null, int $createdBy = null): bool {
        $stockBefore = $this->getProductStock($productId);
        $stockAfter = $stockBefore + $quantity;
        
        if ($stockAfter < 0) {
            throw new Exception("Stock cannot be negative");
        }
        
        // Update product stock
        $stmt = $this->db->prepare("UPDATE business_items SET stock = ? WHERE id = ?");
        $stmt->execute([$stockAfter, $productId]);
        
        // Create movement record
        $stmt = $this->db->prepare("
            INSERT INTO stock_movements 
            (line_account_id, product_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, reference_number, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId, $productId, $type, $quantity, 
            $stockBefore, $stockAfter, $refType, $refId, $refNumber, $notes, $createdBy
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
}
