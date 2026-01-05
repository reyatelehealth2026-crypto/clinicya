<?php
/**
 * Property-Based Test: Duplicate Batch Number Adds Quantity
 * 
 * **Feature: goods-receive-disposal, Property 4: Duplicate Batch Number Adds Quantity**
 * **Validates: Requirements 1.5**
 * 
 * Property: For any GR with batch_number that already exists for the same product, 
 * the existing batch quantity SHALL increase instead of creating a duplicate record.
 */

namespace Tests\GoodsReceiveDisposal;

use PHPUnit\Framework\TestCase;
use PDO;

class DuplicateBatchHandlingPropertyTest extends TestCase
{
    private $db;
    private $lineAccountId = 1;
    
    protected function setUp(): void
    {
        // Create in-memory SQLite database for testing
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create required tables
        $this->createTables();
    }
    
    private function createTables(): void
    {
        // suppliers table
        $this->db->exec("
            CREATE TABLE suppliers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                code VARCHAR(50),
                name VARCHAR(255),
                is_active INTEGER DEFAULT 1,
                total_purchase DECIMAL(12,2) DEFAULT 0
            )
        ");
        
        // business_items table
        $this->db->exec("
            CREATE TABLE business_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                name VARCHAR(255),
                sku VARCHAR(100),
                stock INTEGER DEFAULT 0,
                unit VARCHAR(50) DEFAULT 'ชิ้น',
                image_url VARCHAR(500)
            )
        ");
        
        // purchase_orders table
        $this->db->exec("
            CREATE TABLE purchase_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                po_number VARCHAR(50),
                supplier_id INTEGER,
                status VARCHAR(20) DEFAULT 'draft',
                order_date DATE,
                expected_date DATE,
                notes TEXT,
                subtotal DECIMAL(12,2) DEFAULT 0,
                total_amount DECIMAL(12,2) DEFAULT 0,
                created_by INTEGER,
                submitted_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // purchase_order_items table
        $this->db->exec("
            CREATE TABLE purchase_order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                po_id INTEGER,
                product_id INTEGER,
                quantity INTEGER,
                unit_cost DECIMAL(10,2),
                subtotal DECIMAL(12,2),
                received_quantity INTEGER DEFAULT 0,
                notes TEXT
            )
        ");
        
        // goods_receives table
        $this->db->exec("
            CREATE TABLE goods_receives (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                gr_number VARCHAR(50),
                po_id INTEGER,
                status VARCHAR(20) DEFAULT 'draft',
                receive_date DATE,
                notes TEXT,
                received_by INTEGER,
                confirmed_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // goods_receive_items table
        $this->db->exec("
            CREATE TABLE goods_receive_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                gr_id INTEGER,
                po_item_id INTEGER,
                product_id INTEGER,
                quantity INTEGER,
                batch_number VARCHAR(50),
                lot_number VARCHAR(50),
                expiry_date DATE,
                manufacture_date DATE,
                notes TEXT
            )
        ");
        
        // inventory_batches table
        $this->db->exec("
            CREATE TABLE inventory_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                product_id INTEGER,
                batch_number VARCHAR(50),
                lot_number VARCHAR(50),
                supplier_id INTEGER,
                quantity INTEGER,
                quantity_available INTEGER,
                cost_price DECIMAL(10,2),
                manufacture_date DATE,
                expiry_date DATE,
                received_at DATETIME,
                received_by INTEGER,
                location_id INTEGER,
                status VARCHAR(20) DEFAULT 'active',
                notes TEXT,
                disposal_date DATETIME,
                disposal_by INTEGER,
                disposal_reason TEXT
            )
        ");
        
        // stock_movements table with value tracking (Requirements 6.3)
        $this->db->exec("
            CREATE TABLE stock_movements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                product_id INTEGER,
                quantity INTEGER,
                stock_before INTEGER DEFAULT 0,
                stock_after INTEGER DEFAULT 0,
                movement_type VARCHAR(50),
                reference_type VARCHAR(50),
                reference_id INTEGER,
                reference_number VARCHAR(50),
                notes TEXT,
                unit_cost DECIMAL(10,2),
                value_change DECIMAL(12,2),
                created_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // warehouse_locations table
        $this->db->exec("
            CREATE TABLE warehouse_locations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                location_code VARCHAR(50),
                zone VARCHAR(50),
                shelf VARCHAR(50),
                bin VARCHAR(50)
            )
        ");
    }
    
    /**
     * Create test supplier
     */
    private function createTestSupplier(): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO suppliers (line_account_id, code, name, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $stmt->execute([$this->lineAccountId, 'SUP-' . rand(100, 999), 'Test Supplier']);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Create test product
     */
    private function createTestProduct(): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO business_items (line_account_id, name, sku, stock)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([$this->lineAccountId, 'Test Product ' . rand(1, 100), 'SKU-' . rand(1000, 9999)]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Create test PO with items
     */
    private function createTestPO(int $supplierId, array $items): array
    {
        $poNumber = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
        $stmt = $this->db->prepare("
            INSERT INTO purchase_orders (line_account_id, po_number, supplier_id, status, order_date)
            VALUES (?, ?, ?, 'submitted', ?)
        ");
        $stmt->execute([$this->lineAccountId, $poNumber, $supplierId, date('Y-m-d')]);
        $poId = (int)$this->db->lastInsertId();
        
        $poItems = [];
        foreach ($items as $item) {
            $subtotal = $item['quantity'] * $item['unit_cost'];
            $stmt = $this->db->prepare("
                INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_cost, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$poId, $item['product_id'], $item['quantity'], $item['unit_cost'], $subtotal]);
            $poItems[] = [
                'id' => (int)$this->db->lastInsertId(),
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost']
            ];
        }
        
        return [
            'id' => $poId,
            'po_number' => $poNumber,
            'supplier_id' => $supplierId,
            'items' => $poItems
        ];
    }
    
    /**
     * Create test GR with items
     */
    private function createTestGR(int $poId, array $items): array
    {
        $grNumber = 'GR-' . date('Ymd') . '-' . rand(1000, 9999);
        $stmt = $this->db->prepare("
            INSERT INTO goods_receives (line_account_id, gr_number, po_id, status, receive_date)
            VALUES (?, ?, ?, 'draft', ?)
        ");
        $stmt->execute([$this->lineAccountId, $grNumber, $poId, date('Y-m-d')]);
        $grId = (int)$this->db->lastInsertId();
        
        foreach ($items as $item) {
            $stmt = $this->db->prepare("
                INSERT INTO goods_receive_items 
                (gr_id, po_item_id, product_id, quantity, batch_number, lot_number, expiry_date, manufacture_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $grId,
                $item['po_item_id'],
                $item['product_id'],
                $item['quantity'],
                $item['batch_number'] ?? null,
                $item['lot_number'] ?? null,
                $item['expiry_date'] ?? null,
                $item['manufacture_date'] ?? null
            ]);
        }
        
        return [
            'id' => $grId,
            'gr_number' => $grNumber,
            'po_id' => $poId,
            'items' => $items
        ];
    }
    
    /**
     * Create an existing batch in the database
     */
    private function createExistingBatch(int $productId, string $batchNumber, int $quantity, float $costPrice): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO inventory_batches 
            (line_account_id, product_id, batch_number, quantity, quantity_available, cost_price, status, received_at)
            VALUES (?, ?, ?, ?, ?, ?, 'active', ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            $productId,
            $batchNumber,
            $quantity,
            $quantity,
            $costPrice,
            date('Y-m-d H:i:s')
        ]);
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Simulate confirmGR logic - mirrors PurchaseOrderService.confirmGR()
     * Handles duplicate batch numbers by updating existing batch quantity
     */
    private function simulateConfirmGR(int $grId): bool
    {
        $stmt = $this->db->prepare("SELECT * FROM goods_receives WHERE id = ?");
        $stmt->execute([$grId]);
        $gr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$gr || $gr['status'] !== 'draft') {
            throw new \Exception("Cannot confirm non-draft GR");
        }
        
        $stmt = $this->db->prepare("SELECT * FROM purchase_orders WHERE id = ?");
        $stmt->execute([$gr['po_id']]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $this->db->prepare("
            SELECT gri.*, poi.unit_cost
            FROM goods_receive_items gri
            LEFT JOIN purchase_order_items poi ON gri.po_item_id = poi.id
            WHERE gri.gr_id = ?
        ");
        $stmt->execute([$grId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->db->beginTransaction();
        
        try {
            foreach ($items as $item) {
                $batchNumber = !empty($item['batch_number']) 
                    ? $item['batch_number'] 
                    : sprintf("GR%d-%d-%s", $grId, $item['product_id'], date('YmdHis'));
                
                // Check for existing batch with same batch_number and product_id (Requirements 1.5)
                $stmt = $this->db->prepare("
                    SELECT * FROM inventory_batches 
                    WHERE batch_number = ? AND product_id = ? AND line_account_id = ?
                ");
                $stmt->execute([$batchNumber, $item['product_id'], $this->lineAccountId]);
                $existingBatch = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingBatch) {
                    // Update existing batch quantity instead of creating duplicate
                    $newQuantity = $existingBatch['quantity'] + $item['quantity'];
                    $newQuantityAvailable = $existingBatch['quantity_available'] + $item['quantity'];
                    
                    $stmt = $this->db->prepare("
                        UPDATE inventory_batches 
                        SET quantity = ?, quantity_available = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newQuantity, $newQuantityAvailable, $existingBatch['id']]);
                } else {
                    // Create new batch
                    $stmt = $this->db->prepare("
                        INSERT INTO inventory_batches 
                        (line_account_id, product_id, batch_number, lot_number, supplier_id,
                         quantity, quantity_available, cost_price, manufacture_date, expiry_date,
                         received_at, status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
                    ");
                    $stmt->execute([
                        $this->lineAccountId,
                        $item['product_id'],
                        $batchNumber,
                        $item['lot_number'],
                        $po['supplier_id'],
                        $item['quantity'],
                        $item['quantity'],
                        $item['unit_cost'],
                        $item['manufacture_date'],
                        $item['expiry_date'],
                        date('Y-m-d H:i:s'),
                        "Created from GR: {$gr['gr_number']}"
                    ]);
                }
                
                // Update stock
                $stmt = $this->db->prepare("
                    UPDATE business_items SET stock = stock + ? WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
            
            $stmt = $this->db->prepare("UPDATE goods_receives SET status = 'confirmed', confirmed_at = ? WHERE id = ?");
            $stmt->execute([date('Y-m-d H:i:s'), $grId]);
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get batch by batch_number and product_id
     */
    private function getBatchByNumber(string $batchNumber, int $productId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM inventory_batches 
            WHERE batch_number = ? AND product_id = ? AND line_account_id = ?
        ");
        $stmt->execute([$batchNumber, $productId, $this->lineAccountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Count batches for a product with specific batch_number
     */
    private function countBatchesByNumber(string $batchNumber, int $productId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM inventory_batches 
            WHERE batch_number = ? AND product_id = ? AND line_account_id = ?
        ");
        $stmt->execute([$batchNumber, $productId, $this->lineAccountId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Property Test: Duplicate Batch Number Adds Quantity
     * 
     * **Feature: goods-receive-disposal, Property 4: Duplicate Batch Number Adds Quantity**
     * **Validates: Requirements 1.5**
     * 
     * For any GR with batch_number that already exists for the same product,
     * the existing batch quantity SHALL increase instead of creating a duplicate record.
     */
    public function testDuplicateBatchNumberAddsQuantity(): void
    {
        // Run 100 iterations with random data
        for ($i = 0; $i < 100; $i++) {
            // Setup: Create supplier and product
            $supplierId = $this->createTestSupplier();
            $productId = $this->createTestProduct();
            
            // Generate random quantities
            $existingQuantity = rand(1, 100);
            $newQuantity = rand(1, 100);
            $unitCost = round(rand(100, 10000) / 100, 2);
            
            // Use a fixed batch number for this test
            $batchNumber = 'BATCH-DUP-' . uniqid();
            
            // Create existing batch with the batch number
            $existingBatchId = $this->createExistingBatch($productId, $batchNumber, $existingQuantity, $unitCost);
            
            // Verify existing batch was created
            $batchBefore = $this->getBatchByNumber($batchNumber, $productId);
            $this->assertNotNull($batchBefore, "Existing batch should be created on iteration {$i}");
            $this->assertEquals($existingQuantity, (int)$batchBefore['quantity'], "Initial quantity should match on iteration {$i}");
            
            // Count batches before GR
            $batchCountBefore = $this->countBatchesByNumber($batchNumber, $productId);
            $this->assertEquals(1, $batchCountBefore, "Should have exactly 1 batch before GR on iteration {$i}");
            
            // Create PO and GR with the same batch number
            $items = [[
                'product_id' => $productId,
                'quantity' => $newQuantity,
                'unit_cost' => $unitCost,
                'batch_number' => $batchNumber,
                'lot_number' => null,
                'expiry_date' => null,
                'manufacture_date' => null
            ]];
            
            $po = $this->createTestPO($supplierId, $items);
            $items[0]['po_item_id'] = $po['items'][0]['id'];
            
            $gr = $this->createTestGR($po['id'], $items);
            
            // Act: Confirm GR
            $this->simulateConfirmGR($gr['id']);
            
            // Assert: No duplicate batch created
            $batchCountAfter = $this->countBatchesByNumber($batchNumber, $productId);
            $this->assertEquals(
                1, 
                $batchCountAfter, 
                "Should still have exactly 1 batch after GR (no duplicate) on iteration {$i}"
            );
            
            // Assert: Existing batch quantity increased
            $batchAfter = $this->getBatchByNumber($batchNumber, $productId);
            $expectedQuantity = $existingQuantity + $newQuantity;
            
            $this->assertEquals(
                $expectedQuantity,
                (int)$batchAfter['quantity'],
                "Batch quantity should increase from {$existingQuantity} to {$expectedQuantity} on iteration {$i}"
            );
            
            $this->assertEquals(
                $expectedQuantity,
                (int)$batchAfter['quantity_available'],
                "Batch quantity_available should also increase on iteration {$i}"
            );
        }
    }
    
    /**
     * Property Test: Multiple GRs with same batch number accumulate quantity
     * 
     * **Feature: goods-receive-disposal, Property 4: Duplicate Batch Number Adds Quantity**
     * **Validates: Requirements 1.5**
     */
    public function testMultipleGRsWithSameBatchNumberAccumulateQuantity(): void
    {
        for ($i = 0; $i < 50; $i++) {
            // Setup
            $supplierId = $this->createTestSupplier();
            $productId = $this->createTestProduct();
            $batchNumber = 'BATCH-MULTI-' . uniqid();
            $unitCost = round(rand(100, 10000) / 100, 2);
            
            // Generate random number of GRs (2-5)
            $numGRs = rand(2, 5);
            $quantities = [];
            $totalExpectedQuantity = 0;
            
            for ($j = 0; $j < $numGRs; $j++) {
                $qty = rand(1, 50);
                $quantities[] = $qty;
                $totalExpectedQuantity += $qty;
            }
            
            // Process each GR
            foreach ($quantities as $idx => $qty) {
                $items = [[
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'batch_number' => $batchNumber,
                    'lot_number' => null,
                    'expiry_date' => null,
                    'manufacture_date' => null
                ]];
                
                $po = $this->createTestPO($supplierId, $items);
                $items[0]['po_item_id'] = $po['items'][0]['id'];
                
                $gr = $this->createTestGR($po['id'], $items);
                $this->simulateConfirmGR($gr['id']);
            }
            
            // Assert: Still only one batch
            $batchCount = $this->countBatchesByNumber($batchNumber, $productId);
            $this->assertEquals(
                1, 
                $batchCount, 
                "Should have exactly 1 batch after {$numGRs} GRs on iteration {$i}"
            );
            
            // Assert: Total quantity is sum of all GR quantities
            $batch = $this->getBatchByNumber($batchNumber, $productId);
            $this->assertEquals(
                $totalExpectedQuantity,
                (int)$batch['quantity'],
                "Total quantity should be sum of all GR quantities ({$totalExpectedQuantity}) on iteration {$i}"
            );
        }
    }
    
    /**
     * Property Test: Different batch numbers for same product create separate batches
     * 
     * **Feature: goods-receive-disposal, Property 4: Duplicate Batch Number Adds Quantity**
     * **Validates: Requirements 1.5**
     * 
     * This is the inverse property - different batch numbers should NOT be merged
     */
    public function testDifferentBatchNumbersCreateSeparateBatches(): void
    {
        for ($i = 0; $i < 50; $i++) {
            // Setup
            $supplierId = $this->createTestSupplier();
            $productId = $this->createTestProduct();
            
            // Generate random number of different batch numbers (2-5)
            $numBatches = rand(2, 5);
            $batchNumbers = [];
            $quantities = [];
            
            for ($j = 0; $j < $numBatches; $j++) {
                $batchNumbers[] = 'BATCH-DIFF-' . uniqid() . '-' . $j;
                $quantities[] = rand(1, 50);
            }
            
            // Create GRs with different batch numbers
            foreach ($batchNumbers as $idx => $batchNumber) {
                $items = [[
                    'product_id' => $productId,
                    'quantity' => $quantities[$idx],
                    'unit_cost' => round(rand(100, 10000) / 100, 2),
                    'batch_number' => $batchNumber,
                    'lot_number' => null,
                    'expiry_date' => null,
                    'manufacture_date' => null
                ]];
                
                $po = $this->createTestPO($supplierId, $items);
                $items[0]['po_item_id'] = $po['items'][0]['id'];
                
                $gr = $this->createTestGR($po['id'], $items);
                $this->simulateConfirmGR($gr['id']);
            }
            
            // Assert: Each batch number has exactly one batch
            foreach ($batchNumbers as $idx => $batchNumber) {
                $batchCount = $this->countBatchesByNumber($batchNumber, $productId);
                $this->assertEquals(
                    1, 
                    $batchCount, 
                    "Each unique batch number should have exactly 1 batch on iteration {$i}"
                );
                
                $batch = $this->getBatchByNumber($batchNumber, $productId);
                $this->assertEquals(
                    $quantities[$idx],
                    (int)$batch['quantity'],
                    "Each batch should have its own quantity on iteration {$i}"
                );
            }
            
            // Assert: Total number of batches equals number of unique batch numbers
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM inventory_batches 
                WHERE product_id = ? AND line_account_id = ?
            ");
            $stmt->execute([$productId, $this->lineAccountId]);
            $totalBatches = (int)$stmt->fetchColumn();
            
            $this->assertEquals(
                $numBatches,
                $totalBatches,
                "Total batches should equal number of unique batch numbers on iteration {$i}"
            );
        }
    }
    
    /**
     * Property Test: Same batch number for different products creates separate batches
     * 
     * **Feature: goods-receive-disposal, Property 4: Duplicate Batch Number Adds Quantity**
     * **Validates: Requirements 1.5**
     * 
     * Duplicate detection is per product_id + batch_number combination
     */
    public function testSameBatchNumberDifferentProductsCreateSeparateBatches(): void
    {
        for ($i = 0; $i < 50; $i++) {
            // Setup
            $supplierId = $this->createTestSupplier();
            
            // Create multiple products
            $numProducts = rand(2, 4);
            $productIds = [];
            $quantities = [];
            
            for ($j = 0; $j < $numProducts; $j++) {
                $productIds[] = $this->createTestProduct();
                $quantities[] = rand(1, 50);
            }
            
            // Use the SAME batch number for all products
            $sharedBatchNumber = 'BATCH-SHARED-' . uniqid();
            
            // Create GRs for each product with the same batch number
            foreach ($productIds as $idx => $productId) {
                $items = [[
                    'product_id' => $productId,
                    'quantity' => $quantities[$idx],
                    'unit_cost' => round(rand(100, 10000) / 100, 2),
                    'batch_number' => $sharedBatchNumber,
                    'lot_number' => null,
                    'expiry_date' => null,
                    'manufacture_date' => null
                ]];
                
                $po = $this->createTestPO($supplierId, $items);
                $items[0]['po_item_id'] = $po['items'][0]['id'];
                
                $gr = $this->createTestGR($po['id'], $items);
                $this->simulateConfirmGR($gr['id']);
            }
            
            // Assert: Each product has its own batch (same batch_number, different product_id)
            foreach ($productIds as $idx => $productId) {
                $batch = $this->getBatchByNumber($sharedBatchNumber, $productId);
                
                $this->assertNotNull(
                    $batch,
                    "Each product should have a batch with the shared batch number on iteration {$i}"
                );
                
                $this->assertEquals(
                    $quantities[$idx],
                    (int)$batch['quantity'],
                    "Each product's batch should have its own quantity on iteration {$i}"
                );
            }
            
            // Assert: Total batches with this batch_number equals number of products
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM inventory_batches 
                WHERE batch_number = ? AND line_account_id = ?
            ");
            $stmt->execute([$sharedBatchNumber, $this->lineAccountId]);
            $totalBatches = (int)$stmt->fetchColumn();
            
            $this->assertEquals(
                $numProducts,
                $totalBatches,
                "Total batches with shared batch_number should equal number of products on iteration {$i}"
            );
        }
    }
}
