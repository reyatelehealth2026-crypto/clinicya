# Requirements Document

## Introduction

ระบบจัดการบัญชีรายรับ-รายจ่าย (Accounting Management) สำหรับร้านขายยา/ธุรกิจค้าปลีก ครอบคลุม:
- **เจ้าหนี้ (Account Payable - AP)**: จัดการหนี้ที่ต้องจ่ายให้ Supplier จากการสั่งซื้อสินค้า (PO)
- **ลูกหนี้ (Account Receivable - AR)**: จัดการหนี้ที่ลูกค้าค้างชำระจาก Invoice
- **ค่าใช้จ่ายอื่นๆ (Other Expenses)**: ค่าน้ำ ค่าไฟ Internet และค่าใช้จ่ายดำเนินงานอื่นๆ

ระบบนี้เชื่อมต่อกับระบบ Procurement (PO/GR) และระบบ Shop Orders ที่มีอยู่แล้ว

## Glossary

- **Account Payable (AP)**: เจ้าหนี้การค้า - ยอดเงินที่ต้องจ่ายให้ Supplier
- **Account Receivable (AR)**: ลูกหนี้การค้า - ยอดเงินที่ลูกค้าค้างชำระ
- **Invoice**: ใบแจ้งหนี้ที่ออกให้ลูกค้า
- **Payment Voucher**: ใบสำคัญจ่าย - เอกสารบันทึกการจ่ายเงินให้เจ้าหนี้
- **Receipt Voucher**: ใบสำคัญรับ - เอกสารบันทึกการรับเงินจากลูกหนี้
- **Expense**: ค่าใช้จ่าย - รายจ่ายที่ไม่ใช่การซื้อสินค้า
- **Expense Category**: หมวดหมู่ค่าใช้จ่าย เช่น ค่าสาธารณูปโภค, ค่าเช่า
- **Due Date**: วันครบกำหนดชำระ
- **Aging Report**: รายงานอายุหนี้ - แสดงหนี้ค้างชำระแยกตามช่วงเวลา
- **Supplier**: ผู้ขายสินค้า/วัตถุดิบให้ร้าน
- **Customer**: ลูกค้าที่ซื้อสินค้าจากร้าน
- **Credit Term**: ระยะเวลาเครดิต (จำนวนวันที่ให้เครดิต)
- **Accounting System**: ระบบบัญชีที่จัดการ AP, AR และ Expenses

## Requirements

### Requirement 1: Account Payable Management (เจ้าหนี้)

**User Story:** As a store owner, I want to track money owed to suppliers, so that I can manage cash flow and pay suppliers on time.

#### Acceptance Criteria

1. WHEN a Purchase Order is received (GR completed) THEN the Accounting System SHALL create an AP record with supplier info, amount, and due date calculated from credit terms
2. WHEN viewing AP list THEN the Accounting System SHALL display all outstanding payables sorted by due date with supplier name, amount, and days until due
3. WHEN a user records a payment to supplier THEN the Accounting System SHALL create a Payment Voucher, reduce AP balance, and update payment status
4. WHEN AP is partially paid THEN the Accounting System SHALL track remaining balance and maintain payment history
5. WHEN AP is fully paid THEN the Accounting System SHALL mark the AP record as closed and record the closing date

### Requirement 2: Account Receivable Management (ลูกหนี้)

**User Story:** As a store owner, I want to track money owed by customers, so that I can follow up on payments and manage receivables.

#### Acceptance Criteria

1. WHEN a credit sale is made (Invoice created with payment_status not paid) THEN the Accounting System SHALL create an AR record with customer info, amount, and due date
2. WHEN viewing AR list THEN the Accounting System SHALL display all outstanding receivables sorted by due date with customer name, amount, and days overdue
3. WHEN a customer makes a payment (slip uploaded or cash received) THEN the Accounting System SHALL create a Receipt Voucher, reduce AR balance, and update payment status
4. WHEN AR is partially paid THEN the Accounting System SHALL track remaining balance and maintain payment history
5. WHEN AR is fully paid THEN the Accounting System SHALL mark the AR record as closed and record the closing date

