-- =============================================================================
-- Migration: Platform Owner - Detailed Customer/Billing Tracking
-- Date:      2026-06-04
-- Scope:     master DB `zrismpsz_reya_platform`
--
-- Adds richer, optional metadata to the platform customer/billing system:
--   * tenant_subscriptions: contract, trial, billing contact, tax/address fields
--   * tenant_payments: invoice/receipt/slip verification fields
--   * tenant_usage_snapshots: more operational usage counters
--
-- Re-run safe: all DDL uses ADD COLUMN IF NOT EXISTS.
-- =============================================================================
USE `zrismpsz_reya_platform`;

ALTER TABLE `tenant_subscriptions`
  ADD COLUMN IF NOT EXISTS `contract_no` VARCHAR(80) DEFAULT NULL
    COMMENT 'Customer contract/reference number' AFTER `tenant_id`,
  ADD COLUMN IF NOT EXISTS `contract_signed_date` DATE DEFAULT NULL
    COMMENT 'Date contract/subscription was signed' AFTER `contract_no`,
  ADD COLUMN IF NOT EXISTS `trial_ends_at` DATE DEFAULT NULL
    COMMENT 'Trial/grace period end date, if any' AFTER `next_due_date`,
  ADD COLUMN IF NOT EXISTS `billing_contact_name` VARCHAR(150) DEFAULT NULL
    COMMENT 'Billing contact person' AFTER `amount_thb`,
  ADD COLUMN IF NOT EXISTS `billing_contact_email` VARCHAR(190) DEFAULT NULL
    COMMENT 'Billing contact email' AFTER `billing_contact_name`,
  ADD COLUMN IF NOT EXISTS `billing_contact_phone` VARCHAR(50) DEFAULT NULL
    COMMENT 'Billing contact phone' AFTER `billing_contact_email`,
  ADD COLUMN IF NOT EXISTS `tax_id` VARCHAR(30) DEFAULT NULL
    COMMENT 'Tax ID / national business registration number' AFTER `billing_contact_phone`,
  ADD COLUMN IF NOT EXISTS `billing_address` TEXT DEFAULT NULL
    COMMENT 'Billing address for invoice/receipt' AFTER `tax_id`,
  ADD COLUMN IF NOT EXISTS `invoice_prefix` VARCHAR(40) DEFAULT NULL
    COMMENT 'Optional invoice prefix used by back office' AFTER `billing_address`,
  ADD COLUMN IF NOT EXISTS `auto_suspend_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Whether platform ops may auto-suspend this tenant after overdue policy' AFTER `invoice_prefix`,
  ADD COLUMN IF NOT EXISTS `internal_note` TEXT DEFAULT NULL
    COMMENT 'Internal platform-owner note, separate from short billing note' AFTER `note`;

ALTER TABLE `tenant_payments`
  ADD COLUMN IF NOT EXISTS `invoice_no` VARCHAR(80) DEFAULT NULL
    COMMENT 'Invoice number associated with this payment' AFTER `reference`,
  ADD COLUMN IF NOT EXISTS `receipt_no` VARCHAR(80) DEFAULT NULL
    COMMENT 'Receipt number issued for this payment' AFTER `invoice_no`,
  ADD COLUMN IF NOT EXISTS `slip_url` VARCHAR(500) DEFAULT NULL
    COMMENT 'URL/path to payment proof or slip' AFTER `receipt_no`,
  ADD COLUMN IF NOT EXISTS `verified_at` TIMESTAMP NULL DEFAULT NULL
    COMMENT 'When platform owner verified this payment' AFTER `recorded_by`,
  ADD COLUMN IF NOT EXISTS `verified_by` INT NULL DEFAULT NULL
    COMMENT 'platform_users.id who verified the payment' AFTER `verified_at`;

ALTER TABLE `tenant_usage_snapshots`
  ADD COLUMN IF NOT EXISTS `num_admin_users` INT NULL DEFAULT NULL
    COMMENT 'Number of admin_users in tenant DB' AFTER `num_users`,
  ADD COLUMN IF NOT EXISTS `num_line_accounts` INT NULL DEFAULT NULL
    COMMENT 'Number of line_accounts in tenant DB' AFTER `num_admin_users`,
  ADD COLUMN IF NOT EXISTS `num_products` INT NULL DEFAULT NULL
    COMMENT 'Number of products/business_items in tenant DB' AFTER `num_orders`,
  ADD COLUMN IF NOT EXISTS `last_user_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Latest users.created_at in tenant DB' AFTER `num_messages`,
  ADD COLUMN IF NOT EXISTS `last_order_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Latest transaction/order created_at in tenant DB' AFTER `last_user_at`,
  ADD COLUMN IF NOT EXISTS `last_message_at` DATETIME NULL DEFAULT NULL
    COMMENT 'Latest messages.created_at in tenant DB' AFTER `last_order_at`;

-- =============================================================================
-- End of migration_2026-06-04_platform_billing_details.sql
-- =============================================================================
