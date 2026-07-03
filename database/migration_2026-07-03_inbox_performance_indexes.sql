-- =====================================================================
-- Inbox V2 Performance Indexes / ดัชนีเพิ่มความเร็ว Inbox V2
-- 2026-07-03
--
-- ROOT CAUSE: the tenant template (migration_2026-05-25_tenant_template.sql)
-- created `messages`, `users`, `conversation_assignments`,
-- `conversation_multi_assignees`, `user_tag_assignments` and
-- `account_followers` with ONLY a PRIMARY KEY — every inbox query
-- (conversation list, chat open, unread counts, 5-second poll) was a full
-- table scan, so inbox latency grew linearly with accumulated messages.
--
-- สาเหตุ: template ของ tenant DB สร้างตารางโดยไม่มี secondary index เลย
-- ทำให้ทุก query ของ inbox เป็น full table scan ยิ่งข้อความสะสมเยอะยิ่งช้า
--
-- APPLY: run install/migrate_all_tenants_inbox_performance_indexes.php
-- (iterates the legacy DB + every zrismpsz_reya_t_* tenant DB, checks
-- information_schema first, so it is idempotent and MySQL-compatible).
-- This .sql file uses MariaDB `ADD INDEX IF NOT EXISTS` syntax and is the
-- canonical record / manual fallback for a single database.
-- =====================================================================

-- ---------------------------------------------------------------------
-- messages — the hot table (chat open, unread counts, poll, previews)
-- ---------------------------------------------------------------------
-- Equality lookups + InnoDB index extension gives (user_id, id) ordering
-- for cursor pagination (getMessagesCursor: WHERE user_id=? ORDER BY id DESC)
ALTER TABLE `messages` ADD INDEX IF NOT EXISTS `idx_msg_user` (`user_id`);
-- Last-message-per-user subqueries: MAX(created_at) / ORDER BY created_at DESC LIMIT 1
ALTER TABLE `messages` ADD INDEX IF NOT EXISTS `idx_msg_user_created` (`user_id`, `created_at`);
-- Unread counts + mark-as-read: WHERE user_id=? AND direction='incoming' AND is_read=0
ALTER TABLE `messages` ADD INDEX IF NOT EXISTS `idx_msg_user_dir_read` (`user_id`, `direction`, `is_read`);
-- Poll (every 5s per open tab): WHERE line_account_id=? AND created_at > ?
ALTER TABLE `messages` ADD INDEX IF NOT EXISTS `idx_msg_line_created` (`line_account_id`, `created_at`);

-- ---------------------------------------------------------------------
-- users — tenant/account scoping + webhook lookup
-- ---------------------------------------------------------------------
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_line_account` (`line_account_id`);
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_line_user` (`line_user_id`);

-- ---------------------------------------------------------------------
-- conversation_assignments — LEFT JOIN ca ON ca.user_id = u.id (per list row)
-- ---------------------------------------------------------------------
ALTER TABLE `conversation_assignments` ADD INDEX IF NOT EXISTS `idx_ca_user` (`user_id`);

-- ---------------------------------------------------------------------
-- conversation_multi_assignees — assignee lookups: WHERE user_id=? AND status='active'
-- ---------------------------------------------------------------------
ALTER TABLE `conversation_multi_assignees` ADD INDEX IF NOT EXISTS `idx_cma_user_status` (`user_id`, `status`);

-- ---------------------------------------------------------------------
-- user_tag_assignments — tag lookups per conversation row
-- ---------------------------------------------------------------------
ALTER TABLE `user_tag_assignments` ADD INDEX IF NOT EXISTS `idx_uta_user` (`user_id`, `tag_id`);

-- ---------------------------------------------------------------------
-- account_followers — "เพิ่งแอด ยังไม่ทัก" segment + badge count
-- ---------------------------------------------------------------------
ALTER TABLE `account_followers` ADD INDEX IF NOT EXISTS `idx_af_line_following` (`line_account_id`, `is_following`, `user_id`);
