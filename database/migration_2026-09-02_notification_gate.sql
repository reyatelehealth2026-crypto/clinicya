-- ============================================================
-- NotificationGate — ประตูเดียวก่อนส่งข้อความ push หาลูกค้า
-- Single gate in front of every customer-facing push message
--
-- ก่อนหน้านี้ cron แจ้งเตือน 8 ตัวเรียก LineAPI::pushMessage() ตรงเข้าหาลูกค้า
-- มีเพียง 4 ตัวที่อ่าน user_notification_preferences จึงไม่มีที่ใดตอบได้ว่า
-- ลูกค้ารายหนึ่งได้รับข้อความอะไรไปบ้าง และตอนที่เขาปิดสวิตช์แล้วระบบเคารพจริงไหม
--
-- Before this, 8 reminder crons called LineAPI::pushMessage() directly and only
-- 4 of them consulted user_notification_preferences, so nothing could answer
-- "why did this customer receive this message?" — a PDPA gap on health data.
--
-- หมายเหตุ: ตารางนี้เป็นของ "สายลูกค้า" คนละชุดกับ odoo_notification_log
-- ซึ่งเป็นของสาย Odoo delivery (NotificationRouter) การรวมสองชุดให้เหลือชุดเดียว
-- เป็นงานตามหลังที่ควรทำตอนถอด Odoo ออก
--
-- @see classes/NotificationGate.php
-- ============================================================

-- ------------------------------------------------------------
-- บันทึกการตัดสินใจของ Gate ทุกครั้ง ทั้งที่ส่งและไม่ส่ง
-- Every gate decision, sent or skipped, with its reason
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notification_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `line_account_id` int(11) DEFAULT NULL COMMENT 'บัญชี LINE ที่เป็นเจ้าของข้อความ',
  `user_id` int(11) NOT NULL COMMENT 'ลูกค้าปลายทาง (users.id)',
  `line_user_id` varchar(50) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL COMMENT 'คีย์ใน NotificationGate::POLICY เช่น medication_dose',
  `dedupe_key` varchar(191) DEFAULT NULL COMMENT 'คีย์กันส่งซ้ำ ภายใน 24 ชม.',
  `decision` enum('sent','skipped','failed') NOT NULL,
  `reason` varchar(40) NOT NULL COMMENT 'ok | pref_off | quiet_hours | duplicate | daily_cap | no_line_user | send_failed',
  `detail` text DEFAULT NULL COMMENT 'ข้อความ error เมื่อส่งไม่สำเร็จ',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  -- นับเพดานต่อวัน: WHERE user_id = ? AND decision = 'sent' AND DATE(created_at) = CURDATE()
  KEY `idx_daily_cap` (`user_id`, `decision`, `created_at`),
  -- กันส่งซ้ำ: WHERE user_id = ? AND event_type = ? AND dedupe_key = ?
  KEY `idx_dedupe` (`user_id`, `event_type`, `dedupe_key`, `created_at`),
  -- รายงานฝั่งแอดมินต่อบัญชี
  KEY `idx_account_time` (`line_account_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ทุกการตัดสินใจส่ง/ไม่ส่งของ NotificationGate — หลักฐาน PDPA';

-- ------------------------------------------------------------
-- ช่วงห้ามรบกวนต่อลูกค้า
-- Per-customer quiet hours (defaults 21:00–08:00 Asia/Bangkok)
--
-- หมายเหตุ: ช่วงนี้ใช้กับข้อความที่ "ร้านเป็นคนเริ่ม" เท่านั้น
-- การเตือนทานยาที่ลูกค้าตั้งเวลาเอง และการเตือนนัดหมาย ยกเว้นเสมอ
-- ดู NotificationGate::POLICY
-- ------------------------------------------------------------
ALTER TABLE `user_notification_preferences`
  ADD COLUMN IF NOT EXISTS `quiet_hours_start` time DEFAULT '21:00:00'
    COMMENT 'เริ่มช่วงห้ามรบกวน' AFTER `restock_alerts`,
  ADD COLUMN IF NOT EXISTS `quiet_hours_end` time DEFAULT '08:00:00'
    COMMENT 'สิ้นสุดช่วงห้ามรบกวน' AFTER `quiet_hours_start`;
