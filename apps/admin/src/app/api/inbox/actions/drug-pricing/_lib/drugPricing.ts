import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * drugPricing.ts — literal port of api/inbox-v2.php's `case 'drug_pricing':
 * case 'drug-pricing': case 'calculate_margin':` (lines ~705-770), the
 * INLINE-SQL margin-calc action — NOT `PharmacyIntegrationService::
 * getDrugPricing()` (that's `drug-pricing-data`, a different action/route
 * despite the similar name) and NOT `DrugPricingEngineService::
 * calculateMargin()` (that's `max-discount/_lib/drugPricingEngine.ts`'s
 * `calculateMargin`, called by `drug-info` and `max-discount` only).
 *
 * ```php
 * case 'drug_pricing':
 * case 'drug-pricing':
 * case 'calculate_margin':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);
 *     if (!$drugId) { sendError('Drug ID is required'); }
 *     try {
 *         $columnsStmt = $db->query("SHOW COLUMNS FROM business_items");
 *         $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
 *         $hasCostPrice = in_array('cost_price', $columns);
 *         if ($hasCostPrice) {
 *             $stmt = $db->prepare("SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id = ?");
 *         } else {
 *             $stmt = $db->prepare("SELECT id, name, price, sale_price FROM business_items WHERE id = ?");
 *         }
 *         $stmt->execute([$drugId]);
 *         $drug = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if (!$drug) { sendError('Drug not found', 404); }
 *
 *         $price = (float) ($drug['sale_price'] ?? $drug['price'] ?? 0);
 *         $cost = $hasCostPrice ? (float) ($drug['cost_price'] ?? 0) : 0;
 *         $estimated = false;
 *         if ($cost <= 0 && $price > 0) {
 *             $cost = $price * 0.7;
 *             $estimated = true;
 *         }
 *         $margin = $price - $cost;
 *         $marginPercent = $price > 0 ? (($price - $cost) / $price) * 100 : 0;
 *
 *         sendResponse(['success' => true, 'data' => [
 *             'drugId' => (int) $drug['id'], 'drugName' => $drug['name'],
 *             'cost' => round($cost, 2), 'price' => round($price, 2),
 *             'margin' => round($margin, 2), 'marginPercent' => round($marginPercent, 2),
 *             'estimated' => $estimated
 *         ]]);
 *     } catch (PDOException $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Database error: ' . $e->getMessage(), 500);
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SIMPLIFICATION — the `SHOW COLUMNS FROM business_items` probe is dropped
 * ═══════════════════════════════════════════════════════════════════════
 * Per this batch's confirmed schema finding (see this batch's brief and
 * `../max-discount/_lib/drugPricingEngine.ts`'s identical note):
 * `business_items.cost_price` is a real column in both the committed tenant
 * template (`packages/db/src/generated/tenant-db.d.ts`'s
 * `BusinessItems.cost_price: Generated<Decimal | null>`) and
 * `database/install_complete_latest.sql`. `$hasCostPrice` is therefore
 * always `true` in this port — `cost_price` is always selected, and the
 * `$hasCostPrice ? ... : 0` cost branch always takes the `cost_price` arm.
 *
 * NOTE the `estimated` cost fallback here (`$cost <= 0 && $price > 0` ->
 * `$cost = $price * 0.7`) is intentionally distinct from
 * `DrugPricingEngineService::calculateMargin()`'s own estimate (`$cost <=
 * 0` -> `$cost = $basePrice * 0.7`, with NO `$price > 0` guard) — this
 * action's own inline copy requires `$price > 0` as well, so a drug with
 * `price = 0` and no `cost_price` gets `cost = 0` here (not `0 * 0.7 = 0`
 * anyway — same numeric result, but `estimated` stays `false` in that edge
 * case, unlike the shared pricing-engine's version). Ported literally, not
 * unified with the pricing-engine's helper.
 */

/** PHP `round($x, 2)`. */
function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

interface DrugPricingRow {
  id: number;
  name: string;
  price: unknown;
  sale_price: unknown;
  cost_price: unknown;
}

export interface DrugPricingData {
  drugId: number;
  drugName: string;
  cost: number;
  price: number;
  margin: number;
  marginPercent: number;
  estimated: boolean;
}

export async function getDrugPricingInline(db: Kysely<TenantDB>, drugId: number): Promise<DrugPricingData | null> {
  const result = await sql<DrugPricingRow>`
    SELECT id, name, price, sale_price, cost_price FROM business_items WHERE id = ${drugId}
  `.execute(db);
  const drug = result.rows[0];

  if (!drug) {
    return null;
  }

  const price = toFloatOrZero(drug.sale_price ?? drug.price ?? 0);
  let cost = toFloatOrZero(drug.cost_price);

  let estimated = false;
  if (cost <= 0 && price > 0) {
    cost = price * 0.7;
    estimated = true;
  }

  const margin = price - cost;
  const marginPercent = price > 0 ? ((price - cost) / price) * 100 : 0;

  return {
    drugId: Number(drug.id),
    drugName: drug.name,
    cost: round2(cost),
    price: round2(price),
    margin: round2(margin),
    marginPercent: round2(marginPercent),
    estimated,
  };
}
