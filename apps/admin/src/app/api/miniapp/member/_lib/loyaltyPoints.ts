import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * loyaltyPoints.ts — port of the ONE classes/LoyaltyPoints.php method api/member.php's `get_card`
 * action actually calls (`getUserPoints`). A second, small, deliberately duplicated port of this same
 * method lives under rewards/_lib/loyaltyPoints.ts — this batch owns both member.php and rewards.php,
 * but the allowed-paths boundary (apps/admin/src/app/api/miniapp/** — ONLY member/, rewards/,
 * wishlist/, no shared ancestor folder) means each endpoint folder is self-contained rather than
 * reaching into a sibling's `_lib`. Read classes/LoyaltyPoints.php in full before editing this file.
 */

export interface UserPointsResult {
  total_points: number;
  available_points: number;
  used_points: number;
}

/**
 * Port of LoyaltyPoints::getUserPoints(): prefers the SUM over `points_transactions`; if that's zero,
 * falls back to the `users` row's total_points/available_points/used_points columns, further falling
 * back to the legacy `points` column when `available_points` is empty but `points` has a value. Note
 * this query is NOT scoped by line_account_id (matches the PHP source exactly — user_id alone).
 */
export async function getUserPoints(db: Kysely<TenantDB>, userId: number): Promise<UserPointsResult> {
  const txResult = await sql<{
    total_points: number | string;
    available_points: number | string;
    used_points: number | string;
  }>`
    SELECT
      COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_points,
      COALESCE(SUM(points), 0) as available_points,
      COALESCE(SUM(CASE WHEN points < 0 THEN ABS(points) ELSE 0 END), 0) as used_points
    FROM points_transactions
    WHERE user_id = ${userId}
  `.execute(db);
  const result = txResult.rows[0];

  if (!result || Number(result.available_points) === 0) {
    const userResult = await sql<{
      total_points: number | string | null;
      available_points: number | string | null;
      used_points: number | string | null;
      points: number | string | null;
    }>`
      SELECT total_points, available_points, used_points, points FROM users WHERE id = ${userId}
    `.execute(db);
    const userRow = userResult.rows[0];
    if (userRow) {
      let availablePoints = userRow.available_points;
      let totalPoints = userRow.total_points;
      const hasAvailable = availablePoints !== null && availablePoints !== undefined && Number(availablePoints) !== 0;
      const hasLegacyPoints = userRow.points !== null && userRow.points !== undefined && Number(userRow.points) !== 0;
      if (!hasAvailable && hasLegacyPoints) {
        availablePoints = userRow.points;
        totalPoints = userRow.points;
      }
      if (Number(availablePoints ?? 0) > 0) {
        return {
          total_points: Number(totalPoints ?? 0),
          available_points: Number(availablePoints ?? 0),
          used_points: Number(userRow.used_points ?? 0),
        };
      }
    }
  }

  if (!result) {
    return { total_points: 0, available_points: 0, used_points: 0 };
  }

  return {
    total_points: Number(result.total_points),
    available_points: Math.max(0, Number(result.available_points)),
    used_points: Number(result.used_points),
  };
}
