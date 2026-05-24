-- =============================================================================
-- Migration: Thai-standard accounting documents (เอกสาร) for REYA
-- Date:      2026-05-24
-- Scope:    business_documents, business_document_items, document_sequences,
--          shop_tax_info
--
-- Tables shared across QT / BL / INV / RE / TAX / DN / CN / PO / GR / DNP / CNP.
-- All tables multi-tenant scoped via line_account_id.
-- Re-run safe: every CREATE uses IF NOT EXISTS; idempotent on partial deploys.
-- Charset: utf8mb4_unicode_ci (Thai language).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) business_documents — master record (one row per accounting document)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `business_documents` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `line_account_id` INT NOT NULL COMMENT 'tenant scope — FK line_accounts.id',
  `doc_type` ENUM('QT','BL','INV','RE','TAX','DN','CN','PO','GR','DNP','CNP') NOT NULL
    COMMENT 'QT=quotation, BL=billing-note, INV=invoice, RE=receipt, TAX=tax-invoice, DN=debit-note, CN=credit-note, PO=purchase-order, GR=goods-receipt, DNP=debit-note-purchase, CNP=credit-note-purchase',
  `doc_number` VARCHAR(30) NOT NULL COMMENT 'human-facing number, e.g. QT-2605-0001',
  `ref_transaction_id` INT NULL COMMENT 'FK transactions.id when bound to an order',
  `ref_doc_id` INT NULL COMMENT 'FK business_documents.id (e.g. INV references BL)',

  -- Customer snapshot (frozen at creation time for legal continuity)
  `customer_user_id` INT NULL COMMENT 'FK users.id when LINE customer',
  `customer_name` VARCHAR(255) DEFAULT NULL COMMENT 'ชื่อลูกค้า',
  `customer_tax_id` VARCHAR(20) DEFAULT NULL COMMENT 'เลขประจำตัวผู้เสียภาษี 13 หลัก',
  `customer_branch_code` VARCHAR(20) DEFAULT NULL COMMENT 'รหัสสาขาลูกค้า — 00000 = สำนักงานใหญ่',
  `customer_address` TEXT DEFAULT NULL,
  `customer_phone` VARCHAR(50) DEFAULT NULL,
  `customer_email` VARCHAR(100) DEFAULT NULL,

  -- Dates
  `issue_date` DATE NOT NULL COMMENT 'วันที่ออกเอกสาร',
  `due_date` DATE NULL COMMENT 'วันที่ครบกำหนด (BL/INV)',
  `valid_until` DATE NULL COMMENT 'ใช้ได้ถึงวันที่ (QT)',

  -- Money — DECIMAL(12,2) per spec
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'รวมก่อนส่วนลด/ภาษี',
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ส่วนลดรวม',
  `vat_rate` DECIMAL(4,2) NOT NULL DEFAULT 7.00 COMMENT 'อัตรา VAT %',
  `vat_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ยอด VAT',
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ยอดสุทธิรวม VAT',

  -- Payment metadata (mainly for RE)
  `payment_method` VARCHAR(50) DEFAULT NULL COMMENT 'cash / transfer / credit_card / qr / cheque',
  `payment_ref` VARCHAR(100) DEFAULT NULL COMMENT 'เลขอ้างอิงการชำระ',

  -- Workflow
  `status` ENUM('pending_approval','approved','cancelled') NOT NULL DEFAULT 'pending_approval'
    COMMENT 'รออนุมัติ / อนุมัติ / ยกเลิก',
  `note` TEXT DEFAULT NULL COMMENT 'หมายเหตุ (พิมพ์บนเอกสาร)',
  `internal_note` TEXT DEFAULT NULL COMMENT 'หมายเหตุภายใน (ไม่พิมพ์)',

  -- Audit
  `created_by` INT DEFAULT NULL COMMENT 'admin_users.id',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_by` INT NULL,
  `approved_at` TIMESTAMP NULL,
  `cancelled_by` INT NULL,
  `cancelled_at` TIMESTAMP NULL,
  `cancel_reason` TEXT NULL,
  `pdf_path` VARCHAR(500) NULL COMMENT 'cached generated PDF/HTML path (optional)',

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_number_account` (`line_account_id`, `doc_number`),
  KEY `idx_doc_line_account_type` (`line_account_id`, `doc_type`, `issue_date`),
  KEY `idx_doc_status` (`status`),
  KEY `idx_doc_customer` (`customer_user_id`),
  KEY `idx_doc_ref_transaction` (`ref_transaction_id`),
  KEY `idx_doc_ref_doc` (`ref_doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='เอกสารบัญชี (ใบเสนอราคา/ใบกำกับภาษี/ใบเสร็จ ฯลฯ)';

-- -----------------------------------------------------------------------------
-- 2) business_document_items — line items per document
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `business_document_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `document_id` INT NOT NULL COMMENT 'FK business_documents.id',
  `line_no` INT NOT NULL DEFAULT 1 COMMENT 'ลำดับบรรทัด (1..N)',
  `product_id` INT NULL COMMENT 'FK business_items.id (nullable for free-text services)',
  `product_sku` VARCHAR(100) DEFAULT NULL,
  `product_name` VARCHAR(255) NOT NULL COMMENT 'ชื่อสินค้า/บริการ',
  `description` TEXT DEFAULT NULL COMMENT 'รายละเอียดเพิ่มเติม',
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit` VARCHAR(50) DEFAULT NULL COMMENT 'หน่วย เช่น กล่อง, ขวด, เม็ด',
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '(qty * unit_price) - discount_amount',
  PRIMARY KEY (`id`),
  KEY `idx_di_document` (`document_id`, `line_no`),
  KEY `idx_di_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='รายการสินค้าในเอกสาร';

-- -----------------------------------------------------------------------------
-- 3) document_sequences — per-tenant, per-month, per-type running number
--    Row is locked SELECT ... FOR UPDATE in genDocNumber() to prevent races.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_sequences` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `line_account_id` INT NOT NULL,
  `doc_type` VARCHAR(10) NOT NULL,
  `year_month` CHAR(4) NOT NULL COMMENT 'YYMM in Buddhist year tail e.g. 2605 = พ.ค. 2569',
  `last_seq` INT NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seq_tenant_type_month` (`line_account_id`, `doc_type`, `year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ตัวเลขลำดับเอกสาร (atomic counter)';

-- -----------------------------------------------------------------------------
-- 4) shop_tax_info — per-tenant business identity printed on documents
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shop_tax_info` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `line_account_id` INT NOT NULL,
  `business_name` VARCHAR(255) DEFAULT NULL COMMENT 'ชื่อกิจการ',
  `business_name_en` VARCHAR(255) DEFAULT NULL,
  `tax_id` VARCHAR(20) DEFAULT NULL COMMENT 'เลขประจำตัวผู้เสียภาษี 13 หลัก',
  `branch_code` VARCHAR(20) NOT NULL DEFAULT '00000' COMMENT '00000 = สำนักงานใหญ่',
  `address` TEXT DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `logo_url` VARCHAR(500) DEFAULT NULL,
  `authorized_signer` VARCHAR(255) DEFAULT NULL COMMENT 'ผู้มีอำนาจลงนาม',
  `signer_position` VARCHAR(100) DEFAULT NULL COMMENT 'ตำแหน่ง',
  `is_vat_registered` TINYINT(1) NOT NULL DEFAULT 0,
  `default_vat_rate` DECIMAL(4,2) NOT NULL DEFAULT 7.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_tax_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ข้อมูลธุรกิจสำหรับเอกสารทางภาษี (ต่อ tenant)';
