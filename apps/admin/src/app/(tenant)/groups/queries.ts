import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — data assembly for /groups, ported from groups.php's
 * three read queries (lines 38-58):
 *
 *   1. SELECT g.*, COUNT(ug.user_id) as member_count FROM groups g
 *      LEFT JOIN user_groups ug ON g.id = ug.group_id GROUP BY g.id
 *      ORDER BY g.name                                    -- NOT scoped by line_account_id
 *   2. SELECT id, display_name, picture_url FROM users
 *      WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL)
 *      ORDER BY display_name                              -- bound $currentBotId
 *   3. (when ?view=<id> is set) SELECT * FROM groups WHERE id = ?, then
 *      SELECT u.* FROM users u JOIN user_groups ug ON u.id = ug.user_id
 *      WHERE ug.group_id = ? AND (u.line_account_id = ? OR u.line_account_id IS NULL)
 *
 * `$currentBotId` in groups.php is never assigned in the file itself — it
 * comes from `includes/header.php` (required on line 11, before any of these
 * queries run) into the including scope: `$currentBotId = $currentBot['id']
 * ?? null;` after header.php's own "sticky" bot auto-select (see that file's
 * lines 138-174). Callers here pass it in explicitly (from
 * `session.currentBotId`, the Next equivalent) rather than resolving it a
 * second time — same convention as `users/queries.ts`'s `getAllTags()`.
 *
 * Deliberately raw `sql` fragments, not Kysely's typed `.selectFrom()`
 * builder — see users/queries.ts's `getUsersListPage()` doc comment for why.
 */

export interface GroupRow {
  id: number;
  name: string;
  description: string | null;
  color: string | null;
  createdAt: Date;
  lineAccountId: number | null;
  memberCount: number;
}

/** Ported from groups.php lines 37-39. Intentionally NOT scoped by line_account_id — the PHP query has no such WHERE clause. */
export async function getGroupsList(db: Kysely<TenantDB>): Promise<GroupRow[]> {
  const result = await sql<GroupRow>`
    SELECT
      g.id AS id, g.name AS name, g.description AS description, g.color AS color,
      g.created_at AS createdAt, g.line_account_id AS lineAccountId,
      COUNT(ug.user_id) AS memberCount
    FROM groups g
    LEFT JOIN user_groups ug ON g.id = ug.group_id
    GROUP BY g.id
    ORDER BY g.name
  `.execute(db);
  return result.rows;
}

export interface UserOptionRow {
  id: number;
  displayName: string | null;
  pictureUrl: string | null;
}

/** Ported from groups.php lines 41-44 — the "add member" dropdown's user list. */
export async function getAllUsersForGroups(db: Kysely<TenantDB>, currentBotId: number | null): Promise<UserOptionRow[]> {
  const result = await sql<UserOptionRow>`
    SELECT id, display_name AS displayName, picture_url AS pictureUrl
    FROM users
    WHERE is_blocked = 0 AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)
    ORDER BY display_name
  `.execute(db);
  return result.rows;
}

export interface GroupDetailRow {
  id: number;
  name: string;
  description: string | null;
  color: string | null;
  createdAt: Date;
  lineAccountId: number | null;
}

/** Ported from groups.php lines 49-52 — plain `SELECT * FROM groups WHERE id = ?`, no line_account_id scoping on the group row itself. */
export async function getGroupById(db: Kysely<TenantDB>, groupId: number): Promise<GroupDetailRow | null> {
  const result = await sql<GroupDetailRow>`
    SELECT id, name, description, color, created_at AS createdAt, line_account_id AS lineAccountId
    FROM groups WHERE id = ${groupId}
  `.execute(db);
  return result.rows[0] ?? null;
}

export interface GroupMemberRow {
  id: number;
  displayName: string | null;
  pictureUrl: string | null;
}

/** Ported from groups.php lines 55-57 — members of one group, scoped like getAllUsersForGroups(). */
export async function getGroupMembers(db: Kysely<TenantDB>, groupId: number, currentBotId: number | null): Promise<GroupMemberRow[]> {
  const result = await sql<GroupMemberRow>`
    SELECT u.id AS id, u.display_name AS displayName, u.picture_url AS pictureUrl
    FROM users u
    JOIN user_groups ug ON u.id = ug.user_id
    WHERE ug.group_id = ${groupId} AND (u.line_account_id = ${currentBotId} OR u.line_account_id IS NULL)
  `.execute(db);
  return result.rows;
}

export interface GroupsPageData {
  groups: GroupRow[];
  allUsers: UserOptionRow[];
  viewGroup: GroupDetailRow | null;
  members: GroupMemberRow[];
}

/**
 * Assembles the whole /groups page's data, mirroring groups.php's
 * top-to-bottom query sequence (including its `isset($_GET['view'])` +
 * "group found" gates around the view/members queries — see lines 47-59).
 */
export async function getGroupsPageData(
  db: Kysely<TenantDB>,
  currentBotId: number | null,
  viewId: number | null
): Promise<GroupsPageData> {
  const [groups, allUsers] = await Promise.all([getGroupsList(db), getAllUsersForGroups(db, currentBotId)]);

  let viewGroup: GroupDetailRow | null = null;
  let members: GroupMemberRow[] = [];
  if (viewId !== null) {
    viewGroup = await getGroupById(db, viewId);
    if (viewGroup) {
      members = await getGroupMembers(db, viewGroup.id, currentBotId);
    }
  }

  return { groups, allUsers, viewGroup, members };
}
