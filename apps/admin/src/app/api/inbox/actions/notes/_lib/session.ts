import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the /api/inbox/actions/notes
 * Route Handlers (POST here + DELETE under `[noteId]/`). Ports
 * inbox-v2.php's `case 'save_note':` (lines 414-429) and
 * `case 'delete_note':` (lines 431-442), the same-page AJAX actions gated on
 * `$_SERVER['HTTP_X_REQUESTED_WITH']` in the original.
 *
 * Same "who is logged in + which tenant DB" gate as
 * api/inbox/conversations/_lib/session.ts and (tenant)/inbox/_lib/session.ts
 * (both mirror inbox-v2.php's `require_once 'includes/header.php'` gate at
 * line 991 — any authenticated admin, no page-specific role restriction).
 * Returns a JSON-shaped auth result rather than redirect()ing — this is a
 * same-page AJAX action endpoint consumed by client-side `fetch()`, not a
 * page render, so an unauthenticated/expired-session request must get back
 * a JSON 401 the caller can react to, not an HTML redirect response.
 *
 * Duplicated (not imported) from the conversations/messages/tags/medical
 * copies — same "every consumer resolves its own session" rationale
 * documented on users/_lib/session.ts, and this batch's ownership boundary
 * keeps api/inbox/actions/notes/** independently editable from sibling
 * action families without cross-imports. Shared between route.ts (POST) and
 * [noteId]/route.ts (DELETE) — both belong to the same "notes" action
 * family, per the brief's "one _lib per route DIRECTORY (tags/, notes/,
 * medical/)" instruction, not one per HTTP verb.
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