### Requirement 3: Other Expenses Management (ค่าใช้จ่ายอื่นๆ)

**User Story:** As a store owner, I want to record and categorize operating expenses, so that I can track business costs and analyze spending.

#### Acceptance Criteria

1. WHEN a user creates an expense record THEN the Accounting System SHALL store expense category, amount, date, description, and optional attachment
2. WHEN viewing expense list THEN the Accounting System SHALL display expenses filterable by category, date range, and payment status
3. WHEN a user creates an expense category THEN the Accounting System SHALL store category name, description, and default expense type
4. THE Accounting System SHALL provide default expense categories including utilities (ค่าสาธารณูปโภค), rent (ค่าเช่า), salary (เงินเดือน), and miscellaneous (อื่นๆ)
5. WHEN an expense is recorded THEN the Accounting System SHALL allow marking as paid or unpaid with optional due date

### Requirement 4: Payment Processing

**User Story:** As a store owner, I want to process payments efficiently, so that I can keep accurate records of all money movements.

#### Acceptance Criteria

1. WHEN recording a payment THEN the Accounting System SHALL capture payment method (cash, transfer, cheque), reference number, and payment date
2. WHEN a payment is recorded THEN the Accounting System SHALL generate a unique voucher number in format PV-YYYYMMDD-XXXX for payments or RV-YYYYMMDD-XXXX for receipts
3. WHEN viewing payment history THEN the Accounting System SHALL display all payments with voucher number, date, amount, and related AP/AR reference
4. WHEN a payment slip is attached THEN the Accounting System SHALL store the file reference and link it to the payment record

### Requirement 5: Aging Reports

**User Story:** As a store owner, I want to see aging reports, so that I can identify overdue accounts and take action.

#### Acceptance Criteria

1. WHEN viewing AP aging report THEN the Accounting System SHALL display payables grouped by age brackets: current, 1-30 days, 31-60 days, 61-90 days, over 90 days
2. WHEN viewing AR aging report THEN the Accounting System SHALL display receivables grouped by age brackets: current, 1-30 days, 31-60 days, 61-90 days, over 90 days
3. WHEN generating aging report THEN the Accounting System SHALL calculate totals for each age bracket and grand total
4. WHEN an account is overdue THEN the Accounting System SHALL highlight the record with visual indicator based on severity

### Requirement 6: Dashboard and Summary

**User Story:** As a store owner, I want to see a financial summary dashboard, so that I can quickly understand my business's financial position.

#### Acceptance Criteria

1. WHEN viewing accounting dashboard THEN the Accounting System SHALL display total AP, total AR, and net position (AR - AP)
2. WHEN viewing dashboard THEN the Accounting System SHALL show upcoming payments due within 7 days
3. WHEN viewing dashboard THEN the Accounting System SHALL show overdue amounts for both AP and AR
4. WHEN viewing dashboard THEN the Accounting System SHALL display monthly expense summary by category

### Requirement 7: Data Serialization and Storage

**User Story:** As a developer, I want reliable data storage, so that financial records are accurately persisted and retrievable.

#### Acceptance Criteria

1. WHEN storing payment details THEN the Accounting System SHALL serialize payment metadata to JSON format
2. WHEN retrieving payment details THEN the Accounting System SHALL deserialize JSON data back to original structure
3. WHEN storing expense attachments THEN the Accounting System SHALL store file path reference in database and file in uploads directory

### Requirement 8: Integration with Existing Systems

**User Story:** As a store owner, I want the accounting system integrated with existing PO and Orders, so that I have a unified view of finances.

#### Acceptance Criteria

1. WHEN a GR (Goods Receive) is completed THEN the Accounting System SHALL automatically create corresponding AP record linked to the PO
2. WHEN an Invoice is created from shop order THEN the Accounting System SHALL automatically create corresponding AR record linked to the transaction
3. WHEN viewing AP detail THEN the Accounting System SHALL show link to original PO and GR records
4. WHEN viewing AR detail THEN the Accounting System SHALL show link to original transaction/order record
