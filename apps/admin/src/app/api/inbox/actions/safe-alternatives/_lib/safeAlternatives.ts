import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getAllergies, type AllergyEntry } from '../../check-drug-interactions/_lib/customerHealthEngine';

/**
 * safeAlternatives.ts — port of `classes/DrugRecommendEngineService.php`'s
 * `getSafeAlternatives()` (lines 798-889) and its private helpers
 * `getSimilarDrugs()` (889-928), `calculateSimilarity()` (930-956),
 * `getDrugDetails()` (1004-1023), `getUserConditions()` (1092-1114),
 * `checkAllergyMatch()` (1116-1150), and `checkConditionSafety()`
 * (1151-1206), as driven by api/inbox-v2.php's `case 'safe_alternatives':
 * case 'safe-alternatives': case 'get_safe_alternatives':` (lines ~1478-1508).
 *
 * ```php
 * public function getSafeAlternatives(int $drugId, int $userId): array
 * {
 *     $alternatives = [];
 *     $originalDrug = $this->getDrugDetails($drugId);
 *     if (!$originalDrug) {
 *         return ['alternatives' => [], 'originalDrug' => null, 'reason' => 'Original drug not found'];
 *     }
 *     $allergies = $this->getUserAllergies($userId);
 *     $conditions = $this->getUserConditions($userId);
 *
 *     $originalSafe = true;
 *     $unsafeReasons = [];
 *     $allergyMatch = $this->checkAllergyMatch($originalDrug, $allergies);
 *     if ($allergyMatch['hasMatch']) {
 *         $originalSafe = false;
 *         $unsafeReasons[] = "แพ้ยา: " . implode(', ', $allergyMatch['matchedAllergies']);
 *     }
 *
 *     $categoryId = $originalDrug['category_id'] ?? null;
 *     $similarDrugs = $this->getSimilarDrugs($drugId, $categoryId, 10);
 *
 *     foreach ($similarDrugs as $drug) {
 *         if (($drug['stock'] ?? 0) <= 0) { continue; }
 *         $allergyCheck = $this->checkAllergyMatch($drug, $allergies);
 *         if ($allergyCheck['hasMatch']) { continue; }
 *         $conditionSafety = $this->checkConditionSafety($drug, $userId);
 *         $alternatives[] = [
 *             'drugId' => $drug['id'], 'name' => $drug['name'], 'genericName' => $drug['generic_name'] ?? null,
 *             'price' => (float)($drug['sale_price'] ?? $drug['price'] ?? 0), 'stock' => (int)($drug['stock'] ?? 0),
 *             'imageUrl' => $drug['image_url'] ?? null, 'isSafeForConditions' => $conditionSafety['isSafe'],
 *             'conditionWarnings' => $conditionSafety['warnings'], 'similarity' => $this->calculateSimilarity($originalDrug, $drug)
 *         ];
 *         if (count($alternatives) >= 5) { break; }
 *     }
 *
 *     usort($alternatives, function($a, $b) { return $b['similarity'] - $a['similarity']; });
 *
 *     return [
 *         'alternatives' => $alternatives,
 *         'originalDrug' => ['id' => $originalDrug['id'], 'name' => $originalDrug['name'], 'isSafe' => $originalSafe, 'unsafeReasons' => $unsafeReasons],
 *         'userId' => $userId, 'allergiesChecked' => $allergies, 'conditionsChecked' => $conditions
 *     ];
 * }
 * ```
 *
 * `getUserAllergies()` — see this batch's runbook: `setHealthEngine()` is
 * always called in production, so this routes straight through the
 * canonical `../../check-drug-interactions/_lib/customerHealthEngine.ts`'s
 * `getAllergies()` rather than reimplementing `DrugRecommendEngineService`'s
 * own dead-code direct-query fallback.
 *
 * ```php
 * private function getSimilarDrugs(int $excludeDrugId, ?int $categoryId, int $limit = 10): array
 * {
 *     $sql = "SELECT bi.*, ic.name as category_name FROM business_items bi
 *             LEFT JOIN item_categories ic ON bi.category_id = ic.id
 *             WHERE bi.is_active = 1 AND bi.id != ? AND bi.stock > 0";
 *     $params = [$excludeDrugId];
 *     if ($categoryId) { $sql .= " AND bi.category_id = ?"; $params[] = $categoryId; }
 *     if ($this->lineAccountId) { $sql .= " AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)"; $params[] = $this->lineAccountId; }
 *     $sql .= " ORDER BY bi.stock DESC LIMIT ?"; $params[] = $limit;
 *     ...
 * }
 *
 * private function getDrugDetails(int $drugId): ?array
 * {
 *     $stmt = $this->db->prepare("SELECT bi.*, ic.name as category_name FROM business_items bi
 *         LEFT JOIN item_categories ic ON bi.category_id = ic.id WHERE bi.id = ?");
 *     ...
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `bi.*` -> explicit column list; `category_name` dropped (never read)
 * ═══════════════════════════════════════════════════════════════════════
 * Same "explicit column list instead of `SELECT *`" convention already
 * established by `../../drug-info/_lib/drugInfo.ts` (Phase 4 batch 4a).
 * Only the fields this action's own consumers actually read are selected:
 * `id`/`name`/`generic_name`/`description`/`price`/`sale_price`/`stock`/
 * `image_url`/`category_id`. `category_name` (from the `LEFT JOIN
 * item_categories`) is selected by the literal PHP query but never read by
 * `getSafeAlternatives()`'s own consumption of either `getSimilarDrugs()`'s
 * or `getDrugDetails()`'s rows — the join is dropped entirely (it cannot
 * introduce duplicate/filtered rows, being a `LEFT JOIN` on a PK match, so
 * this changes nothing observable). No `is_prescription`/`requires_prescription`
 * schema-drift fix is needed here either — this action never reads that
 * column (unlike `../drug-card/_lib/drugCard.ts`, which does).
 *
 * ```php
 * private function calculateSimilarity(array $drug1, array $drug2): float
 * {
 *     $score = 0;
 *     if (($drug1['category_id'] ?? 0) === ($drug2['category_id'] ?? 0)) { $score += 40; }
 *     $price1 = (float)($drug1['price'] ?? 0); $price2 = (float)($drug2['price'] ?? 0);
 *     if ($price1 > 0 && $price2 > 0) {
 *         $priceDiff = abs($price1 - $price2) / max($price1, $price2);
 *         if ($priceDiff <= 0.3) { $score += 30 * (1 - $priceDiff); }
 *     }
 *     $name1 = strtolower($drug1['name'] ?? ''); $name2 = strtolower($drug2['name'] ?? '');
 *     similar_text($name1, $name2, $nameSimilarity);
 *     $score += $nameSimilarity * 0.3;
 *     return round($score, 2);
 * }
 * ```
 *
 * SAFETY NOTE — `similar_text($name1, $name2, $nameSimilarity)`'s third,
 * by-reference argument receives PHP's PERCENTAGE match (0-100), not the
 * raw matched-character count (which is what the function's own RETURN
 * value would be, but that return value is discarded here — no `$x =`
 * assignment). `similarTextPercent()` below is a faithful port of PHP's
 * built-in `similar_text()` algorithm (a longest-common-substring find,
 * recursing on both remainders) — NOT Levenshtein, NOT a naive substring
 * check. Validated against the PHP manual's own documented example:
 * `similar_text('World', 'Word')` matches 4 characters, percent ≈ 88.89%
 * (`route.test.ts` asserts this exact value). Getting this wrong silently
 * reorders which alternative a pharmacist sees first — this function's
 * result feeds `usort($alternatives, ... $b['similarity'] - $a['similarity'])`
 * directly.
 *
 * ```php
 * private function getUserConditions(int $userId): array
 * {
 *     $stmt = $this->db->prepare("SELECT medical_conditions FROM users WHERE id = ?");
 *     ...
 *     if ($user && !empty($user['medical_conditions'])) {
 *         $conditionList = preg_split('/[,\n]+/', $user['medical_conditions']);
 *         return array_map('trim', array_filter($conditionList));
 *     }
 *     return [];
 * }
 *
 * private function checkAllergyMatch(array $drug, array $allergies): array
 * {
 *     $matchedAllergies = [];
 *     $drugName = strtolower($drug['name'] ?? ''); $genericName = strtolower($drug['generic_name'] ?? ''); $description = strtolower($drug['description'] ?? '');
 *     foreach ($allergies as $allergy) {
 *         $allergyName = strtolower($allergy['name'] ?? $allergy);
 *         if (stripos($drugName, $allergyName) !== false || stripos($genericName, $allergyName) !== false ||
 *             stripos($allergyName, $drugName) !== false || stripos($allergyName, $genericName) !== false ||
 *             stripos($description, $allergyName) !== false) {
 *             $matchedAllergies[] = $allergy['name'] ?? $allergy;
 *         }
 *     }
 *     return ['hasMatch' => !empty($matchedAllergies), 'matchedAllergies' => $matchedAllergies];
 * }
 *
 * private function checkConditionSafety(array $drug, int $userId): array
 * {
 *     $conditions = $this->getUserConditions($userId);
 *     $warnings = [];
 *     $conditionWarnings = [ ... 12 Thai/English condition-keyword => [dangerous drug keywords] pairs ... ];
 *     $drugName = strtolower($drug['name'] ?? ''); $genericName = strtolower($drug['generic_name'] ?? ''); $description = strtolower($drug['description'] ?? '');
 *     foreach ($conditions as $condition) {
 *         $conditionLower = strtolower($condition);
 *         foreach ($conditionWarnings as $condKey => $dangerousDrugs) {
 *             if (stripos($conditionLower, $condKey) !== false) {
 *                 foreach ($dangerousDrugs as $dangerous) {
 *                     if (stripos($drugName, $dangerous) !== false || stripos($genericName, $dangerous) !== false || stripos($description, $dangerous) !== false) {
 *                         $warnings[] = "ควรระวังการใช้กับผู้ป่วย {$condition}";
 *                         break 2;
 *                     }
 *                 }
 *             }
 *         }
 *     }
 *     return ['isSafe' => empty($warnings), 'warnings' => $warnings, 'conditionsChecked' => $conditions];
 * }
 * ```
 *
 * `break 2` breaks out of BOTH the inner `$dangerousDrugs` loop AND the
 * `$conditionWarnings` loop for the CURRENT `$condition` — at most one
 * warning is ever pushed per user condition, even if it matches several
 * `conditionWarnings` keys. Ported via a labeled-equivalent early
 * `continue` to the outer `for...of conditions` loop.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * PRESERVED PHP QUIRK — `stripos($haystack, '')` matches (empty-needle
 * landmine) in `checkAllergyMatch()`
 * ═══════════════════════════════════════════════════════════════════════
 * As of PHP 8.0, `strpos()`/`stripos()` accept an empty-string needle and
 * return `0` (a match at position 0) rather than `false`. `checkAllergyMatch()`'s
 * 4th disjunct, `stripos($allergyName, $genericName) !== false`, therefore
 * evaluates to `0 !== false` = `true` WHENEVER `$genericName` is empty
 * (`$drug['generic_name']` is `null`/`''`) — REGARDLESS of what the
 * allergy actually is. Since most `business_items` rows have no
 * `generic_name` populated, this means: any time a customer has at least
 * one allergy on file, a drug lacking a `generic_name` is reported as an
 * allergy match for THAT allergy, even when the names are unrelated. This
 * is a genuine, reachable property of the literal PHP source (not a typo
 * or a schema-drift bug) — it is PRESERVED here, not "fixed", per this
 * batch's literal-port mandate; `ciIncludes()` below reproduces the same
 * empty-needle-always-matches semantic for exactly this reason. `route.test.ts`
 * exercises the "no allergies on file" path for the main ordering/exclusion
 * assertions (where this quirk cannot fire) and separately documents the
 * quirk itself.
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

/** PHP `(int) $v` on a DB column value — non-numeric/null/undefined -> 0. */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/** PHP `empty($v)` for a string value. */
function isPhpEmptyString(value: string | null | undefined): boolean {
  return value === null || value === undefined || value === '' || value === '0';
}

