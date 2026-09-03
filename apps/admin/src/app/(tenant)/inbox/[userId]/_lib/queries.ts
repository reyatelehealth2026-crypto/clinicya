import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getInitialMessages, type MessageRow } from '../../../../api/inbox/messages/_lib/query';

/**
 * queries.ts — data assembly for `(tenant)/inbox/[userId]`'s page.tsx,
 * ported from inbox-v2.php's "Get Selected User" block (lines 1104-1139):
 * the `users` row (+ `effective_display_name`, line 1121), its `user_tags`
 * (lines 1131-1134), and the latest-300 message read (delegated to
 * `getInitialMessages()` in the sibling `api/inbox/messages/_lib/query.ts`
 * this batch also owns — see the brief's "May create/edit ONLY" scope).
 *
 * DELIBERATELY NOT PORTED HERE (see page.tsx's module doc + the brief's
 * boundaries):
 *  - `UPDATE messages SET is_read = 1 ...` (line 1123) — the page-load
 *    mark-as-read side effect. Deferred with every other mutation this
 *    batch excludes; this is read-only data assembly.
 *  - `allTags` (line 1135-1137, every tag available for the picker), the
 *    HealthEngine profile/classification (lines 1141-1147), and the PDPA
 *    health-data-consent lookup (lines 1149-1159) — all belong to the
 *    HUD/tag-editor/consult panels this batch does not port (no UI here
 *    reads them).
 *
 * Independently queries `users`/`user_tags`/`user_tag_assignments` rather
 * than importing anything from `(tenant)/inbox/_lib/**` or
 * `api/inbox/conversations/**` — those are conversationList's exclusive
 * territory (see the brief: "fetch independently if you need tag/admin
 * data, never cross-import").
 */

export interface SelectedUserRow {
  id: number;
  picture_url: string | null;
  display_name: string | null;
  custom_display_name: string | null;
}

export interface UserTagRow {
  id: number;
  name: string;
  color: string | null;
}

export interface InboxThreadPageData {
  selectedUser: SelectedUserRow;
  userTags: UserTagRow[];
  messages: MessageRow[];
}

async function getSelectedUser(db: Kysely<TenantDB>, userId: number): Promise<SelectedUserRow | null> {
  const result = await sql<SelectedUserRow>`
    SELECT id, picture_url, display_name, custom_display_name
    FROM users
    WHERE id = ${userId}
  `.execute(db);
  return result.rows[0] ?? null;
}

/** `SELECT t.* FROM user_tags t JOIN user_tag_assignments uta ON t.id = uta.tag_id WHERE uta.user_id = ?` (inbox-v2.php lines 1131-1134). */
async function getUserTagsForUser(db: Kysely<TenantDB>, userId: number): Promise<UserTagRow[]> {
  const result = await sql<UserTagRow>`
    SELECT t.id, t.name, t.color
    FROM user_tags t
    JOIN user_tag_assignments uta ON t.id = uta.tag_id
    WHERE uta.user_id = ${userId}
  `.execute(db);
  return result.rows;
}

/**
 * Assembles `/inbox/[userId]`'s whole page in one call. Returns `null` when
 * `userId` doesn't resolve to a `users` row — mirrors inbox-v2.php's
 * `$selectedUser = $stmt->fetch(...)` coming back `false` (the page then
 * simply never enters its `<?php if ($selectedUser): ?>` chat-pane branch;
 * page.tsx renders a not-found state instead of a silent empty pane).
 */
export async function getInboxThreadPageData(db: Kysely<TenantDB>, userId: number): Promise<InboxThreadPageData | null> {
  const selectedUser = await getSelectedUser(db, userId);
  if (!selectedUser) {
    return null;
  }

  const [userTags, messages] = await Promise.all([getUserTagsForUser(db, userId), getInitialMessages(db, userId)]);

  return { selectedUser, userTags, messages };
}
