import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * drugPricingEngine.ts — literal port of `classes/DrugPricingEngineService.php`'s
 * `calculateMargin()` (lines 45-107) and `getMaxDiscount()` (lines 119-174).
 *
 * ```php
 * public function calculateMargin(int $drugId, ?float $customPrice = null): array
 * {
 *     try {
 *         $stmt = $this->db->prepare("SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id = ?");
 *         $stmt->execute([$drugId]);
 *         $drug = $stmt->fetch(PDO::FETCH_ASSOC);
 *     } catch (PDOException $e) {
 *         $stmt = $this->db->prepare("SELECT id, name, price, sale_price FROM business_items WHERE id = ?");
 *         $stmt->execute([$drugId]);
 *         $drug = $stmt->fetch(PDO::FETCH_ASSOC);
 *     }
 *
 *     if (!$drug) {
 *         return ['cost' => 0.0, 'price' => 0.0, 'margin' => 0.0, 'marginPercent' => 0.0, 'error' => 'Drug not found'];
 *     }
 *
 *     $cost = (float)($drug['cost_price'] ?? 0);
 *     if ($cost <= 0) {
 *         $basePrice = (float)($drug['sale_price'] ?? $drug['price'] ?? 0);
 *         $cost = $basePrice * 0.7;
 *     }
 *
 *     $price = $customPrice !== null ? $customPrice : (float)($drug['sale_price'] ?? $drug['price'] ?? 0);
 *     $margin = $price - $cost;
 *     $marginPercent = $price > 0 ? (($price - $cost) / $price) * 100 : 0.0;
 *
 *     return ['drugId' => $drugId, 'drugName' => $drug['name'], 'cost' => round($cost, 2), 'price' => round($price, 2), 'margin' => round($margin, 2), 'marginPercent' => round($marginPercent, 2)];
 * }
 *
 * public function getMaxDiscount(int $drugId, float $minMarginPercent = self::DEFAULT_MIN_MARGIN): array
 * {
 *     $pricing = $this->calculateMargin($drugId);
 *     if (isset($pricing['error'])) {
 *         return ['maxDiscount' => 0.0, 'maxDiscountPercent' => 0.0, 'floorPrice' => 0.0, 'error' => $pricing['error']];
 *     }
 *     $cost = $pricing['cost'];
 *     $price = $pricing['price'];
 *     $minMarginDecimal = $minMarginPercent / 100;
 *     if ($minMarginDecimal >= 1) {
 *         return ['maxDiscount' => 0.0, 'maxDiscountPercent' => 0.0, 'floorPrice' => $price, 'currentPrice' => $price, 'cost' => $cost, 'minMarginPercent' => $minMarginPercent, 'error' => 'Invalid minimum margin percentage'];
 *     }
 *     $floorPrice = $cost / (1 - $minMarginDecimal);
 *     $maxDiscount = max(0, $price - $floorPrice);
 *     $maxDiscountPercent = $price > 0 ? ($maxDiscount / $price) * 100 : 0.0;
 *     return ['drugId' => $drugId, 'maxDiscount' => round($maxDiscount, 2), 'maxDiscountPercent' => round($maxDiscountPercent, 2), 'floorPrice' => round($floorPrice, 2), 'currentPrice' => round($price, 2), 'cost' => round($cost, 2), 'minMarginPercent' => $minMarginPercent];
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SIMPLIFICATION — the `cost_price`-column-existence PDOException fallback
 * is dropped (unreachable on the committed schema)
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `calculateMargin()` wraps its own `SELECT ... cost_price ...` in a
 * try/catch and re-queries without `cost_price` if the column doesn't
 * exist. Per this batch's confirmed schema finding (also noted on
 * drug-pricing/_lib/drugPricing.ts and drug-info's own module doc),
 * `business_items.cost_price` is a real column in both the committed
 * tenant template (`packages/db/src/generated/tenant-db.d.ts`'s
 * `BusinessItems.cost_price: Generated<Decimal | null>`) and
 * `database/install_complete_latest.sql` — the fallback branch is
 * unreachable here and is not ported. `cost_price` is always selected.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * PRICING-ENGINE-SERVICE-UNAVAILABLE 503 — structurally unreachable in Next
 * ═══════════════════════════════════════════════════════════════════════
 * `api/inbox-v2.php`'s `case 'max_discount':` guards the call with
 * `$pricingEngine = loadService('DrugPricingEngineService', ...); if
 * (!$pricingEngine) { sendError('Pricing engine service not available',
 * 503); }` — PHP's `loadService()` does a runtime `file_exists()` +
 * `class_exists()` check because the class file may not be deployed on a
 * given box. There is no equivalent failure mode in this Next port: this
 * module is a static TypeScript import — either it compiles and is present
 * in the built bundle, or the build fails outright. `max-discount/route.ts`
 * therefore does NOT fabricate a runtime 503 branch for "service
 * unavailable" — that PHP state has no Next analogue. Same decision is
 * documented on `drug-pricing-data/_lib/drugPricingData.ts` for
 * `PharmacyIntegrationService`'s identical `loadService()` guard.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CROSS-ROUTE IMPORT — deliberate, single-owner exception
 * ═══════════════════════════════════════════════════════════════════════
 * `drug-info/route.ts` imports `calculateMargin` directly from this module
 * (`../max-discount/_lib/drugPricingEngine`) rather than duplicating the
 * calculation a second time. This is the one deliberate cross-route import
 * in this batch — both `drug-info/**` and `max-discount/**` belong to the
 * same builder stream this round (see this batch's brief), so there is a
 * single owner for `calculateMargin`'s logic. No other sibling action
 * family imports from here.
 */

