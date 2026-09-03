import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * data-rights.ts — zod contracts for the ported `api/data-rights.php`
 * (178 lines, delegating to `modules/PDPA/Services/DataRightsService.php`,
 * 365 lines — both read in full). ALL THREE actions have live callers,
 * verified via `data-rights-api.ts` + `data-rights-request.ts` (the pure
 * request-body builders): `withdraw_consent`, `request_deletion`,
 * `export_data`.
 *
 * ============================================================================
 * DEVIATION #1 (same finding as consent.ts, flagged here too — read that
 * file's doc comment for the full writeup): TENANT-RESOLUTION BEHAVIOR IS
 * DELIBERATELY NOT PRESERVED.
 * ============================================================================
 * `api/data-rights.php` is, like `api/consent.php`, CONSPICUOUSLY MISSING
 * `require_once bootstrap/route_by_account.php`. DECISION (already made,
 * mig-orchestrator sign-off): port using the STANDARD two-phase
 * `resolveMiniappTenantContext()`/`withMiniappTenant()` helper, exactly like
 * every other `/api/miniapp/**` route — this is a deliberate, byte-level
 * DEVIATION from the PHP original's tenant-resolution behavior (real
 * production PHP silently falls back to the legacy/default DB for this
 * surface today; the Next port never does — "no legacy-DB fallback" per
 * `apps/admin/src/lib/miniapp/tenant.ts`'s point 2c). PARITY HARNESS IMPACT:
 * its PHP-side call for `data-rights:*` needs a `Host` header pinned to the
 * seeded tenant subdomain, to exercise PHP's real subdomain-resolution path
 * rather than the broken fallback it takes on the root domain — otherwise
 * the two stacks could legitimately be reading/writing different DBs and any
 * diff is meaningless.
 *
 * ============================================================================
 * MIGRATION DEPENDENCY (load-bearing — verified against this worktree):
 * ============================================================================
 * The `data_deletion_requests` table and `users.deletion_status` /
 * `users.deletion_requested_at` columns are CONFIRMED MISSING from
 * `database/migration_2026-05-25_tenant_template.sql` and from
 * `packages/db/src/generated/tenant-db.d.ts`'s Kysely types — they live ONLY
 * in the separately-committed `database/migration_2026-07-04_pdpa_data_rights.sql`
 * (confirmed whitelisted in `.gitignore` via
 * `!database/migration_2026-07-04_pdpa_data_rights.sql`, i.e. it IS
 * committed). Because these are not in the generated Kysely types, the Route
 * Handler uses the `sql` tagged-template escape hatch (same pattern
 * `health-profile`'s/`wishlist`'s `_lib` files already use for hand-written
 * SQL) for every query touching `data_deletion_requests` /
 * `users.deletion_status` / `users.deletion_requested_at` — NOT the typed
 * Kysely query builder. A Kysely-codegen regeneration is explicitly out of
 * this batch's scope. **The parity harness's fixture-seeding step needs to
 * apply this migration to the tenant DB before running `data-rights:*`
 * cases**, or `export_data`'s `profile.deletion_status` field and
 * `request_deletion`'s writes will fail/come back empty.
 *
 * `modules/PDPA/Services/DataRightsService.php::ensureDeletionSchema()`'s
 * lazy self-healing `ALTER TABLE`/`CREATE TABLE IF NOT EXISTS` (a resilience
 * fallback for tenants that haven't run the migration yet) is DELIBERATELY
 * NOT PORTED — DDL-on-every-request from application code is exactly the
 * pattern CLAUDE.md's "Auto-create tables" convention discourages for new
 * work, and the migration dependency above already makes the DDL-at-request
 * fallback unnecessary once the parity harness (or a real deploy) applies
 * the migration up front. Same "flagged simplification" precedent as
 * `medication-reminders.ts`'s dropped `CREATE TABLE IF NOT EXISTS` calls.
 *
 * ============================================================================
 * DECISION #2: two admin-facing side effects — DELIBERATELY NOT PORTED.
 * ============================================================================
 * (1) `ConsultationAudit` hash-chained audit logging (`consultation_audit`
 *     table, `drAudit()` calls on every action) — this is Phase 7
 *     (mig-ai)'s object per the migration plan. Grepped this worktree's
 *     `apps/`/`packages/` for `ConsultationAudit`/`consultation_audit`:
 *     ZERO hits — no Next-side port exists yet, and the table itself isn't
 *     in the generated Kysely types either. Building a parallel audit
 *     mechanism ahead of Phase 7 is explicitly out of scope for this batch.
 * (2) Best-effort Telegram/`dev_logs` admin notification on
 *     `request_deletion` (`SiteNotifier::sendTelegram()` + a raw
 *     `dev_logs` INSERT) — grepped for a shared `dev_logs`-compat writer
 *     utility (CLAUDE.md's cross-cutting workstream #3 mentions one is
 *     planned): none exists yet in `apps/`/`packages/`.
 * Both are DELIBERATELY-NOT-PORTED side effects, flagged here rather than
 * silently dropped, same standard as consent.ts's `ActivityLogger` decision.
 * request_deletion's DB effects (the SOFT `users.deletion_status` flag +
 * the `data_deletion_requests` ledger row) ARE fully ported — only the two
 * notification/audit side channels are not.
 *
 * ============================================================================
 * SECURITY-CRITICAL (replicate exactly): `resolveUser()` NEVER trusts a
 * client-supplied `user_id` — it resolves `users.id` server-side from
 * `(line_user_id, line_account_id)` ONLY: `line_account_id`-scoped lookup
 * first, falling back to a `line_user_id`-only lookup if that specific
 * combo misses (mirrors `member.php`'s own scoped→unscoped fallback). The
 * request body's `user_id` field (if a client ever sent one) MUST be
 * ignored entirely — this is the IDOR/cross-tenant guard the PHP original
 * itself calls out as "the most important" rule in its own doc comment.
 *
 * `request_deletion` is a SOFT flag ONLY — `UPDATE users SET
 * deletion_status='requested', deletion_requested_at=NOW()`, NEVER a
 * `DELETE`. Confirmation code format: `REYA-DEL-` + 8 random chars from
 * `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no 0/O/1/I, to avoid customer
 * transcription errors) — see `DATA_RIGHTS_CONFIRMATION_CODE_REGEX` below,
 * exported for the parity harness's FORMAT_CHECKS.
 *
 * `export_data`'s best-effort reads (`user_consents`, `consent_logs`,
 * `ai_conversation_history`, `transactions`+`transaction_items` — all
 * confirmed present in the base template / generated Kysely types) are each
 * individually try/catch-wrapped, returning `[]` on failure — the Route
 * Handler replicates that per-query isolation so one failing read never
 * fails the whole export.
 *
 * ENVELOPE: local `drJsonResponse($success, $message='', $data=[])` = same
 * `array_merge` pattern as `consent.ts`, always HTTP 200 (no
 * `http_response_code()` call anywhere in `api/data-rights.php` either) —
 * `flatSuccessEnvelope()`-shaped.
 */

/**
 * `REYA-DEL-` + 8 chars from the no-ambiguous-glyphs alphabet
 * (`generateConfirmationCode()` in DataRightsService.php). Exported for the
 * parity harness's FORMAT_CHECKS — the code itself is random per request and
 * can never be byte-diffed between the PHP and Next runs, only shape-checked.
 */
export const DATA_RIGHTS_CONFIRMATION_CODE_REGEX = /^REYA-DEL-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/;

/** Shared across all 3 actions: `'User not found'` / `'LINE User ID required'` / `'Invalid action'` / `'เกิดข้อผิดพลาดในการดำเนินการ'`. */
const DataRightsGenericFail = flatSuccessEnvelope({ success: z.literal(false) });
export type DataRightsGenericFail = z.infer<typeof DataRightsGenericFail>;

// ---------------------------------------------------------------------------
// POST action=withdraw_consent
// ---------------------------------------------------------------------------

export const DataRightsWithdrawConsentRequestSchema = z.object({
  action: z.literal('withdraw_consent'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  /** Defaults to `'health_data'` server-side when absent/non-string/empty — matches PHP. */
  consent_type: z.string().optional(),
});
export type DataRightsWithdrawConsentRequest = z.infer<typeof DataRightsWithdrawConsentRequestSchema>;

const DataRightsWithdrawConsentOk = flatSuccessEnvelope({
  success: z.literal(true),
  message: z.literal('ถอนความยินยอมเรียบร้อยแล้ว'),
  consent_type: z.string(),
});
export const DataRightsWithdrawConsentResponseSchema = z.union([DataRightsWithdrawConsentOk, DataRightsGenericFail]);
export type DataRightsWithdrawConsentResponse = z.infer<typeof DataRightsWithdrawConsentResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=request_deletion — SOFT flag only, never a DELETE
// ---------------------------------------------------------------------------

export const DataRightsRequestDeletionRequestSchema = z.object({
  action: z.literal('request_deletion'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  /** Truncated server-side to 2000 chars (`mb_substr($reason, 0, 2000)`); omitted entirely if empty. */
  reason: z.string().optional(),
});
export type DataRightsRequestDeletionRequest = z.infer<typeof DataRightsRequestDeletionRequestSchema>;

const DataRightsRequestDeletionOk = flatSuccessEnvelope({
  success: z.literal(true),
  message: z.literal('รับคำขอลบข้อมูลแล้ว เราจะดำเนินการภายใน 30 วัน'),
  confirmation_code: z.string().regex(DATA_RIGHTS_CONFIRMATION_CODE_REGEX),
  status: z.literal('requested'),
});
export const DataRightsRequestDeletionResponseSchema = z.union([DataRightsRequestDeletionOk, DataRightsGenericFail]);
export type DataRightsRequestDeletionResponse = z.infer<typeof DataRightsRequestDeletionResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=export_data
// ---------------------------------------------------------------------------

export const DataRightsExportDataRequestSchema = z.object({
  action: z.literal('export_data'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type DataRightsExportDataRequest = z.infer<typeof DataRightsExportDataRequestSchema>;

/**
 * `normaliseUserProfile()`'s allowlist, read verbatim off
 * `DataRightsService.php` (NOT the brief's paraphrased count — this is the
 * literal `$allow` array): id, line_account_id, line_user_id, display_name,
 * real_name, first_name, last_name, phone, email, birthday, gender, address,
 * district, province, postal_code, member_id, is_registered, total_orders,
 * total_spent, available_points, medical_conditions, drug_allergies,
 * current_medications, blood_type, weight, height, created_at,
 * registered_at, consent_privacy, consent_terms, consent_health_data,
 * consent_date, deletion_status, deletion_requested_at. `array_key_exists`
 * gates inclusion in PHP (a column present-but-null still appears with a
 * `null` value; a genuinely absent column is omitted) — every field here is
 * `.optional()` to model that possibility, even though a `SELECT *`-shaped
 * fetch against a migrated tenant DB will realistically always populate all
 * of them.
 */
export const DataRightsProfileSchema = z.object({
  id: z.number().optional(),
  line_account_id: z.number().nullable().optional(),
  line_user_id: z.string().optional(),
  display_name: z.string().nullable().optional(),
  real_name: z.string().nullable().optional(),
  first_name: z.string().nullable().optional(),
  last_name: z.string().nullable().optional(),
  phone: z.string().nullable().optional(),
  email: z.string().nullable().optional(),
  birthday: z.string().nullable().optional(),
  gender: z.string().nullable().optional(),
  address: z.string().nullable().optional(),
  district: z.string().nullable().optional(),
  province: z.string().nullable().optional(),
  postal_code: z.string().nullable().optional(),
  member_id: z.string().nullable().optional(),
  is_registered: z.number().nullable().optional(),
  total_orders: z.number().nullable().optional(),
  total_spent: z.number().nullable().optional(),
  available_points: z.number().nullable().optional(),
  medical_conditions: z.string().nullable().optional(),
  drug_allergies: z.string().nullable().optional(),
  current_medications: z.string().nullable().optional(),
  blood_type: z.string().nullable().optional(),
  weight: z.number().nullable().optional(),
  height: z.number().nullable().optional(),
  created_at: z.string().nullable().optional(),
  registered_at: z.string().nullable().optional(),
  consent_privacy: z.number().nullable().optional(),
  consent_terms: z.number().nullable().optional(),
  consent_health_data: z.number().nullable().optional(),
  consent_date: z.string().nullable().optional(),
  /** Only populated once `database/migration_2026-07-04_pdpa_data_rights.sql` has run — see this file's MIGRATION DEPENDENCY note above. */
  deletion_status: z.enum(['none', 'requested', 'processing', 'completed']).nullable().optional(),
  deletion_requested_at: z.string().nullable().optional(),
});
export type DataRightsProfile = z.infer<typeof DataRightsProfileSchema>;

/** `fetchOwnConsents()` — `SELECT consent_type, consent_version, is_accepted, accepted_at, withdrawn_at, updated_at FROM user_consents WHERE user_id = ? ORDER BY id ASC`. */
export const DataRightsConsentSchema = z.object({
  consent_type: z.string(),
  consent_version: z.string(),
  is_accepted: z.number(),
  accepted_at: z.string().nullable(),
  withdrawn_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});

/** `fetchOwnConsentLogs()` — `SELECT consent_type, action, consent_version, created_at FROM consent_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 200`. */
export const DataRightsConsentLogSchema = z.object({
  consent_type: z.string(),
  action: z.string(),
  consent_version: z.string(),
  created_at: z.string().nullable(),
});

/** `fetchOwnChatHistory()` — `SELECT role, content, session_id, created_at FROM ai_conversation_history WHERE user_id = ? ORDER BY created_at ASC LIMIT 500`. */
export const DataRightsChatMessageSchema = z.object({
  role: z.string(),
  content: z.string(),
  session_id: z.string().nullable(),
  created_at: z.string().nullable(),
});

/**
 * `fetchOwnOrders()` — `transactions t LEFT JOIN transaction_items ti ... GROUP BY t.id`;
 * `products` is a nullable `GROUP_CONCAT`. `total_amount` is a DECIMAL column read via a
 * plain `fetchAll(PDO::FETCH_ASSOC)` with no cast — PDO (and mysql2, matching, with no
 * `decimalNumbers` option set) hand back the raw decimal string (e.g. `"25.00"`), not a
 * float, so this is `z.union([z.number(), z.string()])` — same string-tolerant pattern as
 * `consultation_fee`/`rating`/`review_count` in appointments.ts — NOT `z.number()`.
 */
export const DataRightsOrderSchema = z.object({
  id: z.number(),
  order_number: z.string(),
  total_amount: z.union([z.number(), z.string()]),
  status: z.string().nullable(),
  created_at: z.string().nullable(),
  products: z.string().nullable(),
});

export const DataRightsExportSchema = z.object({
  export_meta: z.object({
    /** `date('c')` — ISO 8601 with numeric offset, e.g. `2026-07-13T10:00:00+07:00`. */
    generated_at: z.string(),
    standard: z.literal('PDPA (Thailand) — ข้อมูลส่วนบุคคลของเจ้าของข้อมูลเท่านั้น'),
    user_id: z.number().nullable(),
  }),
  profile: DataRightsProfileSchema,
  consents: z.array(DataRightsConsentSchema),
  consent_history: z.array(DataRightsConsentLogSchema),
  chat_history: z.array(DataRightsChatMessageSchema),
  orders: z.array(DataRightsOrderSchema),
});
export type DataRightsExport = z.infer<typeof DataRightsExportSchema>;

const DataRightsExportDataOk = flatSuccessEnvelope({
  success: z.literal(true),
  message: z.literal('ส่งออกข้อมูลเรียบร้อยแล้ว'),
  data: DataRightsExportSchema,
});
export const DataRightsExportDataResponseSchema = z.union([DataRightsExportDataOk, DataRightsGenericFail]);
export type DataRightsExportDataResponse = z.infer<typeof DataRightsExportDataResponseSchema>;

// ---------------------------------------------------------------------------
// Discriminated request union — every action shares one Route Handler.
// ---------------------------------------------------------------------------

export const DataRightsRequestSchema = z.union([
  DataRightsWithdrawConsentRequestSchema,
  DataRightsRequestDeletionRequestSchema,
  DataRightsExportDataRequestSchema,
]);
export type DataRightsRequest = z.infer<typeof DataRightsRequestSchema>;