/** `stripos($a, $b) !== false` — case-insensitive substring search. */
function ciIncludes(haystack: string, needle: string): boolean {
  if (needle === '') return true;
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

// ─────────────────────────────────────────────────────────────────────────
// similar_text() — faithful port of PHP's built-in algorithm
// ─────────────────────────────────────────────────────────────────────────

/**
 * PHP's `php_similar_str()`: find the longest common substring, then
 * recurse on the unmatched left and right remainders. Returns the total
 * matched-character count (this is `similar_text()`'s own RETURN value,
 * not the percentage).
 */
function phpSimilarChar(s1: string, s2: string): number {
  const len1 = s1.length;
  const len2 = s2.length;
  let max = 0;
  let posA = 0;
  let posB = 0;

  for (let p = 0; p < len1; p++) {
    for (let q = 0; q < len2; q++) {
      let l = 0;
      while (p + l < len1 && q + l < len2 && s1[p + l] === s2[q + l]) {
        l++;
      }
      if (l > max) {
        max = l;
        posA = p;
        posB = q;
      }
    }
  }

  if (max === 0) return 0;

  let sum = max;
  if (posA > 0 && posB > 0) {
    sum += phpSimilarChar(s1.slice(0, posA), s2.slice(0, posB));
  }
  if (posA + max < len1 && posB + max < len2) {
    sum += phpSimilarChar(s1.slice(posA + max), s2.slice(posB + max));
  }
  return sum;
}

/**
 * `similar_text($s1, $s2, $percent)`'s by-reference `$percent` output —
 * `matchedChars * 2 / (len1 + len2) * 100`. Validated against PHP's own
 * manual example: `similarTextPercent('World', 'Word')` === `800/9` ≈
 * 88.888...%.
 */
export function similarTextPercent(s1: string, s2: string): number {
  const total = s1.length + s2.length;
  if (total === 0) return 0;
  const matched = phpSimilarChar(s1, s2);
  return (matched * 2 * 100) / total;
}

// ─────────────────────────────────────────────────────────────────────────
// getUserConditions()
// ─────────────────────────────────────────────────────────────────────────

interface MedicalConditionsRow {
  medical_conditions: string | null;
}

export async function getUserConditions(db: Kysely<TenantDB>, userId: number): Promise<string[]> {
  const result = await sql<MedicalConditionsRow>`SELECT medical_conditions FROM users WHERE id = ${userId}`.execute(db);
  const user = result.rows[0];
  if (user && !isPhpEmptyString(user.medical_conditions)) {
    return (user.medical_conditions as string)
      .split(/[,\n]+/)
      .map((c) => c.trim())
      .filter((c) => c !== '' && c !== '0');
  }
  return [];
}

// ─────────────────────────────────────────────────────────────────────────
// checkAllergyMatch() / checkConditionSafety()
// ─────────────────────────────────────────────────────────────────────────

export interface DrugLike {
  name: string | null;
  generic_name?: string | null;
  description?: string | null;
}

/** `$allergy['name'] ?? $allergy` — this port's `AllergyEntry` always carries a `.name`. */
function allergyName(allergy: AllergyEntry): string {
  return allergy.name;
}

export interface AllergyMatchResult {
  hasMatch: boolean;
  matchedAllergies: string[];
}

export function checkAllergyMatch(drug: DrugLike, allergies: AllergyEntry[]): AllergyMatchResult {
  const matchedAllergies: string[] = [];
  const drugName = (drug.name ?? '').toLowerCase();
  const genericName = (drug.generic_name ?? '').toLowerCase();
  const description = (drug.description ?? '').toLowerCase();

  for (const allergy of allergies) {
    const allergyNameValue = allergyName(allergy);
    const allergyLower = allergyNameValue.toLowerCase();

    if (
      ciIncludes(drugName, allergyLower) ||
      ciIncludes(genericName, allergyLower) ||
      ciIncludes(allergyLower, drugName) ||
      ciIncludes(allergyLower, genericName) ||
      ciIncludes(description, allergyLower)
    ) {
      matchedAllergies.push(allergyNameValue);
    }
  }

  return { hasMatch: matchedAllergies.length > 0, matchedAllergies };
}

const CONDITION_WARNINGS: readonly (readonly [string, readonly string[]])[] = [
  ['เบาหวาน', ['sugar', 'glucose', 'syrup']],
  ['diabetes', ['sugar', 'glucose', 'syrup']],
  ['ความดันสูง', ['sodium', 'nsaid', 'ibuprofen']],
  ['hypertension', ['sodium', 'nsaid', 'ibuprofen']],
  ['ไต', ['nsaid', 'ibuprofen', 'aspirin']],
  ['kidney', ['nsaid', 'ibuprofen', 'aspirin']],
  ['ตับ', ['paracetamol', 'acetaminophen']],
  ['liver', ['paracetamol', 'acetaminophen']],
  ['หอบหืด', ['aspirin', 'nsaid', 'beta-blocker']],
  ['asthma', ['aspirin', 'nsaid', 'beta-blocker']],
  ['ตั้งครรภ์', ['nsaid', 'aspirin', 'ibuprofen', 'warfarin']],
  ['pregnancy', ['nsaid', 'aspirin', 'ibuprofen', 'warfarin']],
];

export interface ConditionSafetyResult {
  isSafe: boolean;
  warnings: string[];
  conditionsChecked: string[];
}

export async function checkConditionSafety(db: Kysely<TenantDB>, drug: DrugLike, userId: number): Promise<ConditionSafetyResult> {
  const conditions = await getUserConditions(db, userId);
  const warnings: string[] = [];

  const drugName = (drug.name ?? '').toLowerCase();
  const genericName = (drug.generic_name ?? '').toLowerCase();
  const description = (drug.description ?? '').toLowerCase();

  conditionLoop: for (const condition of conditions) {
    const conditionLower = condition.toLowerCase();
    for (const [condKey, dangerousDrugs] of CONDITION_WARNINGS) {
      if (ciIncludes(conditionLower, condKey)) {
        for (const dangerous of dangerousDrugs) {
          if (ciIncludes(drugName, dangerous) || ciIncludes(genericName, dangerous) || ciIncludes(description, dangerous)) {
            warnings.push(`ควรระวังการใช้กับผู้ป่วย ${condition}`);
            continue conditionLoop; // PHP's `break 2` — at most one warning per condition.
          }
        }
      }
    }
  }

  return { isSafe: warnings.length === 0, warnings, conditionsChecked: conditions };
}

// ─────────────────────────────────────────────────────────────────────────
// getDrugDetails() / getSimilarDrugs()
// ─────────────────────────────────────────────────────────────────────────

interface SafeAltDrugRow {
  id: number;
  name: string;
  generic_name: string | null;
  description: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  image_url: string | null;
  category_id: number | null;
}

export interface OriginalDrugDetails {
  id: number;
  name: string;
  generic_name: string | null;
  description: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  image_url: string | null;
  category_id: number | null;
}

const SELECT_COLUMNS = `bi.id, bi.name, bi.generic_name, bi.description, bi.price, bi.sale_price, bi.stock, bi.image_url, bi.category_id`;

export async function getDrugDetails(db: Kysely<TenantDB>, drugId: number): Promise<OriginalDrugDetails | null> {
  try {
    const result = await sql<SafeAltDrugRow>`
      SELECT ${sql.raw(SELECT_COLUMNS)} FROM business_items bi WHERE bi.id = ${drugId}
    `.execute(db);
    return result.rows[0] ?? null;
  } catch {
    return null;
  }
}

export async function getSimilarDrugs(
  db: Kysely<TenantDB>,
  excludeDrugId: number,
  categoryId: number | null,
  lineAccountId: number | null,
  limit = 10
): Promise<SafeAltDrugRow[]> {
  try {
    if (categoryId && lineAccountId) {
      const result = await sql<SafeAltDrugRow>`
        SELECT ${sql.raw(SELECT_COLUMNS)} FROM business_items bi
        WHERE bi.is_active = 1 AND bi.id != ${excludeDrugId} AND bi.stock > 0
        AND bi.category_id = ${categoryId}
        AND (bi.line_account_id = ${lineAccountId} OR bi.line_account_id IS NULL)
        ORDER BY bi.stock DESC LIMIT ${limit}
      `.execute(db);
      return result.rows;
    }
    if (categoryId) {
      const result = await sql<SafeAltDrugRow>`
        SELECT ${sql.raw(SELECT_COLUMNS)} FROM business_items bi
        WHERE bi.is_active = 1 AND bi.id != ${excludeDrugId} AND bi.stock > 0
        AND bi.category_id = ${categoryId}
        ORDER BY bi.stock DESC LIMIT ${limit}
      `.execute(db);
      return result.rows;
    }
    if (lineAccountId) {
      const result = await sql<SafeAltDrugRow>`
        SELECT ${sql.raw(SELECT_COLUMNS)} FROM business_items bi
        WHERE bi.is_active = 1 AND bi.id != ${excludeDrugId} AND bi.stock > 0
        AND (bi.line_account_id = ${lineAccountId} OR bi.line_account_id IS NULL)
        ORDER BY bi.stock DESC LIMIT ${limit}
      `.execute(db);
      return result.rows;
    }
    const result = await sql<SafeAltDrugRow>`
      SELECT ${sql.raw(SELECT_COLUMNS)} FROM business_items bi
      WHERE bi.is_active = 1 AND bi.id != ${excludeDrugId} AND bi.stock > 0
      ORDER BY bi.stock DESC LIMIT ${limit}
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

// ─────────────────────────────────────────────────────────────────────────
// calculateSimilarity()
// ─────────────────────────────────────────────────────────────────────────

export function calculateSimilarity(drug1: OriginalDrugDetails, drug2: SafeAltDrugRow): number {
  let score = 0;

  if ((drug1.category_id ?? 0) === (drug2.category_id ?? 0)) {
    score += 40;
  }

  const price1 = toFloatOrZero(drug1.price);
  const price2 = toFloatOrZero(drug2.price);
  if (price1 > 0 && price2 > 0) {
    const priceDiff = Math.abs(price1 - price2) / Math.max(price1, price2);
    if (priceDiff <= 0.3) {
      score += 30 * (1 - priceDiff);
    }
  }

  const name1 = (drug1.name ?? '').toLowerCase();
  const name2 = (drug2.name ?? '').toLowerCase();
  score += similarTextPercent(name1, name2) * 0.3;

  return round2(score);
}

// ─────────────────────────────────────────────────────────────────────────
// getSafeAlternatives()
// ─────────────────────────────────────────────────────────────────────────

export interface SafeAlternative {
  drugId: number;
  name: string;
  genericName: string | null;
  price: number;
  stock: number;
  imageUrl: string | null;
  isSafeForConditions: boolean;
  conditionWarnings: string[];
  similarity: number;
}

export type GetSafeAlternativesResult =
  | { alternatives: []; originalDrug: null; reason: string }
  | {
      alternatives: SafeAlternative[];
      originalDrug: { id: number; name: string; isSafe: boolean; unsafeReasons: string[] };
      userId: number;
      allergiesChecked: AllergyEntry[];
      conditionsChecked: string[];
    };

export async function getSafeAlternatives(
  db: Kysely<TenantDB>,
  drugId: number,
  userId: number,
  lineAccountId: number | null = null
): Promise<GetSafeAlternativesResult> {
  const originalDrug = await getDrugDetails(db, drugId);

  if (!originalDrug) {
    return { alternatives: [], originalDrug: null, reason: 'Original drug not found' };
  }

  const allergies = await getAllergies(db, userId);
  const conditions = await getUserConditions(db, userId);

  let originalSafe = true;
  const unsafeReasons: string[] = [];

  const allergyMatch = checkAllergyMatch(originalDrug, allergies);
  if (allergyMatch.hasMatch) {
    originalSafe = false;
    unsafeReasons.push(`แพ้ยา: ${allergyMatch.matchedAllergies.join(', ')}`);
  }

  const categoryId = originalDrug.category_id ?? null;
  const similarDrugs = await getSimilarDrugs(db, drugId, categoryId, lineAccountId, 10);

  const alternatives: SafeAlternative[] = [];

  for (const drug of similarDrugs) {
    if (toIntOrZero(drug.stock) <= 0) continue;

    const allergyCheck = checkAllergyMatch(drug, allergies);
    if (allergyCheck.hasMatch) continue;

    const conditionSafety = await checkConditionSafety(db, drug, userId);

    alternatives.push({
      drugId: drug.id,
      name: drug.name,
      genericName: drug.generic_name ?? null,
      price: toFloatOrZero(drug.sale_price ?? drug.price ?? 0),
      stock: toIntOrZero(drug.stock),
      imageUrl: drug.image_url ?? null,
      isSafeForConditions: conditionSafety.isSafe,
      conditionWarnings: conditionSafety.warnings,
      similarity: calculateSimilarity(originalDrug, drug),
    });

    if (alternatives.length >= 5) break;
  }

  alternatives.sort((a, b) => b.similarity - a.similarity);

  return {
    alternatives,
    originalDrug: { id: originalDrug.id, name: originalDrug.name, isSafe: originalSafe, unsafeReasons },
    userId,
    allergiesChecked: allergies,
    conditionsChecked: conditions,
  };
}
