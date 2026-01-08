-- =============================================
-- LINE CRM Pharmacy - Thai Installation SQL
-- =============================================

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+07:00' */;

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET character_set_connection = utf8mb4;
SET collation_connection = utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- DEFAULT DATA WITH THAI
-- =============================================

-- Clear existing data first
DELETE FROM `settings` WHERE 1=1;
DELETE FROM `item_categories` WHERE 1=1;
DELETE FROM `points_tiers` WHERE 1=1;
DELETE FROM `point_rewards` WHERE 1=1;
DELETE FROM `zone_types` WHERE 1=1;
DELETE FROM `expense_categories` WHERE 1=1;

-- Insert default settings
INSERT INTO `settings` (`key`, `value`, `type`, `description`) VALUES
('site_name', 'LINE CRM Pharmacy', 'string', 'Site Name'),
('timezone', 'Asia/Bangkok', 'string', 'Timezone'),
('currency', 'THB', 'string', 'Currency'),
('points_per_baht', '1', 'number', 'Points per Baht'),
('points_value', '0.1', 'number', 'Points Value'),
('min_redeem_points', '100', 'number', 'Min Redeem Points');

-- Insert default categories (Thai)
INSERT INTO `item_categories` (`id`, `name`, `name_en`, `sort_order`, `is_active`) VALUES
(1, 'ยาสามัญประจำบ้าน', 'OTC Medicines', 1, 1),
(2, 'วิตามินและอาหารเสริม', 'Vitamins & Supplements', 2, 1),
(3, 'เวชสำอาง', 'Cosmeceuticals', 3, 1),
(4, 'อุปกรณ์การแพทย์', 'Medical Devices', 4, 1),
(5, 'ผลิตภัณฑ์ดูแลสุขภาพ', 'Health Care Products', 5, 1);

-- Insert default tiers (Thai)
INSERT INTO `points_tiers` (`id`, `line_account_id`, `name`, `min_points`, `points_multiplier`, `color`, `icon`, `sort_order`) VALUES
(1, NULL, 'Bronze', 0, 1.00, '#CD7F32', 'fa-medal', 1),
(2, NULL, 'Silver', 1000, 1.25, '#C0C0C0', 'fa-medal', 2),
(3, NULL, 'Gold', 5000, 1.50, '#FFD700', 'fa-crown', 3),
(4, NULL, 'Platinum', 15000, 2.00, '#E5E4E2', 'fa-gem', 4);

-- Insert default rewards (Thai)
INSERT INTO `point_rewards` (`id`, `name`, `description`, `points_required`, `type`, `value`, `is_active`) VALUES
(1, 'ส่วนลด 50 บาท', 'คูปองส่วนลด 50 บาท', 100, 'discount', 50, 1),
(2, 'ส่วนลด 100 บาท', 'คูปองส่วนลด 100 บาท', 200, 'discount', 100, 1),
(3, 'จัดส่งฟรี', 'ฟรีค่าจัดส่ง 1 ครั้ง', 150, 'shipping', 0, 1),
(4, 'ของขวัญพิเศษ', 'รับของขวัญพิเศษจากร้าน', 500, 'gift', 0, 1);

-- Insert default zone types (Thai)
INSERT INTO `zone_types` (`id`, `code`, `label`, `color`, `icon`, `description`, `is_default`, `sort_order`) VALUES
(1, 'general', 'ทั่วไป', 'blue', 'fa-box', 'โซนจัดเก็บสินค้าทั่วไป', 1, 1),
(2, 'cold_storage', 'ห้องเย็น', 'cyan', 'fa-snowflake', 'โซนควบคุมอุณหภูมิ 2-8C', 1, 2),
(3, 'controlled', 'ยาควบคุม', 'red', 'fa-lock', 'โซนเก็บยาควบคุมพิเศษ', 1, 3),
(4, 'hazardous', 'วัตถุอันตราย', 'orange', 'fa-exclamation-triangle', 'โซนเก็บสารเคมี', 1, 4);

-- Insert default expense categories (Thai)
INSERT INTO `expense_categories` (`id`, `name`, `name_en`, `expense_type`, `is_default`, `is_active`) VALUES
(1, 'ค่าสาธารณูปโภค', 'Utilities', 'operating', 1, 1),
(2, 'ค่าเช่า', 'Rent', 'operating', 1, 1),
(3, 'เงินเดือน', 'Salary', 'operating', 1, 1),
(4, 'ค่าอินเทอร์เน็ต', 'Internet', 'operating', 1, 1),
(5, 'ค่าโทรศัพท์', 'Telephone', 'operating', 1, 1),
(6, 'ค่าขนส่ง', 'Transportation', 'operating', 1, 1),
(7, 'ค่าซ่อมบำรุง', 'Maintenance', 'operating', 1, 1),
(8, 'ค่าใช้จ่ายสำนักงาน', 'Office Supplies', 'administrative', 1, 1),
(9, 'ค่าธรรมเนียมธนาคาร', 'Bank Fees', 'financial', 1, 1),
(10, 'อื่นๆ', 'Miscellaneous', 'other', 1, 1);

SET FOREIGN_KEY_CHECKS = 1;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- Done!
