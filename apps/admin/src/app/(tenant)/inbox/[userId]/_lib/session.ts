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
 * requireTenantPageContext — local copy of
 * `(tenant)/users/_lib/session.ts`'s function of the same name, for
 * `(tenant)/inbox/[userId]`. Same duplication rationale as that file's own
 * doc comment: `(tenant)/layout.tsx` resolves+gates the session for the
 * page shell, but doesn't hand it down as a prop, so every leaf page that
 * needs the session/db directly re-resolves it here. inbox-v2.php has no
 * page-specific role gate beyond "any authenticated admin" (a plain
 * `includes/header.php` include, same as users.php/user-detail.php), so the
 * allow-list is every TenantRole, matching the source file being copied.
 *
 * A super_admin who never entered a tenant context has session.tenantId ===
 * null (mirrors $_SESSION['active_tenant_id'] unset) — treated the same as
 * no session at all, since inbox-v2.php has no code path for an admin
 * request with no resolved tenant DB.
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
