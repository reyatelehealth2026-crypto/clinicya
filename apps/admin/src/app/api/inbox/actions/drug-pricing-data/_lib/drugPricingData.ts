import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * drugPricingData.ts — literal port of `classes/PharmacyIntegrationService.php`'s
 * `getDrugPricing()` (lines 653-704), as driven by api/inbox-v2.php's
 * `case 'drug_pricing_data': case 'drug-pricing-data':` (lines ~1022-1046).
 *
 * ```php
 * public function getDrugPricing(int $productId): array
 * {
 *     try {
 *         $stmt = $this->db->prepare("SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id = ?");
 *         $stmt->execute([$productId]);
 *         $product = $stmt->fetch(PDO::FETCH_ASSOC);
 *
 *         if (!$product) {
 *             return ['found' => false, 'productId' => $productId];
 *         }
 *
 *         $price = (float)$product['price'];
 *         $salePrice = $product['sale_price'] ? (float)$product['sale_price'] : null;
 *         $costPrice = $product['cost_price'] ? (float)$product['cost_price'] : null;
 *
 *         $effectivePrice = $salePrice ?? $price;
 *         $margin = $costPrice ? ($effectivePrice - $costPrice) : null;
 *         $marginPercent = ($costPrice && $effectivePrice > 0)
 *             ? (($effectivePrice - $costPrice) / $effectivePrice) * 100
 *             : null;
 *
 *         return [
 *             'found' => true, 'productId' => $productId, 'name' => $product['name'],
 *             'price' => $price, 'salePrice' => $salePrice, 'costPrice' => $costPrice,
 *             'effectivePrice' => $effectivePrice,
 *             'margin' => $margin ? round($margin, 2) : null,
 *             'marginPercent' => $marginPercent ? round($marginPercent, 2) : null,
 *             'hasCostData' => $costPrice !== null
 *         ];
 *     } catch (PDOException $e) {
 *         error_log("PharmacyIntegration getDrugPricing error: " . $e->getMessage());
 *         return ['found' => false, 'productId' => $productId, 'error' => $e->getMessage()];
 *     }
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * "PharmacyIntegrationService not available" 503 — structurally unreachable
 * in Next
 * ═══════════════════════════════════════════════════════════════════════
 * `api/inbox-v2.php`'s `case 'drug_pricing_data':` guards the call with
 * `$integration = loadService('PharmacyIntegrationService', ...); if
 * (!$integration) { sendError('Integration service not available', 503); }`
 * — a runtime `file_exists()`/`class_exists()` probe. This module is a
 * static TypeScript import; either it compiles into the bundle or the
 * build fails. `route.ts` does NOT fabricate a runtime 503 branch for
 * this. Same decision documented on `../max-discount/_lib/drugPricingEngine.ts`.
 *
 * NOTE the PHP `$product['sale_price'] ? ... : null` / `$product['cost_price']
 * ? ... : null` checks are PHP truthiness, not null checks — a `sale_price`
 * of `0`/`'0'`/`0.00` (falsy) is treated the same as absent/null and yields
 * `salePrice: null`. Ported literally via `phpTruthyToFloat()` below, not
 * a `!== null` check.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * DB ERRORS ARE SWALLOWED INTO THE RETURN VALUE, NOT THROWN — literal PHP
 * parity, and the reason `route.ts` never needs its own 500 branch
 * ═══════════════════════════════════════════════════════════════════════
 * The PHP method's own `catch (PDOException $e)` returns `['found' =>
 * false, 'productId' => $productId, 'error' => $e->getMessage()]` rather
 * than letting the exception propagate — `case 'drug_pricing_data':` has no
 * try/catch of its own, so a genuine DB failure here still reaches
 * `sendResponse()` normally (HTTP 200, `success: false` because `found` is
 * `false`), never api/inbox-v2.php's outer `catch (Throwable $e)` handler
 * (line 3553, `'Internal server error: ...'`, 500) that a THROWN exception
 * from here would otherwise hit. This function reproduces that: any error
 * from the query is caught here and folded into the same `{found: false,
 * productId, error}` shape `route.ts` already returns as HTTP 200 — it
 * never rejects.
 */

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/**
 * PHP `$v ? (float) $v : null` — PHP truthiness on the raw DB value: a
 * numeric-string `'0'`/`'0.00'`/`0` is falsy, so this returns `null` for
 * those, matching `sale_price`/`cost_price`'s exact PHP treatment (NOT a
 * `!= null` check — `0` is a legitimate value that PHP still discards here).
 */
function phpTruthyToFloatOrNull(value: unknown): number | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'number' && value === 0) return null;
  if (typeof value === 'string' && (value === '' || Number(value) === 0)) return null;
  return toFloatOrZero(value);
}

/** PHP `round($x, 2)`. */
function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

interface DrugPricingRow {
  id: number;
  name: string;
  price: unknown;
  sale_price: unknown;
  cost_price: unknown;
}

export type DrugPricingDataResult =
  | { found: false; productId: number }
  | { found: false; productId: number; error: string }
  | {
      found: true;
      productId: number;
      name: string;
      price: number;
      salePrice: number | null;
      costPrice: number | null;
      effectivePrice: number;
      margin: number | null;
      marginPercent: number | null;
      hasCostData: boolean;
    };

export async function getDrugPricingData(db: Kysely<TenantDB>, productId: number): Promise<DrugPricingDataResult> {
  try {
    const result = await sql<DrugPricingRow>`
      SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id = ${productId}
    `.execute(db);
    const product = result.rows[0];

    if (!product) {
      return { found: false, productId };
    }

    const price = toFloatOrZero(product.price);
    const salePrice = phpTruthyToFloatOrNull(product.sale_price);
    const costPrice = phpTruthyToFloatOrNull(product.cost_price);

    const effectivePrice = salePrice ?? price;
    const margin = costPrice ? effectivePrice - costPrice : null;
    const marginPercent = costPrice && effectivePrice > 0 ? ((effectivePrice - costPrice) / effectivePrice) * 100 : null;

    return {
      found: true,
      productId,
      name: product.name,
      price,
      salePrice,
      costPrice,
      effectivePrice,
      margin: margin ? round2(margin) : null,
      marginPercent: marginPercent ? round2(marginPercent) : null,
      hasCostData: costPrice !== null,
    };
  } catch (error) {
    // PharmacyIntegrationService::getDrugPricing()'s own `catch (PDOException $e)` — see module doc.
    const message = error instanceof Error ? error.message : String(error);
    return { found: false, productId, error: message };
  }
}
