# Implementation Plan

- [x] 1. Add batch fields to goods_receive_items table
  - [x] 1.1 Create migration file for batch_number, lot_number, expiry_date, manufacture_date columns
    - Add columns to goods_receive_items table
    - _Requirements: 1.2, 4.2_
  - [x] 1.2 Run migration and verify columns exist
    - Execute migration script
    - _Requirements: 1.2_

- [x] 2. Modify PurchaseOrderService.confirmGR() to create batches
  - [x] 2.1 Update confirmGR() to call BatchService.createBatch() for each item
    - Import BatchService
    - Create batch with product_id, batch_number, quantity, cost_price, expiry_date
    - Handle duplicate batch_number by updating existing batch
    - _Requirements: 1.1, 1.2, 1.3, 1.5_
  - [x] 2.2 Write property test for GR creates batch
    - **Property 2: GR Confirmation Creates Batch with Correct Values**
    - **Validates: Requirements 1.2, 1.3, 4.2**
  - [x] 2.3 Write property test for duplicate batch handling
    - **Property 4: Duplicate Batch Number Adds Quantity**
    - **Validates: Requirements 1.5**

- [x] 3. Update GR UI to capture batch information
  - [x] 3.1 Modify includes/procurement/gr.php to add batch input fields
    - Add batch_number, lot_number, expiry_date, manufacture_date inputs per item
    - _Requirements: 1.2, 4.2_
  - [x] 3.2 Update api/inventory.php create_gr action to pass batch data
    - Include batch fields in GR item creation
    - _Requirements: 1.2_

- [x] 4. Checkpoint - Verify GR creates batches
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Add disposeBatchWithStock() method to BatchService
  - [x] 5.1 Implement disposeBatchWithStock() method
    - Calculate disposal_value = quantity_available × cost_price
    - Call existing disposeBatch() to update status
    - Call InventoryService.updateStock() to decrease stock
    - Create stock_movement with type 'disposal'
    - _Requirements: 2.1, 2.2, 2.3, 2.4_
  - [ ]* 5.2 Write property test for disposal decreases stock
    - **Property 5: Disposal Decreases Stock**
    - **Validates: Requirements 2.1**
  - [ ]* 5.3 Write property test for disposal updates batch status
    - **Property 6: Disposal Updates Batch Status**
    - **Validates: Requirements 2.2, 2.3**
  - [ ]* 5.4 Write property test for disposal rejects over-quantity
    - **Property 8: Disposal Rejects Over-Quantity**
    - **Validates: Requirements 2.5**

- [x] 6. Add disposal expense creation
  - [x] 6.1 Add createDisposalExpense() method to ExpenseService
    - Generate expense number with DSP prefix
    - Set category based on disposal reason (expiry_loss, damage_loss, inventory_loss)
    - Record batch_id, product_id, quantity, unit_cost in metadata
    - _Requirements: 5.1, 5.2, 5.5_
  - [x] 6.2 Update disposeBatchWithStock() to call createDisposalExpense()
    - Pass disposal value and reason to expense service
    - _Requirements: 5.1, 5.2_
  - [ ]* 6.3 Write property test for disposal creates expense
    - **Property 13: Disposal Creates Expense with Correct Value**
    - **Validates: Requirements 5.1, 5.2**
  - [ ]* 6.4 Write property test for expired disposal categorization
    - **Property 14: Expired Disposal Categorization**
    - **Validates: Requirements 5.5**

- [x] 7. Create disposal API endpoint
  - [x] 7.1 Add dispose_batch action to api/batches.php
    - Accept batch_id, reason, pharmacist_id
    - Call BatchService.disposeBatchWithStock()
    - Return disposal result with value
    - _Requirements: 2.1, 2.2, 5.1_

- [x] 8. Checkpoint - Verify disposal flow
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Add stock-batch synchronization check
  - [x] 9.1 Add syncStockWithBatches() method to InventoryService
    - Calculate sum of active batch quantity_available
    - Compare with business_items.stock
    - Update stock if mismatch found
    - Log discrepancies
    - _Requirements: 3.1, 3.2_
  - [ ]* 9.2 Write property test for stock equals sum of batches
    - **Property 9: Stock Equals Sum of Active Batches**
    - **Validates: Requirements 3.1, 3.2**

- [x] 10. Add value tracking to stock movements
  - [x] 10.1 Add value_change column to stock_movements table if not exists
    - Create migration for value_change DECIMAL(12,2) column
    - _Requirements: 6.3_
  - [x] 10.2 Update InventoryService.updateStock() to record value_change
    - Calculate value_change = quantity × unit_cost
    - Store in stock_movements record
    - _Requirements: 6.3_
  - [ ]* 10.3 Write property test for movement includes value
    - **Property 15: Stock Movement Includes Value Change**
    - **Validates: Requirements 6.3**

- [x] 11. Update UI to display value flow
  - [x] 11.1 Update GR detail view to show cost breakdown
    - Display item-level: qty × cost = subtotal
    - Display total GR value
    - _Requirements: 4.1, 4.4_
  - [x] 11.2 Update batch list to show disposal value
    - Display batch cost_price and total value
    - Show disposal value when disposed
    - _Requirements: 5.3_
  - [x] 11.3 Update stock movements view to show value_change
    - Add value_change column to movements table
    - _Requirements: 6.3_

- [x] 12. Final Checkpoint - Verify all integrations
  - Ensure all tests pass, ask the user if questions arise.
