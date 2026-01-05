# Design Document: Goods Receive & Disposal Integration

## Overview

ปรับปรุงการเชื่อมโยงระบบที่มีอยู่แล้วให้ทำงานสัมพันธ์กัน:
- **PurchaseOrderService.confirmGR()** → เพิ่ม stock + สร้าง batch + สร้าง AP
- **BatchService.disposeBatch()** → ลด stock + สร้าง expense record

### Current State
- `PurchaseOrderService.confirmGR()` เรียก `InventoryService.updateStock()` แต่ไม่สร้าง batch
- `BatchService.disposeBatch()` อัพเดท batch status แต่ไม่ลด stock ใน business_items
- ไม่มีการบันทึก cost_price ใน batch และไม่มี disposal expense

### Target State
- GR confirm → stock + batch + AP (พร้อม cost tracking)
- Disposal confirm → stock - batch + expense (พร้อม loss tracking)

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Goods Receive Flow                        │
├─────────────────────────────────────────────────────────────────┤
│  PO → GR → confirmGR()                                          │
│       │                                                          │
│       ├─→ InventoryService.updateStock(+qty)                    │
│       │   └─→ business_items.stock += qty                       │
│       │   └─→ stock_movements (type: goods_receive)             │
│       │                                                          │
│       ├─→ BatchService.createBatch()  [NEW]                     │
│       │   └─→ inventory_batches (qty, cost_price, expiry)       │
│       │                                                          │
│       └─→ AccountPayableService.createFromGR()                  │
│           └─→ account_payables (total_value)                    │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                        Disposal Flow                             │
├─────────────────────────────────────────────────────────────────┤
│  Batch → disposeBatchWithStock()  [NEW METHOD]                  │
│       │                                                          │
│       ├─→ BatchService.disposeBatch()                           │
│       │   └─→ inventory_batches.status = 'disposed'             │
│       │   └─→ inventory_batches.quantity_available = 0          │
│       │                                                          │
│       ├─→ InventoryService.updateStock(-qty)  [NEW]             │
│       │   └─→ business_items.stock -= qty                       │
│       │   └─→ stock_movements (type: disposal)                  │
│       │                                                          │
│       └─→ ExpenseService.createDisposalExpense()  [NEW]         │
│           └─→ expenses (disposal_value = qty × cost_price)      │
└─────────────────────────────────────────────────────────────────┘
```

## Components and Interfaces

### 1. PurchaseOrderService (Modified)

```php
/**
 * confirmGR() - เพิ่มการสร้าง batch
 */
public function confirmGR(int $grId, int $confirmedBy = null): bool {
    // ... existing code ...
    
    foreach ($items as $item) {
        // 1. Update stock (existing)
        $this->inventoryService->updateStock(...);
        
        // 2. Create batch (NEW)
        $batchService->createBatch([
            'product_id' => $item['product_id'],
            'batch_number' => $item['batch_number'] ?? $this->generateBatchNumber($grId),
            'quantity' => $item['quantity'],
            'quantity_available' => $item['quantity'],
            'cost_price' => $item['unit_cost'],
            'expiry_date' => $item['expiry_date'] ?? null,
            'supplier_id' => $po['supplier_id'],
            'received_at' => date('Y-m-d H:i:s'),
            'received_by' => $confirmedBy
        ]);
        
        // 3. Update PO item (existing)
        // ...
    }
    
    // 4. Create AP (existing)
    $apService->createFromGR($grId);
}
```

### 2. BatchService (Modified)

```php
/**
 * disposeBatchWithStock() - ทำลาย batch พร้อมลด stock และสร้าง expense
 */
