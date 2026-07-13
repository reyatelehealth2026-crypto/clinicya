import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { buildExportForUser, markForDeletion, withdrawConsent, type UserRow } from './service';

/**
 * handlers.ts — the three action handlers ported from `api/data-rights.php` (178 lines) +
 * `modules/PDPA/Services/DataRightsService.php` (365 lines), both read in full. See
 * `packages/contracts/src/data-rights.ts`'s doc comment for the two flagged deviations (tenant
 * resolution, the `data_deletion_requests` migration dependency) and the two dropped side effects
 * (`ConsultationAudit` hash-chained logging, best-effort Telegram/`dev_logs` admin notification).
 *
 * ORDERING NOTE (replicated exactly from `api/data-rights.php`): identity is validated — `line_user_id`
 * present, THEN `resolveUser()` succeeds — BEFORE the `switch ($action)` is ever reached (the file's own
 * comment: "Validate identity BEFORE doing anything"). Practical effect: an unrecognized `action` with a
 * missing `line_user_id` returns `'LINE User ID required'`, and one with an unresolvable user returns
 * `'User not found'` — NEVER `'Invalid action'` unless identity resolution already succeeded. `route.ts`
 * therefore resolves the user ONCE, before its own switch, and passes the already-resolved `UserRow`
 * into whichever handler below runs — these handlers never re-resolve or fail on a missing user.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of `drJsonResponse($success, $message, $data)` — always HTTP 200. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): ActionResult {
  return { status: 200, body: { success, message, ...data } };
}

export interface DataRightsContext {
  db: Kysely<TenantDB>;
  lineUserId: string;
  lineAccountId: number | null;
  ip: string | null;
  ua: string | null;
}

export async function handleWithdrawConsent(ctx: DataRightsContext, user: UserRow, consentTypeInput: unknown): Promise<ActionResult> {
  const consentType = typeof consentTypeInput === 'string' && consentTypeInput !== '' ? consentTypeInput : 'health_data';
  const userId = Number(user.id);

  await withdrawConsent(ctx.db, userId, consentType, ctx.lineAccountId, ctx.ip, ctx.ua);

  // NOTE: ConsultationAudit hash-chained logging (drAudit('consent_withdraw', ...)) is deliberately NOT
  // ported — Phase 7 (mig-ai)'s object per the migration plan. See this batch's contract doc comment.

  return ok(true, 'ถอนความยินยอมเรียบร้อยแล้ว', { consent_type: consentType });
}

export async function handleRequestDeletion(ctx: DataRightsContext, user: UserRow, reasonInput: unknown): Promise<ActionResult> {
  const reason = typeof reasonInput === 'string' && reasonInput !== '' ? reasonInput.slice(0, 2000) : null;
  const userId = Number(user.id);

  const code = await markForDeletion(ctx.db, userId, ctx.lineUserId, ctx.lineAccountId, reason, ctx.ip, ctx.ua);

  // NOTE: ConsultationAudit hash-chained logging AND the best-effort Telegram/dev_logs admin
  // notification are both deliberately NOT ported — see this batch's contract doc comment for the
  // full decision writeup (Phase 7 territory / no shared dev_logs-compat writer exists yet).

  return ok(true, 'รับคำขอลบข้อมูลแล้ว เราจะดำเนินการภายใน 30 วัน', { confirmation_code: code, status: 'requested' });
}

export async function handleExportData(ctx: DataRightsContext, user: UserRow): Promise<ActionResult> {
  const exportPayload = await buildExportForUser(ctx.db, user);

  // NOTE: ConsultationAudit hash-chained logging (drAudit('data_export', ...)) is deliberately NOT
  // ported — same Phase 7 decision as the other two actions.

  return ok(true, 'ส่งออกข้อมูลเรียบร้อยแล้ว', { data: exportPayload });
}
