import { z } from 'zod';

/**
 * medication-reminders.ts — zod contracts for the ported `api/medication-
 * reminders.php` (338 lines, read in full) actions owned by this batch:
 * `list` (GET, also the default action when `action` is omitted — PHP's
 * `$input['action'] ?? $_GET['action'] ?? 'list'`), `add` (POST), `delete`
 * (POST), `mark_taken` (POST). `update`/`history`/`adherence`/`from_order`
 * are explicitly OUT of scope — zero line-mini-app callers anywhere
 * (`reminders-api.ts` only ever builds `list`/`add`/`delete`/`mark_taken`
 * requests), confirmed via grep.
 *
 * ROUTING: unlike `consent.php`/`data-rights.php`, this file DOES `require_once
 * bootstrap/route_by_account.php` — standard two-phase tenant resolution, no
 * deviation from the rest of this batch.
 *
 * ENVELOPE: AD HOC, like `wishlist.php` — raw `echo json_encode([...])` at
 * each call site, NO shared `jsonResponse()` helper, NO `http_response_code()`
 * call anywhere in the file (always implicit HTTP 200), inconsistent
 * `'error'` vs `'message'` keys across branches (`list`/`mark_taken`'s ok
 * branch use `message`; every failure branch uses `error`; `add`'s ok branch
 * uses BOTH `reminder_id` and `message`) — modeled the same way
 * `packages/contracts/src/wishlist.ts`'s own doc comment describes
 * wishlist's shape: hand-built per-branch schemas, NOT `flatSuccessEnvelope()`.
 *
 * SUBTLE TRAPS replicated exactly (see the Route Handler, not just this
 * contract, for the actual behavior — this file only documents the shapes):
 *   - `delete` does `UPDATE ... WHERE id = ? AND user_id = ?` with NO
 *     existence/ownership check beforehand, and returns
 *     `{success:true,message:'ลบการเตือนแล้ว'}` REGARDLESS of whether any row
 *     actually matched (deleting someone else's or a nonexistent
 *     `reminder_id` silently "succeeds" with 0 rows affected). Do not add a
 *     real 404/ownership check the PHP original doesn't have.
 *   - `mark_taken` DOES verify ownership first (`SELECT ... WHERE id=? AND
 *     user_id=?`) before inserting into `medication_taken_history` —
 *     deliberately asymmetric with `delete`'s lack of a check; both are
 *     preserved verbatim, not "fixed" into consistency.
 *   - The top-level `catch(Exception $e)` returns
 *     `{success:false,error:$e->getMessage()}` — a raw exception-message
 *     leak. Modeled as `error: z.string()` (arbitrary), not sanitized.
 *
 * TABLE AUTO-CREATE NOT PORTED (same flagged-simplification precedent as
 * `health-profile/_lib/query.ts`'s own doc comment, and per this brief's own
 * instruction): the two `CREATE TABLE IF NOT EXISTS` calls at the top of
 * `api/medication-reminders.php` (`medication_reminders`,
 * `medication_taken_history`) are NOT reproduced — both tables are confirmed
 * present, unconditionally, in the committed tenant template /
 * `packages/db`'s generated Kysely types.
 */

// ---------------------------------------------------------------------------
// Shared sub-shape
// ---------------------------------------------------------------------------

/**
 * `SELECT r.*, (SELECT COUNT... taken_count_7d), (SELECT COUNT... missed_count_7d)
 *  FROM medication_reminders r WHERE r.user_id = ? AND r.is_active = 1
 *  ORDER BY r.created_at DESC` — plus PHP's post-query mutation:
 * `reminder_times` is JSON-decoded (`?: []` on decode failure), and
 * `adherence_percent` is computed from `taken_count_7d`/`missed_count_7d`
 * (100 when there were zero doses in the 7-day window).
 */
export const MedicationReminderItemSchema = z.object({
  id: z.number(),
  user_id: z.number(),
  line_user_id: z.string().nullable(),
  line_account_id: z.number().nullable(),
  medication_name: z.string(),
  dosage: z.string().nullable(),
  frequency: z.string().nullable(),
  reminder_times: z.array(z.string()),
  start_date: z.string().nullable(),
  end_date: z.string().nullable(),
  notes: z.string().nullable(),
  is_active: z.union([z.literal(0), z.literal(1)]),
  product_id: z.number().nullable(),
  order_id: z.number().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
  taken_count_7d: z.number(),
  missed_count_7d: z.number(),
  /** `round((taken / (taken + missed)) * 100)`, else 100 when `taken + missed === 0`. */
  adherence_percent: z.number(),
});
export type MedicationReminderItem = z.infer<typeof MedicationReminderItemSchema>;

