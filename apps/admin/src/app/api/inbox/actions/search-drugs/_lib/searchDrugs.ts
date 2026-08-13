import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * searchDrugs.ts — literal port of api/inbox-v2.php's `case 'search_drugs':
 * case 'search-drugs':` (lines ~617-699):
 *
 * ```php
 * case 'search_drugs':
 * case 'search-drugs':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $query = trim($_GET['query'] ?? '');
 *     if (empty($query)) { sendError('Search query is required'); }
 *     if (mb_strlen($query) < 2) { sendError('Query must be at least 2 characters'); }
 *     if (mb_strlen($query) > 100) { sendError('Query is too long (max 100 characters)'); }
 *     try {
 *         $columnsStmt = $db->query("SHOW COLUMNS FROM business_items");
 *         $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
 *         $hasGenericName = in_array('generic_name', $columns);
 *         $hasNameEn = in_array('name_en', $columns);
 *
 *         $selectCols = "id, name, sku, price, sale_price, stock, description";
 *         if ($hasGenericName) $selectCols .= ", generic_name";
 *         if ($hasNameEn) $selectCols .= ", name_en";
 *
 *         $searchConditions = ["name LIKE ?", "sku LIKE ?"];
 *         $params = ["%{$query}%", "%{$query}%"];
 *         if ($hasGenericName) { $searchConditions[] = "generic_name LIKE ?"; $params[] = "%{$query}%"; }
 *         if ($hasNameEn) { $searchConditions[] = "name_en LIKE ?"; $params[] = "%{$query}%"; }
 *
 *         $sql = "SELECT {$selectCols} FROM business_items
 *                 WHERE is_active = 1
 *                 AND (" . implode(' OR ', $searchConditions) . ")";
 *         if ($lineAccountId) { $sql .= " AND (line_account_id = ? OR line_account_id IS NULL)"; $params[] = $lineAccountId; }
 *         $sql .= " ORDER BY stock DESC, name ASC LIMIT 10";
 *
 *         $stmt = $db->prepare($sql);
 *         $stmt->execute($params);
 *         $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *
 *         $results = [];
 *         foreach ($drugs as $drug) {
 *             $results[] = [
 *                 'id' => (int) $drug['id'], 'name' => $drug['name'],
 *                 'name_en' => $drug['name_en'] ?? '', 'generic_name' => $drug['generic_name'] ?? '',
 *                 'sku' => $drug['sku'], 'price' => (float) ($drug['sale_price'] ?? $drug['price'] ?? 0),
 *                 'stock' => (int) ($drug['stock'] ?? 0)
 *             ];
 *         }
 *
 *         sendResponse(['success' => true, 'data' => $results, 'count' => count($results), 'query' => $query]);
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
 * Per this batch's confirmed schema finding: `business_items.generic_name`
 * and `business_items.name_en` are both real columns in the committed
 * tenant template (`packages/db/src/generated/tenant-db.d.ts`'s
 * `BusinessItems.generic_name` / `.name_en`, both `Generated<string |
 * null>`) and in `database/install_complete_latest.sql`. `$hasGenericName`
 * and `$hasNameEn` are therefore always `true` in this port —
 * `generic_name`/`name_en` are always selected and always searched, and
 * `$searchConditions` always has all 4 `LIKE` arms.
 *
 * PHP's `if ($lineAccountId) { ... AND (line_account_id = ? OR
 * line_account_id IS NULL) }` clause is kept literally (not dropped) even
 * though `lineAccountId` always resolves to a truthy int in this port
 * (`session.currentBotId ?? 1`) — see `route.ts`'s own doc for why the
 * WHERE shape is preserved regardless.
 */

interface SearchDrugRow {
  id: number;
  name: string;
  sku: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  description: string | null;
  generic_name: string | null;
  name_en: string | null;
}

export interface SearchDrugResult {
  id: number;
  name: string;
  name_en: string;
  generic_name: string;
  sku: string | null;
  price: number;
  stock: number;
}

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `(int) $v` — non-numeric/null/undefined -> 0. */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/**
 * `lineAccountId` is passed through as-is (PHP's `if ($lineAccountId)`
 * guard around the whole `AND (line_account_id = ? OR line_account_id IS
 * NULL)` clause is kept literally for WHERE-shape parity per this batch's
 * brief, even though it's effectively always-true here).
 */
export async function searchDrugs(db: Kysely<TenantDB>, query: string, lineAccountId: number): Promise<SearchDrugResult[]> {
  const term = `%${query}%`;

  const result = lineAccountId
    ? await sql<SearchDrugRow>`
        SELECT id, name, sku, price, sale_price, stock, description, generic_name, name_en
        FROM business_items
        WHERE is_active = 1
        AND (name LIKE ${term} OR sku LIKE ${term} OR generic_name LIKE ${term} OR name_en LIKE ${term})
        AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
        ORDER BY stock DESC, name ASC LIMIT 10
      `.execute(db)
    : await sql<SearchDrugRow>`
        SELECT id, name, sku, price, sale_price, stock, description, generic_name, name_en
        FROM business_items
        WHERE is_active = 1
        AND (name LIKE ${term} OR sku LIKE ${term} OR generic_name LIKE ${term} OR name_en LIKE ${term})
        ORDER BY stock DESC, name ASC LIMIT 10
      `.execute(db);

  return result.rows.map((drug) => ({
    id: toIntOrZero(drug.id),
    name: drug.name,
    name_en: drug.name_en ?? '',
    generic_name: drug.generic_name ?? '',
    sku: drug.sku,
    price: toFloatOrZero(drug.sale_price ?? drug.price ?? 0),
    stock: toIntOrZero(drug.stock ?? 0),
  }));
}
