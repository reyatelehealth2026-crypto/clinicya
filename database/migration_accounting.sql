-- =============================================
-- Accounting Management System Migration
-- Version: 1.0
-- Description: Creates tables for AP, AR, Expenses, and Vouchers
-- =============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- EXPENSE CATEGORIES (หมวดหมู่ค่าใช้จ่าย)
-- =============================================
CREATE TABLE IF NOT EXISTS `expense_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `name` VARCHAR(100) NOT NULL,
    `name_en` VARCHAR(100),
    `description` TEXT,
    `expense_type` ENUM('operating', 'administrative', 'financial', 'other') DEFAULT 'operating',
    `is_default` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_exp_cat_active` (`is_active`),
    INDEX `idx_exp_cat_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- EXPENSES (ค่าใช้จ่าย)
-- =============================================
CREATE TABLE IF NOT EXISTS `expenses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `expense_number` VARCHAR(50) UNIQUE NOT NULL,
    `category_id` INT NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `expense_date` DATE NOT NULL,
    `due_date` DATE,
    `description` TEXT,
    `vendor_name` VARCHAR(255),
    `reference_number` VARCHAR(100),
    `attachment_path` VARCHAR(500),
    `payment_status` ENUM('unpaid', 'paid') DEFAULT 'unpaid',
    `payment_voucher_id` INT,
    `notes` TEXT,
    `metadata` JSON,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_exp_category` (`category_id`),
    INDEX `idx_exp_date` (`expense_date`),
    INDEX `idx_exp_status` (`payment_status`),
    INDEX `idx_exp_line_account` (`line_account_id`),
    CONSTRAINT `fk_expense_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================
-- ACCOUNT PAYABLE (เจ้าหนี้)
-- =============================================
CREATE TABLE IF NOT EXISTS `account_payables` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `ap_number` VARCHAR(50) UNIQUE NOT NULL,
    `supplier_id` INT NOT NULL,
    `po_id` INT DEFAULT NULL,
    `gr_id` INT DEFAULT NULL,
    `invoice_number` VARCHAR(100),
    `invoice_date` DATE,
    `due_date` DATE NOT NULL,
    `total_amount` DECIMAL(12,2) NOT NULL,
    `paid_amount` DECIMAL(12,2) DEFAULT 0,
    `balance` DECIMAL(12,2) NOT NULL,
    `status` ENUM('open', 'partial', 'paid', 'cancelled') DEFAULT 'open',
    `notes` TEXT,
    `metadata` JSON,
    `closed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ap_supplier` (`supplier_id`),
    INDEX `idx_ap_status` (`status`),
    INDEX `idx_ap_due_date` (`due_date`),
    INDEX `idx_ap_po` (`po_id`),
    INDEX `idx_ap_gr` (`gr_id`),
    INDEX `idx_ap_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ACCOUNT RECEIVABLE (ลูกหนี้)
-- =============================================
CREATE TABLE IF NOT EXISTS `account_receivables` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `ar_number` VARCHAR(50) UNIQUE NOT NULL,
    `user_id` INT NOT NULL,
    `transaction_id` INT DEFAULT NULL,
    `invoice_number` VARCHAR(100),
    `invoice_date` DATE,
    `due_date` DATE NOT NULL,
    `total_amount` DECIMAL(12,2) NOT NULL,
    `received_amount` DECIMAL(12,2) DEFAULT 0,
    `balance` DECIMAL(12,2) NOT NULL,
    `status` ENUM('open', 'partial', 'paid', 'cancelled') DEFAULT 'open',
    `notes` TEXT,
    `metadata` JSON,
    `closed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ar_user` (`user_id`),
    INDEX `idx_ar_status` (`status`),
    INDEX `idx_ar_due_date` (`due_date`),
    INDEX `idx_ar_transaction` (`transaction_id`),
    INDEX `idx_ar_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================
-- PAYMENT VOUCHERS (ใบสำคัญจ่าย)
-- =============================================
CREATE TABLE IF NOT EXISTS `payment_vouchers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `voucher_number` VARCHAR(50) UNIQUE NOT NULL,
    `voucher_type` ENUM('ap', 'expense') NOT NULL,
    `reference_id` INT NOT NULL COMMENT 'AP ID or Expense ID',
    `payment_date` DATE NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `payment_method` ENUM('cash', 'transfer', 'cheque', 'credit_card') NOT NULL,
    `bank_account` VARCHAR(100),
    `reference_number` VARCHAR(100),
    `cheque_number` VARCHAR(50),
    `cheque_date` DATE,
    `attachment_path` VARCHAR(500),
    `notes` TEXT,
    `metadata` JSON,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pv_type` (`voucher_type`),
    INDEX `idx_pv_ref` (`reference_id`),
    INDEX `idx_pv_date` (`payment_date`),
    INDEX `idx_pv_line_account` (`line_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- RECEIPT VOUCHERS (ใบสำคัญรับ)
-- =============================================
CREATE TABLE IF NOT EXISTS `receipt_vouchers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT DEFAULT NULL,
    `voucher_number` VARCHAR(50) UNIQUE NOT NULL,
    `ar_id` INT NOT NULL,
    `receipt_date` DATE NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `payment_method` ENUM('cash', 'transfer', 'cheque', 'credit_card') NOT NULL,
    `bank_account` VARCHAR(100),
    `reference_number` VARCHAR(100),
    `slip_id` INT COMMENT 'Link to payment_slips table',
    `attachment_path` VARCHAR(500),
    `notes` TEXT,
    `metadata` JSON,
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rv_ar` (`ar_id`),
    INDEX `idx_rv_date` (`receipt_date`),
    INDEX `idx_rv_line_account` (`line_account_id`),
    CONSTRAINT `fk_rv_ar` FOREIGN KEY (`ar_id`) REFERENCES `account_receivables`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


-- =============================================
-- DEFAULT EXPENSE CATEGORIES
-- =============================================
INSERT INTO `expense_categories` (`name`, `name_en`, `expense_type`, `is_default`, `is_active`) VALUES
('ค่าสาธารณูปโภค', 'Utilities', 'operating', 1, 1),
('ค่าเช่า', 'Rent', 'operating', 1, 1),
('เงินเดือน', 'Salary', 'operating', 1, 1),
('ค่าอินเทอร์เน็ต', 'Internet', 'operating', 1, 1),
('ค่าโทรศัพท์', 'Telephone', 'operating', 1, 1),
('ค่าขนส่ง', 'Transportation', 'operating', 1, 1),
('ค่าซ่อมบำรุง', 'Maintenance', 'operating', 1, 1),
('ค่าใช้จ่ายสำนักงาน', 'Office Supplies', 'administrative', 1, 1),
('ค่าธรรมเนียมธนาคาร', 'Bank Fees', 'financial', 1, 1),
('อื่นๆ', 'Miscellaneous', 'other', 1, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
