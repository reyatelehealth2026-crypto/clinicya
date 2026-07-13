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

// ---------------------------------------------------------------------------
// WRITE actions (mig-api, Phase 3 batch 2, wt-phase3b2) — appended below the GET-only contracts above,
// which are owned by the reads lane and left untouched (no renames/removals of anything above this
// point). Read api/health-profile.php in full (781 lines) before editing this section.
//
// Client-verified action set (line-mini-app/src/lib/health-api.ts, grepped across all of
// line-mini-app/src): `update_personal`, `update_medical_history`, `add_allergy`, `remove_allergy`,
// `add_medication`, `remove_medication` — SIX of api/health-profile.php's SEVEN POST actions.
// `update_medication` has ZERO callers and is explicitly OUT of scope — not modeled here.
//
// RESPONSE ENVELOPE — same file-level `jsonResponse($data, $statusCode = 200)` helper the `get` action
// above uses, i.e. `{success, ...}` per handler WITH real per-branch HTTP status codes (400 validation,
// 500 DB error, implicit 200 success) — NOT `flatSuccessEnvelope()` (no shared top-level `message` key
// convention; failures use `error`, successes use `message` — see each action's schema below for the
// exact key each branch actually carries). This extends the SAME per-status-code convention
// `HEALTH_PROFILE_GET_STATUS` already established, via one `HEALTH_PROFILE_WRITE_STATUS` map keyed per
// action (documented per-action below) rather than inventing a second envelope style in this file.
//
// TABLE AUTO-CREATE NOT PORTED — same rationale query.ts's own doc comment already gives for the `get`
// action: `createHealthProfileTables($pdo)` runs on EVERY request in the PHP original (including these
// six write actions), but packages/db's generated schema confirms `user_health_profiles`/
// `user_drug_allergies`/`user_current_medications` already exist unconditionally on the committed
// tenant template — not reproduced in apps/admin/.../health-profile/_lib/mutations.ts either.
//
// SUBTLE TRAP — PRESERVE, DO NOT "FIX": `removeAllergy()` runs
// `DELETE FROM user_drug_allergies WHERE id = ? AND line_user_id = ?` — NO `line_account_id` scoping
// at all. `removeMedication()` is the identical pattern (`UPDATE user_current_medications SET
// is_active = 0 WHERE id = ? AND line_user_id = ?`, soft-delete). Do NOT add a `line_account_id`
// predicate to either mutation's WHERE clause in the port — that would silently change which rows are
// deletable/deactivatable versus the real PHP behavior. This is safe as written (a caller must still
// supply the correct `line_user_id` the row actually belongs to), just not to be "improved" on by a
// port that assumes every mutation needs tenant scoping.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// POST action=update_personal
// ---------------------------------------------------------------------------

