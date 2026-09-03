import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the /api/inbox/actions/tags
 * Route Handler (port of inbox-v2.php's `case 'update_tags'`, lines 397-412).
 *
 * Same "who is logged in + which tenant DB" gate as
 * api/inbox/conversations/_lib/session.ts and (tenant)/inbox/_lib/session.ts
 * (both mirror inbox-v2.php's `require_once 'includes/header.php'` gate at
 * line 991 — any authenticated admin, no page-specific role restriction).
 * Returns a JSON-shaped auth result rather than redirect()ing — this is a
 * same-page AJAX action endpoint (inbox-v2.php's POST switch, gated on
 * `$_SERVER['HTTP_X_REQUESTED_WITH']`), consumed by client-side `fetch()`,
 * not a page render, so an unauthenticated/expired-session request must get
 * back a JSON 401 the caller can react to, not an HTML redirect response.
 *
 * Duplicated (not imported) from the conversations/messages/notes/medical
 * copies — same "every consumer resolves its own session" rationale
 * documented on users/_lib/session.ts, and this batch's ownership boundary
 * keeps api/inbox/actions/tags/**, .../notes/**, .../medical/** and sibling
 * action families independently editable without cross-imports.
 */

const TENANT_ROLES: readonly TenantSession['role'][] = [
  'super_admin',
  'admin',
  'pharmacist',
  'marketing',
  'tech',
  'staff',
];

export interface InboxApiContext {
  session: TenantSession & { tenantId: number };
  db: Kysely<TenantDB>;
}

export type InboxApiAuthResult = { ok: true; value: InboxApiContext } | { ok: false; status: 401 };

export async function resolveInboxApiContext(): Promise<InboxApiAuthResult> {
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
