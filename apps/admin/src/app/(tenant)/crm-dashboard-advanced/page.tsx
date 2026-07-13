import type { Metadata } from 'next';
import { Tabs } from '@/components/Tabs';
import { requireTenantPageContext } from '../users/_lib/session';
import { ExecutiveOverviewTab } from './_components/ExecutiveOverviewTab';
import { SalesPipelineTab } from './_components/SalesPipelineTab';
import { ServiceCenterTab } from './_components/ServiceCenterTab';
import { MarketingHubTab } from './_components/MarketingHubTab';
import { AnalyticsStudioTab } from './_components/AnalyticsStudioTab';
import { CustomersTab } from './_components/CustomersTab';
import { DealsTab } from './_components/DealsTab';
import { TicketsTab } from './_components/TicketsTab';
import { ReportsTab } from './_components/ReportsTab';

/**
 * (tenant)/crm-dashboard-advanced/page.tsx — Server Component port of
 * crm-dashboard-advanced.php, the 9-section "CRM Pro" data-dense dashboard
 * (executive-overview/sales-pipeline/service-center/marketing-hub/
 * analytics-studio/customers-list/deals-list/tickets-list/reports.php). The
 * PHP page itself renders ZERO server-side data — every section is a
 * client-side `<div id="section-X" class="section-panel">` toggled by
 * `showSection(sectionId)`, populated on click via `fetch('api/crm-dashboard
 * -api.php', {action, ...})`. Ported here as a server-routed `?tab=`
 * switch (same convention as Tabs.tsx / (tenant)/dashboard's page.tsx),
 * resolving each section's data server-side instead of via client fetch —
 * the "server-render into a client island's initial state" pattern already
 * used by /analytics's advanced/crm tabs.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * CRITICAL FINDING + AUTHORIZED RESOLUTION (see queries.ts's module doc for
 * full detail — repeated here because it's the reason this page renders at
 * all on a tenant DB matching the committed schema):
 * ══════════════════════════════════════════════════════════════════════════
 * crm_deals/crm_tickets/crm_ticket_interactions do not exist in
 * database/migration_2026-05-25_tenant_template.sql. Real PHP
 * (crm-dashboard-advanced.php:28, `$overview =
 * $crmService->getExecutiveOverview();`, UNGUARDED before any HTML) 500s on
 * load on any such tenant. This is a pre-existing PHP defect, out of scope
 * to fix at the schema layer. Every crm_deals/crm_tickets-touching read in
 * queries.ts is defensively wrapped (an intentional, documented deviation
 * from byte-parity, NOT present in PHP) so this page returns 200 with
 * documented empty defaults instead of 500ing — this is the regression this
 * batch's acceptance criteria checks for.
 *
 * lineAccountId: crm-dashboard-advanced.php reads `$_SESSION['line_account_id']
 * ?? null` — a session key NO PHP file in the repo ever sets (grepped) — so
 * `$currentBotId` is ALWAYS `null` in real production, both for the page's
 * own dead initial `getExecutiveOverview()` call AND every AJAX-driven tab
 * (api/crm-dashboard-api.php constructs `new CRMDashboardService($db)` with
 * no lineAccountId argument at all). Mirrored exactly below: `null`, NOT
 * `session.currentBotId` (contrast with every other Phase 2 page, which
 * genuinely does read a live session key). See queries.ts's module doc for
 * the full WHERE-clause implications of this.
 *
 * Access gate: crm-dashboard-advanced.php has no page-specific role check
 * beyond `includes/auth_check.php`'s generic "must be logged in" requirement
 * (confirmed by reading the full 854-line source) — reuses
 * users/_lib/session's requireTenantPageContext(), same convention as
 * loyalty-members/system-status/user-detail.
 */
export interface CrmDashboardAdvancedPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

