import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { getSession, requireRole, PLATFORM_SESSION_COOKIE, type PlatformRole, type PlatformSession } from '@reya/auth';

// NOTE: PlatformSession's role field is `.platformRole`, not `.role` (TenantSession is the one with
// `.role`) — requireRole()'s real signature uses @reya/auth's `RoleOf<S>` conditional type to account
// for this asymmetry (a flagged, additive deviation from the literal interfaceContract text, which
// wrote the constraint as `S['role']`); PlatformRole is that resolved type for S=PlatformSession.
const PLATFORM_ROLES: readonly PlatformRole[] = ['super_admin', 'support', 'readonly'];

/**
 * (platform)/layout.tsx — skeleton chrome for the platform-super-admin
 * realm (admin/platform-login.php, admin/switch-tenant.php, admin/beta-signups.php
 * equivalents land here in a later phase). No nav manifest of its own in
 * this batch — the nav manifest deliverable ports header.php's TENANT-side
 * menu only; platform nav is out of scope here.
 */
export default async function PlatformLayout({ children }: { children: ReactNode }) {
  const cookieStore = await cookies();
  const sid = cookieStore.get(PLATFORM_SESSION_COOKIE)?.value;

  const rawSession = await getSession(sid, 'platform');
  const platformSession = rawSession && rawSession.realm === 'platform' ? rawSession : null;

  const result = requireRole<PlatformSession>(platformSession, PLATFORM_ROLES);
  if (!result.ok) {
    redirect('/auth/login?realm=platform');
    return null;
  }

  const session = result.value;

  return (
    <div data-realm="platform">
      <header>
        <span data-testid="platform-user-name">{session.name}</span>
        {session.impersonatedTenantId !== null ? (
          <span data-testid="impersonation-banner">
            กำลังสวมสิทธิ์ tenant #{session.impersonatedTenantId} / Impersonating tenant #
            {session.impersonatedTenantId}
          </span>
        ) : null}
      </header>
      <main>{children}</main>
    </div>
  );
}
