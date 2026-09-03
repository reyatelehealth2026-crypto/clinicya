import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * appointments.ts — zod contracts for api/appointments.php (762 lines, read in full before writing
 * this file) — the FIVE actions with real line-mini-app callers (grep-verified against
 * line-mini-app/src/lib/appointments-api.ts AND src/components/miniapp/VideoClient.tsx, which calls
 * this endpoint directly with `fetch()`, bypassing the lib wrapper): `pharmacists` (GET),
 * `available_slots` (GET), `book` (POST), `my_appointments` (GET), `cancel` (POST).
 * `pharmacist_detail`, `today_appointments`, `detail`, `rate` are explicitly OUT of scope this batch —
 * zero callers (confirmed by grep; `today_appointments` looks admin-facing, `detail`/`rate` have no UI
 * wired to them at all).
 *
 * RESPONSE ENVELOPE: api/appointments.php's own local `jsonResponse($success, $message, $data = [])`
 * does `echo json_encode(['success'=>$success, 'message'=>$message, ...$data])` — same flat
 * always-HTTP-200 shape as member.php/rewards.php (no header()/http_response_code() call anywhere in
 * the file — every branch, including outright failures, responds HTTP 200). This file therefore builds
 * on the shared `flatSuccessEnvelope()` helper throughout, exactly like member.ts/rewards.ts, NOT the
 * per-branch-status-code convention health-profile.ts uses.
 *
 * SUBTLE TRAP — PRESERVE, DO NOT "FIX": because PHP's `[...]` array spread (later key wins on
 * collision) puts `...$data` AFTER the literal `'message'=>$message` key, two branches of
 * `handleAvailableSlots()` pass their OWN `message` key INSIDE `$data`
 * (`jsonResponse(true, 'OK', ['slots'=>[], 'message'=>'วันหยุด'])` for the holiday case,
 * `jsonResponse(true, 'OK', ['slots'=>[], 'message'=>'ไม่มีตารางในวันนี้'])` for the
 * no-schedule-for-this-weekday case), which SILENTLY OVERRIDES the outer `'OK'` message argument. The
 * actual wire response on both branches is `{success:true, message:'วันหยุด'|'ไม่มีตารางในวันนี้',
 * slots:[]}` — `message` is NEVER `'OK'` on these two branches, even though the PHP source reads
 * `jsonResponse(true, 'OK', [...])` at the call site. Modeled below as two distinct literal-`message`
 * success variants in `AppointmentsAvailableSlotsResponseSchema`'s union (`AvailableSlotsHoliday` /
 * `AvailableSlotsNoSchedule`), never collapsed into one "message optional/generic string" shape — a
 * port that returned `message:'OK'` here would be a real, silent contract regression. Ported the same
 * way (spreading `data` after `message` in a JS object literal, which has the identical later-key-wins
 * semantics) in apps/admin's `_lib/handlers.ts`.
 *
 * appointment_id FORMAT (book's response — for a future FORMAT_CHECKS regex in the parity harness,
 * infra/e2e's territory, not this file's — documented here per this batch's brief so the parity
 * harness's author can read it off this contract): `'APT' . date('ymdHis') . rand(100, 999)` — literal
 * prefix `APT` + 12 digits (`ymdHis`: 2-digit Gregorian year, month, day, hour, minute, second) + 3
 * digits (`rand(100, 999)`) = `APT` + 15 digits total, e.g. `APT260713143022517`. Exported below as
 * `APPOINTMENT_ID_FORMAT_REGEX`. This ID is NOT persisted anywhere on the canonical tenant template
 * (see the DYNAMIC-COLUMN VERIFICATION note below) — it exists ONLY in the JSON response, never in a DB
 * column, so `cancel`/lookups always resolve through the appointment's numeric `id` (auto-increment
 * PK), never this generated string.
 *
 * DYNAMIC-COLUMN VERIFICATION (packages/db/src/generated/tenant-db.d.ts, per the brief's
 * "ported-as-defensive vs skipped-as-no-op" discipline — verified myself, documented here since this is
 * the contract file the brief asked findings to live in, not infra/):
 *
 *   - `pharmacists`: EVERY optional column api/appointments.php's `SHOW COLUMNS FROM pharmacists`
 *     checks for (title, specialty, sub_specialty, hospital, license_no, bio, consulting_areas,
 *     work_experience, image_url, rating, review_count, consultation_fee, consultation_duration,
 *     is_available, is_active, line_account_id) IS present, unconditionally, on the committed
 *     template's generated `Pharmacists` interface — a verified no-op, matching the precedent already
 *     set by health-profile's query.ts and wishlist's route.ts (both of which found their own PHP's
 *     defensive runtime column-introspection to be a no-op against the canonical template).
 *     SKIPPED-AS-NO-OP in the Next port: apps/admin's `_lib/handlers.ts` always selects/checks the full
 *     column set unconditionally, no `SHOW COLUMNS FROM pharmacists` at request time.
 *
 *   - `appointments`: NOT a no-op — genuinely different outcome from the pharmacists table above, and
 *     from the brief's stated "expected" precedent. The committed template's `Appointments` interface
 *     (`database/migration_2026-05-25_tenant_template.sql`'s `CREATE TABLE appointments`, verified
 *     directly, not just via the generated types) has NO `appointment_id`, `end_time`, `duration`,
 *     `type`, `symptoms`, or `consultation_fee` columns at all — it has `duration_minutes` and
 *     `appointment_type` instead, with different NAMES and, for `appointment_type`, a different ENUM
 *     vocabulary (`consultation|video_call|pickup|delivery`) than the free-text `type`/`symptoms`
 *     fields the client sends. Of every optional column `handleBook()`'s dynamic INSERT and
 *     `handleCancel()`'s dynamic UPDATE check for, only `line_account_id` and `cancelled_reason`
 *     actually exist on this template. Practical effect, verified against the real schema:
 *       - `handleBook()`'s effective INSERT is only
 *         `(user_id, pharmacist_id, appointment_date, appointment_time, status, line_account_id)` —
 *         the client's `type`/`symptoms` payload fields are silently discarded, never persisted
 *         anywhere (not even into the unrelated `notes` column, since PHP never checks for a `notes`
 *         key in this handler — only the literal `symptoms` key, which doesn't exist as a column).
 *       - `handleCancel()`'s dynamic UPDATE only ever sets `cancelled_reason` (present), never
 *         `cancelled_by` (absent), and its appointment lookup ALWAYS takes the `WHERE id = ? AND
 *         user_id = ?` branch (`appointment_id` column absent, so `hasAptIdCol` is always false) —
 *         the `appointment_id` request field is run through PHP's `intval($appointmentId)`, which in
 *         real traffic IS the row's numeric `id` (confirmed: line-mini-app's `AppointmentsClient.tsx`
 *         calls `cancelAppointment(lineUserId, apt.id)` with the numeric row id from `my_appointments`,
 *         never the unpersisted generated string).
 *     Because this is a genuine (not no-op) column-presence difference, and because member.php's own
 *     `_lib/columns.ts` establishes the sibling precedent that `SHOW COLUMNS`-based introspection IS
 *     ported faithfully when tenant DBs can genuinely drift from the canonical template, this batch
 *     PORTS (not skips) real `SHOW COLUMNS FROM appointments` / `SHOW COLUMNS FROM pharmacists`-style
 *     runtime introspection for the `appointments` table specifically, in
 *     apps/admin/.../appointments/_lib/columns.ts — so a drifted tenant DB that DOES have these columns
 *     still gets them populated, exactly as PHP would, rather than being permanently hard-coded to the
 *     reduced column set this ONE seeded harness tenant happens to expose.
 *
 * INSURANCES: `pharmacists`'s `pharmacist_insurances`/`insurances` JOIN is wrapped in try/catch in the
 * PHP source; both tables are CONFIRMED ABSENT from `database/migration_2026-05-25_tenant_template.sql`
 * (verified — no `CREATE TABLE` for either name anywhere in that file, and no corresponding interface
 * in the generated tenant-db types), so `pharmacists[].insurances` always resolves to `[]` in both PHP
 * and this port. Adding those tables is out of scope for this batch.
 *
 * DEAD CODE PRESERVED: `handleCancel()` computes `$hoursUntil` (days*24 + hours until the appointment)
 * and a comment claims "at least 2 hours before", but NO code path ever actually compares `$hoursUntil`
 * against a threshold — the only real gate is `if ($appointmentDateTime <= $now)` ("already passed").
 * A cancellation 5 minutes before the appointment succeeds in the real PHP. Not "fixed" here.
 */

