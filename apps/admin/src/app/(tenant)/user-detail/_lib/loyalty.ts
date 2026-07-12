import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * loyalty.ts — TypeScript port of classes/LoyaltyPoints.php +
 * classes/TierService.php, scoped to exactly what user-detail.php uses:
 * getUserPoints(), getPointsHistory(), getUserTier() (LoyaltyPoints's
 * wrapper, which just delegates to TierService), and the addPoints/
 * deductPoints + tier-recompute chain the `add_points` POST handler runs
 * (user-detail.php lines 49-74).
 *
 * Every write/read touching real columns goes through the raw `sql` escape
 * hatch, not Kysely's typed builder — see ../../users/queries.ts's
 * `getUsersListPage()` doc comment for why (no `CamelCasePlugin` configured
 * on the shared `Kysely<TenantDB>` instance @reya/db's `getTenantDb()`
 * returns).
 *
 * KNOWN QUIRK, replicated on purpose: `users.member_tier` (the column both
 * LoyaltyPoints::updateUserTier() and TierService::updateUserTier() write)
 * does not appear in the generated `TenantDB` schema at all — it is not
 * part of the committed tenant template (database/migration_2026-05-25_
 * tenant_template.sql), only ever added ad hoc on some production tenants
 * via legacy `ensureColumn`-style `isset($existingColumns['member_tier'])`
 * checks scattered across the PHP codebase (webhook.php, api/member.php,
 * scripts/backfill_followers_to_members.php, …). `updateUserTierColumn()`
 * below wraps its UPDATE in a try/catch that silently no-ops on failure,
 * mirroring TierService::updateUserTier()'s own try/catch exactly (its
 * comment literally says "member_tier column might not exist").
 *
 * SIMPLIFICATION, flagged: LoyaltyPoints::addPoints()/deductPoints() each
 * also call a private `updateUserTier($userId, $newBalance)` that writes
 * `users.member_tier` from the just-computed available-points balance —
 * but user-detail.php's `add_points` POST handler unconditionally calls
 * `TierService::updateUserTier($userId)` again immediately afterward, which
 * re-reads `users.total_points` fresh (already updated by that point) and
 * overwrites `member_tier` a second time. Since both writes target the same
 * row/column within the same request and the second always runs, the first
 * (available-points-based) write is never observable in the final DB state.
 * This port skips that transient first write and performs only the second,
 * final one via `recomputeAndPersistMemberTier()` — same end state, one
 * fewer UPDATE. See user-detail/actions.ts's `addPointsAction` for the
 * call site.
 */

// ---------------------------------------------------------------------------
// points_settings (LoyaltyPoints::loadSettings)
// ---------------------------------------------------------------------------

export interface PointsSettings {
  pointsPerBaht: number;
  minOrderForPoints: number;
  pointsExpiryDays: number;
  isActive: number;
}

const DEFAULT_POINTS_SETTINGS: PointsSettings = {
  pointsPerBaht: 0.001,
  minOrderForPoints: 0,
  pointsExpiryDays: 365,
  isActive: 1,
};

