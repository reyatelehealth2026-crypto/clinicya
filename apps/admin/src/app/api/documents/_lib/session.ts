import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the /api/documents Route
 * Handlers (this directory's `route.ts` GET/POST, and `[id]/route.ts` GET).
 * Ports api/documents.php's own auth gate (lines 26-28:
 * `require_once 'includes/auth_check.php'` before the action switch — any
 * logged-in admin, no page-specific role restriction, same as
 * inbox-v2.php's shell gate).
 *
 * Returns a JSON-shaped auth result rather than redirect()ing — this is an
 * API endpoint consumed by client-side `fetch()`, not a page render, so an
 * unauthenticated/expired-session request must get back a JSON 401 the
 * caller can react to. Same pattern as
 * api/inbox/actions/notes/_lib/session.ts and
 * api/inbox/conversations/_lib/session.ts (duplicated rather than imported
 * cross-feature — see those files' own doc comments for the rationale; this
 * batch's ownership boundary keeps api/documents/** independently editable
 * from apps/admin/src/app/api/inbox/** per the brief's "do not touch
 * apps/admin/src/app/api/inbox/**" instruction).
 *
 * `lineAccountId` resolution is intentionally NOT done here — see this
 * directory's route.ts/[id]/route.ts, which each compute
 * `session.currentBotId ?? 1` directly (the established precedent at
 * api/inbox/conversations/route.ts:51). PHP's three-tier fallback chain
 * (`$_SESSION['current_bot_id']` -> `admin_users.line_account_id` lookup ->
 * first active `line_accounts` row) is deliberately NOT replicated:
 * TenantSession.currentBotId already carries the equivalent resolved value,
 * and the admin_users/first-active-line_account fallbacks only existed in
 * PHP to cover sessions the Next-side session store cannot produce in the
 * first place.
 */

const TENANT_ROLES: readonly TenantSession['role'][] = [
  'super_admin',
  'admin',
  'pharmacist',
  'marketing',
  'tech',
  'staff',
];

export interface DocumentsApiContext {
  session: TenantSession & { tenantId: number };
  db: Kysely<TenantDB>;
}

export type DocumentsApiAuthResult = { ok: true; value: DocumentsApiContext } | { ok: false; status: 401 };

export async function resolveDocumentsApiContext(): Promise<DocumentsApiAuthResult> {
  const cookieStore = await cookies();
  const sid = cookieStore.get(TENANT_SESSION_COOKIE)?.value;

  const rawSession = await getSession(sid, 'tenant');
  const tenantSession = rawSession && rawSession.realm === 'tenant' ? rawSession : null;

  const result = requireRole<TenantSession>(tenantSession, TENANT_ROLES);
  if (!result.ok || result.value.tenantId === null) {
    return { ok: false, status: 401 };
  }

  const session = result.value as TenantSession & { tenantId: number };
  const db = await getTenantDb(session.tenantId);
  return { ok: true, value: { session, db } };
}
