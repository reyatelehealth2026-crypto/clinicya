import type { Metadata } from 'next';
import { requireTenantPageContext } from '../users/_lib/session';
import { getPharmacistsData } from './queries';
import { PharmacistsClient } from './_components/PharmacistsClient';

/**
 * (tenant)/pharmacists/page.tsx — Server Component port of the LIVE tab
 * partial `includes/pharmacy/pharmacists.php` (463 LOC, confirmed by
 * reading the full file), included by `pharmacy.php`'s tab router when
 * `?tab=pharmacists`. See the brief's own "CRITICAL SOURCE CORRECTION": the
 * repo-root `pharmacists.php` (479 LOC) is, as currently committed, a dead
 * 301-redirect stub whose body is a commented-out "kept for reference"
 * copy — that file was NOT ported from.
 *
 * Access gate: includes/pharmacy/pharmacists.php has no page-specific role
 * check of its own beyond `pharmacy.php`'s `includes/auth_check.php`
 * generic "must be logged in" requirement (grepped for
 * isSuperAdmin/isAdmin/isStaff in the full tab-partial file — zero hits) —
 * reuses users/_lib/session's requireTenantPageContext(), the same
 * cross-route import convention templates/loyalty-members/user-detail
 * already establish.
 *
 * Serves at the same clean URL family PHP does conceptually
 * (`pharmacy.php?tab=pharmacists`) but as its own direct route, `/pharmacists`
 * — matching templates.php's / users.php's precedent of a single Next route
 * per PHP page/tab rather than reproducing the tab-query-param shape. See
 * this batch's brief for the known flip-time caveat this creates (two live
 * URLs writing the same tables until `includes/redirects.php`/routing is
 * revisited) — out of this batch's scope to resolve.
 */
export const metadata: Metadata = { title: 'เภสัชกร' };

export default async function PharmacistsPage() {
  const { db } = await requireTenantPageContext();
  const pharmacists = await getPharmacistsData(db);

  return (
    <div className="max-w-6xl mx-auto px-4 py-4">
      <PharmacistsClient pharmacists={pharmacists} />
    </div>
  );
}
