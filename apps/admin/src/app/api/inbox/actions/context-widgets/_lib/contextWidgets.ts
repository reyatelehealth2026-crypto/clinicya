import { sql, type Kysely, type SqlBool } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * contextWidgets.ts — literal port of `classes/ConsultationAnalyzerService.php`'s
 * `getContextWidgets()` (lines 432-488) and its private helper tree:
 * `getActiveKeywords()`/`getDefaultKeywords()` (494-525), `buildWidget()`
 * (536-560), `buildSymptomWidget()`/`getSymptomRecommendations()`/
 * `getPopularDrugs()` (570-727), `buildDrugInfoWidget()` (736-751),
 * `buildInteractionWidget()` (758-775), `buildAllergyWidget()` (782-803),
 * `buildPricingWidget()` (810-823), `buildPregnancyWidget()` (829-843),
 * `checkForDrugNames()` (851-928), `searchDrugsFromMessage()` (938-1107),
 * `checkAllergyWarnings()` (1339-1357), `getUserAllergies()` (1364-1380),
 * `getUserMedications()` (1387-1403) — as driven by api/inbox-v2.php's
 * `case 'context_widgets': case 'context-widgets': case
 * 'get_context_widgets':` (lines ~1521-1564).
 *
 * ```php
 * public function getContextWidgets(string $message, int $userId): array
 * {
 *     $widgets = [];
 *     $messageLower = mb_strtolower($message);
 *     $keywords = $this->getActiveKeywords();
 *     $matchedKeywords = [];
 *     foreach ($keywords as $keyword) {
 *         if (mb_stripos($messageLower, mb_strtolower($keyword['keyword'])) !== false) {
 *             $matchedKeywords[] = $keyword;
 *         }
 *     }
 *     usort($matchedKeywords, fn($a, $b) => ($b['priority'] ?? 0) - ($a['priority'] ?? 0));
 *     foreach ($matchedKeywords as $keyword) {
 *         $widgetType = $keyword['widget_type'];
 *         $relatedData = json_decode($keyword['related_data'] ?? '{}', true);
 *         $existingTypes = array_column($widgets, 'type');
 *         if (in_array($widgetType, $existingTypes)) { continue; }
 *         $widget = $this->buildWidget($widgetType, $keyword, $relatedData, $userId, $message);
 *         if ($widget) { $widgets[] = $widget; }
 *     }
 *     $drugWidgets = $this->checkForDrugNames($message, $userId);
 *     foreach ($drugWidgets as $drugWidget) {
 *         $existingTypes = array_column($widgets, 'type');
 *         if (!in_array($drugWidget['type'], $existingTypes)) { $widgets[] = $drugWidget; }
 *     }
 *     $allergyWidget = $this->checkAllergyWarnings($userId);
 *     if ($allergyWidget) { array_unshift($widgets, $allergyWidget); }
 *     return array_slice($widgets, 0, 4);
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SAFETY-CRITICAL — the allergy-warning widget
 * ═══════════════════════════════════════════════════════════════════════
 * `checkAllergyWarnings()` always runs LAST and, when the customer has any
 * recorded `users.drug_allergies`, its `allergy_warning` widget is
 * `array_unshift()`-ed to the FRONT of the (already possibly-4-long) list
 * before the final `array_slice(..., 0, 4)` cap — i.e. it can and does push
 * out a widget that would otherwise have made the cut. Ported exactly via
 * `widgets.unshift(...)` before `.slice(0, 4)`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `getUserAllergies()`/`getUserMedications()` — filter-THEN-trim (not the
 * other way around), and NOT the same split pattern as
 * `PharmacyIntegrationService::parseTextToArray()`
 * ═══════════════════════════════════════════════════════════════════════
 * `classes/ConsultationAnalyzerService.php`'s own `getUserAllergies()` /
 * `getUserMedications()` are DIFFERENT private methods from
 * `PharmacyIntegrationService::parseTextToArray()` (the one
 * `../../medical-history/_lib/medicalHistory.ts` exports) — different
 * split regex (`/[,\n]+/`, no `;`) AND a different operation order:
 * `array_map('trim', array_filter($list))` filters PHP-falsy raw pieces
 * (`''`/`'0'`) FIRST, THEN trims what remains — so a whitespace-only piece
 * (e.g. `" "`) survives the filter (it's non-empty, non-`'0'`) and comes out
 * as `''` in the final array, unlike `parseTextToArray()`'s trim-then-filter
 * order (which would have dropped it). Ported literally via
 * `splitFilterTrim()` below — NOT by reusing `parseTextToArray`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `SHOW COLUMNS FROM business_items` probes are dropped
 * ═══════════════════════════════════════════════════════════════════════
 * Same confirmed schema finding as `../../search-drugs/_lib/searchDrugs.ts`
 * and `../../drug-pricing/_lib/drugPricing.ts`: every column
 * `searchDrugsFromMessage()` probes for (`generic_name`, `name_en`,
 * `active_ingredient`, `manufacturer`, `unit`) is a real, always-present
 * column on `packages/db/src/generated/tenant-db.d.ts`'s `BusinessItems`
 * interface. The `$hasGenericName`/`$hasNameEn`/... flags are therefore
 * always `true` here — all 5 optional columns are always selected.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `searchDrugsFromMessage()` — DELIBERATE DUPLICATION, not cross-imported
 * ═══════════════════════════════════════════════════════════════════════
 * `ConsultationAnalyzerService::searchDrugsFromMessage()` is ALSO needed,
 * independently, by the sibling `interactionsAndAlternatives` builder's
 * `recommendations` route this same round. Per this batch's ownership
 * split (different builder, not the "same builder, same round" precedent
 * that justifies e.g. `check-allergy` importing from `medical-history`),
 * this file ports its OWN independent copy rather than cross-importing from
 * that sibling's directory — see this batch's runbook,
 * `docs/runbooks/phase4-batch6-consultation-widgets-parity.md`, for the
 * cross-reference.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `checkForDrugNames()`'s `$userId` parameter is accepted but never read
 * ═══════════════════════════════════════════════════════════════════════
 * The PHP method signature is `checkForDrugNames(string $message, int
 * $userId): array`, but `$userId` is never referenced in the method body.
 * Dropped from this port's signature (callers here pass only `message`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `checkForDrugNames()`'s break condition is `count($widgets) >= 3`, NOT
 * "limit to 2 drug widgets" (the PHP comment is misleading)
 * ═══════════════════════════════════════════════════════════════════════
 * The loop's own inline comment says `// Limit to 2 drug widgets`, but the
 * actual guard is `if (count($widgets) >= 3) break;` — evaluated AFTER
 * `$widgets` may already contain one `symptom` widget (from
 * `searchDrugsFromMessage()`'s own matches, pushed before this loop even
 * starts). So the loop can add up to 3 `drug_info` widgets if no symptom
 * widget was pushed first, or up to 2 if one was — the total across both
 * sources caps at 3, not "2 drug widgets" specifically. Ported against the
 * literal code, not the comment (same "trust the code over a stale
 * comment" precedent as `../../refill-reminders/route.test.ts`'s resolved
 * brief/PHP contradiction).
 */

