import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { mapDbRoleToMenuRole } from '@reya/config';
import { ALL_PRIMARY_NAV_ITEMS } from '@/nav/manifest';

const TENANT_ROLES: readonly TenantSession['role'][] = [
  'super_admin',
  'admin',
  'pharmacist',
  'marketing',
  'tech',
  'staff',
];

/**
 * (tenant)/layout.tsx — skeleton chrome for the tenant realm. Reads the
 * session via getSession()/requireRole() (interface contract) and renders
 * the L1 flat rail (apps/admin/src/nav/manifest.ts) filtered to the caller's
 * role. No real page content yet — Phase 2+ ports individual pages under
 * this layout.
 */
export default async function TenantLayout({ children }: { children: ReactNode }) {
  const cookieStore = await cookies();
  const sid = cookieStore.get(TENANT_SESSION_COOKIE)?.value;

  const rawSession = await getSession(sid, 'tenant');
  // getSession()'s contract return type is the full Session union even when
  // realm:'tenant' is passed — narrow explicitly via the discriminant field
  // rather than trusting the argument to imply the return shape.
  const tenantSession = rawSession && rawSession.realm === 'tenant' ? rawSession : null;

  const result = requireRole<TenantSession>(tenantSession, TENANT_ROLES);
  if (!result.ok) {
    redirect('/auth/login?realm=tenant');
    return null;
  }

  const session = result.value;
  const menuRole = mapDbRoleToMenuRole(session.role);
  const visibleNav = ALL_PRIMARY_NAV_ITEMS.filter((item) => item.roles.includes(menuRole));

  return (
    <div data-realm="tenant">
      <header>
        <span data-testid="tenant-display-name">{session.displayName}</span>
      </header>
      <nav aria-label="Primary">
        <ul>
          {visibleNav.map((item) => (
            <li key={item.key}>
              <a href={item.href}>
                <span lang="th">{item.labelTh}</span> <span lang="en">{item.labelEn}</span>
              </a>
            </li>
          ))}
        </ul>
      </nav>
      <main>{children}</main>
    </div>
  );
}
