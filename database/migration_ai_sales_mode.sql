-- Migration: Add AI Sales Mode and Business Knowledge
-- Date: 2026-01-12

-- Add new columns to ai_settings
ALTER TABLE ai_settings 
ADD COLUMN IF NOT EXISTS `ai_mode` ENUM('pharmacist', 'sales', 'support') DEFAULT 'pharmacist' COMMENT 'โหมด AI: เภสัชกร/พนักงานขาย/ซัพพอร์ต' AFTER `pharmacy_mode`,
ADD COLUMN IF NOT EXISTS `business_info` TEXT COMMENT 'ข้อมูลธุรกิจ เช่น ชื่อร้าน ที่อยู่ เวลาทำการ' AFTER `ai_mode`,
ADD COLUMN IF NOT EXISTS `product_knowledge` TEXT COMMENT 'ข้อมูลสินค้าเพิ่มเติม' AFTER `business_info`,
ADD COLUMN IF NOT EXISTS `sales_prompt` TEXT COMMENT 'Prompt สำหรับโหมดขาย' AFTER `product_knowledge`,
ADD COLUMN IF NOT EXISTS `auto_load_products` TINYINT(1) DEFAULT 1 COMMENT 'โหลดสินค้าอัตโนมัติ' AFTER `sales_prompt`,
ADD COLUMN IF NOT EXISTS `product_load_limit` INT DEFAULT 50 COMMENT 'จำนวนสินค้าที่โหลด' AFTER `auto_load_products`;
