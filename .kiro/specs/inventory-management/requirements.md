# Requirements Document

## Introduction

ระบบ Inventory Management สำหรับร้านขายยา/ร้านค้าออนไลน์ ที่ทำงานคล้าย POS โดยมีความสามารถในการจัดการสต็อกสินค้าอย่างครบวงจร รวมถึงการสร้างใบสั่งซื้อ (Purchase Order), การรับสินค้าเข้า (Goods Receive), การปรับสต็อก (Stock Adjustment) และการติดตามประวัติการเคลื่อนไหวของสต็อก

## Glossary

- **Inventory System**: ระบบจัดการคลังสินค้าและสต็อก
- **Purchase Order (PO)**: ใบสั่งซื้อสินค้าจาก Supplier
- **Goods Receive (GR)**: ใบรับสินค้าเข้าคลังจากใบสั่งซื้อ
- **Stock Adjustment**: การปรับปรุงจำนวนสต็อกด้วยตนเอง (เพิ่ม/ลด)
- **Stock Movement**: การเคลื่อนไหวของสต็อก (เข้า/ออก)
- **Supplier**: ผู้จำหน่ายสินค้า
- **Reorder Point**: จุดสั่งซื้อใหม่ (เมื่อสต็อกต่ำกว่าจุดนี้ควรสั่งซื้อ)
- **Admin User**: ผู้ดูแลระบบที่มีสิทธิ์จัดการสต็อก

## Requirements

### Requirement 1: Supplier Management

**User Story:** As an admin user, I want to manage supplier information, so that I can track where products come from and create purchase orders.

#### Acceptance Criteria

1. WHEN an admin user creates a new supplier THEN the Inventory System SHALL store supplier name, contact person, phone, email, and address
2. WHEN an admin user views supplier list THEN the Inventory System SHALL display all suppliers with their contact information and total purchase amount
3. WHEN an admin user edits supplier information THEN the Inventory System SHALL update the supplier record and maintain history
4. WHEN an admin user deactivates a supplier THEN the Inventory System SHALL mark the supplier as inactive and prevent new purchase orders to that supplier

### Requirement 2: Purchase Order (PO) Creation

**User Story:** As an admin user, I want to create purchase orders, so that I can order products from suppliers.

#### Acceptance Criteria

1. WHEN an admin user creates a new purchase order THEN the Inventory System SHALL generate a unique PO number with format "PO-YYYYMMDD-XXXX"
2. WHEN an admin user adds products to a purchase order THEN the Inventory System SHALL allow selecting products, specifying quantity, and unit cost
3. WHEN an admin user saves a purchase order THEN the Inventory System SHALL calculate total amount and store the PO with status "draft"
4. WHEN an admin user submits a purchase order THEN the Inventory System SHALL change status to "submitted" and record submission timestamp
5. WHEN an admin user cancels a purchase order THEN the Inventory System SHALL change status to "cancelled" and record cancellation reason
6. IF a purchase order contains no items THEN the Inventory System SHALL prevent submission and display validation error

### Requirement 3: Goods Receive (GR) Processing

**User Story:** As an admin user, I want to receive goods from purchase orders, so that I can update stock levels when products arrive.

#### Acceptance Criteria

1. WHEN an admin user creates a goods receive from a purchase order THEN the Inventory System SHALL generate a unique GR number with format "GR-YYYYMMDD-XXXX"
2. WHEN an admin user receives products THEN the Inventory System SHALL allow entering received quantity for each product line
3. WHEN received quantity differs from ordered quantity THEN the Inventory System SHALL allow partial receive and track remaining quantity
4. WHEN an admin user confirms goods receive THEN the Inventory System SHALL increase product stock by received quantity
5. WHEN all items from a purchase order are received THEN the Inventory System SHALL update PO status to "completed"
6. WHEN goods receive is confirmed THEN the Inventory System SHALL create stock movement records with type "receive"

### Requirement 4: Stock Adjustment

**User Story:** As an admin user, I want to adjust stock levels manually, so that I can correct discrepancies from physical counts or handle damaged goods.

#### Acceptance Criteria

1. WHEN an admin user creates a stock adjustment THEN the Inventory System SHALL generate a unique adjustment number with format "ADJ-YYYYMMDD-XXXX"
2. WHEN an admin user adjusts stock THEN the Inventory System SHALL require adjustment type (increase/decrease), quantity, and reason
3. WHEN stock adjustment is confirmed THEN the Inventory System SHALL update product stock and create stock movement record
4. IF stock decrease would result in negative stock THEN the Inventory System SHALL prevent the adjustment and display warning
5. WHEN an admin user views adjustment history THEN the Inventory System SHALL display all adjustments with reason, quantity, and timestamp

### Requirement 5: Stock Movement Tracking

**User Story:** As an admin user, I want to track all stock movements, so that I can audit inventory changes and identify discrepancies.

#### Acceptance Criteria

1. WHEN any stock change occurs THEN the Inventory System SHALL create a stock movement record with movement type, quantity, reference document, and timestamp
2. WHEN an admin user views stock movement history THEN the Inventory System SHALL display movements filtered by product, date range, or movement type
3. WHEN viewing stock movement THEN the Inventory System SHALL show running balance after each movement
4. WHEN exporting stock movement report THEN the Inventory System SHALL generate CSV file with all movement details

### Requirement 6: Low Stock Alerts

**User Story:** As an admin user, I want to receive alerts when stock is low, so that I can reorder products before they run out.

#### Acceptance Criteria

1. WHEN product stock falls below reorder point THEN the Inventory System SHALL display the product in low stock alert list
2. WHEN an admin user sets reorder point for a product THEN the Inventory System SHALL store the reorder point value
3. WHEN viewing low stock alerts THEN the Inventory System SHALL show product name, current stock, reorder point, and suggested order quantity
4. WHEN an admin user creates PO from low stock alert THEN the Inventory System SHALL pre-populate PO with suggested products and quantities

### Requirement 7: Inventory Reports

**User Story:** As an admin user, I want to generate inventory reports, so that I can analyze stock levels and purchase patterns.

#### Acceptance Criteria

1. WHEN an admin user requests stock valuation report THEN the Inventory System SHALL calculate total inventory value based on cost price
2. WHEN an admin user requests purchase history report THEN the Inventory System SHALL display PO summary by supplier and date range
3. WHEN an admin user requests stock movement summary THEN the Inventory System SHALL show total in/out quantities by product and period
4. WHEN generating reports THEN the Inventory System SHALL allow export to CSV and PDF formats

### Requirement 8: Data Serialization

**User Story:** As a developer, I want to serialize and deserialize inventory data, so that I can export/import data and integrate with external systems.

#### Acceptance Criteria

1. WHEN exporting purchase order data THEN the Inventory System SHALL serialize PO to JSON format with all line items
2. WHEN importing purchase order data THEN the Inventory System SHALL parse JSON and create valid PO records
3. WHEN serializing stock movement data THEN the Inventory System SHALL include all movement details and reference information
4. WHEN deserializing data THEN the Inventory System SHALL validate data integrity and report parsing errors
5. WHEN round-trip serialization occurs THEN the Inventory System SHALL produce equivalent data after serialize then deserialize

