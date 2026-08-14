import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * loyaltyPoints.ts — literal port of `classes/LoyaltyPoints.php`'s
 * `getUserPoints($userId)` ONLY (lines 50-96), as called by
 * `api/inbox-v2.php`'s `case 'customer_crm':`:
 *
 * ```php
 * try {
 *     require_once __DIR__ . '/../classes/LoyaltyPoints.php';
 *     $loyalty = new LoyaltyPoints($db, $lineAccountId);
 *     $points = $loyalty->getUserPoints($userId);
 *     ...
 * } catch (Exception $e) {
 *     logInboxApiException($e, 'catch');
 * }
 * ```
 *
 * `LoyaltyPoints::__construct()` also calls `loadSettings()` (queries
 * `points_settings`), and the class separately exposes `calculatePoints()`
 * — NEITHER is ported here. `loadSettings()` populates `$this->settings`,
 * which `getUserPoints()` never reads, and it has its own internal
 * `try/catch` that never lets a failure propagate out of construction
 * (falls back to hardcoded defaults on any `Exception`) — so skipping it
 * changes nothing observable on this call path, it just avoids an unused
 * `points_settings` query. `calculatePoints()` is unrelated to this action
 * entirely (used by the points-award flow, not the CRM read). This module
 * is a plain function, not a class — `new LoyaltyPoints(...)` construction
 * plus the `getUserPoints()` call collapses to one function call.
 *
 * This is a FRESH, INDEPENDENTLY-OWNED copy scoped to `customer-crm/**` —
 * `apps/admin/src/app/api/miniapp/{member,rewards}/_lib/loyaltyPoints.ts`
 * already exist as unrelated ports of DIFFERENT `LoyaltyPoints` methods for
 * the miniapp surface. Do not import from, or rename this file to collide
 * with, those.
 *
 * ```php
 * public function getUserPoints($userId)
 * {
 *     // First, try to get from points_transactions
 *     $stmt = $this->db->prepare("
 *         SELECT
 *             COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_points,
 *             COALESCE(SUM(points), 0) as available_points,
 *             COALESCE(SUM(CASE WHEN points < 0 THEN ABS(points) ELSE 0 END), 0) as used_points
 *         FROM points_transactions
 *         WHERE user_id = ?
 *     ");
 *     $stmt->execute([$userId]);
 *     $result = $stmt->fetch(PDO::FETCH_ASSOC);
 *
 *     // If no data in points_transactions, fallback to users table
 *     if (!$result || (int) $result['available_points'] === 0) {
 *         $stmt = $this->db->prepare("SELECT total_points, available_points, used_points, points FROM users WHERE id = ?");
 *         $stmt->execute([$userId]);
 *         $userResult = $stmt->fetch(PDO::FETCH_ASSOC);
 *
 *         if ($userResult) {
 *             // Use 'points' column if available_points is 0 but points has value (same logic as points-history.php)
 *             if (empty($userResult['available_points']) && !empty($userResult['points'])) {
 *                 $userResult['available_points'] = $userResult['points'];
 *                 $userResult['total_points'] = $userResult['points'];
 *             }
 *
 *             if ((int) $userResult['available_points'] > 0) {
 *                 return $userResult;
 *             }
 *         }
 *     }
 *
 *     if (!$result) {
 *         return ['total_points' => 0, 'available_points' => 0, 'used_points' => 0];
 *     }
 *
 *     // Ensure available_points is not negative
 *     $result['available_points'] = max(0, (int) $result['available_points']);
 *
 *     return $result;
 * }
 * ```
 *
 * `points_transactions` (columns `user_id`/`points`, both confirmed present
 * in `packages/db/src/generated/tenant-db.d.ts`) and `users`
 * (`total_points`/`available_points`/`used_points`/`points`, all confirmed
 * present) — this is a fully literal, unmodified port, no schema-drift fix
 * needed.
 *
 * `error_log(...)` debug lines (PHP lines 64, 68, 72, 79) are dropped —
 * server-side debug logging with no TS equivalent established anywhere else
 * in this `api/inbox/actions/*` family (every sibling port drops
 * `logInboxApiException`/`error_log` calls the same way).
 *
 * RETURN SHAPE — two distinct branches, mirrored exactly:
 *   - The aggregate (`points_transactions`) branch ALWAYS returns exactly
 *     `{total_points, available_points, used_points}` — `available_points`
 *     clamped to `>= 0` via `Math.max(0, ...)`, `total_points`/`used_points`
 *     NOT clamped (mirrors PHP: only `available_points` gets the `max(0,
 *     ...)` treatment). `COALESCE(..., 0)` on every aggregate column means
 *     this query never returns a null-valued row for a user with zero
 *     `points_transactions` rows — SUM of an empty set still yields one row
 *     of zeros, not zero rows — so the `!$result` branch (`return
 *     ['total_points' => 0, 'available_points' => 0, 'used_points' => 0]`)
 *     is structurally unreachable through any real query result; it is kept
 *     here only for literal parity with the PHP source and can only be
 *     exercised in tests via a fake DB that deliberately returns an empty
 *     result set for this specific query.
 *   - The `users`-table EARLY-RETURN branch returns the raw row —
 *     `{total_points, available_points, used_points, points}` — WITH the
 *     extra `points` key the aggregate branch never has, and with NO
 *     `max(0, ...)` clamp applied (PHP's early `return $userResult;` at line
 *     83 happens before the clamp at line 93, which only runs on the
 *     fall-through path). `total_points`/`used_points` can therefore still
 *     be `null` here if the `users` row itself has `null` in those columns
 *     — passed through unchanged, exactly as PHP does (no casting on this
 *     path beyond the one `(int)` cast used for the `> 0` gate check
 *     itself, which does not mutate the stored value).
 */

