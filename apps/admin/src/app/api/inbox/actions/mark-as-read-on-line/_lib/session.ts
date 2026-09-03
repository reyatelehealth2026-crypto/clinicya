import { cookies } from 'next/headers';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * session.ts — auth/tenant-db resolution for the
 * POST /api/inbox/actions/mark-as-read-on-line Route Handler. Ports
 * api/inbox-v2.php's `case 'mark_as_read_on_line':` (lines 2601-2679).
 *
 * Same "who is logged in + which tenant DB" gate as the sibling
 * api/inbox/actions/{tags,medical,notes,send-message,mark-all-read}/_lib/session.ts
 * copies and api/inbox/conversations/_lib/session.ts — api/inbox-v2.php
 * itself has no explicit login check ahead of its action switch (it only
 * reads `$_SESSION['current_bot_id']`/`$_SESSION['admin_id']` opportunistically,
 * see api/inbox-v2.php lines 71-72), but every already-merged Route Handler
 * ported from this same PHP file gates on a valid tenant session and
 * returns a JSON 401 rather than proceeding unauthenticated — this file
 * follows that established batch convention, not a literal PHP auth check.
 * Requires any of the six TenantRole values — mark_as_read_on_line has no
 * page-specific role restriction in the PHP source either.
 *
 * Duplicated (not imported) from the conversations/messages/tags/notes/
 * medical/send-message/mark-all-read copies — same "every consumer resolves
 * its own session" rationale documented on users/_lib/session.ts, and this
 * batch's ownership boundary keeps api/inbox/actions/mark-all-read/**,
 * .../mark-as-read-on-line/**, and .../assign-tag/** independently editable
 * without cross-imports (and without reaching into any other action
 * family's directory).
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