// ---------------------------------------------------------------------------
// Shared sub-shapes
// ---------------------------------------------------------------------------

/**
 * `SELECT {selectCols} FROM pharmacists {whereClause} ORDER BY id DESC` (see the DYNAMIC-COLUMN
 * VERIFICATION note above — every optional column below is unconditionally selected, no runtime
 * `SHOW COLUMNS` check), plus the per-row post-processing `handleGetPharmacists()` applies.
 */
export const PharmacistListItemSchema = z.object({
  id: z.number(),
  name: z.string(),
  // Defaulted when the DB value is NULL — PHP's `isset($p['x'])` is false for a null column value, not
  // just a missing key, so these seven fields are NEVER actually null/absent in the real response.
  title: z.string(),
  specialty: z.string(),
  is_available: z.union([z.number(), z.boolean()]),
  rating: z.union([z.number(), z.string()]),
  review_count: z.union([z.number(), z.string()]),
  consultation_fee: z.union([z.number(), z.string()]),
  consultation_duration: z.union([z.number(), z.string()]),
  // Selected (column verified present) but never defaulted by handleGetPharmacists() — raw passthrough.
  sub_specialty: z.string().nullable(),
  hospital: z.string().nullable(),
  license_no: z.string().nullable(),
  bio: z.string().nullable(),
  consulting_areas: z.string().nullable(),
  work_experience: z.string().nullable(),
  image_url: z.string().nullable(),
  is_active: z.union([z.number(), z.boolean()]).nullable(),
  line_account_id: z.number().nullable(),
  /** `SELECT COUNT(*) FROM appointments WHERE pharmacist_id = ? AND status = 'completed'`, 0 on error. */
  case_count: z.number(),
  /** Always `[]` in practice — see the INSURANCES note above. */
  insurances: z.array(z.unknown()),
});
export type PharmacistListItem = z.infer<typeof PharmacistListItemSchema>;

