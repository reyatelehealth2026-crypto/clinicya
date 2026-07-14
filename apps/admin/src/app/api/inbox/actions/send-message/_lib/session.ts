import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the
 * POST /api/inbox/actions/send-message Route Handler. Same "who is logged
 * in + which tenant DB" gate as api/inbox/conversations/_lib/session.ts and
 * api/inbox/messages/route.ts: inbox-v2.php's AJAX handler block (the
 * `if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']))`
 * gate at inbox-v2.php line ~991) runs only after `includes/header.php` has
 * already required a logged-in admin session for the page itself — so this
 * Next Route Handler (reachable directly, not wrapped by
 * `(tenant)/layout.tsx`, which only gates page renders) must perform that
 * check itself. Requires any of the six TenantRole values — send_message
 * has no page-specific role restriction beyond "any authenticated admin".
 *
 * Duplicated (not imported) from
 * api/inbox/conversations/_lib/session.ts's resolveInboxApiContext() —
 * established repo precedent ("every consumer resolves its own session",
 * see that file's own doc comment) and this batch's ownership boundary
 * (messagingActions owns apps/admin/src/app/api/inbox/actions/send-message/**
 * exclusively) keeps this folder independently editable without reaching
 * into already-merged batch-1 territory.
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
