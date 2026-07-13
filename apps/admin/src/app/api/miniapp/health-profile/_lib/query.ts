import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { HealthProfileGetResponse } from '@reya/contracts';

/**
 * query.ts — TypeScript port of api/health-profile.php's `getHealthProfile()`
 * (`action=get` ONLY — read api/health-profile.php in full, 782 lines,
 * before writing this file; every write action and get_allergies/
 * get_medications/search_drugs are out of this batch's scope, see route.ts).
 *
 * TABLE AUTO-CREATE NOT PORTED (flagged simplification): PHP's
 * `createHealthProfileTables($pdo)` runs `CREATE TABLE IF NOT EXISTS` for
 * `user_health_profiles`/`user_drug_allergies`/`user_current_medications` on
 * every request — per CLAUDE.md's own "Auto-create tables" convention, new
 * features should use a versioned migration instead, and packages/db's
 * generated schema confirms all three tables already exist unconditionally
 * on a tenant DB created from the committed template. Not reproduced here.
 */

interface ProfileRow {
  id: number;
  name: string | null;
  age: number | null;
  gender: 'male' | 'female' | 'other' | null;
  weight: string | number | null;
  height: string | number | null;
  blood_type: 'A' | 'B' | 'AB' | 'O' | 'unknown';
  medical_conditions: string | null;
  updated_at: string | Date | null;
}

interface AllergyRow {
  id: number;
  line_user_id: string;
  line_account_id: number | null;
  drug_name: string;
  drug_id: number | null;
  reaction_type: 'rash' | 'breathing' | 'swelling' | 'other';
  reaction_notes: string | null;
  severity: 'mild' | 'moderate' | 'severe';
  created_at: string | Date;
}

interface MedicationRow {
  id: number;
  line_user_id: string;
  line_account_id: number | null;
  medication_name: string;
  product_id: number | null;
  dosage: string | null;
  frequency: string | null;
  start_date: string | Date | null;
  notes: string | null;
  is_active: number;
  created_at: string | Date;
  updated_at: string | Date;
}

function asDateTimeString(value: string | Date | null): string | null {
  if (value === null) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

function asDateString(value: string | Date | null): string | null {
  if (value === null) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
}

/** `calculateCompletionPercentage()` — 9 tracked fields, round((filled/9)*100). */
function calculateCompletionPercent(
  profile: ProfileRow,
  allergies: AllergyRow[],
  medications: MedicationRow[],
  conditions: unknown[]
): number {
  let filled = 0;
  if (profile.name) filled++;
  if (profile.age) filled++;
  if (profile.gender) filled++;
  if (profile.weight) filled++;
  if (profile.height) filled++;
  if (profile.blood_type && profile.blood_type !== 'unknown') filled++;
  if (conditions.length > 0) filled++;
  if (allergies.length > 0) filled++;
  if (medications.length > 0) filled++;
  return Math.round((filled / 9) * 100);
}

function decodeConditions(value: string | null): string[] {
  if (!value) return [];
  try {
    const decoded: unknown = JSON.parse(value);
    return Array.isArray(decoded) ? decoded.filter((v): v is string => typeof v === 'string') : [];
  } catch {
    return [];
  }
}

export async function getHealthProfileAction(
  db: Kysely<TenantDB>,
  lineUserId: string | null,
  lineAccountId: number
): Promise<HealthProfileGetResponse> {
  if (!lineUserId) {
    return { success: false, error: 'Missing line_user_id' };
  }

  try {
    let profile: ProfileRow;
    const existing = await sql<ProfileRow>`
      SELECT id, name, age, gender, weight, height, blood_type, medical_conditions, updated_at
        FROM user_health_profiles WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId}
    `.execute(db);

    if (existing.rows[0]) {
      profile = existing.rows[0];
    } else {
      // Create empty profile — mirrors PHP's auto-INSERT-on-first-view.
      await sql`
        INSERT INTO user_health_profiles (line_user_id, line_account_id) VALUES (${lineUserId}, ${lineAccountId})
      `.execute(db);
      profile = {
        id: 0,
        name: null,
        age: null,
        gender: null,
        weight: null,
        height: null,
        blood_type: 'unknown',
        medical_conditions: null,
        updated_at: null,
      };
    }

    const allergiesResult = await sql<AllergyRow>`
      SELECT id, line_user_id, line_account_id, drug_name, drug_id, reaction_type, reaction_notes, severity, created_at
        FROM user_drug_allergies WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId}
       ORDER BY created_at DESC
    `.execute(db);

    const medicationsResult = await sql<MedicationRow>`
      SELECT id, line_user_id, line_account_id, medication_name, product_id, dosage, frequency, start_date, notes, is_active, created_at, updated_at
        FROM user_current_medications
       WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId} AND is_active = 1
       ORDER BY created_at DESC
    `.execute(db);

    const conditions = decodeConditions(profile.medical_conditions);
    const completion = calculateCompletionPercent(profile, allergiesResult.rows, medicationsResult.rows, conditions);

    return {
      success: true,
      profile: {
        personal_info: {
          name: profile.name,
          age: profile.age,
          gender: profile.gender,
          // CONTRACT-DRIFT FIX: pass the raw DECIMAL value straight through, same
          // as every non-numeric column below — PHP's getHealthProfile() returns
          // `$profile['weight']`/`$profile['height']` UNCAST (raw PDO string,
          // e.g. "70.50"/"175.00"), never `(float)`-cast. Number()-casting here
          // silently turned those into JS numbers (70.5/175), a real field-level
          // parity mismatch (mig-verify Phase 3 batch 1 finding).
          weight: profile.weight,
          height: profile.height,
          blood_type: profile.blood_type,
        },
        medical_conditions: conditions,
        allergies: allergiesResult.rows.map((row) => ({
          id: Number(row.id),
          line_user_id: row.line_user_id,
          line_account_id: row.line_account_id === null ? null : Number(row.line_account_id),
          drug_name: row.drug_name,
          drug_id: row.drug_id === null ? null : Number(row.drug_id),
          reaction_type: row.reaction_type,
          reaction_notes: row.reaction_notes,
          severity: row.severity,
          created_at: asDateTimeString(row.created_at) ?? '',
        })),
        medications: medicationsResult.rows.map((row) => ({
          id: Number(row.id),
          line_user_id: row.line_user_id,
          line_account_id: row.line_account_id === null ? null : Number(row.line_account_id),
          medication_name: row.medication_name,
          product_id: row.product_id === null ? null : Number(row.product_id),
          dosage: row.dosage,
          frequency: row.frequency,
          start_date: asDateString(row.start_date),
          notes: row.notes,
          is_active: row.is_active,
          created_at: asDateTimeString(row.created_at) ?? '',
          updated_at: asDateTimeString(row.updated_at) ?? '',
        })),
        completion_percent: completion,
        updated_at: asDateTimeString(profile.updated_at),
      },
    };
  } catch {
    return { success: false, error: 'Database error' };
  }
}