interface PointsTransactionsAggregateRow {
  total_points: number;
  available_points: number;
  used_points: number;
}

interface UsersPointsRow {
  total_points: number | null;
  available_points: number | null;
  used_points: number | null;
  points: number | null;
}

export interface LoyaltyPointsResult {
  total_points: number | null;
  available_points: number;
  used_points: number | null;
  /** Only present on the `users`-table early-return branch — see module doc. */
  points?: number | null;
}

/** PHP's `empty($v)` for a nullable numeric column — true for `null`/`0` (the only falsy numeric values this column can hold). */
function phpEmptyNum(value: number | null | undefined): boolean {
  return value === null || value === undefined || value === 0;
}

export async function getUserPoints(db: Kysely<TenantDB>, userId: number): Promise<LoyaltyPointsResult> {
  const aggResult = await sql<PointsTransactionsAggregateRow>`
    SELECT
        COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as total_points,
        COALESCE(SUM(points), 0) as available_points,
        COALESCE(SUM(CASE WHEN points < 0 THEN ABS(points) ELSE 0 END), 0) as used_points
    FROM points_transactions
    WHERE user_id = ${userId}
  `.execute(db);
  // COALESCE(..., 0) means this row is never truly absent for a real query result — see module doc.
  const result = aggResult.rows[0];

  if (!result || Number(result.available_points) === 0) {
    const usersResult = await sql<UsersPointsRow>`
      SELECT total_points, available_points, used_points, points FROM users WHERE id = ${userId}
    `.execute(db);
    const userRow = usersResult.rows[0];

    if (userRow) {
      const row: UsersPointsRow = { ...userRow };
      // "Use 'points' column if available_points is 0 but points has value."
      if (phpEmptyNum(row.available_points) && !phpEmptyNum(row.points)) {
        row.available_points = row.points;
        row.total_points = row.points;
      }
      if (Number(row.available_points ?? 0) > 0) {
        return {
          total_points: row.total_points,
          available_points: Number(row.available_points),
          used_points: row.used_points,
          points: row.points,
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
