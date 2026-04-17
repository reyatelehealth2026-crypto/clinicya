-- =====================================================================
-- Seed default pharmacy categories for Mini App shop
-- Target table: business_categories (clinicya DB)
-- Safe to re-run: uses INSERT IGNORE + name uniqueness check
-- =====================================================================

-- Ensure name is unique per line_account_id so INSERT IGNORE works as expected.
-- This is additive; skip silently if the index/constraint already exists.
-- (MySQL will error on duplicate add; wrap into procedure so the migration stays idempotent.)
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE business_categories ADD UNIQUE KEY uniq_cat_name_account (line_account_id, name)',
        'DO 0'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'business_categories'
      AND INDEX_NAME = 'uniq_cat_name_account'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Insert default categories (no line_account_id = shared for all accounts).
INSERT IGNORE INTO business_categories (line_account_id, name, description, sort_order, is_active)
VALUES
    (NULL, 'ยาสามัญประจำบ้าน',   'ยาพื้นฐานที่ควรมีติดบ้าน',           1, 1),
    (NULL, 'ยาทางเดินหายใจ',     'แก้หวัด คัดจมูก ไอ หอบหืด ภูมิแพ้',  2, 1),
    (NULL, 'ยาระบบทางเดินอาหาร', 'แก้ท้องอืด ท้องเสีย ลดกรด',          3, 1),
    (NULL, 'ยาแก้ปวด / ลดไข้',   'พาราเซตามอล, NSAIDs, แก้ปวดกล้ามเนื้อ', 4, 1),
    (NULL, 'ยาโรคเรื้อรัง',       'เบาหวาน ความดัน ไขมัน',              5, 1),
    (NULL, 'ยาใช้ภายนอก',         'ครีม ขี้ผึ้ง สเปรย์ เจล',             6, 1),
    (NULL, 'วิตามินและอาหารเสริม','อาหารเสริมและแร่ธาตุ',                7, 1),
    (NULL, 'ยาเด็ก',              'ยาและผลิตภัณฑ์สำหรับเด็ก',           8, 1),
    (NULL, 'สุขอนามัยส่วนบุคคล',  'สบู่ ยาสีฟัน ผลิตภัณฑ์ดูแลตัว',        9, 1),
    (NULL, 'อุปกรณ์การแพทย์',     'ปรอท เครื่องวัดความดัน ผ้าพันแผล',  10, 1),
    (NULL, 'ยาและสารต้านเชื้อ',   'ยาปฏิชีวนะ ยาต้านเชื้อรา (ภายใต้คำแนะนำเภสัชกร)', 11, 1),
    (NULL, 'อื่นๆ',                'สินค้าอื่นๆ',                           99, 1);

-- Optional: auto-classify existing products by keywords in name/generic_name.
-- Only touches rows where category_id IS NULL so re-running won't overwrite
-- manual classifications.
UPDATE business_items p
JOIN business_categories c ON c.line_account_id IS NULL AND c.name = 'ยาทางเดินหายใจ'
SET p.category_id = c.id
WHERE p.category_id IS NULL
  AND (p.name LIKE '%สเปรย์%' OR p.name LIKE '%นาซอล%' OR p.name LIKE '%หวัด%' OR p.name LIKE '%ไอ%' OR p.name LIKE '%ภูมิแพ้%');

UPDATE business_items p
JOIN business_categories c ON c.line_account_id IS NULL AND c.name = 'ยาแก้ปวด / ลดไข้'
SET p.category_id = c.id
WHERE p.category_id IS NULL
  AND (p.name LIKE '%พารา%' OR p.name LIKE '%ไอบูโพรเฟน%' OR p.name LIKE '%ลดไข้%' OR p.name LIKE '%แก้ปวด%');

UPDATE business_items p
JOIN business_categories c ON c.line_account_id IS NULL AND c.name = 'ยาระบบทางเดินอาหาร'
SET p.category_id = c.id
WHERE p.category_id IS NULL
  AND (p.name LIKE '%ท้องเสีย%' OR p.name LIKE '%ท้องอืด%' OR p.name LIKE '%ลดกรด%' OR p.name LIKE '%โอเรก%' OR p.name LIKE '%ORS%');

UPDATE business_items p
JOIN business_categories c ON c.line_account_id IS NULL AND c.name = 'ยาใช้ภายนอก'
SET p.category_id = c.id
WHERE p.category_id IS NULL
  AND (p.name LIKE '%ครีม%' OR p.name LIKE '%ขี้ผึ้ง%' OR p.name LIKE '%เจล%' OR p.name LIKE '%โลชั่น%');

UPDATE business_items p
JOIN business_categories c ON c.line_account_id IS NULL AND c.name = 'วิตามินและอาหารเสริม'
SET p.category_id = c.id
WHERE p.category_id IS NULL
  AND (p.name LIKE '%วิตามิน%' OR p.name LIKE '%vitamin%' OR p.name LIKE '%Zinc%' OR p.name LIKE '%Calcium%' OR p.name LIKE '%Iron%' OR p.name LIKE '%อาหารเสริม%');

-- Remaining NULL → "อื่นๆ"
UPDATE business_items p
JOIN business_categories c ON c.line_account_id IS NULL AND c.name = 'อื่นๆ'
SET p.category_id = c.id
WHERE p.category_id IS NULL;

-- Summary (printed when running interactively)
SELECT 'categories' AS entity, COUNT(*) AS total FROM business_categories WHERE is_active = 1
UNION ALL
SELECT 'products_with_category', COUNT(*) FROM business_items WHERE category_id IS NOT NULL
UNION ALL
SELECT 'products_without_category', COUNT(*) FROM business_items WHERE category_id IS NULL;
