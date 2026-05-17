-- ---------------------------------------------------------------------------
-- Migration: 2026-05-17 onboarding wizard
-- ---------------------------------------------------------------------------
-- เพิ่ม columns สำหรับติดตามสถานะ Setup Wizard ของ admin user แต่ละคน
-- ใช้ใน /onboarding/wizard.php (7 ขั้น) และ auth_check.php (auto-redirect)
-- ---------------------------------------------------------------------------

ALTER TABLE `admin_users`
  ADD COLUMN IF NOT EXISTS `onboarding_completed` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'ทำ wizard ครบทั้ง 7 ขั้นแล้วหรือยัง',
  ADD COLUMN IF NOT EXISTS `onboarding_step` INT NOT NULL DEFAULT 0
    COMMENT 'ขั้นล่าสุดที่ทำสำเร็จ (0..7)',
  ADD COLUMN IF NOT EXISTS `onboarding_skipped` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'กดข้ามทั้งหมด',
  ADD COLUMN IF NOT EXISTS `onboarding_completed_at` TIMESTAMP NULL DEFAULT NULL
    COMMENT 'เวลาทำ wizard เสร็จ';

-- Existing admins (created before wizard) ถือว่าเสร็จไปแล้ว ไม่ต้อง redirect
UPDATE `admin_users`
SET `onboarding_completed` = 1,
    `onboarding_step` = 7,
    `onboarding_completed_at` = COALESCE(`onboarding_completed_at`, `created_at`)
WHERE `onboarding_completed` = 0
  AND `created_at` < '2026-05-17 00:00:00';
