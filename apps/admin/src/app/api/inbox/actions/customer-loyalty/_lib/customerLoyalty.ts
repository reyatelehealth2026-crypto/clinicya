import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * customerLoyalty.ts — literal, self-contained port of
 * `classes/DrugPricingEngineService.php`'s `getCustomerLoyalty()` +
 * `getUserTierInfo()` + `getPurchaseStats()` + `getAverageDiscount()` +
 * `calculateDiscountExpectation()` (lines 268-520), as called by
 * `api/inbox-v2.php`'s `case 'customer_loyalty': case 'customer-loyalty':`
 * (lines 843-868).
 *
 * ```php
 * public function getCustomerLoyalty(int $userId): array
 * {
 *     $tierInfo = $this->getUserTierInfo($userId);
 *     $purchaseStats = $this->getPurchaseStats($userId);
 *     $avgDiscount = $this->getAverageDiscount($userId);
 *
 *     return [
 *         'userId' => $userId,
 *         'loyaltyTier' => $tierInfo['tier'],
 *         'tierColor' => $tierInfo['color'],
 *         'totalPoints' => $tierInfo['points'],
 *         'pointsToNextTier' => $tierInfo['pointsToNext'],
 *         'nextTierName' => $tierInfo['nextTier'],
 *         'avgDiscount' => round($avgDiscount, 2),
 *         'avgDiscountPercent' => $purchaseStats['avgOrderValue'] > 0
 *             ? round(($avgDiscount / $purchaseStats['avgOrderValue']) * 100, 2)
 *             : 0.0,
 *         'totalPurchases' => round($purchaseStats['totalSpent'], 2),
 *         'orderCount' => $purchaseStats['orderCount'],
 *         'avgOrderValue' => round($purchaseStats['avgOrderValue'], 2),
 *         'lastPurchaseDate' => $purchaseStats['lastPurchase'],
 *         'discountExpectation' => $this->calculateDiscountExpectation($tierInfo['tier'], $avgDiscount)
 *     ];
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SCHEMA-DRIFT FIX (1) — `member_tiers.name`/`badge_color` do not exist
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `getUserTierInfo()` custom-tiers query:
 *
 * ```php
 * $stmt = $this->db->prepare("
 *     SELECT name, tier_name, min_points, badge_color as color
 *     FROM member_tiers
 *     WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1
 *     ORDER BY min_points ASC
 * ");
 * ```
 *
 * `name` and `badge_color` do NOT exist on `MemberTiers` in
 * `packages/db/src/generated/tenant-db.d.ts` — only `tier_name` and `color`
 * do. **Before**: this query ALWAYS throws `Unknown column` on the
 * committed schema, caught by PHP's own `catch (PDOException $e)`, which
 * falls through to the `points_tiers` fallback query — permanently skipping
 * ANY tenant's own configured `member_tiers` custom tiers, every single
 * call, with no way to reach them. **After**: the query below selects
 * `tier_name, min_points, color` (dropping the two nonexistent columns
 * entirely, not merely aliasing them), mapped to
 * `{name: row.tier_name, min_points: row.min_points, color: row.color ??
 * '#6B7280'}` — matching PHP's own row-mapping fallback logic
 * (`$t['tier_name'] ?? $t['name']`, `$t['color'] ?? '#6B7280'`) with the
 * dead `$t['name']` alternative removed (it can never be populated once the
 * nonexistent column is dropped from the SELECT). A tenant's real,
 * configured `member_tiers` rows are now actually reachable.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SCHEMA-DRIFT FIX (2) — `getAverageDiscount()`'s fallback `discount` column
 * does not exist (dropped entirely, not merely reproduced-and-caught)
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `getAverageDiscount()`:
 *
 * ```php
 * private function getAverageDiscount(int $userId): float
 * {
 *     try {
 *         $stmt = $this->db->prepare("
 *             SELECT COALESCE(AVG(discount_amount), 0) as avg_discount
 *             FROM transactions
 *             WHERE user_id = ? AND status NOT IN ('cancelled', 'pending', 'failed') AND discount_amount > 0
 *         ");
 *         $stmt->execute([$userId]);
 *         $result = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if ($result && $result['avg_discount'] > 0) { return (float)$result['avg_discount']; }
 *
 *         // Try with discount column
 *         $stmt = $this->db->prepare("
 *             SELECT COALESCE(AVG(discount), 0) as avg_discount
 *             FROM transactions
 *             WHERE user_id = ? AND status NOT IN ('cancelled', 'pending', 'failed') AND discount > 0
 *         ");
 *         $stmt->execute([$userId]);
 *         $result = $stmt->fetch(PDO::FETCH_ASSOC);
 *         return (float)($result['avg_discount'] ?? 0);
 *     } catch (PDOException $e) {
 *         return 0.0;
 *     }
 * }
 * ```
 *
 * The PRIMARY query (`discount_amount`) is fine as-is — that column is
 * confirmed present on `Transactions` (`discount_amount: Generated<Decimal
 * | null>`) — and is a fully literal, unmodified port. The FALLBACK query
 * selects a bare `discount` column that does not exist ANYWHERE on
 * `Transactions` in `tenant-db.d.ts` — it always throws, and that throw is
 * silently caught by the SAME outer `try`'s own `catch (PDOException $e) {
 * return 0.0; }`, always yielding `0.0` from that branch today. **Fix**:
 * the fallback query is DROPPED ENTIRELY — when the primary query's average
 * is `0`/absent, this port returns `0.0` directly, a NET-IDENTICAL
 * observable result to today's always-failing fallback, but with no query
 * left in this file that is guaranteed to fail against the real schema.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `getUserTierInfo()` (lines 300-355) — full literal port
 * ═══════════════════════════════════════════════════════════════════════
 * ```php
 * private function getUserTierInfo(int $userId): array
 * {
 *     $defaultTiers = [
 *         ['name' => 'Bronze', 'min_points' => 0, 'color' => '#CD7F32'],
 *         ['name' => 'Silver', 'min_points' => 1000, 'color' => '#C0C0C0'],
 *         ['name' => 'Gold', 'min_points' => 5000, 'color' => '#FFD700'],
 *         ['name' => 'Platinum', 'min_points' => 15000, 'color' => '#E5E4E2']
 *     ];
 *
 *     $points = 0;
 *     try {
 *         $stmt = $this->db->prepare("SELECT total_points, available_points, points FROM users WHERE id = ?");
 *         $stmt->execute([$userId]);
 *         $user = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if ($user) { $points = (int)($user['total_points'] ?? $user['points'] ?? 0); }
 *     } catch (PDOException $e) { /* Use default 0 points *\/ }
 *
 *     $tiers = $defaultTiers;
 *     try {
 *         // member_tiers query — see FIX (1) above
 *         if (!empty($customTiers)) {
 *             $tiers = array_map(fn($t) => [
 *                 'name' => $t['tier_name'] ?? $t['name'],
 *                 'min_points' => (int)$t['min_points'],
 *                 'color' => $t['color'] ?? '#6B7280'
 *             ], $customTiers);
 *         }
 *     } catch (PDOException $e) {
 *         try {
 *             $stmt = $this->db->prepare("
 *                 SELECT name, min_points, color FROM points_tiers
 *                 WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY min_points ASC
 *             ");
 *             $stmt->execute([$this->lineAccountId]);
 *             $customTiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *             if (!empty($customTiers)) { $tiers = $customTiers; }
 *         } catch (PDOException $e2) { /* Use default tiers *\/ }
 *     }
 *
 *     $currentTier = $tiers[0];
 *     $nextTier = null;
 *     foreach ($tiers as $i => $tier) {
 *         if ($points >= $tier['min_points']) {
 *             $currentTier = $tier;
 *             $nextTier = $tiers[$i + 1] ?? null;
 *         }
 *     }
 *
 *     return [
 *         'tier' => $currentTier['name'], 'color' => $currentTier['color'] ?? '#6B7280',
 *         'points' => $points,
 *         'pointsToNext' => $nextTier ? max(0, $nextTier['min_points'] - $points) : 0,
 *         'nextTier' => $nextTier ? $nextTier['name'] : null
 *     ];
 * }
 * ```
 *
 * `points_tiers` (columns `name`/`min_points`/`color`) are ALL confirmed
 * present on `PointsTiers` in `tenant-db.d.ts` — this fallback branch is a
 * fully literal, unmodified port, no fix needed.
 *
 * `foreach` + reassignment (not an early `break`) means the LAST tier whose
 * `min_points` the user's `points` meets or exceeds wins — ported via a
 * plain `for` loop that keeps overwriting `currentTier`/`nextTier`, not a
 * `find()`/`break`, to preserve this exact "last match wins" semantics
 * (matters only if `tiers` is unsorted; both `member_tiers` and
 * `points_tiers` queries `ORDER BY min_points ASC`, and `defaultTiers` is
 * already ascending, so in practice this is equivalent to "first tier from
 * the top the user qualifies for" — ported literally regardless).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `getPurchaseStats()` (lines 372-420) — full literal port, no fix needed
 * ═══════════════════════════════════════════════════════════════════════
 * `transactions` (`grand_total`/`created_at`/`user_id`/`status`) and
 * `orders` (same 4 columns) are both confirmed present in
 * `tenant-db.d.ts` — ported both branches (primary `transactions` query,
 * `orders`-table fallback on throw) literally, verbatim WHERE clauses.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `calculateDiscountExpectation()` (lines 495-520) — exact Thai strings kept
 * ═══════════════════════════════════════════════════════════════════════
 * ```php
 * private function calculateDiscountExpectation(string $tier, float $avgDiscount): array
 * {
 *     $tierExpectations = [
 *         'Bronze' => ['min' => 0, 'max' => 5, 'typical' => 0],
 *         'Silver' => ['min' => 3, 'max' => 8, 'typical' => 5],
 *         'Gold' => ['min' => 5, 'max' => 12, 'typical' => 8],
 *         'Platinum' => ['min' => 8, 'max' => 15, 'typical' => 10]
 *     ];
 *     $expectation = $tierExpectations[$tier] ?? $tierExpectations['Bronze'];
 *     if ($avgDiscount > 0) {
 *         $expectation['historical'] = round($avgDiscount, 2);
 *         $expectation['recommendation'] = "ลูกค้าเคยได้รับส่วนลดเฉลี่ย ฿" . number_format($avgDiscount, 2);
 *     } else {
 *         $expectation['historical'] = 0;
 *         $expectation['recommendation'] = "ลูกค้าใหม่ แนะนำส่วนลด {$expectation['typical']}%";
 *     }
 *     return $expectation;
 * }
 * ```
 * A `$tier` not present in `$tierExpectations` (i.e. any custom
 * `member_tiers`/`points_tiers` tier name outside the 4 hardcoded ones)
 * falls back to the `'Bronze'` expectation entry — ported via `??`.
 */

// ─────────────────────────────────────────────────────────────────────────
// getUserTierInfo()
// ─────────────────────────────────────────────────────────────────────────

interface TierEntry {
  name: string;
  min_points: number;
  color: string | null;
}

const DEFAULT_TIERS: TierEntry[] = [
  { name: 'Bronze', min_points: 0, color: '#CD7F32' },
  { name: 'Silver', min_points: 1000, color: '#C0C0C0' },
  { name: 'Gold', min_points: 5000, color: '#FFD700' },
  { name: 'Platinum', min_points: 15000, color: '#E5E4E2' },
];

interface UserPointsRow {
  total_points: number | null;
  available_points: number | null;
  points: number | null;
}

/** FIX (1) applied: `tier_name, min_points, color` — `name`/`badge_color` dropped (do not exist on `MemberTiers`). See module doc. */
interface MemberTierRow {
  tier_name: string | null;
  min_points: number;
  color: string | null;
}

interface PointsTierRow {
  name: string;
  min_points: number;
  color: string | null;
}

export interface TierInfo {
  tier: string;
  color: string;
  points: number;
  pointsToNext: number;
  nextTier: string | null;
}

async function getUserTierInfo(db: Kysely<TenantDB>, lineAccountId: number, userId: number): Promise<TierInfo> {
  let points = 0;
  try {
    const result = await sql<UserPointsRow>`
      SELECT total_points, available_points, points FROM users WHERE id = ${userId}
    `.execute(db);
    const user = result.rows[0];
    if (user) {
      points = Number(user.total_points ?? user.points ?? 0);
    }
  } catch {
    // Use default 0 points.
  }

  let tiers: TierEntry[] = DEFAULT_TIERS;
  try {
    // FIX (1): `tier_name, min_points, color` — `name`/`badge_color` dropped, see module doc.
    const result = await sql<MemberTierRow>`
      SELECT tier_name, min_points, color
      FROM member_tiers
      WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL) AND is_active = 1
      ORDER BY min_points ASC
    `.execute(db);
    const customTiers = result.rows;

    if (customTiers.length > 0) {
      tiers = customTiers.map((t) => ({
        name: t.tier_name ?? '',
        min_points: Number(t.min_points),
        color: t.color ?? '#6B7280',
      }));
    }
  } catch {
    try {
      const result = await sql<PointsTierRow>`
        SELECT name, min_points, color FROM points_tiers
        WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL ORDER BY min_points ASC
      `.execute(db);
      const customTiers = result.rows;
      if (customTiers.length > 0) {
        tiers = customTiers.map((t) => ({ name: t.name, min_points: Number(t.min_points), color: t.color }));
      }
    } catch {
      // Use default tiers.
    }
  }

  let currentTier: TierEntry = tiers[0] as TierEntry;
  let nextTier: TierEntry | null = null;
  for (let i = 0; i < tiers.length; i++) {
    const tier = tiers[i] as TierEntry;
    if (points >= tier.min_points) {
      currentTier = tier;
      nextTier = tiers[i + 1] ?? null;
    }
  }

  return {
    tier: currentTier.name,
    color: currentTier.color ?? '#6B7280',
    points,
    pointsToNext: nextTier ? Math.max(0, nextTier.min_points - points) : 0,
    nextTier: nextTier ? nextTier.name : null,
  };
}

// ─────────────────────────────────────────────────────────────────────────
// getPurchaseStats()
// ─────────────────────────────────────────────────────────────────────────

export interface PurchaseStats {
  totalSpent: number;
  orderCount: number;
  avgOrderValue: number;
  lastPurchase: Date | null;
}

const DEFAULT_PURCHASE_STATS: PurchaseStats = {
  totalSpent: 0.0,
  orderCount: 0,
  avgOrderValue: 0.0,
  lastPurchase: null,
};

interface PurchaseStatsRow {
  order_count: number;
  total_spent: string | number;
  avg_order: string | number;
  last_purchase: Date | null;
}

async function getPurchaseStats(db: Kysely<TenantDB>, userId: number): Promise<PurchaseStats> {
  try {
    const result = await sql<PurchaseStatsRow>`
      SELECT
          COUNT(*) as order_count,
          COALESCE(SUM(grand_total), 0) as total_spent,
          COALESCE(AVG(grand_total), 0) as avg_order,
          MAX(created_at) as last_purchase
      FROM transactions
      WHERE user_id = ${userId} AND status NOT IN ('cancelled', 'pending', 'failed')
    `.execute(db);
    const row = result.rows[0];
    if (row) {
      return {
        totalSpent: Number(row.total_spent),
        orderCount: Number(row.order_count),
        avgOrderValue: Number(row.avg_order),
        lastPurchase: row.last_purchase,
      };
    }
    return DEFAULT_PURCHASE_STATS;
  } catch {
    try {
      const result = await sql<PurchaseStatsRow>`
        SELECT
            COUNT(*) as order_count,
            COALESCE(SUM(grand_total), 0) as total_spent,
            COALESCE(AVG(grand_total), 0) as avg_order,
            MAX(created_at) as last_purchase
        FROM orders
        WHERE user_id = ${userId} AND status IN ('paid', 'confirmed', 'delivered', 'completed')
      `.execute(db);
      const row = result.rows[0];
      if (row) {
        return {
          totalSpent: Number(row.total_spent),
          orderCount: Number(row.order_count),
          avgOrderValue: Number(row.avg_order),
          lastPurchase: row.last_purchase,
        };
      }
      return DEFAULT_PURCHASE_STATS;
    } catch {
      return DEFAULT_PURCHASE_STATS;
    }
  }
}

// ─────────────────────────────────────────────────────────────────────────
// getAverageDiscount() — FIX (2) applied: no-op `discount`-column fallback dropped
// ─────────────────────────────────────────────────────────────────────────

interface AvgDiscountRow {
  avg_discount: string | number;
}

async function getAverageDiscount(db: Kysely<TenantDB>, userId: number): Promise<number> {
  try {
    const result = await sql<AvgDiscountRow>`
      SELECT COALESCE(AVG(discount_amount), 0) as avg_discount
      FROM transactions
      WHERE user_id = ${userId}
      AND status NOT IN ('cancelled', 'pending', 'failed')
      AND discount_amount > 0
    `.execute(db);
    const row = result.rows[0];
    if (row && Number(row.avg_discount) > 0) {
      return Number(row.avg_discount);
    }
    // FIX (2): the no-op `discount`-column fallback query is dropped here —
    // that column does not exist anywhere on `Transactions`; it always threw
    // and was always caught by the outer catch below, always yielding 0.0.
    // See module doc.
    return 0.0;
  } catch {
    return 0.0;
  }
}

// ─────────────────────────────────────────────────────────────────────────
// calculateDiscountExpectation()
// ─────────────────────────────────────────────────────────────────────────

export interface DiscountExpectation {
  min: number;
  max: number;
  typical: number;
  historical: number;
  recommendation: string;
}

interface TierExpectationBase {
  min: number;
  max: number;
  typical: number;
}

const TIER_EXPECTATIONS: Record<string, TierExpectationBase> = {
  Bronze: { min: 0, max: 5, typical: 0 },
  Silver: { min: 3, max: 8, typical: 5 },
  Gold: { min: 5, max: 12, typical: 8 },
  Platinum: { min: 8, max: 15, typical: 10 },
};

/** PHP's `round($x, 2)`. */
function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

/** PHP's `number_format($x, 2)` — thousands-separated, 2 decimal places. */
function numberFormat2(value: number): string {
  return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function calculateDiscountExpectation(tier: string, avgDiscount: number): DiscountExpectation {
  const base = TIER_EXPECTATIONS[tier] ?? (TIER_EXPECTATIONS.Bronze as TierExpectationBase);

  if (avgDiscount > 0) {
    return {
      ...base,
      historical: round2(avgDiscount),
      recommendation: `ลูกค้าเคยได้รับส่วนลดเฉลี่ย ฿${numberFormat2(avgDiscount)}`,
    };
  }

  return {
    ...base,
    historical: 0,
    recommendation: `ลูกค้าใหม่ แนะนำส่วนลด ${base.typical}%`,
  };
}

// ─────────────────────────────────────────────────────────────────────────
// getCustomerLoyalty()
// ─────────────────────────────────────────────────────────────────────────

export interface CustomerLoyalty {
  userId: number;
  loyaltyTier: string;
  tierColor: string;
  totalPoints: number;
  pointsToNextTier: number;
  nextTierName: string | null;
  avgDiscount: number;
  avgDiscountPercent: number;
  totalPurchases: number;
  orderCount: number;
  avgOrderValue: number;
  lastPurchaseDate: Date | null;
  discountExpectation: DiscountExpectation;
}

export async function getCustomerLoyalty(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  userId: number
): Promise<CustomerLoyalty> {
  const tierInfo = await getUserTierInfo(db, lineAccountId, userId);
  const purchaseStats = await getPurchaseStats(db, userId);
  const avgDiscount = await getAverageDiscount(db, userId);

  return {
    userId,
    loyaltyTier: tierInfo.tier,
    tierColor: tierInfo.color,
    totalPoints: tierInfo.points,
    pointsToNextTier: tierInfo.pointsToNext,
    nextTierName: tierInfo.nextTier,
    avgDiscount: round2(avgDiscount),
    avgDiscountPercent: purchaseStats.avgOrderValue > 0 ? round2((avgDiscount / purchaseStats.avgOrderValue) * 100) : 0.0,
    totalPurchases: round2(purchaseStats.totalSpent),
    orderCount: purchaseStats.orderCount,
    avgOrderValue: round2(purchaseStats.avgOrderValue),
    lastPurchaseDate: purchaseStats.lastPurchase,
    discountExpectation: calculateDiscountExpectation(tierInfo.tier, avgDiscount),
  };
}
