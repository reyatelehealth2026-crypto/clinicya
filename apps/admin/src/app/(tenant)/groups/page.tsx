import type { Metadata } from 'next';
import { requireTenantPageContext } from '../users/_lib/session';
import { getGroupsPageData } from './queries';
import { GroupsPanel } from './_components/GroupsPanel';

/**
 * (tenant)/groups/page.tsx — Server Component port of groups.php (212 LOC,
 * confirmed by reading the full file). `?view=<id>` controls a right-hand
 * detail panel on ONE page (not two routes) — mirrored here as
 * `/groups?view=N`, matching the PHP source's own single-file two-panel
 * layout and its exact redirect shape after mutations (see actions.ts).
 *
 * `$currentBotId` in groups.php comes from includes/header.php (required on
 * line 11, before any of groups.php's own queries run) — see queries.ts's
 * module doc for the full trace. `session.currentBotId` is the Next
 * equivalent, resolved once here via requireTenantPageContext().
 *
 * Access gate: groups.php has no page-specific role check beyond
 * includes/header.php's generic "must be logged in" requirement (grepped
 * the full file for isSuperAdmin/isAdmin/isStaff — zero hits).
 */
export const metadata: Metadata = { title: 'Groups Manager' };

interface GroupsPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

export default async function GroupsPage({ searchParams }: GroupsPageProps) {
  const params = await searchParams;
  const { db, session } = await requireTenantPageContext();

  const viewRaw = first(params, 'view');
  // Mirrors PHP's `isset($_GET['view'])` gate — a present-but-non-numeric
  // value still reaches the query in PHP (bound as the string PDO received),
  // which returns no row; parsing to a number here and falling back to null
  // only when the param is entirely absent preserves that "queried but not
  // found" behavior rather than silently treating a garbage value as "no view".
  const viewId = viewRaw !== undefined ? Number.parseInt(viewRaw, 10) || 0 : null;

  const { groups, allUsers, viewGroup, members } = await getGroupsPageData(db, session.currentBotId, viewId);

  return (
    <div className="max-w-6xl mx-auto px-4 py-4">
      <div className="mb-4">
        <h1 className="text-xl font-bold text-slate-800">Groups Manager</h1>
        <p className="text-sm text-slate-500 mt-0.5">จัดการกลุ่มผู้ใช้และแท็ก</p>
      </div>
      <GroupsPanel groups={groups} allUsers={allUsers} viewGroupId={viewGroup?.id ?? null} viewGroup={viewGroup} members={members} />
    </div>
  );
}
