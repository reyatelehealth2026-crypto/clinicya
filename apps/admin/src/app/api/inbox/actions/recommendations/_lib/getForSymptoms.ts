import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getAllergies, getMedications, type AllergyEntry } from '../../check-drug-interactions/_lib/customerHealthEngine';

/**
 * getForSymptoms.ts — port of `classes/DrugRecommendEngineService.php`'s
 * `getForSymptoms()` (lines 91-171) and its private helpers `searchDrugs()`
 * (179-217), `checkDrugInteractionsInternal()` (309-337) + `findInteraction()`
 * (345-388) + `getDrugNames()` (968-996), `checkAllergyMatch()` (1116-1150),
 * `checkConditionSafety()` (1151-1206) + `getUserConditions()` (1092-1114),
 * `getDefaultDosage()` (1207-1231), and `getDefaultUsage()` (1233-1248), as
 * driven by api/inbox-v2.php's `case 'recommendations': ...` (lines
 * ~1191-1350)'s final `getForSymptoms()` branch (reached only when
 * `type !== 'context'` AND `symptoms` is non-empty — see `./recommendations.ts`'s
 * module doc for the full cascade).
 *
 * ```php
 * public function getForSymptoms(array $symptoms, int $userId, int $limit = 5): array
 * {
 *     $recommendations = [];
 *     $allergies = $this->getUserAllergies($userId);
 *     $currentMedications = $this->getUserCurrentMedications($userId);
 *
 *     $categories = []; $keywords = [];
 *     foreach ($symptoms as $symptom) {
 *         $symptomLower = mb_strtolower(trim($symptom));
 *         foreach ($this->symptomDrugMap as $key => $mapping) {
 *             if (mb_stripos($symptomLower, $key) !== false || mb_stripos($key, $symptomLower) !== false) {
 *                 $categories[] = $mapping['category'];
 *                 $keywords = array_merge($keywords, $mapping['keywords']);
 *             }
 *         }
 *     }
 *     $categories = array_unique($categories); $keywords = array_unique($keywords);
 *     if (empty($keywords)) { $keywords = ['paracetamol', 'vitamin']; }
 *
 *     $drugs = $this->searchDrugs($keywords, $limit * 2);
 *     foreach ($drugs as $drug) {
 *         if (($drug['stock'] ?? 0) <= 0) { continue; }
 *         $allergyMatch = $this->checkAllergyMatch($drug, $allergies);
 *         if ($allergyMatch['hasMatch']) { continue; }
 *         $interactions = $this->checkDrugInteractionsInternal([$drug['id']], $currentMedications);
 *         $recommendations[] = [
 *             'drugId' => $drug['id'], 'name' => $drug['name'], 'genericName' => $drug['generic_name'] ?? null,
 *             'sku' => $drug['sku'] ?? null, 'category' => $drug['category_name'] ?? 'ยาทั่วไป',
 *             'dosage' => $drug['dosage'] ?? $this->getDefaultDosage($drug), 'usage' => $drug['usage_instructions'] ?? $this->getDefaultUsage($drug),
 *             'price' => (float)($drug['sale_price'] ?? $drug['price'] ?? 0), 'originalPrice' => (float)($drug['price'] ?? 0),
 *             'stock' => (int)($drug['stock'] ?? 0), 'imageUrl' => $drug['image_url'] ?? null,
 *             'isPrescription' => (bool)($drug['is_prescription'] ?? false),
 *             'hasInteractions' => !empty($interactions['interactions']), 'interactions' => $interactions['interactions'] ?? [],
 *             'interactionSeverity' => $interactions['severity'] ?? null,
 *             'safeForConditions' => $this->checkConditionSafety($drug, $userId), 'matchedSymptoms' => $symptoms
 *         ];
 *         if (count($recommendations) >= $limit) { break; }
 *     }
 *
 *     return ['recommendations' => $recommendations, 'symptoms' => $symptoms, 'categories' => $categories,
 *             'userId' => $userId, 'allergiesChecked' => count($allergies), 'currentMedicationsChecked' => count($currentMedications)];
 * }
 * ```
 *
 * `getUserAllergies()`/`getUserCurrentMedications()` — per this batch's
 * runbook, `setHealthEngine()` is always called in production, so this
 * port routes straight through the canonical
 * `../../check-drug-interactions/_lib/customerHealthEngine.ts` (`getAllergies()`
 * verbatim; `getMedications()` mapped to `.name`, matching PHP's own
 * `array_column($this->healthEngine->getMedications($userId), 'name')`),
 * never reimplementing `DrugRecommendEngineService`'s own dead-code
 * direct-query fallback.
 *
 * ```php
 * private function searchDrugs(array $keywords, int $limit = 10): array
 * {
 *     if (empty($keywords)) { return []; }
 *     $conditions = []; $params = [];
 *     foreach ($keywords as $keyword) {
 *         $conditions[] = "(bi.name LIKE ? OR bi.sku LIKE ? OR bi.description LIKE ?)";
 *         $params[] = "%{$keyword}%"; $params[] = "%{$keyword}%"; $params[] = "%{$keyword}%";
 *     }
 *     $whereClause = implode(' OR ', $conditions);
 *     $sql = "SELECT bi.*, ic.name as category_name FROM business_items bi
 *             LEFT JOIN item_categories ic ON bi.category_id = ic.id
 *             WHERE bi.is_active = 1 AND ({$whereClause})";
 *     if ($this->lineAccountId) { $sql .= " AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)"; $params[] = $this->lineAccountId; }
 *     $sql .= " ORDER BY bi.stock DESC, bi.name ASC LIMIT ?"; $params[] = $limit;
 *     ...
 * }
 * ```
 *
 * `bi.*` -> explicit column list (same "`../../drug-info/_lib/drugInfo.ts`"
 * convention as every other action in this batch): only the columns this
 * function's own consumers read are selected — `id`/`name`/`generic_name`/
 * `sku`/`dosage`/`usage_instructions`/`sale_price`/`price`/`stock`/
 * `image_url`/`is_prescription`(aliased, see below)/`description`, plus
 * `category_name` from the join (genuinely read, unlike `safe-alternatives`'/
 * `drug-card`'s own copies).
 *
 * CONFIRMED SCHEMA-DRIFT FIX — `bi.requires_prescription AS is_prescription`
 * (same fix as `../../drug-inventory/_lib/drugInventory.ts` — `bi.*` never
 * actually populates a key literally named `is_prescription`, so
 * `(bool)($drug['is_prescription'] ?? false)` is silently, permanently
 * `false` in production today; this port aliases the real column so the
 * `isPrescription` flag reflects real data).
 *
 * ```php
 * private function checkDrugInteractionsInternal(array $drugIds, array $currentMedications): array
 * {
 *     $drugNames = $this->getDrugNames($drugIds);
 *     $interactions = []; $maxSeverity = null;
 *     $severityOrder = ['mild'=>1,'moderate'=>2,'severe'=>3,'contraindicated'=>4];
 *     foreach ($drugNames as $drug1) {
 *         foreach ($currentMedications as $drug2) {
 *             $interaction = $this->findInteraction($drug1, $drug2);
 *             if ($interaction) {
 *                 $interactions[] = $interaction;
 *                 $currentSeverityLevel = $severityOrder[$interaction['severity']] ?? 0;
 *                 $maxSeverityLevel = $severityOrder[$maxSeverity] ?? 0;
 *                 if ($currentSeverityLevel > $maxSeverityLevel) { $maxSeverity = $interaction['severity']; }
 *             }
 *         }
 *     }
 *     return ['hasInteractions' => !empty($interactions), 'interactions' => $interactions, 'severity' => $maxSeverity];
 * }
 * ```
 *
 * `findInteraction()`/`getDrugNames()` here are the SAME literal source as
 * `../../check-drug-interactions/_lib/checkDrugInteractions.ts`'s own copies
 * (same class, same private methods) — duplicated (not imported) per this
 * batch's per-action-family ownership boundary; each directory keeps its
 * own copy of shared private helpers, matching the established convention
 * for `session.ts`/`fakeTenantDb.ts` across this whole `api/inbox/actions/*`
 * family.
 *
 * ```php
 * private function checkAllergyMatch(array $drug, array $allergies): array { ... }   // identical shape/logic to ../../safe-alternatives' own copy
 * private function checkConditionSafety(array $drug, int $userId): array { ... }      // identical shape/logic to ../../safe-alternatives' own copy
 * private function getUserConditions(int $userId): array { ... }
 * ```
 * These three are the SAME literal PHP methods `../../safe-alternatives/_lib/safeAlternatives.ts`
 * already ports (see that file's module doc for the full PHP source,
 * including the PRESERVED empty-needle `stripos()` quirk in
 * `checkAllergyMatch()` — this copy reproduces the identical quirk, since
 * it is the identical PHP method body). Duplicated here rather than
 * cross-imported, same rationale as above.
 *
 * ```php
 * private function getDefaultDosage(array $drug): string
 * {
 *     $name = strtolower($drug['name'] ?? '');
 *     if (stripos($name, 'paracetamol') !== false || stripos($name, 'tylenol') !== false) { return '500-1000 mg ทุก 4-6 ชั่วโมง (ไม่เกิน 4000 mg/วัน)'; }
 *     if (stripos($name, 'ibuprofen') !== false) { return '200-400 mg ทุก 4-6 ชั่วโมง (ไม่เกิน 1200 mg/วัน)'; }
 *     if (stripos($name, 'loratadine') !== false) { return '10 mg วันละ 1 ครั้ง'; }
 *     if (stripos($name, 'cetirizine') !== false) { return '10 mg วันละ 1 ครั้ง'; }
 *     return 'ตามคำแนะนำบนฉลาก';
 * }
 *
 * private function getDefaultUsage(array $drug): string
 * {
 *     $name = strtolower($drug['name'] ?? '');
 *     if (stripos($name, 'paracetamol') !== false) { return 'รับประทานหลังอาหารหรือเมื่อมีอาการ'; }
 *     if (stripos($name, 'antacid') !== false || stripos($name, 'omeprazole') !== false) { return 'รับประทานก่อนอาหาร 30 นาที'; }
 *     if (stripos($name, 'antibiotic') !== false) { return 'รับประทานให้ครบตามที่แพทย์สั่ง'; }
 *     return 'รับประทานตามคำแนะนำบนฉลาก';
 * }
 * ```
 *
 * `mb_stripos($haystack, $needle) !== false` (symptom-to-category matching,
 * BOTH directions) is ported via `ciIncludes()`.
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

/** PHP `empty($v)` for a possibly-null string DB column value. */
function isPhpEmptyString(value: string | null | undefined): boolean {
  return value === null || value === undefined || value === '' || value === '0';
}

