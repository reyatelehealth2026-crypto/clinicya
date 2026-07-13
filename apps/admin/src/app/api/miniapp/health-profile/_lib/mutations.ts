import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';
import {
  HEALTH_CONDITION_KEYS,
  HEALTH_PROFILE_ADD_ALLERGY_STATUS,
  HEALTH_PROFILE_ADD_MEDICATION_STATUS,
  HEALTH_PROFILE_REMOVE_ALLERGY_STATUS,
  HEALTH_PROFILE_REMOVE_MEDICATION_STATUS,
  HEALTH_PROFILE_UPDATE_MEDICAL_HISTORY_STATUS,
  HEALTH_PROFILE_UPDATE_PERSONAL_STATUS,
  type HealthMedicationInteraction,
} from '@reya/contracts';
import { floatval, intval, isset, phpEmpty, strOrEmpty } from './phpCompat';

/**
 * mutations.ts — the SIX write-action handlers ported from api/health-profile.php's POST switch (781
 * lines, read in full): `update_personal`, `update_medical_history`, `add_allergy`, `remove_allergy`,
 * `add_medication`, `remove_medication`. `update_medication` has ZERO line-mini-app callers (grepped
 * across all of line-mini-app/src) and is NOT ported. This file is NEW and parallel to the existing,
 * UNTOUCHED `_lib/query.ts` (which owns `action=get` only) — nothing here modifies query.ts or its
 * behavior.
 *
 * RESPONSE ENVELOPE: same per-branch HTTP status convention as query.ts's `getHealthProfileAction()`
 * (api/health-profile.php's own `jsonResponse($data, $statusCode = 200)` helper) — every function here
 * returns `{status, body}` looked up from the matching `HEALTH_PROFILE_*_STATUS` map in
 * `@reya/contracts` (see packages/contracts/src/health-profile.ts's WRITE-actions doc comment), NOT
 * `flatSuccessEnvelope()`.
 *
 * TABLE AUTO-CREATE NOT PORTED — same rationale query.ts's own doc comment gives for `action=get`:
 * `createHealthProfileTables($pdo)` runs on every PHP request, but packages/db's generated schema
 * confirms `user_health_profiles`/`user_drug_allergies`/`user_current_medications` already exist
 * unconditionally on the committed tenant template. Not reproduced here either.
 *
 * SUBTLE TRAP — PRESERVE, DO NOT "FIX": `removeAllergyAction()`'s DELETE and `removeMedicationAction()`'s
 * soft-delete UPDATE have NO `line_account_id` predicate at all — verbatim `WHERE id = ? AND
 * line_user_id = ?` from the PHP source. Do not add tenant scoping here; see
 * packages/contracts/src/health-profile.ts's doc comment for the full rationale.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

function failWith<E extends string>(map: Record<E | 'ok', number>, error: E): ActionResult {
  return { status: map[error], body: { success: false, error } };
}

// ---------------------------------------------------------------------------
// action=update_personal
// ---------------------------------------------------------------------------

const VALID_GENDERS = ['male', 'female', 'other'];
const VALID_BLOOD_TYPES = ['A', 'B', 'AB', 'O', 'unknown'];

export async function updatePersonalAction(db: Kysely<TenantDB>, input: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(input.line_user_id);
  const lineAccountId = isset(input.line_account_id) ? intval(input.line_account_id) : 0;

  if (phpEmpty(lineUserId)) {
    return failWith(HEALTH_PROFILE_UPDATE_PERSONAL_STATUS, 'Missing line_user_id');
  }

  let name: string | null = isset(input.name) ? strOrEmpty(input.name).trim() : null;
  if (name === '') name = null;
  if (name !== null && Array.from(name).length > 255) {
    name = Array.from(name).slice(0, 255).join('');
  }
  const age = isset(input.age) ? intval(input.age) : null;
  const gender = isset(input.gender) ? strOrEmpty(input.gender) : null;
  const weight = isset(input.weight) ? floatval(input.weight) : null;
  const height = isset(input.height) ? floatval(input.height) : null;
  let bloodType = isset(input.blood_type) ? strOrEmpty(input.blood_type) : 'unknown';

  if (age !== null && (age < 0 || age > 150)) {
    return failWith(HEALTH_PROFILE_UPDATE_PERSONAL_STATUS, 'Invalid age');
  }
  if (gender !== null && !VALID_GENDERS.includes(gender)) {
    return failWith(HEALTH_PROFILE_UPDATE_PERSONAL_STATUS, 'Invalid gender');
  }
  if (!VALID_BLOOD_TYPES.includes(bloodType)) {
    bloodType = 'unknown';
  }

  try {
    await sql`
      INSERT INTO user_health_profiles (line_user_id, line_account_id, name, age, gender, weight, height, blood_type)
      VALUES (${lineUserId}, ${lineAccountId}, ${name}, ${age}, ${gender}, ${weight}, ${height}, ${bloodType})
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        age = VALUES(age),
        gender = VALUES(gender),
        weight = VALUES(weight),
        height = VALUES(height),
        blood_type = VALUES(blood_type),
        updated_at = CURRENT_TIMESTAMP
    `.execute(db);

    return { status: HEALTH_PROFILE_UPDATE_PERSONAL_STATUS.ok, body: { success: true, message: 'บันทึกข้อมูลส่วนตัวแล้ว' } };
  } catch {
    return failWith(HEALTH_PROFILE_UPDATE_PERSONAL_STATUS, 'Database error');
  }
}

// ---------------------------------------------------------------------------
// action=update_medical_history
// ---------------------------------------------------------------------------

export async function updateMedicalHistoryAction(db: Kysely<TenantDB>, input: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(input.line_user_id);
  const lineAccountId = isset(input.line_account_id) ? intval(input.line_account_id) : 0;
  const rawConditions = Array.isArray(input.conditions) ? input.conditions : [];

  if (phpEmpty(lineUserId)) {
    return failWith(HEALTH_PROFILE_UPDATE_MEDICAL_HISTORY_STATUS, 'Missing line_user_id');
  }

  // `array_filter($conditions, fn($c) => in_array($c, $validConditions))` — unknown values silently dropped.
  const validKeys: readonly string[] = HEALTH_CONDITION_KEYS;
  const conditions = rawConditions.filter((c): c is string => typeof c === 'string' && validKeys.includes(c));

  try {
    await sql`
      INSERT INTO user_health_profiles (line_user_id, line_account_id, medical_conditions)
      VALUES (${lineUserId}, ${lineAccountId}, ${JSON.stringify(conditions)})
      ON DUPLICATE KEY UPDATE medical_conditions = VALUES(medical_conditions), updated_at = CURRENT_TIMESTAMP
    `.execute(db);

    return { status: HEALTH_PROFILE_UPDATE_MEDICAL_HISTORY_STATUS.ok, body: { success: true, message: 'บันทึกประวัติการแพทย์แล้ว' } };
  } catch {
    return failWith(HEALTH_PROFILE_UPDATE_MEDICAL_HISTORY_STATUS, 'Database error');
  }
}

// ---------------------------------------------------------------------------
// action=add_allergy
// ---------------------------------------------------------------------------

const VALID_REACTION_TYPES = ['rash', 'breathing', 'swelling', 'other'];
const VALID_SEVERITIES = ['mild', 'moderate', 'severe'];

export async function addAllergyAction(db: Kysely<TenantDB>, input: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(input.line_user_id);
  const lineAccountId = isset(input.line_account_id) ? intval(input.line_account_id) : 0;
  const drugName = strOrEmpty(input.drug_name).trim();
  const drugId = isset(input.drug_id) ? intval(input.drug_id) : null;
  let reactionType = isset(input.reaction_type) ? strOrEmpty(input.reaction_type) : 'other';
  const reactionNotes = strOrEmpty(input.reaction_notes);
  let severity = isset(input.severity) ? strOrEmpty(input.severity) : 'moderate';

  if (phpEmpty(lineUserId) || phpEmpty(drugName)) {
    return failWith(HEALTH_PROFILE_ADD_ALLERGY_STATUS, 'Missing required fields');
  }

  if (!VALID_REACTION_TYPES.includes(reactionType)) reactionType = 'other';
  if (!VALID_SEVERITIES.includes(severity)) severity = 'moderate';

  try {
    const dupResult = await sql<{ id: number }>`
      SELECT id FROM user_drug_allergies WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId} AND drug_name = ${drugName}
    `.execute(db);
    if (dupResult.rows.length > 0) {
      return failWith(HEALTH_PROFILE_ADD_ALLERGY_STATUS, 'ยานี้มีอยู่ในรายการแพ้ยาแล้ว');
    }

    const insertResult = await sql`
      INSERT INTO user_drug_allergies (line_user_id, line_account_id, drug_name, drug_id, reaction_type, reaction_notes, severity)
      VALUES (${lineUserId}, ${lineAccountId}, ${drugName}, ${drugId}, ${reactionType}, ${reactionNotes}, ${severity})
    `.execute(db);
    const allergyId = Number(insertResult.insertId ?? 0);

    return {
      status: HEALTH_PROFILE_ADD_ALLERGY_STATUS.ok,
      body: {
        success: true,
        message: 'เพิ่มข้อมูลการแพ้ยาแล้ว',
        allergy: { id: allergyId, drug_name: drugName, reaction_type: reactionType, severity },
      },
    };
  } catch {
    return failWith(HEALTH_PROFILE_ADD_ALLERGY_STATUS, 'Database error');
  }
}

// ---------------------------------------------------------------------------
// action=remove_allergy
// ---------------------------------------------------------------------------

export async function removeAllergyAction(db: Kysely<TenantDB>, input: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(input.line_user_id);
  const allergyId = input.allergy_id;

  if (phpEmpty(lineUserId) || phpEmpty(allergyId)) {
    return failWith(HEALTH_PROFILE_REMOVE_ALLERGY_STATUS, 'Missing required fields');
  }

  try {
    // SUBTLE TRAP — NO line_account_id predicate, verbatim from the PHP source. See this file's own
    // doc comment / packages/contracts/src/health-profile.ts's doc comment. Response is {success:true}
    // regardless of whether a row actually matched — PHP never checks rowCount() either.
    await sql`DELETE FROM user_drug_allergies WHERE id = ${intval(allergyId)} AND line_user_id = ${lineUserId}`.execute(db);
    return { status: HEALTH_PROFILE_REMOVE_ALLERGY_STATUS.ok, body: { success: true, message: 'ลบข้อมูลการแพ้ยาแล้ว' } };
  } catch {
    return failWith(HEALTH_PROFILE_REMOVE_ALLERGY_STATUS, 'Database error');
  }
}

// ---------------------------------------------------------------------------
// action=add_medication (+ checkMedicationInteractions())
// ---------------------------------------------------------------------------

interface ExistingMedicationRow {
  medication_name: string;
  product_id: number | null;
}

interface DrugInteractionRow {
  severity: string | null;
  description: string | null;
  recommendation: string | null;
}

/**
 * Port of api/health-profile.php::checkMedicationInteractions(). REALISTICALLY ALWAYS RESOLVES TO `[]`
 * against the committed tenant template: the JOIN below references `di.drug1_id`/`di.drug2_id`, but
 * the committed `drug_interactions` table (database/migration_2026-05-25_tenant_template.sql, verified
 * directly) has NO such columns — only `drug1_name`/`drug1_generic`/`drug2_name`/`drug2_generic`. The
 * query therefore throws a real SQL error ("Unknown column 'di.drug1_id'") on every call that reaches
 * it, caught by this function's own try/catch — exactly like the PHP original's actual (accidental)
 * production behavior today. Ported literally rather than short-circuited/"fixed", so this self-heals
 * if a future migration ever adds those columns; fixing the schema itself is out of this batch's scope.
 */
