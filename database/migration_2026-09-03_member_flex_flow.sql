-- ============================================================================
-- Migration: Member Flex flow — ปิดลูปเตือนทานยา (postback → adherence log)
-- Date: 2026-09-03
--
-- ปุ่ม "ทานแล้ว / เตือนอีกครั้ง" ถูกส่งอยู่แล้วใน cron/medication_reminder.php
-- แต่ไม่เคยมี handler รับ → การกดของลูกค้าหายไปทั้งหมด
--
-- ตาราง medication_taken_history มีอยู่แล้วในเทมเพลต tenant (reminder_id,
-- user_id, scheduled_time, taken_at, status, notes) จึงไม่สร้างตารางใหม่
-- เติมเฉพาะสิ่งที่ขาดสำหรับ flow เลื่อนเตือน
--
-- Idempotent: รันซ้ำได้ ใช้ได้กับทุก tenant DB (reya_tenant_*)
-- ============================================================================

-- 1) รองรับสถานะ "snoozed" (เลื่อนเตือน) เพิ่มจาก taken/skipped/missed
ALTER TABLE `medication_taken_history`
    MODIFY COLUMN `status` ENUM('taken','skipped','missed','snoozed')
        DEFAULT 'taken'
        COMMENT 'taken=ทานแล้ว, skipped=ข้าม, missed=ไม่ตอบ, snoozed=ขอเลื่อน';

-- 2) เวลาที่ต้องเตือนซ้ำหลังกด "เลื่อน" — cron รอบถัดไปมาอ่านช่องนี้
ALTER TABLE `medication_taken_history`
    ADD COLUMN IF NOT EXISTS `snooze_until` DATETIME NULL DEFAULT NULL
        COMMENT 'เวลาที่ต้องเตือนซ้ำ (เฉพาะ status=snoozed)' AFTER `taken_at`;

-- 3) index ให้ cron หยิบงาน snooze ที่ถึงเวลาได้โดยไม่ full scan
ALTER TABLE `medication_taken_history`
    ADD INDEX IF NOT EXISTS `idx_mth_snooze` (`status`, `snooze_until`);

-- 4) index สำหรับอ่าน adherence ต่อ user (HUD เภสัชกร + คำนวณวันยาหมดจริง)
ALTER TABLE `medication_taken_history`
    ADD INDEX IF NOT EXISTS `idx_mth_user_taken` (`user_id`, `taken_at`);

-- 5) กันกดปุ่มเดิมซ้ำในสล็อตเดียวกัน (double-tap / LINE retry)
ALTER TABLE `medication_taken_history`
    ADD INDEX IF NOT EXISTS `idx_mth_reminder_slot` (`reminder_id`, `scheduled_time`, `taken_at`);