public function disposeBatchWithStock(
    int $batchId, 
    int $pharmacistId, 
    string $reason,
    InventoryService $inventoryService,
    ExpenseService $expenseService
): array {
    $batch = $this->getBatch($batchId);
    
    // 1. Calculate disposal value
    $disposalValue = $batch['quantity_available'] * ($batch['cost_price'] ?? 0);
    
    // 2. Dispose batch (existing - updates status)
    $this->disposeBatch($batchId, $pharmacistId, $reason);
    
    // 3. Reduce stock (NEW)
    $inventoryService->updateStock(
        $batch['product_id'],
        -$batch['quantity_available'],
        'disposal',
        'batch_disposal',
        $batchId,
        "DSP-{$batchId}",
        $reason,
        $pharmacistId
    );
    
    // 4. Create expense record (NEW)
    $expenseId = $expenseService->createDisposalExpense([
        'batch_id' => $batchId,
        'product_id' => $batch['product_id'],
        'quantity' => $batch['quantity_available'],
        'unit_cost' => $batch['cost_price'] ?? 0,
        'total_amount' => $disposalValue,
        'reason' => $reason,
        'category' => $this->getDisposalCategory($reason),
        'approved_by' => $pharmacistId
    ]);
    
    return [
        'batch_id' => $batchId,
        'disposed_quantity' => $batch['quantity_available'],
        'disposal_value' => $disposalValue,
        'expense_id' => $expenseId
    ];
}

private function getDisposalCategory(string $reason): string {
    if (stripos($reason, 'expir') !== false) return 'expiry_loss';
    if (stripos($reason, 'damage') !== false) return 'damage_loss';
    return 'inventory_loss';
}
```

### 3. ExpenseService (New Method)

```php
/**
 * createDisposalExpense() - สร้าง expense record สำหรับการทำลายสินค้า
 */
