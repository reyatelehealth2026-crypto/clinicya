import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * loyalty.ts — local TypeScript port of classes/LoyaltyPoints.php, scoped to
 * exactly what the give_by_phone mutation (see ../actions.ts) needs:
 * loadSettings()/calculatePoints(), getUserPoints(), and addPoints()
 * (including its private updateUserTier($userId, $points) call — see below).
 *
 * Deliberately duplicated rather than cross-imported from
 * apps/admin/src/app/(tenant)/user-detail/_lib/loyalty.ts, which already
 * ports an overlapping subset (getUserPoints/addPoints/getTiers/
 * calculateTier) for a DIFFERENT call site (user-detail.php's `add_points`
 * POST handler). Two reasons this file does NOT just import that one:
 *   1. This batch's allowed paths are apps/admin/src/app/(tenant)/{analytics,
 *      activity-logs,loyalty-members}/** only — user-detail/_lib is out of
 *      scope to depend on for anything beyond what its OWN batch already
 *      established as a cross-import (requireTenantPageContext, which this
 *      page also reuses the same way).
 *   2. The two call sites' addPoints() flow genuinely differs in tier-update
 *      shape: user-detail.php's handler calls TierService::updateUserTier()
 *      itself, separately, right after LoyaltyPoints::addPoints() returns —
 *      so that port's addPoints() intentionally SKIPS the transient write
 *      LoyaltyPoints::addPoints()'s own private updateUserTier($userId,
 *      $newBalance) performs (see that file's module doc for the full
 *      reasoning). points-claim.php's handleGiveByPhone() -> pcCreditCounterSale()
 *      -> LoyaltyPoints::addPoints() has NO such follow-up call — the
 *      private updateUserTier($userId, $newBalance) write inside addPoints()
 *      IS the only tier write for this flow, so it must NOT be skipped here.
 *      Same underlying TierService port, different call shape — reproducing
 *      class-private-method-with-recompute-from-caller-provided-balance
 *      distinctly per call site, matching classes/LoyaltyPoints.php +
 *      classes/TierService.php exactly.
 */

// ---------------------------------------------------------------------------
// points_settings (LoyaltyPoints::loadSettings / calculatePoints)
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

/** Ported from LoyaltyPoints::calculatePoints($amount). */
export function calculatePoints(settings: PointsSettings, amount: number): number {
  if (!settings.isActive) return 0;
  if (amount < settings.minOrderForPoints) return 0;
  return Math.floor(amount * settings.pointsPerBaht);
}

// ---------------------------------------------------------------------------
// getUserPoints (LoyaltyPoints::getUserPoints) — same fallback-then-fallthrough
// shape as user-detail/_lib/loyalty.ts's port; see that file for the quirk notes.
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
// Tier calc (classes/TierService.php subset — getTiers/calculateTier)
// ---------------------------------------------------------------------------

interface TierDef {
  tierCode: string;
  minPoints: number;
}

const DEFAULT_TIERS: TierDef[] = [
  { tierCode: 'bronze', minPoints: 0 },
  { tierCode: 'silver', minPoints: 1000 },
  { tierCode: 'gold', minPoints: 5000 },
  { tierCode: 'platinum', minPoints: 15000 },
];

async function getTierCodes(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<TierDef[]> {
  try {
    const result = await sql<{ tierName: string; minPoints: number }>`
      SELECT name AS tierName, min_points AS minPoints
      FROM tier_settings
      WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
      ORDER BY min_points ASC
    `.execute(db);
    const tiers = result.rows.map((r) => ({ tierCode: r.tierName.toLowerCase().replace(/ /g, '_'), minPoints: Number(r.minPoints) }));
    return tiers.length > 0 ? tiers : DEFAULT_TIERS;
  } catch {
    return DEFAULT_TIERS;
  }
}

/** Ported from TierService::calculateTier($points) — the tier-code half only (this call site only needs `tier_code`, mirrors LoyaltyPoints::updateUserTier()'s own usage). */
function calculateTierCode(tiers: TierDef[], points: number): string {
  let current = tiers[0]!;
  for (const tier of tiers) {
    if (points >= tier.minPoints) current = tier;
  }
  return current.tierCode;
}

/**
 * Ported from LoyaltyPoints::updateUserTier($userId, $points) (private
 * method, called from within addPoints()/deductPoints()): computes the tier
 * for the just-updated balance and best-effort writes `users.member_tier`.
 * Wrapped in try/catch matching the PHP source's own
 * `catch (Exception $e) { error_log(...) }` — `member_tier` is not part of
 * the committed tenant template (see user-detail/_lib/loyalty.ts's module
 * doc for the same quirk), so this silently no-ops on a tenant DB without
 * that column, exactly like the PHP source does.
 */
async function updateUserTier(db: Kysely<TenantDB>, userId: number, points: number, lineAccountId: number | null): Promise<void> {
  try {
    const tiers = await getTierCodes(db, lineAccountId);
    const tierCode = calculateTierCode(tiers, points);
    await sql`UPDATE users SET member_tier = ${tierCode} WHERE id = ${userId}`.execute(db);
  } catch {
    // member_tier column might not exist on this tenant DB — best-effort, matches LoyaltyPoints.php.
  }
}

// ---------------------------------------------------------------------------
// addPoints (LoyaltyPoints::addPoints)
// ---------------------------------------------------------------------------

function addDaysIso(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

export interface AddPointsResult {
  ok: boolean;
  newBalance: number;
}

/**
 * Ported from LoyaltyPoints::addPoints($userId, $points, $referenceType,
 * $referenceId, $description) — including its private updateUserTier() call
 * (see this file's module doc for why THIS port keeps that call, unlike
 * user-detail/_lib/loyalty.ts's addPoints()).
 */
export async function addPoints(
  db: Kysely<TenantDB>,
  userId: number,
  points: number,
  referenceType: string | null,
  referenceId: number | null,
  description: string | null,
  lineAccountId: number | null
): Promise<AddPointsResult> {
  if (points <= 0) {
    return { ok: false, newBalance: 0 };
  }
  const current = await getUserPoints(db, userId);
  const newBalance = current.availablePoints + points;
  const settings = await loadPointsSettings(db, lineAccountId);
  const expiresAt = settings.pointsExpiryDays > 0 ? addDaysIso(settings.pointsExpiryDays) : null;

  await sql`
    UPDATE users SET total_points = total_points + ${points}, available_points = available_points + ${points}
    WHERE id = ${userId}
  `.execute(db);

  await updateUserTier(db, userId, newBalance, lineAccountId);

  await sql`
    INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description, expires_at)
    VALUES (${userId}, ${lineAccountId}, 'earn', ${points}, ${newBalance}, ${referenceType}, ${referenceId}, ${description ?? `Earned ${points} points`}, ${expiresAt})
  `.execute(db);

  return { ok: true, newBalance };
}
