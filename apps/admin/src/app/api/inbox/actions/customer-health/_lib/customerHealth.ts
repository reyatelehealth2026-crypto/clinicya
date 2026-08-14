import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getDraftStyle, type DraftStyle } from '../../draft-style/_lib/draftStyle';

/**
 * customerHealth.ts — literal port of
 * `classes/CustomerHealthEngineService.php::getHealthProfile()` (lines
 * 64-98) and everything it transitively calls: `getUserHealthData()` (105-147),
 * `getAllergies()` (158-235), `getMedications()` (245-342),
 * `resolveLineUserId()` (348-358), `overlayMiniAppHealthProfile()` (366-405),
 * `mergeMiniAppAllergies()` (413-462), `mergeMiniAppMedications()` (470-516),
 * `getRecentPurchasedMedications()` (525-592), `getOrCreateProfile()`
 * (1044-1079), and `getTypeLabel()` (1028-1036). `classifyCustomer()`'s own
 * tree belongs to `../../classify-customer/_lib/classifyCustomer.ts`, and
 * `getDraftStyle()` is imported from `../../draft-style/_lib/draftStyle.ts`
 * (single-owner cross-route import — see that module's doc; matches PHP's
 * own `getHealthProfile()` calling `$this->getDraftStyle(...)` on the same
 * class).
 *
 * ```php
 * public function getHealthProfile(int $userId): array
 * {
 *     $userHealth = $this->getUserHealthData($userId);
 *     $allergies = $this->getAllergies($userId);
 *     $medications = $this->getMedications($userId);
 *     $profile = $this->getOrCreateProfile($userId);
 *     $draftStyle = $this->getDraftStyle($profile['communicationType'] ?? self::TYPE_DIRECT);
 *     return [
 *         'userId' => $userId, 'allergies' => $allergies, 'medications' => $medications,
 *         'conditions' => $userHealth['conditions'], 'communicationType' => $profile['communicationType'],
 *         'communicationTypeLabel' => $this->getTypeLabel($profile['communicationType']),
 *         'confidence' => $profile['confidence'], 'tips' => $profile['tips'] ?? $draftStyle['tips'],
 *         'draftStyle' => $draftStyle, 'weight' => $userHealth['weight'], 'height' => $userHealth['height'],
 *         'bloodType' => $userHealth['bloodType'], 'hasAllergyWarning' => !empty($allergies),
 *         'lastAnalyzedAt' => $profile['lastAnalyzedAt'], 'messageCountAnalyzed' => $profile['messageCountAnalyzed']
 *     ];
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * DEAD-CODE BRANCHES — `user_allergies` / `user_medications` NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * `getAllergies()`'s middle branch (`SELECT ... FROM user_allergies WHERE
 * user_id = ? AND is_active = 1`) and `getMedications()`'s middle branch
 * (`SELECT ... FROM user_medications WHERE user_id = ? AND is_active = 1`)
 * both target tables that do NOT exist in
 * `packages/db/src/generated/tenant-db.d.ts`'s typed `TenantDB` interface —
 * confirmed by grepping the generated file for `UserAllergies`/
 * `UserMedications` (zero matches; both `user_drug_allergies` and
 * `user_current_medications`, the LINE-mini-app-authored tables, DO exist and
 * ARE ported below via `mergeMiniAppAllergies`/`mergeMiniAppMedications`).
 * PHP's own `catch (PDOException $e) { // table might not exist }` around
 * each of these branches means they ALWAYS throw and ALWAYS contribute
 * nothing in production today — the exact same "schema-drift, PHP silently
 * never populates this" situation already documented on
 * `../../drug-info/_lib/drugInfo.ts` (`is_prescription`) and
 * `../../medical-history/_lib/medicalHistory.ts` (`birth_date`/
 * `chronic_diseases`). Per this batch's brief, these two branches are
 * intentionally NOT reproduced as a raw untyped `sql` query against a table
 * Kysely's typed schema doesn't know about (that would just be inventing a
 * new, never-tested code path) — they are omitted, documented here as
 * confirmed-dead, matching PHP's own real-world contribution of zero rows.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX — `getRecentPurchasedMedications()` uses
 * `requires_prescription`, NOT `is_prescription`
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's primary transactions/transaction_items query's WHERE clause
 * references `bi.is_prescription = 1` — unlike the `is_prescription` reads
 * documented on `../../drug-info/_lib/drugInfo.ts` (a `SELECT bi.*` that
 * simply never populates a nonexistent key), THIS is a column reference
 * inside a `WHERE` clause: `business_items.is_prescription` does not exist
 * (confirmed against `BusinessItems` in `tenant-db.d.ts` — the real column is
 * `requires_prescription`), so on the real, live schema this specific query
 * would throw a genuine "Unknown column" SQL error on every single call,
 * caught by PHP's own `catch (PDOException $e)`, which falls through to the
 * `orders`/`order_items` fallback query below it (itself inside a *nested*
 * try/catch) — meaning in current production, `getRecentPurchasedMedications()`
 * ALWAYS serves from `orders`, NEVER from `transactions`, regardless of which
 * table actually has the customer's real purchase history. This port fixes
 * forward per this batch's brief: the primary query below reads the real
 * `requires_prescription` column, so it can actually succeed against
 * `transactions`/`transaction_items` when that data exists. The `orders`/
 * `order_items` fallback (both tables DO exist in `tenant-db.d.ts`) is still
 * ported and still wrapped in its own nested try/catch, exactly matching
 * PHP's structure — it now serves its ORIGINALLY INTENDED purpose (a
 * fallback for tenants whose only purchase records live in the older
 * `orders` table) rather than being the sole, permanently-forced path.
 */

