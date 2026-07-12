import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import type { Metadata } from 'next';
import { getSession, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb } from '@reya/db';
import { Tabs } from '@/components/Tabs';
import { resolveActiveTab } from './_lib/dateFilter';
import { ExecutiveTab } from './executive';
import { CrmTab } from './crm';

/**
 * (tenant)/dashboard/page.tsx — Server Component port of dashboard.php, the
 * consolidated Executive/CRM dashboard router (dashboard.php's own doc
 * comment: "รวมหน้า Executive Dashboard และ CRM Dashboard เป็นหน้าเดียว").
 * Serves at the SAME clean URL PHP does — `/dashboard?tab=executive` (also
 * the default with `tab` absent/invalid) and `/dashboard?tab=crm` — matching
 * apps/admin/src/nav/manifest.ts's 'overview' nav item hrefs exactly (no new
 * URL shape introduced).
 *
 * NOT ported here (dead per the brief's investigation, confirmed by reading
 * both tab partials): dashboard.php's `$isOdooMode` computation
 * (order_data_source==='odoo' && ODOO_INTEGRATION_ENABLED). It's computed in
 * the PHP source but never consumed by either includes/dashboard/executive.php
 * or includes/dashboard/crm.php — there is no per-tile Odoo branching on this
 * page to replicate. The real Odoo gate lives one layer up, at the nav/routing
 * level (already ported in apps/admin/src/nav/manifest.ts): an Odoo-mode
 * tenant's 'Dashboard' nav item points at `/odoo-dashboard` instead of
 * `/dashboard?tab=executive`, so an Odoo-mode tenant structurally never lands
 * on this route from nav. This page needs zero Odoo-gating code.
 */
export interface DashboardPageProps {
  searchParams: Promise<{ tab?: string; date?: string }>;
}

const PAGE_TITLES: Record<'executive' | 'crm', string> = {
  executive: 'Executive Dashboard',
  crm: 'CRM Dashboard',
};

export async function generateMetadata({ searchParams }: DashboardPageProps): Promise<Metadata> {
  const params = await searchParams;
  const activeTab = resolveActiveTab(params.tab);
  return { title: PAGE_TITLES[activeTab] };
}

export default async function DashboardPage({ searchParams }: DashboardPageProps) {
  const params = await searchParams;
  const activeTab = resolveActiveTab(params.tab);

  const cookieStore = await cookies();
  const sid = cookieStore.get(TENANT_SESSION_COOKIE)?.value;
  const rawSession = await getSession(sid, 'tenant');
  const session = rawSession && rawSession.realm === 'tenant' ? (rawSession as TenantSession) : null;

  if (!session || session.tenantId === null) {
    redirect('/auth/login?realm=tenant');
    return null;
  }

  const db = await getTenantDb(session.tenantId);

  // Resolved by direct invocation (`await Tab({...})`), not JSX (`<Tab .../>`) — plain
  // react-dom (what @testing-library/react drives in Jest) cannot await a nested async
  // function component reached via JSX the way Next.js's RSC renderer can; calling it
  // directly here keeps this page's own render tree fully synchronous by the time it
  // returns, and matches how executive.test.tsx/crm.test.tsx already exercise these tab
  // components ("call the async Server Component directly", per the brief).
  const tabContent = activeTab === 'crm' ? await CrmTab({ db, currentBotId: session.currentBotId }) : await ExecutiveTab({ db, dateParam: params.date });

  return (
    <div className="db-shell" style={{ display: 'flex', flexDirection: 'column', gap: 24, maxWidth: 1440, margin: '0 auto' }} data-testid="dashboard-page">
      <Tabs
        basePath="/dashboard"
        activeTab={activeTab}
        tabs={[
          { key: 'executive', label: <span lang="th">Executive Dashboard</span> },
          { key: 'crm', label: <span lang="th">CRM Dashboard</span> },
        ]}
      />

      {tabContent}
    </div>
  );
}