/** Shared by every action's failure branch: `{success:false, error:<string>}` — never `message`. */
export const MedicationRemindersFailSchema = z.object({ success: z.literal(false), error: z.string() });
export type MedicationRemindersFail = z.infer<typeof MedicationRemindersFailSchema>;

// ---------------------------------------------------------------------------
// GET action=list (also the default when `action` is omitted)
// ---------------------------------------------------------------------------

export const MedicationRemindersListQuerySchema = z.object({
  action: z.literal('list').optional(),
  line_user_id: z.string().optional(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type MedicationRemindersListQuery = z.infer<typeof MedicationRemindersListQuerySchema>;

/** `!$userId && !$lineUserId` early-exit branch AND the normal populated branch share this one shape. */
const MedicationRemindersListOk = z.object({
  success: z.literal(true),
  reminders: z.array(MedicationReminderItemSchema),
});
/** `list`'s own body never fails outside the shared top-level catch — reuse the generic fail shape. */
export const MedicationRemindersListResponseSchema = z.union([MedicationRemindersListOk, MedicationRemindersFailSchema]);
export type MedicationRemindersListResponse = z.infer<typeof MedicationRemindersListResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=add
// ---------------------------------------------------------------------------

export const MedicationRemindersAddRequestSchema = z.object({
  action: z.literal('add'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  medication_name: z.string(),
  dosage: z.string().optional(),
  /** Defaults to `'daily'` server-side when absent. */
  frequency: z.string().optional(),
  /** Defaults to `['08:00']` server-side when absent. */
  reminder_times: z.array(z.string()).optional(),
  /** Defaults to today (`date('Y-m-d')`) server-side when absent. */
  start_date: z.string().optional(),
  end_date: z.string().nullable().optional(),
  notes: z.string().optional(),
  product_id: z.union([z.string(), z.number()]).nullable().optional(),
  order_id: z.union([z.string(), z.number()]).nullable().optional(),
});
export type MedicationRemindersAddRequest = z.infer<typeof MedicationRemindersAddRequestSchema>;

const MedicationRemindersAddOk = z.object({
  success: z.literal(true),
  reminder_id: z.number(),
  message: z.literal('เพิ่มการเตือนทานยาแล้ว'),
});
/** `!$userId` -> `{success:false, error:'User not found'}`; empty `medication_name` -> `{success:false, error:'กรุณาระบุชื่อยา'}`. */
export const MedicationRemindersAddResponseSchema = z.union([MedicationRemindersAddOk, MedicationRemindersFailSchema]);
export type MedicationRemindersAddResponse = z.infer<typeof MedicationRemindersAddResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=delete — NO ownership check, always succeeds (see trap above)
// ---------------------------------------------------------------------------

export const MedicationRemindersDeleteRequestSchema = z.object({
  action: z.literal('delete'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  reminder_id: z.union([z.string(), z.number()]),
});
export type MedicationRemindersDeleteRequest = z.infer<typeof MedicationRemindersDeleteRequestSchema>;

const MedicationRemindersDeleteOk = z.object({ success: z.literal(true), message: z.literal('ลบการเตือนแล้ว') });
/** Only failure branch is `!$userId` -> `{success:false, error:'User not found'}` (no ownership-miss branch exists). */
export const MedicationRemindersDeleteResponseSchema = z.union([MedicationRemindersDeleteOk, MedicationRemindersFailSchema]);
export type MedicationRemindersDeleteResponse = z.infer<typeof MedicationRemindersDeleteResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=mark_taken — DOES verify ownership first (see trap above)
// ---------------------------------------------------------------------------

export const MedicationRemindersMarkTakenRequestSchema = z.object({
  action: z.literal('mark_taken'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  reminder_id: z.union([z.string(), z.number()]),
  scheduled_time: z.string().nullable().optional(),
  /** Defaults to `'taken'` server-side when absent. */
  status: z.enum(['taken', 'skipped', 'missed']).optional(),
  notes: z.string().optional(),
});
export type MedicationRemindersMarkTakenRequest = z.infer<typeof MedicationRemindersMarkTakenRequestSchema>;

/** `message` is `'บันทึกการทานยาแล้ว'` when `status === 'taken'`, else `'บันทึกแล้ว'`. */
const MedicationRemindersMarkTakenOk = z.object({
  success: z.literal(true),
  message: z.union([z.literal('บันทึกการทานยาแล้ว'), z.literal('บันทึกแล้ว')]),
});
/** `!$userId` -> `{success:false, error:'User not found'}`; ownership-check miss -> `{success:false, error:'Reminder not found'}`. */
export const MedicationRemindersMarkTakenResponseSchema = z.union([MedicationRemindersMarkTakenOk, MedicationRemindersFailSchema]);
export type MedicationRemindersMarkTakenResponse = z.infer<typeof MedicationRemindersMarkTakenResponseSchema>;
