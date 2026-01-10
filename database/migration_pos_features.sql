-- POS Additional Features Migration
-- Hold/Park, Price Override, Cash Movements, Reprint tracking

-- Add hold columns to pos_transactions
ALTER TABLE pos_transactions 
ADD COLUMN IF NOT EXISTS hold_note VARCHAR(255) NULL AFTER status,
ADD COLUMN IF NOT EXISTS hold_at DATETIME NULL AFTER hold_note;

-- Add price override columns to pos_transaction_items
ALTER TABLE pos_transaction_items 
ADD COLUMN IF NOT EXISTS original_price DECIMAL(12,2) NULL AFTER unit_price,
ADD COLUMN IF NOT EXISTS price_override_reason VARCHAR(255) NULL AFTER discount_amount,
ADD COLUMN IF NOT EXISTS price_override_by INT NULL AFTER price_override_reason,
ADD COLUMN IF NOT EXISTS price_override_at DATETIME NULL AFTER price_override_by;

-- Add reprint tracking to pos_transactions
ALTER TABLE pos_transactions 
ADD COLUMN IF NOT EXISTS reprint_count INT DEFAULT 0 AFTER completed_at,
ADD COLUMN IF NOT EXISTS last_reprint_at DATETIME NULL AFTER reprint_count,
ADD COLUMN IF NOT EXISTS last_reprint_by INT NULL AFTER last_reprint_at;

-- Add cash adjustments to pos_shifts
ALTER TABLE pos_shifts 
ADD COLUMN IF NOT EXISTS cash_adjustments DECIMAL(12,2) DEFAULT 0 AFTER total_refunds;

-- Create cash movements table
CREATE TABLE IF NOT EXISTS pos_cash_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NOT NULL,
    shift_id INT NOT NULL,
    movement_type ENUM('in', 'out') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_shift (shift_id),
    INDEX idx_type (movement_type),
    INDEX idx_created (created_at),
    
    FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update status enum to include 'hold'
-- Note: MySQL doesn't support IF NOT EXISTS for MODIFY, so we use a safe approach
-- This will add 'hold' status if not already present
ALTER TABLE pos_transactions 
MODIFY COLUMN status ENUM('draft', 'hold', 'pending', 'completed', 'voided', 'refunded') DEFAULT 'draft';

-- Add returned_quantity column if not exists (for older installations)
ALTER TABLE pos_transaction_items 
ADD COLUMN IF NOT EXISTS returned_quantity INT DEFAULT 0 AFTER quantity;
