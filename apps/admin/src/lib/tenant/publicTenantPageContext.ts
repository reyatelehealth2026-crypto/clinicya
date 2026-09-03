import { headers } from 'next/headers';
import { notFound } from 'next/navigation';
import type { Kysely } from 'kysely';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * publicTenantPageContext.ts — "which tenant DB does this request belong to"
 * for PUBLIC, unauthenticated pages under `(public)/**`.
 *
 * The session-gated sibling is `(tenant)/users/_lib/session.ts`'s
 * `requireTenantPageContext()`, which resolves the tenant from the logged-in
 * admin's `session.tenantId` and redirects to `/auth/login` when there is no
 * session. That is the wrong gate for pages the PHP original serves to anyone
 * with the URL (articles.php / article.php include no `includes/header.php`,
 * check no `$_SESSION`, and call none of `isSuperAdmin()`/`isAdmin()`/
 * `isStaff()`) — routing those through a session gate turns a public page
 * into a login-walled one.
 *
 * Tenant identity here comes from the same place PHP's
 * `bootstrap/resolve_subdomain.php` gets it: the Host header. `proxy.ts`
 * already runs `resolveTenant()` on every non-static request and sets
 * `x-tenant-id` when a tenant resolved (it answers 404/503 itself for
 * unknown/suspended slugs, so those never reach a page). This reads that
 * header rather than re-querying the master DB per page.
 *
 * A request with no resolved tenant (reserved subdomain, unmatched host,
 * root domain carrying an explicit LINE-account signal — `proxy.ts`'s
 * `kind: 'none'` branch) has no tenant DB to read articles from, so it gets
 * a 404. There is deliberately NO legacy-DB fallback, matching the choice
 * already made in `lib/miniapp/tenant.ts`.
 */

export interface PublicTenantPageContext {
  tenantId: number;
  db: Kysely<TenantDB>;
}

export async function requirePublicTenantContext(): Promise<PublicTenantPageContext> {
  const headerList = await headers();
  const raw = headerList.get('x-tenant-id');
  const tenantId = raw === null ? Number.NaN : Number(raw);

  if (!Number.isFinite(tenantId) || tenantId <= 0) {
    notFound();
    throw new Error('unreachable — notFound() always throws');
  }

  const db = await getTenantDb(tenantId);
  return { tenantId, db };
}
