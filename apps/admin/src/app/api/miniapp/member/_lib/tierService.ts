import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * tierService.ts — port of classes/TierService.php (getTiers()/calculateTier()) for the `check` and
 * `get_card` actions of api/member.php. Read classes/TierService.php in full before editing this file.
 *
 * DELIBERATE DEVIATION (flagged, not a silent behavior change): TierService.php keeps a PHP
 * `static $tierCache` keyed only by `lineAccountId`. In classic PHP-FPM/mod_php that cache is, in
 * practice, request-scoped (a fresh PHP process/request never sees another request's static state in
 * this hosting model) — i.e. every request already recomputes tiers from the DB, so PHP's own observed
 * behavior is "no cross-request caching" despite the static declaration. This Node port is a single
 * long-running process serving MANY tenants through the pooled Kysely registry, so keying a
 * process-lifetime cache by `lineAccountId` alone (a value that repeats across different tenant DBs,
 * e.g. every tenant's default account is often id=1) would risk serving tier data from the WRONG
 * tenant's DB. Skipping the cache reproduces PHP's actual per-request-fresh behavior exactly — it does
 * not change any single response's field values, only removes an intra-request memoization that would
 * be unsafe to keep here.
 */

export interface TierRow {
  tier_code: string;
  tier_name: string;
  min_points: number;
  color: string;
  icon: string;
  discount_percent: number;
}

export interface TierInfo {
  tier_code: string;
  tier_name: string;
  name: string;
  color: string;
  icon: string;
  discount_percent: number;
  min_points: number;
  current_tier_points: number;
  current_points: number;
  points_to_next: number;
  progress_percent: number;
  next_tier_code: string | null;
  next_tier_name: string;
  next_tier_points: number | null;
}

const DEFAULT_TIERS: TierRow[] = [
  { tier_code: 'bronze', tier_name: 'Bronze', min_points: 0, color: '#CD7F32', icon: '🥉', discount_percent: 0 },
  { tier_code: 'silver', tier_name: 'Silver', min_points: 1000, color: '#C0C0C0', icon: '🥈', discount_percent: 3 },
  { tier_code: 'gold', tier_name: 'Gold', min_points: 5000, color: '#FFD700', icon: '🥇', discount_percent: 5 },
  { tier_code: 'platinum', tier_name: 'Platinum', min_points: 15000, color: '#6366F1', icon: '💎', discount_percent: 10 },
];

function iconForTierName(tierName: string): string {
  const name = tierName.toLowerCase();
  if (name.includes('bronze') || name.includes('member')) return '🥉';
  if (name.includes('silver')) return '🥈';
  if (name.includes('gold')) return '🥇';
  if (name.includes('platinum') || name.includes('diamond')) return '💎';
  if (name.includes('vip') || name.includes('royal')) return '👑';
  return '🏅';
}

/**
 * Port of TierService::getTiers(). Mirrors PHP's exact try/catch NESTING, not just its two data
 * sources — this matters because member_tiers is a fallback for a THROWING tier_settings query only
 * (e.g. the table doesn't exist), NOT for a tier_settings query that succeeds but matches zero rows
 * (a tenant that never configured custom tiers). In that success-but-empty case PHP's `$tiers = []`
 * from `fetchAll()` skips the member_tiers attempt entirely and falls straight through to the shared
 * `if (empty($tiers)) $tiers = DEFAULT_TIERS;` at the end — reproduced here via the `tiers`/`querySucceeded`
 * bookkeeping below rather than an early `return` from inside the first try block (an earlier version of
 * this port had exactly that bug: an early return on empty-but-successful tier_settings rows skipped
 * DEFAULT_TIERS just the same as it wrongly skipped member_tiers).
 */
async function getTiers(db: Kysely<TenantDB>, lineAccountId: number): Promise<TierRow[]> {
  let tiers: TierRow[] = [];
  let tierSettingsQuerySucceeded = false;

  try {
    const result = await sql<{
      tier_name: string;
      tier_code: string;
      min_points: number | null;
      color: string | null;
      discount_percent: number | string | null;
    }>`
      SELECT name as tier_name, LOWER(REPLACE(name, ' ', '_')) as tier_code,
             min_points, badge_color as color, multiplier as discount_percent
      FROM tier_settings
      WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
      ORDER BY min_points ASC
    `.execute(db);

    tiers = result.rows.map((row) => ({
      tier_code: row.tier_code ?? row.tier_name?.toLowerCase() ?? 'bronze',
      tier_name: row.tier_name,
      min_points: Number(row.min_points ?? 0),
      color: row.color ?? '#6B7280',
      icon: iconForTierName(row.tier_name ?? ''),
      discount_percent: Number(row.discount_percent ?? 0),
    }));
    tierSettingsQuerySucceeded = true;
  } catch {
    // tier_settings may not exist on this tenant DB — fall back to member_tiers (only in this branch).
  }

  if (!tierSettingsQuerySucceeded) {
    try {
      const result = await sql<{
        tier_code: string;
        tier_name: string;
        min_points: number | null;
        color: string | null;
        icon: string | null;
        discount_percent: number | string | null;
      }>`
        SELECT tier_code, tier_name, min_points, color, icon, discount_percent
        FROM member_tiers
        WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
          AND is_active = 1
        ORDER BY min_points ASC
      `.execute(db);

      tiers = result.rows.map((row) => ({
        tier_code: row.tier_code ?? row.tier_name?.toLowerCase() ?? 'bronze',
        tier_name: row.tier_name ?? 'Bronze',
        min_points: Number(row.min_points ?? 0),
        color: row.color ?? '#6B7280',
        icon: row.icon ?? '🏅',
        discount_percent: Number(row.discount_percent ?? 0),
      }));
    } catch {
      // member_tiers may not exist either — `tiers` stays [], DEFAULT_TIERS below applies.
    }
  }

  return tiers.length > 0 ? tiers : DEFAULT_TIERS;
}

/** Port of TierService::calculateTier(). */
export async function calculateTier(db: Kysely<TenantDB>, lineAccountId: number, points: number): Promise<TierInfo> {
  const tiers = await getTiers(db, lineAccountId);
  const firstTier = tiers[0] ?? DEFAULT_TIERS[0]!;

  let currentTier: TierRow = firstTier;
  let currentIndex = 0;
  tiers.forEach((tier, index) => {
    if (points >= tier.min_points) {
      currentTier = tier;
      currentIndex = index;
    }
  });

  const nextTier: TierRow | undefined = tiers[currentIndex + 1];
  const pointsToNext = nextTier ? Math.max(0, nextTier.min_points - points) : 0;

  let progress = 100;
  if (nextTier) {
    const rangeStart = currentTier.min_points;
    const rangeEnd = nextTier.min_points;
    const range = rangeEnd - rangeStart;
    if (range > 0) {
      progress = Math.min(100, Math.floor(((points - rangeStart) / range) * 100));
    }
  }

  return {
    tier_code: currentTier.tier_code,
    tier_name: currentTier.tier_name,
    name: currentTier.tier_name,
    color: currentTier.color,
    icon: currentTier.icon,
    discount_percent: currentTier.discount_percent,
    min_points: currentTier.min_points,
    current_tier_points: currentTier.min_points,
    current_points: points,
    points_to_next: pointsToNext,
    progress_percent: progress,
    next_tier_code: nextTier?.tier_code ?? null,
    next_tier_name: nextTier?.tier_name ?? 'Max Level',
    next_tier_points: nextTier?.min_points ?? null,
  };
}
