'use server';

import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../users/_lib/session';

/**
 * actions.ts — Server Actions for groups.php's single POST handler (lines
 * 14-35):
 *
 *   - create       -> action==='create': INSERT INTO groups (name, description, color) VALUES (?, ?, ?)
 *   - update       -> action==='update': UPDATE groups SET name=?, description=?, color=? WHERE id=?
 *   - delete       -> action==='delete': DELETE FROM groups WHERE id = ?
 *   - add_member   -> action==='add_member': INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)
 *   - remove_member -> action==='remove_member': DELETE FROM user_groups WHERE user_id = ? AND group_id = ?
 *
 * `INSERT IGNORE INTO user_groups (user_id, group_id)` deliberately does NOT
 * set `line_account_id` — same as the PHP source. `user_groups.line_account_id`
 * is `NOT NULL DEFAULT 1` (database/migration_2026-05-25_tenant_template.sql),
 * so the DB default silently applies, exactly matching the PHP behavior of
 * never touching that column.
 *
 * Redirect shape mirrors groups.php's shared
 * `header('Location: groups.php' . (isset($_POST['group_id']) ? '?view=' .
 * $_POST['group_id'] : ''))` EXACTLY: create/update/delete's forms only ever
 * post `id` (never `group_id` — see the #modal form's hidden inputs), so
 * they redirect to bare `/groups`, losing any `?view=` the user was on. Only
 * add_member/remove_member post an explicit `group_id` field, so only those
 * two redirect back to `/groups?view=<group_id>`. This looks asymmetric but
 * is a byte-for-byte port of the PHP redirect logic, not a bug introduced
 * here.
 */

export interface GroupFormInput {
  name: string;
  description: string;
  color: string;
}

function readGroupInput(formData: FormData): GroupFormInput {
  return {
    name: String(formData.get('name') ?? ''),
    description: String(formData.get('description') ?? ''),
    color: String(formData.get('color') ?? ''),
  };
}

export async function createGroupAction(formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const input = readGroupInput(formData);
  await sql`INSERT INTO groups (name, description, color) VALUES (${input.name}, ${input.description}, ${input.color})`.execute(db);
  redirect('/groups');
}

export async function updateGroupAction(id: number, formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const input = readGroupInput(formData);
  await sql`UPDATE groups SET name = ${input.name}, description = ${input.description}, color = ${input.color} WHERE id = ${id}`.execute(
    db
  );
  redirect('/groups');
}

export async function deleteGroupAction(id: number): Promise<void> {
  const { db } = await requireTenantPageContext();
  await sql`DELETE FROM groups WHERE id = ${id}`.execute(db);
  redirect('/groups');
}

export async function addMemberAction(groupId: number, formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();
  const userId = Number.parseInt(String(formData.get('user_id') ?? ''), 10) || 0;
  await sql`INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (${userId}, ${groupId})`.execute(db);
  redirect(`/groups?view=${groupId}`);
}

export async function removeMemberAction(groupId: number, userId: number): Promise<void> {
  const { db } = await requireTenantPageContext();
  await sql`DELETE FROM user_groups WHERE user_id = ${userId} AND group_id = ${groupId}`.execute(db);
  redirect(`/groups?view=${groupId}`);
}
