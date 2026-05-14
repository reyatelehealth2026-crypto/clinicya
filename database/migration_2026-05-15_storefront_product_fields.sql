-- ---------------------------------------------------------------------------
-- Migration: 2026-05-15 add usage_instructions + image_gallery to the
-- storefront product cache so admins can publish full product detail pages
-- (รายละเอียด / วิธีใช้ / รูปภาพหลายรูป) and the LIFF mini-app shop can
-- display them.
-- ---------------------------------------------------------------------------
-- Context: the existing storefront tab writes to `odoo_products_cache` but
-- that table only has `description` and a single `image_url`. The admin
-- product modal (includes/inventory/storefront.php) and the mini-app product
-- detail page (line-mini-app /shop/product) both want a multi-image gallery
-- and a separate "how to use" / "วิธีใช้" field.
--
-- Both columns are nullable so legacy rows continue to work. image_gallery
-- stores a JSON array of fully-qualified image URLs (e.g. ["https://…/1.jpg",
-- "https://…/2.jpg"]); the API normaliser is responsible for collapsing it
-- back into the `image_gallery: string[]` shape the front-end expects.
-- ---------------------------------------------------------------------------

SET time_zone = '+07:00';

ALTER TABLE `odoo_products_cache`
    ADD COLUMN IF NOT EXISTS `usage_instructions` TEXT NULL AFTER `description`,
    ADD COLUMN IF NOT EXISTS `image_gallery`      LONGTEXT NULL AFTER `image_url`;
