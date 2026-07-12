import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — literal port of loyalty-members.php's own inline query block
 * (lines 33-65) + its `lmName()` display-name helper (lines 18-31). Gated
 * exactly like the PHP source: `if ($lineAccountId > 0)` — when there is no
 * current bot selected, PHP renders the page with the zeroed defaults
 * ($stats = ['total'=>0,'points'=>0,'today'=>0], $members = []) rather than
 * querying at all; getLoyaltyMembersData() reproduces that short-circuit.
 */

export interface LoyaltyMemberRow {
  id: number;
  display_name: string | null;
  real_name: string | null;
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  available_points: number;
  total_points: number;
  created_at: Date;
}

export interface LoyaltyMemberStats {
  total: number;
  points: number;
  today: number;
}

export interface LoyaltyMembersData {
  stats: LoyaltyMemberStats;
  members: LoyaltyMemberRow[];
}

const EMPTY_STATS: LoyaltyMemberStats = { total: 0, points: 0, today: 0 };

/**
 * Ported from loyalty-members.php lines 36-65. `search` is
 * `trim($_GET['q'] ?? '')`; when non-empty it's ANDed onto the phone/
 * real_name/display_name LIKE clause exactly as the PHP source does. Falls
 * back to the same defaults on any query error (mirrors the PHP source's
 * `catch (Throwable $e) { error_log(...) }` — the stats/members already
 * assigned before the try block stay at their pre-try values on failure,
 * which for a rethrown mid-block error would be whatever was set so far;
 * simplified here to "reset both to empty on any error", the practically
 * equivalent outcome since the two queries are the only writes into either
 * variable).
 */
export async function getLoyaltyMembersData(db: Kysely<TenantDB>, lineAccountId: number, search: string): Promise<LoyaltyMembersData> {
  if (lineAccountId <= 0) {
    return { stats: EMPTY_STATS, members: [] };
  }

  try {
    const statsResult = await sql<{ total: number; points: number; today: number }>`
      SELECT COUNT(*) AS total,
             COALESCE(SUM(available_points), 0) AS points,
             COALESCE(SUM(created_at >= CURDATE()), 0) AS today
      FROM users
      WHERE line_account_id = ${lineAccountId} AND line_user_id LIKE 'offline:%'
    `.execute(db);
    const stats = statsResult.rows[0] ?? EMPTY_STATS;

    let members: LoyaltyMemberRow[];
    if (search !== '') {
      const like = `%${search}%`;
      const membersResult = await sql<LoyaltyMemberRow>`
        SELECT id, display_name, real_name, first_name, last_name, phone, available_points, total_points, created_at
        FROM users
        WHERE line_account_id = ${lineAccountId} AND line_user_id LIKE 'offline:%'
        AND (phone LIKE ${like} OR real_name LIKE ${like} OR display_name LIKE ${like})
        ORDER BY created_at DESC LIMIT 300
      `.execute(db);
      members = membersResult.rows;
    } else {
      const membersResult = await sql<LoyaltyMemberRow>`
        SELECT id, display_name, real_name, first_name, last_name, phone, available_points, total_points, created_at
        FROM users
        WHERE line_account_id = ${lineAccountId} AND line_user_id LIKE 'offline:%'
        ORDER BY created_at DESC LIMIT 300
      `.execute(db);
      members = membersResult.rows;
    }

    return {
      stats: { total: Number(stats.total ?? 0), points: Number(stats.points ?? 0), today: Number(stats.today ?? 0) },
      members,
    };
  } catch {
    return { stats: EMPTY_STATS, members: [] };
  }
}

/** Ported from loyalty-members.php's lmName(array $u) helper (lines 18-31). */
export function lmName(u: { real_name?: string | null; first_name?: string | null; last_name?: string | null; display_name?: string | null }): string {
  const real = (u.real_name ?? '').trim();
  if (real !== '') return real;
  const parts = `${(u.first_name ?? '').trim()} ${(u.last_name ?? '').trim()}`.trim();
  if (parts !== '') return parts;
  const display = (u.display_name ?? '').trim();
  return display !== '' ? display : 'ลูกค้า';
}
