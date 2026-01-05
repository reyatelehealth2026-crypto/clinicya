-- Migration: Add CNY API compatible fields to business_items
-- Run this migration to support full CNY API data sync

-- Add name_en column for English product name
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS name_en VARCHAR(500) NULL AFTER name;

-- Ensure other CNY fields exist
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS generic_name VARCHAR(500) NULL COMMENT 'ชื่อสามัญ/สารสำคัญ';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS usage_instructions TEXT NULL COMMENT 'วิธีใช้';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS manufacturer VARCHAR(255) NULL COMMENT 'ผู้ผลิต';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) NULL;
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS unit VARCHAR(100) NULL COMMENT 'หน่วยจำนวน เช่น ขวด[ 60ML ]';
ALTER TABLE business_items ADD COLUMN IF NOT EXISTS base_unit VARCHAR(50) NULL COMMENT 'หน่วยนับ เช่น ขวด, กล่อง, แผง';

-- Add index for barcode search
CREATE INDEX IF NOT EXISTS idx_business_items_barcode ON business_items(barcode);
