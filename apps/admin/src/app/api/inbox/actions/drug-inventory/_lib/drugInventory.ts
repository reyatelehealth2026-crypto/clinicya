import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * drugInventory.ts — literal port of `classes/PharmacyIntegrationService.php`'s
 * `getDrugInventory()` (lines 516-576), as driven by api/inbox-v2.php's
 * `case 'drug_inventory': case 'drug-inventory': case 'get_drug_inventory':`
 * (lines ~986-1011).
 *
 * ```php
 * public function getDrugInventory(int $productId): array
 * {
 *     try {
 *         $stmt = $this->db->prepare("
 *             SELECT
 *                 id, sku, name, generic_name,
 *                 stock, min_stock,
 *                 price, sale_price, cost_price,
 *                 is_active, is_prescription, drug_category,
 *                 storage_condition, storage_zone_type,
 *                 requires_batch_tracking, requires_expiry_tracking
 *             FROM business_items
 *             WHERE id = ?
 *         ");
 *         $stmt->execute([$productId]);
 *         $product = $stmt->fetch(PDO::FETCH_ASSOC);
 *
 *         if (!$product) {
 *             return ['found' => false, 'productId' => $productId, 'inStock' => false, 'stock' => 0];
 *         }
 *
 *         $stock = (int)$product['stock'];
 *         $minStock = (int)$product['min_stock'];
 *
 *         return [
 *             'found' => true, 'productId' => $productId, 'sku' => $product['sku'],
 *             'name' => $product['name'], 'genericName' => $product['generic_name'],
 *             'stock' => $stock, 'minStock' => $minStock,
 *             'inStock' => $stock > 0, 'isLowStock' => $stock > 0 && $stock <= $minStock,
 *             'isOutOfStock' => $stock <= 0,
 *             'price' => (float)$product['price'],
 *             'salePrice' => $product['sale_price'] ? (float)$product['sale_price'] : null,
 *             'costPrice' => $product['cost_price'] ? (float)$product['cost_price'] : null,
 *             'isActive' => (bool)$product['is_active'],
 *             'isPrescription' => (bool)$product['is_prescription'],
 *             'drugCategory' => $product['drug_category'],
 *             'storageCondition' => $product['storage_condition'],
 *             'storageZoneType' => $product['storage_zone_type'],
 *             'requiresBatchTracking' => (bool)$product['requires_batch_tracking'],
 *             'requiresExpiryTracking' => (bool)$product['requires_expiry_tracking']
 *         ];
 *     } catch (PDOException $e) {
 *         error_log("PharmacyIntegration getDrugInventory error: " . $e->getMessage());
 *         return ['found' => false, 'productId' => $productId, 'error' => $e->getMessage()];
 *     }
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX — `is_prescription` -> `requires_prescription`
 * ═══════════════════════════════════════════════════════════════════════
 * This batch's confirmed finding (verified against both
 * `packages/db/src/generated/tenant-db.d.ts`'s `BusinessItems` interface
 * AND `database/install_complete_latest.sql`'s real DDL): the column PHP
 * reads as `is_prescription` does not exist in either the committed tenant
 * template or production — the real column is `requires_prescription` (a
 * historical rename; older `database/install_complete.sql` /
 * `database/schema_complete.sql` dumps DID have `is_prescription`).
 * `api/drug-interactions.php` / `api/ai-telepharmacy-admin.php` already
 * defensively guard this exact drift via `information_schema` checks, but
 * `PharmacyIntegrationService.php` does not.
 *
 * EFFECT IN CURRENT PRODUCTION: the explicit `SELECT ... is_prescription
 * ...` above throws a PDOException ("Unknown column") on EVERY call —
 * caught locally by the method's own `catch (PDOException $e)` — so
 * `case 'drug_inventory':` ALWAYS returns `{success: false, data:
 * {found: false, productId, error}}` in production today, regardless of
 * whether `$productId` is a real row.
 *
 * This is a deliberate, documented FIX-FORWARD deviation (not a
 * reproduction of the always-broken PHP behavior): the query below selects
 * the real `requires_prescription` column, ALIASED to `is_prescription` in
 * the result set so the response envelope's `isPrescription` key stays
 * exactly where every consumer already expects it. Same precedent already
 * set by Phase 4 batch 3's assign-conversation route (see
 * `docs/runbooks/phase4-batch3-inbox-actions-parity.md` §1, "intentional,
 * flagged deviation from a genuine PHP bug"). `low-stock-drugs` carries the
 * identical fix for the identical root cause — see that route's own
 * `_lib/lowStockDrugs.ts` module doc.
 *
 * DB errors (genuinely unrelated to this fixed column) are still swallowed
 * into the return value exactly as PHP's own `catch (PDOException $e)`
 * does — see the "DB ERRORS ARE SWALLOWED" note on
 * `../../drug-pricing-data/_lib/drugPricingData.ts` for the identical
 * rationale (no case-level try/catch in `case 'drug_inventory':`, so a
 * thrown exception here would otherwise reach api/inbox-v2.php's outer
 * `catch (Throwable $e)` handler and a different, generic 500 response —
 * this function never throws, matching the literal PHP method's own
 * internal catch).
 */

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `(int) $v` on a DB column value — non-numeric/null/undefined -> 0. */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/** PHP `$v ? (float) $v : null` — PHP truthiness on the raw DB value (a falsy `0`/`'0'` yields `null`, not `0`). */
function phpTruthyToFloatOrNull(value: unknown): number | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'number' && value === 0) return null;
  if (typeof value === 'string' && (value === '' || Number(value) === 0)) return null;
  return toFloatOrZero(value);
}

/** PHP `(bool) $v` on a DB column value (MySQL `TINYINT(1)`/`0`/`1`, or `null`). */
function toBool(value: unknown): boolean {
  return Boolean(value);
}

interface DrugInventoryRow {
  id: number;
  sku: string | null;
  name: string;
  generic_name: string | null;
  stock: unknown;
  min_stock: unknown;
  price: unknown;
  sale_price: unknown;
  cost_price: unknown;
  is_active: unknown;
  is_prescription: unknown;
  drug_category: string | null;
  storage_condition: string | null;
  storage_zone_type: string | null;
  requires_batch_tracking: unknown;
  requires_expiry_tracking: unknown;
}

export type DrugInventoryResult =
  | { found: false; productId: number; inStock: false; stock: 0 }
  | { found: false; productId: number; error: string }
  | {
      found: true;
      productId: number;
      sku: string | null;
      name: string;
      genericName: string | null;
      stock: number;
      minStock: number;
      inStock: boolean;
      isLowStock: boolean;
      isOutOfStock: boolean;
      price: number;
      salePrice: number | null;
      costPrice: number | null;
      isActive: boolean;
      isPrescription: boolean;
      drugCategory: string | null;
      storageCondition: string | null;
      storageZoneType: string | null;
      requiresBatchTracking: boolean;
      requiresExpiryTracking: boolean;
    };

export async function getDrugInventory(db: Kysely<TenantDB>, productId: number): Promise<DrugInventoryResult> {
  try {
    const result = await sql<DrugInventoryRow>`
      SELECT
        id, sku, name, generic_name,
        stock, min_stock,
        price, sale_price, cost_price,
        is_active, requires_prescription AS is_prescription, drug_category,
        storage_condition, storage_zone_type,
        requires_batch_tracking, requires_expiry_tracking
      FROM business_items
      WHERE id = ${productId}
    `.execute(db);
    const product = result.rows[0];

    if (!product) {
      return { found: false, productId, inStock: false, stock: 0 };
    }

    const stock = toIntOrZero(product.stock);
    const minStock = toIntOrZero(product.min_stock);

    return {
      found: true,
      productId,
      sku: product.sku,
      name: product.name,
      genericName: product.generic_name,
      stock,
      minStock,
      inStock: stock > 0,
      isLowStock: stock > 0 && stock <= minStock,
      isOutOfStock: stock <= 0,
      price: toFloatOrZero(product.price),
      salePrice: phpTruthyToFloatOrNull(product.sale_price),
      costPrice: phpTruthyToFloatOrNull(product.cost_price),
      isActive: toBool(product.is_active),
      isPrescription: toBool(product.is_prescription),
      drugCategory: product.drug_category,
      storageCondition: product.storage_condition,
      storageZoneType: product.storage_zone_type,
      requiresBatchTracking: toBool(product.requires_batch_tracking),
      requiresExpiryTracking: toBool(product.requires_expiry_tracking),
    };
  } catch (error) {
    // PharmacyIntegrationService::getDrugInventory()'s own `catch (PDOException $e)` — see module doc.
    const message = error instanceof Error ? error.message : String(error);
    return { found: false, productId, error: message };
  }
}