/**
 * `SELECT a.*, p.name as pharmacist_name, p.title as pharmacist_title, p.specialty, p.image_url as
 * pharmacist_image FROM appointments a JOIN pharmacists p ON a.pharmacist_id = p.id` — raw `a.*`
 * columns per the committed template's `appointments` table (see DYNAMIC-COLUMN VERIFICATION above),
 * plus the four joined pharmacist display fields.
 */
export const AppointmentRowSchema = z.object({
  id: z.number(),
  line_account_id: z.number().nullable(),
  user_id: z.number(),
  pharmacist_id: z.number().nullable(),
  appointment_type: z.enum(['consultation', 'video_call', 'pickup', 'delivery']).nullable(),
  appointment_date: z.string(),
  appointment_time: z.string(),
  duration_minutes: z.number().nullable(),
  status: z.enum(['pending', 'confirmed', 'completed', 'cancelled', 'no_show']).nullable(),
  notes: z.string().nullable(),
  reminder_sent: z.union([z.number(), z.boolean()]).nullable(),
  created_at: z.string(),
  updated_at: z.string(),
  reminder_10min_sent: z.union([z.number(), z.boolean()]).nullable(),
  reminder_now_sent: z.union([z.number(), z.boolean()]).nullable(),
  cancelled_reason: z.string().nullable(),
  pharmacist_name: z.string(),
  pharmacist_title: z.string().nullable(),
  specialty: z.string().nullable(),
  pharmacist_image: z.string().nullable(),
});
export type AppointmentRow = z.infer<typeof AppointmentRowSchema>;

// ---------------------------------------------------------------------------
// GET action=pharmacists
// ---------------------------------------------------------------------------

export const AppointmentsPharmacistsQuerySchema = z.object({
  action: z.literal('pharmacists'),
  line_account_id: z.union([z.string(), z.number()]).optional(), // `$_GET['line_account_id'] ?? 1` — read but unused for filtering (no WHERE clause references it)
});
export type AppointmentsPharmacistsQuery = z.infer<typeof AppointmentsPharmacistsQuerySchema>;

const AppointmentsPharmacistsOk = flatSuccessEnvelope({
  success: z.literal(true),
  pharmacists: z.array(PharmacistListItemSchema),
});
const AppointmentsPharmacistsFail = flatSuccessEnvelope({
  success: z.literal(false),
  pharmacists: z.array(PharmacistListItemSchema).optional(), // present ([]) only on the catch-branch failure
});
export const AppointmentsPharmacistsResponseSchema = z.union([AppointmentsPharmacistsOk, AppointmentsPharmacistsFail]);
export type AppointmentsPharmacistsResponse = z.infer<typeof AppointmentsPharmacistsResponseSchema>;

// ---------------------------------------------------------------------------
// GET action=available_slots
// ---------------------------------------------------------------------------

export const AppointmentsAvailableSlotsQuerySchema = z.object({
  action: z.literal('available_slots'),
  pharmacist_id: z.union([z.string(), z.number()]),
  date: z.string().optional(), // `$_GET['date'] ?? date('Y-m-d')`
});
export type AppointmentsAvailableSlotsQuery = z.infer<typeof AppointmentsAvailableSlotsQuerySchema>;

export const AvailableSlotSchema = z.object({
  time: z.string(), // `H:i`
  available: z.boolean(),
});
export type AvailableSlot = z.infer<typeof AvailableSlotSchema>;

