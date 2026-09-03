import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * lowStockDrugs.ts — literal port of `classes/PharmacyIntegrationService.php`'s
 * `getLowStockDrugs()` (lines 712-735), as driven by api/inbox-v2.php's
 * `case 'low_stock_drugs': case 'low-stock-drugs':` (lines ~1161-1182).
 *
 * ```php
 * public function getLowStockDrugs(int $limit = 50): array
 * {
 *     try {
 *         $stmt = $this->db->prepare("
 *             SELECT
 *                 id, sku, name, generic_name,
 *                 stock, min_stock,
 *                 drug_category, is_prescription
 *             FROM business_items
 *             WHERE is_active = 1
 *             AND stock <= min_stock
 *             AND stock > 0
 *             ORDER BY (stock / GREATEST(min_stock, 1)) ASC
 *             LIMIT ?
 *         ");
 *         $stmt->execute([$limit]);
 *         return $stmt->fetchAll(PDO::FETCH_ASSOC);
 *     } catch (PDOException $e) {
 *         error_log("PharmacyIntegration getLowStockDrugs error: " . $e->getMessage());
 *         return [];
 *     }
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX — `is_prescription` -> `requires_prescription`
 * ═══════════════════════════════════════════════════════════════════════
 * Same confirmed finding as `../../drug-inventory/_lib/drugInventory.ts`
 * (see that module's doc for the full writeup):
 * `business_items.is_prescription` does not exist in either the committed
 * tenant template or production — the real column is
 * `requires_prescription`.
 *
 * EFFECT IN CURRENT PRODUCTION: the explicit `SELECT ... is_prescription
 * ...` above throws a PDOException ("Unknown column") on EVERY call —
 * caught locally by the method's own `catch (PDOException $e)`, which
 * returns a bare `[]`. `case 'low_stock_drugs':` then does
 * `sendResponse(['success' => true, 'data' => $result, 'count' =>
 * count($result)])` UNCONDITIONALLY — there is no `found`/error check for
 * this action (unlike `drug-inventory`/`drug-pricing-data`) — so today
 * this action ALWAYS returns `{success: true, data: [], count: 0}` in
 * production, even when real low-stock rows exist.
 *
 * This is a deliberate, documented FIX-FORWARD deviation (not a
 * reproduction of the always-broken PHP behavior): the query below selects
 * the real `requires_prescription` column, ALIASED to `is_prescription` in
 * the result set, so real low-stock rows are actually returned. Same
 * precedent already set by Phase 4 batch 3's assign-conversation route
 * (`docs/runbooks/phase4-batch3-inbox-actions-parity.md` §1).
 *
 * The `catch (PDOException $e) { return []; }` swallow itself IS kept
 * (this function never throws — a genuine, unrelated DB failure still
 * resolves to an empty array, exactly as PHP's own method does), since
 * that swallow-to-empty-array behavior is independent of the schema-drift
 * bug being fixed here and `case 'low_stock_drugs':` has no case-level
 * try/catch of its own to fall back on (a thrown exception here would
 * otherwise reach api/inbox-v2.php's generic outer `catch (Throwable $e)`
 * handler and a different 500 response shape than this action has ever
 * produced).
 */

interface LowStockRow {
  id: number;
  sku: string | null;
  name: string;
  generic_name: string | null;
  stock: number;
  min_stock: number;
  drug_category: string | null;
  is_prescription: number | null;
}

export async function getLowStockDrugs(db: Kysely<TenantDB>, limit: number): Promise<LowStockRow[]> {
  try {
    const result = await sql<LowStockRow>`
      SELECT
        id, sku, name, generic_name,
        stock, min_stock,
        drug_category, requires_prescription AS is_prescription
      FROM business_items
      WHERE is_active = 1
      AND stock <= min_stock
      AND stock > 0
      ORDER BY (stock / GREATEST(min_stock, 1)) ASC
      LIMIT ${limit}
    `.execute(db);
    return result.rows;
  } catch {
    // PharmacyIntegrationService::getLowStockDrugs()'s own `catch (PDOException $e) { return []; }` — see module doc.
    return [];
  }
}
