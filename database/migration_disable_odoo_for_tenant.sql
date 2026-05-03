-- Migration: Disable Odoo integration for non-Odoo tenants
-- Date: 2026-05-04
-- Purpose: Reset shop_settings.order_data_source to 'shop' for all rows so the
--          per-tenant Odoo gate ($isOdooMode) evaluates to false everywhere.
--          Run this once when forking the codebase to a tenant that does not
--          use Odoo. Safe to re-run (idempotent).

-- Ensure the column exists (matches ensureShopOrderDataSourceColumn() in PHP)
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'shop_settings'
      AND COLUMN_NAME = 'order_data_source'
);

SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE shop_settings ADD COLUMN order_data_source VARCHAR(20) DEFAULT ''shop''',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Force every existing row to 'shop'
UPDATE shop_settings
SET order_data_source = 'shop'
WHERE order_data_source IS NULL OR order_data_source <> 'shop';

-- Verification
SELECT
    COUNT(*)                                              AS total_rows,
    SUM(order_data_source = 'shop')                       AS shop_rows,
    SUM(order_data_source = 'odoo')                       AS odoo_rows_remaining
FROM shop_settings;
