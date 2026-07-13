import { redirect } from 'next/navigation';
import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';
import { requireTenantPageContext } from '../../users/_lib/session';

/**
 * requireAnalyticsPageContext — analytics.php's access gate (lines 18-22):
 *
 *   require_once 'includes/auth_check.php';
 *   if (!isAdmin() && !isSuperAdmin()) { header('Location: /'); exit; }
 *
 * Two tiers, replicated exactly:
 *   1. "must be logged in at all" — delegated to users/_lib/session's
 *      requireTenantPageContext() (cross-route import; user-detail/actions.ts
 *      and user-detail/queries.ts already establish this exact
 *      cross-import-rather-than-duplicate convention for the same helper).
 *      No page-specific role gate exists at this tier for users.php/
 *      user-detail.php, so reusing it verbatim is correct.
 *   2. analytics.php's OWN additional gate on top: isAdmin()||isSuperAdmin()
 *      is `role IN ('admin','super_admin')` per includes/auth_check.php's
 *      isAdmin()/isSuperAdmin() definitions (isAdmin() already includes
 *      super_admin, so the `||` is redundant — same net set). Any other role
 *      (pharmacist/marketing/tech/staff) redirects to '/' — NOT to the login
 *      page, matching PHP's `header('Location: /')` exactly (not a 401/403).
 */
const ANALYTICS_ALLOWED_ROLES: readonly TenantSession['role'][] = ['admin', 'super_admin'];

export interface AnalyticsPageContext {
  session: TenantSession & { tenantId: number };
  db: Kysely<TenantDB>;
}

export async function requireAnalyticsPageContext(): Promise<AnalyticsPageContext> {
  const { session, db } = await requireTenantPageContext();
  if (!ANALYTICS_ALLOWED_ROLES.includes(session.role)) {
    redirect('/');
    throw new Error('unreachable — redirect() always throws');
  }
  return { session, db };
}