public function createDisposalExpense(array $data): int {
    return $this->create([
        'expense_number' => $this->generateExpenseNumber('DSP'),
        'category_id' => $this->getOrCreateCategory($data['category']),
        'amount' => $data['total_amount'],
        'expense_date' => date('Y-m-d'),
        'description' => "Disposal: {$data['reason']} - Qty: {$data['quantity']} @ {$data['unit_cost']}",
        'reference_type' => 'batch_disposal',
        'reference_id' => $data['batch_id'],
        'status' => 'approved',
        'approved_by' => $data['approved_by'],
        'metadata' => json_encode([
            'product_id' => $data['product_id'],
            'batch_id' => $data['batch_id'],
            'quantity' => $data['quantity'],
            'unit_cost' => $data['unit_cost']
        ])
    ]);
}
```

## Data Models

### goods_receive_items (Modified - add batch fields)

```sql
ALTER TABLE goods_receive_items ADD COLUMN batch_number VARCHAR(50) NULL;
ALTER TABLE goods_receive_items ADD COLUMN lot_number VARCHAR(50) NULL;
ALTER TABLE goods_receive_items ADD COLUMN expiry_date DATE NULL;
ALTER TABLE goods_receive_items ADD COLUMN manufacture_date DATE NULL;
```

### inventory_batches (Existing - ensure cost_price)

```sql
-- Already has cost_price column
-- Verify: cost_price DECIMAL(10,2) NULL
```

### stock_movements (Existing - verify columns)

```sql
-- movement_type: 'goods_receive', 'disposal', 'adjustment_in', 'adjustment_out'
-- reference_type: 'goods_receive', 'batch_disposal', 'adjustment'
-- reference_id: GR ID or Batch ID
```



## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: GR Confirmation Updates Stock
*For any* GR with items, when confirmed, the business_items.stock for each product SHALL increase by exactly the received quantity.
**Validates: Requirements 1.1**

### Property 2: GR Confirmation Creates Batch with Correct Values
*For any* GR item, when confirmed, a batch record SHALL be created with quantity = quantity_available = received quantity AND cost_price = PO item unit_cost.
**Validates: Requirements 1.2, 1.3, 4.2**

### Property 3: GR Creates Stock Movement Record
*For any* confirmed GR, a stock_movement record SHALL exist with movement_type = 'goods_receive' and reference_id = GR ID.
**Validates: Requirements 1.4**

### Property 4: Duplicate Batch Number Adds Quantity
*For any* GR with batch_number that already exists for the same product, the existing batch quantity SHALL increase instead of creating a duplicate record.
**Validates: Requirements 1.5**

### Property 5: Disposal Decreases Stock
*For any* batch disposal, the business_items.stock SHALL decrease by exactly the batch's quantity_available.
**Validates: Requirements 2.1**

### Property 6: Disposal Updates Batch Status
*For any* disposed batch, the batch status SHALL be 'disposed' AND quantity_available SHALL be zero.
**Validates: Requirements 2.2, 2.3**

### Property 7: Disposal Creates Stock Movement
*For any* batch disposal, a stock_movement record SHALL exist with movement_type = 'disposal' and reference_id = batch ID.
**Validates: Requirements 2.4**

### Property 8: Disposal Rejects Over-Quantity
*For any* disposal attempt where quantity exceeds batch.quantity_available, the system SHALL throw an exception and make no changes.
**Validates: Requirements 2.5**

### Property 9: Stock Equals Sum of Active Batches
*For any* product, business_items.stock SHALL equal the sum of quantity_available from all batches with status = 'active'.
**Validates: Requirements 3.1, 3.2**

### Property 10: Transaction Rollback on Failure
*For any* operation that fails mid-transaction, all database changes SHALL be rolled back to the state before the operation.
**Validates: Requirements 3.3**

### Property 11: GR Creates AP with Correct Value
*For any* confirmed GR, an AP record SHALL be created with total_amount = sum of (item quantity × item unit_cost).
**Validates: Requirements 4.1, 4.3**

### Property 12: Inventory Valuation Calculation
*For any* product, inventory valuation SHALL equal sum of (batch.cost_price × batch.quantity_available) for all active batches.
**Validates: Requirements 4.5**

### Property 13: Disposal Creates Expense with Correct Value
*For any* batch disposal, an expense record SHALL be created with amount = batch.quantity_available × batch.cost_price.
**Validates: Requirements 5.1, 5.2**

### Property 14: Expired Disposal Categorization
*For any* disposal with reason containing 'expir', the expense category SHALL be 'expiry_loss'.
**Validates: Requirements 5.5**

### Property 15: Stock Movement Includes Value Change
*For any* stock movement, the record SHALL include value_change = quantity × unit_cost.
**Validates: Requirements 6.3**

## Error Handling

### GR Confirmation Errors
- **Invalid GR Status**: Throw exception if GR status is not 'draft'
- **Missing Items**: Throw exception if GR has no items
- **Invalid Quantity**: Throw exception if any item quantity ≤ 0
- **Past Expiry Date**: Throw exception if expiry_date is in the past

### Disposal Errors
- **Batch Not Found**: Throw exception with 404 status
- **Invalid Batch Status**: Throw exception if batch is not 'active'
- **Insufficient Quantity**: Throw exception if disposal qty > quantity_available
- **Missing Reason**: Throw exception if disposal reason is empty
- **Missing Approval**: Throw exception for controlled substances without pharmacist approval

### Transaction Errors
- All operations use database transactions
- On any exception, rollback and re-throw
- Log error details for debugging

## Testing Strategy

### Property-Based Testing Library
- **PHPUnit** with custom generators for test data
- Minimum 100 iterations per property test

### Unit Tests
- Test individual service methods in isolation
- Mock database for unit tests
- Test edge cases: zero quantity, null values, boundary conditions

### Property-Based Tests
Each property test will:
1. Generate random valid input data
2. Execute the operation
3. Assert the property holds
4. Tag with property reference: `**Feature: goods-receive-disposal, Property N: description**`

### Integration Tests
- Test full flow: PO → GR → Stock + Batch + AP
- Test full flow: Batch → Disposal → Stock - Expense
- Verify data consistency across tables

### Test Data Generators
```php
// Generate random GR with items
function generateGR(): array {
    return [
        'po_id' => randomPoId(),
        'items' => array_map(fn() => [
            'product_id' => randomProductId(),
            'quantity' => rand(1, 100),
            'unit_cost' => rand(10, 1000) / 10,
            'batch_number' => 'BATCH-' . uniqid(),
            'expiry_date' => date('Y-m-d', strtotime('+' . rand(30, 365) . ' days'))
        ], range(1, rand(1, 5)))
    ];
}

// Generate random batch for disposal
function generateBatchForDisposal(): array {
    return [
        'batch_id' => randomActiveBatchId(),
        'reason' => randomDisposalReason(),
        'pharmacist_id' => randomPharmacistId()
    ];
}
```
