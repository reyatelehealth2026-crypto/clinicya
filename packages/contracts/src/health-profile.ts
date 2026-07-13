import { z } from 'zod';

/**
 * health-profile.ts — zod contracts for api/health-profile.php's `action=get`
 * ONLY (this batch's scope — `get_allergies`/`get_medications`/`search_drugs`
 * all have zero line-mini-app callers per the brief's grep verification;
 * `get` already nests allergies+medications, making those three redundant
 * for this client). All seven POST actions (update_personal,
 * update_medical_history, add_allergy, remove_allergy, add_medication,
 * update_medication, remove_medication) are writes and out of this
 * (reads-lane) batch's scope entirely. Read api/health-profile.php in full
 * (782 lines) before writing this file.
 *
 * RESPONSE ENVELOPE — `{success, ...}` per handler, WITH real HTTP status
 * codes via the file's own `jsonResponse($data, $statusCode = 200)` helper
 * (400 validation, 500 DB error) — NOT `flatSuccessEnvelope()` (no top-level
 * `message` key on the `get` action's success/failure branches; a `message`
 * key does appear on some of the (out-of-scope) write actions, but never on
 * `get`).
 */

// ---------------------------------------------------------------------------
// GET request (action=get)
// ---------------------------------------------------------------------------

export const HealthProfileGetQuerySchema = z.object({
  action: z.literal('get'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(), // `$_GET['line_account_id'] ?? 0`
});
export type HealthProfileGetQuery = z.infer<typeof HealthProfileGetQuerySchema>;

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------

export const HealthPersonalInfoSchema = z.object({
  name: z.string().nullable(),
  age: z.number().nullable(),
  gender: z.enum(['male', 'female', 'other']).nullable(),
  weight: z.union([z.number(), z.string()]).nullable(),
  height: z.union([z.number(), z.string()]).nullable(),
  blood_type: z.enum(['A', 'B', 'AB', 'O', 'unknown']),
});
export type HealthPersonalInfo = z.infer<typeof HealthPersonalInfoSchema>;

/** `SELECT * FROM user_drug_allergies WHERE line_user_id = ? AND line_account_id = ? ORDER BY created_at DESC` — raw row, no projection. */
export const HealthAllergySchema = z.object({
  id: z.number(),
  line_user_id: z.string(),
  line_account_id: z.number().nullable(),
  drug_name: z.string(),
  drug_id: z.number().nullable(),
  reaction_type: z.enum(['rash', 'breathing', 'swelling', 'other']),
  reaction_notes: z.string().nullable(),
  severity: z.enum(['mild', 'moderate', 'severe']),
  created_at: z.string(),
});
export type HealthAllergy = z.infer<typeof HealthAllergySchema>;

/** `SELECT * FROM user_current_medications WHERE line_user_id = ? AND line_account_id = ? AND is_active = 1 ORDER BY created_at DESC` — raw row. */
export const HealthMedicationSchema = z.object({
  id: z.number(),
  line_user_id: z.string(),
  line_account_id: z.number().nullable(),
  medication_name: z.string(),
  product_id: z.number().nullable(),
  dosage: z.string().nullable(),
  frequency: z.string().nullable(),
  start_date: z.string().nullable(),
  notes: z.string().nullable(),
  is_active: z.union([z.number(), z.boolean()]),
  created_at: z.string(),
  updated_at: z.string(),
});
export type HealthMedication = z.infer<typeof HealthMedicationSchema>;

export const HealthProfileSchema = z.object({
  personal_info: HealthPersonalInfoSchema,
  /** `json_decode($profile['medical_conditions'], true) ?: []` — array of the fixed condition-key strings (see updateMedicalHistory()'s $validConditions for the vocabulary; not re-validated here since this is a READ contract). */
  medical_conditions: z.array(z.string()),
  allergies: z.array(HealthAllergySchema),
  medications: z.array(HealthMedicationSchema),
  /** `calculateCompletionPercentage()` — round((filled/9)*100), 0-100 integer. */
  completion_percent: z.number(),
  updated_at: z.string().nullable(),
});
export type HealthProfile = z.infer<typeof HealthProfileSchema>;

export const HealthProfileGetOkSchema = z.object({
  success: z.literal(true),
  profile: HealthProfileSchema,
});
export type HealthProfileGetOk = z.infer<typeof HealthProfileGetOkSchema>;

export const HealthProfileGetFailSchema = z.object({
  success: z.literal(false),
  error: z.enum(['Missing line_user_id', 'Database error']),
});
export type HealthProfileGetFail = z.infer<typeof HealthProfileGetFailSchema>;

export const HealthProfileGetResponseSchema = z.union([HealthProfileGetOkSchema, HealthProfileGetFailSchema]);
export type HealthProfileGetResponse = z.infer<typeof HealthProfileGetResponseSchema>;

/** getHealthProfile()'s two jsonResponse() status codes; the ok branch is implicit 200 (no explicit statusCode arg). */
export const HEALTH_PROFILE_GET_STATUS = {
  ok: 200,
  'Missing line_user_id': 400,
  'Database error': 500,
} as const;
