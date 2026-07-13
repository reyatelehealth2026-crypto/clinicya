import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — data assembly for /line-group-detail?id=N, ported from
 * line-group-detail.php (179 LOC, read-only, confirmed by reading the full
 * file — no `$_SERVER['REQUEST_METHOD'] === 'POST'` handling anywhere).
 *
 *   1. lines 15-22: group row + bot_name, LEFT JOIN line_accounts,
 *      WHERE g.id = ? — no line_account_id scoping (any group id resolves,
 *      regardless of the currently-selected bot).
 *   2. lines 33-41: `SELECT * FROM line_group_members WHERE group_id = ?
 *      ORDER BY is_active DESC, total_messages DESC`, wrapped in
 *      `try {} catch (Exception $e) {}` — silently falls back to `[]`.
 *   3. lines 45-56: `SELECT gm.*, lgm.display_name FROM line_group_messages
 *      gm LEFT JOIN line_group_members lgm ON gm.group_id = lgm.group_id AND
 *      gm.line_user_id = lgm.line_user_id WHERE gm.group_id = ? ORDER BY
 *      gm.created_at DESC LIMIT 50`, same try/catch-to-`[]` fallback.
 */

export interface LineGroupDetailRow {
  id: number;
  groupId: string;
  groupType: 'group' | 'room' | null;
  groupName: string | null;
  pictureUrl: string | null;
  memberCount: number;
  totalMessages: number;
  isActive: number | null;
  joinedAt: Date;
  botName: string | null;
}

/** Ported from line-group-detail.php lines 14-22. Returns null when the id doesn't resolve — page.tsx redirects on null (mirrors `header('Location: line-groups.php'); exit;`). */
export async function getLineGroupDetail(db: Kysely<TenantDB>, groupId: number): Promise<LineGroupDetailRow | null> {
  const result = await sql<LineGroupDetailRow>`
    SELECT g.id AS id, g.group_id AS groupId, g.group_type AS groupType, g.group_name AS groupName,
           g.picture_url AS pictureUrl, g.member_count AS memberCount, g.total_messages AS totalMessages,
           g.is_active AS isActive, g.joined_at AS joinedAt, la.name AS botName
    FROM line_groups g
    LEFT JOIN line_accounts la ON g.line_account_id = la.id
    WHERE g.id = ${groupId}
  `.execute(db);
  return result.rows[0] ?? null;
}

export interface LineGroupMemberDetailRow {
  id: number;
  displayName: string | null;
  pictureUrl: string | null;
  isActive: number | null;
  totalMessages: number;
  lastMessageAt: Date | null;
}

/** Ported from line-group-detail.php lines 33-41. */
export async function getLineGroupMembersDetail(db: Kysely<TenantDB>, groupId: number): Promise<LineGroupMemberDetailRow[]> {
  try {
    const result = await sql<LineGroupMemberDetailRow>`
      SELECT id, display_name AS displayName, picture_url AS pictureUrl, is_active AS isActive,
             total_messages AS totalMessages, last_message_at AS lastMessageAt
      FROM line_group_members
      WHERE group_id = ${groupId}
      ORDER BY is_active DESC, total_messages DESC
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

export interface LineGroupMessageRow {
  id: number;
  displayName: string | null;
  createdAt: Date;
  messageType: string | null;
  content: string | null;
}

/** Ported from line-group-detail.php lines 45-56. */
export async function getLineGroupMessagesDetail(db: Kysely<TenantDB>, groupId: number): Promise<LineGroupMessageRow[]> {
  try {
    const result = await sql<LineGroupMessageRow>`
      SELECT gm.id AS id, lgm.display_name AS displayName, gm.created_at AS createdAt,
             gm.message_type AS messageType, gm.content AS content
      FROM line_group_messages gm
      LEFT JOIN line_group_members lgm ON gm.group_id = lgm.group_id AND gm.line_user_id = lgm.line_user_id
      WHERE gm.group_id = ${groupId}
      ORDER BY gm.created_at DESC
      LIMIT 50
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

export interface LineGroupDetailPageData {
  group: LineGroupDetailRow;
  members: LineGroupMemberDetailRow[];
  messages: LineGroupMessageRow[];
}

/** Assembles the whole page's data. Returns null when the group id doesn't resolve. */
export async function getLineGroupDetailPageData(db: Kysely<TenantDB>, groupId: number): Promise<LineGroupDetailPageData | null> {
  const group = await getLineGroupDetail(db, groupId);
  if (!group) {
    return null;
  }
  const [members, messages] = await Promise.all([getLineGroupMembersDetail(db, groupId), getLineGroupMessagesDetail(db, groupId)]);
  return { group, members, messages };
}