const AvailableSlotsOk = flatSuccessEnvelope({
  success: z.literal(true),
  message: z.literal('OK'),
  slots: z.array(AvailableSlotSchema),
  duration: z.number(),
});
/** See the SUBTLE TRAP doc comment above — `message` is 'วันหยุด', never 'OK', on this branch. */
const AvailableSlotsHoliday = flatSuccessEnvelope({
  success: z.literal(true),
  message: z.literal('วันหยุด'),
  slots: z.array(AvailableSlotSchema).max(0),
});
/** See the SUBTLE TRAP doc comment above — `message` is 'ไม่มีตารางในวันนี้', never 'OK', on this branch. */
const AvailableSlotsNoSchedule = flatSuccessEnvelope({
  success: z.literal(true),
  message: z.literal('ไม่มีตารางในวันนี้'),
  slots: z.array(AvailableSlotSchema).max(0),
});
const AvailableSlotsFail = flatSuccessEnvelope({ success: z.literal(false) });
export const AppointmentsAvailableSlotsResponseSchema = z.union([
  AvailableSlotsOk,
  AvailableSlotsHoliday,
  AvailableSlotsNoSchedule,
  AvailableSlotsFail,
]);
export type AppointmentsAvailableSlotsResponse = z.infer<typeof AppointmentsAvailableSlotsResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=book
// ---------------------------------------------------------------------------

export const AppointmentsBookRequestSchema = z.object({
  action: z.literal('book'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(), // `$data['line_account_id'] ?? 1`
  pharmacist_id: z.union([z.string(), z.number()]),
  date: z.string(),
  time: z.string(),
  symptoms: z.string().optional(), // accepted but NEVER persisted — see DYNAMIC-COLUMN VERIFICATION above
  type: z.string().optional(), // accepted but NEVER persisted — see DYNAMIC-COLUMN VERIFICATION above
});
export type AppointmentsBookRequest = z.infer<typeof AppointmentsBookRequestSchema>;

/** `'APT' . date('ymdHis') . rand(100, 999)` — `APT` + 12-digit timestamp + 3-digit rand = 15 digits total. */
export const APPOINTMENT_ID_FORMAT_REGEX = /^APT\d{15}$/;

const AppointmentsBookOk = flatSuccessEnvelope({
  success: z.literal(true),
  appointment_id: z.string().regex(APPOINTMENT_ID_FORMAT_REGEX),
  id: z.number(),
  date: z.string(),
  time: z.string(),
  duration: z.number(),
});
const AppointmentsBookFail = flatSuccessEnvelope({ success: z.literal(false) });
export const AppointmentsBookResponseSchema = z.union([AppointmentsBookOk, AppointmentsBookFail]);
export type AppointmentsBookResponse = z.infer<typeof AppointmentsBookResponseSchema>;

// ---------------------------------------------------------------------------
// GET action=my_appointments
// ---------------------------------------------------------------------------

export const AppointmentsMyAppointmentsQuerySchema = z.object({
  action: z.literal('my_appointments'),
  line_user_id: z.string(),
  status: z.string().optional(),
  limit: z.union([z.string(), z.number()]).optional(), // `min((int)($_GET['limit'] ?? 20), 50)`
});
export type AppointmentsMyAppointmentsQuery = z.infer<typeof AppointmentsMyAppointmentsQuerySchema>;

const AppointmentsMyAppointmentsOk = flatSuccessEnvelope({
  success: z.literal(true),
  upcoming: z.array(AppointmentRowSchema),
  past: z.array(AppointmentRowSchema),
  all: z.array(AppointmentRowSchema),
});
const AppointmentsMyAppointmentsFail = flatSuccessEnvelope({ success: z.literal(false) });
export const AppointmentsMyAppointmentsResponseSchema = z.union([
  AppointmentsMyAppointmentsOk,
  AppointmentsMyAppointmentsFail,
]);
export type AppointmentsMyAppointmentsResponse = z.infer<typeof AppointmentsMyAppointmentsResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=cancel
// ---------------------------------------------------------------------------

export const AppointmentsCancelRequestSchema = z.object({
  action: z.literal('cancel'),
  appointment_id: z.union([z.string(), z.number()]), // resolves through `intval()` to the numeric row id — see DYNAMIC-COLUMN VERIFICATION above
  line_user_id: z.string(),
  reason: z.string().optional(),
});
export type AppointmentsCancelRequest = z.infer<typeof AppointmentsCancelRequestSchema>;

/** Every branch of `handleCancel()` is a flat `{success, message}` with no extra data fields. */
export const AppointmentsCancelResponseSchema = flatSuccessEnvelope({});
export type AppointmentsCancelResponse = z.infer<typeof AppointmentsCancelResponseSchema>;

// ---------------------------------------------------------------------------
// Shared "unknown/unsupported action" shape — `default: jsonResponse(false, 'Invalid action')`, both
// the GET and POST switch statements.
// ---------------------------------------------------------------------------

export const AppointmentsInvalidActionResponseSchema = flatSuccessEnvelope({ success: z.literal(false) });
export type AppointmentsInvalidActionResponse = z.infer<typeof AppointmentsInvalidActionResponseSchema>;
