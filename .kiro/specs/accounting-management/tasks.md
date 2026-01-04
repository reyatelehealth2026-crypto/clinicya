# Implementation Plan

## Accounting Management System

- [x] 1. Database Setup






  - [x] 1.1 Create migration file for accounting tables

    - Create `database/migration_accounting.sql` with all 6 tables
    - Include indexes and foreign key constraints
    - Add default expense categories insert statements
    - _Requirements: 3.4, 7.3_

  - [x] 1.2 Create installation script

    - Create `install/run_accounting_migration.php`
    - Check if tables exist before creating
    - _Requirements: 3.4_


- [x] 2. Expense Category Service





  - [x] 2.1 Create ExpenseCategoryService class

    - Create `classes/ExpenseCategoryService.php`
    - Implement CRUD operations
    - Implement `initializeDefaults()` method
    - _Requirements: 3.3, 3.4_
  - [ ]* 2.2 Write property test for expense category
    - **Property 7: Expense Creation and Storage** (category part)
    - **Validates: Requirements 3.3**


- [x] 3. Expense Service





  - [x] 3.1 Create ExpenseService class

    - Create `classes/ExpenseService.php`
    - Implement create, update, delete methods
    - Implement getAll with filters (category, date range, status)
    - Implement getMonthlySummary
    - Generate expense number format: EXP-YYYYMMDD-XXXX
    - _Requirements: 3.1, 3.2, 3.5_
  - [ ]* 3.2 Write property test for expense creation
    - **Property 7: Expense Creation and Storage**
    - **Validates: Requirements 3.1**


- [x] 4. Payment Voucher Service





  - [x] 4.1 Create PaymentVoucherService class

    - Create `classes/PaymentVoucherService.php`
    - Implement voucher number generation (PV-YYYYMMDD-XXXX)
    - Implement create and getById methods
    - Implement getHistory with filters
    - _Requirements: 4.1, 4.2, 4.3, 4.4_
  - [ ]* 4.2 Write property test for voucher number uniqueness
    - **Property 10: Voucher Number Uniqueness** (Payment Voucher)
    - **Validates: Requirements 4.2**


- [x] 5. Receipt Voucher Service





  - [x] 5.1 Create ReceiptVoucherService class

    - Create `classes/ReceiptVoucherService.php`
    - Implement voucher number generation (RV-YYYYMMDD-XXXX)
    - Implement create and getById methods
    - Implement getHistory with filters
    - _Requirements: 4.1, 4.2, 4.3, 4.4_
  - [ ]* 5.2 Write property test for voucher number uniqueness
    - **Property 10: Voucher Number Uniqueness** (Receipt Voucher)
    - **Validates: Requirements 4.2**

- [ ] 6. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.



- [x] 7. Account Payable Service




  - [x] 7.1 Create AccountPayableService class

    - Create `classes/AccountPayableService.php`
    - Implement createFromGR method (auto-create from Goods Receive)
    - Implement getAll, getById methods
    - Implement recordPayment method (create voucher, update balance)
    - Implement getAgingReport, getUpcomingDue, getOverdue methods
    - Generate AP number format: AP-YYYYMMDD-XXXX
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 5.1_
  - [ ]* 7.2 Write property test for AP creation from GR
    - **Property 1: AP Creation from GR**
    - **Validates: Requirements 1.1, 8.1**
  - [ ]* 7.3 Write property test for AP payment processing
    - **Property 3: AP Payment Processing**
    - **Validates: Requirements 1.3, 1.4, 1.5, 4.2**


- [x] 8. Account Receivable Service











  - [x] 8.1 Create AccountReceivableService class


    - Create `classes/AccountReceivableService.php`
    - Implement createFromTransaction method (auto-create from order)
    - Implement getAll, getById methods
    - Implement recordReceipt method (create voucher, update balance)
    - Implement getAgingReport, getUpcomingDue, getOverdue methods
    - Generate AR number format: AR-YYYYMMDD-XXXX
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 5.2_
  - [ ]* 8.2 Write property test for AR creation from transaction
    - **Property 2: AR Creation from Transaction**
    - **Validates: Requirements 2.1, 8.2**
  - [ ]* 8.3 Write property test for AR receipt processing
    - **Property 4: AR Receipt Processing**
    - **Validates: Requirements 2.3, 2.4, 2.5, 4.2**