/** PHP `round($x, 2)` — for the non-negative magnitudes this module ever produces, `Math.round` half-up is equivalent. */
function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

interface PricingRow {
  id: number;
  name: string;
  price: unknown;
  sale_price: unknown;
  cost_price: unknown;
}

export type MarginResult =
  | {
      cost: number;
      price: number;
      margin: number;
      marginPercent: number;
      error: string;
    }
  | {
      drugId: number;
      drugName: string;
      cost: number;
      price: number;
      margin: number;
      marginPercent: number;
    };

export function marginHasError(result: MarginResult): result is Extract<MarginResult, { error: string }> {
  return 'error' in result;
}

/**
 * Literal port of `DrugPricingEngineService::calculateMargin()`
 * (lines 45-107). `customPrice`, when provided, overrides the
 * `sale_price ?? price` selling price used for the margin calculation —
 * matching PHP's `$customPrice !== null` check exactly (0 is a valid
 * override, distinct from "not provided").
 */
export async function calculateMargin(db: Kysely<TenantDB>, drugId: number, customPrice: number | null = null): Promise<MarginResult> {
  const result = await sql<PricingRow>`
    SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id = ${drugId}
  `.execute(db);
  const drug = result.rows[0];

  if (!drug) {
    return { cost: 0.0, price: 0.0, margin: 0.0, marginPercent: 0.0, error: 'Drug not found' };
  }

  let cost = toFloatOrZero(drug.cost_price);
  if (cost <= 0) {
    const basePrice = toFloatOrZero(drug.sale_price ?? drug.price ?? 0);
    cost = basePrice * 0.7;
  }

  const price = customPrice !== null ? customPrice : toFloatOrZero(drug.sale_price ?? drug.price ?? 0);

  const margin = price - cost;
  const marginPercent = price > 0 ? ((price - cost) / price) * 100 : 0.0;

  return {
    drugId,
    drugName: drug.name,
    cost: round2(cost),
    price: round2(price),
    margin: round2(margin),
    marginPercent: round2(marginPercent),
  };
}

export type MaxDiscountResult =
  | {
      maxDiscount: number;
      maxDiscountPercent: number;
      floorPrice: number;
      error: string;
    }
  | {
      maxDiscount: number;
      maxDiscountPercent: number;
      floorPrice: number;
      currentPrice: number;
      cost: number;
      minMarginPercent: number;
      error: string;
    }
  | {
      drugId: number;
      maxDiscount: number;
      maxDiscountPercent: number;
      floorPrice: number;
      currentPrice: number;
      cost: number;
      minMarginPercent: number;
    };

/**
 * Literal port of `DrugPricingEngineService::getMaxDiscount()`
 * (lines 119-174). Calls `calculateMargin(db, drugId)` internally — no
 * `customPrice` override — matching PHP's `$this->calculateMargin($drugId)`
 * call (no second arg).
 */
export async function getMaxDiscount(db: Kysely<TenantDB>, drugId: number, minMarginPercent = 10.0): Promise<MaxDiscountResult> {
  const pricing = await calculateMargin(db, drugId);

  if (marginHasError(pricing)) {
    return { maxDiscount: 0.0, maxDiscountPercent: 0.0, floorPrice: 0.0, error: pricing.error };
  }

  const cost = pricing.cost;
  const price = pricing.price;
  const minMarginDecimal = minMarginPercent / 100;

  if (minMarginDecimal >= 1) {
    return {
      maxDiscount: 0.0,
      maxDiscountPercent: 0.0,
      floorPrice: price,
      currentPrice: price,
      cost,
      minMarginPercent,
      error: 'Invalid minimum margin percentage',
    };
  }

  const floorPrice = cost / (1 - minMarginDecimal);
  const maxDiscount = Math.max(0, price - floorPrice);
  const maxDiscountPercent = price > 0 ? (maxDiscount / price) * 100 : 0.0;

  return {
    drugId,
    maxDiscount: round2(maxDiscount),
    maxDiscountPercent: round2(maxDiscountPercent),
    floorPrice: round2(floorPrice),
    currentPrice: round2(price),
    cost: round2(cost),
    minMarginPercent,
  };
}
