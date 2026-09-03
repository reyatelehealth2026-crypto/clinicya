import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import type { Kysely } from 'kysely';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb, type TenantDB } from '@reya/db';

const TENANT_ROLES: readonly TenantSession['role'][] = [
  'super_admin',
  'admin',
  'pharmacist',
  'marketing',
  'tech',
  'staff',
];

export interface TenantPageContext {
  session: TenantSession & { tenantId: number };
  db: Kysely<TenantDB>;
}

/**
 * requireTenantPageContext — standalone local copy for `(tenant)/shop/order-detail`.
 * Same duplication rationale as `(tenant)/users/_lib/session.ts`'s own doc comment
 * (and every other per-feature copy in this codebase, e.g.
 * `(tenant)/inbox/_lib/session.ts`, `(tenant)/inbox/[userId]/_lib/session.ts`):
 * `(tenant)/layout.tsx` resolves+gates the session for the page shell but doesn't
 * hand it down as a prop, so every leaf page/action that needs the session/db
 * directly re-resolves it here.
 *
 * shop/order-detail.php has no page-specific role gate beyond "any authenticated
 * admin" (`require_once 'includes/header.php'` at line 605, the same plain
 * include as users.php/user-detail.php/inbox-v2.php), so the allow-list is every
 * TenantRole.
 *
 * Deliberately NOT imported from `../orders/_lib/session` (the sibling
 * `(tenant)/shop/orders` list page, ownership boundary per the porting brief) or
 * from `(tenant)/users/_lib/session` — this file is a byte-for-byte duplicate of
 * that shape, kept local so orderDetail and ordersList stay fully disjoint.
 *
 * A super_admin who never entered a tenant context has session.tenantId === null
 * (mirrors $_SESSION['active_tenant_id'] unset — see classes/TenantContext.php's
 * "super-admins do not get an implicit tenant" note in CLAUDE.md) — treated the
 * same as no session at all here, since shop/order-detail.php has no code path
 * for an admin request with no resolved tenant DB.
 */
export async function requireTenantPageContext(): Promise<TenantPageContext> {
  const cookieStore = await cookies();
  const sid = cookieStore.get(TENANT_SESSION_COOKIE)?.value;

  const rawSession = await getSession(sid, 'tenant');
  const tenantSession = rawSession && rawSession.realm === 'tenant' ? rawSession : null;

  const result = requireRole<TenantSession>(tenantSession, TENANT_ROLES);
  if (!result.ok || result.value.tenantId === null) {
    redirect('/auth/login?realm=tenant');
    throw new Error('unreachable — redirect() always throws');
  }

  const session = result.value as TenantSession & { tenantId: number };
  const db = await getTenantDb(session.tenantId);
  return { session, db };
}
