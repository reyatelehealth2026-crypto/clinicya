# Tasks: Inventory Management System

## Task 1: Database Migration
- [x] Create migration file `database/migration_inventory.sql`
- [x] Add tables: suppliers, purchase_orders, purchase_order_items
- [x] Add tables: goods_receives, goods_receive_items
- [x] Add tables: stock_adjustments, stock_movements
- [x] Add columns to business_items: reorder_point, supplier_id

## Task 2: Core Services
- [x] Create `classes/InventoryService.php`
- [x] Create `classes/PurchaseOrderService.php`
- [x] Create `classes/SupplierService.php`

## Task 3: API Endpoints
- [x] Create `api/inventory.php` (unified API for all inventory operations)

## Task 4: Admin Pages
- [x] Create `inventory/suppliers.php` (Supplier management)
- [x] Create `inventory/purchase-orders.php` (PO list & create)
- [x] Create `inventory/po-detail.php` (PO detail with items)
- [x] Create `inventory/goods-receive.php` (GR processing)
- [x] Create `inventory/stock-adjustment.php` (Stock adjustment)
- [x] Create `inventory/stock-movements.php` (Movement history)
- [x] Create `inventory/low-stock.php` (Low stock alerts)
- [x] Create `inventory/reports.php` (Inventory reports)

## Task 5: Navigation & Integration
- [x] Add Inventory menu section to header.php
- [ ] Update OnboardingAssistant with inventory features (optional)

## Summary
All core inventory management features have been implemented:
- Supplier management (CRUD)
- Purchase Order workflow (create, add items, submit, cancel)
- Goods Receive processing (receive items, confirm, update stock)
- Stock Adjustment (increase/decrease with reasons)
- Stock Movement tracking (audit trail)
- Low Stock alerts (out of stock, critical, warning)
- Reports (stock valuation, movement summary, purchase summary)
