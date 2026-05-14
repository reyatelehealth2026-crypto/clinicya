-- ---------------------------------------------------------------------------
-- Migration: 2026-05-15 restore PRIMARY KEY on 7 compound/business-key tables
-- ---------------------------------------------------------------------------
-- Companion to migration_2026-05-15_restore_pk_all_tables.sql.
-- These 7 tables don't have an `id` column so the auto-generated migration
-- skipped them. Their canonical PRIMARY KEY definitions come from
-- database/install_complete_latest.sql where available; for 3 tables not
-- present in any canonical SQL (inbox_auth_verification_tokens,
-- odoo_circuit_breaker_state, odoo_products_sync_state) we infer the PK
-- from the column meaning (token, service_name, line_account_id).
--
-- All 7 tables were empty at audit time so no dedupe is needed.
-- ---------------------------------------------------------------------------

SET time_zone = '+07:00';

-- Junction tables (compound key from install_complete_latest.sql)
ALTER TABLE `segment_members` ADD PRIMARY KEY (`segment_id`, `user_id`);
ALTER TABLE `user_groups`     ADD PRIMARY KEY (`user_id`, `group_id`);

-- Single-column natural-key tables (from install_complete_latest.sql)
ALTER TABLE `business_items_to_products_map` ADD PRIMARY KEY (`old_business_item_id`);
ALTER TABLE `sync_config`                    ADD PRIMARY KEY (`config_key`);

-- Tables not present in canonical SQL — PK inferred from column meaning.
-- `token` is the unique grant; `service_name` is one row per service;
-- `line_account_id` is one row per LINE OA tenant.
ALTER TABLE `inbox_auth_verification_tokens` ADD PRIMARY KEY (`token`);
ALTER TABLE `odoo_circuit_breaker_state`     ADD PRIMARY KEY (`service_name`);
ALTER TABLE `odoo_products_sync_state`       ADD PRIMARY KEY (`line_account_id`);

-- One-row-per-user tables (also missed by the auto-generator because they
-- key on user_id rather than id). PK definitions per install_complete_latest.sql.
ALTER TABLE `user_states`             ADD PRIMARY KEY (`user_id`);
ALTER TABLE `user_profiles_extended`  ADD PRIMARY KEY (`user_id`);
