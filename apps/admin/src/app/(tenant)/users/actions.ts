'use server';

import { sql } from 'kysely';
import { revalidatePath } from 'next/cache';
import { requireTenantPageContext } from './_lib/session';

/**
 * actions.ts — Server Actions replicating api/ajax_handler.php's tag cases
 * exactly (lines 73-107 for the bulk cases, 165-204 for the single cases):
 *
 *   - assignTagAction   -> case 'assign_tag':   INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (?, ?, 'manual')
 *   - removeTagAction   -> case 'remove_tag':   DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?
 *   - bulkAssignTagAction -> case 'bulk_assign_tag': same INSERT IGNORE looped per user id, assigned_by='bulk', count = rows actually inserted
 *   - bulkRemoveTagAction -> case 'bulk_remove_tag': same DELETE looped per user id, count = rows actually deleted
 *   - getUserTagsAction -> case 'get_user_tags': SELECT t.* FROM user_tags t JOIN user_tag_assignments a ON t.id = a.tag_id WHERE a.user_id = ?
 *
 * Intentional gap (flagged per this batch's brief, not silently dropped):
 * ajax_handler.php's `assign_tag` case also writes an ActivityLogger row
 * (`classes/ActivityLogger.php`, action UPDATE, "ติด Tag ลูกค้า"). That
 * audit write is NOT reproduced here — ActivityLogger/TenantActivity
 * best-effort audit writes are out of scope for this batch per the brief.
 * `user_tag_assignments` row state (what mig-verify's fixture-diff checks)
 * is unaffected by this gap.
 *
 * revalidatePath('/users') is called after the two BULK actions only,
 * mirroring the PHP client's `location.reload()` after a bulk op (see
 * users.php's bulkAssignTag()/bulkRemoveTag() inline `<script>`). The two
 * SINGLE actions (assign/remove via the tag modal) deliberately do NOT
 * revalidate — in the PHP original, single assign/remove only refreshes the
 * modal's own tag list (`loadUserTags()`), never the underlying page/table,
 * until the user manually reloads. Preserved here for behavioral parity.
 *
 * Every write below goes through the raw `sql` escape hatch, not Kysely's
 * typed `.insertInto()/.deleteFrom()/.selectFrom()` builder — see
 * queries.ts's `getUsersListPage()` doc comment for why (no
 * `CamelCasePlugin` on the shared `Kysely<TenantDB>` instance, so the typed
 * builder's camelCase property names would compile to nonexistent
 * camelCase SQL identifiers instead of the real snake_case columns).
 */

export interface TagActionResult {
  success: boolean;
}

export interface BulkTagActionResult {
  success: boolean;
  count: number;
}

export interface UserTagRow {
  id: number;
  name: string;
  color: string | null;
}

function assertValidIds(userId: number, tagId: number): void {
  if (!Number.isInteger(userId) || userId <= 0 || !Number.isInteger(tagId) || tagId <= 0) {
    throw new Error('Missing required fields');
  }
}

export async function assignTagAction(userId: number, tagId: number): Promise<TagActionResult> {
  assertValidIds(userId, tagId);
  const { db } = await requireTenantPageContext();
  await sql`INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (${userId}, ${tagId}, 'manual')`.execute(
    db
  );
  return { success: true };
}

export async function removeTagAction(userId: number, tagId: number): Promise<TagActionResult> {
  assertValidIds(userId, tagId);
  const { db } = await requireTenantPageContext();
  await sql`DELETE FROM user_tag_assignments WHERE user_id = ${userId} AND tag_id = ${tagId}`.execute(db);
  return { success: true };
}

export async function bulkAssignTagAction(userIds: number[], tagId: number): Promise<BulkTagActionResult> {
  if (userIds.length === 0 || !tagId) {
    throw new Error('Missing required fields');
  }
  const { db } = await requireTenantPageContext();
  let count = 0;
  for (const userId of userIds) {
    const result = await sql`INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by) VALUES (${Number(userId)}, ${tagId}, 'bulk')`.execute(
      db
    );
    if ((result.numAffectedRows ?? BigInt(0)) > BigInt(0)) {
      count++;
    }
  }
  revalidatePath('/users');
  return { success: true, count };
}

export async function bulkRemoveTagAction(userIds: number[], tagId: number): Promise<BulkTagActionResult> {
  if (userIds.length === 0 || !tagId) {
    throw new Error('Missing required fields');
  }
  const { db } = await requireTenantPageContext();
  let count = 0;
  for (const userId of userIds) {
    const result = await sql`DELETE FROM user_tag_assignments WHERE user_id = ${Number(userId)} AND tag_id = ${tagId}`.execute(
      db
    );
    if ((result.numAffectedRows ?? BigInt(0)) > BigInt(0)) {
      count++;
    }
  }
  revalidatePath('/users');
  return { success: true, count };
}

export async function getUserTagsAction(userId: number): Promise<UserTagRow[]> {
  const { db } = await requireTenantPageContext();
  const result = await sql<UserTagRow>`
    SELECT t.id, t.name, t.color
    FROM user_tags t
    JOIN user_tag_assignments a ON t.id = a.tag_id
    WHERE a.user_id = ${userId}
  `.execute(db);
  return result.rows;
}
