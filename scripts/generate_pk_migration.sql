-- Generator: emit ALTER TABLE statements for every table in zrismpsz_demo
-- that has an `id` column NOT NULL but no PRIMARY KEY.
--
-- For integer `id` columns we add PRIMARY KEY + AUTO_INCREMENT.
-- For varchar/char `id` columns (UUIDs etc.) we only add PRIMARY KEY since
-- AUTO_INCREMENT is not valid on non-integer types.
--
-- Excludes 3 data-bearing tables handled in a manual section after data
-- renumbering: business_categories, telegram_settings, admin_users.
--
-- Usage:
--   mysql -u<user> -p<pass> zrismpsz_demo < scripts/generate_pk_migration.sql > /tmp/pk_alters.sql
SELECT CONCAT(
    'ALTER TABLE `', c.TABLE_NAME, '` ADD PRIMARY KEY (`id`)',
    CASE WHEN c.DATA_TYPE IN ('varchar','char','text','tinytext','mediumtext','longtext')
         THEN ''
         ELSE CONCAT(', MODIFY `id` ', c.COLUMN_TYPE, ' NOT NULL AUTO_INCREMENT')
    END,
    ';'
)
FROM information_schema.COLUMNS c
LEFT JOIN information_schema.TABLE_CONSTRAINTS tc
  ON c.TABLE_SCHEMA = tc.TABLE_SCHEMA
 AND c.TABLE_NAME   = tc.TABLE_NAME
 AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
WHERE c.TABLE_SCHEMA = 'zrismpsz_demo'
  AND c.COLUMN_NAME = 'id'
  AND c.IS_NULLABLE = 'NO'
  AND tc.CONSTRAINT_NAME IS NULL
  AND c.TABLE_NAME NOT IN (
      'business_categories',
      'telegram_settings',
      'admin_users'
  )
ORDER BY c.TABLE_NAME;
