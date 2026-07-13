import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { PointsHistoryResponse } from '@reya/contracts';

/**
 * query.ts — TypeScript port of api/points-history.php's `action=history`
 * ONLY (read api/points-history.php in full — 432 lines — before writing
 * this file; every other action is out of this batch's scope, see route.ts).
 *
 * User lookup mirrors the PHP file's exact fallback chain: when
 * `line_account_id` is given, try the scoped row first, then fall back to
 * the unscoped row (legacy rows without line_account_id); when it's absent,
 * go straight to the unscoped lookup.
 *
 * `available_points`/`total_points` fallback to the legacy `points` column
 * when `available_points` is empty but `points` has a value — ported
 * verbatim (`empty($user['available_points']) && !empty($user['points'])`).
 */

interface UserRow {
  id: number;
  line_account_id: number | null;
  total_points: number | null;
  available_points: number | null;
  used_points: number | null;
  points: number | null;
  display_name: string | null;
}

async function selectUserByLineUserId(db: Kysely<TenantDB>, lineUserId: string): Promise<UserRow | null> {
  const result = await sql<UserRow>`
    SELECT id, line_account_id, total_points, available_points, used_points, points, display_name
      FROM users WHERE line_user_id = ${lineUserId} LIMIT 1
  `.execute(db);
  return result.rows[0] ?? null;
}

async function selectUserByLineUserIdAndAccount(
  db: Kysely<TenantDB>,
  lineUserId: string,
  lineAccountId: number
): Promise<UserRow | null> {
  const result = await sql<UserRow>`
    SELECT id, line_account_id, total_points, available_points, used_points, points, display_name
      FROM users WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId} LIMIT 1
  `.execute(db);
  return result.rows[0] ?? null;
}

async function findUser(
  db: Kysely<TenantDB>,
  lineUserId: string,
  lineAccountId: number | null
): Promise<UserRow | null> {
  if (lineAccountId !== null && lineAccountId > 0) {
    const scoped = await selectUserByLineUserIdAndAccount(db, lineUserId, lineAccountId);
    if (scoped) {
      return scoped;
    }
    // Fallback: same person, no account scoping (legacy rows without line_account_id).
    return selectUserByLineUserId(db, lineUserId);
  }

  return selectUserByLineUserId(db, lineUserId);
}

interface HistoryRow {
  id: number;
  type: 'adjust' | 'earn' | 'expire' | 'redeem' | 'refund';
  points: number;
  balance_after: number;
  description: string | null;
  reference_type: string | null;
  reference_id: number | null;
  created_at: string | Date;
}

/** `date('d/m/Y H:i', strtotime($created_at))` — Gregorian day/month/year (NOT Buddhist-era; PHP's date() here never applies the +543 offset this codebase uses elsewhere for user-facing Thai dates). */
function formatPhpDate(createdAt: string | Date): string {
  const d = createdAt instanceof Date ? createdAt : new Date(createdAt.replace(' ', 'T'));
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function isoLikeString(value: string | Date): string {
  if (typeof value === 'string') return value;
  // Match MySQL's `YYYY-MM-DD HH:MM:SS` DATETIME text form (PDO's default), not a `Z`-suffixed ISO string.
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

export async function getPointsHistoryAction(
  db: Kysely<TenantDB>,
  lineUserId: string | null,
  lineAccountId: number | null,
  limit: number
): Promise<PointsHistoryResponse> {
  if (!lineUserId) {
    return { success: false, error: 'Missing line_user_id' };
  }

  const user = await findUser(db, lineUserId, lineAccountId);
  if (!user) {
    return { success: false, error: 'User not found' };
  }

  let availablePoints = user.available_points;
  let totalPoints = user.total_points;
  if (!availablePoints && user.points) {
    availablePoints = user.points;
    totalPoints = user.points;
  }

  const historyResult = await sql<HistoryRow>`
    SELECT id, type, points, balance_after, description, reference_type, reference_id, created_at
      FROM points_transactions
     WHERE user_id = ${user.id}
     ORDER BY created_at DESC
     LIMIT ${limit}
  `.execute(db);

  return {
    success: true,
    user: {
      name: user.display_name,
      total_points: Number(totalPoints ?? 0),
      available_points: Number(availablePoints ?? 0),
      used_points: Number(user.used_points ?? 0),
    },
    history: historyResult.rows.map((row) => ({
      id: Number(row.id),
      type: row.type,
      points: Number(row.points),
      balance_after: Number(row.balance_after),
      description: row.description,
      reference_type: row.reference_type,
      reference_id: row.reference_id === null ? null : Number(row.reference_id),
      created_at: isoLikeString(row.created_at),
      formatted_date: formatPhpDate(row.created_at),
    })),
  };
}