async function checkMedicationInteractions(
  db: Kysely<TenantDB>,
  lineUserId: string,
  lineAccountId: number,
  newMedicationName: string,
  newProductId: number | null
): Promise<HealthMedicationInteraction[]> {
  const interactions: HealthMedicationInteraction[] = [];
  try {
    const existingResult = await sql<ExistingMedicationRow>`
      SELECT medication_name, product_id FROM user_current_medications
      WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId} AND is_active = 1
    `.execute(db);
    const existingMeds = existingResult.rows;
    if (existingMeds.length === 0) return [];

    // `SHOW TABLES LIKE 'drug_interactions'` — table-existence guard, ported even though the table IS
    // present on the committed template (cheap, matches PHP's defensive posture).
    const tableCheck = await sql`SHOW TABLES LIKE 'drug_interactions'`.execute(db);
    if (tableCheck.rows.length === 0) return [];

    for (const med of existingMeds) {
      const existingProductId = med.product_id ?? 0;
      const existingNamePattern = `%${med.medication_name}%`;
      const newNamePattern = `%${newMedicationName}%`;

      const interactionResult = await sql<DrugInteractionRow>`
        SELECT di.*, p1.name as drug1_name, p2.name as drug2_name
        FROM drug_interactions di
        LEFT JOIN products p1 ON di.drug1_id = p1.id
        LEFT JOIN products p2 ON di.drug2_id = p2.id
        WHERE (
          (di.drug1_id = ${existingProductId} AND di.drug2_id = ${newProductId}) OR
          (di.drug1_id = ${newProductId} AND di.drug2_id = ${existingProductId}) OR
          (p1.name LIKE ${existingNamePattern} AND p2.name LIKE ${newNamePattern}) OR
          (p1.name LIKE ${newNamePattern} AND p2.name LIKE ${existingNamePattern})
        )
        LIMIT 1
      `.execute(db);
      const interaction = interactionResult.rows[0];

      if (interaction) {
        interactions.push({
          drug1: med.medication_name,
          drug2: newMedicationName,
          severity: interaction.severity ?? 'moderate',
          description: interaction.description ?? 'อาจมีปฏิกิริยาระหว่างยา',
          recommendation: interaction.recommendation ?? 'ควรปรึกษาเภสัชกร',
        });
      }
    }
  } catch {
    // Best-effort — mirrors PHP's catch (PDOException $e) { error_log(...); }.
  }
  return interactions;
}