const TABS = [
  { key: 'overview', label: 'Executive Overview' },
  { key: 'pipeline', label: 'Sales Pipeline' },
  { key: 'service', label: 'Service Center' },
  { key: 'marketing', label: 'Marketing Hub' },
  { key: 'analytics', label: 'Analytics Studio' },
  { key: 'customers', label: 'Customers' },
  { key: 'deals', label: 'All Deals' },
  { key: 'tickets', label: 'Tickets' },
  { key: 'reports', label: 'Reports' },
] as const;
type TabKey = (typeof TABS)[number]['key'];

const PAGE_TITLES: Record<TabKey, string> = {
  overview: 'Executive Overview',
  pipeline: 'Sales Pipeline',
  service: 'Service Center',
  marketing: 'Marketing Hub',
  analytics: 'Analytics Studio',
  customers: 'Customers',
  deals: 'All Deals',
  tickets: 'Tickets',
  reports: 'Reports',
};

export async function generateMetadata({ searchParams }: CrmDashboardAdvancedPageProps): Promise<Metadata> {
  const params = await searchParams;
  const activeTab = resolveActiveTab(first(params, 'tab'));
  return { title: `${PAGE_TITLES[activeTab]} — CRM Dashboard Advanced` };
}

function resolveActiveTab(tabParam: string | undefined): TabKey {
  return (TABS.some((t) => t.key === tabParam) ? tabParam : 'overview') as TabKey;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

export default async function CrmDashboardAdvancedPage({ searchParams }: CrmDashboardAdvancedPageProps) {
  const params = await searchParams;
  const { db } = await requireTenantPageContext();
  const activeTab = resolveActiveTab(first(params, 'tab'));

  // ALWAYS null — see this file's module doc ("lineAccountId" section).
  const lineAccountId: number | null = null;

  const preserveParams: Record<string, string | undefined> = {};

  // Resolved by direct invocation (`await Tab({...})`), not JSX — matches
  // (tenant)/dashboard/page.tsx's own established convention (see that
  // file's doc comment for why: plain react-dom under
  // @testing-library/react can't await a nested async function component
  // reached via JSX the way Next.js's RSC renderer can).
  let tabContent: Awaited<ReturnType<typeof ExecutiveOverviewTab>>;
  switch (activeTab) {
    case 'pipeline':
      tabContent = await SalesPipelineTab({ db, lineAccountId });
      break;
    case 'service':
      tabContent = await ServiceCenterTab({ db, lineAccountId, status: first(params, 'status') ?? '', priority: first(params, 'priority') ?? '' });
      break;
    case 'marketing':
      tabContent = await MarketingHubTab({ db, lineAccountId });
      break;
    case 'analytics':
      tabContent = await AnalyticsStudioTab({ db, period: first(params, 'period') ?? '30d' });
      break;
    case 'customers': {
      const pageRaw = first(params, 'page');
      const page = Math.max(1, Number.parseInt(pageRaw ?? '1', 10) || 1);
      tabContent = await CustomersTab({ db, lineAccountId, search: first(params, 'search') ?? '', page });
      break;
    }
    case 'deals':
      tabContent = await DealsTab({ db, lineAccountId, stage: first(params, 'stage') ?? '', search: first(params, 'search') ?? '' });
      break;
    case 'tickets':
      tabContent = await TicketsTab({ db, status: first(params, 'status') ?? '' });
      break;
    case 'reports':
      tabContent = ReportsTab();
      break;
    case 'overview':
    default:
      tabContent = await ExecutiveOverviewTab({ db, lineAccountId, period: first(params, 'period') ?? '30d' });
      break;
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
        <div>
          <h1 className="text-lg font-semibold text-gray-800">{PAGE_TITLES[activeTab]}</h1>
        </div>
      </div>

      <Tabs tabs={TABS.map((t) => ({ key: t.key, label: t.label }))} activeTab={activeTab} basePath="/crm-dashboard-advanced" preserveParams={preserveParams} />

      <div className="mt-4">{tabContent}</div>
    </div>
  );
}
