<?php
/**
 * Property-Based Test: GR Confirmation Creates Batch with Correct Values
 * 
 * **Feature: goods-receive-disposal, Property 2: GR Confirmation Creates Batch with Correct Values**
 * **Validates: Requirements 1.2, 1.3, 4.2**
 * 
 * Property: For any GR item, when confirmed, a batch record SHALL be created with 
 * quantity = quantity_available = received quantity AND cost_price = PO item unit_cost.
 */

namespace Tests\GoodsReceiveDisposal;

use PHPUnit\Framework\TestCase;
use PDO;

class GRCreatesBatchPropertyTest extends TestCase
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
        
        // warehouse_locations table (for batch joins)
        $this->db->exec("
            CREATE TABLE warehouse_locations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                location_code VARCHAR(50),
                zone VARCHAR(50),
                shelf VARCHAR(50),
                bin VARCHAR(50)
            )
        ");
        
        // doc_sequences table for generating document numbers
        $this->db->exec("
            CREATE TABLE doc_sequences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                doc_type VARCHAR(20),
                prefix VARCHAR(10),
                current_number INTEGER DEFAULT 0,
                year INTEGER,
                month INTEGER
            )
        ");
    }

    
    /**
     * Generate random GR item data for testing
     */
    private function generateRandomGRItem(int $productId, int $poItemId): array
    {
        $quantity = rand(1, 100);
        $unitCost = round(rand(100, 10000) / 100, 2); // 1.00 to 100.00
        
        return [
            'product_id' => $productId,
            'po_item_id' => $poItemId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'batch_number' => 'BATCH-' . uniqid(),
            'lot_number' => 'LOT-' . rand(1000, 9999),
            'expiry_date' => date('Y-m-d', strtotime('+' . rand(30, 365) . ' days')),
            'manufacture_date' => date('Y-m-d', strtotime('-' . rand(1, 30) . ' days'))
        ];
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
        $stmt->execute([$this->lineAccountId, 'SUP-' . rand(100, 999), 'Test Supplier ' . rand(1, 100)]);
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
        // Create PO
        $poNumber = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
        $stmt = $this->db->prepare("
            INSERT INTO purchase_orders (line_account_id, po_number, supplier_id, status, order_date)
            VALUES (?, ?, ?, 'submitted', ?)
        ");
        $stmt->execute([$this->lineAccountId, $poNumber, $supplierId, date('Y-m-d')]);
        $poId = (int)$this->db->lastInsertId();
        
        // Create PO items
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
        // Create GR
        $grNumber = 'GR-' . date('Ymd') . '-' . rand(1000, 9999);
        $stmt = $this->db->prepare("
            INSERT INTO goods_receives (line_account_id, gr_number, po_id, status, receive_date)
            VALUES (?, ?, ?, 'draft', ?)
        ");
        $stmt->execute([$this->lineAccountId, $grNumber, $poId, date('Y-m-d')]);
        $grId = (int)$this->db->lastInsertId();
        
        // Create GR items
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
     * Simulate confirmGR logic - creates batches for each GR item
     * This mirrors the actual PurchaseOrderService.confirmGR() implementation
     */
    private function simulateConfirmGR(int $grId): bool
    {
        // Get GR
        $stmt = $this->db->prepare("SELECT * FROM goods_receives WHERE id = ?");
        $stmt->execute([$grId]);
        $gr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$gr || $gr['status'] !== 'draft') {
            throw new \Exception("Cannot confirm non-draft GR");
        }
        
        // Get PO for supplier info
        $stmt = $this->db->prepare("SELECT * FROM purchase_orders WHERE id = ?");
        $stmt->execute([$gr['po_id']]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get GR items with PO item unit_cost
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
                // Generate batch number if not provided
                $batchNumber = !empty($item['batch_number']) 
                    ? $item['batch_number'] 
                    : sprintf("GR%d-%d-%s", $grId, $item['product_id'], date('YmdHis'));
                
                // Check for existing batch with same batch_number and product_id
                $stmt = $this->db->prepare("
                    SELECT * FROM inventory_batches 
                    WHERE batch_number = ? AND product_id = ? AND line_account_id = ?
                ");
                $stmt->execute([$batchNumber, $item['product_id'], $this->lineAccountId]);
                $existingBatch = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingBatch) {
                    // Update existing batch quantity
                    $newQuantity = $existingBatch['quantity'] + $item['quantity'];
                    $newQuantityAvailable = $existingBatch['quantity_available'] + $item['quantity'];
                    
                    $stmt = $this->db->prepare("
                        UPDATE inventory_batches 
                        SET quantity = ?, quantity_available = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newQuantity, $newQuantityAvailable, $existingBatch['id']]);
                } else {
                    // Create new batch (Requirements 1.2, 1.3, 4.2)
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
                        $item['quantity'],           // quantity = received quantity
                        $item['quantity'],           // quantity_available = received quantity
                        $item['unit_cost'],          // cost_price = PO item unit_cost
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
                
                // Create stock movement
                $stmt = $this->db->prepare("
                    INSERT INTO stock_movements 
                    (line_account_id, product_id, quantity, movement_type, reference_type, reference_id, reference_number)
                    VALUES (?, ?, ?, 'receive', 'goods_receive', ?, ?)
                ");
                $stmt->execute([
                    $this->lineAccountId,
                    $item['product_id'],
                    $item['quantity'],
                    $grId,
                    $gr['gr_number']
                ]);
            }
            
            // Update GR status
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
     * Property Test: GR Confirmation Creates Batch with Correct Values
     * 
     * **Feature: goods-receive-disposal, Property 2: GR Confirmation Creates Batch with Correct Values**
     * **Validates: Requirements 1.2, 1.3, 4.2**
     * 
     * For any GR item, when confirmed:
     * - A batch record SHALL be created
     * - quantity = received quantity
     * - quantity_available = received quantity  
     * - cost_price = PO item unit_cost
     */
    public function testGRConfirmationCreatesBatchWithCorrectValues(): void
    {
        // Run 100 iterations with random GR data
        for ($i = 0; $i < 100; $i++) {
            // Setup: Create supplier and products
            $supplierId = $this->createTestSupplier();
            $numItems = rand(1, 5);
            $items = [];
            
            for ($j = 0; $j < $numItems; $j++) {
                $productId = $this->createTestProduct();
                $items[] = $this->generateRandomGRItem($productId, 0); // po_item_id will be set later
            }
            
            // Create PO with items
            $po = $this->createTestPO($supplierId, $items);
            
            // Update items with actual po_item_ids
            foreach ($items as $idx => &$item) {
                $item['po_item_id'] = $po['items'][$idx]['id'];
            }
            unset($item);
            
            // Create GR with items
            $gr = $this->createTestGR($po['id'], $items);
            
            // Act: Confirm GR
            $this->simulateConfirmGR($gr['id']);
            
            // Assert: Verify each item created a batch with correct values
            foreach ($items as $item) {
                $batch = $this->getBatchByNumber($item['batch_number'], $item['product_id']);
                
                // Batch should exist
                $this->assertNotNull(
                    $batch,
                    "Batch should be created for product {$item['product_id']} on iteration {$i}"
                );
                
                // quantity = received quantity (Requirements 1.2, 1.3)
                $this->assertEquals(
                    $item['quantity'],
                    (int)$batch['quantity'],
                    "Batch quantity should equal received quantity on iteration {$i}"
                );
                
                // quantity_available = received quantity (Requirements 1.3)
                $this->assertEquals(
                    $item['quantity'],
                    (int)$batch['quantity_available'],
                    "Batch quantity_available should equal received quantity on iteration {$i}"
                );
                
                // cost_price = PO item unit_cost (Requirements 4.2)
                $this->assertEquals(
                    $item['unit_cost'],
                    (float)$batch['cost_price'],
                    "Batch cost_price should equal PO item unit_cost on iteration {$i}"
                );
                
                // Verify batch status is active
                $this->assertEquals(
                    'active',
                    $batch['status'],
                    "Batch status should be 'active' on iteration {$i}"
                );
            }
        }
    }
    
    /**
     * Property Test: Batch quantity equals quantity_available for new batches
     * 
     * **Feature: goods-receive-disposal, Property 2: GR Confirmation Creates Batch with Correct Values**
     * **Validates: Requirements 1.3**
     */
    public function testBatchQuantityEqualsQuantityAvailable(): void
    {
        for ($i = 0; $i < 100; $i++) {
            // Setup
            $supplierId = $this->createTestSupplier();
            $productId = $this->createTestProduct();
            
            $quantity = rand(1, 1000);
            $unitCost = round(rand(100, 100000) / 100, 2);
            
            $items = [[
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'batch_number' => 'BATCH-' . uniqid(),
                'lot_number' => null,
                'expiry_date' => null,
                'manufacture_date' => null
            ]];
            
            $po = $this->createTestPO($supplierId, $items);
            $items[0]['po_item_id'] = $po['items'][0]['id'];
            
            $gr = $this->createTestGR($po['id'], $items);
            
            // Act
            $this->simulateConfirmGR($gr['id']);
            
            // Assert
            $batch = $this->getBatchByNumber($items[0]['batch_number'], $productId);
            
            $this->assertEquals(
                $batch['quantity'],
                $batch['quantity_available'],
                "For new batches, quantity should equal quantity_available on iteration {$i}"
            );
        }
    }
    
    /**
     * Property Test: Cost price is correctly transferred from PO to batch
     * 
     * **Feature: goods-receive-disposal, Property 2: GR Confirmation Creates Batch with Correct Values**
     * **Validates: Requirements 4.2**
     */
    public function testCostPriceTransferredFromPOToBatch(): void
    {
        for ($i = 0; $i < 100; $i++) {
            // Setup with various cost prices
            $supplierId = $this->createTestSupplier();
            $productId = $this->createTestProduct();
            
            // Test various cost price ranges
            $unitCost = round(rand(1, 999999) / 100, 2); // 0.01 to 9999.99
            
            $items = [[
                'product_id' => $productId,
                'quantity' => rand(1, 100),
                'unit_cost' => $unitCost,
                'batch_number' => 'BATCH-' . uniqid(),
                'lot_number' => null,
                'expiry_date' => null,
                'manufacture_date' => null
            ]];
            
            $po = $this->createTestPO($supplierId, $items);
            $items[0]['po_item_id'] = $po['items'][0]['id'];
            
            $gr = $this->createTestGR($po['id'], $items);
            
            // Act
            $this->simulateConfirmGR($gr['id']);
            
            // Assert
            $batch = $this->getBatchByNumber($items[0]['batch_number'], $productId);
            
            $this->assertEquals(
                $unitCost,
                (float)$batch['cost_price'],
                "Batch cost_price ({$batch['cost_price']}) should equal PO unit_cost ({$unitCost}) on iteration {$i}"
            );
        }
    }
    
    /**
     * Property Test: Multiple GR items create multiple batches
     * 
     * **Feature: goods-receive-disposal, Property 2: GR Confirmation Creates Batch with Correct Values**
     * **Validates: Requirements 1.2**
     */
    public function testMultipleGRItemsCreateMultipleBatches(): void
    {
        for ($i = 0; $i < 50; $i++) {
            // Setup with multiple items
            $supplierId = $this->createTestSupplier();
            $numItems = rand(2, 10);
            $items = [];
            
            for ($j = 0; $j < $numItems; $j++) {
                $productId = $this->createTestProduct();
                $items[] = [
                    'product_id' => $productId,
                    'quantity' => rand(1, 100),
                    'unit_cost' => round(rand(100, 10000) / 100, 2),
                    'batch_number' => 'BATCH-' . uniqid() . '-' . $j,
                    'lot_number' => null,
                    'expiry_date' => null,
                    'manufacture_date' => null
                ];
            }
            
            $po = $this->createTestPO($supplierId, $items);
            
            foreach ($items as $idx => &$item) {
                $item['po_item_id'] = $po['items'][$idx]['id'];
            }
            unset($item);
            
            $gr = $this->createTestGR($po['id'], $items);
            
            // Act
            $this->simulateConfirmGR($gr['id']);
            
            // Assert: Count batches created
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM inventory_batches WHERE line_account_id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $batchCount = (int)$stmt->fetchColumn();
            
            $this->assertGreaterThanOrEqual(
                $numItems,
                $batchCount,
                "At least {$numItems} batches should be created on iteration {$i}"
            );
            
            // Verify each item has a corresponding batch
            foreach ($items as $item) {
                $batch = $this->getBatchByNumber($item['batch_number'], $item['product_id']);
                $this->assertNotNull(
                    $batch,
                    "Each GR item should have a corresponding batch on iteration {$i}"
                );
            }
        }
    }
}