- [x] 9. Aging Report Logic





  - [x] 9.1 Implement aging calculation helper

    - Create shared aging bracket calculation function
    - Support brackets: current, 1-30, 31-60, 61-90, 90+ days
    - _Requirements: 5.1, 5.2, 5.3_
  - [ ]* 9.2 Write property test for aging report grouping
    - **Property 5: Aging Report Grouping**
    - **Validates: Requirements 5.1, 5.2, 5.3**


- [x] 10. Checkpoint - Ensure all tests pass




  - Ensure all tests pass, ask the user if questions arise.



- [x] 11. Dashboard Service




  - [x] 11.1 Create AccountingDashboardService class

    - Create `classes/AccountingDashboardService.php`
    - Implement getSummary (total AP, AR, net position)
    - Implement getUpcomingPayments
    - Implement getOverdueSummary
    - Implement getExpenseSummaryByCategory
    - _Requirements: 6.1, 6.2, 6.3, 6.4_
  - [ ]* 11.2 Write property test for dashboard totals
    - **Property 6: Dashboard Totals Consistency**
    - **Validates: Requirements 6.1**


- [x] 12. Integration with Existing Systems




  - [x] 12.1 Hook AP creation to GR completion


    - Modify `includes/procurement/gr.php` to trigger AP creation
    - _Requirements: 8.1_
  - [x] 12.2 Hook AR creation to credit sales


    - Modify order processing to trigger AR creation for credit sales
    - _Requirements: 8.2_
  - [ ]* 12.3 Write property test for record linking
    - **Property 9: Record Linking Integrity**
    - **Validates: Requirements 8.3, 8.4**


- [x] 13. API Endpoints





  - [x] 13.1 Create accounting API handler

    - Create `api/accounting.php`
    - Implement AP endpoints: list, detail, record_payment
    - Implement AR endpoints: list, detail, record_receipt
    - Implement Expense endpoints: list, create, update, delete
    - Implement Dashboard endpoint: summary
    - _Requirements: 1.2, 2.2, 3.2, 6.1_

- [x] 14. Admin UI - Main Page






  - [x] 14.1 Create accounting main page with tabs

    - Create `accounting.php` with tab-based UI
    - Tabs: Dashboard, เจ้าหนี้ (AP), ลูกหนี้ (AR), ค่าใช้จ่าย (Expenses)
    - _Requirements: 1.2, 2.2, 3.2, 6.1_




- [x] 15. Admin UI - Dashboard Tab



  - [x] 15.1 Create dashboard tab content

    - Create `includes/accounting/dashboard.php`
    - Display summary cards (Total AP, AR, Net Position)
    - Display upcoming payments due within 7 days
    - Display overdue amounts
    - Display monthly expense chart by category
    - _Requirements: 6.1, 6.2, 6.3, 6.4_


- [x] 16. Admin UI - Account Payable Tab





  - [x] 16.1 Create AP tab content

    - Create `includes/accounting/ap.php`
    - Display AP list with filters (status, supplier, date range)
    - Show aging indicators
    - Add payment recording modal
    - _Requirements: 1.2, 1.3, 5.1, 5.4_



- [x] 17. Admin UI - Account Receivable Tab




  - [x] 17.1 Create AR tab content

    - Create `includes/accounting/ar.php`
    - Display AR list with filters (status, customer, date range)
    - Show aging indicators
    - Add receipt recording modal
    - _Requirements: 2.2, 2.3, 5.2, 5.4_



- [x] 18. Admin UI - Expenses Tab




  - [x] 18.1 Create expenses tab content

    - Create `includes/accounting/expenses.php`
    - Display expense list with filters (category, date range, status)
    - Add expense creation/edit modal
    - Add category management section
    - _Requirements: 3.1, 3.2, 3.3, 3.5_


- [x] 19. Checkpoint - Ensure all tests pass




  - Ensure all tests pass, ask the user if questions arise.

- [x] 20. Metadata Serialization






  - [x] 20.1 Implement metadata helper functions

    - Create helper for JSON serialization/deserialization
    - Handle payment metadata storage
    - _Requirements: 7.1, 7.2_
  - [ ]* 20.2 Write property test for metadata round-trip
    - **Property 8: Payment Metadata Round-Trip**
    - **Validates: Requirements 7.1, 7.2**



- [x] 21. Add Menu Navigation




  - [x] 21.1 Add accounting menu to sidebar

    - Update `includes/header.php` to include accounting menu item
    - Add icon and link to accounting.php
    - _Requirements: N/A (UI integration)_


- [x] 22. Final Checkpoint - Ensure all tests pass






  - Ensure all tests pass, ask the user if questions arise.
