import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the /api/inbox/actions/add-customer-tag
 * Route Handler (port of api/inbox-v2.php's `case 'add_customer_tag':`,
 * lines ~2057-2090).
 *
 * Same "who is logged in + which tenant DB" gate as
 * api/inbox/actions/get-admins/_lib/session.ts and every sibling
 * actions/{tags,notes,medical,send-message,assign-conversation}/_lib/session.ts
 * copy (all mirror inbox-v2.php's `require_once 'includes/header.php'` gate —
 * any authenticated admin, no page-specific role restriction). Returns a
 * JSON-shaped auth result rather than redirect()ing — this is a same-page
 * AJAX action endpoint (api/inbox-v2.php's action switch), consumed by
 * client-side `fetch()`, not a page render, so an unauthenticated/expired
 * session request must get back a JSON 401 the caller can react to, not an
 * HTML redirect response.
 *
 * Duplicated (not imported) from the sibling copies — same "every consumer
 * resolves its own session" rationale documented on get-admins/_lib/session.ts.
 * NOT the same route as the already-merged `actions/assign-tag/route.ts`
 * (ports `case 'assign_tag':`, a byte-adjacent but SEPARATE case label with
 * no find-or-create-by-name preamble — it takes an existing `tag_id`
 * directly) — see `_lib/addCustomerTag.ts`'s module doc for the full
 * distinction. This file is not imported by, and does not import from,
 * `actions/assign-tag/_lib/session.ts`.
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
