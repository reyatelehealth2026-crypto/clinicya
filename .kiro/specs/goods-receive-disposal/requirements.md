# Requirements Document

## Introduction

ปรับปรุงการเชื่อมโยงระบบ Goods Receive และ Disposal ที่มีอยู่แล้ว ให้ทำงานสัมพันธ์กันระหว่าง:
- **PurchaseOrderService** (GR) → **InventoryService** (stock) → **BatchService** (batch)
- เมื่อรับสินค้า: เพิ่ม stock ใน business_items + สร้าง batch ใน inventory_batches
- เมื่อทำลายสินค้า: ลด stock ใน business_items + อัพเดท batch status เป็น 'disposed'

## Glossary

- **Goods Receive (GR)**: กระบวนการรับสินค้าเข้าคลังจาก Purchase Order (PurchaseOrderService)
- **Disposal**: กระบวนการทำลายสินค้า (BatchService.disposeBatch)
- **business_items**: ตารางหลักเก็บข้อมูลสินค้าและ stock ปัจจุบัน
- **inventory_batches**: ตารางเก็บข้อมูล batch/lot ของสินค้าแต่ละรุ่น
- **stock_movements**: ตารางบันทึกการเคลื่อนไหวของ stock
- **InventoryService**: Service จัดการ stock และ stock_movements
- **BatchService**: Service จัดการ batch/lot tracking
- **PurchaseOrderService**: Service จัดการ PO และ GR

## Requirements

### Requirement 1

**User Story:** As a warehouse staff, I want GR confirmation to automatically update stock and create batch, so that inventory is synchronized.

#### Acceptance Criteria

1. WHEN a GR is confirmed THEN the system SHALL call InventoryService.updateStock to increase business_items.stock
2. WHEN a GR is confirmed THEN the system SHALL call BatchService.createBatch to create inventory_batches record
3. WHEN creating batch THEN the system SHALL set quantity and quantity_available equal to received quantity
4. WHEN a GR is confirmed THEN the system SHALL create stock_movement with type 'goods_receive' and reference to GR
5. IF batch_number already exists for same product THEN the system SHALL update existing batch quantity instead of creating duplicate

### Requirement 2

**User Story:** As a pharmacist, I want disposal to automatically update stock and batch status, so that inventory reflects actual quantities.

#### Acceptance Criteria

1. WHEN a disposal is confirmed THEN the system SHALL call InventoryService.updateStock to decrease business_items.stock
2. WHEN a disposal is confirmed THEN the system SHALL call BatchService.disposeBatch to update batch status to 'disposed'
3. WHEN disposing batch THEN the system SHALL set quantity_available to zero
4. WHEN a disposal is confirmed THEN the system SHALL create stock_movement with type 'disposal' and reference to batch
5. IF disposal quantity exceeds batch quantity_available THEN the system SHALL reject operation with error message

### Requirement 3

**User Story:** As a warehouse manager, I want stock and batch quantities to stay synchronized, so that inventory reports are accurate.

#### Acceptance Criteria

1. WHEN business_items.stock changes THEN the system SHALL ensure it equals sum of active batch quantity_available
2. WHEN batch quantity_available changes THEN the system SHALL recalculate and update business_items.stock
3. WHEN database transaction fails THEN the system SHALL rollback all changes to maintain consistency
4. WHEN viewing product THEN the system SHALL display both total stock and batch breakdown

### Requirement 4

**User Story:** As an accountant, I want GR to record cost value, so that I can track inventory value and create AP.

#### Acceptance Criteria

1. WHEN a GR is confirmed THEN the system SHALL calculate total_value as sum of (quantity × cost_price) for each item
2. WHEN creating batch THEN the system SHALL record cost_price per unit from PO item
3. WHEN a GR is confirmed THEN the system SHALL create Account Payable (AP) record with total_value
4. WHEN viewing GR THEN the system SHALL display item-level cost breakdown and total value
5. WHEN viewing inventory valuation THEN the system SHALL calculate value based on batch cost_price × quantity_available

### Requirement 5

**User Story:** As an accountant, I want disposal to record loss value, so that I can track inventory write-offs.

#### Acceptance Criteria

1. WHEN a disposal is confirmed THEN the system SHALL calculate disposal_value as quantity × batch.cost_price
2. WHEN a disposal is confirmed THEN the system SHALL create expense record for inventory write-off
3. WHEN viewing disposal THEN the system SHALL display disposed quantity and total loss value
4. WHEN generating reports THEN the system SHALL show disposal summary by period with total loss amount
5. WHEN disposing expired products THEN the system SHALL categorize as 'expiry_loss' expense type

### Requirement 6

**User Story:** As a warehouse manager, I want to see value flow at each step, so that I can understand inventory cost movement.

#### Acceptance Criteria

1. WHEN viewing GR THEN the system SHALL display: PO Value → GR Value → Stock Value increase
2. WHEN viewing disposal THEN the system SHALL display: Batch Value → Disposal Value → Stock Value decrease
3. WHEN viewing stock movements THEN the system SHALL include value_change column showing cost impact
4. WHEN viewing product THEN the system SHALL display current stock value (quantity × average cost)
5. WHEN running valuation report THEN the system SHALL show opening value, GR additions, disposal deductions, and closing value

