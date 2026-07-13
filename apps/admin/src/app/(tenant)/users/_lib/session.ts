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
 * requireTenantPageContext — shared "who is logged in + which tenant DB"
 * resolution for Server Components/Server Actions under (tenant)/users and
 * (tenant)/user-detail (users.php/user-detail.php have no page-specific role
 * gate beyond "any authenticated admin" — see the plain `includes/header.php`
 * include with no `isSuperAdmin()`/`isAdmin()` check in either PHP file).
 *
 * Duplicates (tenant)/layout.tsx's own getSession()/requireRole() gate
 * (Phase 1 batch) rather than importing it, because a Next.js layout doesn't
 * hand its resolved session down to page children via props — every leaf
 * page that needs the session/db directly (not just nav-filtering) has to
 * re-resolve it. Additionally resolves a Kysely<TenantDB> via
 * @reya/db's getTenantDb(session.tenantId), which layout.tsx does not need.
 *
 * A super_admin who never entered a tenant context has session.tenantId ===
 * null (mirrors $_SESSION['active_tenant_id'] unset — see
 * classes/TenantContext.php's "super-admins do not get an implicit tenant"
 * note in CLAUDE.md) — treated the same as no session at all here, since
 * users.php/user-detail.php have no code path for an admin request with no
 * resolved tenant DB.
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