export const HealthProfileUpdatePersonalRequestSchema = z.object({
  action: z.literal('update_personal'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(), // `$input['line_account_id'] ?? 0`
  name: z.string().optional(),
  age: z.union([z.string(), z.number()]).optional(),
  gender: z.enum(['male', 'female', 'other']).optional(),
  weight: z.union([z.string(), z.number()]).optional(),
  height: z.union([z.string(), z.number()]).optional(),
  blood_type: z.enum(['A', 'B', 'AB', 'O', 'unknown']).optional(),
});
export type HealthProfileUpdatePersonalRequest = z.infer<typeof HealthProfileUpdatePersonalRequestSchema>;

export const HealthProfileUpdatePersonalOkSchema = z.object({
  success: z.literal(true),
  message: z.literal('บันทึกข้อมูลส่วนตัวแล้ว'),
});
export const HealthProfileUpdatePersonalFailSchema = z.object({
  success: z.literal(false),
  error: z.enum(['Missing line_user_id', 'Invalid age', 'Invalid gender', 'Database error']),
});
export const HealthProfileUpdatePersonalResponseSchema = z.union([
  HealthProfileUpdatePersonalOkSchema,
  HealthProfileUpdatePersonalFailSchema,
]);
export type HealthProfileUpdatePersonalResponse = z.infer<typeof HealthProfileUpdatePersonalResponseSchema>;

/** `updatePersonalInfo()`'s four `jsonResponse()` status codes; ok is implicit 200. */
export const HEALTH_PROFILE_UPDATE_PERSONAL_STATUS = {
  ok: 200,
  'Missing line_user_id': 400,
  'Invalid age': 400,
  'Invalid gender': 400,
  'Database error': 500,
} as const;

// ---------------------------------------------------------------------------
// POST action=update_medical_history
// ---------------------------------------------------------------------------

/** `$validConditions` vocabulary from `updateMedicalHistory()` — anything else is silently filtered out via `array_filter()`, never rejected. */
export const HEALTH_CONDITION_KEYS = [
  'diabetes',
  'hypertension',
  'heart_disease',
  'kidney_disease',
  'liver_disease',
  'pregnancy',
  'asthma',
  'thyroid',
  'cancer',
  'other',
] as const;

export const HealthProfileUpdateMedicalHistoryRequestSchema = z.object({
  action: z.literal('update_medical_history'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  /** Unknown values are silently dropped server-side, not rejected — the request schema stays permissive (`z.array(z.string())`), matching PHP's `array_filter()` behavior rather than a `z.enum()` per element. */
  conditions: z.array(z.string()).optional(),
});
export type HealthProfileUpdateMedicalHistoryRequest = z.infer<typeof HealthProfileUpdateMedicalHistoryRequestSchema>;

export const HealthProfileUpdateMedicalHistoryOkSchema = z.object({
  success: z.literal(true),
  message: z.literal('บันทึกประวัติการแพทย์แล้ว'),
});
export const HealthProfileUpdateMedicalHistoryFailSchema = z.object({
  success: z.literal(false),
  error: z.enum(['Missing line_user_id', 'Database error']),
});
export const HealthProfileUpdateMedicalHistoryResponseSchema = z.union([
  HealthProfileUpdateMedicalHistoryOkSchema,
  HealthProfileUpdateMedicalHistoryFailSchema,
]);
export type HealthProfileUpdateMedicalHistoryResponse = z.infer<typeof HealthProfileUpdateMedicalHistoryResponseSchema>;

export const HEALTH_PROFILE_UPDATE_MEDICAL_HISTORY_STATUS = {
  ok: 200,
  'Missing line_user_id': 400,
  'Database error': 500,
} as const;

// ---------------------------------------------------------------------------
// POST action=add_allergy
// ---------------------------------------------------------------------------

export const HealthProfileAddAllergyRequestSchema = z.object({
  action: z.literal('add_allergy'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  drug_name: z.string(),
  drug_id: z.union([z.string(), z.number()]).nullable().optional(),
  reaction_type: z.enum(['rash', 'breathing', 'swelling', 'other']).optional(),
  reaction_notes: z.string().optional(),
  severity: z.enum(['mild', 'moderate', 'severe']).optional(),
});
export type HealthProfileAddAllergyRequest = z.infer<typeof HealthProfileAddAllergyRequestSchema>;

export const HealthProfileAddAllergyOkSchema = z.object({
  success: z.literal(true),
  message: z.literal('เพิ่มข้อมูลการแพ้ยาแล้ว'),
  allergy: z.object({
    id: z.number(),
    drug_name: z.string(),
    reaction_type: z.enum(['rash', 'breathing', 'swelling', 'other']),
    severity: z.enum(['mild', 'moderate', 'severe']),
  }),
});
export const HealthProfileAddAllergyFailSchema = z.object({
  success: z.literal(false),
  error: z.enum(['Missing required fields', 'ยานี้มีอยู่ในรายการแพ้ยาแล้ว', 'Database error']),
});
export const HealthProfileAddAllergyResponseSchema = z.union([
  HealthProfileAddAllergyOkSchema,
  HealthProfileAddAllergyFailSchema,
]);
export type HealthProfileAddAllergyResponse = z.infer<typeof HealthProfileAddAllergyResponseSchema>;

export const HEALTH_PROFILE_ADD_ALLERGY_STATUS = {
  ok: 200,
  'Missing required fields': 400,
  'ยานี้มีอยู่ในรายการแพ้ยาแล้ว': 400,
  'Database error': 500,
} as const;

// ---------------------------------------------------------------------------
// POST action=remove_allergy
// ---------------------------------------------------------------------------

export const HealthProfileRemoveAllergyRequestSchema = z.object({
  action: z.literal('remove_allergy'),
  line_user_id: z.string(),
  allergy_id: z.union([z.string(), z.number()]),
});
export type HealthProfileRemoveAllergyRequest = z.infer<typeof HealthProfileRemoveAllergyRequestSchema>;

export const HealthProfileRemoveAllergyOkSchema = z.object({
  success: z.literal(true),
  message: z.literal('ลบข้อมูลการแพ้ยาแล้ว'),
});
export const HealthProfileRemoveAllergyFailSchema = z.object({
  success: z.literal(false),
  error: z.enum(['Missing required fields', 'Database error']),
});
export const HealthProfileRemoveAllergyResponseSchema = z.union([
  HealthProfileRemoveAllergyOkSchema,
  HealthProfileRemoveAllergyFailSchema,
]);
export type HealthProfileRemoveAllergyResponse = z.infer<typeof HealthProfileRemoveAllergyResponseSchema>;

export const HEALTH_PROFILE_REMOVE_ALLERGY_STATUS = {
  ok: 200,
  'Missing required fields': 400,
  'Database error': 500,
} as const;

// ---------------------------------------------------------------------------
// POST action=add_medication
// ---------------------------------------------------------------------------

export const HealthProfileAddMedicationRequestSchema = z.object({
  action: z.literal('add_medication'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  medication_name: z.string(),
  product_id: z.union([z.string(), z.number()]).nullable().optional(),
  dosage: z.string().optional(),
  frequency: z.string().optional(),
  start_date: z.string().optional(),
  notes: z.string().optional(),
});
export type HealthProfileAddMedicationRequest = z.infer<typeof HealthProfileAddMedicationRequestSchema>;

/**
 * `checkMedicationInteractions()`'s per-interaction shape. See mutations.ts's own doc comment for why
 * this is realistically always `[]` against the committed tenant template (the JOIN's `di.drug1_id`/
 * `di.drug2_id` columns don't exist on `drug_interactions` there) — modeled here anyway because the
 * PHP source's *shape* is what a schema-drifted or future-migrated tenant would actually return.
 */
export const HealthMedicationInteractionSchema = z.object({
  drug1: z.string(),
  drug2: z.string(),
  severity: z.string(),
  description: z.string(),
  recommendation: z.string(),
});
export type HealthMedicationInteraction = z.infer<typeof HealthMedicationInteractionSchema>;

const HealthProfileAddMedicationOkBase = z.object({
  success: z.literal(true),
  message: z.literal('เพิ่มยาที่ใช้ประจำแล้ว'),
  medication: z.object({
    id: z.number(),
    medication_name: z.string(),
    dosage: z.string(),
    frequency: z.string(),
  }),
});
/**
 * `$response['interactions']`/`$response['has_interactions']` are only present when interactions were
 * found — absent (not `[]`/`false`) otherwise. The extended (with-interactions) variant MUST be listed
 * FIRST in this union: zod's `z.object()` is "strip" mode by default (unmatched extra keys are dropped
 * silently on a successful parse, they don't fail it), so if the no-interactions base schema were tried
 * first it would also "successfully" match a with-interactions payload and z.union() would return
 * whichever schema succeeds first — silently discarding `interactions`/`has_interactions` from the
 * parsed result. Ordering this way makes the union true to PHP's actual conditional key presence.
 */
export const HealthProfileAddMedicationOkSchema = z.union([
  HealthProfileAddMedicationOkBase.extend({
    interactions: z.array(HealthMedicationInteractionSchema),
    has_interactions: z.literal(true),
  }),
  HealthProfileAddMedicationOkBase,
]);
export const HealthProfileAddMedicationFailSchema = z.object({
  success: z.literal(false),
  error: z.enum(['Missing required fields', 'Database error']),
});
export const HealthProfileAddMedicationResponseSchema = z.union([
  HealthProfileAddMedicationOkSchema,
  HealthProfileAddMedicationFailSchema,
]);
export type HealthProfileAddMedicationResponse = z.infer<typeof HealthProfileAddMedicationResponseSchema>;

export const HEALTH_PROFILE_ADD_MEDICATION_STATUS = {
  ok: 200,
  'Missing required fields': 400,
  'Database error': 500,
} as const;

// ---------------------------------------------------------------------------
// POST action=remove_medication
// ---------------------------------------------------------------------------

export const HealthProfileRemoveMedicationRequestSchema = z.object({
  action: z.literal('remove_medication'),
  line_user_id: z.string(),
  medication_id: z.union([z.string(), z.number()]),
});
export type HealthProfileRemoveMedicationRequest = z.infer<typeof HealthProfileRemoveMedicationRequestSchema>;

export const HealthProfileRemoveMedicationOkSchema = z.object({
  success: z.literal(true),
  message: z.literal('ลบยาออกจากรายการแล้ว'),
});
export const HealthProfileRemoveMedicationFailSchema = z.object({
  success: z.literal(false),
  error: z.enum(['Missing required fields', 'Database error']),
});
export const HealthProfileRemoveMedicationResponseSchema = z.union([
  HealthProfileRemoveMedicationOkSchema,
  HealthProfileRemoveMedicationFailSchema,
]);
export type HealthProfileRemoveMedicationResponse = z.infer<typeof HealthProfileRemoveMedicationResponseSchema>;

export const HEALTH_PROFILE_REMOVE_MEDICATION_STATUS = {
  ok: 200,
  'Missing required fields': 400,
  'Database error': 500,
} as const;

// ---------------------------------------------------------------------------
// Combined write-status map — one entry per action, mirroring `HEALTH_PROFILE_GET_STATUS`'s shape so
// route.ts's POST dispatcher can look up `HEALTH_PROFILE_WRITE_STATUS[action][key]` uniformly instead
// of importing six differently-named per-action consts. The six per-action consts above are ALSO
// exported individually (not just via this map) since some already-written call sites/tests may prefer
// the narrower, action-specific type.
// ---------------------------------------------------------------------------
export const HEALTH_PROFILE_WRITE_STATUS = {
  update_personal: HEALTH_PROFILE_UPDATE_PERSONAL_STATUS,
  update_medical_history: HEALTH_PROFILE_UPDATE_MEDICAL_HISTORY_STATUS,
  add_allergy: HEALTH_PROFILE_ADD_ALLERGY_STATUS,
  remove_allergy: HEALTH_PROFILE_REMOVE_ALLERGY_STATUS,
  add_medication: HEALTH_PROFILE_ADD_MEDICATION_STATUS,
  remove_medication: HEALTH_PROFILE_REMOVE_MEDICATION_STATUS,
} as const;
