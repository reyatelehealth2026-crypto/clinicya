-- =============================================
-- Landing Page Upgrade Migration
-- Version: 1.0
-- Description: Creates tables for FAQ, Testimonials, and Landing Settings
-- =============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- LANDING FAQs TABLE (คำถามที่พบบ่อย)
-- =============================================
CREATE TABLE IF NOT EXISTS `landing_faqs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NULL,
    `question` VARCHAR(500) NOT NULL,
    `answer` TEXT NOT NULL,
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_faq_account` (`line_account_id`),
    INDEX `idx_faq_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- LANDING TESTIMONIALS TABLE (รีวิวจากลูกค้า)
-- =============================================
CREATE TABLE IF NOT EXISTS `landing_testimonials` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NULL,
    `customer_name` VARCHAR(100) NOT NULL,
    `customer_avatar` VARCHAR(255) NULL,
    `rating` TINYINT DEFAULT 5,
    `review_text` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `source` VARCHAR(50) NULL COMMENT 'google, facebook, manual',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `approved_at` TIMESTAMP NULL,
    INDEX `idx_testimonial_account` (`line_account_id`),
    INDEX `idx_testimonial_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- LANDING SETTINGS TABLE (ตั้งค่าหน้า Landing)
-- =============================================
CREATE TABLE IF NOT EXISTS `landing_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `line_account_id` INT NULL,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_landing_setting` (`line_account_id`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================
-- DEFAULT FAQ ITEMS (ตัวอย่างคำถามที่พบบ่อย)
-- =============================================
INSERT INTO `landing_faqs` (`question`, `answer`, `sort_order`, `is_active`) VALUES
('ร้านยาเปิดให้บริการเวลาใด?', 'ร้านยาเปิดให้บริการทุกวัน ตั้งแต่เวลา 09:00 - 21:00 น. สามารถสอบถามเภสัชกรผ่าน LINE ได้ตลอดเวลาทำการ', 1, 1),
('สามารถสั่งยาออนไลน์ได้อย่างไร?', 'สามารถสั่งยาผ่าน LINE Official Account ของร้าน โดยแชทสอบถามเภสัชกร หรือเลือกสินค้าจากร้านค้าออนไลน์ได้เลย', 2, 1),
('มีบริการจัดส่งยาถึงบ้านหรือไม่?', 'มีบริการจัดส่งยาถึงบ้านทั่วประเทศ โดยจัดส่งผ่านขนส่งเอกชน ใช้เวลา 1-3 วันทำการ', 3, 1),
('ต้องมีใบสั่งยาจากแพทย์หรือไม่?', 'ยาบางประเภทต้องมีใบสั่งยาจากแพทย์ เภสัชกรจะแจ้งให้ทราบหากยาที่ต้องการจำเป็นต้องใช้ใบสั่งยา', 4, 1),
('มีบริการปรึกษาเภสัชกรฟรีหรือไม่?', 'มีบริการปรึกษาเภสัชกรฟรีผ่าน LINE และ Video Call สามารถนัดหมายล่วงหน้าได้', 5, 1)
ON DUPLICATE KEY UPDATE `question` = VALUES(`question`);

-- =============================================
-- DEFAULT TESTIMONIALS (ตัวอย่างรีวิว)
-- =============================================
INSERT INTO `landing_testimonials` (`customer_name`, `rating`, `review_text`, `status`, `source`, `approved_at`) VALUES
('คุณสมชาย', 5, 'บริการดีมาก เภสัชกรให้คำปรึกษาละเอียด ส่งยาถึงบ้านรวดเร็ว ประทับใจมากครับ', 'approved', 'manual', NOW()),
('คุณสมหญิง', 5, 'ใช้บริการมาหลายครั้งแล้ว ราคายาถูกกว่าร้านอื่น เภสัชกรใจดี ตอบคำถามรวดเร็ว', 'approved', 'manual', NOW()),
('คุณวิชัย', 4, 'สะดวกมากที่สั่งยาผ่าน LINE ได้ ไม่ต้องเดินทางไปร้าน เหมาะกับคนที่ไม่มีเวลา', 'approved', 'manual', NOW())
ON DUPLICATE KEY UPDATE `customer_name` = VALUES(`customer_name`);
