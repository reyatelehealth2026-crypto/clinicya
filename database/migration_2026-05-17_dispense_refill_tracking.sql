-- ---------------------------------------------------------------------------
-- Migration: 2026-05-17 dispense refill tracking
-- ---------------------------------------------------------------------------
-- เพิ่ม columns `source` / `source_ref_id` ใน `medication_refill_tracking`
-- เพื่อให้ระบบจ่ายยา (dispense) สามารถลงทะเบียนยาเพื่อแจ้งเตือนล่วงหน้า 3 วัน
-- ก่อนยาหมด (ดูคำนวณวันใน classes/RefillTrackingHelper.php และ
-- cron/medication_refill_reminder.php)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `medication_refill_tracking` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `line_user_id` VARCHAR(50),
  `line_account_id` INT,
  `product_id` INT NOT NULL,
  `product_name` VARCHAR(255),
  `quantity_purchased` INT DEFAULT 0,
  `daily_dosage` INT DEFAULT 1 COMMENT 'จำนวนที่ทานต่อวัน (รวมทุกมื้อ)',
  `purchase_date` DATE,
  `estimated_end_date` DATE,
  `reminder_sent_at` TIMESTAMP NULL,
  `order_id` INT,
  `source` VARCHAR(50) DEFAULT NULL COMMENT 'dispense | order | manual',
  `source_ref_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_end_date` (`estimated_end_date`),
  INDEX `idx_product` (`product_id`),
  INDEX `idx_user_product` (`user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ถ้าตารางมีอยู่แล้วก่อนหน้า (สร้างโดย cron เดิม) — เพิ่ม columns/indexes ที่ขาด
-- MySQL 8.0+ และ MariaDB 10.3+ รองรับ IF NOT EXISTS ใน ADD COLUMN/INDEX
ALTER TABLE `medication_refill_tracking`
  ADD COLUMN IF NOT EXISTS `source` VARCHAR(50) DEFAULT NULL COMMENT 'dispense | order | manual',
  ADD COLUMN IF NOT EXISTS `source_ref_id` INT DEFAULT NULL;

ALTER TABLE `medication_refill_tracking`
  ADD INDEX IF NOT EXISTS `idx_user_product` (`user_id`, `product_id`);
