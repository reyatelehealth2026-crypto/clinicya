-- =====================================================================
-- Points ledger: idempotency key + the index it never had
-- 2026-09-07 — ADR-008 phase 4
--
-- ROOT CAUSE (idempotency): nothing stops the same award being credited
-- twice. Every integration that can retry — the Odoo invoice webhook, a
-- re-submitted receipt claim, a double-tapped approve button — awards
-- points again on the retry. ADR-008 makes points_transactions the
-- ledger, and a ledger that cannot reject a duplicate is not one.
--
-- สาเหตุ: ไม่มีอะไรกันการให้แต้มซ้ำเลย webhook ที่ส่งซ้ำหรือปุ่มที่กดสองครั้ง
-- จะได้แต้มสองรอบ
--
-- ROOT CAUSE (index): the tenant template
-- (migration_2026-05-25_tenant_template.sql) created points_transactions
-- with ONLY a PRIMARY KEY. Verified on production 2026-09-07: tenants
-- 0001, 0003 and 0013 all have exactly one index. Every balance read is
-- `SUM(points) WHERE user_id = ?` — a full table scan, 2,897 rows and
-- growing on tenant 0003 — and since ADR-008 that scan runs while
-- holding the user row lock, so it serialises every concurrent award.
--
-- APPLY: run install/migrate_all_tenants_points_idempotency.php
-- (iterates the legacy DB + every zrismpsz_reya_t_* tenant DB, checks
-- information_schema first, so it is idempotent and MySQL-compatible).
-- This .sql file uses MariaDB `IF NOT EXISTS` syntax and is the
-- canonical record / manual fallback for a single database.
-- =====================================================================

-- ---------------------------------------------------------------------
-- idempotency_key — one award per key, forever
-- ---------------------------------------------------------------------
-- Callers pass a key naming the thing being paid for, scoped to the LINE
-- account: "3:order:1042", "3:receipt:88", "3:odoo:INV-10392". A retry
-- carries the same key and is rejected by the unique index rather than
-- credited a second time.
--
-- NULL is allowed and repeats freely — MySQL/MariaDB unique indexes do
-- not constrain NULLs. That is deliberate: manual adjustments and other
-- awards with no natural key keep working unchanged.
--
-- 190, not 255: utf8mb4 is 4 bytes per character and InnoDB's index-key
-- limit is 767 bytes on older row formats.
ALTER TABLE `points_transactions`
  ADD COLUMN IF NOT EXISTS `idempotency_key` VARCHAR(190) NULL
  COMMENT 'กันให้แต้มซ้ำ: "<line_account_id>:<source>:<id>" — NULL = ไม่มีคีย์ธรรมชาติ'
  AFTER `reference_id`;

ALTER TABLE `points_transactions`
  ADD UNIQUE INDEX IF NOT EXISTS `uniq_points_idem` (`idempotency_key`);

-- ---------------------------------------------------------------------
-- idx_pt_user — the balance read, on every single award
-- ---------------------------------------------------------------------
-- LoyaltyPoints::getUserPoints() sums this table by user_id, and
-- getPointsHistory() pages it by user_id ORDER BY created_at DESC.
-- InnoDB index extension appends the primary key, so (user_id) alone
-- also serves the id-ordered reads.
ALTER TABLE `points_transactions`
  ADD INDEX IF NOT EXISTS `idx_pt_user` (`user_id`);

-- Points expiry sweeps look for live rows past their date.
ALTER TABLE `points_transactions`
  ADD INDEX IF NOT EXISTS `idx_pt_expires` (`expires_at`);