export async function addMedicationAction(db: Kysely<TenantDB>, input: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(input.line_user_id);
  const lineAccountId = isset(input.line_account_id) ? intval(input.line_account_id) : 0;
  const medicationName = strOrEmpty(input.medication_name).trim();
  const productId = isset(input.product_id) ? intval(input.product_id) : null;
  const dosage = strOrEmpty(input.dosage);
  const frequency = strOrEmpty(input.frequency);
  const startDate = isset(input.start_date) ? strOrEmpty(input.start_date) : null;
  const notes = strOrEmpty(input.notes);

  if (phpEmpty(lineUserId) || phpEmpty(medicationName)) {
    return failWith(HEALTH_PROFILE_ADD_MEDICATION_STATUS, 'Missing required fields');
  }

  try {
    const interactions = await checkMedicationInteractions(db, lineUserId, lineAccountId, medicationName, productId);

    const insertResult = await sql`
      INSERT INTO user_current_medications (line_user_id, line_account_id, medication_name, product_id, dosage, frequency, start_date, notes)
      VALUES (${lineUserId}, ${lineAccountId}, ${medicationName}, ${productId}, ${dosage}, ${frequency}, ${startDate}, ${notes})
    `.execute(db);
    const medicationId = Number(insertResult.insertId ?? 0);

    const body: Record<string, unknown> = {
      success: true,
      message: 'เพิ่มยาที่ใช้ประจำแล้ว',
      medication: { id: medicationId, medication_name: medicationName, dosage, frequency },
    };
    // `if (!empty($interactions)) { $response['interactions']=...; $response['has_interactions']=true; }`
    // — keys ABSENT (not []/false) when there are no interactions.
    if (interactions.length > 0) {
      body.interactions = interactions;
      body.has_interactions = true;
    }

    return { status: HEALTH_PROFILE_ADD_MEDICATION_STATUS.ok, body };
  } catch {
    return failWith(HEALTH_PROFILE_ADD_MEDICATION_STATUS, 'Database error');
  }
}

// ---------------------------------------------------------------------------
// action=remove_medication
// ---------------------------------------------------------------------------

export async function removeMedicationAction(db: Kysely<TenantDB>, input: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(input.line_user_id);
  const medicationId = input.medication_id;

  if (phpEmpty(lineUserId) || phpEmpty(medicationId)) {
    return failWith(HEALTH_PROFILE_REMOVE_MEDICATION_STATUS, 'Missing required fields');
  }

  try {
    // SUBTLE TRAP — NO line_account_id predicate, same pattern as removeAllergyAction(). Soft delete.
    await sql`UPDATE user_current_medications SET is_active = 0 WHERE id = ${intval(medicationId)} AND line_user_id = ${lineUserId}`.execute(db);
    return { status: HEALTH_PROFILE_REMOVE_MEDICATION_STATUS.ok, body: { success: true, message: 'ลบยาออกจากรายการแล้ว' } };
  } catch {
    return failWith(HEALTH_PROFILE_REMOVE_MEDICATION_STATUS, 'Database error');
  }
}