export async function loadPointsSettings(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<PointsSettings> {
  try {
    const result = await sql<PointsSettings>`
      SELECT points_per_baht AS pointsPerBaht, min_order_for_points AS minOrderForPoints,
             points_expiry_days AS pointsExpiryDays, is_active AS isActive
      FROM points_settings
      WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
      ORDER BY line_account_id DESC
      LIMIT 1
    `.execute(db);
    return result.rows[0] ?? DEFAULT_POINTS_SETTINGS;
  } catch {
    return DEFAULT_POINTS_SETTINGS;
  }
}

// ---------------------------------------------------------------------------
// getUserPoints (LoyaltyPoints::getUserPoints) — the fallback-then-fallthrough
// logic below is ported literally, quirks included (see the PHP source's own
// comments about the points_transactions-vs-users-table dual source).
// ---------------------------------------------------------------------------

export interface UserPoints {
  totalPoints: number;
  availablePoints: number;
  usedPoints: number;
}

export async function getUserPoints(db: Kysely<TenantDB>, userId: number): Promise<UserPoints> {
  const ptResult = await sql<{ totalPoints: number; availablePoints: number; usedPoints: number }>`
    SELECT
      COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) AS totalPoints,
      COALESCE(SUM(points), 0) AS availablePoints,
      COALESCE(SUM(CASE WHEN points < 0 THEN ABS(points) ELSE 0 END), 0) AS usedPoints
    FROM points_transactions
    WHERE user_id = ${userId}
  `.execute(db);
  const result = ptResult.rows[0] ?? null;

  if (!result || Number(result.availablePoints) === 0) {
    const userResult = await sql<{
      totalPoints: number | null;
      availablePoints: number | null;
      usedPoints: number | null;
      points: number | null;
    }>`
      SELECT total_points AS totalPoints, available_points AS availablePoints, used_points AS usedPoints, points
      FROM users WHERE id = ${userId}
    `.execute(db);
    const userRow = userResult.rows[0];
    if (userRow) {
      let availablePoints = userRow.availablePoints;
      let totalPoints = userRow.totalPoints;
      if (!availablePoints && userRow.points) {
        availablePoints = userRow.points;
        totalPoints = userRow.points;
      }
      if (Number(availablePoints ?? 0) > 0) {
        return {
          totalPoints: Number(totalPoints ?? 0),
          availablePoints: Number(availablePoints ?? 0),
          usedPoints: Number(userRow.usedPoints ?? 0),
        };
      }
    }
  }

  if (!result) {
    return { totalPoints: 0, availablePoints: 0, usedPoints: 0 };
  }

  return {
    totalPoints: Number(result.totalPoints),
    availablePoints: Math.max(0, Number(result.availablePoints)),
    usedPoints: Number(result.usedPoints),
  };
}

// ---------------------------------------------------------------------------
// getPointsHistory (LoyaltyPoints::getPointsHistory)
// ---------------------------------------------------------------------------

export interface PointsHistoryRow {
  id: number;
  type: 'adjust' | 'earn' | 'expire' | 'redeem' | 'refund';
  points: number;
  description: string | null;
  createdAt: Date;
}

export async function getPointsHistory(db: Kysely<TenantDB>, userId: number, limit: number): Promise<PointsHistoryRow[]> {
  const result = await sql<PointsHistoryRow>`
    SELECT id, type, points, description, created_at AS createdAt
    FROM points_transactions
    WHERE user_id = ${userId}
    ORDER BY created_at DESC
    LIMIT ${limit}
  `.execute(db);
  return result.rows;
}

// ---------------------------------------------------------------------------
// TierService port
// ---------------------------------------------------------------------------

export interface TierDef {
  tierCode: string;
  tierName: string;
  minPoints: number;
  color: string;
  icon: string;
  discountPercent: number;
}

const DEFAULT_TIERS: TierDef[] = [
  { tierCode: 'bronze', tierName: 'Bronze', minPoints: 0, color: '#CD7F32', icon: '🥉', discountPercent: 0 },
  { tierCode: 'silver', tierName: 'Silver', minPoints: 1000, color: '#C0C0C0', icon: '🥈', discountPercent: 3 },
  { tierCode: 'gold', tierName: 'Gold', minPoints: 5000, color: '#FFD700', icon: '🥇', discountPercent: 5 },
  { tierCode: 'platinum', tierName: 'Platinum', minPoints: 15000, color: '#6366F1', icon: '💎', discountPercent: 10 },
];

function iconForTierName(name: string): string {
  const n = name.toLowerCase();
  if (n.includes('bronze') || n.includes('member')) return '🥉';
  if (n.includes('silver')) return '🥈';
  if (n.includes('gold')) return '🥇';
  if (n.includes('platinum') || n.includes('diamond')) return '💎';
  if (n.includes('vip') || n.includes('royal')) return '👑';
  return '🏅';
}

interface RawTier {
  tierCode?: string | null;
  tierName?: string | null;
  minPoints?: number | string | null;
  color?: string | null;
  icon?: string | null;
  discountPercent?: number | string | null;
}

function normalizeTier(t: RawTier): TierDef {
  const tierCode = t.tierCode ?? (t.tierName ?? 'bronze').toLowerCase();
  const tierName = t.tierName ?? tierCode.charAt(0).toUpperCase() + tierCode.slice(1);
  return {
    tierCode,
    tierName,
    minPoints: Number(t.minPoints ?? 0),
    color: t.color ?? '#6B7280',
    icon: t.icon ?? '🏅',
    discountPercent: Number(t.discountPercent ?? 0),
  };
}

/**
 * Ported from TierService::getTiers(). Faithful to a subtle PHP control-flow
 * detail: the fallback to `member_tiers` only fires if the `tier_settings`
 * query THROWS — not merely if it returns zero rows (an empty-but-successful
 * result falls straight through to DEFAULT_TIERS). Since `tier_settings` is
 * part of the committed tenant template (always exists on a real tenant DB),
 * the `member_tiers` branch is effectively unreachable in normal operation —
 * same "dead in practice, kept for parity" shape as this batch's other two
 * flagged quirks.
 */
export async function getTiers(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<TierDef[]> {
  try {
    const result = await sql<{
      tierName: string;
      tierCode: string;
      minPoints: number;
      color: string | null;
      discountPercent: number | null;
    }>`
      SELECT name AS tierName, LOWER(REPLACE(name, ' ', '_')) AS tierCode, min_points AS minPoints,
             badge_color AS color, multiplier AS discountPercent
      FROM tier_settings
      WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
      ORDER BY min_points ASC
    `.execute(db);
    const tiers = result.rows.map((row) => normalizeTier({ ...row, icon: iconForTierName(row.tierName) }));
    return tiers.length > 0 ? tiers : DEFAULT_TIERS;
  } catch {
    try {
      const result = await sql<{
        tierCode: string;
        tierName: string;
        minPoints: number;
        color: string | null;
        icon: string | null;
        discountPercent: number | null;
      }>`
        SELECT tier_code AS tierCode, tier_name AS tierName, min_points AS minPoints, color, icon,
               discount_percent AS discountPercent
        FROM member_tiers
        WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL) AND is_active = 1
        ORDER BY min_points ASC
      `.execute(db);
      const tiers = result.rows.map(normalizeTier);
      return tiers.length > 0 ? tiers : DEFAULT_TIERS;
    } catch {
      return DEFAULT_TIERS;
    }
  }
}

export interface TierCalcResult {
  tierCode: string;
  tierName: string;
  /** Alias of tierName, mirrors TierService::calculateTier()'s `name` field (kept for backwards-compat callers). */
  name: string;
  color: string;
  icon: string;
  discountPercent: number;
  minPoints: number;
  currentPoints: number;
  pointsToNext: number;
  progressPercent: number;
  nextTierCode: string | null;
  nextTierName: string;
  nextTierPoints: number | null;
}

/** Ported from TierService::calculateTier(). */
export function calculateTier(tiers: TierDef[], points: number): TierCalcResult {
  let currentTier = tiers[0]!;
  let currentIndex = 0;
  tiers.forEach((tier, index) => {
    if (points >= tier.minPoints) {
      currentTier = tier;
      currentIndex = index;
    }
  });

  const nextTier = tiers[currentIndex + 1] ?? null;
  const pointsToNext = nextTier ? Math.max(0, nextTier.minPoints - points) : 0;

  let progress = 100;
  if (nextTier) {
    const range = nextTier.minPoints - currentTier.minPoints;
    if (range > 0) {
      progress = Math.min(100, Math.floor(((points - currentTier.minPoints) / range) * 100));
    }
  }

  return {
    tierCode: currentTier.tierCode,
    tierName: currentTier.tierName,
    name: currentTier.tierName,
    color: currentTier.color,
    icon: currentTier.icon,
    discountPercent: currentTier.discountPercent,
    minPoints: currentTier.minPoints,
    currentPoints: points,
    pointsToNext,
    progressPercent: progress,
    nextTierCode: nextTier?.tierCode ?? null,
    nextTierName: nextTier?.tierName ?? 'Max Level',
    nextTierPoints: nextTier?.minPoints ?? null,
  };
}

/** Ported from TierService::getUserTier() — points source is `total_points` falling back to `points`. */
async function getUserTierPoints(db: Kysely<TenantDB>, userId: number): Promise<number> {
  try {
    const result = await sql<{ points: number | null; totalPoints: number | null }>`
      SELECT points, total_points AS totalPoints FROM users WHERE id = ${userId}
    `.execute(db);
    const row = result.rows[0];
    if (!row) {
      return 0;
    }
    return Number(row.totalPoints ?? row.points ?? 0);
  } catch {
    return 0;
  }
}

/** Ported from LoyaltyPoints::getUserTier() (which just delegates to TierService::getUserTier()). Used both for display (member card) and for the tier-recompute step below. */
export async function getUserTier(db: Kysely<TenantDB>, userId: number, lineAccountId: number | null): Promise<TierCalcResult> {
  const points = await getUserTierPoints(db, userId);
  const tiers = await getTiers(db, lineAccountId);
  return calculateTier(tiers, points);
}

/** Ported from TierService::updateUserTier() — see this file's module doc for the `member_tier` drift-column quirk this try/catch exists for. */
export async function updateUserTierColumn(db: Kysely<TenantDB>, userId: number, tierCode: string): Promise<void> {
  try {
    await sql`UPDATE users SET member_tier = ${tierCode} WHERE id = ${userId}`.execute(db);
  } catch {
    // member_tier column might not exist on this tenant DB — best-effort, matches TierService.php.
  }
}

/** getUserTier() + updateUserTierColumn() composed — this is what user-detail.php's `add_points` handler calls unconditionally after every points adjustment. */
export async function recomputeAndPersistMemberTier(db: Kysely<TenantDB>, userId: number, lineAccountId: number | null): Promise<void> {
  const tier = await getUserTier(db, userId, lineAccountId);
  await updateUserTierColumn(db, userId, tier.tierCode);
}

// ---------------------------------------------------------------------------
// addPoints / deductPoints (LoyaltyPoints::addPoints / ::deductPoints)
// ---------------------------------------------------------------------------

function addDaysIso(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

/** Ported from LoyaltyPoints::addPoints() — see module doc for the skipped transient tier write. No-ops (matches PHP's `if ($points <= 0) return false;`) for points <= 0. */
export async function addPoints(
  db: Kysely<TenantDB>,
  userId: number,
  points: number,
  referenceType: string | null,
  referenceId: number | null,
  description: string | null,
  lineAccountId: number | null
): Promise<boolean> {
  if (points <= 0) {
    return false;
  }
  const current = await getUserPoints(db, userId);
  const newBalance = current.availablePoints + points;
  const settings = await loadPointsSettings(db, lineAccountId);
  const expiresAt = settings.pointsExpiryDays > 0 ? addDaysIso(settings.pointsExpiryDays) : null;

  await sql`
    UPDATE users SET total_points = total_points + ${points}, available_points = available_points + ${points}
    WHERE id = ${userId}
  `.execute(db);

  await sql`
    INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description, expires_at)
    VALUES (${userId}, ${lineAccountId}, 'earn', ${points}, ${newBalance}, ${referenceType}, ${referenceId}, ${description ?? `Earned ${points} points`}, ${expiresAt})
  `.execute(db);

  return true;
}

/** Ported from LoyaltyPoints::deductPoints() — returns false (no-op, no throw) when the user doesn't have enough available points, matching PHP exactly. */
export async function deductPoints(
  db: Kysely<TenantDB>,
  userId: number,
  points: number,
  referenceType: string | null,
  referenceId: number | null,
  description: string | null,
  lineAccountId: number | null
): Promise<boolean> {
  if (points <= 0) {
    return false;
  }
  const current = await getUserPoints(db, userId);
  if (current.availablePoints < points) {
    return false;
  }
  const newBalance = current.availablePoints - points;

  await sql`
    UPDATE users SET available_points = available_points - ${points}, used_points = used_points + ${points}
    WHERE id = ${userId}
  `.execute(db);

  await sql`
    INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description)
    VALUES (${userId}, ${lineAccountId}, 'redeem', ${-points}, ${newBalance}, ${referenceType}, ${referenceId}, ${description ?? `Used ${points} points`})
  `.execute(db);

  return true;
}
