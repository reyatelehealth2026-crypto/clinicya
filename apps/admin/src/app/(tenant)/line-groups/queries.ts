import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — data assembly for /line-groups, ported from line-groups.php's
 * two read blocks (lines 79-128).
 *
 * `$currentBotId` in line-groups.php is its OWN local variable (line 15:
 * `$currentBotId = $_SESSION['current_bot_id'] ?? null;`), NOT the
 * `includes/header.php`-derived one groups.php relies on (that page's
 * `require_once 'includes/header.php'` doesn't even run until line 130,
 * AFTER these queries) — this page reads `$_SESSION['current_bot_id']`
 * directly with no `getAccessibleBots()`/sticky-default fallback. Mirrored
 * here as `session.currentBotId` (the Next session's equivalent of the same
 * raw `$_SESSION['current_bot_id']` value), not re-deriving a "sticky
 * default bot" the PHP source never computes for this page.
 *
 * Groups list — line-groups.php lines 80-102: BOTH branches ported
 * (scoped prepared statement when `$currentBotId` is set, else an unscoped
 * `$db->query()` with no bound param at all — not "bind NULL", genuinely no
 * WHERE clause). The whole block is wrapped in `try { } catch (Exception $e)
 * { // Table doesn't exist }`, silently falling back to `$groups = []` —
 * reproduced by returning `[]` on any query failure.
 *
 * Stats — lines 104-128: four independent aggregate queries, also wrapped in
 * one try/catch that leaves whichever stats haven't been computed yet at
 * their zeroed defaults. PHP builds the WHERE clause via raw string
 * interpolation of `$currentBotId` (not a bound parameter) — reproduced
 * here with an actual bound parameter instead (behaviorally identical
 * output; `$currentBotId` is always an internal int from the session, never
 * raw user input, so there is no injection-safety regression either way —
 * this only changes HOW the value reaches MySQL, not what the query returns).
 */

export interface LineGroupRow {
  id: number;
  groupId: string;
  groupType: 'group' | 'room' | null;
  groupName: string | null;
  pictureUrl: string | null;
  memberCount: number;
  isActive: number | null;
  joinedAt: Date;
  totalMessages: number;
  lineAccountId: number;
  botName: string | null;
}

export async function getLineGroupsList(db: Kysely<TenantDB>, currentBotId: number | null): Promise<LineGroupRow[]> {
  try {
    if (currentBotId) {
      const result = await sql<LineGroupRow>`
        SELECT g.id AS id, g.group_id AS groupId, g.group_type AS groupType, g.group_name AS groupName,
               g.picture_url AS pictureUrl, g.member_count AS memberCount, g.is_active AS isActive,
               g.joined_at AS joinedAt, g.total_messages AS totalMessages, g.line_account_id AS lineAccountId,
               la.name AS botName
        FROM line_groups g
        LEFT JOIN line_accounts la ON g.line_account_id = la.id
        WHERE g.line_account_id = ${currentBotId}
        ORDER BY g.is_active DESC, g.joined_at DESC
      `.execute(db);
      return result.rows;
    }
    const result = await sql<LineGroupRow>`
      SELECT g.id AS id, g.group_id AS groupId, g.group_type AS groupType, g.group_name AS groupName,
             g.picture_url AS pictureUrl, g.member_count AS memberCount, g.is_active AS isActive,
             g.joined_at AS joinedAt, g.total_messages AS totalMessages, g.line_account_id AS lineAccountId,
             la.name AS botName
      FROM line_groups g
      LEFT JOIN line_accounts la ON g.line_account_id = la.id
      ORDER BY g.is_active DESC, g.joined_at DESC
    `.execute(db);
    return result.rows;
  } catch {
    // Mirrors line-groups.php's `catch (Exception $e) { // Table doesn't exist }`.
    return [];
  }
}

export interface LineGroupsStats {
  total: number;
  active: number;
  totalMembers: number;
  totalMessages: number;
}

const ZERO_STATS: LineGroupsStats = { total: 0, active: 0, totalMembers: 0, totalMessages: 0 };

export async function getLineGroupsStats(db: Kysely<TenantDB>, currentBotId: number | null): Promise<LineGroupsStats> {
  try {
    const totalResult = currentBotId
      ? await sql<{ count: number }>`SELECT COUNT(*) AS count FROM line_groups WHERE line_account_id = ${currentBotId}`.execute(db)
      : await sql<{ count: number }>`SELECT COUNT(*) AS count FROM line_groups`.execute(db);

    const activeResult = currentBotId
      ? await sql<{ count: number }>`SELECT COUNT(*) AS count FROM line_groups WHERE line_account_id = ${currentBotId} AND is_active = 1`.execute(
          db
        )
      : await sql<{ count: number }>`SELECT COUNT(*) AS count FROM line_groups WHERE is_active = 1`.execute(db);

    const membersResult = currentBotId
      ? await sql<{ total: number | null }>`SELECT SUM(member_count) AS total FROM line_groups WHERE line_account_id = ${currentBotId}`.execute(
          db
        )
      : await sql<{ total: number | null }>`SELECT SUM(member_count) AS total FROM line_groups`.execute(db);

    const messagesResult = currentBotId
      ? await sql<{ total: number | null }>`SELECT SUM(total_messages) AS total FROM line_groups WHERE line_account_id = ${currentBotId}`.execute(
          db
        )
      : await sql<{ total: number | null }>`SELECT SUM(total_messages) AS total FROM line_groups`.execute(db);

    return {
      total: Number(totalResult.rows[0]?.count ?? 0),
      active: Number(activeResult.rows[0]?.count ?? 0),
      totalMembers: Number(membersResult.rows[0]?.total ?? 0),
      totalMessages: Number(messagesResult.rows[0]?.total ?? 0),
    };
  } catch {
    return ZERO_STATS;
  }
}

export interface LineGroupsPageData {
  groups: LineGroupRow[];
  stats: LineGroupsStats;
}

export async function getLineGroupsPageData(db: Kysely<TenantDB>, currentBotId: number | null): Promise<LineGroupsPageData> {
  const [groups, stats] = await Promise.all([getLineGroupsList(db, currentBotId), getLineGroupsStats(db, currentBotId)]);
  return { groups, stats };
}

// ---------------------------------------------------------------------------
// leave_group Server Action support (DB-side only — see actions.ts)
// ---------------------------------------------------------------------------

export interface LineGroupForLeave {
  id: number;
  lineAccountId: number;
  groupId: string;
  groupType: 'group' | 'room' | null;
  groupName: string | null;
}

/** Ported from line-groups.php lines 27-30 — fetch the group row before leaving. */
export async function getLineGroupForLeave(db: Kysely<TenantDB>, groupDbId: number): Promise<LineGroupForLeave | null> {
  const result = await sql<LineGroupForLeave>`
    SELECT id, line_account_id AS lineAccountId, group_id AS groupId, group_type AS groupType, group_name AS groupName
    FROM line_groups WHERE id = ${groupDbId}
  `.execute(db);
  return result.rows[0] ?? null;
}

/** Ported from line-groups.php line 45: `UPDATE line_groups SET is_active = 0, left_at = NOW() WHERE id = ?`. */
export async function markLineGroupLeft(db: Kysely<TenantDB>, groupDbId: number): Promise<void> {
  await sql`UPDATE line_groups SET is_active = 0, left_at = NOW() WHERE id = ${groupDbId}`.execute(db);
}