/** `stripos($a, $b) !== false` (and its bidirectional callers) — case-insensitive substring search, empty needle always matches (see safe-alternatives' module doc for why this is preserved, not "fixed"). */
function ciIncludes(haystack: string, needle: string): boolean {
  if (needle === '') return true;
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

// ─────────────────────────────────────────────────────────────────────────
// symptomDrugMap
// ─────────────────────────────────────────────────────────────────────────

interface SymptomMapping {
  category: string;
  keywords: readonly string[];
}

const SYMPTOM_DRUG_MAP: readonly (readonly [string, SymptomMapping])[] = [
  ['ปวดหัว', { category: 'pain_relief', keywords: ['paracetamol', 'ibuprofen', 'aspirin'] }],
  ['headache', { category: 'pain_relief', keywords: ['paracetamol', 'ibuprofen', 'aspirin'] }],
  ['ปวดกล้ามเนื้อ', { category: 'pain_relief', keywords: ['ibuprofen', 'diclofenac', 'muscle relaxant'] }],
  ['ปวดท้อง', { category: 'digestive', keywords: ['antacid', 'buscopan', 'omeprazole'] }],
  ['ไข้', { category: 'fever', keywords: ['paracetamol', 'ibuprofen'] }],
  ['fever', { category: 'fever', keywords: ['paracetamol', 'ibuprofen'] }],
  ['ไอ', { category: 'cough', keywords: ['dextromethorphan', 'bromhexine', 'cough syrup'] }],
  ['cough', { category: 'cough', keywords: ['dextromethorphan', 'bromhexine', 'cough syrup'] }],
  ['เจ็บคอ', { category: 'throat', keywords: ['strepsils', 'betadine gargle', 'throat lozenge'] }],
  ['คัดจมูก', { category: 'nasal', keywords: ['pseudoephedrine', 'nasal spray', 'antihistamine'] }],
  ['น้ำมูก', { category: 'nasal', keywords: ['antihistamine', 'loratadine', 'cetirizine'] }],
  ['ท้องเสีย', { category: 'diarrhea', keywords: ['loperamide', 'ors', 'smecta'] }],
  ['diarrhea', { category: 'diarrhea', keywords: ['loperamide', 'ors', 'smecta'] }],
  ['ท้องผูก', { category: 'constipation', keywords: ['dulcolax', 'lactulose', 'fiber'] }],
  ['คลื่นไส้', { category: 'nausea', keywords: ['domperidone', 'dimenhydrinate'] }],
  ['อาหารไม่ย่อย', { category: 'indigestion', keywords: ['antacid', 'omeprazole', 'ranitidine'] }],
  ['แพ้', { category: 'allergy', keywords: ['loratadine', 'cetirizine', 'chlorpheniramine'] }],
  ['ผื่น', { category: 'skin', keywords: ['calamine', 'hydrocortisone', 'antihistamine'] }],
  ['คัน', { category: 'skin', keywords: ['calamine', 'hydrocortisone', 'antihistamine'] }],
  ['ตาแดง', { category: 'eye', keywords: ['eye drops', 'artificial tears'] }],
  ['ตาแห้ง', { category: 'eye', keywords: ['artificial tears', 'eye lubricant'] }],
  ['นอนไม่หลับ', { category: 'sleep', keywords: ['diphenhydramine', 'melatonin'] }],
  ['insomnia', { category: 'sleep', keywords: ['diphenhydramine', 'melatonin'] }],
];

// ─────────────────────────────────────────────────────────────────────────
// searchDrugs()
// ─────────────────────────────────────────────────────────────────────────

interface SymptomDrugRow {
  id: number;
  name: string;
  generic_name: string | null;
  sku: string | null;
  dosage: string | null;
  usage_instructions: string | null;
  sale_price: unknown;
  price: unknown;
  stock: unknown;
  image_url: string | null;
  is_prescription: unknown;
  description: string | null;
  category_name: string | null;
}

async function searchDrugs(db: Kysely<TenantDB>, keywords: string[], lineAccountId: number | null, limit = 10): Promise<SymptomDrugRow[]> {
  if (keywords.length === 0) return [];

  try {
    const likeConditions = keywords.map((k) => {
      const pattern = `%${k}%`;
      return sql`(bi.name LIKE ${pattern} OR bi.sku LIKE ${pattern} OR bi.description LIKE ${pattern})`;
    });
    const whereClause = sql.join(likeConditions, sql` OR `);

    const lineAccountClause = lineAccountId
      ? sql` AND (bi.line_account_id = ${lineAccountId} OR bi.line_account_id IS NULL)`
      : sql``;

    const result = await sql<SymptomDrugRow>`
      SELECT bi.id, bi.name, bi.generic_name, bi.sku, bi.dosage, bi.usage_instructions,
             bi.sale_price, bi.price, bi.stock, bi.image_url,
             bi.requires_prescription AS is_prescription, bi.description,
             ic.name AS category_name
      FROM business_items bi
      LEFT JOIN item_categories ic ON bi.category_id = ic.id
      WHERE bi.is_active = 1 AND (${whereClause})${lineAccountClause}
      ORDER BY bi.stock DESC, bi.name ASC LIMIT ${limit}
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

// ─────────────────────────────────────────────────────────────────────────
// checkDrugInteractionsInternal() / findInteraction() / getDrugNames()
// ─────────────────────────────────────────────────────────────────────────

interface DrugNameRow {
  id: number;
  name: string;
  generic_name: string | null;
}

async function getDrugNames(db: Kysely<TenantDB>, drugIds: number[]): Promise<string[]> {
  if (drugIds.length === 0) return [];
  try {
    const result = await sql<DrugNameRow>`
      SELECT id, name, generic_name FROM business_items WHERE id IN (${sql.join(drugIds)})
    `.execute(db);
    const names: string[] = [];
    for (const drug of result.rows) {
      names.push(drug.name);
      if (!isPhpEmptyString(drug.generic_name)) names.push(drug.generic_name as string);
    }
    return [...new Set(names)];
  } catch {
    return [];
  }
}

interface InternalInteraction {
  drug1: string;
  drug1Generic: string | null;
  drug2: string;
  drug2Generic: string | null;
  severity: string;
  description: string | null;
  recommendation: string | null;
}

interface InternalInteractionRow {
  drug1_name: string;
  drug1_generic: string | null;
  drug2_name: string;
  drug2_generic: string | null;
  severity: string;
  description: string | null;
  recommendation: string | null;
}

async function findInteraction(db: Kysely<TenantDB>, drug1: string, drug2: string): Promise<InternalInteraction | null> {
  try {
    const drug1Pattern = `%${drug1}%`;
    const drug2Pattern = `%${drug2}%`;
    const result = await sql<InternalInteractionRow>`
      SELECT * FROM drug_interactions
      WHERE ((drug1_name LIKE ${drug1Pattern} OR drug1_generic LIKE ${drug1Pattern}) AND (drug2_name LIKE ${drug2Pattern} OR drug2_generic LIKE ${drug2Pattern}))
         OR ((drug1_name LIKE ${drug2Pattern} OR drug1_generic LIKE ${drug2Pattern}) AND (drug2_name LIKE ${drug1Pattern} OR drug2_generic LIKE ${drug1Pattern}))
      LIMIT 1
    `.execute(db);
    const row = result.rows[0];
    if (!row) return null;
    return {
      drug1: row.drug1_name,
      drug1Generic: row.drug1_generic,
      drug2: row.drug2_name,
      drug2Generic: row.drug2_generic,
      severity: row.severity,
      description: row.description,
      recommendation: row.recommendation,
    };
  } catch {
    return null;
  }
}

const SEVERITY_ORDER: Record<string, number> = { mild: 1, moderate: 2, severe: 3, contraindicated: 4 };

interface InternalInteractionCheck {
  hasInteractions: boolean;
  interactions: InternalInteraction[];
  severity: string | null;
}

async function checkDrugInteractionsInternal(db: Kysely<TenantDB>, drugIds: number[], currentMedications: string[]): Promise<InternalInteractionCheck> {
  const drugNames = await getDrugNames(db, drugIds);
  const interactions: InternalInteraction[] = [];
  let maxSeverity: string | null = null;

  for (const drug1 of drugNames) {
    for (const drug2 of currentMedications) {
      const interaction = await findInteraction(db, drug1, drug2);
      if (interaction) {
        interactions.push(interaction);
        const currentLevel = SEVERITY_ORDER[interaction.severity] ?? 0;
        const maxLevel = maxSeverity !== null ? (SEVERITY_ORDER[maxSeverity] ?? 0) : 0;
        if (currentLevel > maxLevel) maxSeverity = interaction.severity;
      }
    }
  }

  return { hasInteractions: interactions.length > 0, interactions, severity: maxSeverity };
}

// ─────────────────────────────────────────────────────────────────────────
// getUserConditions() / checkAllergyMatch() / checkConditionSafety()
// ─────────────────────────────────────────────────────────────────────────

interface MedicalConditionsRow {
  medical_conditions: string | null;
}

async function getUserConditions(db: Kysely<TenantDB>, userId: number): Promise<string[]> {
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

interface DrugLike {
  name: string | null;
  generic_name?: string | null;
  description?: string | null;
}

interface AllergyMatchResult {
  hasMatch: boolean;
  matchedAllergies: string[];
}

function checkAllergyMatch(drug: DrugLike, allergies: AllergyEntry[]): AllergyMatchResult {
  const matchedAllergies: string[] = [];
  const drugName = (drug.name ?? '').toLowerCase();
  const genericName = (drug.generic_name ?? '').toLowerCase();
  const description = (drug.description ?? '').toLowerCase();

  for (const allergy of allergies) {
    const allergyNameValue = allergy.name;
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

interface ConditionSafetyResult {
  isSafe: boolean;
  warnings: string[];
  conditionsChecked: string[];
}

async function checkConditionSafety(db: Kysely<TenantDB>, drug: DrugLike, userId: number): Promise<ConditionSafetyResult> {
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
            continue conditionLoop;
          }
        }
      }
    }
  }

  return { isSafe: warnings.length === 0, warnings, conditionsChecked: conditions };
}

// ─────────────────────────────────────────────────────────────────────────
// getDefaultDosage() / getDefaultUsage()
// ─────────────────────────────────────────────────────────────────────────

function getDefaultDosage(drug: DrugLike): string {
  const name = (drug.name ?? '').toLowerCase();
  if (ciIncludes(name, 'paracetamol') || ciIncludes(name, 'tylenol')) return '500-1000 mg ทุก 4-6 ชั่วโมง (ไม่เกิน 4000 mg/วัน)';
  if (ciIncludes(name, 'ibuprofen')) return '200-400 mg ทุก 4-6 ชั่วโมง (ไม่เกิน 1200 mg/วัน)';
  if (ciIncludes(name, 'loratadine')) return '10 mg วันละ 1 ครั้ง';
  if (ciIncludes(name, 'cetirizine')) return '10 mg วันละ 1 ครั้ง';
  return 'ตามคำแนะนำบนฉลาก';
}

function getDefaultUsage(drug: DrugLike): string {
  const name = (drug.name ?? '').toLowerCase();
  if (ciIncludes(name, 'paracetamol')) return 'รับประทานหลังอาหารหรือเมื่อมีอาการ';
  if (ciIncludes(name, 'antacid') || ciIncludes(name, 'omeprazole')) return 'รับประทานก่อนอาหาร 30 นาที';
  if (ciIncludes(name, 'antibiotic')) return 'รับประทานให้ครบตามที่แพทย์สั่ง';
  return 'รับประทานตามคำแนะนำบนฉลาก';
}

// ─────────────────────────────────────────────────────────────────────────
// getForSymptoms()
// ─────────────────────────────────────────────────────────────────────────

export interface SymptomRecommendation {
  drugId: number;
  name: string;
  genericName: string | null;
  sku: string | null;
  category: string;
  dosage: string;
  usage: string;
  price: number;
  originalPrice: number;
  stock: number;
  imageUrl: string | null;
  isPrescription: boolean;
  hasInteractions: boolean;
  interactions: InternalInteraction[];
  interactionSeverity: string | null;
  safeForConditions: ConditionSafetyResult;
  matchedSymptoms: string[];
}

export interface GetForSymptomsResult {
  recommendations: SymptomRecommendation[];
  symptoms: string[];
  categories: string[];
  userId: number;
  allergiesChecked: number;
  currentMedicationsChecked: number;
}

export async function getForSymptoms(
  db: Kysely<TenantDB>,
  symptoms: string[],
  userId: number,
  lineAccountId: number | null,
  limit = 5
): Promise<GetForSymptomsResult> {
  const allergies = await getAllergies(db, userId);
  const currentMedications = (await getMedications(db, userId)).map((m) => m.name);

  const categories: string[] = [];
  let keywords: string[] = [];

  for (const symptom of symptoms) {
    const symptomLower = symptom.trim().toLowerCase();
    for (const [key, mapping] of SYMPTOM_DRUG_MAP) {
      if (ciIncludes(symptomLower, key) || ciIncludes(key, symptomLower)) {
        categories.push(mapping.category);
        keywords = keywords.concat(mapping.keywords as string[]);
      }
    }
  }

  const uniqueCategories = [...new Set(categories)];
  let uniqueKeywords = [...new Set(keywords)];
  if (uniqueKeywords.length === 0) {
    uniqueKeywords = ['paracetamol', 'vitamin'];
  }

  const drugs = await searchDrugs(db, uniqueKeywords, lineAccountId, limit * 2);

  const recommendations: SymptomRecommendation[] = [];

  for (const drug of drugs) {
    if (toIntOrZero(drug.stock ?? 0) <= 0) continue;

    const allergyMatch = checkAllergyMatch(drug, allergies);
    if (allergyMatch.hasMatch) continue;

    const interactions = await checkDrugInteractionsInternal(db, [drug.id], currentMedications);
    const safeForConditions = await checkConditionSafety(db, drug, userId);

    recommendations.push({
      drugId: drug.id,
      name: drug.name,
      genericName: drug.generic_name ?? null,
      sku: drug.sku ?? null,
      category: drug.category_name ?? 'ยาทั่วไป',
      // `$drug['dosage'] ?? $this->getDefaultDosage($drug)` — `??` is a NULL check
      // only (unlike `!empty()`): an empty-string `dosage`/`usage_instructions`
      // value from the DB is used AS-IS, not replaced by the default.
      dosage: drug.dosage !== null ? drug.dosage : getDefaultDosage(drug),
      usage: drug.usage_instructions !== null ? drug.usage_instructions : getDefaultUsage(drug),
      price: toFloatOrZero(drug.sale_price ?? drug.price ?? 0),
      originalPrice: toFloatOrZero(drug.price ?? 0),
      stock: toIntOrZero(drug.stock ?? 0),
      imageUrl: drug.image_url ?? null,
      isPrescription: Boolean(drug.is_prescription ?? false),
      hasInteractions: interactions.interactions.length > 0,
      interactions: interactions.interactions,
      interactionSeverity: interactions.severity,
      safeForConditions,
      matchedSymptoms: symptoms,
    });

    if (recommendations.length >= limit) break;
  }

  return {
    recommendations,
    symptoms,
    categories: uniqueCategories,
    userId,
    allergiesChecked: allergies.length,
    currentMedicationsChecked: currentMedications.length,
  };
}
