-- Migration: เพิ่ม unique key ให้ landing_settings บน tenant DB ที่ provision จาก template เก่า
-- Date: 2026-07-03
--
-- ปัญหา: migration_2026-05-25_tenant_template.sql สร้าง landing_settings โดยไม่มี
-- UNIQUE KEY (line_account_id, setting_key) ทำให้ทุก upsert แบบ ON DUPLICATE KEY
-- (SEO / trust / custom_html / landing v2) insert แถวใหม่ซ้ำแทนที่จะอัปเดต
--
-- ต้องรันกับ "ทุก tenant DB" (zrismpsz_reya_t_%) — ใช้ pattern เดียวกับ
-- install/migrate_all_tenants_payment_slips_verification.php
-- DB หลัก (install_complete_latest.sql) มี key นี้อยู่แล้ว รันซ้ำไม่พัง (IF NOT EXISTS)

-- 1) ลบแถวซ้ำก่อน: เก็บแถว id มากสุด (ใหม่สุด) ต่อ (line_account_id, setting_key)
--    หมายเหตุ: <=> เทียบ NULL ได้ (NULL <=> NULL = true)
DELETE ls FROM landing_settings ls
JOIN landing_settings newer
  ON  newer.line_account_id <=> ls.line_account_id
  AND newer.setting_key = ls.setting_key
  AND newer.id > ls.id;

-- 2) เพิ่ม unique key (MariaDB รองรับ IF NOT EXISTS)
ALTER TABLE landing_settings
  ADD UNIQUE KEY IF NOT EXISTS uk_landing_setting (line_account_id, setting_key);
