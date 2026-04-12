-- Migration: Add payment_status column to transactions table if missing
-- Fixes: checkout order creation failing with "Unknown column 'payment_status'"
-- Safe to run multiple times (uses IF NOT EXISTS / IGNORE)

ALTER TABLE `transactions`
    ADD COLUMN IF NOT EXISTS `payment_status` VARCHAR(20) DEFAULT 'pending';

-- Ensure existing rows without payment_status get a sane default
UPDATE `transactions`
SET `payment_status` = 'pending'
WHERE `payment_status` IS NULL OR `payment_status` = '';

-- Index for status lookups used in dashboard queries
ALTER TABLE `transactions`
    ADD INDEX IF NOT EXISTS `idx_txn_payment_status` (`payment_status`);
