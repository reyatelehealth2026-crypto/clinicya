-- =============================================================================
-- migration_2026-07-04_tenant_onboard_progress.sql
-- Persists step progress for the platform-admin "new tenant onboarding" wizard
-- (admin/tenant-onboard.php + classes/OnboardingWizard.php).
-- Target DB: master / platform (zrismpsz_reya_platform). MariaDB. Idempotent.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tenant_onboarding_progress` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL COMMENT 'FK tenants.id — one progress row per tenant',
  `progress_json` TEXT NULL COMMENT 'OnboardingWizard step-completion map, JSON-encoded',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_onboard_progress_tenant` (`tenant_id`),
  CONSTRAINT `fk_tenant_onboard_progress_tenant` FOREIGN KEY (`tenant_id`)
    REFERENCES `tenants` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Resumable step state for the platform-admin tenant onboarding wizard';
