import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * assignTag.ts — the insert behind api/inbox-v2.php's `case 'assign_tag':`
 * (lines 2130-2153):
 *
 * ```php
 * $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())");
 * $stmt->execute([$userId, $tagId, $adminId ?? 'Admin']);
 * ```
 *
 * DISTINCT FROM the same-page-AJAX `update_tags` action (a different PHP
 * file's own switch, already ported at the sibling, already-merged
 * `actions/tags` Route Handler): that one is add/remove-toggle with
 * `assigned_by` hardcoded to the literal `'manual'` and echoes the full
 * current tag list back in its response. This action is insert-only — no
 * remove/toggle branch, no tag-list echo, and `assigned_by` here is the
 * acting admin's ID (falling back to the literal string `'Admin'`), not a
 * fixed source label. Nothing in this file imports from, or references,
 * that other action family's directory.
 *
 * SCHEMA-DRIFT NOTE (mirrors the identical, already-flagged gap on
 * `user_tag_assignments` that the already-merged `actions/tags` INSERT
 * IGNORE is *also* silently exposed to — see that file's own doc comment
 * for the sibling finding on the same table): the committed
 * `database/migration_2026-05-25_tenant_template.sql` gives
 * `user_tag_assignments` only a non-unique `KEY idx_uta_user(user_id, tag_id)`,
 * not the `UNIQUE KEY unique_user_tag(user_id, tag_id)` production's
 * `install_complete_latest.sql` additionally carries. `INSERT IGNORE` only
 * suppresses a duplicate-key error when a matching unique/primary key
 * exists to violate — on a freshly-provisioned tenant DB built from the
 * committed template, this statement can therefore insert duplicate
 * `(user_id, tag_id)` rows instead of no-op'ing on a re-assign, exactly
 * like the sibling `update_tags` action's own `add` branch already does on
 * the same table. This is a pre-existing schema gap outside this batch's
 * allowed paths (`database/**` is off-limits) — the SQL below is written
 * literally as PHP has it, matching PHP's own (equally "wrong" on a fresh
 * tenant) behavior byte-for-byte, not attempting a fix.
 *
 * `created_at` is bound to `NOW()` explicitly (matching the literal PHP
 * SQL) even though the column also carries a `current_timestamp()` default
 * — see `actions/notes/_lib/`'s equivalent `sql\`NOW()\`` value for the
 * same pattern already established in this codebase.
 */
export async function assignTagAction(
  db: Kysely<TenantDB>,
  userId: number,
  tagId: number,
  assignedBy: string
): Promise<void> {
  await db
    .insertInto('user_tag_assignments')
    // line_account_id intentionally omitted — PHP's prepared statement never
    // binds it either (matches the sibling update_tags INSERT IGNORE's own
    // omission); the column is NOT NULL DEFAULT 1, so MySQL applies that
    // default.
    .values({
      user_id: userId,
      tag_id: tagId,
      assigned_by: assignedBy,
      created_at: sql`NOW()`,
    })
    .ignore()
    .execute();
}

/**
 * PHP's `$adminId ?? 'Admin'` (api/inbox-v2.php line 2148), where `$adminId`
 * is the file-level `$_SESSION['admin_id'] ?? $_GET['admin_id'] ?? $_POST['admin_id'] ?? null`
 * (line 72). `TenantSession.adminUserId` (@reya/auth) is always a number —
 * this fallback is therefore structurally unreachable via this Route
 * Handler today, but kept for literal parity with the broader PHP
 * expression (which genuinely can be null when no session/admin_id request
 * param is present) and to keep the `assigned_by varchar(50)` column fed a
 * string either way.
 */
export function resolveAssignedBy(adminUserId: number | null | undefined): string {
  return adminUserId !== null && adminUserId !== undefined ? String(adminUserId) : 'Admin';
}
