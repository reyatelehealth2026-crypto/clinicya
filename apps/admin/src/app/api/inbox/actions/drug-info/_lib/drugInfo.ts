import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * drugInfo.ts — literal port of api/inbox-v2.php's `case 'drug_info':
 * case 'drug-info': case 'get_drug_info':` (lines ~518-612)'s DB-lookup +
 * response-shaping half (the `calculateMargin()` call itself lives in
 * `route.ts`, imported from `../max-discount/_lib/drugPricingEngine.ts` —
 * see that file's module doc and `route.ts`'s own doc for why):
 *
 * ```php
 * if ($drugId) {
 *     $stmt = $db->prepare("
 *         SELECT bi.*, ic.name as category_name
 *         FROM business_items bi
 *         LEFT JOIN item_categories ic ON bi.category_id = ic.id
 *         WHERE bi.id = ?
 *     ");
 *     $stmt->execute([$drugId]);
 * } else {
 *     $stmt = $db->prepare("
 *         SELECT bi.*, ic.name as category_name
 *         FROM business_items bi
 *         LEFT JOIN item_categories ic ON bi.category_id = ic.id
 *         WHERE (bi.name LIKE ? OR bi.sku LIKE ?)
 *         AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)
 *         LIMIT 1
 *     ");
 *     $searchTerm = '%' . $drugName . '%';
 *     $stmt->execute([$searchTerm, $searchTerm, $lineAccountId]);
 * }
 * $drug = $stmt->fetch(PDO::FETCH_ASSOC);
 * if (!$drug) { sendError('Drug not found', 404); }
 *
 * $effectivePrice = (float) ($drug['sale_price'] ?? 0) > 0 ? (float) $drug['sale_price'] : (float) ($drug['price'] ?? 0);
 *
 * sendResponse(['success' => true, 'data' => [
 *     'id' => (int) $drug['id'], 'name' => $drug['name'],
 *     'nameEn' => $drug['name_en'] ?? null, 'genericName' => $drug['generic_name'] ?? null,
 *     'manufacturer' => $drug['manufacturer'] ?? null, 'unit' => $drug['unit'] ?? $drug['base_unit'] ?? null,
 *     'sku' => $drug['sku'] ?? null, 'description' => $drug['description'] ?? null,
 *     'price' => (float) ($drug['price'] ?? 0), 'salePrice' => (float) ($drug['sale_price'] ?? 0),
 *     'effectivePrice' => $effectivePrice, 'category' => $drug['category_name'] ?? null,
 *     'imageUrl' => $drug['image_url'] ?? null, 'stock' => (int) ($drug['stock'] ?? 0),
 *     'isActive' => (bool) ($drug['is_active'] ?? true), 'isPrescription' => (bool) ($drug['is_prescription'] ?? false),
 *     'contraindications' => $drug['contraindications'] ?? null, 'dosage' => $drug['dosage'] ?? null,
 *     'usageInstructions' => $drug['usage_instructions'] ?? null, 'activeIngredient' => $drug['active_ingredient'] ?? null,
 *     'dosageForm' => $drug['dosage_form'] ?? null, 'barcode' => $drug['barcode'] ?? null,
 *     'pricing' => $pricing
 * ]]);
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX — `isPrescription` reads `requires_prescription`
 * ═══════════════════════════════════════════════════════════════════════
 * `bi.*` selects every real column on `business_items`, including
 * `requires_prescription` — but NOT a column literally named
 * `is_prescription` (it does not exist; see this batch's confirmed finding,
 * documented in full on `../../drug-inventory/_lib/drugInventory.ts`). PHP's
 * own `$drug['is_prescription'] ?? false` read is therefore silently,
 * PERMANENTLY `false` in production today — `bi.*` never throws (unlike
 * `drug-inventory`'s explicit `SELECT ... is_prescription ...`, which does),
 * it just never populates a key that isn't there, so `?? false` always wins.
 * This port reads the real `requires_prescription` value instead — a
 * deliberate, documented fix-forward deviation, same precedent as Phase 4
 * batch 3's assign-conversation route.
 *
 * `SHOW COLUMNS`-style probing is not applicable here (PHP never probes for
 * this action — `bi.*` selects whatever exists, silently omitting the rest);
 * this file selects every field explicitly instead of `bi.*` for a properly
 * typed `sql` row shape, but is otherwise a 1:1 column-for-column read.
 */

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

interface DrugInfoRow {
  id: number;
  name: string;
  name_en: string | null;
  generic_name: string | null;
  manufacturer: string | null;
  unit: string | null;
  base_unit: string | null;
  sku: string | null;
  description: string | null;
  price: unknown;
  sale_price: unknown;
  category_name: string | null;
  image_url: string | null;
  stock: unknown;
  is_active: unknown;
  requires_prescription: unknown;
  contraindications: string | null;
  dosage: string | null;
  usage_instructions: string | null;
  active_ingredient: string | null;
  dosage_form: string | null;
  barcode: string | null;
}

/**
 * The response shape's own fields (everything except `pricing`, which
 * `route.ts` attaches separately after its own `calculateMargin()` call —
 * matching PHP's `$pricing` being computed in a distinct try/catch block
 * from the row fetch).
 */
export interface DrugInfoData {
  id: number;
  name: string;
  nameEn: string | null;
  genericName: string | null;
  manufacturer: string | null;
  unit: string | null;
  sku: string | null;
  description: string | null;
  price: number;
  salePrice: number;
  effectivePrice: number;
  category: string | null;
  imageUrl: string | null;
  stock: number;
  isActive: boolean;
  isPrescription: boolean;
  contraindications: string | null;
  dosage: string | null;
  usageInstructions: string | null;
  activeIngredient: string | null;
  dosageForm: string | null;
  barcode: string | null;
}

const SELECT_COLUMNS = `
  bi.id, bi.name, bi.name_en, bi.generic_name, bi.manufacturer, bi.unit, bi.base_unit,
  bi.sku, bi.description, bi.price, bi.sale_price, ic.name AS category_name,
  bi.image_url, bi.stock, bi.is_active, bi.requires_prescription, bi.contraindications,
  bi.dosage, bi.usage_instructions, bi.active_ingredient, bi.dosage_form, bi.barcode
`;

function shapeDrugInfo(drug: DrugInfoRow): DrugInfoData {
  const price = toFloatOrZero(drug.price);
  const salePrice = toFloatOrZero(drug.sale_price);
  const effectivePrice = salePrice > 0 ? salePrice : price;

  return {
    id: toIntOrZero(drug.id),
    name: drug.name,
    nameEn: drug.name_en ?? null,
    genericName: drug.generic_name ?? null,
    manufacturer: drug.manufacturer ?? null,
    unit: drug.unit ?? drug.base_unit ?? null,
    sku: drug.sku ?? null,
    description: drug.description ?? null,
    price,
    salePrice,
    effectivePrice,
    category: drug.category_name ?? null,
    imageUrl: drug.image_url ?? null,
    stock: toIntOrZero(drug.stock ?? 0),
    isActive: drug.is_active === null || drug.is_active === undefined ? true : Boolean(drug.is_active),
    isPrescription: Boolean(drug.requires_prescription ?? false),
    contraindications: drug.contraindications ?? null,
    dosage: drug.dosage ?? null,
    usageInstructions: drug.usage_instructions ?? null,
    activeIngredient: drug.active_ingredient ?? null,
    dosageForm: drug.dosage_form ?? null,
    barcode: drug.barcode ?? null,
  };
}

/** By id — `WHERE bi.id = ?`. Returns `null` when no row matches. */
export async function getDrugInfoById(db: Kysely<TenantDB>, drugId: number): Promise<DrugInfoData | null> {
  const result = await sql<DrugInfoRow>`
    SELECT ${sql.raw(SELECT_COLUMNS)}
    FROM business_items bi
    LEFT JOIN item_categories ic ON bi.category_id = ic.id
    WHERE bi.id = ${drugId}
  `.execute(db);

  const drug = result.rows[0];
  return drug ? shapeDrugInfo(drug) : null;
}

/**
 * By name — `WHERE (bi.name LIKE ? OR bi.sku LIKE ?) AND (bi.line_account_id
 * = ? OR bi.line_account_id IS NULL) LIMIT 1`, search term `%{name}%` bound
 * twice. Returns `null` when no row matches.
 */
export async function getDrugInfoByName(db: Kysely<TenantDB>, drugName: string, lineAccountId: number): Promise<DrugInfoData | null> {
  const term = `%${drugName}%`;

  const result = await sql<DrugInfoRow>`
    SELECT ${sql.raw(SELECT_COLUMNS)}
    FROM business_items bi
    LEFT JOIN item_categories ic ON bi.category_id = ic.id
    WHERE (bi.name LIKE ${term} OR bi.sku LIKE ${term})
    AND (bi.line_account_id = ${lineAccountId} OR bi.line_account_id IS NULL)
    LIMIT 1
  `.execute(db);

  const drug = result.rows[0];
  return drug ? shapeDrugInfo(drug) : null;
}