// ─────────────────────────────────────────────────────────────────────────
// Shared PHP-semantics helpers
// ─────────────────────────────────────────────────────────────────────────

/** PHP `mb_stripos($haystack, $needle) !== false` — case-insensitive substring test. */
function mbStripos(haystack: string, needle: string): boolean {
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

/** PHP `(int) $v` — non-numeric/null/undefined -> 0. */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `round($x, $precision)` — round-half-away-from-zero. */
function phpRound(value: number, precision: number): number {
  const factor = 10 ** precision;
  return (Math.sign(value) * Math.round(Math.abs(value) * factor)) / factor;
}

/** PHP `empty($v)` for a string value already known to be a `string | null` — true for `null`, `''`, and the exact string `'0'`. */
function isPhpEmptyString(value: string | null): boolean {
  return value === null || value === '' || value === '0';
}

// ─────────────────────────────────────────────────────────────────────────
// Shared widget/action/drug-recommendation types
// ─────────────────────────────────────────────────────────────────────────

export interface ActionItem {
  label: string;
  action: string;
}

export interface DrugRecommendationItem {
  id: number;
  drugId: number;
  name: string;
  nameEn?: string;
  genericName?: string;
  sku: string | null;
  price: number;
  originalPrice: number;
  costPrice?: number;
  margin?: number | null;
  stock: number;
  unit?: string;
  manufacturer?: string;
  category: string;
  dosage: string;
  imageUrl?: string | null;
  matchScore?: number;
}

export interface SymptomWidget {
  type: 'symptom';
  title: string;
  titleEn: string;
  icon: string;
  keyword: string;
  category: string;
  severity?: string;
  recommendations: DrugRecommendationItem[];
  actions: ActionItem[];
}

export interface DrugInfoWidget {
  type: 'drug_info';
  title: string;
  titleEn: string;
  icon: string;
  drugName: string;
  relatedData?: Record<string, unknown>;
  actions: ActionItem[];
  drugId?: number;
  sku?: string | null;
  price?: number;
  stock?: number;
  inStock?: boolean;
  drug?: {
    id: number;
    name: string;
    sku: string | null;
    price: number;
    stock: number;
    description: string | null;
  };
}

export interface InteractionWidget {
  type: 'interaction';
  title: string;
  titleEn: string;
  icon: string;
  currentMedications: string[];
  medicationCount: number;
  actions: ActionItem[];
}

export interface AllergyWidget {
  type: 'allergy';
  title: string;
  titleEn: string;
  icon: string;
  allergies: string[];
  allergyCount: number;
  isAlert: true;
  actions: ActionItem[];
}

export interface PricingWidget {
  type: 'pricing';
  title: string;
  titleEn: string;
  icon: string;
  relatedData: Record<string, unknown>;
  actions: ActionItem[];
}

export interface PregnancyWidget {
  type: 'pregnancy';
  title: string;
  titleEn: string;
  icon: string;
  isAlert: true;
  message: string;
  actions: ActionItem[];
}

export interface AllergyWarningWidget {
  type: 'allergy_warning';
  title: string;
  titleEn: string;
  icon: string;
  allergies: string[];
  allergyCount: number;
  isAlert: true;
  priority: 100;
}

export type ContextWidget =
  | SymptomWidget
  | DrugInfoWidget
  | InteractionWidget
  | AllergyWidget
  | PricingWidget
  | PregnancyWidget
  | AllergyWarningWidget;

// ─────────────────────────────────────────────────────────────────────────
// getActiveKeywords() / getDefaultKeywords()  (lines 494-525)
// ─────────────────────────────────────────────────────────────────────────

type WidgetTypeKey = 'allergy' | 'drug_info' | 'interaction' | 'pregnancy' | 'pricing' | 'symptom';

interface KeywordRow {
  keyword: string;
  keyword_type: 'action' | 'condition' | 'drug' | 'symptom';
  widget_type: WidgetTypeKey;
  related_data: string | null;
  priority: number | null;
}

/** PHP `getDefaultKeywords()` (lines 515-525) — literal, byte-for-byte copy, used both as the getActiveKeywords() DB-error fallback and directly by tests. */
function getDefaultKeywords(): KeywordRow[] {
  return [
    { keyword: 'ปวดหัว', keyword_type: 'symptom', widget_type: 'symptom', related_data: '{"category":"pain"}', priority: 10 },
    { keyword: 'ไข้', keyword_type: 'symptom', widget_type: 'symptom', related_data: '{"category":"fever"}', priority: 15 },
    { keyword: 'ไอ', keyword_type: 'symptom', widget_type: 'symptom', related_data: '{"category":"respiratory"}', priority: 10 },
    { keyword: 'แพ้ยา', keyword_type: 'condition', widget_type: 'allergy', related_data: '{"alert":true}', priority: 20 },
    { keyword: 'ตั้งครรภ์', keyword_type: 'condition', widget_type: 'pregnancy', related_data: '{"alert":true}', priority: 25 },
    { keyword: 'ยาตีกัน', keyword_type: 'action', widget_type: 'interaction', related_data: '{"check_required":true}', priority: 20 },
  ];
}

/** PHP `getActiveKeywords()` (lines 494-509) — swallows PDOException into `getDefaultKeywords()`. */
async function getActiveKeywords(db: Kysely<TenantDB>): Promise<KeywordRow[]> {
  try {
    const result = await sql<KeywordRow>`
      SELECT keyword, keyword_type, widget_type, related_data, priority
      FROM pharmacy_context_keywords
      WHERE is_active = 1
      ORDER BY priority DESC
    `.execute(db);
    return result.rows;
  } catch {
    return getDefaultKeywords();
  }
}

/** PHP `json_decode($keyword['related_data'] ?? '{}', true)` — malformed/missing JSON degrades to `{}`, matching `json_decode`'s `null` return on parse failure feeding into `?? '{}'` upstream of the call in spirit (defensive: this port never throws on bad JSON either). */
function parseRelatedData(raw: string | null): Record<string, unknown> {
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

// ─────────────────────────────────────────────────────────────────────────
// searchDrugsFromMessage()  (lines 938-1107) — deliberate duplicate, see module doc
// ─────────────────────────────────────────────────────────────────────────

interface SearchDrugsFromMessageRow {
  id: number;
  name: string;
  sku: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  description: string | null;
  image_url: string | null;
  generic_name: string | null;
  name_en: string | null;
  active_ingredient: string | null;
  manufacturer: string | null;
  unit: string | null;
}

/** PHP `ConsultationAnalyzerService::searchDrugsFromMessage()` (lines 938-1107). */
export async function searchDrugsFromMessage(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  message: string
): Promise<DrugRecommendationItem[]> {
  const drugs: DrugRecommendationItem[] = [];
  const messageLower = message.toLowerCase();

  // "มี" (availability-query) pattern extraction, then trailing Thai particle strip.
  const availMatch = /มี\s*(.+)/u.exec(message);
  const searchTerm = availMatch ? (availMatch[1] ?? '').trim() : message;
  let searchTermLower = searchTerm.toLowerCase();
  searchTermLower = searchTermLower.replace(/(มั้ย|ไหม|บ้าง|ครับ|ค่ะ|นะ|จ้า|หรือเปล่า)\s*$/u, '');
  searchTermLower = searchTermLower.trim();

  try {
    const result = lineAccountId
      ? await sql<SearchDrugsFromMessageRow>`
          SELECT id, name, sku, price, sale_price, stock, description, image_url,
                 generic_name, name_en, active_ingredient, manufacturer, unit
          FROM business_items
          WHERE is_active = 1
          AND stock > 0
          AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
          ORDER BY stock DESC
          LIMIT 500
        `.execute(db)
      : await sql<SearchDrugsFromMessageRow>`
          SELECT id, name, sku, price, sale_price, stock, description, image_url,
                 generic_name, name_en, active_ingredient, manufacturer, unit
          FROM business_items
          WHERE is_active = 1
          AND stock > 0
          ORDER BY stock DESC
          LIMIT 500
        `.execute(db);

    const allProducts = result.rows;
    const matchedProducts: SearchDrugsFromMessageRow[] = [];
    const matchScores = new Map<number, number>();

    for (const product of allProducts) {
      let score = 0;
      const productNameLower = product.name.toLowerCase();
      const productSku = (product.sku ?? '').toLowerCase();
      const genericName = (product.generic_name ?? '').toLowerCase();
      const nameEn = (product.name_en ?? '').toLowerCase();
      const activeIngredient = (product.active_ingredient ?? '').toLowerCase();

      // Priority 1: exact match with search term
      if (searchTermLower && [...searchTermLower].length >= 2) {
        if (productNameLower.includes(searchTermLower)) score += 100;
        if (nameEn && nameEn.includes(searchTermLower)) score += 100;
        if (genericName && genericName.includes(searchTermLower)) score += 80;
        if (productSku && productSku.includes(searchTermLower)) score += 90;
        if (activeIngredient && activeIngredient.includes(searchTermLower)) score += 70;
      }

      // Priority 2: word boundary match on the product's main (first) name token
      const mainName = productNameLower.split(/[\s\-\/\(\[]+/u)[0] ?? '';
      if ([...mainName].length >= 3 && messageLower.includes(mainName)) score += 50;

      // Priority 3: English name words
      if (nameEn) {
        for (const rawWord of nameEn.split(/[\s\-\/\(\)\[\]]+/u)) {
          const word = rawWord.trim();
          if ([...word].length >= 3 && messageLower.includes(word)) {
            score += 40;
            break;
          }
        }
      }

      // Priority 4: generic name words
      if (genericName) {
        for (const rawWord of genericName.split(/[\s\-\/\(\)\[\]]+/u)) {
          const word = rawWord.trim();
          if ([...word].length >= 3 && messageLower.includes(word)) {
            score += 30;
            break;
          }
        }
      }

      // Priority 5: any significant word from the product name
      for (const rawWord of productNameLower.split(/[\s\-\/\(\)\[\]]+/u)) {
        const word = rawWord.trim();
        if ([...word].length >= 4 && messageLower.includes(word)) {
          score += 20;
          break;
        }
      }

      if (score > 0) {
        matchedProducts.push(product);
        matchScores.set(product.id, score);
      }
    }

    // Sort by score descending (PHP8+ usort is stable; JS sort is stable since ES2019).
    matchedProducts.sort((a, b) => (matchScores.get(b.id) ?? 0) - (matchScores.get(a.id) ?? 0));

    const seenIds = new Set<number>();
    for (const product of matchedProducts) {
      if (seenIds.has(product.id)) continue;
      seenIds.add(product.id);

      const price = toFloatOrZero(product.sale_price ?? product.price ?? 0);
      const cost = price * 0.7;
      const margin = price > 0 ? phpRound(((price - cost) / price) * 100, 1) : null;

      drugs.push({
        id: toIntOrZero(product.id),
        drugId: toIntOrZero(product.id),
        name: product.name,
        nameEn: product.name_en ?? '',
        genericName: product.generic_name ?? '',
        sku: product.sku,
        price,
        originalPrice: toFloatOrZero(product.price ?? 0),
        costPrice: cost,
        margin,
        stock: toIntOrZero(product.stock ?? 0),
        unit: product.unit ?? '',
        manufacturer: product.manufacturer ?? '',
        category: 'ยาทั่วไป',
        dosage: product.description ?? '',
        imageUrl: product.image_url,
        matchScore: matchScores.get(product.id) ?? 0,
      });

      if (drugs.length >= 5) break;
    }
  } catch {
    // ConsultationAnalyzer searchDrugsFromMessage error — PHP swallows via error_log().
  }

  return drugs;
}

// ─────────────────────────────────────────────────────────────────────────
// getSymptomRecommendations() / getPopularDrugs()  (lines 599-727)
// ─────────────────────────────────────────────────────────────────────────

const CATEGORY_KEYWORDS: Record<string, string[]> = {
  pain: ['paracetamol', 'ibuprofen', 'พาราเซตามอล', 'ไอบูโพรเฟน', 'แก้ปวด'],
  fever: ['paracetamol', 'พาราเซตามอล', 'ลดไข้'],
  respiratory: ['แก้ไอ', 'ลดน้ำมูก', 'cough', 'cold'],
  digestive: ['ธาตุน้ำขาว', 'แก้ท้องเสีย', 'antacid'],
  skin: ['แก้แพ้', 'antihistamine', 'calamine'],
  general: ['paracetamol', 'vitamin', 'พาราเซตามอล', 'วิตามิน'],
};

interface SymptomRecommendationRow {
  id: number;
  name: string;
  sku: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  description: string | null;
  image_url: string | null;
  category: string | null;
}

function toSimpleRecommendation(drug: SymptomRecommendationRow): DrugRecommendationItem {
  return {
    id: toIntOrZero(drug.id),
    drugId: toIntOrZero(drug.id),
    name: drug.name,
    sku: drug.sku,
    price: toFloatOrZero(drug.sale_price ?? drug.price ?? 0),
    originalPrice: toFloatOrZero(drug.price ?? 0),
    stock: toIntOrZero(drug.stock ?? 0),
    category: drug.category ?? 'ยาทั่วไป',
    dosage: drug.description ?? '',
  };
}

/** PHP `getPopularDrugs()` (lines 678-727) — swallows PDOException into `[]`. */
async function getPopularDrugs(db: Kysely<TenantDB>, lineAccountId: number, limit = 5): Promise<DrugRecommendationItem[]> {
  try {
    const result = lineAccountId
      ? await sql<SymptomRecommendationRow>`
          SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, bi.stock, bi.description, bi.image_url, ic.name as category
          FROM business_items bi
          LEFT JOIN item_categories ic ON bi.category_id = ic.id
          WHERE bi.is_active = 1 AND bi.stock > 0
          AND (bi.line_account_id = ${lineAccountId} OR bi.line_account_id IS NULL)
          ORDER BY bi.stock DESC, bi.name ASC LIMIT ${limit}
        `.execute(db)
      : await sql<SymptomRecommendationRow>`
          SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, bi.stock, bi.description, bi.image_url, ic.name as category
          FROM business_items bi
          LEFT JOIN item_categories ic ON bi.category_id = ic.id
          WHERE bi.is_active = 1 AND bi.stock > 0
          ORDER BY bi.stock DESC, bi.name ASC LIMIT ${limit}
        `.execute(db);

    return result.rows.map(toSimpleRecommendation);
  } catch {
    return [];
  }
}

/** PHP `getSymptomRecommendations()` (lines 599-671) — falls back to `getPopularDrugs(3)` both on no-match and on DB error. */
async function getSymptomRecommendations(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  category: string
): Promise<DrugRecommendationItem[]> {
  const keywords = CATEGORY_KEYWORDS[category] ?? CATEGORY_KEYWORDS.general ?? [];

  try {
    const conditions = keywords.map((keyword) => {
      const term = `%${keyword}%`;
      return sql<SqlBool>`(bi.name LIKE ${term} OR bi.description LIKE ${term})`;
    });
    const whereClause = sql.join(conditions, sql` OR `);

    const result = lineAccountId
      ? await sql<SymptomRecommendationRow>`
          SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, bi.stock, bi.description, bi.image_url, ic.name as category
          FROM business_items bi
          LEFT JOIN item_categories ic ON bi.category_id = ic.id
          WHERE bi.is_active = 1 AND bi.stock > 0 AND (${whereClause})
          AND (bi.line_account_id = ${lineAccountId} OR bi.line_account_id IS NULL)
          ORDER BY bi.stock DESC LIMIT 5
        `.execute(db)
      : await sql<SymptomRecommendationRow>`
          SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, bi.stock, bi.description, bi.image_url, ic.name as category
          FROM business_items bi
          LEFT JOIN item_categories ic ON bi.category_id = ic.id
          WHERE bi.is_active = 1 AND bi.stock > 0 AND (${whereClause})
          ORDER BY bi.stock DESC LIMIT 5
        `.execute(db);

    const recommendations = result.rows.map(toSimpleRecommendation);

    if (recommendations.length === 0) {
      return getPopularDrugs(db, lineAccountId, 3);
    }
    return recommendations;
  } catch {
    return getPopularDrugs(db, lineAccountId, 3);
  }
}

// ─────────────────────────────────────────────────────────────────────────
// buildWidget() dispatch + the 6 build*Widget() builders  (lines 536-843)
// ─────────────────────────────────────────────────────────────────────────

/** PHP `buildSymptomWidget()` (lines 570-592) — `$message` param is accepted by PHP but never used in the body; dropped here. */
async function buildSymptomWidget(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  keyword: KeywordRow,
  relatedData: Record<string, unknown>
): Promise<SymptomWidget> {
  const category = typeof relatedData.category === 'string' ? relatedData.category : 'general';
  const severity = typeof relatedData.severity === 'string' ? relatedData.severity : 'mild';
  const recommendations = await getSymptomRecommendations(db, lineAccountId, category);

  return {
    type: 'symptom',
    title: 'แนะนำยาสำหรับอาการ',
    titleEn: 'Drug Recommendations',
    icon: '💊',
    keyword: keyword.keyword,
    category,
    severity,
    recommendations,
    actions: [
      { label: 'ดูรายละเอียด', action: 'view_recommendations' },
      { label: 'ตรวจสอบยาตีกัน', action: 'check_interactions' },
    ],
  };
}

/** PHP `buildDrugInfoWidget()` (lines 736-751) — pure, no DB. */
function buildDrugInfoWidget(keyword: KeywordRow, relatedData: Record<string, unknown>): DrugInfoWidget {
  return {
    type: 'drug_info',
    title: 'ข้อมูลยา',
    titleEn: 'Drug Information',
    icon: '💊',
    drugName: keyword.keyword,
    relatedData,
    actions: [
      { label: 'ดูรายละเอียด', action: 'view_drug_info' },
      { label: 'ตรวจสอบยาตีกัน', action: 'check_interactions' },
      { label: 'ดูราคา', action: 'view_pricing' },
    ],
  };
}

/** PHP `splitOnCommaNewline()`-equivalent used by `getUserAllergies()`/`getUserMedications()` — see module doc for the filter-then-trim order and the different regex from `parseTextToArray()`. */
function splitFilterTrim(text: string): string[] {
  const pieces = text.split(/[,\n]+/);
  return pieces.filter((piece) => !isPhpEmptyString(piece)).map((piece) => piece.trim());
}

/** PHP `getUserAllergies()` (lines 1364-1380) — swallows PDOException into `[]`. */
async function getUserAllergies(db: Kysely<TenantDB>, userId: number): Promise<string[]> {
  try {
    const result = await sql<{ drug_allergies: string | null }>`
      SELECT drug_allergies FROM users WHERE id = ${userId}
    `.execute(db);
    const user = result.rows[0];
    if (user && !isPhpEmptyString(user.drug_allergies)) {
      return splitFilterTrim(user.drug_allergies as string);
    }
  } catch {
    // ConsultationAnalyzer getUserAllergies error — PHP swallows via error_log().
  }
  return [];
}

/** PHP `getUserMedications()` (lines 1387-1403) — swallows PDOException into `[]`. */
async function getUserMedications(db: Kysely<TenantDB>, userId: number): Promise<string[]> {
  try {
    const result = await sql<{ current_medications: string | null }>`
      SELECT current_medications FROM users WHERE id = ${userId}
    `.execute(db);
    const user = result.rows[0];
    if (user && !isPhpEmptyString(user.current_medications)) {
      return splitFilterTrim(user.current_medications as string);
    }
  } catch {
    // ConsultationAnalyzer getUserMedications error — PHP swallows via error_log().
  }
  return [];
}

/** PHP `buildInteractionWidget()` (lines 758-775). */
async function buildInteractionWidget(db: Kysely<TenantDB>, userId: number): Promise<InteractionWidget> {
  const medications = await getUserMedications(db, userId);
  return {
    type: 'interaction',
    title: 'ตรวจสอบยาตีกัน',
    titleEn: 'Drug Interaction Checker',
    icon: '⚠️',
    currentMedications: medications,
    medicationCount: medications.length,
    actions: [
      { label: 'ตรวจสอบ', action: 'check_interactions' },
      { label: 'เพิ่มยา', action: 'add_medication' },
    ],
  };
}

/** PHP `buildAllergyWidget()` (lines 782-803) — `null` when the user has no recorded allergies. */
async function buildAllergyWidget(db: Kysely<TenantDB>, userId: number): Promise<AllergyWidget | null> {
  const allergies = await getUserAllergies(db, userId);
  if (allergies.length === 0) return null;

  return {
    type: 'allergy',
    title: '⚠️ แพ้ยา',
    titleEn: 'Drug Allergies',
    icon: '🚨',
    allergies,
    allergyCount: allergies.length,
    isAlert: true,
    actions: [
      { label: 'ดูรายละเอียด', action: 'view_allergies' },
      { label: 'แก้ไข', action: 'edit_allergies' },
    ],
  };
}

/** PHP `buildPricingWidget()` (lines 810-823) — pure, no DB. */
function buildPricingWidget(relatedData: Record<string, unknown>): PricingWidget {
  return {
    type: 'pricing',
    title: 'ราคาและส่วนลด',
    titleEn: 'Pricing & Discounts',
    icon: '💰',
    relatedData,
    actions: [
      { label: 'คำนวณส่วนลด', action: 'calculate_discount' },
      { label: 'ดูกำไร', action: 'view_margin' },
    ],
  };
}

/** PHP `buildPregnancyWidget()` (lines 829-843) — pure, no DB. */
function buildPregnancyWidget(): PregnancyWidget {
  return {
    type: 'pregnancy',
    title: '🤰 ยาปลอดภัยสำหรับคนท้อง',
    titleEn: 'Pregnancy-Safe Drugs',
    icon: '🤰',
    isAlert: true,
    message: 'กรุณาตรวจสอบความปลอดภัยของยาก่อนแนะนำ',
    actions: [
      { label: 'ดูยาที่ปลอดภัย', action: 'view_safe_drugs' },
      { label: 'ปรึกษาเภสัชกร', action: 'consult_pharmacist' },
    ],
  };
}

/** PHP `buildWidget()` (lines 536-560) — dispatch by `widget_type`. */
async function buildWidget(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  widgetType: WidgetTypeKey,
  keyword: KeywordRow,
  relatedData: Record<string, unknown>,
  userId: number
): Promise<ContextWidget | null> {
  switch (widgetType) {
    case 'symptom':
      return buildSymptomWidget(db, lineAccountId, keyword, relatedData);
    case 'drug_info':
      return buildDrugInfoWidget(keyword, relatedData);
    case 'interaction':
      return buildInteractionWidget(db, userId);
    case 'allergy':
      return buildAllergyWidget(db, userId);
    case 'pricing':
      return buildPricingWidget(relatedData);
    case 'pregnancy':
      return buildPregnancyWidget();
    default:
      return null;
  }
}

// ─────────────────────────────────────────────────────────────────────────
// checkForDrugNames()  (lines 851-928)
// ─────────────────────────────────────────────────────────────────────────

interface CheckDrugNameRow {
  id: number;
  name: string;
  sku: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  description: string | null;
}

/** PHP `checkForDrugNames()` (lines 851-928) — see module doc for the dropped-but-unused `$userId` param and the literal `count($widgets) >= 3` break condition. */
async function checkForDrugNames(db: Kysely<TenantDB>, lineAccountId: number, message: string): Promise<ContextWidget[]> {
  const widgets: ContextWidget[] = [];
  const messageLower = message.toLowerCase();

  try {
    const matchedDrugs = await searchDrugsFromMessage(db, lineAccountId, message);
    if (matchedDrugs.length > 0) {
      widgets.push({
        type: 'symptom',
        title: 'แนะนำยาจากข้อความ',
        titleEn: 'Drug Recommendations',
        icon: '💊',
        keyword: 'ค้นหาจากข้อความ',
        category: 'search',
        recommendations: matchedDrugs,
        actions: [
          { label: 'ดูรายละเอียด', action: 'view_recommendations' },
          { label: 'ตรวจสอบยาตีกัน', action: 'check_interactions' },
        ],
      });
    }

    const result = await sql<CheckDrugNameRow>`
      SELECT id, name, sku, price, sale_price, stock, description
      FROM business_items
      WHERE is_active = 1
      AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
      LIMIT 200
    `.execute(db);

    for (const product of result.rows) {
      const nameLower = product.name.toLowerCase();
      if ([...nameLower].length >= 3 && messageLower.includes(nameLower)) {
        const price = toFloatOrZero(product.sale_price ?? product.price ?? 0);
        const stock = toIntOrZero(product.stock ?? 0);

        widgets.push({
          type: 'drug_info',
          title: `ข้อมูลยา: ${product.name}`,
          titleEn: `Drug Info: ${product.name}`,
          icon: '💊',
          drugId: toIntOrZero(product.id),
          drugName: product.name,
          sku: product.sku,
          price,
          stock,
          inStock: stock > 0,
          drug: {
            id: toIntOrZero(product.id),
            name: product.name,
            sku: product.sku,
            price,
            stock,
            description: product.description,
          },
          actions: [
            { label: 'ดูรายละเอียด', action: 'view_drug_info' },
            { label: 'ตรวจสอบยาตีกัน', action: 'check_interactions' },
          ],
        });

        // Literal PHP guard: `if (count($widgets) >= 3) break;` — see module doc.
        if (widgets.length >= 3) break;
      }
    }
  } catch {
    // ConsultationAnalyzer checkForDrugNames error — PHP swallows via error_log(), returns whatever was collected so far.
  }

  return widgets;
}

// ─────────────────────────────────────────────────────────────────────────
// checkAllergyWarnings()  (lines 1339-1357)
// ─────────────────────────────────────────────────────────────────────────

/** PHP `checkAllergyWarnings()` (lines 1339-1357) — `null` when the user has no recorded allergies. */
async function checkAllergyWarnings(db: Kysely<TenantDB>, userId: number): Promise<AllergyWarningWidget | null> {
  const allergies = await getUserAllergies(db, userId);
  if (allergies.length === 0) return null;

  return {
    type: 'allergy_warning',
    title: '⚠️ ลูกค้าแพ้ยา',
    titleEn: 'Customer Has Drug Allergies',
    icon: '🚨',
    allergies,
    allergyCount: allergies.length,
    isAlert: true,
    priority: 100,
  };
}

// ─────────────────────────────────────────────────────────────────────────
// getContextWidgets()  (lines 432-488) — the exported entry point
// ─────────────────────────────────────────────────────────────────────────

export async function getContextWidgets(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  message: string,
  userId: number
): Promise<ContextWidget[]> {
  const widgets: ContextWidget[] = [];
  const messageLower = message.toLowerCase();

  const keywords = await getActiveKeywords(db);
  const matchedKeywords = keywords.filter((k) => mbStripos(messageLower, k.keyword));

  // usort() by priority descending — stable in PHP 8.0+ (this repo targets PHP 8.0+), matching JS's stable Array.sort.
  const sortedMatches = [...matchedKeywords].sort((a, b) => (b.priority ?? 0) - (a.priority ?? 0));

  for (const keyword of sortedMatches) {
    const widgetType = keyword.widget_type;
    const relatedData = parseRelatedData(keyword.related_data);

    const existingTypes = new Set(widgets.map((w) => w.type));
    if (existingTypes.has(widgetType)) continue;

    const widget = await buildWidget(db, lineAccountId, widgetType, keyword, relatedData, userId);
    if (widget) widgets.push(widget);
  }

  const drugWidgets = await checkForDrugNames(db, lineAccountId, message);
  for (const drugWidget of drugWidgets) {
    const existingTypes = new Set(widgets.map((w) => w.type));
    if (!existingTypes.has(drugWidget.type)) {
      widgets.push(drugWidget);
    }
  }

  // SAFETY-CRITICAL: always shown first when present — see module doc.
  const allergyWidget = await checkAllergyWarnings(db, userId);
  if (allergyWidget) {
    widgets.unshift(allergyWidget);
  }

  return widgets.slice(0, 4);
}
