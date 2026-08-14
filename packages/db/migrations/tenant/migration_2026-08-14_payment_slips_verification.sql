-- migration_2026-08-14_payment_slips_verification.sql
--
-- Reconciliation migration: commits the GhostX slip-verification columns
-- that install/migration_payment_slips_verification.php has been adding to
-- payment_slips ad hoc, per-tenant, outside the committed schema, since the
-- ghostx-slip-verification feature shipped. Columns/types/ALTER order below
-- are ported verbatim from that script's two ALTER TABLE statements (it
-- issues them as two separate calls, guarded by its own
-- `SHOW COLUMNS ... LIKE` idempotency checks at the application level; this
-- migration relies on packages/db's tenant_migrations ledger for
-- once-per-tenant idempotency instead, so no IF NOT EXISTS/guard clause is
-- added here that the source script itself did not have).
--
-- See also: packages/db/src/generated/tenant-db.d.ts's PaymentSlips
-- interface, hand-patched with these same 5 fields until a real
-- kysely-codegen run regenerates that file against a migrated tenant DB.
--
-- @spec ghostx-slip-verification

ALTER TABLE payment_slips
  ADD COLUMN verify_ref VARCHAR(100) DEFAULT NULL COMMENT 'GhostX transactionRef (unique)' AFTER status,
  ADD COLUMN verify_amount DECIMAL(12,2) DEFAULT NULL COMMENT 'Amount confirmed by GhostX' AFTER verify_ref,
  ADD COLUMN verify_data JSON DEFAULT NULL COMMENT 'Full GhostX response payload' AFTER verify_amount,
  ADD COLUMN verified_at DATETIME DEFAULT NULL COMMENT 'When verification succeeded' AFTER verify_data;

ALTER TABLE payment_slips ADD UNIQUE INDEX uniq_verify_ref (verify_ref);

ALTER TABLE payment_slips ADD COLUMN qr_payload TEXT DEFAULT NULL COMMENT 'Raw QR string from the slip' AFTER verified_at;
