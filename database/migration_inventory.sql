-- Migration: Inventory Management System
-- Version: 1.0
-- Date: 2025-12-26

-- =====================================================
-- 1. Suppliers Table
-- =====================================================
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT NULL,
    code VARCHAR(20) UNIQUE,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255),
    phone VARCHAR(50),
    email VARCHAR(255),
    address TEXT,
    tax_id VARCHAR(20),
    payment_terms INT DEFAULT 30 COMMENT 'วันครบกำหนดชำระ',
    total_purchase_amount DECIMAL(15,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supplier_code (code),
    INDEX idx_supplier_active (is_active),
    INDEX idx_supplier_line_account (line_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. Purchase Orders Table
-- =====================================================
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT NULL,
    po_number VARCHAR(30) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    status ENUM('draft', 'submitted', 'partial', 'completed', 'cancelled') DEFAULT 'draft',
    order_date DATE NOT NULL,
    expected_date DATE,
    subtotal DECIMAL(15,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) DEFAULT 0,
    notes TEXT,
    cancel_reason TEXT,
    created_by INT,
    submitted_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_po_number (po_number),
    INDEX idx_po_status (status),
    INDEX idx_po_supplier (supplier_id),
    INDEX idx_po_line_account (line_account_id),
    INDEX idx_po_order_date (order_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 3. Purchase Order Items Table
-- =====================================================
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    product_id INT NOT NULL COMMENT 'FK to business_items.id',
    quantity INT NOT NULL,
    received_quantity INT DEFAULT 0,
    unit_cost DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL,
    notes TEXT,
    INDEX idx_poi_po (po_id),
    INDEX idx_poi_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 4. Goods Receives Table
-- =====================================================
CREATE TABLE IF NOT EXISTS goods_receives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT NULL,
    gr_number VARCHAR(30) UNIQUE NOT NULL,
    po_id INT NOT NULL,
    status ENUM('draft', 'confirmed', 'cancelled') DEFAULT 'draft',
    receive_date DATE NOT NULL,
    notes TEXT,
    received_by INT,
    confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gr_number (gr_number),
    INDEX idx_gr_po (po_id),
    INDEX idx_gr_line_account (line_account_id),
    INDEX idx_gr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 5. Goods Receive Items Table
-- =====================================================
CREATE TABLE IF NOT EXISTS goods_receive_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gr_id INT NOT NULL,
    po_item_id INT NOT NULL,
    product_id INT NOT NULL COMMENT 'FK to business_items.id',
    quantity INT NOT NULL,
    notes TEXT,
    INDEX idx_gri_gr (gr_id),
    INDEX idx_gri_po_item (po_item_id),
    INDEX idx_gri_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 6. Stock Adjustments Table
-- =====================================================
CREATE TABLE IF NOT EXISTS stock_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT NULL,
    adjustment_number VARCHAR(30) UNIQUE NOT NULL,
    adjustment_type ENUM('increase', 'decrease') NOT NULL,
    product_id INT NOT NULL COMMENT 'FK to business_items.id',
    quantity INT NOT NULL,
    reason ENUM('physical_count', 'damaged', 'expired', 'lost', 'found', 'correction', 'other') NOT NULL,
    reason_detail TEXT,
    stock_before INT NOT NULL,
    stock_after INT NOT NULL,
    status ENUM('draft', 'confirmed', 'cancelled') DEFAULT 'draft',
    created_by INT,
    confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_adj_number (adjustment_number),
    INDEX idx_adj_product (product_id),
    INDEX idx_adj_line_account (line_account_id),
    INDEX idx_adj_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. Stock Movements Table (Audit Trail)
-- =====================================================
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT NULL,
    product_id INT NOT NULL COMMENT 'FK to business_items.id',
    movement_type ENUM('receive', 'sale', 'adjustment_in', 'adjustment_out', 'return', 'transfer') NOT NULL,
    quantity INT NOT NULL COMMENT 'บวก=เข้า, ลบ=ออก',
    stock_before INT NOT NULL,
    stock_after INT NOT NULL,
    reference_type VARCHAR(50) COMMENT 'goods_receive, order, adjustment',
    reference_id INT,
    reference_number VARCHAR(50),
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sm_product (product_id),
    INDEX idx_sm_type (movement_type),
    INDEX idx_sm_reference (reference_type, reference_id),
    INDEX idx_sm_created (created_at),
    INDEX idx_sm_line_account (line_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 8. Update business_items Table
-- =====================================================
-- Add reorder_point column if not exists
SET @dbname = DATABASE();
SET @tablename = 'business_items';
SET @columnname = 'reorder_point';
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
    'SELECT 1',
    'ALTER TABLE business_items ADD COLUMN reorder_point INT DEFAULT 5 AFTER min_stock'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add supplier_id column if not exists
SET @columnname = 'supplier_id';
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
    'SELECT 1',
    'ALTER TABLE business_items ADD COLUMN supplier_id INT DEFAULT NULL AFTER line_account_id'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add indexes (ignore if exists)
-- ALTER TABLE business_items ADD INDEX idx_product_supplier (supplier_id);
-- ALTER TABLE business_items ADD INDEX idx_product_reorder (reorder_point);

-- =====================================================
-- 9. Insert Default Supplier (Optional)
-- =====================================================
INSERT IGNORE INTO suppliers (code, name, contact_person, phone, is_active) 
VALUES ('SUP-DEFAULT', 'Supplier ทั่วไป', '-', '-', 1);
