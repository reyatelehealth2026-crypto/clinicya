-- ---------------------------------------------------------------------------
-- Migration: 2026-05-15 unify the three product tables (business_items,
-- shop_products, cny_products) into a single canonical table: business_items.
--
-- Background:
--   - `business_items` is the existing rich product table (already has
--     usage_instructions, generic_name, manufacturer, description, image_url,
--     photo_path, category_id FK, price, sale_price, cost_price, stock, sku,
--     barcode, dosage, warnings, supplier_id, min_stock, reorder_point,
--     requires_batch_tracking, etc.). Missing only `image_gallery`.
--   - `shop_products` (formerly `odoo_products_cache`) is the older catalog
--     that the storefront tab + mini-app shop wrote to. Its category column
--     is a free-text VARCHAR (no FK).
--   - `cny_products` was a legacy SKU table; the JOIN against it in
--     includes/inventory/products.php is dead weight and is being removed.
--
-- Strategy:
--   1. Add image_gallery to business_items so it can hold the multi-image
--      JSON arrays the mini-app product detail page wants.
--   2. Copy every row from shop_products into business_items, mapping
--      product_code → sku, list_price/online_price → price/sale_price,
--      saleable_qty → stock, free-text category → category_id (looked up by
--      name in business_categories; "ยาและสารต้านเชื้อ" resolves to id=11).
--
-- This migration is idempotent: ADD COLUMN IF NOT EXISTS for the new column,
-- and the INSERT uses a NOT EXISTS guard so re-running it does not duplicate
-- rows. shop_products and cny_products are left in place so the user can
-- confirm the new state before dropping them in a follow-up.
-- ---------------------------------------------------------------------------

SET time_zone = '+07:00';

-- 1. Add image_gallery to the canonical table -------------------------------
ALTER TABLE `business_items`
    ADD COLUMN IF NOT EXISTS `image_gallery` LONGTEXT NULL
        COMMENT 'JSON array of image URLs for the mini-app product detail gallery' AFTER `image_url`;

-- 2. Backfill business_items from shop_products -----------------------------
-- Map columns:
--   product_code   → sku
--   list_price>0   → price; else online_price
--   sale_price OR online_price (when both list & online set and online<list) → sale_price
--   saleable_qty   → stock
--   category (varchar) → category_id (LOOKUP business_categories by name)
--
-- NOT EXISTS guard keys on (line_account_id, sku) so a re-run is a no-op.
INSERT INTO `business_items` (
    line_account_id,
    item_type,
    category_id,
    name,
    name_en,
    generic_name,
    manufacturer,
    description,
    usage_instructions,
    image_url,
    image_gallery,
    sku,
    barcode,
    price,
    sale_price,
    stock,
    is_active,
    created_at,
    updated_at
)
SELECT
    sp.line_account_id,
    -- business_items.item_type is enum('physical','digital','service',
    -- 'booking','content'); 'physical' is the right bucket for shop SKUs.
    'physical'                                         AS item_type,
    -- business_categories.name uses utf8mb4_general_ci and shop_products.category
    -- uses utf8mb4_unicode_ci; force the join to one collation so MySQL doesn't
    -- complain about "Illegal mix of collations" on the equality test.
    (
        SELECT bc.id
        FROM business_categories bc
        WHERE bc.name COLLATE utf8mb4_unicode_ci = sp.category
          AND (bc.line_account_id = sp.line_account_id OR bc.line_account_id IS NULL)
        ORDER BY (bc.line_account_id = sp.line_account_id) DESC
        LIMIT 1
    )                                                  AS category_id,
    sp.name,
    sp.name_en,
    sp.generic_name,
    sp.manufacturer,
    sp.description,
    sp.usage_instructions,
    sp.image_url,
    sp.image_gallery,
    sp.product_code                                    AS sku,
    sp.barcode,
    CASE
        WHEN COALESCE(sp.list_price, 0) > 0 THEN sp.list_price
        ELSE COALESCE(sp.online_price, 0)
    END                                                AS price,
    CASE
        WHEN sp.sale_price IS NOT NULL AND sp.sale_price > 0 THEN sp.sale_price
        WHEN COALESCE(sp.online_price, 0) > 0
             AND COALESCE(sp.list_price, 0) > 0
             AND sp.online_price < sp.list_price       THEN sp.online_price
        ELSE NULL
    END                                                AS sale_price,
    COALESCE(sp.saleable_qty, 0)                       AS stock,
    1                                                  AS is_active,
    NOW()                                              AS created_at,
    NOW()                                              AS updated_at
FROM shop_products sp
WHERE NOT EXISTS (
    SELECT 1
    FROM business_items bi
    WHERE bi.line_account_id = sp.line_account_id
      AND bi.sku COLLATE utf8mb4_unicode_ci = sp.product_code
);
