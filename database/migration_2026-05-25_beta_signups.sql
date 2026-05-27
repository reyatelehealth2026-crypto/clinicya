-- =============================================================================
-- Migration: beta_signups — public lead capture for REYA Beta launch
-- Date:      2026-05-25
-- Scope:     ตาราง beta_signups ใน zrismpsz_reya_platform (master DB)
--
-- Used by:   public form /beta + admin inbox /admin/beta-signups.php
-- =============================================================================

USE `zrismpsz_reya_platform`;

CREATE TABLE IF NOT EXISTS `beta_signups` (
  `id` INT NOT NULL AUTO_INCREMENT,

  -- Section 1: ผู้ลงทะเบียน
  `full_name`       VARCHAR(120) NOT NULL                              COMMENT 'ชื่อ-นามสกุล',
  `phone`           VARCHAR(20)  NOT NULL                              COMMENT 'เบอร์โทร',
  `line_id`         VARCHAR(80)  NOT NULL                              COMMENT 'LINE ID',
  `email`           VARCHAR(120) DEFAULT NULL                          COMMENT 'อีเมล (optional)',

  -- Section 2: ร้าน/องค์กร
  `business_name`   VARCHAR(200) NOT NULL                              COMMENT 'ชื่อร้าน/คลินิก/องค์กร',
  `business_type`   ENUM('single_pharmacy','multi_pharmacy','clinic',
                         'medical_clinic','beauty_clinic',
                         'pharmacy_clinic','other') NOT NULL           COMMENT 'ประเภทธุรกิจ',
  `business_type_other` VARCHAR(120) DEFAULT NULL                      COMMENT 'ถ้าเลือก other ให้ระบุ',
  `branch_count`    ENUM('1','2_3','4_5','5_plus') NOT NULL            COMMENT 'จำนวนสาขา',
  `province`        VARCHAR(80) NOT NULL                               COMMENT 'จังหวัด',

  -- Section 3: ปัญหา
  `pain_points`     JSON NOT NULL                                      COMMENT 'array of pain-point keys',
  `current_system`  ENUM('line_oa_only','spreadsheet','pos','crm',
                         'none','other') NOT NULL                      COMMENT 'ระบบที่ใช้ปัจจุบัน',
  `goals`           JSON NOT NULL                                      COMMENT 'วัตถุประสงค์การใช้ REYA',

  -- Section 4: ความพร้อม
  `trial_window`    ENUM('immediate','7_days','15_days','30_days',
                         'need_more_info') NOT NULL                    COMMENT 'พร้อมทดลองภายใน',
  `has_line_oa`     ENUM('yes','no','barely_used','unsure') NOT NULL   COMMENT 'มี LINE OA หรือยัง',
  `decision_maker`  ENUM('owner','pharmacist','manager',
                         'marketing','it','exec_approval') NOT NULL    COMMENT 'ผู้ตัดสินใจ',
  `contact_time`    ENUM('morning','afternoon','late_afternoon',
                         'evening','line_first') NOT NULL              COMMENT 'ช่วงเวลาที่สะดวก',

  -- Section 5: แพ็กเกจ
  `preferred_package` ENUM('beta_trial','single_pharm','multi_pharm',
                            'clinic','need_advice') NOT NULL           COMMENT 'แพ็กเกจที่สนใจ',
  `knows_beta_perk`   ENUM('yes_want','no_want','need_info') NOT NULL  COMMENT 'รู้เรื่องสิทธิ์ Beta หรือไม่',

  -- Section 6: ข้อความ
  `additional_message` TEXT DEFAULT NULL                               COMMENT 'ปัญหาที่อยากให้แก้ (free text)',
  `demo_format`      ENUM('video_call','clip','phone','line','unsure') NOT NULL
                                                                       COMMENT 'รูปแบบ Demo ที่ต้องการ',

  -- Section 7: ยินยอม
  `consent_contact`  TINYINT(1) NOT NULL DEFAULT 0                     COMMENT '0/1 ยินยอมให้ติดต่อกลับ',

  -- Lead tracking (admin use)
  `status`           ENUM('new','contacted','demo_booked','trial_started',
                          'signed_up','disqualified','spam') NOT NULL DEFAULT 'new',
  `lead_score`       TINYINT NOT NULL DEFAULT 0                         COMMENT 'auto-computed 0-100',
  `assigned_to`      INT DEFAULT NULL                                   COMMENT 'platform_users.id',
  `contacted_at`     TIMESTAMP NULL,
  `contacted_by`     INT NULL                                            COMMENT 'platform_users.id',
  `internal_notes`   TEXT NULL                                           COMMENT 'admin notes (ไม่ส่งให้ลูกค้า)',
  `converted_tenant_id` INT NULL                                         COMMENT 'tenants.id หลัง convert เป็นลูกค้าจริง',

  -- Tracking
  `ip_address`       VARCHAR(45) DEFAULT NULL,
  `user_agent`       VARCHAR(500) DEFAULT NULL,
  `referrer`         VARCHAR(500) DEFAULT NULL,
  `utm_source`       VARCHAR(80) DEFAULT NULL,
  `utm_medium`       VARCHAR(80) DEFAULT NULL,
  `utm_campaign`     VARCHAR(80) DEFAULT NULL,

  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_beta_status_time` (`status`, `created_at`),
  KEY `idx_beta_phone` (`phone`),
  KEY `idx_beta_line_id` (`line_id`),
  KEY `idx_beta_assigned` (`assigned_to`),
  KEY `idx_beta_score` (`lead_score`),
  KEY `idx_beta_utm` (`utm_source`, `utm_campaign`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Beta launch lead capture — ผู้สนใจสมัครทดลองใช้ REYA';