/** PHP `!empty($v) ? (float) $v : null` truthiness gate — `0`/`'0'`/`''`/null/undefined all mean "no value". */
function truthyToFloatOrNull(value: unknown): number | null {
  if (value === null || value === undefined || value === '' || value === 0 || value === '0') return null;
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

/** PHP `stripos($haystack, $needle) !== false` — case-insensitive substring check (Thai has no case, so plain lowercasing is equivalent to `mb_stripos`/`stripos` here). */
function ciIncludes(haystack: string, needle: string): boolean {
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

/** `preg_split('/[,\n]+/', $text)` then `array_map('trim', ...)` then `array_filter(...)` (drops `''`/`'0'`), preserving order. */
function splitTrimFilter(text: string): string[] {
  return text
    .split(/[,\n]+/)
    .map((item) => item.trim())
    .filter((item) => item !== '' && item !== '0');
}

// ═══════════════════════════════════════════════════════════════════════
// getUserHealthData() + overlayMiniAppHealthProfile()
// ═══════════════════════════════════════════════════════════════════════

export interface UserHealthData {
  conditions: string[];
  weight: number | null;
  height: number | null;
  bloodType: string | null;
}

interface UserHealthRow {
  line_user_id: string | null;
  weight: unknown;
  height: unknown;
  blood_type: string | null;
  medical_conditions: string | null;
  drug_allergies: string | null;
  current_medications: string | null;
}

interface MiniAppHealthProfileRow {
  weight: unknown;
  height: unknown;
  blood_type: string | null;
  medical_conditions: string | null;
}

async function overlayMiniAppHealthProfile(db: Kysely<TenantDB>, data: UserHealthData, lineUserId: string): Promise<void> {
  try {
    const result = await sql<MiniAppHealthProfileRow>`
      SELECT weight, height, blood_type, medical_conditions
      FROM user_health_profiles
      WHERE line_user_id = ${lineUserId}
      ORDER BY updated_at DESC
      LIMIT 1
    `.execute(db);
    const p = result.rows[0];
    if (!p) return;

    const weight = truthyToFloatOrNull(p.weight);
    if (weight !== null) data.weight = weight;
    const height = truthyToFloatOrNull(p.height);
    if (height !== null) data.height = height;
    if (p.blood_type && p.blood_type !== 'unknown') data.bloodType = p.blood_type;

    if (p.medical_conditions) {
      let decoded: unknown = null;
      try {
        decoded = JSON.parse(p.medical_conditions);
      } catch {
        decoded = null;
      }
      if (Array.isArray(decoded) && decoded.length > 0) {
        const conditions = decoded
          .map((c: unknown): string => {
            if (typeof c === 'string') return c.trim();
            if (c && typeof c === 'object' && 'name' in c) return String((c as Record<string, unknown>).name ?? '').trim();
            return '';
          })
          .filter((c) => c !== '');
        if (conditions.length > 0) {
          data.conditions = conditions;
        }
      }
    }
  } catch {
    // user_health_profiles may not exist on legacy tenants — keep fallback.
  }
}

async function getUserHealthData(db: Kysely<TenantDB>, userId: number): Promise<UserHealthData> {
  const data: UserHealthData = { conditions: [], weight: null, height: null, bloodType: null };

  try {
    const result = await sql<UserHealthRow>`
      SELECT line_user_id, weight, height, blood_type, medical_conditions, drug_allergies, current_medications
      FROM users
      WHERE id = ${userId}
    `.execute(db);
    const user = result.rows[0];

    if (user) {
      data.weight = truthyToFloatOrNull(user.weight);
      data.height = truthyToFloatOrNull(user.height);
      data.bloodType = user.blood_type ?? null;

      if (user.medical_conditions) {
        data.conditions = splitTrimFilter(user.medical_conditions);
      }

      if (user.line_user_id) {
        await overlayMiniAppHealthProfile(db, data, user.line_user_id);
      }
    }
  } catch (error) {
    // CustomerHealthEngine getUserHealthData error
    console.error('CustomerHealthEngine getUserHealthData error:', error instanceof Error ? error.message : error);
  }

  return data;
}

// ═══════════════════════════════════════════════════════════════════════
// getAllergies() + mergeMiniAppAllergies()
// ═══════════════════════════════════════════════════════════════════════

export interface Allergy {
  name: string;
  severity: string;
  reaction?: string | null;
  notes?: string | null;
  source: string;
  isActive: boolean;
}

interface UserDrugAllergiesTextRow {
  drug_allergies: string | null;
}

interface MiniAppAllergyRow {
  drug_name: string;
  severity: 'mild' | 'moderate' | 'severe' | null;
  reaction_type: 'breathing' | 'other' | 'rash' | 'swelling' | null;
  reaction_notes: string | null;
}

const ALLERGY_NONE_TOKENS = ['ไม่มี', 'ไม่แพ้', 'ไม่มีประวัติแพ้ยา', 'none', 'no', 'n/a', '-'];

async function mergeMiniAppAllergies(db: Kysely<TenantDB>, allergies: Allergy[], userId: number): Promise<void> {
  try {
    const lid = await resolveLineUserId(db, userId);
    if (lid === null) return;

    const result = await sql<MiniAppAllergyRow>`
      SELECT drug_name, severity, reaction_type, reaction_notes
      FROM user_drug_allergies
      WHERE line_user_id = ${lid}
      ORDER BY created_at DESC
    `.execute(db);

    for (const row of result.rows) {
      const name = (row.drug_name ?? '').trim();
      if (name === '' || ALLERGY_NONE_TOKENS.includes(name.toLowerCase())) continue;

      const existing = allergies.find((a) => ciIncludes(a.name, name) || ciIncludes(name, a.name));
      if (existing) {
        existing.severity = row.severity || existing.severity || 'unknown';
        existing.reaction = row.reaction_type ?? existing.reaction ?? null;
        existing.notes = row.reaction_notes ?? existing.notes ?? null;
        existing.source = 'miniapp';
      } else {
        allergies.push({
          name,
          severity: row.severity || 'unknown',
          reaction: row.reaction_type ?? null,
          notes: row.reaction_notes ?? null,
          source: 'miniapp',
          isActive: true,
        });
      }
    }
  } catch {
    // user_drug_allergies may not exist on legacy tenants.
  }
}

export async function getAllergies(db: Kysely<TenantDB>, userId: number): Promise<Allergy[]> {
  const allergies: Allergy[] = [];

  try {
    const result = await sql<UserDrugAllergiesTextRow>`SELECT drug_allergies FROM users WHERE id = ${userId}`.execute(db);
    const user = result.rows[0];

    if (user && user.drug_allergies) {
      for (const allergy of splitTrimFilter(user.drug_allergies)) {
        allergies.push({ name: allergy, severity: 'unknown', source: 'user_profile', isActive: true });
      }
    }

    // DEAD CODE, NOT PORTED: PHP's `user_allergies` branch — see module doc.

    await mergeMiniAppAllergies(db, allergies, userId);
  } catch (error) {
    console.error('CustomerHealthEngine getAllergies error:', error instanceof Error ? error.message : error);
  }

  return allergies;
}

// ═══════════════════════════════════════════════════════════════════════
// getMedications() + mergeMiniAppMedications() + getRecentPurchasedMedications()
// ═══════════════════════════════════════════════════════════════════════

export interface Medication {
  name: string;
  dosage?: string | null;
  frequency?: string | null;
  notes?: string | null;
  startDate?: unknown;
  productId?: number;
  lastPurchased?: unknown;
  source: string;
  isActive: boolean;
}

interface UserMedicationsTextRow {
  current_medications: string | null;
}

interface MiniAppMedicationRow {
  medication_name: string;
  dosage: string | null;
  frequency: string | null;
  notes: string | null;
}

interface PurchasedMedicationRow {
  name: string;
  product_id: number;
  last_purchased: unknown;
}

async function getRecentPurchasedMedications(db: Kysely<TenantDB>, userId: number, days = 90): Promise<Medication[]> {
  try {
    // FIX-FORWARD: `bi.requires_prescription` (real column) — see module doc.
    const result = await sql<PurchasedMedicationRow>`
      SELECT DISTINCT bi.name, bi.id as product_id, MAX(t.created_at) as last_purchased
      FROM transactions t
      JOIN transaction_items ti ON t.id = ti.transaction_id
      JOIN business_items bi ON ti.product_id = bi.id
      LEFT JOIN item_categories ic ON bi.category_id = ic.id
      WHERE t.user_id = ${userId}
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL ${sql.raw(String(days))} DAY)
      AND t.status NOT IN ('cancelled', 'failed')
      AND (ic.name LIKE '%ยา%' OR ic.name LIKE '%drug%' OR ic.name LIKE '%medicine%'
           OR bi.name LIKE '%ยา%' OR bi.requires_prescription = 1)
      GROUP BY bi.id, bi.name
      ORDER BY last_purchased DESC
      LIMIT 10
    `.execute(db);

    return result.rows.map((row) => ({
      name: row.name,
      productId: row.product_id,
      lastPurchased: row.last_purchased,
      source: 'purchase_history',
      isActive: true,
    }));
  } catch {
    // Table might not exist or have different structure — fall back to `orders`.
    try {
      const result = await sql<PurchasedMedicationRow>`
        SELECT DISTINCT bi.name, bi.id as product_id, MAX(o.created_at) as last_purchased
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN business_items bi ON oi.product_id = bi.id
        WHERE o.user_id = ${userId}
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL ${sql.raw(String(days))} DAY)
        AND o.status IN ('paid', 'confirmed', 'delivered', 'completed')
        GROUP BY bi.id, bi.name
        ORDER BY last_purchased DESC
        LIMIT 10
      `.execute(db);

      return result.rows.map((row) => ({
        name: row.name,
        productId: row.product_id,
        lastPurchased: row.last_purchased,
        source: 'purchase_history',
        isActive: true,
      }));
    } catch {
      // Ignore — neither purchase-history table produced usable results.
      return [];
    }
  }
}

async function mergeMiniAppMedications(db: Kysely<TenantDB>, medications: Medication[], userId: number): Promise<void> {
  try {
    const lid = await resolveLineUserId(db, userId);
    if (lid === null) return;

    const result = await sql<MiniAppMedicationRow>`
      SELECT medication_name, dosage, frequency, notes
      FROM user_current_medications
      WHERE line_user_id = ${lid} AND is_active = 1
      ORDER BY created_at DESC
    `.execute(db);

    for (const row of result.rows) {
      const name = (row.medication_name ?? '').trim();
      if (name === '') continue;

      const existing = medications.find((m) => ciIncludes(m.name, name) || ciIncludes(name, m.name));
      if (existing) {
        existing.dosage = row.dosage || existing.dosage || null;
        existing.frequency = row.frequency || existing.frequency || null;
        existing.notes = row.notes ?? existing.notes ?? null;
        existing.source = 'miniapp';
      } else {
        medications.push({
          name,
          dosage: row.dosage ?? null,
          frequency: row.frequency ?? null,
          notes: row.notes ?? null,
          source: 'miniapp',
          isActive: true,
        });
      }
    }
  } catch {
    // user_current_medications may not exist on legacy tenants.
  }
}

export async function getMedications(db: Kysely<TenantDB>, userId: number): Promise<Medication[]> {
  const medications: Medication[] = [];

  try {
    const result = await sql<UserMedicationsTextRow>`SELECT current_medications FROM users WHERE id = ${userId}`.execute(db);
    const user = result.rows[0];

    if (user && user.current_medications) {
      for (const med of splitTrimFilter(user.current_medications)) {
        medications.push({ name: med, dosage: null, frequency: null, source: 'user_profile', isActive: true });
      }
    }

    // DEAD CODE, NOT PORTED: PHP's `user_medications` branch — see module doc.

    const purchasedMeds = await getRecentPurchasedMedications(db, userId);
    for (const purchasedMed of purchasedMeds) {
      const exists = medications.some((m) => ciIncludes(m.name, purchasedMed.name) || ciIncludes(purchasedMed.name, m.name));
      if (!exists) {
        medications.push(purchasedMed);
      }
    }

    await mergeMiniAppMedications(db, medications, userId);
  } catch (error) {
    console.error('CustomerHealthEngine getMedications error:', error instanceof Error ? error.message : error);
  }

  return medications;
}

// ═══════════════════════════════════════════════════════════════════════
// resolveLineUserId()
// ═══════════════════════════════════════════════════════════════════════

async function resolveLineUserId(db: Kysely<TenantDB>, userId: number): Promise<string | null> {
  try {
    const result = await sql<{ line_user_id: string | null }>`SELECT line_user_id FROM users WHERE id = ${userId}`.execute(db);
    const lid = result.rows[0]?.line_user_id;
    return lid !== undefined && lid !== null && lid !== '' ? lid : null;
  } catch {
    return null;
  }
}

// ═══════════════════════════════════════════════════════════════════════
// getOrCreateProfile() + getTypeLabel()
// ═══════════════════════════════════════════════════════════════════════

interface HealthProfileRecord {
  communicationType: 'A' | 'B' | 'C';
  confidence: number;
  tips: string[] | null;
  lastAnalyzedAt: string | null;
  messageCountAnalyzed: number;
  chronicConditions: unknown[];
}

interface CustomerHealthProfileRow {
  communication_type: 'A' | 'B' | 'C' | null;
  confidence: unknown;
  communication_tips: string | null;
  last_analyzed_at_str: string | null;
  message_count_analyzed: unknown;
  chronic_conditions: string | null;
}

function defaultHealthProfileRecord(): HealthProfileRecord {
  return { communicationType: 'A', confidence: 0.0, tips: null, lastAnalyzedAt: null, messageCountAnalyzed: 0, chronicConditions: [] };
}

async function getOrCreateProfile(db: Kysely<TenantDB>, userId: number): Promise<HealthProfileRecord> {
  try {
    // `last_analyzed_at` DATE_FORMAT'd to a bare string — same convention as
    // ../../send-message/_lib/sendMessage.ts's `reply_token_expires_str` (see
    // that module's doc): avoids ambiguity between mysql2's un-tz'd `Date`
    // hydration and this pool's session-level `+07:00` setting.
    const result = await sql<CustomerHealthProfileRow>`
      SELECT communication_type, confidence, communication_tips,
        DATE_FORMAT(last_analyzed_at, '%Y-%m-%d %H:%i:%s') AS last_analyzed_at_str,
        message_count_analyzed, chronic_conditions
      FROM customer_health_profiles
      WHERE user_id = ${userId}
    `.execute(db);
    const profile = result.rows[0];

    if (profile) {
      return {
        communicationType: profile.communication_type ?? 'A',
        confidence: Number(profile.confidence ?? 0),
        tips: profile.communication_tips ? (JSON.parse(profile.communication_tips) as string[]) : null,
        lastAnalyzedAt: profile.last_analyzed_at_str,
        messageCountAnalyzed: Number(profile.message_count_analyzed ?? 0),
        chronicConditions: profile.chronic_conditions ? (JSON.parse(profile.chronic_conditions) as unknown[]) : [],
      };
    }
  } catch {
    // Table might not exist.
  }

  return defaultHealthProfileRecord();
}

function getTypeLabel(type: string): string {
  const labels: Record<string, string> = {
    A: 'ตรงประเด็น (Type A)',
    B: 'ห่วงใย (Type B)',
    C: 'ใส่ใจรายละเอียด (Type C)',
  };
  return labels[type] ?? 'ไม่ระบุ';
}

// ═══════════════════════════════════════════════════════════════════════
// getHealthProfile() — top-level orchestrator
// ═══════════════════════════════════════════════════════════════════════

export interface HealthProfile {
  userId: number;
  allergies: Allergy[];
  medications: Medication[];
  conditions: string[];
  communicationType: string;
  communicationTypeLabel: string;
  confidence: number;
  tips: string[] | null;
  draftStyle: DraftStyle;
  weight: number | null;
  height: number | null;
  bloodType: string | null;
  hasAllergyWarning: boolean;
  lastAnalyzedAt: string | null;
  messageCountAnalyzed: number;
}

export async function getHealthProfile(db: Kysely<TenantDB>, userId: number): Promise<HealthProfile> {
  const userHealth = await getUserHealthData(db, userId);
  const allergies = await getAllergies(db, userId);
  const medications = await getMedications(db, userId);
  const profile = await getOrCreateProfile(db, userId);
  const draftStyle = getDraftStyle(profile.communicationType ?? 'A');

  return {
    userId,
    allergies,
    medications,
    conditions: userHealth.conditions,
    communicationType: profile.communicationType,
    communicationTypeLabel: getTypeLabel(profile.communicationType),
    confidence: profile.confidence,
    tips: profile.tips ?? draftStyle.tips,
    draftStyle,
    weight: userHealth.weight,
    height: userHealth.height,
    bloodType: userHealth.bloodType,
    hasAllergyWarning: allergies.length > 0,
    lastAnalyzedAt: profile.lastAnalyzedAt,
    messageCountAnalyzed: profile.messageCountAnalyzed,
  };
}
