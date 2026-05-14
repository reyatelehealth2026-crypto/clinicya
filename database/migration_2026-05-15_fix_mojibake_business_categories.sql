-- ---------------------------------------------------------------------------
-- Migration: 2026-05-15 fix double-encoded UTF-8 (mojibake) in business_categories
-- ---------------------------------------------------------------------------
-- The 12 seed rows in `business_categories` on the zrismpsz_demo database
-- were inserted via a client that wrote UTF-8 byte sequences while declaring
-- the connection as latin1. MySQL/MariaDB then re-encoded those bytes a
-- second time when storing in the utf8mb4 column, producing the pattern:
--
--   Thai 'ย' (U+0E22) =  E0 B8 A2  (correct UTF-8)
--   Stored as          : C3 A0  C2 B8  C2 A2  (each byte reinterpreted as
--                                              U+00E0/U+00B8/U+00A2 then
--                                              re-encoded as UTF-8)
--
-- The fix reverses both steps:
--   1. CONVERT(... USING latin1)  — read each utf8mb4 char's codepoint as a
--                                   single latin1 byte, recovering the
--                                   original UTF-8 byte sequence
--   2. CAST AS BINARY             — keep the bytes raw
--   3. CONVERT(... USING utf8mb4) — interpret the recovered bytes as utf8mb4
--
-- Audit confirmed this affected only business_categories. Other Thai-bearing
-- tables on this DB (zone_types, drug_type_rules) were stored cleanly.
--
-- Safe to re-run only on rows still in mojibake state. Running twice on
-- already-fixed data would corrupt it; gate by HEX prefix if re-applying.
-- ---------------------------------------------------------------------------

SET time_zone = '+07:00';

UPDATE `business_categories`
SET `name` = CONVERT(BINARY CONVERT(`name` USING latin1) USING utf8mb4)
WHERE `name` IS NOT NULL;

UPDATE `business_categories`
SET `description` = CONVERT(BINARY CONVERT(`description` USING latin1) USING utf8mb4)
WHERE `description` IS NOT NULL;
