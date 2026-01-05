<?php
/**
 * PurchaseOrderService - จัดการ Purchase Order และ Goods Receive
 */

require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/SupplierService.php';
require_once __DIR__ . '/BatchService.php';

class PurchaseOrderService {
    private $db;
    private $lineAccountId;
    private $inventoryService;
    private $supplierService;
    private $batchService;
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->inventoryService = new InventoryService($db, $lineAccountId);
        $this->supplierService = new SupplierService($db, $lineAccountId);
        $this->batchService = new BatchService($db, $lineAccountId);
    }
    
    // ==================== Purchase Order ====================
    
    /**
     * Create new purchase order
     */
    public function createPO(array $data): array {
        // Validate supplier
        $supplier = $this->supplierService->getById($data['supplier_id']);
        if (!$supplier || !$supplier['is_active']) {
            throw new Exception("Supplier is inactive or not found");
        }
        
        $poNumber = $this->inventoryService->generateDocNumber('PO');
        
        $stmt = $this->db->prepare("
            INSERT INTO purchase_orders 
            (line_account_id, po_number, supplier_id, status, order_date, expected_date, notes, created_by)
            VALUES (?, ?, ?, 'draft', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $poNumber,
            $data['supplier_id'],
            $data['order_date'] ?? date('Y-m-d'),
            $data['expected_date'] ?? null,
            $data['notes'] ?? null,
            $data['created_by'] ?? null
        ]);
        
        $poId = $this->db->lastInsertId();
        
        return [
            'id' => $poId,
            'po_number' => $poNumber
        ];
    }
    
    /**
     * Add item to purchase order
     */
    public function addPOItem(int $poId, array $item): int {
        // Validate PO is draft
        $po = $this->getPO($poId);
        if (!$po || $po['status'] !== 'draft') {
            throw new Exception("Cannot add items to non-draft PO");
        }
        
        $subtotal = $item['quantity'] * $item['unit_cost'];
        
        // Check if unit columns exist
        $cols = $this->db->query("SHOW COLUMNS FROM purchase_order_items")->fetchAll(PDO::FETCH_COLUMN);
        $hasUnitCols = in_array('unit_id', $cols);
        
        if ($hasUnitCols && !empty($item['unit_id'])) {
            $stmt = $this->db->prepare("
                INSERT INTO purchase_order_items 
                (po_id, product_id, unit_id, unit_name, unit_factor, quantity, unit_cost, subtotal, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $poId,
                $item['product_id'],
                $item['unit_id'],
                $item['unit_name'] ?? null,
                $item['unit_factor'] ?? 1,
                $item['quantity'],
                $item['unit_cost'],
                $subtotal,
                $item['notes'] ?? null
            ]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO purchase_order_items 
                (po_id, product_id, quantity, unit_cost, subtotal, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $poId,
                $item['product_id'],
                $item['quantity'],
                $item['unit_cost'],
                $subtotal,
                $item['notes'] ?? null
            ]);
        }
        
        $itemId = $this->db->lastInsertId();
        
        // Update PO totals
        $this->updatePOTotals($poId);
        
        return $itemId;
    }
    
    /**
     * Update PO item
     */
    public function updatePOItem(int $itemId, array $data): bool {
        $stmt = $this->db->prepare("SELECT po_id FROM purchase_order_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $poId = $stmt->fetchColumn();
        
        $po = $this->getPO($poId);
        if (!$po || $po['status'] !== 'draft') {
            throw new Exception("Cannot update items in non-draft PO");
        }
        
        $subtotal = $data['quantity'] * $data['unit_cost'];
        
        $stmt = $this->db->prepare("
            UPDATE purchase_order_items 
            SET quantity = ?, unit_cost = ?, subtotal = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['quantity'],
            $data['unit_cost'],
            $subtotal,
            $data['notes'] ?? null,
            $itemId
        ]);
        
        $this->updatePOTotals($poId);
        return true;
    }
    
    /**
     * Remove PO item
     */
    public function removePOItem(int $itemId): bool {
        $stmt = $this->db->prepare("SELECT po_id FROM purchase_order_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $poId = $stmt->fetchColumn();
        
        $po = $this->getPO($poId);
        if (!$po || $po['status'] !== 'draft') {
            throw new Exception("Cannot remove items from non-draft PO");
        }
        
        $stmt = $this->db->prepare("DELETE FROM purchase_order_items WHERE id = ?");
        $stmt->execute([$itemId]);
        
        $this->updatePOTotals($poId);
        return true;
    }

    /**
     * Submit purchase order
     */
    public function submitPO(int $poId): bool {
        $po = $this->getPO($poId);
        if (!$po || $po['status'] !== 'draft') {
            throw new Exception("Cannot submit non-draft PO");
        }
        
        // Check has items
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE po_id = ?");
        $stmt->execute([$poId]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception("Purchase order must have at least one item");
        }
        
        $stmt = $this->db->prepare("UPDATE purchase_orders SET status = 'submitted', submitted_at = NOW() WHERE id = ?");
        return $stmt->execute([$poId]);
    }
    
    /**
     * Cancel purchase order
     */
    public function cancelPO(int $poId, string $reason): bool {
        $po = $this->getPO($poId);
        if (!$po || !in_array($po['status'], ['draft', 'submitted'])) {
            throw new Exception("Cannot cancel PO with status: " . ($po['status'] ?? 'unknown'));
        }
        
        $stmt = $this->db->prepare("UPDATE purchase_orders SET status = 'cancelled', cancel_reason = ?, cancelled_at = NOW() WHERE id = ?");
        return $stmt->execute([$reason, $poId]);
    }
    
    /**
     * Get purchase order by ID (alias for getPO)
     */
    public function getPOById(int $poId): ?array {
        return $this->getPO($poId);
    }
    
    /**
     * Get purchase order
     */
    public function getPO(int $poId): ?array {
        $stmt = $this->db->prepare("
            SELECT po.*, s.name as supplier_name, s.code as supplier_code
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.id = ?
        ");
        $stmt->execute([$poId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Get PO items
     */
    public function getPOItems(int $poId): array {
        $stmt = $this->db->prepare("
            SELECT poi.*, bi.name as product_name, bi.sku, bi.image_url, bi.unit as default_unit,
                   COALESCE(poi.unit_name, bi.unit, 'ชิ้น') as display_unit
            FROM purchase_order_items poi
            LEFT JOIN business_items bi ON poi.product_id = bi.id
            WHERE poi.po_id = ?
        ");
        $stmt->execute([$poId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all purchase orders
     */
    public function getAllPOs(array $filters = []): array {
        $sql = "SELECT po.*, s.name as supplier_name
                FROM purchase_orders po
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                WHERE 1=1";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (po.line_account_id = ? OR po.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND po.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['supplier_id'])) {
            $sql .= " AND po.supplier_id = ?";
            $params[] = $filters['supplier_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND po.order_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND po.order_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY po.created_at DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update PO totals
     */
    private function updatePOTotals(int $poId): void {
        $stmt = $this->db->prepare("SELECT SUM(subtotal) FROM purchase_order_items WHERE po_id = ?");
        $stmt->execute([$poId]);
        $subtotal = $stmt->fetchColumn() ?: 0;
        
        $stmt = $this->db->prepare("UPDATE purchase_orders SET subtotal = ?, total_amount = ? WHERE id = ?");
        $stmt->execute([$subtotal, $subtotal, $poId]);
    }

    // ==================== Goods Receive ====================
    
    /**
     * Create goods receive from PO
     */
    public function createGR(int $poId, array $data = []): array {
        $po = $this->getPO($poId);
        if (!$po || $po['status'] === 'cancelled') {
            throw new Exception("Cannot receive goods for cancelled PO");
        }
        
        $grNumber = $this->inventoryService->generateDocNumber('GR');
        
        $stmt = $this->db->prepare("
            INSERT INTO goods_receives 
            (line_account_id, gr_number, po_id, status, receive_date, notes, received_by)
            VALUES (?, ?, ?, 'draft', ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $grNumber,
            $poId,
            $data['receive_date'] ?? date('Y-m-d'),
            $data['notes'] ?? null,
            $data['received_by'] ?? null
        ]);
        
        $grId = $this->db->lastInsertId();
        
        return [
            'id' => $grId,
            'gr_number' => $grNumber
        ];
    }
    
    /**
     * Add item to goods receive
     * Requirements: 1.2, 4.2 - Include batch fields
     */
    public function addGRItem(int $grId, array $item): int {
        $gr = $this->getGR($grId);
        if (!$gr || $gr['status'] !== 'draft') {
            throw new Exception("Cannot add items to non-draft GR");
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO goods_receive_items 
            (gr_id, po_item_id, product_id, quantity, notes, batch_number, lot_number, expiry_date, manufacture_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $grId,
            $item['po_item_id'],
            $item['product_id'],
            $item['quantity'],
            $item['notes'] ?? null,
            $item['batch_number'] ?? null,
            $item['lot_number'] ?? null,
            !empty($item['expiry_date']) ? $item['expiry_date'] : null,
            !empty($item['manufacture_date']) ? $item['manufacture_date'] : null
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Confirm goods receive
     * Creates batches for each item and updates stock
     * Requirements: 1.1, 1.2, 1.3, 1.5
     */
    public function confirmGR(int $grId, int $confirmedBy = null): bool {
        $gr = $this->getGR($grId);
        if (!$gr || $gr['status'] !== 'draft') {
            throw new Exception("Cannot confirm non-draft GR");
        }
        
        $this->db->beginTransaction();
        
        try {
            // Get GR items
            $items = $this->getGRItems($grId);
            
            // Get PO for supplier info
            $po = $this->getPO($gr['po_id']);
            
            foreach ($items as $item) {
                // Get unit cost for value tracking (Requirements 6.3)
                $unitCost = isset($item['unit_cost']) ? (float)$item['unit_cost'] : null;
                
                // Update stock (Requirements 1.1) with value tracking (Requirements 6.3)
                $this->inventoryService->updateStock(
                    $item['product_id'],
                    $item['quantity'],
                    'receive',
                    'goods_receive',
                    $grId,
                    $gr['gr_number'],
                    "Received from PO: " . $gr['po_number'],
                    $confirmedBy,
                    $unitCost
                );
                
                // Generate batch number if not provided
                $batchNumber = !empty($item['batch_number']) 
                    ? $item['batch_number'] 
                    : $this->generateBatchNumber($grId, $item['product_id']);
                
                // Check for existing batch with same batch_number and product_id (Requirements 1.5)
                $existingBatch = $this->batchService->getBatchByNumber($batchNumber, $item['product_id']);
                
                if ($existingBatch) {
                    // Update existing batch quantity instead of creating duplicate
                    $newQuantity = $existingBatch['quantity'] + $item['quantity'];
                    $newQuantityAvailable = $existingBatch['quantity_available'] + $item['quantity'];
                    
                    $this->batchService->updateBatch($existingBatch['id'], [
                        'quantity' => $newQuantity,
                        'quantity_available' => $newQuantityAvailable
                    ]);
                } else {
                    // Create new batch (Requirements 1.2, 1.3)
                    $this->batchService->createBatch([
                        'product_id' => $item['product_id'],
                        'batch_number' => $batchNumber,
                        'lot_number' => $item['lot_number'] ?? null,
                        'quantity' => $item['quantity'],
                        'quantity_available' => $item['quantity'],
                        'cost_price' => $item['unit_cost'] ?? null,
                        'expiry_date' => $item['expiry_date'] ?? null,
                        'manufacture_date' => $item['manufacture_date'] ?? null,
                        'supplier_id' => $po['supplier_id'] ?? null,
                        'received_at' => date('Y-m-d H:i:s'),
                        'received_by' => $confirmedBy,
                        'status' => 'active',
                        'notes' => "Created from GR: {$gr['gr_number']}"
                    ]);
                }
                
                // Update PO item received quantity
                $stmt = $this->db->prepare("
                    UPDATE purchase_order_items 
                    SET received_quantity = received_quantity + ? 
                    WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['po_item_id']]);
            }
            
            // Update GR status
            $stmt = $this->db->prepare("UPDATE goods_receives SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?");
            $stmt->execute([$grId]);
            
            // Check if PO is complete
            $this->checkPOCompletion($gr['po_id']);
            
            // Update supplier total purchase
            $this->supplierService->updateTotalPurchase($po['supplier_id'], $po['total_amount']);
            
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Generate batch number for GR item
     * Format: GR{grId}-{productId}-{timestamp}
     */
    private function generateBatchNumber(int $grId, int $productId): string {
        return sprintf("GR%d-%d-%s", $grId, $productId, date('YmdHis'));
    }
    
    /**
     * Get goods receive
     */
    public function getGR(int $grId): ?array {
        $stmt = $this->db->prepare("
            SELECT gr.*, po.po_number, s.name as supplier_name
            FROM goods_receives gr
            LEFT JOIN purchase_orders po ON gr.po_id = po.id
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE gr.id = ?
        ");
        $stmt->execute([$grId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Get GR items
     */
    public function getGRItems(int $grId): array {
        $stmt = $this->db->prepare("
            SELECT gri.*, bi.name as product_name, bi.sku, 
                   poi.quantity as ordered_quantity, poi.unit_cost
            FROM goods_receive_items gri
            LEFT JOIN business_items bi ON gri.product_id = bi.id
            LEFT JOIN purchase_order_items poi ON gri.po_item_id = poi.id
            WHERE gri.gr_id = ?
        ");
        $stmt->execute([$grId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get GRs by PO ID
     */
    public function getGRsByPO(int $poId): array {
        return $this->getAllGRs(['po_id' => $poId]);
    }
    
    /**
     * Get all goods receives
     */
    public function getAllGRs(array $filters = []): array {
        $sql = "SELECT gr.*, po.po_number, s.name as supplier_name
                FROM goods_receives gr
                LEFT JOIN purchase_orders po ON gr.po_id = po.id
                LEFT JOIN suppliers s ON po.supplier_id = s.id
                WHERE 1=1";
        $params = [];
        
        if ($this->lineAccountId) {
            $sql .= " AND (gr.line_account_id = ? OR gr.line_account_id IS NULL)";
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['status'])) {
            $sql .= " AND gr.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['po_id'])) {
            $sql .= " AND gr.po_id = ?";
            $params[] = $filters['po_id'];
        }
        
        $sql .= " ORDER BY gr.created_at DESC";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if PO is complete
     */
    private function checkPOCompletion(int $poId): void {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM purchase_order_items 
            WHERE po_id = ? AND received_quantity < quantity
        ");
        $stmt->execute([$poId]);
        $remaining = $stmt->fetchColumn();
        
        if ($remaining == 0) {
            $stmt = $this->db->prepare("UPDATE purchase_orders SET status = 'completed' WHERE id = ?");
            $stmt->execute([$poId]);
        } else {
            // Check if any received
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM purchase_order_items 
                WHERE po_id = ? AND received_quantity > 0
            ");
            $stmt->execute([$poId]);
            if ($stmt->fetchColumn() > 0) {
                $stmt = $this->db->prepare("UPDATE purchase_orders SET status = 'partial' WHERE id = ?");
                $stmt->execute([$poId]);
            }
        }
    }
}
